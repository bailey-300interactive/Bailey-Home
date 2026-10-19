<?php

/**
 * Import passwords from the old bailey_accounts database into someone's vault (command line).
 *
 *   php dev/import-accounts.php
 *   php dev/import-accounts.php --db=user:password@localhost/bailey_accounts --to=you@300interactive.com --owner=1 --folder=Imported
 *
 * --owner   only accounts that belonged to this old admin_users id (default: all)
 * --folder  vault folder to put them in (default: Imported, "" for none)
 * The person's vault must already be set up (open /vault once in the browser). You'll be asked for its master password.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('ROOT', realpath(__DIR__ . '/..') . '/');
require(ROOT . 'app/init.php');

$args = getopt('', array('db:', 'to:', 'owner:', 'folder:', 'keep-dupes'));
function ask($q, $d = '') { fwrite(STDOUT, $q . ($d !== '' ? " [$d]" : '') . ': '); $a = trim((string)fgets(STDIN)); return $a === '' ? $d : $a; }
function secret($q) { fwrite(STDOUT, $q . ': '); if (posix_isatty(STDIN)) { shell_exec('stty -echo'); } $a = trim((string)fgets(STDIN)); if (posix_isatty(STDIN)) { shell_exec('stty echo'); fwrite(STDOUT, PHP_EOL); } return $a; }

// Old database
if (!empty($args['db']) && preg_match('#^([^:]+):(.*)@([^/:]+)(?::(\d+))?/(.+)$#', $args['db'], $m)) {
    list(, $dbUser, $dbPass, $host, $port, $dbName) = $m;
} else {
    $host = ask('Old database host', 'localhost');
    $dbName = ask('Old database name', 'bailey_accounts');
    $dbUser = ask('MySQL user', 'root');
    $dbPass = secret('MySQL password');
    $port = null;
}
try {
    $pdo = VaultImport::LegacyConnect($host, $dbName, $dbUser, $dbPass, $port ?: null);
    $sum = VaultImport::LegacySummary($pdo);
} catch (RuntimeException $e) {
    fwrite(STDERR, '✗ ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
echo "✓ {$sum['total']} accounts found.\n";
foreach ($sum['owners'] as $o) {
    echo "   owner {$o['id']}: {$o['username']} ({$o['accounts']})\n";
}
if ($sum['encrypted_share'] > 0.5 && !$sum['has_decrypt_hook']) {
    echo "! Most passwords don't look like plain text. See settings/legacy-decrypt.php.sample.\n";
}

// Whose vault
$to = $args['to'] ?? ask('Import into the vault of (email)');
$u = AdminUser::FindByUsername($to);
if (!$u) {
    fwrite(STDERR, "✗ No Home login with that email.\n");
    exit(1);
}
if (!Vault::IsSetUp($u['Id_admin_users'])) {
    fwrite(STDERR, "✗ That person hasn't set up their vault yet. Open /vault in the browser first.\n");
    exit(1);
}
$_SESSION = array('authenticated' => true, 'user_Id' => (int)$u['Id_admin_users'], 'auth_level' => (int)$u['auth_level']);
if (!Vault::Unlock((int)$u['Id_admin_users'], secret('Master password for ' . $to))) {
    fwrite(STDERR, "✗ Wrong master password.\n");
    exit(1);
}
$owner = isset($args['owner']) ? (int)$args['owner'] : (int)ask('Only accounts of old owner id (empty = all)', '');
$folder = $args['folder'] ?? 'Imported';
$r = VaultImport::ImportLegacy($pdo, $owner ?: null, $folder, !isset($args['keep-dupes']));
echo "✓ Imported {$r['added']}" . ($r['skipped'] ? ", skipped {$r['skipped']} already there" : '') . ($r['failed'] ? ", {$r['failed']} could not be decrypted" : '') . ".\n";
Vault::Lock();
