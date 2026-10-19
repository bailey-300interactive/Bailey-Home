<?php

/**
 * Bringing passwords into the vault:
 *  - from the old bailey_accounts database (accounts + admin_users tables)
 *  - from CSV exports of Chrome, Edge, Firefox, Safari, Bitwarden, 1Password, LastPass, KeePass, Dashlane, or any CSV
 * and taking them out again (Bitwarden-style CSV that every manager can read).
 */
class VaultImport
{
    /* ================= Old database ================= */

    /**
     * Connect to the old database
     * @return PDO
     */
    public static function LegacyConnect($host, $dbname, $user, $password, $port = null)
    {
        $dsn = 'mysql:host=' . $host . ';dbname=' . $dbname . ';charset=utf8mb4' . ($port ? ';port=' . (int)$port : '');
        try {
            return new PDO($dsn, $user, $password, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5));
        } catch (PDOException $e) {
            throw new RuntimeException('Could not connect to the old database: ' . preg_replace('/\s*\(.*$/', '', $e->getMessage()));
        }
    }

    /**
     * What's in the old database: its owners and a preview
     */
    public static function LegacySummary(PDO $pdo)
    {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('accounts', $tables, true)) {
            throw new RuntimeException('That database has no "accounts" table.');
        }
        $owners = array();
        if (in_array('admin_users', $tables, true)) {
            foreach ($pdo->query('SELECT u.Id_admin_users AS id, u.username, COUNT(a.Id_accounts) AS accounts FROM admin_users u LEFT JOIN accounts a ON a.admin_users_Id = u.Id_admin_users GROUP BY u.Id_admin_users, u.username ORDER BY u.username') as $r) {
                $owners[] = $r;
            }
        }
        $total = (int)$pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
        $noOwner = (int)$pdo->query('SELECT COUNT(*) FROM accounts WHERE admin_users_Id IS NULL')->fetchColumn();

        $preview = array();
        $looksEncrypted = 0;
        $sample = $pdo->query('SELECT * FROM accounts ORDER BY name LIMIT 200')->fetchAll();
        foreach ($sample as $i => $r) {
            $kind = self::PasswordLooks((string)$r['password']);
            if ($kind !== 'plain') {
                $looksEncrypted++;
            }
            if ($i < 8) {
                $preview[] = array(
                    'name' => $r['name'], 'username' => $r['username'], 'url' => $r['url'],
                    'password' => self::Mask((string)$r['password']), 'looks' => $kind,
                    'has_description' => trim((string)$r['description']) !== '',
                );
            }
        }
        return array(
            'total' => $total,
            'no_owner' => $noOwner,
            'owners' => $owners,
            'preview' => $preview,
            'encrypted_share' => count($sample) ? $looksEncrypted / count($sample) : 0,
            'has_decrypt_hook' => is_file(ROOT . 'settings/legacy-decrypt.php'),
        );
    }

    /**
     * Copy accounts into the person's vault.
     * @param int|null $ownerId only this old admin_users id (null = everything)
     * @return array counts
     */
    public static function ImportLegacy(PDO $pdo, $ownerId, $folderName, $skipDuplicates = true)
    {
        Vault::RequireUnlocked();
        if (is_file(ROOT . 'settings/legacy-decrypt.php')) {
            require_once ROOT . 'settings/legacy-decrypt.php';
        }
        $sql = 'SELECT * FROM accounts';
        $params = array();
        if ($ownerId) {
            $sql .= ' WHERE admin_users_Id = ?';
            $params[] = (int)$ownerId;
        }
        $st = $pdo->prepare($sql . ' ORDER BY Id_accounts');
        $st->execute($params);

        $folderId = $folderName ? Vault::FolderByName($folderName) : null;
        $existing = $skipDuplicates ? self::ExistingKeys() : array();
        $added = $skipped = $failed = 0;
        foreach ($st as $r) {
            $pw = (string)$r['password'];
            if (function_exists('legacy_decrypt') && $pw !== '') {
                $dec = legacy_decrypt($pw);
                if ($dec === false || $dec === null) {
                    $failed++;
                    continue;
                }
                $pw = $dec;
            }
            $item = array(
                'name' => (string)$r['name'],
                'username' => (string)$r['username'],
                'password' => $pw,
                'url' => (string)$r['url'],
                'notes' => (string)$r['description'],
            );
            $key = self::DupKey($item);
            if ($skipDuplicates && isset($existing[$key])) {
                $skipped++;
                continue;
            }
            Vault::Save(null, 'login', $item, $folderId, 0, 'bailey_accounts');
            $existing[$key] = true;
            $added++;
        }
        App::Activity('vault', 'Imported ' . $added . ' passwords from the old accounts database', '/vault');
        return array('added' => $added, 'skipped' => $skipped, 'failed' => $failed);
    }

    /** "plain", "hash" (can't be reversed) or "encrypted" */
    public static function PasswordLooks($pw)
    {
        if ($pw === '') {
            return 'plain';
        }
        if (preg_match('/^\$(2[aby]|argon2i|argon2id)\$/', $pw)) {
            return 'hash';
        }
        if (preg_match('/^[a-f0-9]{32}$|^[a-f0-9]{40}$|^[a-f0-9]{64}$/i', $pw)) {
            return 'hash';
        }
        if (strlen($pw) >= 24 && preg_match('/^[A-Za-z0-9+\/]+=*(:[A-Za-z0-9+\/]+=*)?$/', $pw) && strlen($pw) % 4 === 0) {
            return 'encrypted';
        }
        return 'plain';
    }

    private static function Mask($pw)
    {
        $len = mb_strlen($pw);
        if ($len === 0) {
            return '';
        }
        return mb_substr($pw, 0, min(2, $len)) . str_repeat('•', max(3, min(10, $len - 2)));
    }

    /* ================= CSV ================= */

    const FORMATS = array(
        'home' => array('label' => 'Home export', 'needs' => array('home_type', 'name', 'password')),
        'bitwarden' => array('label' => 'Bitwarden', 'needs' => array('login_password', 'login_username')),
        'onepassword' => array('label' => '1Password', 'needs' => array('title', 'password', 'otpauth', 'archived')),
        'lastpass' => array('label' => 'LastPass', 'needs' => array('url', 'username', 'password', 'extra', 'grouping')),
        'firefox' => array('label' => 'Firefox', 'needs' => array('url', 'username', 'password', 'httprealm')),
        'safari' => array('label' => 'Safari / iCloud Keychain', 'needs' => array('title', 'url', 'username', 'password', 'otpauth')),
        'chrome' => array('label' => 'Chrome / Edge / Brave', 'needs' => array('name', 'url', 'username', 'password')),
        'keepass' => array('label' => 'KeePass', 'needs' => array('account', 'login name', 'password')),
        'dashlane' => array('label' => 'Dashlane', 'needs' => array('username', 'password', 'url', 'title', 'category')),
    );

    /** Work out which app made the CSV from its header row */
    public static function DetectFormat(array $header)
    {
        $h = array_map(function ($c) { return strtolower(trim($c)); }, $header);
        foreach (self::FORMATS as $key => $f) {
            if (count(array_intersect($f['needs'], $h)) === count($f['needs'])) {
                return $key;
            }
        }
        return 'generic';
    }

    /**
     * Turn CSV rows into vault items: array of array(type, data, folder, favorite)
     */
    public static function ParseCsv(array $rows)
    {
        if (count($rows) < 2) {
            throw new RuntimeException('That CSV has no rows.');
        }
        $header = array_map(function ($c) { return strtolower(trim($c)); }, array_shift($rows));
        $format = self::DetectFormat($header);
        $items = array();
        foreach ($rows as $r) {
            $c = array();
            foreach ($header as $i => $name) {
                $c[$name] = $r[$i] ?? '';
            }
            $it = self::MapRow($format, $c, $header);
            if ($it && (($it[1]['password'] ?? '') !== '' || ($it[1]['username'] ?? '') !== '' || ($it[1]['notes'] ?? '') !== '' || $it[0] !== 'login')) {
                $items[] = $it;
            }
        }
        return array('format' => $format, 'label' => self::FORMATS[$format]['label'] ?? 'Generic CSV', 'items' => $items);
    }

    private static function MapRow($format, array $c, array $header)
    {
        $g = function () use ($c) {
            foreach (func_get_args() as $k) {
                if (isset($c[$k]) && $c[$k] !== '') {
                    return $c[$k];
                }
            }
            return '';
        };
        $type = 'login';
        $folder = '';
        $fav = 0;
        switch ($format) {
            case 'bitwarden':
                $bwType = strtolower($g('type'));
                $type = $bwType === 'note' ? 'note' : ($bwType === 'card' ? 'card' : 'login');
                $d = array('name' => $g('name'), 'username' => $g('login_username'), 'password' => $g('login_password'), 'url' => explode(',', $g('login_uri'))[0], 'totp' => $g('login_totp'), 'notes' => $g('notes'));
                $folder = $g('folder');
                $fav = $g('favorite') === '1' ? 1 : 0;
                $fields = array();
                foreach (preg_split('/\r?\n/', $g('fields')) as $line) {
                    if (strpos($line, ': ') !== false) {
                        list($l, $v) = explode(': ', $line, 2);
                        $fields[] = array('label' => $l, 'value' => $v, 'hidden' => false);
                    }
                }
                $d['fields'] = $fields;
                break;
            case 'onepassword':
                $d = array('name' => $g('title'), 'username' => $g('username'), 'password' => $g('password'), 'url' => $g('url', 'website'), 'totp' => $g('otpauth'), 'notes' => $g('notes', 'notesplain'));
                $fav = in_array(strtolower($g('favorite')), array('true', '1'), true) ? 1 : 0;
                $folder = explode(',', $g('tags'))[0];
                break;
            case 'lastpass':
                $d = array('name' => $g('name'), 'username' => $g('username'), 'password' => $g('password'), 'url' => $g('url') === 'http://sn' ? '' : $g('url'), 'totp' => $g('totp'), 'notes' => $g('extra'));
                if ($g('url') === 'http://sn') {
                    $type = 'note';
                }
                $folder = $g('grouping');
                $fav = $g('fav') === '1' ? 1 : 0;
                break;
            case 'firefox':
                $d = array('name' => '', 'username' => $g('username'), 'password' => $g('password'), 'url' => $g('url'), 'notes' => '');
                break;
            case 'safari':
                $d = array('name' => $g('title'), 'username' => $g('username'), 'password' => $g('password'), 'url' => $g('url'), 'totp' => $g('otpauth'), 'notes' => $g('notes'));
                break;
            case 'chrome':
                $d = array('name' => $g('name'), 'username' => $g('username'), 'password' => $g('password'), 'url' => $g('url'), 'notes' => $g('note', 'notes'));
                break;
            case 'keepass':
                $d = array('name' => $g('account', 'title'), 'username' => $g('login name', 'username'), 'password' => $g('password'), 'url' => $g('web site', 'url'), 'notes' => $g('comments', 'notes'));
                $folder = $g('group');
                break;
            case 'dashlane':
                $d = array('name' => $g('title'), 'username' => $g('username', 'username2', 'email'), 'password' => $g('password'), 'url' => $g('url'), 'totp' => $g('otpsecret', 'otpurl'), 'notes' => $g('note'));
                $folder = $g('category');
                break;
            case 'home':
                $type = $g('home_type') ?: 'login';
                $d = json_decode($g('home_data'), true) ?: array('name' => $g('name'), 'username' => $g('username'), 'password' => $g('password'), 'url' => $g('url'), 'totp' => $g('totp'), 'notes' => $g('notes'));
                $folder = $g('folder');
                $fav = $g('favorite') === '1' ? 1 : 0;
                break;
            default:
                // Any CSV: guess by column names
                $d = array(
                    'name' => $g('name', 'title', 'account', 'site', 'service', 'description'),
                    'username' => $g('username', 'user', 'login', 'login name', 'email', 'user name', 'login_username'),
                    'password' => $g('password', 'pass', 'pwd', 'login_password'),
                    'url' => $g('url', 'website', 'web site', 'site url', 'login_uri', 'link', 'address'),
                    'totp' => $g('totp', 'otp', 'otpauth', '2fa'),
                    'notes' => $g('notes', 'note', 'comments', 'extra'),
                );
                $folder = $g('folder', 'group', 'category');
        }
        return array($type, $d, $folder, $fav);
    }

    /**
     * Save parsed items. Returns counts.
     */
    public static function ImportItems(array $items, $folderOverride, $skipDuplicates, $source)
    {
        Vault::RequireUnlocked();
        $existing = $skipDuplicates ? self::ExistingKeys() : array();
        $overrideId = $folderOverride ? Vault::FolderByName($folderOverride) : null;
        $added = $skipped = 0;
        foreach ($items as $it) {
            list($type, $d, $folder, $fav) = $it;
            $key = self::DupKey($d);
            if ($skipDuplicates && isset($existing[$key])) {
                $skipped++;
                continue;
            }
            $folderId = $overrideId ?: ($folder ? Vault::FolderByName($folder) : null);
            Vault::Save(null, $type, $d, $folderId, $fav, $source);
            $existing[$key] = true;
            $added++;
        }
        App::Activity('vault', 'Imported ' . $added . ' items from ' . $source, '/vault');
        return array('added' => $added, 'skipped' => $skipped, 'failed' => 0);
    }

    /** Fingerprint used to skip items that are already in the vault */
    private static function DupKey(array $d)
    {
        $url = strtolower((string)($d['url'] ?? ''));
        $host = parse_url(str_contains($url, '://') ? $url : 'https://' . $url, PHP_URL_HOST) ?: $url;
        return hash('sha256', strtolower(trim((string)($d['username'] ?? ''))) . '|' . preg_replace('/^www\./', '', (string)$host) . '|' . ($d['password'] ?? '') . '|' . strtolower(trim((string)($d['name'] ?? ''))));
    }

    private static function ExistingKeys()
    {
        $keys = array();
        foreach (Vault::Items() as $it) {
            $keys[self::DupKey($it['d'])] = true;
        }
        return $keys;
    }

    /* ================= Export ================= */

    public static function ExportRows()
    {
        $folders = array_column(Vault::Folders(), 'name', 'id');
        $out = array();
        foreach (Vault::Items() as $it) {
            $d = $it['d'];
            $out[] = array(
                $folders[$it['folder_id']] ?? '', $it['favorite'], $it['type'], $d['name'] ?? '', $d['username'] ?? '', $d['password'] ?? '',
                $d['url'] ?? '', $d['totp'] ?? '', $d['notes'] ?? '', json_encode($d, JSON_UNESCAPED_UNICODE)
            );
        }
        return array(array('folder', 'favorite', 'home_type', 'name', 'username', 'password', 'url', 'totp', 'notes', 'home_data'), $out);
    }
}
