<?php

declare(strict_types=1);

namespace Sky;

/**
 * Shared astronomical helpers: angle handling, time scales, nutation, obliquity,
 * sidereal time, refraction and the equatorial -> horizontal transformation.
 *
 * CONVENTIONS USED THROUGHOUT THIS PROJECT (Meeus, Astronomical Algorithms, 2nd ed.):
 *   - Azimuth is measured FROM NORTH TOWARDS EAST, 0..360 deg (N=0, E=90, S=180, W=270).
 *     NOTE: Meeus himself measures azimuth from the south; we convert (see equatorialToHorizontal).
 *   - Altitude is measured from the mathematical horizon, in degrees. "Apparent" altitude
 *     includes atmospheric refraction, "geometric" does not.
 *   - Longitude is EAST-POSITIVE. (Meeus uses west-positive; we convert at the single place
 *     where it matters, in equatorialToHorizontal.)
 *   - Every angle on a public API is in DEGREES; radians are used only inside a method.
 *   - Every instant is handled in UTC internally. Ephemeris series are evaluated in TD
 *     (Dynamical Time), obtained as UT + deltaT.
 */
final class AstroMath
{
    /** Radians per degree. */
    public const DEG = M_PI / 180.0;

    /** JD of the Unix epoch, 1970-01-01T00:00:00Z. */
    public const JD_UNIX_EPOCH = 2440587.5;

    /** JD of J2000.0 (2000-01-01T12:00:00 TT). */
    public const JD_J2000 = 2451545.0;

    /** Equatorial radius of the Earth, km (IAU 1976). */
    public const EARTH_RADIUS_KM = 6378.14;

    /** Astronomical unit, km (IAU 2012). */
    public const AU_KM = 149597870.7;

    private function __construct()
    {
    }

    public static function rad(float $deg): float
    {
        return $deg * self::DEG;
    }

    public static function deg(float $rad): float
    {
        return $rad / self::DEG;
    }

    public static function sinDeg(float $deg): float
    {
        return sin($deg * self::DEG);
    }

    public static function cosDeg(float $deg): float
    {
        return cos($deg * self::DEG);
    }

    public static function tanDeg(float $deg): float
    {
        return tan($deg * self::DEG);
    }

    /** Normalise an angle into [0, 360). */
    public static function norm360(float $deg): float
    {
        $d = fmod($deg, 360.0);

        return $d < 0.0 ? $d + 360.0 : $d;
    }

    /** Normalise an angle into (-180, 180]. */
    public static function norm180(float $deg): float
    {
        $d = self::norm360($deg);

        return $d > 180.0 ? $d - 360.0 : $d;
    }

    /**
     * Julian Day of an instant. The instant is absolute, so the time zone carried by
     * $when is irrelevant here - only the underlying Unix timestamp is used.
     */
    public static function julianDay(\DateTimeInterface $when): float
    {
        $seconds = (float) $when->format('U') + ((float) $when->format('u')) / 1.0e6;

        return self::JD_UNIX_EPOCH + $seconds / 86400.0;
    }

    /** Convert a Julian Day (UT) back to a UTC instant. */
    public static function jdToUtc(float $jd): \DateTimeImmutable
    {
        $seconds = ($jd - self::JD_UNIX_EPOCH) * 86400.0;
        $whole = (int) floor($seconds);
        $micro = (int) round(($seconds - $whole) * 1.0e6);
        if ($micro >= 1000000) {
            $whole += 1;
            $micro -= 1000000;
        }

        $base = (new \DateTimeImmutable('@' . $whole))->setTimezone(new \DateTimeZone('UTC'));

        return $micro > 0
            ? \DateTimeImmutable::createFromFormat(
                'U.u',
                sprintf('%d.%06d', $whole, $micro),
                new \DateTimeZone('UTC')
            )
            : $base;
    }

    /** Julian centuries from J2000.0 for a given Julian Day. */
    public static function centuries(float $jd): float
    {
        return ($jd - self::JD_J2000) / 36525.0;
    }

