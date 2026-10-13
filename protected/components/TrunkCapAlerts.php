<?php
/**
 * Trunk cap engine: alert delivery from console commands (Yii side).
 *
 * E-mail uses the admin SMTP account (pkg_smtps, id_user = 1), like
 * AlarmCommand. Telegram uses trunkcaps_telegram_bot_token / _chat_id.
 * The Telegram API base can be overridden with the TRUNKCAP_TELEGRAM_API
 * environment variable (tests only).
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class TrunkCapAlerts
{
    private $log;

    public function __construct($log = null)
    {
        $this->log = $log ?: function ($message) {
            fwrite(STDERR, '[TrunkCaps] ' . $message . PHP_EOL);
        };
    }

    public static function setting($key, $default = '')
    {
        $value = Yii::app()->db->createCommand('SELECT config_value FROM pkg_configuration WHERE config_key = :key')
            ->queryScalar([':key' => $key]);
        return $value === false || $value === null ? $default : trim((string) $value);
    }

    public function emailConfigured()
    {
        return self::setting('trunkcaps_alert_email') !== '' && $this->smtp() !== null;
    }

    public function telegramConfigured()
    {
        return self::setting('trunkcaps_telegram_bot_token') !== '' && self::setting('trunkcaps_telegram_chat_id') !== '';
    }

    /** @return bool true if sent */
    public function email($subject, $html)
    {
        $to   = self::setting('trunkcaps_alert_email');
        $smtp = $this->smtp();
        if ($to === '' || ! $smtp) {
            return false;
        }

        Yii::import('application.extensions.phpmailer.JPhpMailer');
        $mail = new JPhpMailer;
        $mail->IsSMTP();
        $mail->SMTPAuth   = true;
        $mail->Host       = $smtp->host;
        $mail->SMTPSecure = $smtp->encryption == 'null' ? '' : $smtp->encryption;
        $mail->Username   = $smtp->username;
        $mail->Password   = $smtp->password;
        $mail->Port       = $smtp->port;
        $mail->CharSet    = 'utf-8';
        $mail->SetFrom($smtp->username, 'Trunk Caps');
        $mail->Subject = $subject;
        $mail->AltBody = strip_tags(str_replace(['</tr>', '</p>'], "\n", $html));
        $mail->MsgHTML($html);
        foreach (array_filter(array_map('trim', explode(',', $to))) as $address) {
            $mail->AddAddress($address);
        }

        try {
            if ($mail->Send()) {
                return true;
            }
            $this->say('E-mail failed: ' . $mail->ErrorInfo);
        } catch (Exception $e) {
            $this->say('E-mail failed: ' . $e->getMessage());
        }
        return false;
    }

    /** @return bool true if sent */
    public function telegram($text)
    {
        if (! $this->telegramConfigured()) {
            return false;
        }
        list($ok, $error) = TrunkCapNotifier::sendTelegram(
            self::setting('trunkcaps_telegram_bot_token'),
            self::setting('trunkcaps_telegram_chat_id'),
            $text,
            getenv('TRUNKCAP_TELEGRAM_API') ?: TrunkCapNotifier::TELEGRAM_API
        );
        if (! $ok) {
            $this->say($error);
        }
        return $ok;
    }

    private function smtp()
    {
        $smtp = Smtps::model()->find('id_user = 1');
        if (! isset($smtp->id) || $smtp->host == '' || $smtp->host == 'mail.magnusbilling.com'
            || $smtp->username == '' || $smtp->password == '' || $smtp->port == '') {
            return null;
        }
        return $smtp;
    }

    private function say($message)
    {
        call_user_func($this->log, $message);
    }
}
