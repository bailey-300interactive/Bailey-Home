<?php

/**
 * The password vault.
 *
 * How the encryption works:
 *  - Each person's vault has a random 256-bit data key. Items are encrypted with it
 *    (XChaCha20-Poly1305, libsodium), bound to the owner's user id.
 *  - The data key is never stored in the clear. It's stored wrapped by a key derived from the
 *    master password with Argon2id, and wrapped a second time by a key derived from a recovery code.
 *    The server never stores the master password or the recovery code.
 *  - Unlocking puts the data key in the session encrypted with a random key that lives only in an
 *    HttpOnly cookie, so the session file alone can't open the vault. It locks itself after the
 *    auto-lock time with no vault activity, on sign out, or when you press Lock.
 */
class Vault
{
    const COOKIE = 'home_vk';
    const TYPES = array(
        'login' => array('label' => 'Login', 'plural' => 'Logins', 'icon' => 'globe'),
        'card' => array('label' => 'Card', 'plural' => 'Cards', 'icon' => 'credit-card'),
        'note' => array('label' => 'Secure note', 'plural' => 'Secure notes', 'icon' => 'file-text'),
        'wifi' => array('label' => 'Wi-Fi', 'plural' => 'Wi-Fi', 'icon' => 'wifi'),
        'server' => array('label' => 'Server', 'plural' => 'Servers', 'icon' => 'server'),
    );

    private static $cachedKey = null;

    /* ================= Setup / unlock ================= */

    public static function KeyRow($uid)
    {
        return row('SELECT * FROM vault_keys WHERE user_id = ?', array($uid));
    }

    public static function IsSetUp($uid = null)
    {
        $uid = $uid ?: App::UserId();
        return $uid && (bool)val('SELECT 1 FROM vault_keys WHERE user_id = ?', array($uid));
    }

    /**
     * Create a vault. Returns the recovery code to show once.
     */
    public static function Setup($uid, $master, $hint = '')
    {
        if (self::IsSetUp($uid)) {
            throw new RuntimeException('The vault is already set up.');
        }
        $dataKey = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $kek = self::DeriveKey($master, $salt, VAULT_OPSLIMIT, VAULT_MEMLIMIT);

        $recovery = self::NewRecoveryCode();
        $rsalt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $rkek = self::DeriveKey(self::NormalizeRecovery($recovery), $rsalt, VAULT_OPSLIMIT, VAULT_MEMLIMIT);

        insert('vault_keys', array(
            'user_id' => $uid,
            'salt' => $salt,
            'opslimit' => VAULT_OPSLIMIT,
            'memlimit' => VAULT_MEMLIMIT,
            'wrapped_key' => self::Wrap($dataKey, $kek, $uid),
            'recovery_salt' => $rsalt,
            'recovery_wrapped_key' => self::Wrap($dataKey, $rkek, $uid),
            'hint' => mb_substr($hint, 0, 128) ?: null,
            'auto_lock_minutes' => (int)App::Setting('vault_auto_lock', 15),
            'created_at' => App::UtcNow(),
        ));
        self::StoreUnlocked($uid, $dataKey);
        sodium_memzero($kek);
        sodium_memzero($rkek);
        return $recovery;
    }

    /**
     * Open the vault with the master password. Returns true or false.
     */
    public static function Unlock($uid, $master)
    {
        $k = self::KeyRow($uid);
        if (!$k) {
            return false;
        }
        $kek = self::DeriveKey($master, $k['salt'], (int)$k['opslimit'], (int)$k['memlimit']);
        $dataKey = self::Unwrap($k['wrapped_key'], $kek, $uid);
        sodium_memzero($kek);
        if ($dataKey === false) {
            return false;
        }
        self::StoreUnlocked($uid, $dataKey);
        return true;
    }

    /** Check a master password without changing the session */
    public static function CheckMaster($uid, $master)
    {
        $k = self::KeyRow($uid);
        if (!$k) {
            return false;
        }
        $kek = self::DeriveKey($master, $k['salt'], (int)$k['opslimit'], (int)$k['memlimit']);
        $ok = self::Unwrap($k['wrapped_key'], $kek, $uid) !== false;
        sodium_memzero($kek);
        return $ok;
    }

