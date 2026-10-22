<?php

/**
 * Home: configuration constants. Included by app/init.php.
 * @author Bailey Rotellini
 */

if (!defined('ROOT')) {
    define('ROOT', realpath(__DIR__) . '/');
}

$appIni = @parse_ini_file(ROOT . 'settings/app.ini.php') ?: array();

// Turn on only while developing: shows errors on screen and disables Twig caching
define('DEBUG', !empty($appIni['debug']));

// Emails go out only when this is on (Driver = log in mail.ini.php writes them to storage/logs instead)
define('SEND_EMAILS', !isset($appIni['send_emails']) || !empty($appIni['send_emails']));

define('BASE_URL', rtrim($appIni['base_url'] ?? '', '/'));

define('CONTROLLERS', ROOT . 'app/Controllers/');
define('STORAGE', ROOT . 'storage/');

// Auth levels (framework style: lower number = more access)
define('ADMIN', 0);
define('MEMBER', 10);

// Signing in: a session lasts this long with no activity. "Keep me signed in" brings you
// back in automatically for REMEMBER_DAYS (each visit pushes it out again).
define('SESSION_IDLE_SECONDS', 12 * 3600);
define('REMEMBER_DAYS', 30);

// Version of the database schema this code expects (see dev/database/migrations)
define('SCHEMA_VERSION', 1);

// Vault key derivation (Argon2id). Changing these only affects new vaults / new master passwords.
define('VAULT_OPSLIMIT', SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE);
define('VAULT_MEMLIMIT', SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);
