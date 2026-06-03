<?php
declare(strict_types=1);

namespace Glue\Notify;

use Glue\Config;

/**
 * Minimal email sender. Uses PHP mail() by default. If config 'mail.smtp' is set,
 * it sends a raw SMTP message (no external dependency) so the same code works on
 * hosts where mail() is disabled.
 */
final class Mailer
{
    private array $cfg;

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? Config::section('mail');
    }

    /** Returns ['ok'=>bool, 'error'=>?string]. */
    public function send(string $to, string $subject, string $htmlBody): array
    {
        $fromEmail = $this->cfg['from_email'] ?? 'noreply@localhost';
        $fromName  = $this->cfg['from_name'] ?? 'Bitrix24';

        if (!empty($this->cfg['smtp'])) {
            return $this->sendSmtp($this->cfg['smtp'], $fromEmail, $fromName, $to, $subject, $htmlBody);
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->encodeName($fromName) . " <$fromEmail>",
        ];
        $ok = @mail($to, $this->encodeSubject($subject), $htmlBody, implode("\r\n", $headers));
        return ['ok' => $ok, 'error' => $ok ? null : 'mail() returned false'];
    }

    private function sendSmtp(array $s, string $fromEmail, string $fromName, string $to, string $subject, string $html): array
    {
        $host   = $s['host'] ?? '';
        $port   = (int)($s['port'] ?? 587);
        $secure = $s['secure'] ?? 'tls'; // 'tls' | 'ssl' | ''
        $prefix = $secure === 'ssl' ? 'ssl://' : '';

        $fp = @stream_socket_client("$prefix$host:$port", $errno, $errstr, 20);
        if (!$fp) {
            return ['ok' => false, 'error' => "SMTP connect failed: $errstr ($errno)"];
        }

        $read = static function () use ($fp): string {
            $data = '';
            while ($line = fgets($fp, 515)) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = static function (string $c) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            return $read();
        };

        $read();
        $cmd('EHLO ' . ($host ?: 'localhost'));
        if ($secure === 'tls') {
            $cmd('STARTTLS');
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd('EHLO ' . ($host ?: 'localhost'));
        }
        if (!empty($s['user'])) {
            $cmd('AUTH LOGIN');
            $cmd(base64_encode((string)$s['user']));
            $cmd(base64_encode((string)($s['pass'] ?? '')));
        }
        $cmd("MAIL FROM:<$fromEmail>");
        $cmd("RCPT TO:<$to>");
        $cmd('DATA');

        $headers = "From: " . $this->encodeName($fromName) . " <$fromEmail>\r\n"
            . "To: <$to>\r\n"
            . "Subject: " . $this->encodeSubject($subject) . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $resp = $cmd($headers . $html . "\r\n.");
        $cmd('QUIT');
        fclose($fp);

        $ok = str_starts_with(trim($resp), '250');
        return ['ok' => $ok, 'error' => $ok ? null : "SMTP data response: $resp"];
    }

    private function encodeSubject(string $s): string
    {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }

    private function encodeName(string $s): string
    {
        return preg_match('/[^\x20-\x7e]/', $s) ? $this->encodeSubject($s) : $s;
    }
}
