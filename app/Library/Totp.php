<?php

/**
 * Time-based one-time passwords (RFC 6238), used for login 2-step verification
 * and for the live 2FA codes shown on vault items.
 */
class Totp
{
    const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function NewSecret($bytes = 20)
    {
        return self::Base32Encode(random_bytes($bytes));
    }

    /**
     * Accepts a bare base32 secret or an otpauth:// URI. Returns array(secret, digits, period, algo) or null.
     */
    public static function Parse($input)
    {
        $input = trim((string)$input);
        if ($input === '') {
            return null;
        }
        $digits = 6;
        $period = 30;
        $algo = 'sha1';
        $secret = $input;
        if (stripos($input, 'otpauth://') === 0) {
            $q = array();
            parse_str((string)parse_url($input, PHP_URL_QUERY), $q);
            $secret = $q['secret'] ?? '';
            $digits = isset($q['digits']) ? max(6, min(8, (int)$q['digits'])) : 6;
            $period = isset($q['period']) ? max(10, min(120, (int)$q['period'])) : 30;
            $algo = isset($q['algorithm']) && in_array(strtolower($q['algorithm']), array('sha1', 'sha256', 'sha512'), true) ? strtolower($q['algorithm']) : 'sha1';
        }
        $secret = strtoupper(preg_replace('/[\s=-]/', '', $secret));
        if ($secret === '' || preg_match('/[^A-Z2-7]/', $secret)) {
            return null;
        }
        return array('secret' => $secret, 'digits' => $digits, 'period' => $period, 'algo' => $algo);
    }

    /** Current code for a secret/URI. Returns array(code, seconds_left, period) or null */
    public static function Now($input, $time = null)
    {
        $p = self::Parse($input);
        if (!$p) {
            return null;
        }
        $time = $time ?? time();
        $counter = intdiv($time, $p['period']);
        return array(
            'code' => self::Code($p['secret'], $counter, $p['digits'], $p['algo']),
            'left' => $p['period'] - ($time % $p['period']),
            'period' => $p['period'],
        );
    }

    /** Check a 6-digit code, allowing one step of clock drift */
    public static function Verify($secret, $code, $time = null)
    {
        $code = preg_replace('/\D/', '', (string)$code);
        if (strlen($code) !== 6) {
            return false;
        }
        $counter = intdiv($time ?? time(), 30);
        for ($i = -1; $i <= 1; $i++) {
            if (hash_equals(self::Code($secret, $counter + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function Code($secret, $counter, $digits = 6, $algo = 'sha1')
    {
        $key = self::Base32Decode($secret);
        $bin = pack('N*', 0, $counter);
        if (PHP_INT_SIZE >= 8) {
            $bin = pack('J', $counter);
        }
        $hash = hash_hmac($algo, $bin, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | ((ord($hash[$offset + 1]) & 0xFF) << 16) | ((ord($hash[$offset + 2]) & 0xFF) << 8) | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string)($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function Uri($secret, $account, $issuer)
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
    }

    public static function Base32Encode($data)
    {
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function Base32Decode($s)
    {
        $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
        $bits = '';
        foreach (str_split($s) as $c) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
