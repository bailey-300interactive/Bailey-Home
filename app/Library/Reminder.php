<?php

/**
 * Reminders: one-line quick add, repeats, snooze, email alerts.
 */
class Reminder
{
    const UNITS = array('day' => 'day', 'week' => 'week', 'month' => 'month', 'year' => 'year');
    const DAYS = array('mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun');
    const PRIORITIES = array(0 => 'None', 1 => 'Low', 2 => 'Medium', 3 => 'High');

    private static $dueCount = null;

    /** Open reminders due today or earlier (for the sidebar badge) */
    public static function DueCount()
    {
        if (self::$dueCount === null) {
            try {
                self::$dueCount = (int)val("SELECT COUNT(*) FROM reminders WHERE status = 'open' AND due_date IS NOT NULL AND due_date <= ? AND (snoozed_until IS NULL OR snoozed_until <= ?)", array(today(), App::UtcNow()));
            } catch (Throwable $e) {
                self::$dueCount = 0;
            }
        }
        return self::$dueCount;
    }

    /**
     * Save a reminder from a form/array. Returns the id.
     */
    public static function Save($id, array $in)
    {
        $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 255);
        if ($title === '') {
            throw new RuntimeException('Give the reminder a title.');
        }
        $dueDate = valid_date($in['due_date'] ?? '');
        $dueTime = self::CleanTime($in['due_time'] ?? '');
        $unit = isset(self::UNITS[$in['repeat_unit'] ?? '']) ? $in['repeat_unit'] : null;
        $days = array();
        if ($unit === 'week' && !empty($in['repeat_days'])) {
            $raw = is_array($in['repeat_days']) ? $in['repeat_days'] : explode(',', $in['repeat_days']);
            $days = array_values(array_intersect(array_keys(self::DAYS), array_map('strtolower', $raw)));
        }
        if ($unit && !$dueDate) {
            $dueDate = today();
        }
        $data = array(
            'title' => $title,
            'notes' => trim((string)($in['notes'] ?? '')) ?: null,
            'list_id' => !empty($in['list_id']) ? (int)$in['list_id'] : null,
            'assigned_to' => !empty($in['assigned_to']) ? (int)$in['assigned_to'] : App::UserId(),
            'due_date' => $dueDate,
            'due_time' => $dueDate ? $dueTime : null,
            'due_at' => self::DueAt($dueDate, $dueTime),
            'repeat_unit' => $unit,
            'repeat_interval' => max(1, min(365, (int)($in['repeat_interval'] ?? 1))),
            'repeat_days' => $days ? implode(',', $days) : null,
            'repeat_from' => ($in['repeat_from'] ?? '') === 'completed' ? 'completed' : 'due',
            'repeat_until' => valid_date($in['repeat_until'] ?? ''),
            'priority' => max(0, min(3, (int)($in['priority'] ?? 0))),
            'notify' => !empty($in['notify']) ? 1 : 0,
            'notify_before' => max(0, (int)($in['notify_before'] ?? 0)),
            'link_type' => in_array($in['link_type'] ?? '', array('client', 'project', 'bill', 'invoice', 'vault'), true) ? $in['link_type'] : null,
            'link_id' => !empty($in['link_id']) ? (int)$in['link_id'] : null,
            'updated_at' => App::UtcNow(),
        );
        if ($id) {
            $old = row('SELECT * FROM reminders WHERE id = ?', array($id));
            if (!$old) {
                throw new RuntimeException('Reminder not found.');
            }
            if ($old['due_at'] !== $data['due_at'] || $old['notify_before'] != $data['notify_before']) {
                $data['notified_at'] = null;
                $data['snoozed_until'] = null;
            }
            update('reminders', 'id', $id, $data);
            return (int)$id;
        }
        $data['created_by'] = App::UserId() ?: null;
        $data['created_at'] = App::UtcNow();
        return insert('reminders', $data);
    }

    /**
     * Tick it off. Repeating reminders move to their next date instead.
     * Returns array('done' => bool, 'next' => Y-m-d|null)
     */
    public static function Complete($id)
    {
        $r = row('SELECT * FROM reminders WHERE id = ?', array($id));
        if (!$r) {
            throw new RuntimeException('Reminder not found.');
        }
        insert('reminder_completions', array('reminder_id' => $id, 'title' => $r['title'], 'due_date' => $r['due_date'], 'completed_by' => App::UserId() ?: null, 'completed_at' => App::UtcNow()));
        $next = $r['repeat_unit'] ? self::NextDate($r) : null;
        if ($next && (!$r['repeat_until'] || $next <= $r['repeat_until'])) {
            update('reminders', 'id', $id, array(
                'due_date' => $next, 'due_at' => self::DueAt($next, $r['due_time']), 'notified_at' => null, 'snoozed_until' => null,
                'completed_count' => (int)$r['completed_count'] + 1, 'updated_at' => App::UtcNow()
            ));
            return array('done' => false, 'next' => $next);
        }
        update('reminders', 'id', $id, array('status' => 'done', 'completed_at' => App::UtcNow(), 'completed_count' => (int)$r['completed_count'] + 1, 'updated_at' => App::UtcNow()));
        return array('done' => true, 'next' => null);
    }

    public static function Reopen($id)
    {
        run("UPDATE reminders SET status = 'open', completed_at = NULL, notified_at = NULL WHERE id = ?", array($id));
    }

    /**
     * Snooze: "1h", "3h", "tonight", "tomorrow", "nextweek", or a Y-m-d
     */
    public static function Snooze($id, $how)
    {
        $r = row('SELECT * FROM reminders WHERE id = ?', array($id));
        if (!$r) {
            throw new RuntimeException('Reminder not found.');
        }
        $now = App::Now();
        switch ($how) {
            case '1h': $until = (clone $now)->modify('+1 hour'); break;
            case '3h': $until = (clone $now)->modify('+3 hours'); break;
            case 'tonight': $until = (clone $now)->setTime(19, 0); if ($until <= $now) $until->modify('+1 day'); break;
            case 'tomorrow': $until = (clone $now)->modify('+1 day')->setTime(9, 0); break;
            case 'nextweek': $until = (clone $now)->modify('next monday')->setTime(9, 0); break;
            default:
                $d = valid_date($how);
                if (!$d) {
                    throw new RuntimeException('Pick when to snooze to.');
                }
                $until = new DateTime($d . ' 09:00', App::Timezone());
        }
        $localDate = $until->format('Y-m-d');
        $utc = (clone $until)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        // Moving it to a later day changes its due date; same day just hides it until then
        $data = array('snoozed_until' => $utc, 'notified_at' => null, 'updated_at' => App::UtcNow());
        if (!$r['due_date'] || $localDate > $r['due_date']) {
            $data['due_date'] = $localDate;
            $data['due_time'] = $until->format('H:i:s');
            $data['due_at'] = $utc;
        }
        update('reminders', 'id', $id, $data);
        return $localDate;
    }

    /** Next due date for a repeating reminder */
    public static function NextDate(array $r, $fromDate = null)
    {
        $base = $fromDate ?: (($r['repeat_from'] ?? 'due') === 'completed' || empty($r['due_date']) ? today() : $r['due_date']);
        $d = new DateTime($base);
        $n = max(1, (int)$r['repeat_interval']);
        switch ($r['repeat_unit']) {
            case 'day':
                $d->modify('+' . $n . ' days');
                break;
            case 'week':
                if (!empty($r['repeat_days'])) {
                    $want = explode(',', $r['repeat_days']);
                    $map = array('mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7);
                    $wantN = array_map(function ($x) use ($map) { return $map[$x]; }, $want);
                    $start = clone $d;
                    for ($i = 1; $i <= 7 * $n + 7; $i++) {
                        $d = (clone $start)->modify('+' . $i . ' days');
                        $weeks = intdiv((int)$start->diff($d)->days + ((int)$start->format('N') - 1), 7);
                        if (in_array((int)$d->format('N'), $wantN, true) && ($n === 1 || $weeks % $n === 0)) {
                            break;
                        }
                    }
                } else {
                    $d->modify('+' . $n . ' weeks');
                }
                break;
            case 'month':
                $day = (int)(new DateTime($r['due_date'] ?: $base))->format('j');
                $d->modify('first day of this month')->modify('+' . $n . ' months');
                $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($day, (int)$d->format('t')));
                break;
            case 'year':
                $anchor = new DateTime($r['due_date'] ?: $base);
                $y = (int)$d->format('Y') + $n;
                $m = (int)$anchor->format('n');
                $d = new DateTime(sprintf('%04d-%02d-01', $y, $m));
                $d->setDate($y, $m, min((int)$anchor->format('j'), (int)$d->format('t')));
                break;
        }
        // Repeating from "due" but way behind: catch up to the first date after today
        if (($r['repeat_from'] ?? 'due') === 'due' && $d->format('Y-m-d') < today() && $fromDate === null && !empty($r['due_date'])) {
            $guard = 0;
            while ($d->format('Y-m-d') < today() && $guard++ < 1000) {
                $d = new DateTime(self::NextDate($r, $d->format('Y-m-d')));
            }
        }
        return $d->format('Y-m-d');
    }

    /** "Every 2 weeks on Mon, Wed" */
    public static function RepeatText(array $r)
    {
        if (empty($r['repeat_unit'])) {
            return '';
        }
        $n = (int)$r['repeat_interval'];
        $unit = $r['repeat_unit'];
        $text = $n === 1 ? array('day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly', 'year' => 'Yearly')[$unit] : 'Every ' . $n . ' ' . $unit . 's';
        if ($unit === 'week' && !empty($r['repeat_days'])) {
            $days = explode(',', $r['repeat_days']);
            if ($days === array('mon', 'tue', 'wed', 'thu', 'fri') && $n === 1) {
                return 'Every weekday';
            }
            $text .= ' on ' . implode(', ', array_map(function ($x) { return self::DAYS[$x] ?? $x; }, $days));
        }
        if ($unit === 'month' && !empty($r['due_date'])) {
            $text .= ' on the ' . date('jS', strtotime($r['due_date']));
        }
        if (($r['repeat_from'] ?? '') === 'completed') {
            $text .= ' after done';
        }
        return $text;
    }

    /**
     * Quick add: understands phrases like
     *   "Pay water bill every month on the 15th !high #Home"
     *   "Call mom tomorrow at 6pm", "Renew domain on 3/14", "Trash every tue and fri 7am"
     *   "Dentist next thursday 2:30pm", "Take vitamins every day at 8am", "Oil change in 3 months"
     * Returns the fields Save() takes.
     */
    public static function ParseQuick($text)
    {
        $t = ' ' . trim((string)$text) . ' ';
        $out = array('title' => '', 'due_date' => null, 'due_time' => null, 'repeat_unit' => null, 'repeat_interval' => 1, 'repeat_days' => array(), 'priority' => 0, 'list_id' => null, 'notify' => 1);
        $today = new DateTime(today());
        $dayNames = array('monday' => 'mon', 'mon' => 'mon', 'tuesday' => 'tue', 'tue' => 'tue', 'tues' => 'tue', 'wednesday' => 'wed', 'wed' => 'wed', 'thursday' => 'thu', 'thu' => 'thu', 'thurs' => 'thu', 'friday' => 'fri', 'fri' => 'fri', 'saturday' => 'sat', 'sat' => 'sat', 'sunday' => 'sun', 'sun' => 'sun');
        $dayRx = 'monday|tuesday|wednesday|thursday|friday|saturday|sunday|mon|tues|tue|wed|thurs|thu|fri|sat|sun';
        $full = array('mon' => 'monday', 'tue' => 'tuesday', 'wed' => 'wednesday', 'thu' => 'thursday', 'fri' => 'friday', 'sat' => 'saturday', 'sun' => 'sunday');

        // !high !! priority
        if (preg_match('/\s(!{1,3}|!high|!med(?:ium)?|!low)(?=\s)/i', $t, $m)) {
            $p = strtolower($m[1]);
            $out['priority'] = $p === '!high' || $p === '!!!' ? 3 : ($p === '!low' || $p === '!' ? 1 : 2);
            $t = str_replace($m[0], ' ', $t);
        }
        // #List
        if (preg_match('/\s#([\p{L}\p{N}_-]+)(?=\s)/u', $t, $m)) {
            $id = val('SELECT id FROM reminder_lists WHERE LOWER(name) = LOWER(?)', array(str_replace(array('-', '_'), ' ', $m[1])));
            if (!$id) {
                $id = val('SELECT id FROM reminder_lists WHERE LOWER(name) LIKE LOWER(?) ORDER BY sort LIMIT 1', array($m[1] . '%'));
            }
            if ($id) {
                $out['list_id'] = (int)$id;
                $t = str_replace($m[0], ' ', $t);
            }
        }
        // time: "at 6pm", "8:30am", "at 14:00", "noon"
        if (preg_match('/\s(?:at\s+)?(\d{1,2})(?::(\d{2}))?\s*(am|pm|a|p)(?=\s|,)/i', $t, $m)) {
            $h = (int)$m[1] % 12 + (stripos($m[3], 'p') === 0 ? 12 : 0);
            $out['due_time'] = sprintf('%02d:%02d', $h, (int)($m[2] ?? 0));
            $t = str_replace($m[0], ' ', $t);
        } elseif (preg_match('/\sat\s+(\d{1,2}):(\d{2})(?=\s)/i', $t, $m)) {
            $out['due_time'] = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
            $t = str_replace($m[0], ' ', $t);
        } elseif (preg_match('/\s(?:at\s+)?(noon|midnight|tonight|this evening|this morning)(?=\s)/i', $t, $m)) {
            $map = array('noon' => '12:00', 'midnight' => '00:00', 'tonight' => '19:00', 'this evening' => '18:00', 'this morning' => '09:00');
            $out['due_time'] = $map[strtolower($m[1])];
            if (in_array(strtolower($m[1]), array('tonight', 'this evening', 'this morning'), true)) {
                $out['due_date'] = $today->format('Y-m-d');
            }
            $t = str_replace($m[0], ' ', $t);
        }
        // repeats
        if (preg_match('/\severy\s+(weekday|weekdays)(?=\s)/i', $t, $m)) {
            $out['repeat_unit'] = 'week';
            $out['repeat_days'] = array('mon', 'tue', 'wed', 'thu', 'fri');
            $t = str_replace($m[0], ' ', $t);
        } elseif (preg_match('/\severy\s+((?:(?:' . $dayRx . ')s?\b(?:\s*,\s*|\s+and\s+|\s*&\s*)?)+)/i', $t, $m)) {
            $out['repeat_unit'] = 'week';
            preg_match_all('/(' . $dayRx . ')/i', $m[1], $dm);
            $out['repeat_days'] = array_values(array_unique(array_map(function ($x) use ($dayNames) { return $dayNames[strtolower($x)]; }, $dm[1])));
            $t = str_replace($m[0], ' ', $t);
        } elseif (preg_match('/\s(?:every|each)\s+(?:(\d+|other)\s+)?(day|week|month|year)s?(?=\s)/i', $t, $m)) {
            $out['repeat_unit'] = strtolower($m[2]);
            $out['repeat_interval'] = strtolower($m[1]) === 'other' ? 2 : max(1, (int)($m[1] ?: 1));
            $t = str_replace($m[0], ' ', $t);
        } elseif (preg_match('/\s(daily|weekly|monthly|yearly|annually)(?=\s)/i', $t, $m)) {
            $out['repeat_unit'] = array('daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year', 'annually' => 'year')[strtolower($m[1])];
            $t = str_replace($m[0], ' ', $t);
        }
        // dates
        $setDate = function (DateTime $d) use (&$out) {
            $out['due_date'] = $d->format('Y-m-d');
        };
        if (preg_match('/\s(?:on\s+)?the\s+(\d{1,2})(?:st|nd|rd|th)?(?=\s)/i', $t, $m) && (int)$m[1] >= 1 && (int)$m[1] <= 31) {
            $d = clone $today;
            $day = (int)$m[1];
            if ((int)$d->format('j') > $day) {
                $d->modify('first day of next month');
            }
            $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($day, (int)$d->format('t')));
            $setDate($d);
            if (!$out['repeat_unit'] && preg_match('/\bmonth/i', $text)) {
                $out['repeat_unit'] = 'month';
            }
            $t = str_replace($m[0], ' ', $t);
        } elseif (preg_match('/\s(today|tod)(?=\s)/i', $t, $m)) {
            $setDate(clone $today);
            $t = str_replace($m[0], ' ', $t);
        } elseif (preg_match('/\s(tomorrow|tmrw|tmr)(?=\s)/i', $t, $m)) {
            $setDate((clone $today)->modify('+1 day'));
            $t = str_replace($m[0], ' ', $t);
        } elseif (preg_match('/\sin\s+(\d+|a|an|one|two|three)\s+(day|week|month|year)s?(?=\s)/i', $t, $m)) {
            $words = array('a' => 1, 'an' => 1, 'one' => 1, 'two' => 2, 'three' => 3);
            $n = is_numeric($m[1]) ? (int)$m[1] : $words[strtolower($m[1])];
            $setDate((clone $today)->modify('+' . $n . ' ' . strtolower($m[2]) . 's'));
            $t = str_replace($m[0], ' ', $t);
        } elseif (preg_match('/\s(next\s+)?(week|month|weekend)(?=\s)/i', $t, $m) && !empty($m[1])) {
            $w = strtolower($m[2]);
            $setDate($w === 'week' ? (clone $today)->modify('next monday') : ($w === 'weekend' ? (clone $today)->modify('next saturday') : (clone $today)->modify('first day of next month')));
            $t = str_replace($m[0], ' ', $t);
        } elseif (preg_match('/\s(?:on\s+)?(next\s+|this\s+)?(' . $dayRx . ')(?=\s|,)/i', $t, $m)) {
            $day = $full[$dayNames[strtolower($m[2])]];
            $d = (clone $today)->modify(($today->format('l') === ucfirst($day) && empty($m[1])) ? 'today' : 'next ' . $day);
            $setDate($d);
            $t = str_replace($m[0], ' ', $t);
        } elseif (preg_match('/\s(?:on\s+|by\s+)?(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?(?=\s)/', $t, $m)) {
            $y = !empty($m[3]) ? ((int)$m[3] < 100 ? 2000 + (int)$m[3] : (int)$m[3]) : (int)$today->format('Y');
            if (checkdate((int)$m[1], (int)$m[2], $y)) {
                $d = new DateTime(sprintf('%04d-%02d-%02d', $y, $m[1], $m[2]));
                if (empty($m[3]) && $d < $today) {
                    $d->modify('+1 year');
                }
                $setDate($d);
                $t = str_replace($m[0], ' ', $t);
            }
        } elseif (preg_match('/\s(?:on\s+|by\s+)?(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?(?=\s)/i', $t, $m)) {
            $y = !empty($m[3]) ? (int)$m[3] : (int)$today->format('Y');
            $d = DateTime::createFromFormat('!M j Y', ucfirst(substr(strtolower($m[1]), 0, 3)) . ' ' . (int)$m[2] . ' ' . $y);
            if ($d) {
                if (empty($m[3]) && $d < $today) {
                    $d->modify('+1 year');
                }
                $setDate($d);
                $t = str_replace($m[0], ' ', $t);
            }
        }
        // A repeat on certain weekdays with no date starts on the next one
        if ($out['repeat_unit'] === 'week' && $out['repeat_days'] && !$out['due_date']) {
            $map = array('mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7);
            $skipToday = $out['due_time'] && $out['due_time'] < App::Now()->format('H:i');
            for ($i = $skipToday ? 1 : 0; $i < 8; $i++) {
                $d = (clone $today)->modify('+' . $i . ' days');
                if (in_array((int)$d->format('N'), array_map(function ($x) use ($map) { return $map[$x]; }, $out['repeat_days']), true)) {
                    $out['due_date'] = $d->format('Y-m-d');
                    break;
                }
            }
        }
        if ($out['due_time'] && !$out['due_date']) {
            $out['due_date'] = $today->format('Y-m-d');
            if ($out['due_time'] < App::Now()->format('H:i')) {
                $out['due_date'] = (clone $today)->modify('+1 day')->format('Y-m-d');
            }
        }
        if ($out['repeat_unit'] && !$out['due_date']) {
            $out['due_date'] = $today->format('Y-m-d');
        }
        $title = trim(preg_replace('/\s+/', ' ', $t));
        $title = preg_replace('/\s+(on|at|by|in|every)$/i', '', $title);
        $out['title'] = $title !== '' ? mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1) : trim((string)$text);
        $out['repeat_days'] = implode(',', $out['repeat_days']);
        return $out;
    }

    /**
     * Load reminders for a view.
     * $view: today (overdue + today), upcoming (next 14 days), scheduled, all, someday, done
     */
    public static function ForView($view, $listId = null, $search = '')
    {
        $where = array();
        $p = array();
        $t = today();
        switch ($view) {
            case 'done':
                $where[] = "r.status = 'done'";
                break;
            case 'today':
                $where[] = "r.status = 'open' AND r.due_date IS NOT NULL AND r.due_date <= ?";
                $p[] = $t;
                break;
            case 'upcoming':
                $where[] = "r.status = 'open' AND r.due_date > ? AND r.due_date <= ?";
                $p[] = $t;
                $p[] = (new DateTime($t))->modify('+14 days')->format('Y-m-d');
                break;
            case 'someday':
                $where[] = "r.status = 'open' AND r.due_date IS NULL";
                break;
            default:
                $where[] = "r.status = 'open'";
        }
        if ($listId) {
            $where[] = 'r.list_id = ?';
            $p[] = $listId;
        }
        if ($search !== '') {
            $where[] = '(r.title LIKE ? OR r.notes LIKE ?)';
            $p[] = '%' . $search . '%';
            $p[] = '%' . $search . '%';
        }
        $order = $view === 'done' ? 'r.completed_at DESC' : 'r.due_date IS NULL, r.due_date, r.due_time IS NULL, r.due_time, r.priority DESC, r.id';
        $list = rows('SELECT r.*, l.name AS list_name, l.color AS list_color, l.icon AS list_icon, CONCAT(u.first_name, \' \', u.last_name) AS assignee
            FROM reminders r LEFT JOIN reminder_lists l ON l.id = r.list_id LEFT JOIN admin_users u ON u.Id_admin_users = r.assigned_to
            WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order . ($view === 'done' ? ' LIMIT 200' : ''), $p);
        $now = App::UtcNow();
        foreach ($list as &$r) {
            $r['repeat_text'] = self::RepeatText($r);
            $r['overdue'] = $r['status'] === 'open' && $r['due_date'] && ($r['due_date'] < $t || ($r['due_date'] === $t && $r['due_at'] && $r['due_at'] < $now && $r['due_time']));
            $r['snoozed'] = $r['snoozed_until'] && $r['snoozed_until'] > $now;
            $r['link'] = self::LinkInfo($r['link_type'], $r['link_id']);
        }
        return $list;
    }

    /** Bills due soon, shown alongside reminders */
    public static function UpcomingBills($days = 14)
    {
        $until = (new DateTime(today()))->modify('+' . $days . ' days')->format('Y-m-d');
        return rows('SELECT b.*, c.icon AS cat_icon FROM fin_bills b LEFT JOIN fin_categories c ON c.id = b.category_id WHERE b.active = 1 AND b.next_due <= ? ORDER BY b.next_due', array($until));
    }

    public static function LinkInfo($type, $id)
    {
        if (!$type || !$id) {
            return null;
        }
        switch ($type) {
            case 'client':
                $n = val('SELECT name FROM clients WHERE id = ?', array($id));
                return $n ? array('label' => $n, 'url' => '/time/client/' . $id, 'icon' => 'briefcase') : null;
            case 'project':
                $n = val('SELECT name FROM projects WHERE id = ?', array($id));
                return $n ? array('label' => $n, 'url' => '/time/project/' . $id, 'icon' => 'folder') : null;
            case 'bill':
                $n = val('SELECT name FROM fin_bills WHERE id = ?', array($id));
                return $n ? array('label' => $n, 'url' => '/money/bills', 'icon' => 'credit-card') : null;
            case 'invoice':
                $n = val('SELECT number FROM invoices WHERE id = ?', array($id));
                return $n ? array('label' => 'Invoice ' . $n, 'url' => '/time/invoice/' . $id, 'icon' => 'file-text') : null;
            case 'vault':
                return array('label' => 'Vault item', 'url' => '/vault?item=' . $id, 'icon' => 'lock');
        }
        return null;
    }

    /**
     * Cron: email reminders that are due (or due within their "notify before" window). Returns count queued.
     */
    public static function QueueDueAlerts()
    {
        $now = gmdate('Y-m-d H:i:s');
        $list = rows("SELECT r.*, u.email, u.username, u.first_name, u.timezone FROM reminders r JOIN admin_users u ON u.Id_admin_users = COALESCE(r.assigned_to, r.created_by)
            WHERE r.status = 'open' AND r.notify = 1 AND r.notified_at IS NULL AND r.due_at IS NOT NULL AND r.due_time IS NOT NULL
              AND DATE_SUB(r.due_at, INTERVAL r.notify_before MINUTE) <= ? AND (r.snoozed_until IS NULL OR r.snoozed_until <= ?)
              AND u.notify_reminders = 1 AND u.active = 1", array($now, $now));
        $n = 0;
        foreach ($list as $r) {
            $to = $r['email'] ?: $r['username'];
            $tz = new DateTimeZone($r['timezone'] ?: App::Setting('timezone', 'America/New_York'));
            $when = (new DateTime($r['due_at'], new DateTimeZone('UTC')))->setTimezone($tz);
            Mailer::Queue($to, $r['first_name'], '⏰ ' . $r['title'], 'reminder', array(
                'title' => $r['title'], 'notes' => $r['notes'], 'when' => $when->format('l, M j \a\t g:i A'),
                'repeat' => self::RepeatText($r), 'url' => App::BaseUrl() . '/reminders?open=' . $r['id'], 'first_name' => $r['first_name']
            ));
            run('UPDATE reminders SET notified_at = ? WHERE id = ?', array($now, $r['id']));
            $n++;
        }
        return $n;
    }

    /**
     * Cron: the morning digest (reminders due today/overdue, bills due in 3 days, unpaid invoices due).
     */
    public static function QueueDigests()
    {
        $n = 0;
        foreach (rows('SELECT * FROM admin_users WHERE active = 1 AND notify_digest = 1') as $u) {
            $tz = new DateTimeZone($u['timezone'] ?: App::Setting('timezone', 'America/New_York'));
            $local = new DateTime('now', $tz);
            if ((int)$local->format('G') !== (int)App::Setting('digest_hour', 7)) {
                continue;
            }
            $key = 'digest_sent_' . $u['Id_admin_users'];
            if (App::Setting($key) === $local->format('Y-m-d')) {
                continue;
            }
            $day = $local->format('Y-m-d');
            $reminders = rows("SELECT * FROM reminders WHERE status = 'open' AND due_date <= ? AND (assigned_to = ? OR assigned_to IS NULL) ORDER BY due_date, due_time", array($day, $u['Id_admin_users']));
            $bills = rows('SELECT * FROM fin_bills WHERE active = 1 AND next_due <= ? ORDER BY next_due', array((new DateTime($day))->modify('+3 days')->format('Y-m-d')));
            $invoices = rows("SELECT i.*, c.name AS client FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.status IN ('sent','partial') AND i.due_date <= ? ORDER BY i.due_date", array($day));
            App::SaveSetting($key, $day);
            if (!$reminders && !$bills && !$invoices) {
                continue;
            }
            foreach ($reminders as &$r) {
                $r['overdue'] = $r['due_date'] < $day;
                $r['time'] = $r['due_time'] ? date('g:i A', strtotime($r['due_time'])) : '';
            }
            foreach ($bills as &$b) {
                $b['amount_text'] = money($b['amount']);
                $b['when'] = date('D M j', strtotime($b['next_due']));
            }
            foreach ($invoices as &$i) {
                $i['balance_text'] = money($i['total'] - $i['paid']);
            }
            Mailer::Queue($u['email'] ?: $u['username'], $u['first_name'], 'Your day: ' . $local->format('l, M j'), 'digest', array(
                'first_name' => $u['first_name'], 'date' => $local->format('l, F j'), 'reminders' => $reminders, 'bills' => $bills, 'invoices' => $invoices, 'url' => App::BaseUrl()
            ));
            $n++;
        }
        return $n;
    }

    private static function CleanTime($s)
    {
        $s = trim((string)$s);
        if ($s === '') {
            return null;
        }
        $t = strtotime('2000-01-01 ' . $s);
        return $t ? date('H:i:s', $t) : null;
    }

    /** UTC due moment from a local date + time (end of day for all-day reminders) */
    public static function DueAt($date, $time)
    {
        if (!$date) {
            return null;
        }
        return App::ToUtc($date . ' ' . ($time ?: '09:00:00'));
    }
}
