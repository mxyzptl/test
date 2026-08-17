<?php

declare(strict_types=1);

namespace Sky;

/**
 * Position and phase of the Moon. Meeus, Astronomical Algorithms ch. 47 (the abridged
 * ELP-2000/82 series: the full 60-term tables 47.A and 47.B), plus:
 *   - nutation (ch. 22, abridged),
 *   - topocentric parallax (ch. 40) - the Moon is the one body where this cannot be
 *     skipped: the geocentric position is off by up to ~1 deg, half of our whole budget,
 *   - illuminated fraction and position angle of the bright limb (ch. 48).
 *
 * Claimed accuracy: ~10" in longitude, ~4" in latitude - far inside the +-2 deg required.
 *
 * Conventions: azimuth from NORTH towards EAST (0..360); altitudeDeg is the APPARENT
 * TOPOCENTRIC altitude (parallax + refraction), geometricAltitudeDeg omits refraction only.
 */
final class LunarPosition
{
    /**
     * Table 47.A - periodic terms for the Moon's longitude (sum l, unit 1e-6 deg) and
     * distance (sum r, unit 1e-3 km). Columns: D, M, M', F, sum_l, sum_r.
     */
    private const TERMS_LONGITUDE_DISTANCE = [
        [0, 0, 1, 0, 6288774, -20905355],
        [2, 0, -1, 0, 1274027, -3699111],
        [2, 0, 0, 0, 658314, -2955968],
        [0, 0, 2, 0, 213618, -569925],
        [0, 1, 0, 0, -185116, 48888],
        [0, 0, 0, 2, -114332, -3149],
        [2, 0, -2, 0, 58793, 246158],
        [2, -1, -1, 0, 57066, -152138],
        [2, 0, 1, 0, 53322, -170733],
        [2, -1, 0, 0, 45758, -204586],
        [0, 1, -1, 0, -40923, -129620],
        [1, 0, 0, 0, -34720, 108743],
        [0, 1, 1, 0, -30383, 104755],
        [2, 0, 0, -2, 15327, 10321],
        [0, 0, 1, 2, -12528, 0],
        [0, 0, 1, -2, 10980, 79661],
        [4, 0, -1, 0, 10675, -34782],
        [0, 0, 3, 0, 10034, -23210],
        [4, 0, -2, 0, 8548, -21636],
        [2, 1, -1, 0, -7888, 24208],
        [2, 1, 0, 0, -6766, 30824],
        [1, 0, -1, 0, -5163, -8379],
        [1, 1, 0, 0, 4987, -16675],
        [2, -1, 1, 0, 4036, -12831],
        [2, 0, 2, 0, 3994, -10445],
        [4, 0, 0, 0, 3861, -11650],
        [2, 0, -3, 0, 3665, 14403],
        [0, 1, -2, 0, -2689, -7003],
        [2, 0, -1, 2, -2602, 0],
        [2, -1, -2, 0, 2390, 10056],
        [1, 0, 1, 0, -2348, 6322],
        [2, -2, 0, 0, 2236, -9884],
        [0, 1, 2, 0, -2120, 5751],
        [0, 2, 0, 0, -2069, 0],
        [2, -2, -1, 0, 2048, -4950],
        [2, 0, 1, -2, -1773, 4130],
        [2, 0, 0, 2, -1595, 0],
        [4, -1, -1, 0, 1215, -3958],
        [0, 0, 2, 2, -1110, 0],
        [3, 0, -1, 0, -892, 3258],
        [2, 1, 1, 0, -810, 2616],
        [4, -1, -2, 0, 759, -1897],
        [0, 2, -1, 0, -713, -2117],
        [2, 2, -1, 0, -700, 2354],
        [2, 1, -2, 0, 691, 0],
        [2, -1, 0, -2, 596, 0],
        [4, 0, 1, 0, 549, -1423],
        [0, 0, 4, 0, 537, -1117],
        [4, -1, 0, 0, 520, -1571],
        [1, 0, -2, 0, -487, -1739],
        [2, 1, 0, -2, -399, 0],
        [0, 0, 2, -2, -381, -4421],
        [1, 1, 1, 0, 351, 0],
        [3, 0, -2, 0, -340, 0],
        [4, 0, -3, 0, 330, 0],
        [2, -1, 2, 0, 327, 0],
        [0, 2, 1, 0, -323, 1165],
        [1, 1, -1, 0, 299, 0],
        [2, 0, 3, 0, 294, 0],
        [2, 0, -1, -2, 0, 8752],
    ];

