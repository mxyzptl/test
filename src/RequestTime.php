<?php

declare(strict_types=1);

namespace Sky;

/**
 * Strict parser for the optional ?t= query parameter.
 *
 * Deliberately NOT `new DateTimeImmutable($raw)`: that constructor happily accepts
 * "now", "+3 days", "last monday" and other relative expressions, which would turn a
 * debugging aid into an unvalidated input. Only a fixed set of ISO 8601 shapes is allowed.
 */
final class RequestTime
{
    /** Longest accepted input; anything longer is rejected before any parsing work. */
    public const MAX_LENGTH = 40;

    /** Validity window of the algorithms (and of the deltaT polynomials we use). */
    private const MIN_YEAR = 1900;
    private const MAX_YEAR = 2100;

    /**
     * Widest UTC offset that exists on Earth (Line Islands, +14:00), which is also the range
     * DateTimeZone accepts. Anything outside is a client mistake, not a place. See BUG-0009.
     */
    private const MAX_OFFSET_MINUTES = 14 * 60;

    /**
     * How far to look for the UTC offsets that are in effect around a wall-clock reading.
     * One day is comfortably wider than the widest offset on Earth (14 h) and far narrower
     * than the distance between two DST transitions, so the two probes always land on the
     * two sides of at most one transition. See resolveLocal().
     */
    private const PROBE_SECONDS = 86400;

    private const PATTERN = '/^(\d{4})-(\d{2})-(\d{2})'          // date
        . '(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?'                // optional time
        . '(Z|[+-]\d{2}:?\d{2})?$/';                             // optional zone

    private function __construct()
    {
    }

    /**
     * @param string       $raw            the raw query parameter
     * @param \DateTimeZone $defaultZone   used when the input carries no offset
     *
     * @throws \InvalidArgumentException with a message that is safe to show to the client
     */
    public static function parse(string $raw, \DateTimeZone $defaultZone): \DateTimeImmutable
    {
        $value = trim($raw);

        if ($value === '') {
            throw new \InvalidArgumentException('A "t" paraméter üres.');
        }

        if (strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('A "t" paraméter túl hosszú (max. ' . self::MAX_LENGTH . ' karakter).');
        }

        if (preg_match(self::PATTERN, $value, $m) !== 1) {
            throw new \InvalidArgumentException(
                'A "t" paraméter formátuma érvénytelen. Várt formátum: 2026-08-17T20:31:00+02:00, '
                . '2026-08-17T20:31 vagy 2026-08-17.'
            );
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        $hour = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
        $minute = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;
        $second = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
        $offset = $m[7] ?? '';

        if (!checkdate($month, $day, $year)) {
            throw new \InvalidArgumentException('A "t" paraméterben megadott dátum nem létezik.');
        }

        if ($hour > 23 || $minute > 59 || $second > 59) {
            throw new \InvalidArgumentException('A "t" paraméterben megadott időpont nem létezik.');
        }

        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new \InvalidArgumentException(
                'A "t" paraméter a támogatott tartományon kívül esik (' . self::MIN_YEAR . '–' . self::MAX_YEAR . ').'
            );
        }

        $zone = self::resolveZone($offset, $defaultZone);

        return self::resolveLocal($year, $month, $day, $hour, $minute, $second, $zone);
    }

