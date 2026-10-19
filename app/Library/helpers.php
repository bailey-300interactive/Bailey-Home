<?php

/**
 * Small global helpers used everywhere.
 */

/**
 * Shared database object
 * @return DB
 */
function db()
{
    static $db = null;
    if ($db === null) {
        $db = new DB();
    }
    return $db;
}

/** Rows for a SELECT */
function rows($sql, $params = array())
{
    return db()->query($sql, $params);
}

/** One row or false */
function row($sql, $params = array())
{
    return db()->row($sql, $params);
}

/** One value */
function val($sql, $params = array())
{
    return db()->single($sql, $params);
}

/** Run INSERT/UPDATE/DELETE, returns affected rows */
function run($sql, $params = array())
{
    return db()->query($sql, $params);
}

/**
 * Insert a row from an array and return its id
 * @param string $table
 * @param array $data column => value
 * @return int
 */
function insert($table, $data)
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (:' . implode(',:', $cols) . ')';
    db()->query($sql, $data);
    return db()->lastInsertId();
}

/**
 * Update a row by its primary key
 */
function update($table, $pk, $id, $data)
{
    if (empty($data)) {
        return 0;
    }
    $sets = array();
    foreach ($data as $col => $v) {
        $sets[] = '`' . $col . '` = :' . $col;
    }
    $data['__pk'] = $id;
    return db()->query('UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE `' . $pk . '` = :__pk', $data);
}

/** HTML escape */
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Trimmed POST value or default */
function post($key, $default = '')
{
    return isset($_POST[$key]) && !is_array($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

/** POST value as nullable int */
function post_id($key)
{
    $v = post($key);
    return ctype_digit($v) && (int)$v > 0 ? (int)$v : null;
}

/** POST checkbox */
function post_bool($key)
{
    return !empty($_POST[$key]) ? 1 : 0;
}

/** Parse a money string like "$1,234.50" or "-12" into a float rounded to cents */
function parse_money($s)
{
    $s = trim((string)$s);
    if ($s === '') {
        return 0.0;
    }
    $neg = false;
    if (preg_match('/^\((.*)\)$/', $s, $m)) {
        $neg = true;
        $s = $m[1];
    }
    $s = str_replace(array('$', ',', ' ', '£', '€'), '', $s);
    if (str_starts_with($s, '-')) {
        $neg = !$neg;
        $s = substr($s, 1);
    }
    $n = is_numeric($s) ? (float)$s : 0.0;
    return round($neg ? -$n : $n, 2);
}

/** Format money with the site currency */
function money($n, $currency = null, $showPlus = false)
{
    $currency = $currency ?: App::Setting('currency', 'USD');
    $symbols = array('USD' => '$', 'CAD' => '$', 'AUD' => '$', 'EUR' => '€', 'GBP' => '£');
    $sym = $symbols[$currency] ?? ($currency . ' ');
    $n = (float)$n;
    $out = $sym . number_format(abs($n), 2);
    if ($n < 0) {
        return '-' . $out;
    }
    return ($showPlus && $n > 0 ? '+' : '') . $out;
}

/**
 * Parse a duration typed by a person: "1:30", "1h 30m", "1.5", "90m", "45 min" → seconds
 */
function parse_duration($s)
{
    $s = strtolower(trim((string)$s));
    if ($s === '') {
        return 0;
    }
    if (preg_match('/^(\d+):(\d{1,2})(?::(\d{1,2}))?$/', $s, $m)) {
        return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)($m[3] ?? 0);
    }
    if (preg_match_all('/(\d+(?:\.\d+)?)\s*(hours|hour|hrs|hr|h|minutes|minute|mins|min|m|sec|s)(?![a-z])/', $s, $mm, PREG_SET_ORDER)) {
        $total = 0;
        foreach ($mm as $m) {
            $n = (float)$m[1];
            $u = $m[2][0];
            $total += $u === 'h' ? $n * 3600 : ($u === 'm' ? $n * 60 : $n);
        }
        return (int)round($total);
    }
    if (is_numeric($s)) {
        return (int)round((float)$s * 3600); // plain number = hours
    }
    return 0;
}

/** Seconds → "1:05" */
function hm($seconds)
{
    $seconds = max(0, (int)$seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
}

/** Seconds → decimal hours, 2 places */
function hours($seconds)
{
    return round(((int)$seconds) / 3600, 2);
}

/** A valid Y-m-d or null */
function valid_date($s)
{
    $s = trim((string)$s);
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}

/** Current local date for the signed-in person (Y-m-d) */
function today()
{
    return App::Now()->format('Y-m-d');
}

/** Random URL-safe token */
function token($bytes = 24)
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/** Send JSON and stop */
function json_out($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Stream rows as a CSV download and stop */
function csv_out($filename, $header, $rows, $escapeFormulas = true)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($rows as $r) {
        // Stop spreadsheet formula injection (off for exports meant for other apps, like vault backups)
        if ($escapeFormulas) $r = array_map(function ($v) {
            $v = (string)$v;
            return ($v !== '' && strpos('=+-@', $v[0]) !== false && !is_numeric($v)) ? "'" . $v : $v;
        }, $r);
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

/** Read an uploaded CSV into rows (array of arrays). Detects , ; or tab. */
function read_csv_upload($field, $maxBytes = 10485760)
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Choose a CSV file to upload.');
    }
    if ($_FILES[$field]['size'] > $maxBytes) {
        throw new RuntimeException('That file is too big.');
    }
    $raw = file_get_contents($_FILES[$field]['tmp_name']);
    return parse_csv_string($raw);
}

function parse_csv_string($raw)
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    $firstLine = strtok($raw, "\n");
    $delims = array(',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t"));
    arsort($delims);
    $delim = key($delims);
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $rows = array();
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        if ($r === array(null) || (count($r) === 1 && trim((string)$r[0]) === '')) {
            continue;
        }
        $rows[] = array_map(function ($v) { return trim((string)$v); }, $r);
    }
    fclose($fh);
    return $rows;
}

/** Parse many date formats used in bank exports → Y-m-d or null */
function parse_any_date($s)
{
    $s = trim((string)$s);
    if ($s === '') {
        return null;
    }
    foreach (array('Y-m-d', 'm/d/Y', 'n/j/Y', 'm/d/y', 'n/j/y', 'Y/m/d', 'd-M-Y', 'd M Y', 'M d, Y', 'Ymd', 'm-d-Y', 'Y-m-d H:i:s', 'm/d/Y H:i', 'Y-m-d\TH:i:s') as $f) {
        $d = DateTime::createFromFormat('!' . $f, $s);
        if ($d && $d->format($f) === $s) {
            return $d->format('Y-m-d');
        }
    }
    $t = strtotime($s);
    return $t ? date('Y-m-d', $t) : null;
}
