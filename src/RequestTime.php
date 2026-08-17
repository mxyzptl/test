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

        $parsed = (new \DateTimeImmutable('now', $zone))
            ->setDate($year, $month, $day)
            ->setTime($hour, $minute, $second);

        // A local time that does not exist (the DST spring-forward gap) is silently
        // shifted by PHP. Say so instead of quietly answering for another instant.
        if ($offset === '' && (int) $parsed->format('H') !== $hour) {
            throw new \InvalidArgumentException(
                'A megadott helyi időpont nem létezik (nyári időszámításra való átállás órája).'
            );
        }

        // The mirror image - a local time that exists TWICE (the autumn fall-back hour, e.g.
        // 2026-10-25 02:30 in Europe/Budapest) - cannot be rejected the same way: both readings
        // are legitimate. PHP resolves it to the standard-time (CET, +01:00) occurrence, i.e. the
        // SECOND one, and the contract now says so explicitly instead of leaving it to chance
        // (S3-2). generated_at always carries the resolved offset, so the client can see which
        // one it got, and ?t=...+02:00 asks for the first occurrence. Pinned by a test.

        return $parsed;
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
