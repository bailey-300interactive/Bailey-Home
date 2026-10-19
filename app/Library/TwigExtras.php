<?php

use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Builds the Twig environment with Home's filters and functions.
 */
class TwigExtras
{
    public static function Make($path)
    {
        $loader = new \Twig\Loader\FilesystemLoader($path);
        $twig = new \Twig\Environment($loader, array(
            'debug' => DEBUG,
            'cache' => DEBUG ? false : ROOT . 'storage/cache/twig',
            'autoescape' => 'html',
            'strict_variables' => false
        ));

        try {
            $twig->getExtension(\Twig\Extension\CoreExtension::class)->setTimezone(App::Timezone()->getName());
        } catch (Throwable $e) {
        }

        $twig->addFunction(new TwigFunction('csrf_field', function () {
            return App::CsrfField();
        }, array('is_safe' => array('html'))));

        $twig->addFunction(new TwigFunction('setting', function ($k, $d = null) {
            return App::Setting($k, $d);
        }));

        $twig->addFunction(new TwigFunction('is_admin', function () {
            return App::IsAdmin();
        }));

        $twig->addFunction(new TwigFunction('query', function ($extra = array()) {
            $q = array_merge($_GET, $extra);
            unset($q['p'], $q['p1'], $q['p2']);
            $q = array_filter($q, function ($v) { return $v !== null && $v !== ''; });
            return $q ? '?' . http_build_query($q) : '';
        }));

        $twig->addFilter(new TwigFilter('money', function ($n, $currency = null, $plus = false) {
            return money($n, $currency, $plus);
        }));

        $twig->addFilter(new TwigFilter('hm', function ($s) {
            return hm($s);
        }));

        $twig->addFilter(new TwigFilter('hours', function ($s) {
            return number_format(hours($s), 2);
        }));

        // UTC datetime from the DB → person's local time
        $twig->addFilter(new TwigFilter('local', function ($utc, $format = 'M j, Y g:i A') {
            $d = App::Local($utc);
            return $d ? $d->format($format) : '';
        }));

        // Y-m-d → nice date
        $twig->addFilter(new TwigFilter('nicedate', function ($date, $format = 'M j, Y') {
            if (empty($date)) {
                return '';
            }
            $t = strtotime(substr($date, 0, 10));
            return $t ? date($format, $t) : '';
        }));

        // Y-m-d → "Today", "Tomorrow", "In 3 days", "2 days ago", "Mar 4"
        $twig->addFilter(new TwigFilter('relday', function ($date) {
            return self::RelativeDay($date);
        }));

        $twig->addFilter(new TwigFilter('ago', function ($utc) {
            return self::Ago($utc);
        }));

        $twig->addFilter(new TwigFilter('js', function ($v) {
            return json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        }, array('is_safe' => array('html', 'js'))));

        $twig->addFilter(new TwigFilter('initials', function ($s) {
            $parts = preg_split('/\s+/', trim((string)$s));
            $i = mb_substr($parts[0] ?? '', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : mb_substr($parts[0] ?? '', 1, 1));
            return mb_strtoupper($i ?: '?');
        }));

        $twig->addFilter(new TwigFilter('domain', function ($url) {
            $host = parse_url(str_contains((string)$url, '://') ? $url : 'https://' . $url, PHP_URL_HOST);
            return $host ? preg_replace('/^www\./', '', $host) : '';
        }));

        $twig->addFilter(new TwigFilter('pct', function ($a, $b) {
            return $b > 0 ? min(999, round($a / $b * 100)) : 0;
        }));

        return $twig;
    }

    public static function RelativeDay($date)
    {
        if (empty($date)) {
            return '';
        }
        $d = substr($date, 0, 10);
        $today = new DateTime(today());
        $target = new DateTime($d);
        $diff = (int)$today->diff($target)->format('%r%a');
        if ($diff === 0) {
            return 'Today';
        }
        if ($diff === 1) {
            return 'Tomorrow';
        }
        if ($diff === -1) {
            return 'Yesterday';
        }
        if ($diff > 1 && $diff < 7) {
            return $target->format('l');
        }
        if ($diff < -1 && $diff > -7) {
            return abs($diff) . ' days ago';
        }
        return $target->format($target->format('Y') === $today->format('Y') ? 'M j' : 'M j, Y');
    }

    public static function Ago($utc)
    {
        if (empty($utc)) {
            return '';
        }
        $s = time() - strtotime($utc . ' UTC');
        if ($s < 60) {
            return 'just now';
        }
        if ($s < 3600) {
            return floor($s / 60) . 'm ago';
        }
        if ($s < 86400) {
            return floor($s / 3600) . 'h ago';
        }
        if ($s < 86400 * 7) {
            return floor($s / 86400) . 'd ago';
        }
        $d = App::Local($utc);
        return $d->format('M j');
    }
}
