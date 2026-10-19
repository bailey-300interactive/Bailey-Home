<?php

/**
 * /vault/import: from the old accounts database, from CSV, and export.
 * Included by app/Controllers/vault.php once the vault is unlocked.
 */

$base['page_title'] = 'Import & export';

// Old-database connection details stay in the session only while importing, encrypted with the vault key
$legacy = !empty($_SESSION['vault_legacy']) ? Vault::Decrypt(base64_decode($_SESSION['vault_legacy'])) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    App::CheckCsrf();
    $action = post('action');
    try {
        switch ($action) {
            case 'legacy-connect':
                $cfg = array('host' => post('host') ?: 'localhost', 'port' => post('port'), 'dbname' => post('dbname'), 'user' => post('user'), 'password' => $_POST['password'] ?? '');
                $pdo = VaultImport::LegacyConnect($cfg['host'], $cfg['dbname'], $cfg['user'], $cfg['password'], $cfg['port']);
                VaultImport::LegacySummary($pdo); // checks the tables
                $_SESSION['vault_legacy'] = base64_encode(Vault::Encrypt($cfg));
                App::QueueMessage('Connected to ' . $cfg['dbname'] . '.');
                App::Redirect('/vault/import?tab=database');

            case 'legacy-import':
                if (!$legacy) {
                    throw new RuntimeException('Connect to the old database first.');
                }
                $pdo = VaultImport::LegacyConnect($legacy['host'], $legacy['dbname'], $legacy['user'], $legacy['password'], $legacy['port']);
                $owner = post('owner') === 'all' ? null : (int)post('owner');
                $r = VaultImport::ImportLegacy($pdo, $owner, post('folder'), post_bool('skip_dupes'));
                unset($_SESSION['vault_legacy']);
                App::QueueMessage('Imported ' . $r['added'] . ' passwords' . ($r['skipped'] ? ', skipped ' . $r['skipped'] . ' already in your vault' : '') . ($r['failed'] ? ', ' . $r['failed'] . ' could not be decrypted' : '') . '.');
                App::Redirect('/vault');

            case 'legacy-forget':
                unset($_SESSION['vault_legacy']);
                App::Redirect('/vault/import?tab=database');

            case 'csv-preview':
                $rows = read_csv_upload('file');
                $parsed = VaultImport::ParseCsv($rows);
                if (!$parsed['items']) {
                    throw new RuntimeException('No passwords found in that file.');
                }
                $_SESSION['vault_csv'] = base64_encode(Vault::Encrypt(array('label' => $parsed['label'], 'items' => $parsed['items'], 'file' => $_FILES['file']['name'] ?? 'file.csv')));
                App::Redirect('/vault/import?tab=csv');

            case 'csv-import':
                $pending = !empty($_SESSION['vault_csv']) ? Vault::Decrypt(base64_decode($_SESSION['vault_csv'])) : null;
                if (!$pending) {
                    throw new RuntimeException('Upload the file again.');
                }
                $r = VaultImport::ImportItems($pending['items'], post('folder'), post_bool('skip_dupes'), $pending['label']);
                unset($_SESSION['vault_csv']);
                App::QueueMessage('Imported ' . $r['added'] . ' items' . ($r['skipped'] ? ', skipped ' . $r['skipped'] . ' already in your vault' : '') . '. Delete the CSV file from your computer now. It has your passwords in plain text.');
                App::Redirect('/vault');

            case 'csv-cancel':
                unset($_SESSION['vault_csv']);
                App::Redirect('/vault/import?tab=csv');

            case 'export':
                if (!App::RateLimit('vault-export:' . $uid, 5, 900) || !Vault::CheckMaster($uid, $_POST['master'] ?? '')) {
                    throw new RuntimeException('Wrong master password.');
                }
                list($header, $out) = VaultImport::ExportRows();
                App::Activity('vault', 'Exported the vault to CSV', '/vault/import');
                csv_out('home-vault-' . date('Y-m-d') . '.csv', $header, $out, false);
        }
    } catch (RuntimeException $e) {
        App::QueueMessage($e->getMessage(), 'error');
    }
    App::Redirect('/vault/import' . (isset($_GET['tab']) ? '?tab=' . urlencode($_GET['tab']) : ''));
}

$tab = in_array($_GET['tab'] ?? '', array('database', 'csv', 'export'), true) ? $_GET['tab'] : 'database';
$pageVars['tab'] = $tab;

if ($legacy) {
    try {
        $pdo = VaultImport::LegacyConnect($legacy['host'], $legacy['dbname'], $legacy['user'], $legacy['password'], $legacy['port']);
        $pageVars['legacy'] = VaultImport::LegacySummary($pdo);
        $pageVars['legacy_db'] = $legacy['dbname'] . ' on ' . $legacy['host'];
    } catch (RuntimeException $e) {
        unset($_SESSION['vault_legacy']);
        $pageVars['legacy_error'] = $e->getMessage();
    }
}
if (!empty($_SESSION['vault_csv'])) {
    $pending = Vault::Decrypt(base64_decode($_SESSION['vault_csv']));
    if ($pending) {
        $sample = array();
        foreach (array_slice($pending['items'], 0, 8) as $it) {
            $pw = $it[1]['password'] ?? '';
            $sample[] = array('type' => $it[0], 'name' => $it[1]['name'] ?: ($it[1]['url'] ?? ''), 'username' => $it[1]['username'] ?? '', 'folder' => $it[2], 'password' => $pw !== '' ? mb_substr($pw, 0, 2) . '••••••' : '', 'totp' => !empty($it[1]['totp']));
        }
        $folders = array_values(array_unique(array_filter(array_map(function ($it) { return $it[2]; }, $pending['items']))));
        $pageVars['csv'] = array('label' => $pending['label'], 'file' => $pending['file'], 'count' => count($pending['items']), 'sample' => $sample, 'folders' => $folders);
    }
}
$pageVars['defaults'] = array('host' => 'localhost', 'dbname' => 'bailey_accounts');
$pageVars['formats'] = VaultImport::FORMATS;
$pageVars['vault_count'] = (int)val('SELECT COUNT(*) FROM vault_items WHERE user_id = ?', array($uid));
$pageNeeded = 'vault/import';