    /**
     * Turns a validated wall-clock reading into an instant. Same input, same answer, always.
     *
     * BUG-0015: the previous implementation was
     *
     *     (new \DateTimeImmutable('now', $zone))->setDate($y, $m, $d)->setTime($h, $i, $s)
     *
     * which reads harmlessly and is not. setDate() keeps the CURRENT time of day of the server
     * clock, and the DST flag of that intermediate value is what PHP then uses to resolve an
     * ambiguous setTime(). Measured on the VPS (Europe/Budapest, PHP 8.5.4): ?t=2026-10-25T02:30
     * answered +02:00 while the server clock read 00:00-02:59 and +01:00 for the other 21 hours -
     * the same request, two different instants, and two assertions that went red by time of day.
     *
     * So the base instant is gone: nothing below reads a clock, and the resolution is spelled
     * out rather than inherited from whatever PHP happens to do.
     *
     *   - no candidate   -> the local time does not exist (spring-forward gap) -> client error
     *   - one candidate  -> the ordinary case
     *   - two candidates -> the hour that exists twice (autumn fall-back). The contract
     *     (TASK-0003 API-szerződés v4) names the SECOND occurrence, i.e. standard time
     *     (+01:00 in Budapest), which is the LATER of the two instants. The first occurrence
     *     stays reachable with an explicit offset (?t=2026-10-25T02:30:00+02:00), and
     *     generated_at always carries the offset that was actually used.
     *
     * @throws \InvalidArgumentException with a message that is safe to show to the client
     */
    private static function resolveLocal(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
        \DateTimeZone $zone
    ): \DateTimeImmutable {
        // The wall-clock reading as a number: the very same calendar fields read as if they were
        // UTC. UTC has no transitions and no ambiguity, so this step is pure arithmetic.
        $wall = (new \DateTimeImmutable(
            sprintf('%04d-%02d-%02dT%02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
            new \DateTimeZone('UTC')
        ))->getTimestamp();

        /** @var array<int, true> $candidates instants whose local reading in $zone is exactly $wall */
        $candidates = [];

        foreach ([-self::PROBE_SECONDS, self::PROBE_SECONDS] as $probe) {
            $offset = $zone->getOffset(new \DateTimeImmutable('@' . ($wall + $probe)));
            $instant = $wall - $offset;

            // Round trip: the offset actually in effect at that instant must be the one we used.
            // If it is not, this wall-clock reading never happens with that offset - which is how
            // the spring-forward gap is detected, without relying on PHP silently shifting it.
            if ($zone->getOffset(new \DateTimeImmutable('@' . $instant)) === $offset) {
                $candidates[$instant] = true;
            }
        }

        if ($candidates === []) {
            throw new \InvalidArgumentException(
                'A megadott helyi időpont nem létezik (nyári időszámításra való átállás órája).'
            );
        }

        // One candidate: max() is that one. Two: max() is the later = second occurrence.
        return (new \DateTimeImmutable('@' . max(array_keys($candidates))))->setTimezone($zone);
    }

    /**
     * The regex only guarantees the SHAPE of the offset ([+-]dd:?dd), not that such an offset
     * exists. Two things went wrong before BUG-0009 was fixed:
     *
     *   "+9999" -> DateTimeZone threw DateInvalidTimeZoneException (PHP >= 8.3), which is NOT an
     *             InvalidArgumentException, so it escaped the catch in api/sky.php: 500, empty body.
     *   "+2400" -> accepted; the answer was silently for a different day.
     *   "+0099" -> accepted as +01:39; a plausible-looking answer for the wrong instant.
     *
     * So the offset is validated here, in numbers, before DateTimeZone ever sees it.
     *
     * @throws \InvalidArgumentException with a message that is safe to show to the client
     */
    private static function resolveZone(string $offset, \DateTimeZone $defaultZone): \DateTimeZone
    {
        if ($offset === '') {
            return $defaultZone;
        }

        if ($offset === 'Z') {
            return new \DateTimeZone('UTC');
        }

        $sign = $offset[0] === '-' ? -1 : 1;
        $digits = str_replace(':', '', substr($offset, 1));
        $hours = (int) substr($digits, 0, 2);
        $minutes = (int) substr($digits, 2, 2);

        if ($minutes > 59 || $hours * 60 + $minutes > self::MAX_OFFSET_MINUTES) {
            throw new \InvalidArgumentException(
                'A "t" paraméterben megadott időzóna-eltolás érvénytelen. '
                . 'Megengedett: -14:00 és +14:00 között, a perc rész legfeljebb 59.'
            );
        }

        return new \DateTimeZone(sprintf('%s%02d:%02d', $sign < 0 ? '-' : '+', $hours, $minutes));
    }
}