    public static function ChangeMaster($uid, $old, $new, $hint = null)
    {
        $k = self::KeyRow($uid);
        $kek = self::DeriveKey($old, $k['salt'], (int)$k['opslimit'], (int)$k['memlimit']);
        $dataKey = self::Unwrap($k['wrapped_key'], $kek, $uid);
        if ($dataKey === false) {
            return false;
        }
        self::Rewrap($uid, $dataKey, $new, $hint);
        return true;
    }

    /**
     * Forgot the master password: use the recovery code to set a new one.
     * Returns a NEW recovery code (the old one stops working), or false.
     */
    public static function Recover($uid, $recoveryCode, $newMaster, $hint = null)
    {
        $k = self::KeyRow($uid);
        if (!$k || empty($k['recovery_wrapped_key'])) {
            return false;
        }
        $rkek = self::DeriveKey(self::NormalizeRecovery($recoveryCode), $k['recovery_salt'], (int)$k['opslimit'], (int)$k['memlimit']);
        $dataKey = self::Unwrap($k['recovery_wrapped_key'], $rkek, $uid);
        if ($dataKey === false) {
            return false;
        }
        self::Rewrap($uid, $dataKey, $newMaster, $hint);
        $code = self::NewRecoveryKit($uid, $dataKey);
        self::StoreUnlocked($uid, $dataKey);
        return $code;
    }

    /** Make a new recovery code (vault must be unlocked) */
    public static function RegenerateRecovery($uid)
    {
        $dataKey = self::Key();
        if (!$dataKey) {
            return false;
        }
        return self::NewRecoveryKit($uid, $dataKey);
    }

    private static function NewRecoveryKit($uid, $dataKey)
    {
        $code = self::NewRecoveryCode();
        $rsalt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $rkek = self::DeriveKey(self::NormalizeRecovery($code), $rsalt, VAULT_OPSLIMIT, VAULT_MEMLIMIT);
        run('UPDATE vault_keys SET recovery_salt = ?, recovery_wrapped_key = ?, opslimit = ?, memlimit = ? WHERE user_id = ?', array($rsalt, self::Wrap($dataKey, $rkek, $uid), VAULT_OPSLIMIT, VAULT_MEMLIMIT, $uid));
        return $code;
    }

