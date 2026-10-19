<?php

/**
 * Send one test email using settings/mail.ini.php and show the whole conversation with the mail server.
 *
 *   php dev/mail-test.php you@example.com
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
$to = $argv[1] ?? '';
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    exit("Usage: php dev/mail-test.php you@example.com\n");
}

require __DIR__ . '/../app/Modules/php-mailer/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

$s = parse_ini_file(__DIR__ . '/../settings/mail.ini.php');
if ($s === false) {
    exit("Can't read settings/mail.ini.php\n");
}
$password = str_replace(' ', '', $s['Password'] ?? '');
echo "Host: {$s['Host']}:{$s['Port']} ({$s['SmtpSecure']})  Login: {$s['Username']}  Password: " . strlen($password) . " characters\n\n";

$m = new PHPMailer(true);
$m->isSMTP();
$m->Host = $s['Host'];
$m->Port = (int)$s['Port'];
$m->SMTPSecure = $s['SmtpSecure'];
$m->SMTPAuth = true;
$m->Username = $s['Username'];
$m->Password = $password;
$m->Timeout = 15;
$m->getSMTPInstance()->Timelimit = 20; // don't wait 5 minutes for a stuck server
$m->SMTPDebug = SMTP::DEBUG_SERVER;
$hide = false;
$m->Debugoutput = function ($line) use (&$hide) {
    // don't print the login
    if (str_starts_with($line, 'CLIENT -> SERVER: AUTH')) { $hide = true; echo "CLIENT -> SERVER: AUTH [hidden]\n"; return; }
    if ($hide && str_starts_with($line, 'CLIENT -> SERVER:')) { echo "CLIENT -> SERVER: [hidden]\n"; return; }
    if (str_starts_with($line, 'SERVER -> CLIENT: 235') || str_starts_with($line, 'SERVER -> CLIENT: 5')) { $hide = false; }
    echo rtrim($line), "\n";
};

try {
    $m->setFrom($s['FromEmail'] ?: $s['Username'], $s['FromName'] ?? '');
    $m->addAddress($to);
    $m->Subject = 'Home test email ' . date('g:i:s A');
    $m->Body = 'If you can read this, email works.';
    $m->send();
    echo "\nSENT to $to\n";
} catch (Throwable $e) {
    echo "\nFAILED: " . $m->ErrorInfo . "\n";
}
