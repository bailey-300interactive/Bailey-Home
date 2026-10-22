<?php

/**
 * Send one test text using settings/sms.ini.php and show Twilio's answer.
 *
 *   php dev/sms-test.php 7245550123
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
define('ROOT', realpath(__DIR__ . '/..') . '/');
require(ROOT . 'app/init.php');

$to = Sms::Normalize($argv[1] ?? '');
if (!$to) {
    exit("Usage: php dev/sms-test.php 7245550123   (or +447700900123)\n");
}
$s = Sms::Config();
if (!$s) {
    exit("Can't read settings/sms.ini.php. Copy settings/sms.ini.php.sample and fill it in.\n");
}
echo "Driver: {$s['Driver']}  From: {$s['From']}  Key: " . substr($s['ApiKeySid'], 0, 6) . "…  To: $to\n";
$r = Sms::Send($to, 'Test from ' . App::Setting('site_name', 'Home') . ' at ' . App::Now()->format('g:i:s A') . '. Texts work.');
echo $r['ok'] ? "SENT (id {$r['id']})\n" : "FAILED: {$r['error']}\n";