    /**
     * Table 47.B - periodic terms for the Moon's latitude (sum b, unit 1e-6 deg).
     * Columns: D, M, M', F, sum_b.
     */
    private const TERMS_LATITUDE = [
        [0, 0, 0, 1, 5128122],
        [0, 0, 1, 1, 280602],
        [0, 0, 1, -1, 277693],
        [2, 0, 0, -1, 173237],
        [2, 0, -1, 1, 55413],
        [2, 0, -1, -1, 46271],
        [2, 0, 0, 1, 32573],
        [0, 0, 2, 1, 17198],
        [2, 0, 1, -1, 9266],
        [0, 0, 2, -1, 8822],
        [2, -1, 0, -1, 8216],
        [2, 0, -2, -1, 4324],
        [2, 0, 1, 1, 4200],
        [2, 1, 0, -1, -3359],
        [2, -1, -1, 1, 2463],
        [2, -1, 0, 1, 2211],
        [2, -1, -1, -1, 2065],
        [0, 1, -1, -1, -1870],
        [4, 0, -1, -1, 1828],
        [0, 1, 0, 1, -1794],
        [0, 0, 0, 3, -1749],
        [0, 1, -1, 1, -1565],
        [1, 0, 0, 1, -1491],
        [0, 1, 1, 1, -1475],
        [0, 1, 1, -1, -1410],
        [0, 1, 0, -1, -1344],
        [1, 0, 0, -1, -1335],
        [0, 0, 3, 1, 1107],
        [4, 0, 0, -1, 1021],
        [4, 0, -1, 1, 833],
        [0, 0, 1, -3, 777],
        [4, 0, -2, 1, 671],
        [2, 0, 0, -3, 607],
        [2, 0, 2, -1, 596],
        [2, -1, 1, -1, 491],
        [2, 0, -2, 1, -451],
        [0, 0, 3, -1, 439],
        [2, 0, 2, 1, 422],
        [2, 0, -3, -1, 421],
        [2, 1, -1, 1, -366],
        [2, 1, 0, 1, -351],
        [4, 0, 0, 1, 331],
        [2, -1, 1, 1, 315],
        [2, -2, 0, -1, 302],
        [0, 0, 1, 3, -283],
        [2, 1, 1, -1, -229],
        [1, 1, 0, -1, 223],
        [1, 1, 0, 1, 223],
        [0, 1, -2, -1, -220],
        [2, 1, -1, -1, -220],
        [1, 0, 1, 1, -185],
        [2, -1, -2, -1, 181],
        [0, 1, 2, 1, -177],
        [4, 0, -2, -1, 176],
        [4, -1, -1, -1, 166],
        [1, 0, 1, -1, -164],
        [4, 0, 1, -1, 132],
        [1, 0, -1, -1, -119],
        [4, -1, 0, -1, 115],
        [2, -2, 0, 1, 107],
    ];

