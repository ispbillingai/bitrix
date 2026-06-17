<?php
declare(strict_types=1);

namespace Glue\Reminder;

use Glue\Config;
use Glue\Settings;

/**
 * Renders the per-rule message copy in the recipient's language. Copy lives in
 * lang/en.php and lang/it.php (same convention as the order app's lang/*.php) so
 * non-developers can edit wording without touching code.
 *
 * Language is resolved per message (a customer's lead carries a 'lang'), not
 * globally — different customers get different languages from the same run.
 */
final class Templates
{
    /** Loaded lang arrays, cached by code. */
    private static array $cache = [];

    public static function available(): array
    {
        return ['en', 'it'];
    }

    /** Normalise/validate a language code, falling back to the configured default. */
    public static function lang(?string $code): string
    {
        $code = strtolower(substr(trim((string)$code), 0, 2));
        if (in_array($code, self::available(), true)) {
            return $code;
        }
        $default = strtolower((string)Config::get('app.default_lang', 'it'));
        return in_array($default, self::available(), true) ? $default : 'en';
    }

    /** Fill "{name}" style placeholders from $vars; unknown ones stay literal. */
    public static function render(string $tpl, array $vars): string
    {
        return preg_replace_callback('/\{(\w+)\}/', static function ($m) use ($vars) {
            return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0];
        }, $tpl) ?? $tpl;
    }

    /** WhatsApp text for a rule_key in $lang. Dashboard override wins over the file default. */
    public static function whatsapp(string $ruleKey, array $vars, ?string $lang = null): string
    {
        $lang = self::lang($lang);
        $tpl = self::override('wa', $ruleKey, $lang)
            ?? self::load($lang)['wa'][$ruleKey]
            ?? self::load('en')['wa'][$ruleKey]
            ?? '{name}, we have an update for you.';
        return self::render($tpl, $vars);
    }

    /** ['subject'=>..., 'html'=>...] for a rule_key in $lang. Dashboard overrides win. */
    public static function email(string $ruleKey, array $vars, ?string $lang = null): array
    {
        $lang = self::lang($lang);
        $e = self::load($lang)['email'][$ruleKey]
            ?? self::load('en')['email'][$ruleKey]
            ?? ['subject' => 'Update', 'html' => '<p>Hello {name}.</p>'];
        $subject = self::override('es', $ruleKey, $lang) ?? (string)($e['subject'] ?? '');
        $html    = self::override('eh', $ruleKey, $lang) ?? (string)($e['html'] ?? '');
        return [
            'subject' => self::render($subject, $vars),
            'html'    => self::render($html, $vars),
        ];
    }

    // ---- editable templates (dashboard) --------------------------------------

    /** Rule keys that have editable copy, in display order. */
    public static function ruleKeys(): array
    {
        return array_keys(self::load('en')['wa'] ?? []);
    }

    /**
     * The dashboard override for a template, or null if none set. $kind is
     * 'wa' (WhatsApp), 'es' (email subject) or 'eh' (email HTML). Stored in the
     * settings table under colon-keys so it never collides with dot-path config.
     */
    private static function override(string $kind, string $ruleKey, string $lang): ?string
    {
        $v = Settings::get("tpl:$kind:$ruleKey:$lang");
        $v = is_string($v) ? trim($v) : '';
        return $v !== '' ? $v : null;
    }

    /** The shipped (file) default for a template — what's used when no override is set. */
    public static function defaultText(string $kind, string $ruleKey, string $lang): string
    {
        $s = self::load(self::lang($lang));
        if ($kind === 'wa') {
            return (string)($s['wa'][$ruleKey] ?? '');
        }
        $e = $s['email'][$ruleKey] ?? [];
        return (string)($kind === 'es' ? ($e['subject'] ?? '') : ($e['html'] ?? ''));
    }

    /** The current custom override text for the editor, or '' if using the default. */
    public static function overrideText(string $kind, string $ruleKey, string $lang): string
    {
        return (string)(self::override($kind, $ruleKey, self::lang($lang)) ?? '');
    }

    /** Settings key for a template override (used by the save handler). */
    public static function key(string $kind, string $ruleKey, string $lang): string
    {
        return "tpl:$kind:$ruleKey:" . self::lang($lang);
    }

    private static function load(string $lang): array
    {
        if (!isset(self::$cache[$lang])) {
            $file = dirname(__DIR__, 2) . '/lang/' . $lang . '.php';
            self::$cache[$lang] = is_file($file) ? (array)require $file : [];
        }
        return self::$cache[$lang];
    }
}
