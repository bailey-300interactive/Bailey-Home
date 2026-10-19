<?php

/**
 * Mailer (framework Mailer + a queue and a "log" driver for testing)
 * @author Bailey Rotellini
 */

use PHPMailer\PHPMailer\PHPMailer;

class Mailer
{
    private $TWIG;

    public function __construct()
    {
        require_once(ROOT . 'app/Modules/php-mailer/vendor/autoload.php');
        $this->TWIG = TwigExtras::Make(ROOT . 'public/mail/');
    }

    /**
     * Put an email in the queue. dev/cron.php (or Mailer::Flush at the end of a request) sends it.
     */
    public static function Queue($toEmail, $toName, $subject, $template, $data = array())
    {
        if (empty($toEmail)) {
            return false;
        }
        insert('email_queue', array(
            'to_email' => $toEmail, 'to_name' => $toName, 'subject' => $subject, 'template' => $template,
            'data' => json_encode($data), 'created_at' => App::UtcNow()
        ));
        return true;
    }

    /** Send up to $limit queued emails */
    public static function Flush($limit = 20)
    {
        $sent = 0;
        foreach (rows('SELECT * FROM email_queue WHERE sent_at IS NULL AND attempts < 5 ORDER BY id LIMIT ' . (int)$limit) as $m) {
            $mailer = new Mailer();
            $ok = $mailer->Send(array(array('email' => $m['to_email'], 'name' => $m['to_name'])), $m['subject'], $m['template'], json_decode($m['data'], true) ?: array());
            if ($ok === true) {
                run('UPDATE email_queue SET sent_at = ?, attempts = attempts + 1 WHERE id = ?', array(App::UtcNow(), $m['id']));
                $sent++;
            } else {
                run('UPDATE email_queue SET attempts = attempts + 1, last_error = ? WHERE id = ?', array(mb_substr((string)$ok, 0, 255), $m['id']));
            }
        }
        return $sent;
    }

    /**
     * Send now. Returns true, or an error message.
     * @param array $addresses array(array('email'=>..., 'name'=>...))
     */
    public function Send($addresses, $subject, $template, $data = array(), $replyTo = false)
    {
        if (!SEND_EMAILS) {
            return true;
        }
        $settings = @parse_ini_file(ROOT . 'settings/mail.ini.php') ?: array();
        $app = App::GetSiteProfileVariables();
        $app['base_url'] = App::BaseUrl();
        $app['company_name'] = App::Setting('company_name', '');

        $body = $this->TWIG->render($template . '.html', array(
            'data' => $data,
            'app' => $app,
            'subject' => $subject,
            'header' => $this->TWIG->render('partials/header.html', array('app' => $app)),
            'footer' => $this->TWIG->render('partials/footer.html', array('app' => $app)),
        ));

        if (strtolower($settings['Driver'] ?? 'smtp') === 'log') {
            $to = implode(', ', array_column($addresses, 'email'));
            $plain = preg_replace('/<a [^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $body);
            $plain = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/tr|\/h\d)>/i', "\n", $plain)), ENT_QUOTES, 'UTF-8');
            $text = "To: $to\nSubject: $subject\n\n" . trim(preg_replace("/\n\s*\n+/", "\n\n", $plain)) . "\n" . str_repeat('-', 60) . "\n";
            @file_put_contents(ROOT . 'storage/logs/mail-' . gmdate('Y-m-d') . '.txt', $text, FILE_APPEND);
            return true;
        }

        $m = new PHPMailer(true);
        try {
            $m->isSMTP();
            $m->Host = $settings['Host'] ?? 'localhost';
            $m->Port = (int)($settings['Port'] ?? 587);
            $m->SMTPSecure = $settings['SmtpSecure'] ?? 'tls';
            $m->SMTPAuth = !empty($settings['Username']);
            $m->Username = $settings['Username'] ?? '';
            $m->Password = $settings['Password'] ?? '';
            $m->CharSet = 'UTF-8';
            $m->setFrom($settings['FromEmail'] ?? $settings['Username'], $settings['FromName'] ?? $app['site_name']);
            foreach ($addresses as $a) {
                if (!empty($a['email'])) {
                    $m->addAddress($a['email'], $a['name'] ?? '');
                }
            }
            if ($replyTo) {
                $m->addReplyTo($replyTo);
            }
            $m->isHTML(true);
            $m->Subject = $subject;
            $m->Body = $body;
            $m->AltBody = trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $body)));
            $m->send();
            return true;
        } catch (Throwable $e) {
            (new Log())->write('Mail failed: ' . $m->ErrorInfo);
            return $m->ErrorInfo ?: $e->getMessage();
        }
    }
}
