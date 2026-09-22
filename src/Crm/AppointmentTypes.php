<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;

/**
 * Appointment labels — INSTALLAZIONE, ASSISTENZA, SOPRALLUOGO, and whatever
 * else the office adds later.
 *
 * A label is not the same thing as `appointments.kind`, and the two are
 * deliberately kept apart: `kind` ('sales' | 'intervention') decides which
 * family of messages a visit sends, and there are exactly two of those because
 * there are exactly two sets of templates. The label decides what the visit is
 * CALLED, what colour it wears in the calendar, and which form it opens. Each
 * label declares the kind it belongs to, so picking one sets both and the
 * person booking never sees the distinction.
 *
 * Rows live in a table rather than an enum because the client's list ended in
 * "etc." — a new label is a row, not a migration.
 */
final class AppointmentTypes
{
    /** @var array<string,array>|null in-request cache: the calendar asks per event */
    private static ?array $cache = null;

    /** @return array<string,array> code => row, active only, in display order */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $rows = Db::pdo()->query(
            'SELECT * FROM appointment_types WHERE active = 1 ORDER BY sort, name_it'
        )->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['code']] = $r;
        }
        return self::$cache = $out;
    }

    public static function find(?string $code): ?array
    {
        $code = trim((string)$code);
        return $code === '' ? null : (self::all()[$code] ?? null);
    }

    /** The label in the reader's language, falling back to the raw code. */
    public static function label(?string $code, string $lang = 'it'): string
    {
        $t = self::find($code);
        if (!$t) {
            return (string)$code;
        }
        return (string)($lang === 'en' ? $t['name_en'] : $t['name_it']);
    }

    public static function color(?string $code): string
    {
        return (string)(self::find($code)['color'] ?? '#6b7280');
    }

    /**
     * Which message family this label belongs to. An unknown label is treated
     * as a technician's visit: every label the client named is one, and a
     * visit that wrongly reminds like a sales meeting is a smaller error than
     * one that reminds nobody.
     */
    public static function kindOf(?string $code): string
    {
        return (string)(self::find($code)['kind'] ?? Interventions::KIND);
    }

    /** Does this label carry an installation report? */
    public static function opensInstallForm(?string $code): bool
    {
        return (int)(self::find($code)['opens_install_form'] ?? 0) === 1;
    }

    /** The default label for a new booking — the first one the office listed. */
    public static function defaultCode(): string
    {
        return (string)(array_key_first(self::all()) ?? 'SUPPORT');
    }
}
