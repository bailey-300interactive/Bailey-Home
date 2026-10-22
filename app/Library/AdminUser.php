<?php

/**
 * Admin User Class (framework class, hardened)
 * @author Bailey Rotellini
 */
class AdminUser extends Crud
{
    protected $table = 'admin_users';
    protected $pk = 'Id_admin_users';
    protected $settingsTable = 'user_level_settings';

    const MAX_TRIES = 5;
    const LOCK_MINUTES = 10;

    public function __construct($Id = false)
    {
        parent::__construct($Id);
    }

    public static function FindByUsername($username)
    {
        return row('SELECT * FROM admin_users WHERE username = ? OR email = ? LIMIT 1', array(trim((string)$username), trim((string)$username)));
    }

    /** Changing a password also signs out every "kept signed in" device */
    public static function SetPassword($uid, $password, $keepSelector = null)
    {
        run('UPDATE admin_users SET password = ?, reset_token = NULL, reset_expires = NULL, reset_password = 0, failed_logins = 0, locked_until = NULL WHERE Id_admin_users = ?', array(password_hash($password, PASSWORD_DEFAULT), $uid));
        self::ForgetAll($uid, $keepSelector);
    }

    /* ================= Keep me signed in =================
     *
     * Cookie "home_remember" = selector.validator (random, 12 + 32 bytes).
     * The database keeps the selector and only a SHA-256 hash of the validator, so a copy of the
     * database can't be turned into a working cookie. The cookie is HttpOnly + Secure + SameSite=Lax.
     * The validator changes every time the cookie is used to sign back in. The old one keeps working
     * for 2 minutes, so pages that fire several requests at once don't sign you out.
     * Signing out, changing your password, or "Sign out other devices" deletes the tokens. A turned-off
     * login can't come back in. The vault never comes back from the cookie: it stays locked.
     * Each device keeps its form (CSRF) token, so a tab left open still works after the session is rebuilt.
     */

    const REMEMBER_COOKIE = 'home_remember';
    const REMEMBER_GRACE = 120;

