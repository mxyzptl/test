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

        return $parsed;
    }

    private static function resolveZone(string $offset, \DateTimeZone $defaultZone): \DateTimeZone
    {
        if ($offset === '') {
            return $defaultZone;
        }

        if ($offset === 'Z') {
            return new \DateTimeZone('UTC');
        }

        // Normalise "+0200" to "+02:00" so DateTimeZone accepts it.
        if (!str_contains($offset, ':')) {
            $offset = substr($offset, 0, 3) . ':' . substr($offset, 3);
        }

        return new \DateTimeZone($offset);
    }
}
