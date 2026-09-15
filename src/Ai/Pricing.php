<?php
declare(strict_types=1);

namespace Glue\Ai;

use Glue\Config;
use Glue\Db;

/**
 * What an assistant answer cost, from the token counts the API returns with it
 * ("vorrei capire quanto costa ogni richiesta che faccio", 2026-09-15).
 *
 * Anthropic bills in US dollars per million tokens, each of four kinds at its
 * own rate: fresh input, prompt-cache writes (5-minute: 1.25x input), cache
 * reads (0.1x input; 0.025x on Fable 5.1) and output, which includes the
 * model's thinking. Rates from platform.claude.com/docs/en/about-claude/pricing,
 * checked 2026-09-15. The console's Usage page stays the exact bill; this is the
 * same arithmetic on the same counts, answer by answer.
 */
final class Pricing
{
    /** USD per million tokens: [input, cache write (5 min), cache read, output]. Longest matching prefix wins. */
    private const RATES = [
        'claude-fable-5-1'  => [10.0, 12.5, 0.25, 50.0],
        'claude-mythos-5-1' => [10.0, 12.5, 0.25, 50.0],
        'claude-fable-5'    => [10.0, 12.5, 1.0, 50.0],
        'claude-mythos-5'   => [10.0, 12.5, 1.0, 50.0],
        'claude-opus-5'     => [5.0, 6.25, 0.5, 25.0],
        'claude-opus-4-8'   => [5.0, 6.25, 0.5, 25.0],
        'claude-opus-4-7'   => [5.0, 6.25, 0.5, 25.0],
        'claude-opus-4-6'   => [5.0, 6.25, 0.5, 25.0],
        'claude-opus-4-5'   => [5.0, 6.25, 0.5, 25.0],
        'claude-sonnet-5'   => [2.0, 2.5, 0.2, 10.0],
        'claude-sonnet-4-6' => [3.0, 3.75, 0.3, 15.0],
        'claude-sonnet-4-5' => [3.0, 3.75, 0.3, 15.0],
        'claude-haiku-4-5'  => [1.0, 1.25, 0.1, 5.0],
    ];

    /** Rates for a model id (a dated id such as claude-haiku-4-5-20251001 matches its family), or null. */
    public static function rates(string $model): ?array
    {
        $best = null;
        $len  = 0;
        foreach (self::RATES as $prefix => $r) {
            if (str_starts_with($model, $prefix) && strlen($prefix) > $len) {
                $best = $r;
                $len  = strlen($prefix);
            }
        }
        return $best;
    }

    /** USD for one answer's usage ['in','cache_write','cache_read','out']; null when the model's price is unknown. */
    public static function cost(array $usage, string $model): ?float
    {
        $r = self::rates($model);
        if ($r === null) {
            return null;
        }
        return ((int)($usage['in'] ?? 0) * $r[0]
              + (int)($usage['cache_write'] ?? 0) * $r[1]
              + (int)($usage['cache_read'] ?? 0) * $r[2]
              + (int)($usage['out'] ?? 0) * $r[3]) / 1_000_000;
    }

    public static function tokens(array $usage): int
    {
        return (int)($usage['in'] ?? 0) + (int)($usage['cache_write'] ?? 0)
             + (int)($usage['cache_read'] ?? 0) + (int)($usage['out'] ?? 0);
    }

    /** What an answer's stored meta says it cost: the figure saved with it, else computed from its usage. */
    public static function ofMeta(array $meta): ?float
    {
        if (isset($meta['cost_usd']) && is_numeric($meta['cost_usd'])) {
            return (float)$meta['cost_usd'];
        }
        return empty($meta['usage']) ? null : self::cost((array)$meta['usage'], (string)($meta['model'] ?? ''));
    }

    /** "$ 0,043" (it) or "$0.043" (en), plus "(≈ € 0,037)" when Settings holds a dollar-to-euro rate. */
    public static function money(float $usd, string $lang = 'it'): string
    {
        $fmt = static function (float $v, string $sym) use ($lang): string {
            $dec = $v < 0.1 ? 3 : 2;
            return $lang === 'en' ? $sym . number_format($v, $dec, '.', ',') : $sym . ' ' . number_format($v, $dec, ',', '.');
        };
        $out  = $fmt($usd, '$');
        $rate = self::eurRate();
        return $rate > 0 ? $out . ' (≈ ' . $fmt($usd * $rate, '€') . ')' : $out;
    }

    /** Settings → Assistente AI: euros per dollar, e.g. "0,86"; 0 when unset or implausible. */
    public static function eurRate(): float
    {
        $raw = str_replace(',', '.', trim((string)Config::get('ai.usd_eur', '')));
        return is_numeric($raw) && (float)$raw > 0 && (float)$raw < 10 ? (float)$raw : 0.0;
    }

    /**
     * For the Settings page: this month and last month, and this month per
     * person (an assistant chat belongs to one user: direct_key "ai:<uid>").
     * @return array{this:array{answers:int,tokens:int,usd:float}, prev:array{answers:int,tokens:int,usd:float}, people:array<int,array{answers:int,usd:float}>}
     */
    public static function summary(): array
    {
        $blank = ['answers' => 0, 'tokens' => 0, 'usd' => 0.0];
        $out   = ['this' => $blank, 'prev' => $blank, 'people' => []];
        $thisStart = date('Y-m-01 00:00:00');
        $prevStart = date('Y-m-01 00:00:00', strtotime('first day of last month'));
        $st = Db::pdo()->prepare(
            "SELECT m.created_at, m.meta, c.direct_key
               FROM team_messages m JOIN team_chats c ON c.id = m.chat_id
              WHERE m.role = 'assistant' AND m.created_at >= ?"
        );
        $st->execute([$prevStart]);
        foreach ($st as $r) {
            $meta = json_decode((string)$r['meta'], true) ?: [];
            if (empty($meta['usage'])) {
                continue;
            }
            $usd = self::ofMeta($meta) ?? 0.0;
            $k   = (string)$r['created_at'] >= $thisStart ? 'this' : 'prev';
            $out[$k]['answers']++;
            $out[$k]['tokens'] += self::tokens((array)$meta['usage']);
            $out[$k]['usd']    += $usd;
            if ($k === 'this' && preg_match('/^ai:(\d+)$/', (string)$r['direct_key'], $mm)) {
                $uid = (int)$mm[1];
                $out['people'][$uid] ??= ['answers' => 0, 'usd' => 0.0];
                $out['people'][$uid]['answers']++;
                $out['people'][$uid]['usd'] += $usd;
            }
        }
        uasort($out['people'], static fn($a, $b) => $b['usd'] <=> $a['usd']);
        return $out;
    }
}
