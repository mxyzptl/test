<?php

declare(strict_types=1);

namespace Sky;

/**
 * Finds the instants at which a body's altitude crosses a given value.
 *
 * Meeus ch. 15 gives a closed-form (interpolated) solution for rise/set, but it needs a
 * separate special case for every body and every target altitude, and it degrades near
 * the "no event today" boundary. A scan + bisection is a few hundred cheap evaluations,
 * is identical for the Sun and the Moon, handles all four twilight targets with the same
 * code, and simply returns null when there is no crossing - which for the Moon happens
 * roughly once a month and must not be reported as an error.
 */
final class RiseSetFinder
{
    /** Coarse scan step. 10 minutes is safe: neither body changes altitude fast enough to hide a crossing pair. */
    private const STEP_MINUTES = 10.0;

    /** Bisection stops when the bracket is narrower than this (0.25 s) - far below the 1 minute we display. */
    private const PRECISION_DAYS = 0.25 / 86400.0;

    private function __construct()
    {
    }

    /**
     * Evaluate the altitude function on a regular grid once, so that several targets
     * (rise/set + three twilights) can reuse the same samples.
     *
     * @param callable(float): float $altitudeAt
     *
     * @return array<int, array{0: float, 1: float}> list of [jd, altitudeDeg]
     */
    public static function sample(callable $altitudeAt, float $startJd, float $endJd): array
    {
        $step = self::STEP_MINUTES / 1440.0;
        $samples = [];

        for ($jd = $startJd; $jd < $endJd; $jd += $step) {
            $samples[] = [$jd, $altitudeAt($jd)];
        }
        $samples[] = [$endJd, $altitudeAt($endJd)];

        return $samples;
    }

    /**
     * First upward (rise) and first downward (set) crossing of $targetDeg inside the sampled window.
     *
     * @param array<int, array{0: float, 1: float}> $samples
     * @param callable(float): float                $altitudeAt
     *
     * @return array{rise: ?float, set: ?float} Julian Days in UT, or null
     */
    public static function crossings(array $samples, callable $altitudeAt, float $targetDeg): array
    {
        $rise = null;
        $set = null;

        $count = count($samples);
        for ($i = 1; $i < $count; $i++) {
            $before = $samples[$i - 1][1] - $targetDeg;
            $after = $samples[$i][1] - $targetDeg;

            if ($before < 0.0 && $after >= 0.0 && $rise === null) {
                $rise = self::bisect($altitudeAt, $targetDeg, $samples[$i - 1][0], $samples[$i][0]);
            } elseif ($before >= 0.0 && $after < 0.0 && $set === null) {
                $set = self::bisect($altitudeAt, $targetDeg, $samples[$i - 1][0], $samples[$i][0]);
            }

            if ($rise !== null && $set !== null) {
                break;
            }
        }

        return ['rise' => $rise, 'set' => $set];
    }

    /**
     * Bisect a bracketed crossing. The bracket is guaranteed by the caller
     * (f($lo) and f($hi) lie on opposite sides of the target).
     *
     * @param callable(float): float $altitudeAt
     */
    private static function bisect(callable $altitudeAt, float $targetDeg, float $lo, float $hi): float
    {
        $fLo = $altitudeAt($lo) - $targetDeg;

        // 40 halvings of a 10 minute bracket is ~5e-10 s; the loop exits on PRECISION_DAYS long before.
        for ($i = 0; $i < 40 && ($hi - $lo) > self::PRECISION_DAYS; $i++) {
            $mid = ($lo + $hi) / 2.0;
            $fMid = $altitudeAt($mid) - $targetDeg;

            if (($fLo < 0.0) === ($fMid < 0.0)) {
                $lo = $mid;
                $fLo = $fMid;
            } else {
                $hi = $mid;
            }
        }

        return ($lo + $hi) / 2.0;
    }
}
