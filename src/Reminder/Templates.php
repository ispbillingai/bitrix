<?php
declare(strict_types=1);

namespace Glue\Reminder;

use Glue\Config;

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

    /** WhatsApp text for a rule_key in $lang. */
    public static function whatsapp(string $ruleKey, array $vars, ?string $lang = null): string
    {
        $strings = self::load(self::lang($lang));
        $tpl = $strings['wa'][$ruleKey]
            ?? self::load('en')['wa'][$ruleKey]
            ?? '{name}, we have an update for you.';
        return self::render($tpl, $vars);
    }

    /** ['subject'=>..., 'html'=>...] for a rule_key in $lang. */
    public static function email(string $ruleKey, array $vars, ?string $lang = null): array
    {
        $strings = self::load(self::lang($lang));
        $e = $strings['email'][$ruleKey]
            ?? self::load('en')['email'][$ruleKey]
            ?? ['subject' => 'Update', 'html' => '<p>Hello {name}.</p>'];
        return [
            'subject' => self::render($e['subject'], $vars),
            'html'    => self::render($e['html'], $vars),
        ];
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