    private static function Rewrap($uid, $dataKey, $newMaster, $hint)
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $kek = self::DeriveKey($newMaster, $salt, VAULT_OPSLIMIT, VAULT_MEMLIMIT);
        $data = array($salt, VAULT_OPSLIMIT, VAULT_MEMLIMIT, self::Wrap($dataKey, $kek, $uid), App::UtcNow());
        $sql = 'UPDATE vault_keys SET salt = ?, opslimit = ?, memlimit = ?, wrapped_key = ?, changed_at = ?';
        if ($hint !== null) {
            $sql .= ', hint = ?';
            $data[] = mb_substr($hint, 0, 128) ?: null;
        }
        $data[] = $uid;
        run($sql . ' WHERE user_id = ?', $data);
        sodium_memzero($kek);
    }

    /** Delete a vault and everything in it (used when the master password and recovery code are both lost) */
    public static function Destroy($uid)
    {
        run('DELETE h FROM vault_history h JOIN vault_items i ON i.id = h.item_id WHERE i.user_id = ?', array($uid));
        run('DELETE FROM vault_items WHERE user_id = ?', array($uid));
        run('DELETE FROM vault_folders WHERE user_id = ?', array($uid));
        run('DELETE FROM vault_keys WHERE user_id = ?', array($uid));
        if ($uid === App::UserId()) {
            self::Lock();
        }
    }

    /* ================= Session ================= */

    private static function StoreUnlocked($uid, $dataKey)
    {
        $cookieKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $minutes = (int)(val('SELECT auto_lock_minutes FROM vault_keys WHERE user_id = ?', array($uid)) ?: App::Setting('vault_auto_lock', 15));
        $_SESSION['vault'] = array(
            'uid' => $uid,
            'box' => base64_encode($nonce . sodium_crypto_secretbox($dataKey, $nonce, $cookieKey)),
            'minutes' => max(1, $minutes),
            'until' => time() + max(1, $minutes) * 60,
        );
        if (PHP_SAPI !== 'cli') {
            setcookie(self::COOKIE, base64_encode($cookieKey), array(
                'expires' => 0, 'path' => '/', 'secure' => self::Https(), 'httponly' => true, 'samesite' => 'Strict'
            ));
        }
        $_COOKIE[self::COOKIE] = base64_encode($cookieKey);
        self::$cachedKey = $dataKey;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function Lock()
    {
        unset($_SESSION['vault']);
        self::$cachedKey = null;
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(self::COOKIE, '', array('expires' => time() - 3600, 'path' => '/', 'secure' => self::Https(), 'httponly' => true, 'samesite' => 'Strict'));
        }
        unset($_COOKIE[self::COOKIE]);
    }

    public static function IsUnlocked()
    {
        return self::Key(false) !== null;
    }

    /** Seconds until auto-lock */
    public static function SecondsLeft()
    {
        return isset($_SESSION['vault']['until']) ? max(0, $_SESSION['vault']['until'] - time()) : 0;
    }

    /**
     * The data key, or null when locked.
     * @param bool $touch push the auto-lock time back (only on vault activity)
     */
    public static function Key($touch = true)
    {
        if (self::$cachedKey !== null && !empty($_SESSION['vault'])) {
            if ($touch) {
                $_SESSION['vault']['until'] = time() + $_SESSION['vault']['minutes'] * 60;
            }
            return self::$cachedKey;
        }
        $v = $_SESSION['vault'] ?? null;
        if (!$v || (int)$v['uid'] !== App::UserId() || time() > (int)$v['until'] || empty($_COOKIE[self::COOKIE])) {
            if ($v) {
                self::Lock();
            }
            return null;
        }
        $cookieKey = base64_decode($_COOKIE[self::COOKIE], true);
        $raw = base64_decode($v['box'], true);
        if ($cookieKey === false || strlen($cookieKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || $raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            self::Lock();
            return null;
        }
        $key = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $cookieKey);
        if ($key === false) {
            self::Lock();
            return null;
        }
        if ($touch) {
            $_SESSION['vault']['until'] = time() + $v['minutes'] * 60;
        }
        self::$cachedKey = $key;
        return $key;
    }

    /** Stops an ajax request or a page when the vault is locked */
    public static function RequireUnlocked()
    {
        if (self::Key() === null) {
            if (App::IsAjax()) {
                json_out(array('result' => false, 'locked' => true, 'message' => 'The vault is locked.'), 423);
            }
            App::Redirect('/vault');
        }
    }

    /* ================= Items ================= */

    public static function Encrypt(array $data, $uid = null, $key = null)
    {
        $uid = $uid ?: App::UserId();
        $key = $key ?: self::Key();
        if (!$key) {
            throw new RuntimeException('The vault is locked.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        return 'v1' . $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($json, 'home-vault-item:' . $uid, $nonce, $key);
    }

    public static function Decrypt($blob, $uid = null, $key = null)
    {
        $uid = $uid ?: App::UserId();
        $key = $key ?: self::Key();
        if (!$key || substr($blob, 0, 2) !== 'v1') {
            return null;
        }
        $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $json = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($blob, 2 + $n), 'home-vault-item:' . $uid, substr($blob, 2, $n), $key);
        return $json === false ? null : json_decode($json, true);
    }

    /** Every item for a person, decrypted, sorted by name */
    public static function Items($uid = null)
    {
        $uid = $uid ?: App::UserId();
        $key = self::Key();
        $out = array();
        foreach (rows('SELECT * FROM vault_items WHERE user_id = ?', array($uid)) as $r) {
            $d = self::Decrypt($r['data'], $uid, $key);
            if ($d === null) {
                continue;
            }
            unset($r['data']);
            $out[] = array_merge($r, array('d' => $d));
        }
        usort($out, function ($a, $b) {
            return strnatcasecmp($a['d']['name'] ?? '', $b['d']['name'] ?? '');
        });
        return $out;
    }

    public static function Item($id, $uid = null)
    {
        $uid = $uid ?: App::UserId();
        $r = row('SELECT * FROM vault_items WHERE id = ? AND user_id = ?', array($id, $uid));
        if (!$r) {
            return null;
        }
        $d = self::Decrypt($r['data'], $uid);
        if ($d === null) {
            return null;
        }
        unset($r['data']);
        $r['d'] = $d;
        return $r;
    }

    /** The fields each type stores */
    public static function Clean($type, array $in)
    {
        $type = isset(self::TYPES[$type]) ? $type : 'login';
        $s = function ($k, $max = 1000) use ($in) {
            return isset($in[$k]) && !is_array($in[$k]) ? mb_substr(trim((string)$in[$k]), 0, $max) : '';
        };
        $d = array('name' => $s('name', 200), 'notes' => $s('notes', 20000));
        switch ($type) {
            case 'login':
                $d += array('username' => $s('username', 300), 'password' => $s('password', 1000), 'url' => $s('url', 1000), 'totp' => $s('totp', 500));
                break;
            case 'card':
                $d += array('cardholder' => $s('cardholder', 100), 'number' => preg_replace('/[^0-9 ]/', '', $s('number', 40)), 'exp' => $s('exp', 10), 'cvv' => $s('cvv', 6), 'pin' => $s('pin', 12), 'brand' => $s('brand', 30));
                break;
            case 'wifi':
                $d += array('ssid' => $s('ssid', 100), 'password' => $s('password', 300), 'security' => $s('security', 20));
                break;
            case 'server':
                $d += array('host' => $s('host', 300), 'port' => $s('port', 6), 'username' => $s('username', 300), 'password' => $s('password', 1000), 'url' => $s('url', 1000));
                break;
        }
        // Custom fields: fields[label][], fields[value][], fields[hidden][]
        $fields = array();
        if (!empty($in['field_label']) && is_array($in['field_label'])) {
            foreach ($in['field_label'] as $i => $label) {
                $label = mb_substr(trim((string)$label), 0, 100);
                $value = mb_substr(trim((string)($in['field_value'][$i] ?? '')), 0, 2000);
                if ($label !== '' || $value !== '') {
                    $fields[] = array('label' => $label, 'value' => $value, 'hidden' => !empty($in['field_hidden'][$i]) && $in['field_hidden'][$i] !== '0');
                }
            }
        } elseif (!empty($in['fields']) && is_array($in['fields'])) {
            $fields = $in['fields'];
        }
        $d['fields'] = $fields;
        if ($d['name'] === '') {
            $d['name'] = $d['url'] ?? $d['ssid'] ?? $d['host'] ?? 'Untitled';
            if (!empty($d['url'])) {
                $host = parse_url(str_contains($d['url'], '://') ? $d['url'] : 'https://' . $d['url'], PHP_URL_HOST);
                $d['name'] = $host ? preg_replace('/^www\./', '', $host) : $d['url'];
            }
        }
        return array($type, $d);
    }

    /**
     * Create or update an item. Returns the id.
     */
    public static function Save($id, $type, array $data, $folderId = null, $favorite = 0, $source = null, $uid = null)
    {
        $uid = $uid ?: App::UserId();
        $now = App::UtcNow();
        list($type, $d) = self::Clean($type, $data);
        if ($folderId && !val('SELECT 1 FROM vault_folders WHERE id = ? AND user_id = ?', array($folderId, $uid))) {
            $folderId = null;
        }
        if ($id) {
            $old = self::Item($id, $uid);
            if (!$old) {
                throw new RuntimeException('Item not found.');
            }
            $pwChanged = ($old['d']['password'] ?? '') !== ($d['password'] ?? '');
            if ($pwChanged && !empty($old['d']['password'])) {
                insert('vault_history', array('item_id' => $id, 'data' => self::Encrypt(array('password' => $old['d']['password']), $uid), 'created_at' => $now));
                // keep the last 10
                $keep = db()->column('SELECT id FROM vault_history WHERE item_id = ? ORDER BY id DESC LIMIT 10', array($id));
                if ($keep) {
                    run('DELETE FROM vault_history WHERE item_id = ? AND id NOT IN (' . implode(',', array_map('intval', $keep)) . ')', array($id));
                }
            }
            $upd = array('type' => $type, 'folder_id' => $folderId, 'favorite' => $favorite ? 1 : 0, 'data' => self::Encrypt($d, $uid), 'updated_at' => $now);
            if ($pwChanged) {
                $upd['password_changed_at'] = $now;
            }
            update('vault_items', 'id', $id, $upd);
            return (int)$id;
        }
        return insert('vault_items', array(
            'user_id' => $uid, 'folder_id' => $folderId, 'type' => $type, 'data' => self::Encrypt($d, $uid),
            'favorite' => $favorite ? 1 : 0, 'source' => $source, 'password_changed_at' => $now, 'created_at' => $now, 'updated_at' => $now
        ));
    }

    public static function Delete($id, $uid = null)
    {
        $uid = $uid ?: App::UserId();
        if (run('DELETE FROM vault_items WHERE id = ? AND user_id = ?', array($id, $uid))) {
            run('DELETE FROM vault_history WHERE item_id = ?', array($id));
            return true;
        }
        return false;
    }

    public static function History($id, $uid = null)
    {
        $uid = $uid ?: App::UserId();
        if (!val('SELECT 1 FROM vault_items WHERE id = ? AND user_id = ?', array($id, $uid))) {
            return array();
        }
        $out = array();
        foreach (rows('SELECT * FROM vault_history WHERE item_id = ? ORDER BY id DESC', array($id)) as $h) {
            $d = self::Decrypt($h['data'], $uid);
            if ($d) {
                $out[] = array('password' => $d['password'] ?? '', 'created_at' => $h['created_at']);
            }
        }
        return $out;
    }

    public static function Folders($uid = null)
    {
        $uid = $uid ?: App::UserId();
        return rows('SELECT f.*, (SELECT COUNT(*) FROM vault_items i WHERE i.folder_id = f.id) AS items FROM vault_folders f WHERE f.user_id = ? ORDER BY f.sort, f.name', array($uid));
    }

    public static function FolderByName($name, $uid = null)
    {
        $uid = $uid ?: App::UserId();
        $name = mb_substr(trim($name), 0, 64);
        if ($name === '') {
            return null;
        }
        $id = val('SELECT id FROM vault_folders WHERE user_id = ? AND name = ?', array($uid, $name));
        return $id ? (int)$id : insert('vault_folders', array('user_id' => $uid, 'name' => $name, 'sort' => 0));
    }

    /* ================= Password health ================= */

    /**
     * Strength 0–4 (same scale as the browser meter in vault.js)
     */
    public static function Strength($pw)
    {
        $pw = (string)$pw;
        $len = mb_strlen($pw);
        if ($len === 0) {
            return 0;
        }
        static $common = null;
        if ($common === null) {
            $common = array_flip(array('password', 'password1', 'password123', '123456', '12345678', '123456789', '1234567890', 'qwerty', 'qwerty123', 'abc123', 'letmein', 'welcome', 'welcome1', 'admin', 'admin123', 'iloveyou', 'monkey', 'dragon', 'sunshine', 'princess', 'football', 'baseball', 'master', 'shadow', 'trustno1', '111111', '000000', 'passw0rd', 'changeme', 'secret', 'login', 'test', 'test123', 'root', 'toor'));
        }
        if (isset($common[strtolower($pw)])) {
            return 0;
        }
        $pool = 0;
        if (preg_match('/[a-z]/', $pw)) $pool += 26;
        if (preg_match('/[A-Z]/', $pw)) $pool += 26;
        if (preg_match('/[0-9]/', $pw)) $pool += 10;
        if (preg_match('/[^A-Za-z0-9]/', $pw)) $pool += 33;
        $bits = $len * log(max($pool, 1), 2);
        // repeated characters and simple sequences count for less
        $unique = count(array_unique(preg_split('//u', $pw, -1, PREG_SPLIT_NO_EMPTY)));
        if ($unique < $len / 2) {
            $bits *= 0.6;
        }
        if (preg_match('/(0123|1234|2345|3456|4567|5678|6789|abcd|qwer|asdf|zxcv)/i', $pw)) {
            $bits -= 10;
        }
        // word + number + symbol ("Summer2024!") is weaker than it looks
        if (preg_match('/^[A-Za-z][a-z]{2,}\d{1,4}[^A-Za-z0-9]?$/', $pw)) {
            $bits = min($bits, 35);
        }
        if ($bits < 28) return 0;
        if ($bits < 40) return 1;
        if ($bits < 60) return 2;
        if ($bits < 80) return 3;
        return 4;
    }

    /**
     * Health report for a person's logins
     */
    public static function Health($items = null)
    {
        $items = $items ?? self::Items();
        $byPassword = array();
        foreach ($items as $it) {
            $pw = $it['d']['password'] ?? '';
            if ($pw !== '' && in_array($it['type'], array('login', 'server', 'wifi'), true)) {
                $byPassword[hash('sha256', $pw)][] = $it['id'];
            }
        }
        $weak = $reused = $old = $no2fa = array();
        $withPassword = 0;
        $yearAgo = gmdate('Y-m-d H:i:s', strtotime('-1 year'));
        foreach ($items as $it) {
            $pw = $it['d']['password'] ?? '';
            if ($pw === '' || !in_array($it['type'], array('login', 'server', 'wifi'), true)) {
                continue;
            }
            $withPassword++;
            $score = self::Strength($pw);
            $it['strength'] = $score;
            if ($score <= 1) {
                $weak[] = $it;
            }
            $group = $byPassword[hash('sha256', $pw)];
            if (count($group) > 1) {
                $it['reused_count'] = count($group);
                $reused[] = $it;
            }
            if (!empty($it['password_changed_at']) && $it['password_changed_at'] < $yearAgo) {
                $old[] = $it;
            }
            if ($it['type'] === 'login' && empty($it['d']['totp'])) {
                $no2fa[] = $it;
            }
        }
        $problems = count(array_unique(array_merge(array_column($weak, 'id'), array_column($reused, 'id'))));
        $score = $withPassword ? (int)round(100 * (1 - $problems / $withPassword) - min(15, count($old) / max(1, $withPassword) * 15)) : 100;
        return array(
            'score' => max(0, min(100, $score)),
            'total' => $withPassword,
            'weak' => $weak,
            'reused' => $reused,
            'old' => $old,
            'no2fa' => $no2fa,
        );
    }

    /* ================= Generator ================= */

    public static function Generate($length = 20, $upper = true, $digits = true, $symbols = true)
    {
        $length = max(8, min(128, (int)$length));
        $sets = array('abcdefghijkmnopqrstuvwxyz');
        if ($upper) $sets[] = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        if ($digits) $sets[] = '23456789';
        if ($symbols) $sets[] = '!@#$%^&*-_=+?';
        $all = implode('', $sets);
        $pw = array();
        foreach ($sets as $s) {
            $pw[] = $s[random_int(0, strlen($s) - 1)];
        }
        while (count($pw) < $length) {
            $pw[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($pw) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            list($pw[$i], $pw[$j]) = array($pw[$j], $pw[$i]);
        }
        return implode('', $pw);
    }

    /* ================= Helpers ================= */

    private static function DeriveKey($password, $salt, $ops, $mem)
    {
        return sodium_crypto_pwhash(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, $password, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }

    private static function Wrap($dataKey, $kek, $uid)
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dataKey, 'home-vault-key:' . $uid, $nonce, $kek);
    }

    private static function Unwrap($wrapped, $kek, $uid)
    {
        $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($wrapped, $n), 'home-vault-key:' . $uid, substr($wrapped, 0, $n), $kek);
    }

    /** "ABCD-EFGH-…" (8 groups of 4, base32 without look-alike letters) */
    private static function NewRecoveryCode()
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $groups = array();
        for ($g = 0; $g < 8; $g++) {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $s;
        }
        return implode('-', $groups);
    }

    private static function NormalizeRecovery($code)
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$code));
    }

    private static function Https()
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}
