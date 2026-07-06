<?php
declare(strict_types=1);

namespace Glue\Devices;

use RuntimeException;

/**
 * Minimal RouterOS API client (raw socket, no extensions). Connects to a
 * MikroTik's API port, logs in, and runs /ping. Dependency-free so the poller
 * works from CLI/cron. Throws on connect/login failure so callers can tell
 * "router unreachable" apart from "device down".
 */
final class RouterOsApi
{
    /** @var resource */
    private $sock;

    public function __construct(string $host, int $port, float $timeout = 5.0)
    {
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$sock) {
            throw new RuntimeException("connect failed: $errstr ($errno)");
        }
        stream_set_timeout($sock, (int)ceil($timeout) + 5);
        $this->sock = $sock;
    }

    public function login(string $user, string $pass): void
    {
        // RouterOS 6.43+ plain login: one sentence with =name= / =password=.
        $this->writeSentence(['/login', '=name=' . $user, '=password=' . $pass]);
        $reply = $this->readSentence();
        if (($reply[0] ?? '') !== '!done') {
            throw new RuntimeException('login failed: ' . implode(' ', $reply));
        }
    }

    /**
     * Ping $address $count times. Returns [bool up, ?float latencyMs].
     * up = at least one reply; latency = smallest reported time.
     */
    public function ping(string $address, int $count = 2): array
    {
        $this->writeSentence(['/ping', '=address=' . $address, '=count=' . $count]);

        $received = 0;
        $minMs = null;
        while (true) {
            $sentence = $this->readSentence();
            if (!$sentence) {
                break;
            }
            $type = $sentence[0];
            if ($type === '!re') {
                $attrs = $this->parseAttrs($sentence);
                if (isset($attrs['time']) && $attrs['time'] !== '') {
                    $received++;
                    $ms = $this->timeToMs($attrs['time']);
                    if ($ms !== null && ($minMs === null || $ms < $minMs)) {
                        $minMs = $ms;
                    }
                }
            } elseif ($type === '!done' || $type === '!trap' || $type === '!fatal') {
                break;
            }
        }
        return [$received > 0, $minMs];
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
    }

    // ---- wire protocol -------------------------------------------------

    /** @param string[] $sentence */
    private function parseAttrs(array $sentence): array
    {
        $attrs = [];
        foreach ($sentence as $word) {
            if ($word !== '' && $word[0] === '=') {
                $eq = strpos($word, '=', 1);
                if ($eq !== false) {
                    $attrs[substr($word, 1, $eq - 1)] = substr($word, $eq + 1);
                }
            }
        }
        return $attrs;
    }

    /** RouterOS time strings like "1ms234us", "563us", "5ms604us", "1s200ms". */
    private function timeToMs(string $t): ?float
    {
        if (!preg_match_all('/([\d.]+)(us|ms|s)/', $t, $m, PREG_SET_ORDER)) {
            return is_numeric($t) ? (float)$t : null;
        }
        $ms = 0.0;
        foreach ($m as $part) {
            $val = (float)$part[1];
            $ms += match ($part[2]) {
                'us' => $val / 1000.0,
                'ms' => $val,
                's'  => $val * 1000.0,
                default => 0.0,
            };
        }
        return $ms;
    }

    /** @param string[] $words */
    private function writeSentence(array $words): void
    {
        foreach ($words as $w) {
            fwrite($this->sock, $this->encodeLength(strlen($w)) . $w);
        }
        fwrite($this->sock, chr(0));
    }

    /** @return string[] */
    private function readSentence(): array
    {
        $words = [];
        while (true) {
            $len = $this->readLength();
            if ($len === 0) {
                break;
            }
            $words[] = $this->readBytes($len);
        }
        return $words;
    }

    private function encodeLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        if ($len < 0x4000) {
            $len |= 0x8000;
            return chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
        if ($len < 0x200000) {
            $len |= 0xC00000;
            return chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
        if ($len < 0x10000000) {
            $len |= 0xE0000000;
            return chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
        return chr(0xF0) . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
    }

    private function readLength(): int
    {
        $c = ord($this->readBytes(1));
        if (($c & 0x80) === 0x00) {
            return $c;
        }
        if (($c & 0xC0) === 0x80) {
            return (($c & 0x3F) << 8) + ord($this->readBytes(1));
        }
        if (($c & 0xE0) === 0xC0) {
            $r = ($c & 0x1F) << 16;
            $r += ord($this->readBytes(1)) << 8;
            $r += ord($this->readBytes(1));
            return $r;
        }
        if (($c & 0xF0) === 0xE0) {
            $r = ($c & 0x0F) << 24;
            $r += ord($this->readBytes(1)) << 16;
            $r += ord($this->readBytes(1)) << 8;
            $r += ord($this->readBytes(1));
            return $r;
        }
        $r = ord($this->readBytes(1)) << 24;
        $r += ord($this->readBytes(1)) << 16;
        $r += ord($this->readBytes(1)) << 8;
        $r += ord($this->readBytes(1));
        return $r;
    }

    private function readBytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->sock, $n - strlen($buf));
            if ($chunk === '' || $chunk === false) {
                $meta = stream_get_meta_data($this->sock);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('read timed out');
                }
                throw new RuntimeException('connection closed mid-read');
            }
            $buf .= $chunk;
        }
        return $buf;
    }
}