    /**
     * @param float $brightLimbPositionAngleDeg classic celestial position angle of the midpoint of
     *                                          the bright limb: from the celestial north pole
     *                                          towards east (Meeus 48.5). Reference value only.
     * @param float $brightLimbAngleDeg         the SAME direction expressed in the horizontal
     *                                          frame: from the zenith ("up") towards increasing
     *                                          azimuth. THIS is what the renderer rotates by.
     */
    private function __construct(
        public readonly float $rightAscensionDeg,
        public readonly float $declinationDeg,
        public readonly float $apparentLongitudeDeg,
        public readonly float $latitudeDeg,
        public readonly float $geocentricDistanceKm,
        public readonly float $distanceKm,
        public readonly float $parallaxDeg,
        public readonly float $topocentricRightAscensionDeg,
        public readonly float $topocentricDeclinationDeg,
        public readonly float $geometricAltitudeDeg,
        public readonly float $altitudeDeg,
        public readonly float $azimuthDeg,
        public readonly float $hourAngleDeg,
        public readonly float $illumination,
        public readonly float $phaseAngleDeg,
        public readonly float $elongationDeg,
        public readonly bool $waxing,
        public readonly float $brightLimbPositionAngleDeg,
        public readonly float $brightLimbAngleDeg,
        public readonly float $parallacticAngleDeg,
    ) {
    }

    public static function at(\DateTimeInterface $when, Location $location): self
    {
        return self::fromJulianDay(AstroMath::julianDay($when), $location);
    }

    public static function fromJulianDay(float $jdUt, Location $location): self
    {
        $jde = AstroMath::jdToJde($jdUt);

        $geo = self::geocentric($jde);
        $sun = SolarPosition::geocentric($jde);

        $lstGreenwich = AstroMath::apparentSiderealTimeDeg($jdUt);
        $topo = self::topocentric($geo, $location, $lstGreenwich);

        $horizontal = AstroMath::equatorialToHorizontal(
            $topo['right_ascension'],
            $topo['declination'],
            $location->latitudeDeg,
            $location->longitudeDeg,
            $lstGreenwich
        );

        // Illuminated fraction: computed from the topocentric Moon so that it stays
        // consistent with the limb angle we hand to the renderer. (The topocentric vs.
        // geocentric difference in k is below 0.001.)
        $phase = self::illumination(
            $sun['right_ascension'],
            $sun['declination'],
            $sun['distance_km'],
            $topo['right_ascension'],
            $topo['declination'],
            $topo['distance_km']
        );

        $chi = self::brightLimbPositionAngleDeg(
            $sun['right_ascension'],
            $sun['declination'],
            $topo['right_ascension'],
            $topo['declination']
        );

        $q = AstroMath::parallacticAngleDeg(
            $horizontal['hourAngle'],
            $topo['declination'],
            $location->latitudeDeg
        );

        // The angle the renderer actually needs: the direction of the Sun as seen from the
        // Moon, expressed in the very frame the renderer draws in (zenith = up, increasing
        // azimuth = the direction in which the panorama runs). Computing it straight in the
        // horizontal frame leaves no room for a sign slip.
        //
        // It is equal to norm360(q - chi) - the classic equatorial route through Meeus
        // (48.5) plus the parallactic angle, mirrored because equatorial position angles run
        // "north through east" while azimuth runs the other way round on the sky. The test
        // suite asserts that the two agree, so a mistake in either derivation is caught.
        $sunHorizontal = AstroMath::equatorialToHorizontal(
            $sun['right_ascension'],
            $sun['declination'],
            $location->latitudeDeg,
            $location->longitudeDeg,
            $lstGreenwich
        );
        $brightLimbAngle = AstroMath::bearingFromZenithDeg(
            $horizontal['altitude'],
            $horizontal['azimuth'],
            $sunHorizontal['altitude'],
            $sunHorizontal['azimuth']
        );

        // Waxing = the Moon is ahead of the Sun in ecliptic longitude by less than 180 deg.
        $elongationInLongitude = AstroMath::norm360($geo['apparent_longitude'] - $sun['apparent_longitude']);

        return new self(
            $geo['right_ascension'],
            $geo['declination'],
            $geo['apparent_longitude'],
            $geo['latitude'],
            $geo['distance_km'],
            $topo['distance_km'],
            $geo['parallax'],
            $topo['right_ascension'],
            $topo['declination'],
            $horizontal['altitude'],
            AstroMath::applyRefraction($horizontal['altitude']),
            $horizontal['azimuth'],
            $horizontal['hourAngle'],
            $phase['illumination'],
            $phase['phase_angle'],
            $phase['elongation'],
            $elongationInLongitude > 0.0 && $elongationInLongitude < 180.0,
            $chi,
            $brightLimbAngle,
            $q,
        );
    }