    /**
     * deltaT = TD - UT, in seconds. Polynomial expressions by Espenak & Meeus
     * (NASA Five Millennium Canon), restricted to the branches this project can reach.
     *
     * At the accuracy we need (Sun +-1 deg, Moon +-2 deg) deltaT is almost irrelevant
     * (~70 s moves the Moon by ~0.01 deg), but it is cheap and removes a systematic bias.
     */
    public static function deltaTSeconds(float $decimalYear): float
    {
        $y = $decimalYear;

        if ($y >= 2005.0 && $y < 2050.0) {
            $t = $y - 2000.0;

            return 62.92 + 0.32217 * $t + 0.005589 * $t * $t;
        }

        if ($y >= 1986.0 && $y < 2005.0) {
            $t = $y - 2000.0;

            return 63.86 + 0.3345 * $t - 0.060374 * $t ** 2 + 0.0017275 * $t ** 3
                + 0.000651814 * $t ** 4 + 0.00002373599 * $t ** 5;
        }

        if ($y >= 1961.0 && $y < 1986.0) {
            $t = $y - 1975.0;

            return 45.45 + 1.067 * $t - $t ** 2 / 260.0 - $t ** 3 / 718.0;
        }

        if ($y >= 1941.0 && $y < 1961.0) {
            $t = $y - 1950.0;

            return 29.07 + 0.407 * $t - $t ** 2 / 233.0 + $t ** 3 / 2547.0;
        }

        if ($y >= 1920.0 && $y < 1941.0) {
            $t = $y - 1920.0;

            return 21.20 + 0.84493 * $t - 0.076100 * $t ** 2 + 0.0020936 * $t ** 3;
        }

        if ($y >= 1900.0 && $y < 1920.0) {
            $t = $y - 1900.0;

            return -2.79 + 1.494119 * $t - 0.0598939 * $t ** 2 + 0.0061966 * $t ** 3
                - 0.000197 * $t ** 4;
        }

        if ($y >= 2050.0 && $y < 2150.0) {
            return -20.0 + 32.0 * (($y - 1820.0) / 100.0) ** 2 - 0.5628 * (2150.0 - $y);
        }

        // Far outside our validity window - the generic long-term parabola.
        return -20.0 + 32.0 * (($y - 1820.0) / 100.0) ** 2;
    }

    /** deltaT in seconds for an instant. */
    public static function deltaTFor(\DateTimeInterface $when): float
    {
        $utc = (new \DateTimeImmutable('@' . $when->format('U')))->setTimezone(new \DateTimeZone('UTC'));
        $decimalYear = (float) $utc->format('Y') + ((float) $utc->format('n') - 0.5) / 12.0;

        return self::deltaTSeconds($decimalYear);
    }

    /** JDE (Dynamical Time) for a Julian Day expressed in UT. */
    public static function jdToJde(float $jdUt): float
    {
        $utc = self::jdToUtc($jdUt);

        return $jdUt + self::deltaTFor($utc) / 86400.0;
    }

    /**
     * Nutation in longitude and obliquity, degrees. Meeus ch. 22, abridged series
     * (accuracy ~0.5", far below anything visible in this project).
     *
     * @return array{0: float, 1: float} [deltaPsi, deltaEpsilon] in degrees
     */
    public static function nutation(float $t): array
    {
        $omega = 125.04452 - 1934.136261 * $t + 0.0020708 * $t * $t + $t ** 3 / 450000.0;
        $lSun = 280.4665 + 36000.7698 * $t;
        $lMoon = 218.3165 + 481267.8813 * $t;

        // Arc seconds.
        $deltaPsi = -17.20 * self::sinDeg($omega)
            - 1.32 * self::sinDeg(2.0 * $lSun)
            - 0.23 * self::sinDeg(2.0 * $lMoon)
            + 0.21 * self::sinDeg(2.0 * $omega);

        $deltaEps = 9.20 * self::cosDeg($omega)
            + 0.57 * self::cosDeg(2.0 * $lSun)
            + 0.10 * self::cosDeg(2.0 * $lMoon)
            - 0.09 * self::cosDeg(2.0 * $omega);

        return [$deltaPsi / 3600.0, $deltaEps / 3600.0];
    }

    /** Mean obliquity of the ecliptic, degrees. Meeus (22.2). */
    public static function meanObliquity(float $t): float
    {
        return 23.439291111111111
            - 0.013004166666667 * $t
            - 1.638888888889e-7 * $t * $t
            + 5.036111111111e-7 * $t ** 3;
    }

    /** True obliquity = mean obliquity + nutation in obliquity, degrees. */
    public static function trueObliquity(float $t): float
    {
        [, $deltaEps] = self::nutation($t);

        return self::meanObliquity($t) + $deltaEps;
    }

    /**
     * Apparent sidereal time at Greenwich, degrees. Meeus (12.4) + the nutation term.
     *
     * IMPORTANT: sidereal time is a function of UT, not of TD - pass the UT-based JD.
     */
    public static function apparentSiderealTimeDeg(float $jdUt): float
    {
        $t = self::centuries($jdUt);
        $theta0 = 280.46061837
            + 360.98564736629 * ($jdUt - self::JD_J2000)
            + 0.000387933 * $t * $t
            - $t ** 3 / 38710000.0;

        [$deltaPsi] = self::nutation($t);
        $eps = self::trueObliquity($t);

        return self::norm360($theta0 + $deltaPsi * self::cosDeg($eps));
    }

    /**
     * Atmospheric refraction to be ADDED to a geometric altitude, in degrees.
     * Bennett's formula, Meeus (16.4), for standard conditions (1010 mbar, 10 C).
     *
     * Below -1 deg the body is far enough under the horizon that refraction is neither
     * meaningful nor needed for rendering, so we return 0 there.
     */
    public static function refractionDeg(float $geometricAltitudeDeg): float
    {
        if ($geometricAltitudeDeg < -1.0) {
            return 0.0;
        }

        $h = $geometricAltitudeDeg;
        $arcminutes = 1.02 / self::tanDeg($h + 10.3 / ($h + 5.11)) + 0.0019279;

        return $arcminutes / 60.0;
    }

