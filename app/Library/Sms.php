<?php

/**
 * Text messages through Twilio (settings/sms.ini.php).
 *
 * Security: uses a Twilio *restricted* API key that can only send messages, over HTTPS with certificate checks.
 * Nothing on this site receives calls from Twilio, so there's no webhook to protect. STOP / HELP replies are
 * handled by Twilio itself. Each person gets at most DailyLimit texts a day.
 */
class Sms
{
    const ENDPOINT = 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json';

    /** settings/sms.ini.php, or null when texting isn't set up */
    public static function Config()
    {
        $file = ROOT . 'settings/sms.ini.php';
        if (!is_file($file)) {
            return null;
        }
        $s = @parse_ini_file($file);
        if (!$s) {
            return null;
        }
        $s += array('Driver' => 'twilio', 'AccountSid' => '', 'ApiKeySid' => '', 'ApiKeySecret' => '', 'From' => '', 'DailyLimit' => 25);
        return $s;
    }

    public static function Enabled()
    {
        $s = self::Config();
        return $s && (strtolower($s['Driver']) === 'log' || ($s['AccountSid'] && $s['ApiKeySid'] && $s['ApiKeySecret'] && $s['From']));
    }

    /** "(724) 555-0123" → "+17245550123". US/Canada numbers, or anything already starting with + */
    public static function Normalize($phone)
    {
        $phone = trim((string)$phone);
        $plus = str_starts_with($phone, '+');
        $digits = preg_replace('/\D/', '', $phone);
        if ($plus && strlen($digits) >= 8 && strlen($digits) <= 15) {
            return '+' . $digits;
        }
        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }
        if (strlen($digits) === 11 && $digits[0] === '1') {
            return '+' . $digits;
        }
        return null;
    }

    /** "+17245550123" → "(724) 555-0123" */
    public static function Pretty($phone)
    {
        return preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', (string)$phone, $m) ? "($m[1]) $m[2]-$m[3]" : (string)$phone;
    }

    /**
     * Keep texts cheap: plain characters only (one emoji makes the whole text cost 2–3x),
     * and at most 3 segments.
     */
    public static function Clean($body)
    {
        $body = strtr((string)$body, array('’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', '…' => '...', '·' => '-', ' ' => ' '));
        $body = preg_replace('/[^\x20-\x7E\n]/u', '', $body);
        $body = trim(preg_replace("/[ \t]+/", ' ', $body));
        return mb_strlen($body) > 450 ? mb_substr($body, 0, 447) . '...' : $body;
    }

    /** Add a text to the queue. dev/cron.php sends it. */
    public static function Queue($userId, $phone, $body)
    {
        $phone = self::Normalize($phone);
        if (!$phone || !self::Enabled()) {
            return false;
        }
        insert('sms_queue', array('user_id' => $userId ?: null, 'to_phone' => $phone, 'body' => self::Clean($body), 'created_at' => App::UtcNow()));
        return true;
    }

    /** Send up to $limit queued texts. Returns how many went out. */
    public static function Flush($limit = 20)
    {
        if (!self::Enabled()) {
            return 0;
        }
        $cap = max(1, (int)(self::Config()['DailyLimit'] ?? 25));
        $sent = 0;
        foreach (rows('SELECT * FROM sms_queue WHERE sent_at IS NULL AND attempts < 3 ORDER BY id LIMIT ' . (int)$limit) as $q) {
            if ($q['user_id'] && (int)val('SELECT COUNT(*) FROM sms_queue WHERE user_id = ? AND sent_at >= ?', array($q['user_id'], gmdate('Y-m-d H:i:s', time() - 86400))) >= $cap) {
                run('UPDATE sms_queue SET attempts = 3, last_error = ? WHERE id = ?', array('Skipped: daily limit of ' . $cap . ' texts reached', $q['id']));
                continue;
            }
            $r = self::Send($q['to_phone'], $q['body']);
            if ($r['ok']) {
                run('UPDATE sms_queue SET sent_at = ?, attempts = attempts + 1, provider_id = ?, last_error = NULL WHERE id = ?', array(App::UtcNow(), $r['id'], $q['id']));
                $sent++;
            } else {
                run('UPDATE sms_queue SET attempts = attempts + 1, last_error = ? WHERE id = ?', array(mb_substr($r['error'], 0, 255), $q['id']));
                (new Log())->write('SMS to ' . $q['to_phone'] . ' failed: ' . $r['error']);
            }
        }
        return $sent;
    }

    /**
     * Send one text now. Returns array('ok' => bool, 'id' => provider message id, 'error' => text)
     */
    public static function Send($to, $body)
    {
        $s = self::Config();
        $to = self::Normalize($to);
        if (!$s || !$to) {
            return array('ok' => false, 'id' => null, 'error' => !$s ? 'Texting is not set up (settings/sms.ini.php)' : 'Not a valid phone number');
        }
        $body = self::Clean($body);
        if (strtolower($s['Driver']) === 'log') {
            @file_put_contents(ROOT . 'storage/logs/sms-' . gmdate('Y-m-d') . '.txt', gmdate('H:i:s') . " To: $to\n$body\n" . str_repeat('-', 40) . "\n", FILE_APPEND);
            return array('ok' => true, 'id' => 'log', 'error' => null);
        }
        $ch = curl_init(sprintf(self::ENDPOINT, rawurlencode($s['AccountSid'])));
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(array('To' => $to, 'From' => $s['From'], 'Body' => $body)),
            CURLOPT_USERPWD => $s['ApiKeySid'] . ':' . $s['ApiKeySecret'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
        ));
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return array('ok' => false, 'id' => null, 'error' => 'Could not reach Twilio: ' . $curlErr);
        }
        $json = json_decode($raw, true) ?: array();
        if ($code >= 200 && $code < 300 && !empty($json['sid'])) {
            return array('ok' => true, 'id' => $json['sid'], 'error' => null);
        }
        return array('ok' => false, 'id' => null, 'error' => 'Twilio ' . $code . (isset($json['code']) ? ' / error ' . $json['code'] : '') . ': ' . ($json['message'] ?? 'unknown error'));
    }

    /* ================= Phone verification ================= */

    /** Text a 6-digit code to confirm the number belongs to this person */
    public static function StartVerify($uid, $phone)
    {
        $phone = self::Normalize($phone);
        if (!$phone) {
            throw new RuntimeException('Enter a US mobile number like (724) 555-0123, or a full number starting with +.');
        }
        if (!App::RateLimit('sms-verify:' . $uid, 5, 3600)) {
            throw new RuntimeException('Too many codes. Try again in an hour.');
        }
        $code = (string)random_int(100000, 999999);
        $_SESSION['sms_verify'] = array('phone' => $phone, 'hash' => password_hash($code, PASSWORD_DEFAULT), 'until' => time() + 600, 'tries' => 0);
        $r = self::Send($phone, $code . ' is your ' . App::Setting('site_name', 'Home') . ' code. It expires in 10 minutes.');
        if (!$r['ok']) {
            unset($_SESSION['sms_verify']);
            throw new RuntimeException('Couldn\'t send the code: ' . $r['error']);
        }
        return $phone;
    }

    /** Check the code. On success saves the phone as verified. */
    public static function FinishVerify($uid, $code)
    {
        $v = $_SESSION['sms_verify'] ?? null;
        if (!$v || time() > $v['until'] || $v['tries'] >= 5) {
            unset($_SESSION['sms_verify']);
            throw new RuntimeException('That code expired. Send a new one.');
        }
        $_SESSION['sms_verify']['tries']++;
        if (!password_verify(preg_replace('/\D/', '', (string)$code), $v['hash'])) {
            throw new RuntimeException('That code isn\'t right.');
        }
        run('UPDATE admin_users SET phone = ?, phone_verified_at = ? WHERE Id_admin_users = ?', array($v['phone'], App::UtcNow(), $uid));
        unset($_SESSION['sms_verify']);
        return $v['phone'];
    }
}
