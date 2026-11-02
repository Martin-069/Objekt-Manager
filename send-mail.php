<?php
// ===================================================================
// Begehungs-Tool – Portal-Link E-Mail Versand
// POST { to, subject, body }  (API-Token erforderlich)
// ===================================================================
require_once __DIR__ . '/db.php';
require_api_token();

$in      = json_input();
$to      = trim($in['to']      ?? '');
$subject = trim($in['subject'] ?? '');
$body    = trim($in['body']    ?? '');

if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL))
    json_response(['ok'=>false,'error'=>'Ungültige E-Mail-Adresse']);
if (empty($subject)) $subject = 'Ihr Mängelportal';
if (empty($body))    json_response(['ok'=>false,'error'=>'Kein Nachrichtentext']);

// Absender aus config.php oder Fallback
$from = defined('MAIL_FROM') ? MAIL_FROM : 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Begehungs-Tool';

$headers  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
$headers .= "Reply-To: {$from}\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: 8bit\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "X-Mailer: Begehungs-Tool\r\n";

$ok = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);

if ($ok) {
    json_response(['ok'=>true,'message'=>'E-Mail gesendet an '.$to]);
} else {
    json_response(['ok'=>false,'error'=>'E-Mail konnte nicht gesendet werden (mail() Fehler)']);
}