    /** Remember this device for REMEMBER_DAYS */
    public static function Remember($uid)
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        insert('auth_tokens', array(
            'user_id' => $uid, 'selector' => $selector, 'validator_hash' => hash('sha256', $validator), 'csrf' => App::CsrfToken(),
            'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), 'ip' => App::ClientIp(),
            'created_at' => App::UtcNow(), 'last_used_at' => App::UtcNow(), 'expires_at' => gmdate('Y-m-d H:i:s', time() + REMEMBER_DAYS * 86400),
        ));
        self::SetRememberCookie($selector . '.' . $validator);
        $_SESSION['remember_selector'] = $selector;
    }

    /** Sign back in from the cookie. Returns true when it worked. */
    public static function ResumeFromCookie()
    {
        $raw = (string)($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if (!preg_match('/^([a-f0-9]{24})\.([a-f0-9]{64})$/', $raw, $m)) {
            self::ClearRememberCookie();
            return false;
        }
        list(, $selector, $validator) = $m;
        if (!App::RateLimit('remember-ip:' . App::ClientIp(), 60, 900)) {
            return false;
        }
        $t = row('SELECT * FROM auth_tokens WHERE selector = ?', array($selector));
        $hash = hash('sha256', $validator);
        $ok = $t && $t['expires_at'] > App::UtcNow() && (hash_equals($t['validator_hash'], $hash)
            || ($t['prev_hash'] && hash_equals($t['prev_hash'], $hash) && strtotime($t['rotated_at'] . ' UTC') > time() - self::REMEMBER_GRACE));
        $u = $ok ? row('SELECT * FROM admin_users WHERE Id_admin_users = ? AND active = 1', array($t['user_id'])) : null;
        if (!$u) {
            if ($t && !$ok && $t['expires_at'] <= App::UtcNow()) {
                run('DELETE FROM auth_tokens WHERE id = ?', array($t['id']));
            }
            self::ClearRememberCookie();
            return false;
        }

        self::StartSession($u);
        $_SESSION['remember_selector'] = $selector;
        // Same form token as before, so pages that were already open keep working
        if (!empty($t['csrf'])) {
            $_SESSION['csrf'] = $t['csrf'];
        }
        if (hash_equals($t['validator_hash'], $hash)) {
            // New validator for next time, and push the expiry out
            $next = bin2hex(random_bytes(32));
            run('UPDATE auth_tokens SET prev_hash = validator_hash, validator_hash = ?, rotated_at = ?, last_used_at = ?, ip = ?, expires_at = ? WHERE id = ?',
                array(hash('sha256', $next), App::UtcNow(), App::UtcNow(), App::ClientIp(), gmdate('Y-m-d H:i:s', time() + REMEMBER_DAYS * 86400), $t['id']));
            self::SetRememberCookie($selector . '.' . $next);
        }
        return true;
    }

    /** Forget this device (signing out) */
    public static function ForgetThisDevice()
    {
        $raw = (string)($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        $selector = $_SESSION['remember_selector'] ?? (preg_match('/^([a-f0-9]{24})\./', $raw, $m) ? $m[1] : null);
        if ($selector) {
            run('DELETE FROM auth_tokens WHERE selector = ?', array($selector));
        }
        self::ClearRememberCookie();
    }

    /** Forget every device for a person, except $keepSelector (this one) when given */
    public static function ForgetAll($uid, $keepSelector = null)
    {
        run('DELETE FROM auth_tokens WHERE user_id = ? AND selector <> ?', array($uid, (string)$keepSelector));
    }

    /** Devices that are kept signed in, newest first */
    public static function Devices($uid)
    {
        $list = rows('SELECT selector, user_agent, ip, created_at, last_used_at FROM auth_tokens WHERE user_id = ? AND expires_at > ? ORDER BY last_used_at DESC', array($uid, App::UtcNow()));
        foreach ($list as &$d) {
            $d['name'] = self::DeviceName($d['user_agent']);
            $d['current'] = $d['selector'] === ($_SESSION['remember_selector'] ?? '');
            unset($d['selector']);
        }
        return $list;
    }

    /** "Safari on iPhone" from a user agent */
    public static function DeviceName($ua)
    {
        $ua = (string)$ua;
        $os = str_contains($ua, 'iPhone') ? 'iPhone' : (str_contains($ua, 'iPad') ? 'iPad' : (str_contains($ua, 'Android') ? 'Android'
            : (str_contains($ua, 'Mac OS X') ? 'Mac' : (str_contains($ua, 'Windows') ? 'Windows' : (str_contains($ua, 'Linux') ? 'Linux' : 'Unknown device')))));
        $browser = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/(Chrome|CriOS)\//', $ua) ? 'Chrome' : (preg_match('/(Firefox|FxiOS)\//', $ua) ? 'Firefox'
            : (str_contains($ua, 'Safari') ? 'Safari' : '')));
        return $browser ? "$browser on $os" : $os;
    }

    private static function SetRememberCookie($value)
    {
        setcookie(self::REMEMBER_COOKIE, $value, array('expires' => time() + REMEMBER_DAYS * 86400, 'path' => '/', 'secure' => App::IsHttps(), 'httponly' => true, 'samesite' => 'Lax'));
        $_COOKIE[self::REMEMBER_COOKIE] = $value;
    }

    private static function ClearRememberCookie()
    {
        if (!headers_sent()) {
            setcookie(self::REMEMBER_COOKIE, '', array('expires' => time() - 3600, 'path' => '/', 'secure' => App::IsHttps(), 'httponly' => true, 'samesite' => 'Lax'));
        }
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    public static function PasswordProblem($password)
    {
        if (strlen($password) < 12) {
            return 'Use at least 12 characters.';
        }
        if (Vault::Strength($password) < 2) {
            return 'That password is too easy to guess. Try a few random words together.';
        }
        return null;
    }

    /**
     * Check a username and password. Returns array(status, user) where status is
     * ok | 2fa | locked | wrong | inactive
     */
    public static function Attempt($username, $password)
    {
        $u = self::FindByUsername($username);
        if (!$u) {
            password_verify($password, '$2y$10$p6NpzoRKndDoelZStgeF5ufKFu2ojGHUJ.stU92aiL6rip1BiUg8i'); // same timing as a real check
            return array('wrong', null);
        }
        if (!$u['active']) {
            return array('inactive', null);
        }
        if ($u['locked_until'] && $u['locked_until'] > App::UtcNow()) {
            return array('locked', $u);
        }
        if (!$u['password'] || !password_verify($password, $u['password'])) {
            $fails = (int)$u['failed_logins'] + 1;
            $lock = $fails >= self::MAX_TRIES ? gmdate('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60) : null;
            run('UPDATE admin_users SET failed_logins = ?, locked_until = ? WHERE Id_admin_users = ?', array($lock ? 0 : $fails, $lock, $u['Id_admin_users']));
            return array($lock ? 'locked' : 'wrong', $u);
        }
        if (password_needs_rehash($u['password'], PASSWORD_DEFAULT)) {
            run('UPDATE admin_users SET password = ? WHERE Id_admin_users = ?', array(password_hash($password, PASSWORD_DEFAULT), $u['Id_admin_users']));
        }
        run('UPDATE admin_users SET failed_logins = 0, locked_until = NULL WHERE Id_admin_users = ?', array($u['Id_admin_users']));
        return array($u['totp_enabled'] ? '2fa' : 'ok', $u);
    }

    /** Start the session for a user (framework session variables) */
    public static function StartSession(array $u)
    {
        session_regenerate_id(true);
        $level = row('SELECT * FROM user_level_settings WHERE Id_user_level_settings = ?', array($u['auth_level']));
        $_SESSION['authenticated'] = true;
        $_SESSION['user_Id'] = (int)$u['Id_admin_users'];
        $_SESSION['username'] = $u['username'];
        $_SESSION['name'] = trim($u['first_name'] . ' ' . $u['last_name']);
        $_SESSION['first_name'] = $u['first_name'];
        $_SESSION['title'] = $u['title'];
        $_SESSION['auth_level'] = (int)$u['auth_level'];
        $_SESSION['user_folder'] = $level['folder'] ?? 'user';
        $_SESSION['auth_text'] = $level['display_name'] ?? 'Member';
        $_SESSION['site_theme'] = $u['site_theme'];
        $_SESSION['profile_image'] = $u['profile_image'];
        unset($_SESSION['pending_2fa']);
        run('UPDATE admin_users SET last_login = ? WHERE Id_admin_users = ?', array(App::UtcNow(), $u['Id_admin_users']));
    }

    /** Email a reset link. Always looks the same to the caller. */
    public static function SendReset($username)
    {
        $u = self::FindByUsername($username);
        if (!$u || !$u['active']) {
            return;
        }
        $token = token(32);
        run('UPDATE admin_users SET reset_token = ?, reset_expires = ?, reset_password = 1 WHERE Id_admin_users = ?', array(hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 3600), $u['Id_admin_users']));
        Mailer::Queue($u['email'] ?: $u['username'], $u['first_name'], 'Reset your password', 'reset-password', array(
            'first_name' => $u['first_name'], 'url' => App::BaseUrl() . '/reset-password?token=' . $token
        ));
        Mailer::Flush(5);
    }

    public static function FindByResetToken($token)
    {
        if (!is_string($token) || strlen($token) < 20) {
            return null;
        }
        $u = row('SELECT * FROM admin_users WHERE reset_token = ? AND reset_password = 1 AND reset_expires > ?', array(hash('sha256', $token), App::UtcNow()));
        return $u ?: null;
    }

    public static function ListAll()
    {
        return rows('SELECT u.*, l.display_name AS level_name, (SELECT 1 FROM vault_keys k WHERE k.user_id = u.Id_admin_users) AS has_vault FROM admin_users u LEFT JOIN user_level_settings l ON l.Id_user_level_settings = u.auth_level ORDER BY u.first_name, u.username');
    }

    public static function Options()
    {
        return rows("SELECT Id_admin_users AS id, TRIM(CONCAT(first_name, ' ', last_name)) AS name FROM admin_users WHERE active = 1 ORDER BY first_name");
    }
}