    /**
     * Apparent geocentric ecliptic and equatorial coordinates of the Moon. Meeus ch. 47.
     *
     * @param float $jde Julian Day in Dynamical Time
     *
     * @return array{
     *     mean_longitude: float, apparent_longitude: float, latitude: float,
     *     distance_km: float, parallax: float,
     *     right_ascension: float, declination: float, obliquity: float
     * }
     */
    public static function geocentric(float $jde): array
    {
        $t = AstroMath::centuries($jde);

        // Fundamental arguments, Meeus (47.1)-(47.6), degrees.
        $lPrime = 218.3164477 + 481267.88123421 * $t - 0.0015786 * $t ** 2
            + $t ** 3 / 538841.0 - $t ** 4 / 65194000.0;
        $d = 297.8501921 + 445267.1114034 * $t - 0.0018819 * $t ** 2
            + $t ** 3 / 545868.0 - $t ** 4 / 113065000.0;
        $m = 357.5291092 + 35999.0502909 * $t - 0.0001536 * $t ** 2
            + $t ** 3 / 24490000.0;
        $mPrime = 134.9633964 + 477198.8675055 * $t + 0.0087414 * $t ** 2
            + $t ** 3 / 69699.0 - $t ** 4 / 14712000.0;
        $f = 93.2720950 + 483202.0175233 * $t - 0.0036539 * $t ** 2
            - $t ** 3 / 3526000.0 + $t ** 4 / 863310000.0;

        // Further arguments for the additive terms (Venus, Jupiter, flattening of the Earth).
        $a1 = 119.75 + 131.849 * $t;
        $a2 = 53.09 + 479264.290 * $t;
        $a3 = 313.45 + 481266.484 * $t;

        // Eccentricity correction of the Earth's orbit; applied to terms containing M.
        $e = 1.0 - 0.002516 * $t - 0.0000074 * $t * $t;

        $sumL = 0.0;
        $sumR = 0.0;
        foreach (self::TERMS_LONGITUDE_DISTANCE as [$cd, $cm, $cmp, $cf, $coefL, $coefR]) {
            $argument = $cd * $d + $cm * $m + $cmp * $mPrime + $cf * $f;
            $factor = self::eccentricityFactor($cm, $e);

            $sumL += $coefL * $factor * AstroMath::sinDeg($argument);
            $sumR += $coefR * $factor * AstroMath::cosDeg($argument);
        }

        $sumB = 0.0;
        foreach (self::TERMS_LATITUDE as [$cd, $cm, $cmp, $cf, $coefB]) {
            $argument = $cd * $d + $cm * $m + $cmp * $mPrime + $cf * $f;

            $sumB += $coefB * self::eccentricityFactor($cm, $e) * AstroMath::sinDeg($argument);
        }

        // Additive terms, Meeus p. 342.
        $sumL += 3958.0 * AstroMath::sinDeg($a1)
            + 1962.0 * AstroMath::sinDeg($lPrime - $f)
            + 318.0 * AstroMath::sinDeg($a2);

        $sumB += -2235.0 * AstroMath::sinDeg($lPrime)
            + 382.0 * AstroMath::sinDeg($a3)
            + 175.0 * AstroMath::sinDeg($a1 - $f)
            + 175.0 * AstroMath::sinDeg($a1 + $f)
            + 127.0 * AstroMath::sinDeg($lPrime - $mPrime)
            - 115.0 * AstroMath::sinDeg($lPrime + $mPrime);

        $lambda = AstroMath::norm360($lPrime + $sumL / 1.0e6);
        $beta = $sumB / 1.0e6;
        $distanceKm = 385000.56 + $sumR / 1000.0;

        // Equatorial horizontal parallax, Meeus p. 337.
        $parallax = AstroMath::deg(asin(AstroMath::EARTH_RADIUS_KM / $distanceKm));

        // Apparent longitude: add the nutation in longitude.
        [$deltaPsi] = AstroMath::nutation($t);
        $apparentLongitude = AstroMath::norm360($lambda + $deltaPsi);
        $epsilon = AstroMath::trueObliquity($t);

        [$ra, $dec] = AstroMath::eclipticToEquatorial($apparentLongitude, $beta, $epsilon);

        return [
            'mean_longitude' => AstroMath::norm360($lambda),
            'apparent_longitude' => $apparentLongitude,
            'latitude' => $beta,
            'distance_km' => $distanceKm,
            'parallax' => $parallax,
            'right_ascension' => $ra,
            'declination' => $dec,
            'obliquity' => $epsilon,
        ];
    }

