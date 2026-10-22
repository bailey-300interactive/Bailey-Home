<?php

/**
 * Class for essential app functions (framework App class, extended for Home)
 * Only static functions live here.
 * @author Bailey Rotellini
 */

class App
{
    private static $settings = null;
    private static $user = false;

    public function __construct() {}

    /**
     * Variables the framework's base views expect
     * @return array
     */
    public static function GetSiteProfileVariables()
    {
        $name = self::Setting('site_name', 'Home');
        return array(
            'site_name' => $name,
            'page_title_base' => $name,
            'email' => self::Setting('company_email', ''),
            'public' => false,
            'theme' => 'default',
            'demo_mode' => false,
            'show_search' => true,
            'show_maximize' => true
        );
    }

    /* ---------------- Settings ---------------- */

    public static function Settings()
    {
        if (self::$settings === null) {
            self::$settings = array();
            try {
                foreach (db()->query('SELECT k, v FROM settings') as $r) {
                    self::$settings[$r['k']] = $r['v'];
                }
            } catch (Throwable $e) {
                // before install
            }
        }
        return self::$settings;
    }

    public static function Setting($key, $default = null)
    {
        $s = self::Settings();
        return (isset($s[$key]) && $s[$key] !== '') ? $s[$key] : $default;
    }

    public static function SaveSetting($key, $value)
    {
        db()->query('INSERT INTO settings (k, v) VALUES (:k, :v) ON DUPLICATE KEY UPDATE v = VALUES(v)', array('k' => $key, 'v' => (string)$value));
        self::Settings();
        self::$settings[$key] = (string)$value;
    }

    /* ---------------- Auth ---------------- */