    /** Apparent (refracted) altitude from a geometric altitude, degrees. */
    public static function applyRefraction(float $geometricAltitudeDeg): float
    {
        return $geometricAltitudeDeg + self::refractionDeg($geometricAltitudeDeg);
    }

    /**
     * Equatorial -> horizontal. Meeus (13.5)/(13.6), converted to the north-based
     * azimuth convention of this project.
     *
     * @param float $raDeg  right ascension, degrees
     * @param float $decDeg declination, degrees
     * @param float $latDeg geographic latitude, degrees (north positive)
     * @param float $lonDeg geographic longitude, degrees (EAST positive)
     * @param float $lstDeg apparent sidereal time at Greenwich, degrees
     *
     * @return array{altitude: float, azimuth: float, hourAngle: float}
     *         altitude is GEOMETRIC (no refraction); hourAngle is in [0,360), positive west
     */
    public static function equatorialToHorizontal(
        float $raDeg,
        float $decDeg,
        float $latDeg,
        float $lonDeg,
        float $lstDeg
    ): array {
        $hourAngle = self::norm360($lstDeg + $lonDeg - $raDeg);

        $h = self::rad($hourAngle);
        $dec = self::rad($decDeg);
        $lat = self::rad($latDeg);

        $altitude = asin(sin($lat) * sin($dec) + cos($lat) * cos($dec) * cos($h));

        // Meeus gives the azimuth from the SOUTH, westwards; +180 makes it north-based.
        $azimuthFromSouth = atan2(sin($h), cos($h) * sin($lat) - tan($dec) * cos($lat));

        return [
            'altitude' => self::deg($altitude),
            'azimuth' => self::norm360(self::deg($azimuthFromSouth) + 180.0),
            'hourAngle' => $hourAngle,
        ];
    }

    /**
     * Parallactic angle, degrees: the position angle of the ZENITH as seen from the body,
     * measured from the celestial north pole towards east. Meeus (14.1).
     *
     * This is what turns an equatorial position angle into a "screen" angle: an equatorial
     * position angle chi becomes chi - q relative to the local vertical.
     */
    public static function parallacticAngleDeg(float $hourAngleDeg, float $decDeg, float $latDeg): float
    {
        $h = self::rad($hourAngleDeg);
        $dec = self::rad($decDeg);
        $lat = self::rad($latDeg);

        return self::deg(atan2(sin($h), tan($lat) * cos($dec) - sin($dec) * cos($h)));
    }

    /**
     * Position angle of one body as seen from another, IN THE HORIZONTAL FRAME: measured
     * from the direction of the ZENITH towards INCREASING AZIMUTH, 0..360 deg.
     *
     * This is ordinary spherical bearing arithmetic with the zenith as the pole (altitude
     * plays the role of latitude, azimuth the role of longitude). It is the angle a
     * renderer needs, because a renderer that maps azimuth to x and altitude to y sees
     * exactly this rotation: 0 = towards the zenith ("up"), 90 = towards increasing
     * azimuth, 180 = towards the horizon ("down"), 270 = towards decreasing azimuth.
     */
    public static function bearingFromZenithDeg(
        float $fromAltitudeDeg,
        float $fromAzimuthDeg,
        float $toAltitudeDeg,
        float $toAzimuthDeg
    ): float {
        $deltaAzimuth = $toAzimuthDeg - $fromAzimuthDeg;

        $y = self::sinDeg($deltaAzimuth) * self::cosDeg($toAltitudeDeg);
        $x = self::cosDeg($fromAltitudeDeg) * self::sinDeg($toAltitudeDeg)
            - self::sinDeg($fromAltitudeDeg) * self::cosDeg($toAltitudeDeg) * self::cosDeg($deltaAzimuth);

        return self::norm360(self::deg(atan2($y, $x)));
    }

    /**
     * Ecliptic -> equatorial. Meeus (13.3)/(13.4).
     *
     * @return array{0: float, 1: float} [rightAscension, declination] in degrees
     */
    public static function eclipticToEquatorial(float $lambdaDeg, float $betaDeg, float $epsilonDeg): array
    {
        $lambda = self::rad($lambdaDeg);
        $beta = self::rad($betaDeg);
        $eps = self::rad($epsilonDeg);

        $ra = atan2(
            sin($lambda) * cos($eps) - tan($beta) * sin($eps),
            cos($lambda)
        );
        $dec = asin(sin($beta) * cos($eps) + cos($beta) * sin($eps) * sin($lambda));

        return [self::norm360(self::deg($ra)), self::deg($dec)];
    }
}
