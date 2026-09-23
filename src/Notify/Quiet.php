<?php
declare(strict_types=1);

namespace Glue\Notify;

use Glue\Config;

/**
 * When a message is worth a WhatsApp, and when it is just the next line of a
 * conversation somebody is already reading.
 *
 * Notifying on every message is what makes people mute the notification, and a
 * muted channel carries nothing at all. The rule is the client's: a new
 * conversation always announces itself, and after that only a message arriving
 * into SILENCE does — more than a quarter of an hour since the last one.
 *
 * Measured against the previous message in the thread whatever its sender, on
 * purpose. Two people typing back and forth are both looking at the screen, and
 * pinging either of them adds nothing; the moment the exchange stops and one of
 * them picks it up again half an hour later, that IS news.
 */
final class Quiet
{
    private const DEFAULT_MINUTES = 15;

    /** The silence a message has to arrive into before it is announced. */
    public static function minutes(): int
    {
        return max(0, min(1440, (int)Config::get('notify.quiet_minutes', self::DEFAULT_MINUTES)));
    }

    /**
     * Has the thread been quiet long enough for this message to be announced?
     *
     * @param ?string $previousAt when the previous message in the same thread
     *        landed; null or '' means there was none — a conversation starting,
     *        which always announces itself.
     */
    public static function breaks(?string $previousAt): bool
    {
        $previousAt = trim((string)$previousAt);
        if ($previousAt === '') {
            return true; // nothing before it: this is the start of the conversation
        }
        $ts = strtotime($previousAt);
        if ($ts === false) {
            return true; // unreadable timestamp: announce rather than swallow
        }
        $window = self::minutes();
        if ($window <= 0) {
            return true; // switched off: every message announces itself, as before
        }
        return (time() - $ts) >= $window * 60;
    }
}