    public static function Redirect($location)
    {
        if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            $location = BASE_URL . $location;
        }
        header('Location: ' . $location);
        exit();
    }

    public static function IsAuthenticated()
    {
        return !empty($_SESSION['authenticated']) && $_SESSION['authenticated'] === true && !empty($_SESSION['user_Id']);
    }

    /** Framework style: lower number = more access */
    public static function UserCanAccessPage($auth)
    {
        return self::IsAuthenticated() && isset($_SESSION['auth_level']) && (int)$_SESSION['auth_level'] <= $auth;
    }

    public static function IsAdmin()
    {
        return self::UserCanAccessPage(ADMIN);
    }

    public static function GetAuthLevelText()
    {
        return !empty($_SESSION['user_folder']) ? $_SESSION['user_folder'] : false;
    }

    public static function UserId()
    {
        return self::IsAuthenticated() ? (int)$_SESSION['user_Id'] : 0;
    }

    /** The signed-in person's admin_users row */
    public static function User()
    {
        if (self::$user === false) {
            self::$user = self::IsAuthenticated() ? row('SELECT * FROM admin_users WHERE Id_admin_users = ?', array(self::UserId())) : null;
        }
        return self::$user;
    }

    public static function RequireLogin()
    {
        if (!self::IsAuthenticated()) {
            if (self::IsAjax()) {
                json_out(array('result' => false, 'message' => 'Your session has ended. Sign in again.'), 401);
            }
            $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/';
            self::Redirect('/login');
        }
    }

    public static function RequireAdmin()
    {
        self::RequireLogin();
        if (!self::IsAdmin()) {
            self::Forbidden();
        }
    }

    public static function IsAjax()
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || str_contains($_SERVER['REQUEST_URI'] ?? '', '/app/Ajax/');
    }

    /* ---------------- CSRF ---------------- */

    public static function CsrfToken()
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function CsrfField()
    {
        return '<input type="hidden" name="csrf" value="' . self::CsrfToken() . '">';
    }

    /** Stops the request when the token is missing or wrong */
    public static function CheckCsrf()
    {
        $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (empty($_SESSION['csrf']) || !is_string($sent) || !hash_equals($_SESSION['csrf'], $sent)) {
            if (self::IsAjax()) {
                json_out(array('result' => false, 'message' => 'This page expired. Reload it and try again.'), 419);
            }
            self::QueueMessage('That form expired. Please try again.', 'error');
            self::Redirect($_SERVER['HTTP_REFERER'] ?? '/');
        }
    }

    /* ---------------- Messages ---------------- */

    public static function QueueMessage($message, $type = 'success')
    {
        if (!isset($_SESSION['messages'])) {
            $_SESSION['messages'] = array();
        }
        $_SESSION['messages'][] = array('message' => $message, 'type' => $type);
    }

    public static function CompileMessages()
    {
        if (!empty($_SESSION['messages'])) {
            $messages = $_SESSION['messages'];
            unset($_SESSION['messages']);
            return $messages;
        }
        return array();
    }

    /* ---------------- Errors ---------------- */

    public static function NotFound($message = 'That page does not exist.')
    {
        throw new HttpException($message, 404);
    }

    public static function Forbidden($message = 'You do not have access to that.')
    {
        throw new HttpException($message, 403);
    }

    /* ---------------- Rate limiting ---------------- */

    /**
     * Count a hit for $key. Returns false when over $max hits in $windowSeconds.
     */
    public static function RateLimit($key, $max, $windowSeconds)
    {
        $key = substr(hash('sha256', $key), 0, 64);
        $now = time();
        $r = row('SELECT hits, window_start FROM rate_limits WHERE k = ?', array($key));
        if (!$r || $now - (int)$r['window_start'] > $windowSeconds) {
            run('REPLACE INTO rate_limits (k, hits, window_start) VALUES (?, 1, ?)', array($key, $now));
            return true;
        }
        run('UPDATE rate_limits SET hits = hits + 1 WHERE k = ?', array($key));
        return ((int)$r['hits'] + 1) <= $max;
    }

    public static function IsHttps()
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    public static function ClientIp()
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /* ---------------- Time ---------------- */

    public static function Timezone()
    {
        $u = self::User();
        $tz = !empty($u['timezone']) ? $u['timezone'] : self::Setting('timezone', 'America/New_York');
        try {
            return new DateTimeZone($tz);
        } catch (Throwable $e) {
            return new DateTimeZone('UTC');
        }
    }

    /** Now in the person's time zone */
    public static function Now()
    {
        return new DateTime('now', self::Timezone());
    }

    /** UTC "Y-m-d H:i:s" from the database → DateTime in the person's time zone */
    public static function Local($utc)
    {
        if (empty($utc)) {
            return null;
        }
        $d = new DateTime($utc, new DateTimeZone('UTC'));
        $d->setTimezone(self::Timezone());
        return $d;
    }

    /** Local "Y-m-d H:i" typed by the person → UTC "Y-m-d H:i:s" for the database */
    public static function ToUtc($local)
    {
        if (empty($local)) {
            return null;
        }
        try {
            $d = new DateTime($local, self::Timezone());
        } catch (Throwable $e) {
            return null;
        }
        $d->setTimezone(new DateTimeZone('UTC'));
        return $d->format('Y-m-d H:i:s');
    }

    public static function UtcNow()
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** First day of the week for the person's week (Monday or Sunday) as Y-m-d */
    public static function WeekStart($date = null)
    {
        $d = new DateTime($date ?: today(), self::Timezone());
        $startsMonday = self::Setting('week_start', 'monday') === 'monday';
        $dow = (int)$d->format('N'); // 1 = Mon … 7 = Sun
        $back = $startsMonday ? $dow - 1 : ($dow % 7);
        $d->modify('-' . $back . ' days');
        return $d->format('Y-m-d');
    }

    /* ---------------- Activity ---------------- */

    public static function Activity($module, $text, $link = null)
    {
        try {
            run('INSERT INTO activity (user_id, module, text, link, created_at) VALUES (?, ?, ?, ?, ?)', array(self::UserId() ?: null, $module, mb_substr($text, 0, 255), $link, self::UtcNow()));
        } catch (Throwable $e) {
        }
    }

    /* ---------------- URLs ---------------- */

    public static function BaseUrl()
    {
        if (BASE_URL !== '') {
            return BASE_URL;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    /* ---------------- Menu ---------------- */

    /**
     * Sidebar menu. 'match' is the first URL segment that marks the item active.
     */
    public static function GetMenu()
    {
        if (!self::IsAuthenticated()) {
            return false;
        }
        $main = array(
            array('header' => 'Home'),
            array('name' => 'Dashboard', 'link' => '/', 'icon' => 'grid', 'match' => 'index'),
            array('name' => 'Reminders', 'link' => '/reminders', 'icon' => 'bell', 'match' => 'reminders', 'badge' => Reminder::DueCount()),
            array('name' => 'Vault', 'link' => '/vault', 'icon' => 'lock', 'match' => 'vault', 'sub_items' => array(
                array('name' => 'All items', 'link' => '/vault'),
                array('name' => 'Password health', 'link' => '/vault/health'),
                array('name' => 'Generator', 'link' => '/vault/generator'),
                array('name' => 'Import & export', 'link' => '/vault/import'),
            )),
            array('header' => 'Money'),
            array('name' => 'Money', 'link' => '/money', 'icon' => 'dollar-sign', 'match' => 'money', 'sub_items' => array(
                array('name' => 'Overview', 'link' => '/money'),
                array('name' => 'Transactions', 'link' => '/money/transactions'),
                array('name' => 'Budgets', 'link' => '/money/budgets'),
                array('name' => 'Bills & subscriptions', 'link' => '/money/bills'),
                array('name' => 'Accounts', 'link' => '/money/accounts'),
                array('name' => 'Goals', 'link' => '/money/goals'),
                array('name' => 'Reports', 'link' => '/money/reports'),
                array('name' => 'Import', 'link' => '/money/import'),
                array('name' => 'Categories & rules', 'link' => '/money/categories'),
            )),
            array('header' => 'Work'),
            array('name' => 'Time tracking', 'link' => '/time', 'icon' => 'clock', 'match' => 'time', 'sub_items' => array(
                array('name' => 'Time', 'link' => '/time'),
                array('name' => 'Timesheet', 'link' => '/time/timesheet'),
                array('name' => 'Clients', 'link' => '/time/clients'),
                array('name' => 'Projects', 'link' => '/time/projects'),
                array('name' => 'Invoices', 'link' => '/time/invoices'),
                array('name' => 'Reports', 'link' => '/time/reports'),
                array('name' => 'Services', 'link' => '/time/services'),
            )),
        );
        if (self::IsAdmin()) {
            $main[] = array('header' => 'Admin');
            $main[] = array('name' => 'Settings', 'link' => '/admin/settings', 'icon' => 'settings', 'match' => 'admin', 'sub_items' => array(
                array('name' => 'Site settings', 'link' => '/admin/settings'),
                array('name' => 'Users', 'link' => '/admin/users'),
            ));
        }
        return array('main' => $main, 'footer' => false);
    }
}

/** Thrown by App::NotFound / App::Forbidden, shown by the router's error page */
class HttpException extends Exception
{
}