    /**
     * Geocentric -> topocentric, via rectangular equatorial coordinates. This is the exact
     * form of Meeus (40.2)/(40.3): the observer's position vector is subtracted from the
     * Moon's, which additionally yields the topocentric distance for free.
     *
     * @param array{right_ascension: float, declination: float, distance_km: float} $geo
     *
     * @return array{right_ascension: float, declination: float, distance_km: float}
     */
    public static function topocentric(array $geo, Location $location, float $lstGreenwichDeg): array
    {
        $localSidereal = AstroMath::norm360($lstGreenwichDeg + $location->longitudeDeg);

        $moonX = $geo['distance_km'] * AstroMath::cosDeg($geo['declination']) * AstroMath::cosDeg($geo['right_ascension']);
        $moonY = $geo['distance_km'] * AstroMath::cosDeg($geo['declination']) * AstroMath::sinDeg($geo['right_ascension']);
        $moonZ = $geo['distance_km'] * AstroMath::sinDeg($geo['declination']);

        // The observer sits at right ascension = local sidereal time, declination = phi'.
        $observerX = AstroMath::EARTH_RADIUS_KM * $location->rhoCosPhiPrime() * AstroMath::cosDeg($localSidereal);
        $observerY = AstroMath::EARTH_RADIUS_KM * $location->rhoCosPhiPrime() * AstroMath::sinDeg($localSidereal);
        $observerZ = AstroMath::EARTH_RADIUS_KM * $location->rhoSinPhiPrime();

        $x = $moonX - $observerX;
        $y = $moonY - $observerY;
        $z = $moonZ - $observerZ;

        $distance = sqrt($x * $x + $y * $y + $z * $z);

        return [
            'right_ascension' => AstroMath::norm360(AstroMath::deg(atan2($y, $x))),
            'declination' => AstroMath::deg(asin($z / $distance)),
            'distance_km' => $distance,
        ];
    }

    /**
     * Illuminated fraction of the Moon's disk. Meeus (48.2)-(48.4).
     *
     * @return array{illumination: float, phase_angle: float, elongation: float}
     */
    public static function illumination(
        float $sunRaDeg,
        float $sunDecDeg,
        float $sunDistanceKm,
        float $moonRaDeg,
        float $moonDecDeg,
        float $moonDistanceKm
    ): array {
        // Geocentric elongation of the Moon from the Sun. (48.2)
        $cosPsi = AstroMath::sinDeg($sunDecDeg) * AstroMath::sinDeg($moonDecDeg)
            + AstroMath::cosDeg($sunDecDeg) * AstroMath::cosDeg($moonDecDeg)
                * AstroMath::cosDeg($sunRaDeg - $moonRaDeg);
        $cosPsi = max(-1.0, min(1.0, $cosPsi));
        $psi = AstroMath::deg(acos($cosPsi));

        // Phase angle. (48.3) - atan2 keeps it in the correct quadrant.
        $i = AstroMath::deg(atan2(
            $sunDistanceKm * AstroMath::sinDeg($psi),
            $moonDistanceKm - $sunDistanceKm * $cosPsi
        ));
        $i = AstroMath::norm360($i);
        if ($i > 180.0) {
            $i -= 360.0;
        }

        return [
            'illumination' => (1.0 + AstroMath::cosDeg($i)) / 2.0,
            'phase_angle' => $i,
            'elongation' => $psi,
        ];
    }

