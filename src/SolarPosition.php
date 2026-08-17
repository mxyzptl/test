<?php

declare(strict_types=1);

namespace Sky;

/**
 * Position of the Sun. Meeus, Astronomical Algorithms ch. 25 ("lower accuracy"),
 * which is good to about 0.01 deg in longitude - two orders of magnitude better than
 * the +-1 deg this project requires.
 *
 * Conventions: azimuth from NORTH towards EAST (0..360), altitude from the mathematical
 * horizon. altitudeDeg is APPARENT (refraction included), geometricAltitudeDeg is not.
 */
final class SolarPosition
{
    private function __construct(
        public readonly float $rightAscensionDeg,
        public readonly float $declinationDeg,
        public readonly float $apparentLongitudeDeg,
        public readonly float $distanceKm,
        public readonly float $geometricAltitudeDeg,
        public readonly float $altitudeDeg,
        public readonly float $azimuthDeg,
        public readonly float $hourAngleDeg,
    ) {
    }

    /** Position for an instant, as seen from $location. */
    public static function at(\DateTimeInterface $when, Location $location): self
    {
        return self::fromJulianDay(AstroMath::julianDay($when), $location);
    }

    /**
     * Position for a Julian Day expressed in UT (the ephemeris series are internally
     * evaluated in TD = UT + deltaT; sidereal time stays on UT).
     */
    public static function fromJulianDay(float $jdUt, Location $location): self
    {
        $jde = AstroMath::jdToJde($jdUt);
        $geo = self::geocentric($jde);

        $lst = AstroMath::apparentSiderealTimeDeg($jdUt);
        $horizontal = AstroMath::equatorialToHorizontal(
            $geo['right_ascension'],
            $geo['declination'],
            $location->latitudeDeg,
            $location->longitudeDeg,
            $lst
        );

        return new self(
            $geo['right_ascension'],
            $geo['declination'],
            $geo['apparent_longitude'],
            $geo['distance_km'],
            $horizontal['altitude'],
            AstroMath::applyRefraction($horizontal['altitude']),
            $horizontal['azimuth'],
            $horizontal['hourAngle'],
        );
    }

    /**
     * Apparent geocentric equatorial coordinates of the Sun. Meeus ch. 25.
     *
     * The Sun's horizontal parallax is 8.8", i.e. 0.0024 deg, so no topocentric
     * correction is applied - it is two orders of magnitude below our tolerance.
     *
     * @param float $jde Julian Day in Dynamical Time
     *
     * @return array{
     *     right_ascension: float, declination: float, apparent_longitude: float,
     *     true_longitude: float, distance_km: float, distance_au: float, mean_anomaly: float
     * }
     */
    public static function geocentric(float $jde): array
    {
        $t = AstroMath::centuries($jde);

        // Geometric mean longitude and mean anomaly, degrees. (25.2), (25.3)
        $l0 = 280.46646 + 36000.76983 * $t + 0.0003032 * $t * $t;
        $m = 357.52911 + 35999.05029 * $t - 0.0001537 * $t * $t;

        // Eccentricity of the Earth's orbit. (25.4)
        $e = 0.016708634 - 0.000042037 * $t - 0.0000001267 * $t * $t;

        // Equation of the centre.
        $c = (1.914602 - 0.004817 * $t - 0.000014 * $t * $t) * AstroMath::sinDeg($m)
            + (0.019993 - 0.000101 * $t) * AstroMath::sinDeg(2.0 * $m)
            + 0.000289 * AstroMath::sinDeg(3.0 * $m);

        $trueLongitude = $l0 + $c;
        $trueAnomaly = $m + $c;

        // Radius vector in AU. (25.5)
        $r = 1.000001018 * (1.0 - $e * $e) / (1.0 + $e * AstroMath::cosDeg($trueAnomaly));

        // Apparent longitude: nutation + aberration, Meeus p. 164.
        $omega = 125.04 - 1934.136 * $t;
        $apparentLongitude = $trueLongitude - 0.00569 - 0.00478 * AstroMath::sinDeg($omega);

        // Obliquity corrected for the same reason (Meeus p. 165), so that the resulting
        // right ascension/declination are APPARENT coordinates.
        $epsilon = AstroMath::meanObliquity($t) + 0.00256 * AstroMath::cosDeg($omega);

        // The Sun's ecliptic latitude is below 1.2" - treated as zero here, as Meeus does.
        [$ra, $dec] = AstroMath::eclipticToEquatorial($apparentLongitude, 0.0, $epsilon);

        return [
            'right_ascension' => $ra,
            'declination' => $dec,
            'apparent_longitude' => AstroMath::norm360($apparentLongitude),
            'true_longitude' => AstroMath::norm360($trueLongitude),
            'distance_au' => $r,
            'distance_km' => $r * AstroMath::AU_KM,
            'mean_anomaly' => AstroMath::norm360($m),
        ];
    }

    /**
     * Geometric (unrefracted) altitude of the Sun for a JD in UT - the function the
     * rise/set root finder scans.
     */
    public static function geometricAltitudeAt(float $jdUt, Location $location): float
    {
        $geo = self::geocentric(AstroMath::jdToJde($jdUt));

        return AstroMath::equatorialToHorizontal(
            $geo['right_ascension'],
            $geo['declination'],
            $location->latitudeDeg,
            $location->longitudeDeg,
            AstroMath::apparentSiderealTimeDeg($jdUt)
        )['altitude'];
    }

    /**
     * Standard altitude of the Sun's centre at rise/set: -0.8333 deg (34' refraction
     * + 16' semidiameter), lowered further by the dip of the horizon at the observer's
     * elevation. Compared against the GEOMETRIC altitude.
     */
    public static function riseSetTargetDeg(Location $location): float
    {
        return -0.8333 - $location->horizonDipDeg();
    }

    /**
     * Rise/set and the three twilight boundaries for the given UT window.
     *
     * @return array<string, array{rise: ?float, set: ?float}> keyed by event name,
     *         values are Julian Days in UT (null when the event does not happen)
     */
    public static function events(float $startJdUt, float $endJdUt, Location $location): array
    {
        $altitude = static fn (float $jd): float => self::geometricAltitudeAt($jd, $location);

        $samples = RiseSetFinder::sample($altitude, $startJdUt, $endJdUt);

        return [
            'sun' => RiseSetFinder::crossings($samples, $altitude, self::riseSetTargetDeg($location)),
            'civil' => RiseSetFinder::crossings($samples, $altitude, -6.0),
            'nautical' => RiseSetFinder::crossings($samples, $altitude, -12.0),
            'astronomical' => RiseSetFinder::crossings($samples, $altitude, -18.0),
        ];
    }
}
