<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Config;

/**
 * One phone field for the whole application: a country selector defaulting to
 * Italy next to the number box, and the rule that turns what the user typed
 * into the stored international form.
 *
 * Customers are almost all Italian and almost never type +39 themselves; the
 * old text boxes with a "+39…" placeholder relied on each save path to guess
 * the country. Now every form posts the number as two fields — NAME and
 * NAME_cc — and applyPosted() joins them before any handler reads $_POST, so
 * the dashboard, the partner area, the portal and the public forms all store
 * the same shape without each caring where the number came from.
 *
 * The rule (compose): a number that already starts with + or 00 is taken as
 * international whatever the selector says; otherwise the chosen dial code is
 * prepended. For Italy the digits are kept verbatim because an Italian
 * landline keeps its leading 0 in E.164 (0972 35294 -> +39097235294, the form
 * the customer registry stores); for every other country one trunk zero is
 * dropped (07911 123456 -> +447911123456).
 */
final class Phone
{
    /** dial code => [flag, Italian name, English name]. Italy first: it is the default. */
    public const COUNTRIES = [
        '39'  => ['🇮🇹', 'Italia', 'Italy'],
        '44'  => ['🇬🇧', 'Regno Unito', 'United Kingdom'],
        '33'  => ['🇫🇷', 'Francia', 'France'],
        '49'  => ['🇩🇪', 'Germania', 'Germany'],
        '34'  => ['🇪🇸', 'Spagna', 'Spain'],
        '41'  => ['🇨🇭', 'Svizzera', 'Switzerland'],
        '43'  => ['🇦🇹', 'Austria', 'Austria'],
        '32'  => ['🇧🇪', 'Belgio', 'Belgium'],
        '31'  => ['🇳🇱', 'Paesi Bassi', 'Netherlands'],
        '351' => ['🇵🇹', 'Portogallo', 'Portugal'],
        '30'  => ['🇬🇷', 'Grecia', 'Greece'],
        '40'  => ['🇷🇴', 'Romania', 'Romania'],
        '48'  => ['🇵🇱', 'Polonia', 'Poland'],
        '355' => ['🇦🇱', 'Albania', 'Albania'],
        '1'   => ['🇺🇸', 'USA / Canada', 'USA / Canada'],
        '971' => ['🇦🇪', 'Emirati Arabi', 'UAE'],
        '212' => ['🇲🇦', 'Marocco', 'Morocco'],
        '254' => ['🇰🇪', 'Kenya', 'Kenya'],
    ];

    /** The selector's default: app.default_country_code when it is a listed code, else Italy. */
    public static function defaultCc(): string
    {
        $cc = preg_replace('/\D+/', '', (string)Config::get('app.default_country_code', '39')) ?? '';
        return isset(self::COUNTRIES[$cc]) ? $cc : '39';
    }

    /** "🇮🇹 +39 Italia" — the text of one selector option. */
    public static function optionLabel(string $dial, string $lang = 'it'): string
    {
        $c = self::COUNTRIES[$dial] ?? null;
        if ($c === null) {
            return '+' . $dial;
        }
        return $c[0] . ' +' . $dial . ' ' . ($lang === 'it' ? $c[1] : $c[2]);
    }

    /**
     * What the user typed + the country they chose -> stored international
     * number ('' when there are no digits).
     */
    public static function compose(string $raw, ?string $cc): string
    {
        $raw    = trim($raw);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($raw, '+')) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '00')) {
            $rest = substr($digits, 2);
            return $rest === '' ? '' : '+' . $rest;
        }
        $cc = preg_replace('/\D+/', '', (string)$cc) ?? '';
        if (!isset(self::COUNTRIES[$cc])) {
            $cc = self::defaultCc();
        }
        if ($cc === '39') {
            // "39 339 1234567" typed with the prefix but without the + — an
            // Italian number is never 12+ digits on its own.
            if (strlen($digits) >= 12 && str_starts_with($digits, '39')) {
                return '+' . $digits;
            }
            return '+39' . $digits; // the trunk 0 is part of an Italian landline
        }
        $national = preg_replace('/^0/', '', $digits) ?? $digits;
        return $national === '' ? '' : '+' . $cc . $national;
    }

    /**
     * A stored number back into selector + box for an edit form:
     * +393391234567 -> ['cc' => '39', 'number' => '3391234567']. A country the
     * selector does not list keeps the whole +… number in the box (compose()
     * respects the +); a legacy local number is shown as it is.
     *
     * @return array{cc:string, number:string}
     */
    public static function split(?string $stored): array
    {
        $s = trim((string)$stored);
        $default = self::defaultCc();
        if ($s === '' || !str_starts_with($s, '+')) {
            return ['cc' => $default, 'number' => $s];
        }
        $digits = substr($s, 1);
        $codes = array_keys(self::COUNTRIES);
        usort($codes, fn($a, $b) => strlen((string)$b) <=> strlen((string)$a)); // longest first
        foreach ($codes as $cc) {
            $cc = (string)$cc;
            if (str_starts_with($digits, $cc) && strlen($digits) > strlen($cc)) {
                return ['cc' => $cc, 'number' => substr($digits, strlen($cc))];
            }
        }
        return ['cc' => $default, 'number' => $s];
    }

    /**
     * Join every NAME + NAME_cc pair posted by a form into NAME, so handlers
     * keep reading $_POST['phone'] and find the international number there.
     * Fields without a _cc twin are left alone.
     */
    public static function applyPosted(array &$post): void
    {
        foreach (array_keys($post) as $key) {
            if (!is_string($key) || !str_ends_with($key, '_cc')) {
                continue;
            }
            $base = substr($key, 0, -3);
            if ($base !== '' && isset($post[$base]) && is_string($post[$base])) {
                $post[$base] = self::compose($post[$base], is_string($post[$key]) ? $post[$key] : null);
            }
        }
    }
}
