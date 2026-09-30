<?php
declare(strict_types=1);

namespace Glue\Campaign;

use Glue\Crm\Customers;
use Glue\Db;
use Glue\Notify\Notifier;

/**
 * Who a campaign goes to, worked out from what the page posted (migration 063).
 *
 * "Nel menu Campagne mi dai la possibilità di scegliere i clienti dalla lista
 *  così da non scriverli."
 *
 * Three ways in, and they add up:
 *
 *   contact_ids[]  customers ticked one by one in the picker
 *   groups[]       a whole filter of the Clienti tab ("all customers", "with a
 *                  support contract", "overdue"…), resolved HERE and not in the
 *                  browser: the office ticks a group, and who is in it is read
 *                  at the moment the campaign is created
 *   recipients     the free-text box, still there for a number or an address
 *                  that is not in the registry
 *
 * A contact with no phone (WhatsApp) or no address (email) is not a recipient
 * for that channel and is dropped with a count, rather than queued to fail one
 * by one. Everyone is deduplicated on the normalised phone / lower-cased email,
 * so the same customer reached by two routes is written once.
 */
final class Audience
{
    /** The Clienti tab's own filters, so "the list" means the same thing here. */
    public const GROUPS = ['all', 'support', 'expiring', 'expired', 'owing', 'leads'];

    /** Never build a bigger queue than this from one form post. */
    private const MAX = 5000;

    /**
     * @param array  $post    contact_ids[], groups[], recipients (text)
     * @param string $channel whatsapp | email
     * @return array{recipients:array<int,array{recipient:string,name:?string,contact_id:?int}>,
     *               skipped:int, from_list:int, typed:int}
     */
    public static function resolve(array $post, string $channel): array
    {
        $wa       = $channel !== 'email';
        $out      = [];
        $seen     = [];
        $seenCont = [];   // a customer reached by two routes is looked at once
        $skipped  = 0;
        $list     = 0;

        $add = static function (?string $raw, ?string $name, ?int $contactId) use (&$out, &$seen, &$skipped, $wa): bool {
            $to = $wa ? Notifier::normalizePhone((string)$raw) : mb_strtolower(trim((string)$raw));
            if ($to === '' || ($wa ? !preg_match('/^\+?\d{6,}$/', $to) : !filter_var($to, FILTER_VALIDATE_EMAIL))) {
                $skipped++;
                return false;
            }
            if (isset($seen[$to]) || count($out) >= self::MAX) {
                return false;
            }
            $seen[$to] = true;
            $out[] = ['recipient' => $to, 'name' => $name !== null && trim($name) !== '' ? trim($name) : null,
                      'contact_id' => $contactId ?: null];
            return true;
        };

        // ---- ticked in the picker ----
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($post['contact_ids'] ?? [])))));
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = Db::pdo()->query(
                'SELECT id, name, company, phone, phone2, email FROM contacts WHERE id IN (' . implode(',', $chunk) . ')'
            )->fetchAll() ?: [];
            foreach ($rows as $c) {
                $seenCont[(int)$c['id']] = true;
                $list += $add(self::channelValue($c, $wa), self::label($c), (int)$c['id']) ? 1 : 0;
            }
        }

        // ---- whole groups from the Clienti tab ----
        foreach ((array)($post['groups'] ?? []) as $g) {
            $g = (string)$g;
            if (!in_array($g, self::GROUPS, true)) {
                continue;
            }
            foreach (self::group($g) as $c) {
                if (isset($seenCont[(int)$c['id']])) {
                    continue;   // already ticked by hand, or already in another group
                }
                $seenCont[(int)$c['id']] = true;
                $list += $add(self::channelValue($c, $wa), self::label($c), (int)$c['id']) ? 1 : 0;
            }
        }

        // ---- typed by hand, one per line ----
        $typed = 0;
        foreach (preg_split('/\r\n|\r|\n|,|;/', (string)($post['recipients'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $typed += $add($line, null, null) ? 1 : 0;
        }

        return ['recipients' => $out, 'skipped' => $skipped, 'from_list' => $list, 'typed' => $typed];
    }

    /** How many a group would add — the number shown beside the tick box. */
    public static function groupCount(string $group, string $channel): int
    {
        if (!in_array($group, self::GROUPS, true)) {
            return 0;
        }
        $wa = $channel !== 'email';
        $n  = 0;
        foreach (self::group($group) as $c) {
            $v = self::channelValue($c, $wa);
            if ($v !== null && trim($v) !== '') {
                $n++;
            }
        }
        return $n;
    }

    /** Every contact in one of the Clienti tab's filters. */
    private static function group(string $group): array
    {
        $rows = [];
        $page = 1;
        do {
            // Same reading as the Clienti page, so a group means on this form
            // exactly what it means on that tab.
            $res = Customers::search(['state' => $group === 'all' ? 'all' : $group], $page, 500);
            foreach ($res['rows'] as $r) {
                $rows[] = $r;
            }
            $page++;
        } while ($page <= (int)$res['pages'] && count($rows) < self::MAX);
        return $rows;
    }

    /** The phone (WhatsApp) or the address (email) a contact can be reached on. */
    private static function channelValue(array $c, bool $wa): ?string
    {
        if (!$wa) {
            return trim((string)($c['email'] ?? '')) ?: null;
        }
        return trim((string)($c['phone'] ?? '')) ?: (trim((string)($c['phone2'] ?? '')) ?: null);
    }

    /** What {name} says in the message: the person, or the business. */
    private static function label(array $c): string
    {
        return trim((string)($c['name'] ?? '')) ?: trim((string)($c['company'] ?? ''));
    }
}
