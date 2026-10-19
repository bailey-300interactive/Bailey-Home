<?php

/**
 * Run every minute from cron:
 *   * * * * * php /var/www/home/dev/cron.php >/dev/null 2>&1
 *
 * Emails reminders that are due, the morning digest (at the hour set in Settings),
 * and anything else waiting in the email queue. Also tidies old rate-limit rows.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('ROOT', realpath(__DIR__ . '/..') . '/');
require(ROOT . 'app/init.php');

$lock = fopen(ROOT . 'storage/cache/cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit; // already running
}
touch(ROOT . 'storage/cache/cron.lock'); // lets Admin → Settings see that cron is running

$alerts = Reminder::QueueDueAlerts();
$digests = Reminder::QueueDigests();
$sent = Mailer::Flush(50);
run('DELETE FROM rate_limits WHERE window_start < ?', array(time() - 86400));
// Forgotten bank-import uploads
foreach (glob(ROOT . 'storage/exports/import-*.json') ?: array() as $f) {
    if (filemtime($f) < time() - 86400) {
        @unlink($f);
    }
}

if (in_array('-v', $argv, true)) {
    echo "reminders queued: $alerts, digests: $digests, emails sent: $sent\n";
}
