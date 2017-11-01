<?php
/* Install on the system: gs (ghostscript package), mail, php5-imap library */

require_once('Mail/mimeDecode.php');

/* Connect to Gmail, or configure for any other IMAP server. For Gmail, don't forget to 
allow less secure apps or generate an app-specific password. */

$hostname = '{imap.gmail.com:993/imap/ssl}INBOX';
$username = 'username@gmail.com';
$password = 'gmailpassword';

$inbox = imap_open($hostname,$username,$password) or die('Cannot connect to mail server: ' . imap_last_error());

/* Grab unread emails. Change UNSEEN to ALL if you want to get everything. Be sure to enable the delete and
   purge functions below or else you will send the same faxes over and over. */
$emails = imap_search($inbox,'UNSEEN');

/* if emails are returned, cycle through each... */
if($emails) {
	foreach($emails as $email_number) {
		$message = imap_fetchbody($inbox,$email_number, '');
		
		$params['include_bodies'] = true;
		$params['decode_bodies']  = true;
		$params['decode_headers'] = true;
		$params['input']          = $message;
		$structure = Mail_mimeDecode::decode($params); // decodes whole e-mail into object $structure

		$destination = $structure->headers['subject']; // subject contains destination phone number only
		$date = strtotime($structure->headers['date']);
		$rn = rand(1000,9999);
		$outfile = "/tmp/$destination-$date-$rn.tiff";
		$pdf = NULL;

		findDocs($structure);
		$converted = FALSE;
		if (count($pdf) == 1) $converted = convertSingle($outfile);
		if (count($pdf) > 1) $converted = convertMulti($outfile);
		sendFax($destination, $outfile);
		$reply = "To: " . $structure->headers['from'] . "\n";
		$reply .= "Subject: Fax queued\n\n";
		$reply .= "Your fax to $destination was queued. No status report available.";
		system("echo \"$reply\" | /usr/bin/mail -t", $retval);
		/* Message is left on the server in seen/read state. 
		   If you want to delete the message instead, then:
		   imap_delete($inbox,$email_number);
		*/
	}
} 

/* close the connection */
/* if we are deleting mail above, then do expunge before close:
   imap_expunge($inbox);
*/
imap_close($inbox);
exit;


function convertSingle($outfile) {
	/* Make a single PDF into a fax-appropriate TIFF */
	global $pdf;
	if ($gs = popen("/usr/bin/gs -q -r204x192 -dPDFFitPage -dNOPAUSE -dBATCH -dSAFER -sDEVICE=tiffg4 -sOutputFile=$outfile -", 'w')) {
		fwrite($gs, $pdf[0]);
		pclose($gs);
		return TRUE;
	}

	return FALSE;
}

function convertMulti($outfile) {
	/* Make multiple PDFs into a fax-appropriate TIFF */
	global $pdf;
	for ($i=0; $i<count($pdf); $i++) {
		$tmpfile[$i] = tempnam('/tmp', 'faxpart');
		$h = fopen($tmpfile[$i], 'w');
		fwrite($h, $pdf[$i]);
		fclose($h);
	}

	$infiles = implode(' ', $tmpfile);
	system("/usr/bin/gs -q -r204x192 -dPDFFitPage -dNOPAUSE -dBATCH -dSAFER -sDEVICE=tiffg4 -sOutputFile=$outfile $infiles", $retval);

	if ($retval == 0) {
		for ($i=0; $i<count($pdf); $i++) {
			unlink($tmpfile[$i]);
		}
		return TRUE;
	}

	return FALSE;
}

function findDocs($decodeObj) {
	/* Finds all PDFs in the message and adds them to the $pdf array */
	global $pdf;
	if (isset($decodeObj->parts)) {
		foreach ($decodeObj->parts as $part) {
			findDocs($part);
		}       
	}               
                        
	$pri = $decodeObj->ctype_primary;
	$sec = $decodeObj->ctype_secondary;
                                
	if (!strcasecmp($pri, 'application') && !strcasecmp($sec, 'pdf')) { // grab the PDF as it is
		$pdf[] = $decodeObj->body;
		return;
	}       
}

function sendFax($destination, $outfile) {
	/* Generate a call file to use Asterisk's built-in sendfax (spandsp). Change the outbound-allroutes context
           to the appropriate outbound context if you're not using FreePBX. */
	$out = "Channel: Local/$destination@outbound-allroutes\n";
	$out .= "MaxRetries: 3\n";
	$out .= "RetryTime: 120\n";
	$out .= "Application: SendFax\n";
	$out .= "Data: $outfile,f\n";
	$tmpfile = "/tmp/calltemp";
	file_put_contents($tmpfile, $out);
	return rename($tmpfile, "/var/spool/asterisk/outgoing/" . basename($outfile, ".tiff"));
}

?>
