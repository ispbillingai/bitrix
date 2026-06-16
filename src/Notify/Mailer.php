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
        // EHLO name should be a hostname we own, not the server's — fall back to the
        // from-address domain, never the remote host (some servers reject that).
        $ehlo = substr(strrchr($fromEmail, '@') ?: '@localhost', 1) ?: 'localhost';

        $fp = @stream_socket_client("$prefix$host:$port", $errno, $errstr, 15);
        if (!$fp) {
            return ['ok' => false, 'error' => "SMTP connect failed: $errstr ($errno)"];
        }
        stream_set_timeout($fp, 15); // a silent server must not block the process

        $read = static function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $data .= $line;
                // A multi-line reply has '-' as the 4th char on every line but the
                // last, which has a space. Stop once we see the final line.
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            return rtrim($data);
        };
        $cmd = static function (string $c) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            return $read();
        };
        // Did the server reply with one of the expected status codes?
        $expect = static function (string $resp, array $codes): bool {
            $code = substr(ltrim($resp), 0, 3);
            return in_array($code, $codes, true);
        };
        $fail = static function (string $stage, string $resp) use ($fp): array {
            @fwrite($fp, "QUIT\r\n");
            @fclose($fp);
            $resp = trim($resp);
            return ['ok' => false, 'error' => "SMTP $stage failed" . ($resp !== '' ? ": $resp" : ' (no/empty response — connection may have dropped or timed out)')];
        };

        $greet = $read();
        if (!$expect($greet, ['220'])) {
            return $fail('greeting', $greet);
        }
        if (!$expect($r = $cmd("EHLO $ehlo"), ['250'])) {
            return $fail('EHLO', $r);
        }
        if ($secure === 'tls') {
            if (!$expect($r = $cmd('STARTTLS'), ['220'])) {
                return $fail('STARTTLS', $r);
            }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                return $fail('TLS handshake', '');
            }
            if (!$expect($r = $cmd("EHLO $ehlo"), ['250'])) {
                return $fail('EHLO (post-TLS)', $r);
            }
        }
        if (!empty($s['user'])) {
            if (!$expect($r = $cmd('AUTH LOGIN'), ['334'])) {
                return $fail('AUTH', $r);
            }
            if (!$expect($r = $cmd(base64_encode((string)$s['user'])), ['334'])) {
                return $fail('AUTH username', $r);
            }
            if (!$expect($r = $cmd(base64_encode((string)($s['pass'] ?? ''))), ['235'])) {
                return $fail('AUTH password', $r);
            }
        }
        if (!$expect($r = $cmd("MAIL FROM:<$fromEmail>"), ['250'])) {
            return $fail('MAIL FROM', $r);
        }
        if (!$expect($r = $cmd("RCPT TO:<$to>"), ['250', '251'])) {
            return $fail('RCPT TO', $r);
        }
        if (!$expect($r = $cmd('DATA'), ['354'])) {
            return $fail('DATA', $r);
        }

        $headers = "From: " . $this->encodeName($fromName) . " <$fromEmail>\r\n"
            . "To: <$to>\r\n"
            . "Subject: " . $this->encodeSubject($subject) . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n\r\n";

        // Normalise the body to CRLF and dot-stuff it (RFC 5321 §4.5.2): a line
        // that starts with '.' must be sent as '..', else a body line of "." would
        // prematurely end the message. Then terminate with a lone "." line.
        $body = preg_replace('/\r\n|\r|\n/', "\r\n", $headers . $html);
        $body = preg_replace('/^\./m', '..', (string)$body);
        fwrite($fp, $body . "\r\n.\r\n");
        $resp = $read();
        $cmd('QUIT');
        fclose($fp);

        $ok = $expect($resp, ['250']);
        return ['ok' => $ok, 'error' => $ok ? null
            : 'SMTP message rejected' . (trim($resp) !== '' ? ': ' . trim($resp) : ' (no/empty response — connection dropped or timed out at DATA)')];
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