    /**
     * Position angle of the midpoint of the bright limb, measured from the celestial
     * north pole towards east, 0..360 deg. Meeus (48.5).
     *
     * This is a pure function of the two equatorial positions, so the tests can feed it
     * Meeus' own worked example directly.
     */
    public static function brightLimbPositionAngleDeg(
        float $sunRaDeg,
        float $sunDecDeg,
        float $moonRaDeg,
        float $moonDecDeg
    ): float {
        $deltaRa = $sunRaDeg - $moonRaDeg;

        $numerator = AstroMath::cosDeg($sunDecDeg) * AstroMath::sinDeg($deltaRa);
        $denominator = AstroMath::sinDeg($sunDecDeg) * AstroMath::cosDeg($moonDecDeg)
            - AstroMath::cosDeg($sunDecDeg) * AstroMath::sinDeg($moonDecDeg) * AstroMath::cosDeg($deltaRa);

        return AstroMath::norm360(AstroMath::deg(atan2($numerator, $denominator)));
    }

    /** Terms containing M are multiplied by E (or E^2 for |M| = 2). Meeus p. 338. */
    private static function eccentricityFactor(int $mCoefficient, float $e): float
    {
        return match (abs($mCoefficient)) {
            0 => 1.0,
            1 => $e,
            default => $e * $e,
        };
    }

    /** Geometric (unrefracted) topocentric altitude for a JD in UT - scanned by the rise/set finder. */
    public static function geometricAltitudeAt(float $jdUt, Location $location): float
    {
        $geo = self::geocentric(AstroMath::jdToJde($jdUt));
        $lst = AstroMath::apparentSiderealTimeDeg($jdUt);
        $topo = self::topocentric($geo, $location, $lst);

        return AstroMath::equatorialToHorizontal(
            $topo['right_ascension'],
            $topo['declination'],
            $location->latitudeDeg,
            $location->longitudeDeg,
            $lst
        )['altitude'];
    }

    /**
     * Altitude of the Moon's CENTRE at moonrise/moonset, compared against the TOPOCENTRIC
     * geometric altitude.
     *
     * Meeus (ch. 15) gives h0 = 0.7275*pi - 0.5666 deg for the GEOCENTRIC position, where
     * the leading pi removes the parallax. We already work topocentrically, so only the
     * semidiameter (0.2725*pi) and the refraction (34') remain - plus the dip of the horizon.
     */
    public static function riseSetTargetDeg(Location $location, float $parallaxDeg): float
    {
        return -0.2725 * $parallaxDeg - 0.5666 - $location->horizonDipDeg();
    }

    /**
     * Moonrise/moonset inside the given UT window.
     *
     * @return array{rise: ?float, set: ?float} Julian Days in UT, null when there is no
     *                                          such event that day (normal, ~once a month)
     */
    public static function events(float $startJdUt, float $endJdUt, Location $location): array
    {
        $altitude = static fn (float $jd): float => self::geometricAltitudeAt($jd, $location);

        // The parallax varies by ~0.05 deg over a day; the midpoint value is accurate to
        // a couple of seconds of time, well below the minute we display.
        $midParallax = self::geocentric(AstroMath::jdToJde(($startJdUt + $endJdUt) / 2.0))['parallax'];

        $samples = RiseSetFinder::sample($altitude, $startJdUt, $endJdUt);

        return RiseSetFinder::crossings($samples, $altitude, self::riseSetTargetDeg($location, $midParallax));
    }
}
