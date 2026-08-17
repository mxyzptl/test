<?php

declare(strict_types=1);

/**
 * Standalone test runner for the astronomical core (TASK-0003).
 *
 * There is no PHPUnit here on purpose: the project carries no Composer dependency
 * (Resources/plan/architecture.md), and pulling one in just to run assertions would be a
 * bigger change than the tests themselves.
 *
 * Run:  php tests/run.php          (exit code 0 = all green, 1 = at least one failure)
 *
 * Where the expected values come from:
 *  - MEEUS: the worked examples printed in Jean Meeus, Astronomical Algorithms, 2nd ed.
 *    (examples 12.a, 25.a, 47.a, 48.a). These validate the series themselves, independently
 *    of any site or time zone.
 *  - HORIZONS: NASA/JPL Horizons, queried for the exact site of this project
 *    (19.2836 E, 47.6489 N, 140 m), refracted and airless apparent Az/El. These are the
 *    "reference ephemeris" the brief names as the accuracy yardstick.
 *  - GEOMETRY: values derivable from spherical trigonometry alone (solar noon altitude,
 *    day length), used as coarse plausibility checks.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Sky\AstroMath;
use Sky\ColorMath;
use Sky\Location;
use Sky\LunarPosition;
use Sky\RequestTime;
use Sky\RiseSetFinder;
use Sky\Sky;
use Sky\SkyPalette;
use Sky\SolarPosition;

// ---------------------------------------------------------------------------
// Tiny assertion harness
// ---------------------------------------------------------------------------

final class Result
{
    public static int $passed = 0;
    public static int $failed = 0;
    /** @var list<string> */
    public static array $failures = [];
}

function section(string $title): void
{
    echo "\n" . str_repeat('=', 78) . "\n" . $title . "\n" . str_repeat('=', 78) . "\n";
}

function record(bool $ok, string $name, string $detail): void
{
    if ($ok) {
        Result::$passed++;
        printf("  PASS  %-58s %s\n", $name, $detail);

        return;
    }

    Result::$failed++;
    Result::$failures[] = $name . ' — ' . $detail;
    printf("  FAIL  %-58s %s\n", $name, $detail);
}

function ok(string $name, bool $condition, string $detail = ''): void
{
    record($condition, $name, $detail);
}

/** Numeric comparison with an absolute tolerance. */
function near(string $name, float $actual, float $expected, float $tolerance, string $unit = ''): void
{
    $delta = abs($actual - $expected);
    record(
        $delta <= $tolerance,
        $name,
        sprintf('actual=%.6f expected=%.6f delta=%.6f tol=%.6f%s', $actual, $expected, $delta, $tolerance, $unit)
    );
}

/** Angle comparison that copes with the 0/360 wrap. */
function nearAngle(string $name, float $actual, float $expected, float $tolerance): void
{
    $delta = abs(AstroMath::norm180($actual - $expected));
    record(
        $delta <= $tolerance,
        $name,
        sprintf('actual=%.6f expected=%.6f delta=%.6f tol=%.6f deg', $actual, $expected, $delta, $tolerance)
    );
}

function throwsInvalidArgument(string $name, callable $fn): void
{
    try {
        $fn();
        record(false, $name, 'no exception was thrown');
    } catch (InvalidArgumentException $e) {
        record(true, $name, 'rejected: ' . $e->getMessage());
    } catch (Throwable $e) {
        record(false, $name, 'wrong exception type: ' . $e::class . ' — ' . $e->getMessage());
    }
}

function utc(string $iso): DateTimeImmutable
{
    return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
}

const AU_KM = 149597870.7;

$veresegyhaz = Location::veresegyhaz();
$seaLevel = new Location(
    'Veresegyház (sea level)',
    Location::VERESEGYHAZ_LATITUDE,
    Location::VERESEGYHAZ_LONGITUDE,
    0.0,
    Location::VERESEGYHAZ_TIMEZONE
);

// ---------------------------------------------------------------------------
section('1. Meeus worked examples — validates the series, site-independently');
// ---------------------------------------------------------------------------

// Example 25.a: the Sun on 1992 October 13.0 TD (JDE 2448908.5).
$sun25a = SolarPosition::geocentric(2448908.5);
near('25.a Sun apparent longitude', $sun25a['apparent_longitude'], 199.90895, 0.0005, ' deg');
near('25.a Sun right ascension', $sun25a['right_ascension'], 198.38083, 0.0010, ' deg');
near('25.a Sun declination', $sun25a['declination'], -7.78507, 0.0010, ' deg');
near('25.a Sun radius vector', $sun25a['distance_au'], 0.99766, 0.00002, ' AU');

// Example 47.a: the Moon on 1992 April 12.0 TD (JDE 2448724.5).
$moon47a = LunarPosition::geocentric(2448724.5);
near('47.a Moon longitude (before nutation)', $moon47a['mean_longitude'], 133.162655, 0.0005, ' deg');
near('47.a Moon latitude', $moon47a['latitude'], -3.229126, 0.0005, ' deg');
near('47.a Moon distance', $moon47a['distance_km'], 368409.7, 1.0, ' km');
near('47.a Moon horizontal parallax', $moon47a['parallax'], 0.991990, 0.0002, ' deg');
near('47.a Moon apparent longitude', $moon47a['apparent_longitude'], 133.167265, 0.0020, ' deg');
near('47.a Moon right ascension', $moon47a['right_ascension'], 134.688470, 0.0030, ' deg');
near('47.a Moon declination', $moon47a['declination'], 13.768368, 0.0030, ' deg');

// Example 48.a: illuminated fraction and position angle of the bright limb.
$phase48a = LunarPosition::illumination(20.6579, 8.6964, 149971520.0, 134.6885, 13.7684, 368410.0);
near('48.a elongation psi', $phase48a['elongation'], 110.7929, 0.0010, ' deg');
near('48.a phase angle i', $phase48a['phase_angle'], 69.0756, 0.0010, ' deg');
near('48.a illuminated fraction k', $phase48a['illumination'], 0.6786, 0.0002);
nearAngle(
    '48.a bright limb position angle chi',
    LunarPosition::brightLimbPositionAngleDeg(20.6579, 8.6964, 134.6885, 13.7684),
    285.0,
    0.1
);

// Example 12.a: apparent sidereal time at Greenwich, 1987 April 10.0 UT (JD 2446895.5).
nearAngle('12.a apparent sidereal time', AstroMath::apparentSiderealTimeDeg(2446895.5), 197.692230, 0.0030);

// ---------------------------------------------------------------------------
section('2. Time scales, Julian Day, DST');
// ---------------------------------------------------------------------------

near('JD of 1992-04-12T00:00:00Z', AstroMath::julianDay(utc('1992-04-12 00:00:00')), 2448724.5, 1e-9);
near('JD of J2000.0 (2000-01-01T12:00:00Z)', AstroMath::julianDay(utc('2000-01-01 12:00:00')), 2451545.0, 1e-9);
near('JD of 1970-01-01T00:00:00Z (Unix epoch)', AstroMath::julianDay(utc('1970-01-01 00:00:00')), 2440587.5, 1e-9);

$roundTrip = AstroMath::jdToUtc(2460000.123456);
near('jdToUtc -> julianDay round trip', AstroMath::julianDay($roundTrip), 2460000.123456, 1e-8);

near('deltaT in mid-2026 is ~75 s', AstroMath::deltaTSeconds(2026.6), 75.0, 3.0, ' s');

// DST: the same instant expressed in Budapest local time and in UTC must give the same sky.
// Summer (CEST, UTC+2):
$summerLocal = new DateTimeImmutable('2026-06-21 12:00:00', new DateTimeZone('Europe/Budapest'));
$summerUtc = utc('2026-06-21 10:00:00');
ok(
    'DST summer: 12:00 Europe/Budapest == 10:00 UTC',
    $summerLocal->getTimestamp() === $summerUtc->getTimestamp(),
    'offset=' . $summerLocal->format('P')
);
near(
    'DST summer: identical Sun altitude from local and UTC input',
    SolarPosition::at($summerLocal, $veresegyhaz)->altitudeDeg,
    SolarPosition::at($summerUtc, $veresegyhaz)->altitudeDeg,
    1e-9,
    ' deg'
);

// Winter (CET, UTC+1):
$winterLocal = new DateTimeImmutable('2026-01-15 10:00:00', new DateTimeZone('Europe/Budapest'));
$winterUtc = utc('2026-01-15 09:00:00');
ok(
    'DST winter: 10:00 Europe/Budapest == 09:00 UTC',
    $winterLocal->getTimestamp() === $winterUtc->getTimestamp(),
    'offset=' . $winterLocal->format('P')
);
near(
    'DST winter: identical Moon altitude from local and UTC input',
    LunarPosition::at($winterLocal, $veresegyhaz)->altitudeDeg,
    LunarPosition::at($winterUtc, $veresegyhaz)->altitudeDeg,
    1e-9,
    ' deg'
);

// ---------------------------------------------------------------------------
section('3. Sun vs JPL Horizons at Veresegyhaz (contract tolerance: +-1 deg)');
// ---------------------------------------------------------------------------

/**
 * HORIZONS, target 10 (Sun), site 19.2836 E / 47.6489 N / 0.140 km.
 * [utc, azimuth, refracted elevation, airless elevation (null = not queried), label]
 */
$sunReference = [
    ['2026-06-21 10:00:00', 155.903474, 64.210098, 64.201903, 'summer solstice, midday, DST'],
    ['2026-01-15 09:00:00', 152.722167, 16.835246, null, 'winter, morning, no DST'],
    ['2026-03-20 04:20:00', 84.485093, -4.594056, -5.240669, 'equinox, around sunrise'],
    // Horizons keeps applying refraction well below the horizon (a constant ~0.646 deg),
    // where Bennett's formula is no longer valid - hence the airless value is queried
    // separately instead of being assumed equal to the refracted one.
    ['2026-08-17 22:00:00', 347.025514, -27.630482, -28.277095, 'night, well below horizon'],
];

foreach ($sunReference as [$iso, $expectedAz, $expectedRefracted, $expectedAirless, $label]) {
    $position = SolarPosition::at(utc($iso), $veresegyhaz);

    nearAngle("Sun azimuth  $iso ($label)", $position->azimuthDeg, $expectedAz, 1.0);
    near("Sun altitude $iso (apparent)", $position->altitudeDeg, $expectedRefracted, 1.0, ' deg');

    if ($expectedAirless !== null) {
        // The real test of the algorithm: no refraction model in the way.
        near("Sun altitude $iso (geometric vs airless)", $position->geometricAltitudeDeg, $expectedAirless, 0.05, ' deg');
    }
}

// ---------------------------------------------------------------------------
section('4. Moon vs JPL Horizons at Veresegyhaz (contract tolerance: +-2 deg)');
// ---------------------------------------------------------------------------

/**
 * HORIZONS, target 301 (Moon), same site. Topocentric by construction.
 * [utc, azimuth, refracted elev (null), airless elev, illuminated %, range in AU, label]
 */
$moonReference = [
    ['2026-06-21 10:00:00', 85.957936, -3.217599, -3.864212, 45.71537, 0.00258022065966, 'summer, near horizon'],
    ['2026-01-15 09:00:00', 192.955486, null, 12.558827, 11.24217, 0.00269239802031, 'winter, above horizon'],
];

foreach ($moonReference as [$iso, $expectedAz, $expectedRefracted, $expectedAirless, $illuPercent, $rangeAu, $label]) {
    $position = LunarPosition::at(utc($iso), $veresegyhaz);

    nearAngle("Moon azimuth  $iso ($label)", $position->azimuthDeg, $expectedAz, 2.0);
    near("Moon altitude $iso (geometric vs airless)", $position->geometricAltitudeDeg, $expectedAirless, 0.2, ' deg');

    if ($expectedRefracted !== null) {
        near("Moon altitude $iso (apparent)", $position->altitudeDeg, $expectedRefracted, 2.0, ' deg');
    }

    near("Moon illumination $iso", $position->illumination, $illuPercent / 100.0, 0.02);
    near("Moon topocentric distance $iso", $position->distanceKm, $rangeAu * AU_KM, 60.0, ' km');

    // The geocentric position would be off by up to ~1 deg: prove the correction is real.
    $geocentricAltitude = AstroMath::equatorialToHorizontal(
        $position->rightAscensionDeg,
        $position->declinationDeg,
        $veresegyhaz->latitudeDeg,
        $veresegyhaz->longitudeDeg,
        AstroMath::apparentSiderealTimeDeg(AstroMath::julianDay(utc($iso)))
    )['altitude'];
    ok(
        "Moon topocentric correction is applied $iso",
        abs($geocentricAltitude - $position->geometricAltitudeDeg) > 0.1,
        sprintf(
            'geocentric=%.4f topocentric=%.4f shift=%.4f deg',
            $geocentricAltitude,
            $position->geometricAltitudeDeg,
            $position->geometricAltitudeDeg - $geocentricAltitude
        )
    );
}

// ---------------------------------------------------------------------------
section('5. Moon phases — illumination and waxing (HORIZONS, August 2026, 12:00 UT)');
// ---------------------------------------------------------------------------

/** [date, illuminated %, expected waxing (null = too close to new/full to be meaningful), label] */
$phaseReference = [
    ['2026-08-03', 77.58546, false, 'waning gibbous'],
    ['2026-08-05', 57.50209, false, 'just past last quarter'],
    ['2026-08-06', 46.27542, false, 'LAST QUARTER'],
    ['2026-08-08', 24.21922, false, 'waning crescent'],
    ['2026-08-12', 0.08252, null, 'NEW MOON'],
    ['2026-08-16', 17.27414, true, 'waxing crescent'],
    ['2026-08-19', 44.81237, true, 'approaching first quarter'],
    ['2026-08-20', 54.43254, true, 'FIRST QUARTER'],
    ['2026-08-24', 87.42041, true, 'waxing gibbous'],
    ['2026-08-28', 99.89466, null, 'FULL MOON'],
    ['2026-08-31', 88.27487, false, 'waning gibbous'],
];

foreach ($phaseReference as [$date, $illuPercent, $expectedWaxing, $label]) {
    $position = LunarPosition::at(utc($date . ' 12:00:00'), $veresegyhaz);

    near("illumination $date ($label)", $position->illumination, $illuPercent / 100.0, 0.02);

    if ($expectedWaxing !== null) {
        ok(
            "waxing flag $date ($label)",
            $position->waxing === $expectedWaxing,
            sprintf('actual=%s expected=%s (k=%.4f)', var_export($position->waxing, true), var_export($expectedWaxing, true), $position->illumination)
        );
    }
}

// ---------------------------------------------------------------------------
section('6. Bright limb angle — self-consistency with the direction of the Sun');
// ---------------------------------------------------------------------------

/**
 * bright_limb_angle_deg is computed in the horizontal frame. The classic route to the same
 * direction goes through the equatorial frame: Meeus (48.5) for chi, plus the parallactic
 * angle q. The two derivations share no formula, so agreement is a real check - and a sign
 * slip in either would flip the crescent on screen, which is precisely the kind of silent
 * error this task warns about.
 *
 * The mirror (q - chi rather than chi - q) is not a fudge: equatorial position angles are
 * measured "north through east", which on the sky runs opposite to increasing azimuth.
 */
foreach (['2026-08-16 19:00:00', '2026-08-20 18:00:00', '2026-08-24 20:00:00', '2026-01-15 09:00:00', '2026-06-21 10:00:00'] as $iso) {
    $moon = LunarPosition::at(utc($iso), $veresegyhaz);

    nearAngle(
        "bright limb: horizontal frame == q - chi (Meeus)  $iso",
        $moon->brightLimbAngleDeg,
        AstroMath::norm360($moon->parallacticAngleDeg - $moon->brightLimbPositionAngleDeg),
        0.5
    );
}

/**
 * The independent integrity check the PM asked for: on the flat panorama the renderer
 * actually draws (x from azimuth, y from altitude), the bright limb must point at the
 * drawn position of the Sun. This is planar trigonometry on the published azimuth and
 * altitude values - it shares no formula with either derivation above, so a mirrored
 * crescent cannot survive it even if the two derivations happened to agree with each other.
 *
 * The tolerance is 30 deg because the flat projection is only a local approximation of the
 * sphere; a sign error would show up as ~180 deg or as a mirror, never as 30.
 */
$limbInstants = [
    '2026-08-14 18:30:00', '2026-08-16 19:00:00', '2026-08-18 19:00:00',
    '2026-08-20 18:00:00', '2026-08-22 19:30:00', '2026-08-24 20:00:00',
    '2026-08-28 21:00:00', '2026-08-31 23:00:00', '2026-08-06 03:00:00',
    '2026-08-08 02:00:00', '2026-01-15 09:00:00', '2026-06-21 10:00:00',
];

/**
 * Exact form: build the unit vectors in the horizontal frame (x = north, y = east,
 * z = zenith) and project the direction of the Sun onto the Moon's tangent plane. This is
 * vector algebra, not spherical trigonometry, so it is an independent derivation - but
 * unlike a flat-screen approximation it carries no error, hence the 0.5 deg tolerance.
 */
$directionToSun = static function (float $moonAlt, float $moonAz, float $sunAlt, float $sunAz): float {
    $sun = [
        AstroMath::cosDeg($sunAlt) * AstroMath::cosDeg($sunAz),
        AstroMath::cosDeg($sunAlt) * AstroMath::sinDeg($sunAz),
        AstroMath::sinDeg($sunAlt),
    ];

    // Tangent basis at the Moon: "towards the zenith" and "towards increasing azimuth".
    $towardsZenith = [
        -AstroMath::sinDeg($moonAlt) * AstroMath::cosDeg($moonAz),
        -AstroMath::sinDeg($moonAlt) * AstroMath::sinDeg($moonAz),
        AstroMath::cosDeg($moonAlt),
    ];
    $towardsAzimuth = [-AstroMath::sinDeg($moonAz), AstroMath::cosDeg($moonAz), 0.0];

    $up = $sun[0] * $towardsZenith[0] + $sun[1] * $towardsZenith[1] + $sun[2] * $towardsZenith[2];
    $across = $sun[0] * $towardsAzimuth[0] + $sun[1] * $towardsAzimuth[1] + $sun[2] * $towardsAzimuth[2];

    return AstroMath::norm360(AstroMath::deg(atan2($across, $up)));
};

$worstPlanarError = 0.0;
foreach ($limbInstants as $iso) {
    $when = utc($iso);
    $moon = LunarPosition::at($when, $veresegyhaz);
    $sun = SolarPosition::at($when, $veresegyhaz);

    nearAngle(
        "bright limb points at the Sun (exact vectors)  $iso",
        $moon->brightLimbAngleDeg,
        $directionToSun($moon->geometricAltitudeDeg, $moon->azimuthDeg, $sun->geometricAltitudeDeg, $sun->azimuthDeg),
        0.5
    );

    // The same question asked the way the renderer will see it: on the flat panorama
    // (x from azimuth, y from altitude), does the lit side face the drawn Sun? The flat
    // projection distorts, and the distortion grows with the elongation - at last quarter
    // (90 deg apart) it reaches ~31 deg, which is why the tolerance here is 35 and not 2.
    $meanAltitude = ($moon->geometricAltitudeDeg + $sun->geometricAltitudeDeg) / 2.0;
    $dx = AstroMath::norm180($sun->azimuthDeg - $moon->azimuthDeg) * AstroMath::cosDeg($meanAltitude);
    $dy = -($sun->geometricAltitudeDeg - $moon->geometricAltitudeDeg);
    $screenAngleToSun = AstroMath::norm360(AstroMath::deg(atan2($dx, -$dy)));

    $worstPlanarError = max($worstPlanarError, abs(AstroMath::norm180($moon->brightLimbAngleDeg - $screenAngleToSun)));
    nearAngle("bright limb points at the drawn Sun (flat panorama)  $iso", $moon->brightLimbAngleDeg, $screenAngleToSun, 35.0);
}
record(
    $worstPlanarError < 35.0,
    'worst flat-projection deviation over 12 instants',
    sprintf('%.2f deg — a mirrored crescent would show up as ~180 deg, not as 31', $worstPlanarError)
);

/**
 * Two human-verifiable spot checks. In the renderer's frame 90 deg means "towards
 * increasing azimuth" and 270 deg "towards decreasing azimuth".
 *
 * A first quarter Moon in the evening sky is lit on its western side with a near-vertical
 * terminator; a last quarter Moon before dawn is lit on its eastern side.
 */
$firstQuarter = LunarPosition::at(utc('2026-08-20 18:00:00'), $veresegyhaz);
near('first quarter 2026-08-20: illumination is about half', $firstQuarter->illumination, 0.5, 0.08);
ok('first quarter 2026-08-20: is waxing', $firstQuarter->waxing, var_export($firstQuarter->waxing, true));
nearAngle('first quarter 2026-08-20: lit side towards increasing azimuth (~90)', $firstQuarter->brightLimbAngleDeg, 90.0, 25.0);

$lastQuarter = LunarPosition::at(utc('2026-08-06 03:00:00'), $veresegyhaz);
near('last quarter 2026-08-06: illumination is about half', $lastQuarter->illumination, 0.5, 0.08);
ok('last quarter 2026-08-06: is waning', !$lastQuarter->waxing, var_export($lastQuarter->waxing, true));
nearAngle('last quarter 2026-08-06: lit side towards decreasing azimuth (~270)', $lastQuarter->brightLimbAngleDeg, 270.0, 40.0);

// ---------------------------------------------------------------------------
section('7. Sky palette — bands and continuity');
// ---------------------------------------------------------------------------

// The contract defines every band as [lower, upper): the lower bound belongs to the
// BRIGHTER band. Both sides of each boundary are pinned down here.
$paletteCases = [
    // Design spec 5.4: "golden" owns BOTH of its edges (-0.833 <= h <= +6).
    [45.0, 'day'], [6.001, 'day'], [6.0, 'golden'], [5.999, 'golden'],
    [0.0, 'golden'], [-0.833, 'golden'], [-0.834, 'civil'],
    [-3.0, 'civil'], [-6.0, 'civil'], [-6.001, 'nautical'],
    [-9.0, 'nautical'], [-12.0, 'nautical'], [-12.001, 'astronomical'],
    [-15.0, 'astronomical'], [-18.0, 'astronomical'], [-18.001, 'night'],
    [-40.0, 'night'],
];

foreach ($paletteCases as [$altitude, $expectedPhase]) {
    $palette = SkyPalette::fromSunAltitude($altitude);
    ok(
        sprintf('palette phase at %+.3f deg', $altitude),
        $palette->phase === $expectedPhase,
        sprintf('actual=%s expected=%s blend=%.3f', $palette->phase, $expectedPhase, $palette->blend)
    );
}

near('blend at the middle of the astronomical band', SkyPalette::fromSunAltitude(-15.0)->blend, 0.5, 1e-9);
near('blend at the middle of the nautical band', SkyPalette::fromSunAltitude(-9.0)->blend, 0.5, 1e-9);
near('blend at the lower edge of a band is 0', SkyPalette::fromSunAltitude(-12.0)->blend, 0.0, 1e-9);

// Blend must be monotone in altitude within a band, and always inside [0,1].
$blendValid = true;
$previousPhase = null;
$monotone = true;
$previousBlend = null;
for ($altitude = -90.0; $altitude <= 90.0; $altitude += 0.1) {
    $palette = SkyPalette::fromSunAltitude($altitude);

    if ($palette->blend < 0.0 || $palette->blend > 1.0 || !in_array($palette->phase, SkyPalette::phases(), true)) {
        $blendValid = false;
        break;
    }
    if ($palette->phase === $previousPhase && $previousBlend !== null && $palette->blend < $previousBlend - 1e-12) {
        $monotone = false;
        break;
    }
    $previousPhase = $palette->phase;
    $previousBlend = $palette->blend;
}
ok('blend stays in [0,1] and phase is always valid (-90..90 deg)', $blendValid, 'swept in 0.1 deg steps');
ok('blend is monotone increasing inside every band', $monotone, 'swept in 0.1 deg steps');

// ---------------------------------------------------------------------------
section('7/b. Palette tokens (design spec 5.2, 5.5, 5.6, 6.1, 6.6, 15)');
// ---------------------------------------------------------------------------

// At an anchor altitude the interpolation must reproduce the anchor exactly.
$anchorCases = [
    [50.0, ['#0E56BE', '#2B7BDA', '#66A8E9', '#9DCAF2', '#C8E2F8', '#E4F0FA'], '255 244 214', 0.18],
    [20.0, ['#1257B6', '#2F7CD2', '#6BA6E0', '#A3C8EC', '#CFE1F1', '#EAEDEE'], '255 240 200', 0.24],
    [6.0, ['#14498F', '#2F6CB0', '#6D96C9', '#B0B7D0', '#E2CBB4', '#F6DFAF'], '255 215 154', 0.40],
    [2.0, ['#173F7C', '#33619E', '#7E82AE', '#C89A88', '#EFAE6E', '#FBD68C'], '255 194 122', 0.52],
    [-0.833, ['#16326A', '#2C5089', '#6C6A99', '#BC7C74', '#E9905A', '#FBBE72'], '255 158 82', 0.58],
    [-6.0, ['#0D1F4C', '#1B3268', '#38487E', '#6A5480', '#A8636B', '#D2825F'], '233 113 63', 0.46],
    [-12.0, ['#071132', '#0E1C43', '#182B5A', '#2B3A6B', '#45416F', '#6A4F66'], '124 74 82', 0.26],
    [-18.0, ['#040814', '#060D1F', '#0A1430', '#101C40', '#17244B', '#23305A'], '51 50 92', 0.12],
    [-30.0, ['#02040B', '#030611', '#050A1C', '#080E27', '#0B1330', '#101A3C'], '26 33 72', 0.06],
    [-60.0, ['#02040B', '#030611', '#050A1C', '#080E27', '#0B1330', '#101A3C'], '26 33 72', 0.06],
    [80.0, ['#0E56BE', '#2B7BDA', '#66A8E9', '#9DCAF2', '#C8E2F8', '#E4F0FA'], '255 244 214', 0.18],
];

foreach ($anchorCases as [$altitude, $expectedStops, $expectedGlowRgb, $expectedGlowA]) {
    $tokens = SkyPalette::fromSunAltitude($altitude)->tokens(-20.0, 0.5);

    ok(
        sprintf('palette stops reproduce the anchor at %+.3f deg', $altitude),
        $tokens['stops'] === $expectedStops,
        implode(' ', $tokens['stops'])
    );
    ok(
        sprintf('glow at the anchor %+.3f deg', $altitude),
        $tokens['glow_rgb'] === $expectedGlowRgb && abs($tokens['glow_a'] - $expectedGlowA) < 1e-9,
        $tokens['glow_rgb'] . ' / ' . $tokens['glow_a']
    );
}

// Solar disc anchors (spec 6.1).
$discCases = [
    [20.0, '#FFFEF4', '#FFF0B8', 2.6, 0.42],
    [6.0, '#FFF6DC', '#FFD98A', 3.1, 0.55],
    [2.0, '#FFE7A8', '#FFB44E', 3.7, 0.68],
    [0.0, '#FFC46A', '#F5762A', 4.2, 0.78],
    [-9.0, '#FFC46A', '#F5762A', 4.2, 0.78],
];
foreach ($discCases as [$altitude, $core, $edge, $coronaK, $coronaA]) {
    $tokens = SkyPalette::fromSunAltitude($altitude)->tokens(-20.0, 0.5);
    ok(
        sprintf('sun disc tokens at %+.3f deg', $altitude),
        $tokens['sun_core'] === $core && $tokens['sun_edge'] === $edge
            && abs($tokens['sun_corona_k'] - $coronaK) < 1e-9 && abs($tokens['sun_corona_a'] - $coronaA) < 1e-9,
        sprintf('%s / %s / k=%s / a=%s', $tokens['sun_core'], $tokens['sun_edge'], $tokens['sun_corona_k'], $tokens['sun_corona_a'])
    );
}

// Derived scalars against the reference table of spec 5.5.
$scalarCases = [
    ['day', 40.0, 1.00, 0.42, 0.00],
    ['golden', 3.0, 0.83, 0.37, 0.00],
    ['civil', -3.0, 0.50, 0.27, 0.00],
    ['nautical', -9.0, 0.17, 0.17, 0.36],
    ['astronomical', -15.0, 0.00, 0.12, 0.79],
    ['night', -25.0, 0.00, 0.12, 1.00],
];
foreach ($scalarCases as [$phase, $altitude, $dayness2, $plateAlpha, $starF]) {
    $palette = SkyPalette::fromSunAltitude($altitude);
    $tokens = $palette->tokens(-20.0, 0.5);

    ok("spec 5.5 phase label at {$altitude} deg", $palette->phase === $phase, "actual={$palette->phase} expected=$phase");
    near("spec 5.5 dayness2 at {$altitude} deg", $palette->dayness2(), $dayness2, 0.005);
    near("spec 5.5 plate_alpha at {$altitude} deg", $tokens['plate_alpha'], $plateAlpha, 0.005);
    near("spec 5.5 star_f at {$altitude} deg", $tokens['star_f'], $starF, 0.005);
    near("spec 5.5 vignette_alpha at {$altitude} deg", $tokens['vignette_alpha'], 0.10 + 0.12 * $dayness2, 0.005);
}

// Moon-dependent tokens (spec 6.2.4, 6.4).
near('moon_opacity: full Moon at night', SkyPalette::fromSunAltitude(-25.0)->tokens(40.0, 1.0)['moon_opacity'], 1.00, 0.005);
near('moon_opacity: full Moon at noon', SkyPalette::fromSunAltitude(45.0)->tokens(20.0, 1.0)['moon_opacity'], 0.85, 0.005);
near('moon_opacity: new Moon at noon', SkyPalette::fromSunAltitude(45.0)->tokens(20.0, 0.0)['moon_opacity'], 0.45, 0.005);
near('star_dim: no Moon above the horizon', SkyPalette::fromSunAltitude(-25.0)->tokens(-5.0, 1.0)['star_dim'], 1.00, 0.005);
near('star_dim: full Moon high up', SkyPalette::fromSunAltitude(-25.0)->tokens(40.0, 1.0)['star_dim'], 0.65, 0.005);
near('star_dim: full Moon just above the horizon', SkyPalette::fromSunAltitude(-25.0)->tokens(7.5, 1.0)['star_dim'], 0.825, 0.005);

// Ground base (spec 6.6): pure day colour at dayness=1, pure night colour at dayness=0.
ok('ground_base at full daylight', SkyPalette::fromSunAltitude(45.0)->tokens(-20.0, 0.5)['ground_base'] === '#0B140C', SkyPalette::fromSunAltitude(45.0)->tokens(-20.0, 0.5)['ground_base']);
ok('ground_base deep at night', SkyPalette::fromSunAltitude(-25.0)->tokens(-20.0, 0.5)['ground_base'] === '#05070E', SkyPalette::fromSunAltitude(-25.0)->tokens(-20.0, 0.5)['ground_base']);

// The whole point of the anchor interpolation: no visible jump anywhere.
$maxJump = 0.0;
$previousStops = null;
$tokensValid = true;
for ($altitude = -40.0; $altitude <= 60.0; $altitude += 0.05) {
    $tokens = SkyPalette::fromSunAltitude($altitude)->tokens(-20.0, 0.5);

    foreach ($tokens['stops'] as $stop) {
        if (preg_match('/^#[0-9A-F]{6}$/', $stop) !== 1) {
            $tokensValid = false;
        }
    }

    if ($previousStops !== null) {
        foreach ($tokens['stops'] as $index => $stop) {
            $a = ColorMath::hexToSrgb($stop);
            $b = ColorMath::hexToSrgb($previousStops[$index]);
            $maxJump = max($maxJump, abs($a[0] - $b[0]), abs($a[1] - $b[1]), abs($a[2] - $b[2]));
        }
    }
    $previousStops = $tokens['stops'];
}
ok('every interpolated stop is a valid #RRGGBB', $tokensValid, 'swept -40..+60 deg in 0.05 deg steps');
record($maxJump < 0.02, 'sky gradient is continuous (no visible jump)', sprintf('largest step between 0.05 deg samples = %.4f (limit 0.02 of full scale)', $maxJump));

// Linear-light blending must not go through a muddy midpoint (spec 5.3).
ok(
    'mixLinearSrgb blends in linear light, not on raw hex',
    ColorMath::mixLinearSrgb('#000000', '#FFFFFF', 0.5) === '#BCBCBC',
    'midpoint of black->white = ' . ColorMath::mixLinearSrgb('#000000', '#FFFFFF', 0.5) . ' (naive hex midpoint would be #808080)'
);
ok('mixLinearSrgb at t=0 returns the source colour', ColorMath::mixLinearSrgb('#16326A', '#FF9E52', 0.0) === '#16326A', '');
ok('mixLinearSrgb at t=1 returns the target colour', ColorMath::mixLinearSrgb('#16326A', '#FF9E52', 1.0) === '#FF9E52', '');
near('smoothstep(0.5) = 0.5', ColorMath::smoothstep(0.5), 0.5, 1e-12);
near('smoothstep(0) = 0', ColorMath::smoothstep(0.0), 0.0, 1e-12);
near('smoothstep(1) = 1', ColorMath::smoothstep(1.0), 1.0, 1e-12);
ok('mixOklab at weight=1 returns the first colour', ColorMath::mixOklab('#0B140C', '#05070E', 1.0) === '#0B140C', ColorMath::mixOklab('#0B140C', '#05070E', 1.0));
ok('mixOklab at weight=0 returns the second colour', ColorMath::mixOklab('#0B140C', '#05070E', 0.0) === '#05070E', ColorMath::mixOklab('#0B140C', '#05070E', 0.0));

// ---------------------------------------------------------------------------
section('8. Rise, set and twilight');
// ---------------------------------------------------------------------------

// 8.1 The root finder must land exactly on the target altitude.
foreach (['2026-06-21', '2026-12-21', '2026-03-20'] as $date) {
    $dayStart = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('Europe/Budapest'));
    $startJd = AstroMath::julianDay($dayStart);
    $endJd = AstroMath::julianDay($dayStart->modify('+1 day'));

    $solarEvents = SolarPosition::events($startJd, $endJd, $veresegyhaz);
    $targets = [
        'sun' => SolarPosition::riseSetTargetDeg($veresegyhaz),
        'civil' => -6.0,
        'nautical' => -12.0,
        'astronomical' => -18.0,
    ];

    foreach ($solarEvents as $event => $pair) {
        foreach ($pair as $kind => $jd) {
            if ($jd === null) {
                continue;
            }
            near(
                sprintf('%s: Sun altitude at %s %s equals the target', $date, $event, $kind),
                SolarPosition::geometricAltitudeAt($jd, $veresegyhaz),
                $targets[$event],
                0.002,
                ' deg'
            );
        }
    }

    $lunarEvents = LunarPosition::events($startJd, $endJd, $veresegyhaz);
    foreach ($lunarEvents as $kind => $jd) {
        if ($jd === null) {
            continue;
        }
        $parallax = LunarPosition::geocentric(AstroMath::jdToJde(($startJd + $endJd) / 2.0))['parallax'];
        near(
            sprintf('%s: Moon altitude at moon%s equals the target', $date, $kind),
            LunarPosition::geometricAltitudeAt($jd, $veresegyhaz),
            LunarPosition::riseSetTargetDeg($veresegyhaz, $parallax),
            0.002,
            ' deg'
        );
    }
}

// 8.2 Sunrise/sunset against HORIZONS.
// Horizons refracted centre elevation crosses -0.2665 deg (upper limb at the astronomical
// horizon, i.e. the textbook sunrise for an observer AT SEA LEVEL) at:
//   2026-06-21 sunrise 02:44:23 UT, sunset 18:44:56 UT
// interpolated from the 2-minute Horizons series. We therefore compare the SEA LEVEL
// location; the 140 m site is checked separately for the horizon dip below.
$solstice = new DateTimeImmutable('2026-06-21 00:00:00', new DateTimeZone('Europe/Budapest'));
$solsticeStart = AstroMath::julianDay($solstice);
$solsticeEnd = AstroMath::julianDay($solstice->modify('+1 day'));

$seaEvents = SolarPosition::events($solsticeStart, $solsticeEnd, $seaLevel)['sun'];
$sunriseSeconds = ($seaEvents['rise'] - AstroMath::julianDay(utc('2026-06-21 00:00:00'))) * 86400.0;
$sunsetSeconds = ($seaEvents['set'] - AstroMath::julianDay(utc('2026-06-21 00:00:00'))) * 86400.0;

near('sunrise 2026-06-21 (sea level) vs Horizons', $sunriseSeconds, 2 * 3600 + 44 * 60 + 23, 120.0, ' s from 00:00 UT');
near('sunset  2026-06-21 (sea level) vs Horizons', $sunsetSeconds, 18 * 3600 + 44 * 60 + 56, 120.0, ' s from 00:00 UT');

// 8.3 The 140 m horizon dip must make the day measurably longer.
$hillEvents = SolarPosition::events($solsticeStart, $solsticeEnd, $veresegyhaz)['sun'];
$dipMinutesMorning = ($seaEvents['rise'] - $hillEvents['rise']) * 1440.0;
$dipMinutesEvening = ($hillEvents['set'] - $seaEvents['set']) * 1440.0;
near('140 m dip advances sunrise', $dipMinutesMorning, 3.1, 1.0, ' min');
near('140 m dip delays sunset', $dipMinutesEvening, 3.1, 1.0, ' min');

// 8.4 Day length plausibility (GEOMETRY: 2*H0/15 with h0 = -0.8333 - dip).
$dayLengthSummer = ($hillEvents['set'] - $hillEvents['rise']) * 24.0;
near('day length at the summer solstice', $dayLengthSummer, 16.08, 0.25, ' h');

$winter = new DateTimeImmutable('2026-12-21 00:00:00', new DateTimeZone('Europe/Budapest'));
$winterEvents = SolarPosition::events(
    AstroMath::julianDay($winter),
    AstroMath::julianDay($winter->modify('+1 day')),
    $veresegyhaz
);
$dayLengthWinter = ($winterEvents['sun']['set'] - $winterEvents['sun']['rise']) * 24.0;
near('day length at the winter solstice', $dayLengthWinter, 8.50, 0.25, ' h');

// 8.5 Ordering of the events on a day where all of them fall inside the same local date.
$order = [
    $winterEvents['astronomical']['rise'],
    $winterEvents['nautical']['rise'],
    $winterEvents['civil']['rise'],
    $winterEvents['sun']['rise'],
    $winterEvents['sun']['set'],
    $winterEvents['civil']['set'],
    $winterEvents['nautical']['set'],
    $winterEvents['sun']['set'] !== null ? $winterEvents['astronomical']['set'] : null,
];
$sorted = true;
for ($i = 1; $i < count($order); $i++) {
    if ($order[$i] === null || $order[$i - 1] === null || $order[$i] < $order[$i - 1]) {
        $sorted = false;
        break;
    }
}
ok(
    '2026-12-21: astronomical < nautical < civil < sunrise < sunset < civil < nautical < astronomical',
    $sorted,
    implode(' | ', array_map(
        static fn (?float $jd): string => $jd === null ? 'null' : AstroMath::jdToUtc($jd)->setTimezone(new DateTimeZone('Europe/Budapest'))->format('H:i:s'),
        $order
    ))
);

// 8.6 Moonrise/moonset: a null is legitimate roughly once a month and must not blow up.
$missingRise = 0;
$missingSet = 0;
$moonEventFailure = null;
for ($day = 0; $day < 30; $day++) {
    $dayStart = (new DateTimeImmutable('2026-08-01 00:00:00', new DateTimeZone('Europe/Budapest')))->modify("+$day day");

    try {
        $events = LunarPosition::events(
            AstroMath::julianDay($dayStart),
            AstroMath::julianDay($dayStart->modify('+1 day')),
            $veresegyhaz
        );
    } catch (Throwable $e) {
        $moonEventFailure = $dayStart->format('Y-m-d') . ': ' . $e->getMessage();
        break;
    }

    $missingRise += $events['rise'] === null ? 1 : 0;
    $missingSet += $events['set'] === null ? 1 : 0;
}
ok('30 days of moonrise/moonset computed without an exception', $moonEventFailure === null, (string) $moonEventFailure);
ok(
    'moonrise missing on 0-2 days out of 30 (expected: ~1)',
    $missingRise <= 2,
    "missing rises=$missingRise, missing sets=$missingSet"
);

// ---------------------------------------------------------------------------
section('9. Topocentric correction — vector form vs Meeus (40.2)/(40.3)');
// ---------------------------------------------------------------------------

$jdUt = AstroMath::julianDay(utc('2026-01-15 09:00:00'));
$geo = LunarPosition::geocentric(AstroMath::jdToJde($jdUt));
$lst = AstroMath::apparentSiderealTimeDeg($jdUt);
$vector = LunarPosition::topocentric($geo, $veresegyhaz, $lst);

// Meeus' closed form, written out here on purpose so the two derivations stay independent.
$hourAngle = AstroMath::norm360($lst + $veresegyhaz->longitudeDeg - $geo['right_ascension']);
$sinParallax = AstroMath::sinDeg($geo['parallax']);
$denominator = AstroMath::cosDeg($geo['declination'])
    - $veresegyhaz->rhoCosPhiPrime() * $sinParallax * AstroMath::cosDeg($hourAngle);
$deltaRa = AstroMath::deg(atan2(
    -$veresegyhaz->rhoCosPhiPrime() * $sinParallax * AstroMath::sinDeg($hourAngle),
    $denominator
));
$meeusRa = AstroMath::norm360($geo['right_ascension'] + $deltaRa);
$meeusDec = AstroMath::deg(atan2(
    (AstroMath::sinDeg($geo['declination']) - $veresegyhaz->rhoSinPhiPrime() * $sinParallax) * AstroMath::cosDeg($deltaRa),
    $denominator
));

nearAngle('topocentric RA: vector form == Meeus (40.2)', $vector['right_ascension'], $meeusRa, 0.0005);
near('topocentric Dec: vector form == Meeus (40.3)', $vector['declination'], $meeusDec, 0.0005, ' deg');

// ---------------------------------------------------------------------------
section('10. ?t= parameter validation');
// ---------------------------------------------------------------------------

$budapest = new DateTimeZone('Europe/Budapest');

$validCases = [
    ['2026-08-17T20:31:00+02:00', '2026-08-17 18:31:00'],
    ['2026-08-17T20:31', '2026-08-17 18:31:00'],
    ['2026-08-17 20:31', '2026-08-17 18:31:00'],
    ['2026-08-17T18:31:00Z', '2026-08-17 18:31:00'],
    ['2026-08-17T20:31:00+0200', '2026-08-17 18:31:00'],
    ['2026-08-17', '2026-08-16 22:00:00'],
    ['2026-01-15T10:00', '2026-01-15 09:00:00'],
];

foreach ($validCases as [$input, $expectedUtc]) {
    try {
        $parsed = RequestTime::parse($input, $budapest)->setTimezone(new DateTimeZone('UTC'));
        ok(
            "accepts t=$input",
            $parsed->format('Y-m-d H:i:s') === $expectedUtc,
            'parsed(UTC)=' . $parsed->format('Y-m-d H:i:s') . ' expected=' . $expectedUtc
        );
    } catch (Throwable $e) {
        ok("accepts t=$input", false, 'threw: ' . $e->getMessage());
    }
}

$invalidCases = [
    '' => 'empty',
    'now' => 'relative keyword',
    '+1 day' => 'relative expression',
    'tomorrow' => 'relative keyword',
    '2026-13-01' => 'month 13',
    '2026-02-30' => 'day that does not exist',
    '2026-08-17T25:00' => 'hour 25',
    '2026-08-17T20:61' => 'minute 61',
    '1899-12-31' => 'before the validity window',
    '2101-01-01' => 'after the validity window',
    '2026-03-29T02:30' => 'local time inside the DST spring-forward gap',
    '17/08/2026' => 'wrong format',
    "2026-08-17T20:31:00+02:00'; DROP TABLE" => 'injection attempt',
];

foreach ($invalidCases as $input => $why) {
    throwsInvalidArgument('rejects t=' . var_export($input, true) . " ($why)", static function () use ($input, $budapest): void {
        RequestTime::parse($input, $budapest);
    });
}

throwsInvalidArgument('rejects an over-long t (60 chars)', static function () use ($budapest): void {
    RequestTime::parse(str_repeat('2026-08-17T20:31:00', 4), $budapest);
});

// ---------------------------------------------------------------------------
section('11. Sky::snapshot — the JSON contract');
// ---------------------------------------------------------------------------

$snapshot = Sky::snapshot(new DateTimeImmutable('2026-08-17 20:31:00', $budapest));

$expectedShape = [
    'generated_at' => 'string',
    'location' => 'array',
    'sun' => 'array',
    'moon' => 'array',
    'sky' => 'array',
    'palette' => 'array',
    'times' => 'array',
];
foreach ($expectedShape as $key => $type) {
    ok("contract: key \"$key\" is present and is $type", array_key_exists($key, $snapshot) && get_debug_type($snapshot[$key]) === $type, 'got=' . get_debug_type($snapshot[$key] ?? null));
}

foreach (['name', 'lat', 'lon', 'elevation_m', 'timezone'] as $key) {
    ok("contract: location.$key present", array_key_exists($key, $snapshot['location']), '');
}
foreach (['altitude_deg', 'azimuth_deg', 'visible'] as $key) {
    ok("contract: sun.$key present", array_key_exists($key, $snapshot['sun']), '');
}
foreach (['altitude_deg', 'azimuth_deg', 'visible', 'illumination', 'waxing', 'bright_limb_angle_deg', 'bright_limb_pa_deg', 'distance_km'] as $key) {
    ok("contract: moon.$key present", array_key_exists($key, $snapshot['moon']), '');
}
foreach (['phase', 'sun_altitude_deg', 'blend'] as $key) {
    ok("contract: sky.$key present", array_key_exists($key, $snapshot['sky']), '');
}
foreach (['stops', 'glow_rgb', 'glow_a', 'ground_base', 'star_f', 'star_dim', 'moon_opacity', 'plate_alpha', 'vignette_alpha', 'sun_core', 'sun_edge', 'sun_corona_k', 'sun_corona_a'] as $key) {
    ok("contract: palette.$key present", array_key_exists($key, $snapshot['palette']), '');
}
ok('contract: palette.stops has exactly 6 entries', count($snapshot['palette']['stops']) === 6, (string) count($snapshot['palette']['stops']));
ok(
    'contract: palette has exactly the 13 keys the design spec asks for',
    count($snapshot['palette']) === 13,
    implode(', ', array_keys($snapshot['palette']))
);
foreach (['sunrise', 'sunset', 'moonrise', 'moonset', 'civil_dawn', 'civil_dusk', 'nautical_dawn', 'nautical_dusk', 'astronomical_dawn', 'astronomical_dusk'] as $key) {
    ok("contract: times.$key present", array_key_exists($key, $snapshot['times']), '');
}

ok('contract: location is Veresegyhaz', $snapshot['location']['name'] === 'Veresegyház', $snapshot['location']['name']);
ok('contract: sun.azimuth_deg in [0,360)', $snapshot['sun']['azimuth_deg'] >= 0.0 && $snapshot['sun']['azimuth_deg'] < 360.0, (string) $snapshot['sun']['azimuth_deg']);
ok('contract: moon.azimuth_deg in [0,360)', $snapshot['moon']['azimuth_deg'] >= 0.0 && $snapshot['moon']['azimuth_deg'] < 360.0, (string) $snapshot['moon']['azimuth_deg']);
ok('contract: moon.illumination in [0,1]', $snapshot['moon']['illumination'] >= 0.0 && $snapshot['moon']['illumination'] <= 1.0, (string) $snapshot['moon']['illumination']);
ok('contract: moon.bright_limb_angle_deg in [0,360)', $snapshot['moon']['bright_limb_angle_deg'] >= 0.0 && $snapshot['moon']['bright_limb_angle_deg'] < 360.0, (string) $snapshot['moon']['bright_limb_angle_deg']);
ok('contract: sky.blend in [0,1]', $snapshot['sky']['blend'] >= 0.0 && $snapshot['sky']['blend'] <= 1.0, (string) $snapshot['sky']['blend']);
ok('contract: sky.phase is a known phase', in_array($snapshot['sky']['phase'], SkyPalette::phases(), true), $snapshot['sky']['phase']);
ok('contract: sun.visible matches the altitude sign', $snapshot['sun']['visible'] === ($snapshot['sun']['altitude_deg'] > 0.0), var_export($snapshot['sun']['visible'], true));
ok('contract: sky.sun_altitude_deg mirrors sun.altitude_deg', $snapshot['sky']['sun_altitude_deg'] === $snapshot['sun']['altitude_deg'], '');

$timeFormatOk = true;
foreach ($snapshot['times'] as $key => $value) {
    if ($value !== null && preg_match('/^\d{2}:\d{2}$/', $value) !== 1) {
        $timeFormatOk = false;
        break;
    }
}
ok('contract: every times.* value is "HH:MM" or null', $timeFormatOk, json_encode($snapshot['times'], JSON_UNESCAPED_UNICODE));

$json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
ok('contract: the snapshot is JSON-encodable', $json !== false, 'bytes=' . strlen((string) $json));
ok(
    'contract: the response is deterministic for a fixed instant',
    $snapshot === Sky::snapshot(new DateTimeImmutable('2026-08-17 20:31:00', $budapest)),
    ''
);

echo "\n  sample response:\n  " . $json . "\n";

// ---------------------------------------------------------------------------
section('13. BUG-0008 — refraction is continuous, and sunset is golden');
// ---------------------------------------------------------------------------

/*
 * What broke: refractionDeg() returned 0 below -1 deg, so the PUBLISHED altitude fell off a
 * 0.647 deg cliff (-1.0172 -> -0.3459 in one 10 s step), the -0.833 deg band edge was never
 * reachable, and the sky at the published sunset minute was "civil" instead of "golden".
 *
 * These assertions are written against the OBSERVABLE quantity (the altitude the API publishes
 * and the palette is derived from), not against the formula, so they also close the M2 mutation
 * hole the tester found: switching refraction off now fails here loudly.
 */

// 13.1 The function itself: continuous everywhere, zero exactly where the twilight events live.
near('refraction is continuous at the -1 deg seam (left)', AstroMath::refractionDeg(-1.0 - 1e-9), AstroMath::refractionDeg(-1.0), 1e-6, ' deg');
near('refraction at -1 deg is still Bennett (0.6466)', AstroMath::refractionDeg(-1.0), 0.646613, 1e-5, ' deg');
near('refraction is exactly 0 at -6 deg (civil twilight)', AstroMath::refractionDeg(-6.0), 0.0, 0.0, ' deg');
near('refraction is exactly 0 at -12 deg (nautical)', AstroMath::refractionDeg(-12.0), 0.0, 0.0, ' deg');
near('refraction is exactly 0 at -18 deg (astronomical)', AstroMath::refractionDeg(-18.0), 0.0, 0.0, ' deg');
near('refraction is continuous at the -6 deg seam', AstroMath::refractionDeg(-6.0 + 1e-6), 0.0, 1e-9, ' deg');
// Bennett extended by hand would go NEGATIVE around -5.09 deg (its argument passes 180 deg
// there) and diverges at -5.11. The fade must not do either: non-negative and bounded by the
// -1 deg value all the way down, and matching the smoothstep the contract publishes.
$fadeSane = true;
$fadeMatchesContract = true;
$refractionAtSeam = AstroMath::refractionDeg(-1.0);
for ($h = -6.0; $h <= -1.0; $h += 0.001) {
    $r = AstroMath::refractionDeg($h);
    if ($r < 0.0 || $r > $refractionAtSeam) {
        $fadeSane = false;
        break;
    }
    $s = ($h + 6.0) / 5.0;
    if (abs($r - $refractionAtSeam * $s * $s * (3.0 - 2.0 * $s)) > 1e-12) {
        $fadeMatchesContract = false;
        break;
    }
}
ok('refraction below -1 deg stays in [0, R(-1)] (never negative, never diverges)', $fadeSane, 'swept -6..-1 in 0.001 deg steps');
ok('the fade is exactly the smoothstep the contract documents', $fadeMatchesContract, 'R(h) = R(-1) * s^2 * (3-2s), s = (h+6)/5');

// 13.2 Apparent altitude as a FUNCTION of geometric altitude: no jump, no fold-back.
$worstAltitudeStep = 0.0;
$worstAltitudeStepAt = 0.0;
$strictlyIncreasing = true;
$previousApparent = AstroMath::applyRefraction(-90.0);
for ($i = 1; $i <= 180000; $i++) {
    $h = -90.0 + $i * 0.001;
    $apparent = AstroMath::applyRefraction($h);
    $step = $apparent - $previousApparent;

    if ($step <= 0.0) {
        $strictlyIncreasing = false;
    }
    if (abs($step) > $worstAltitudeStep) {
        $worstAltitudeStep = abs($step);
        $worstAltitudeStepAt = $h;
    }
    $previousApparent = $apparent;
}
record(
    $worstAltitudeStep < 0.0025,
    'apparent altitude has no jump over -90..+90 deg',
    sprintf('largest step per 0.001 deg = %.6f deg at h=%+.3f (limit 0.0025)', $worstAltitudeStep, $worstAltitudeStepAt)
);
ok('apparent altitude is strictly increasing (never folds back)', $strictlyIncreasing, 'swept in 0.001 deg steps');

// 13.3 The band edge the contract documents must actually be reachable.
$reachesBandEdge = false;
for ($h = -3.0; $h <= 0.0; $h += 0.0005) {
    if (abs(AstroMath::applyRefraction($h) - (-0.833)) < 0.001) {
        $reachesBandEdge = true;
        break;
    }
}
ok('the -0.833 deg band edge is actually reachable', $reachesBandEdge, 'the published altitude passes through it');

// 13.4 Refraction is really applied to what we publish (closes mutation M2).
$noonUtc = utc('2026-08-17 10:00:00');
$noonSun = SolarPosition::at($noonUtc, $veresegyhaz);
record(
    $noonSun->altitudeDeg > $noonSun->geometricAltitudeDeg,
    'published Sun altitude is refracted, not geometric',
    sprintf('apparent=%.6f geometric=%.6f diff=%.6f deg', $noonSun->altitudeDeg, $noonSun->geometricAltitudeDeg, $noonSun->altitudeDeg - $noonSun->geometricAltitudeDeg)
);
$horizonSun = SolarPosition::at(utc('2026-08-17 17:50:00'), $veresegyhaz);
near(
    'refraction just below the horizon is the expected ~0.6 deg',
    $horizonSun->altitudeDeg - $horizonSun->geometricAltitudeDeg,
    0.6,
    0.3,
    ' deg'
);

// 13.5 The regression the tester asked for: 1 s steps through the hour around sunset.
/** @return array{worst: float, at: string} */
$scanAltitude = static function (callable $altitudeAt, DateTimeImmutable $from, int $seconds, int $stepSeconds): array {
    $worst = 0.0;
    $worstAt = '';
    $previous = $altitudeAt($from);

    for ($s = $stepSeconds; $s <= $seconds; $s += $stepSeconds) {
        $moment = $from->modify("+$s seconds");
        $altitude = $altitudeAt($moment);
        $step = abs($altitude - $previous);

        if ($step > $worst) {
            $worst = $step;
            $worstAt = $moment->format('Y-m-d H:i:s');
        }
        $previous = $altitude;
    }

    return ['worst' => $worst, 'at' => $worstAt];
};

$sunAltitudeAt = static fn (DateTimeImmutable $m): float => SolarPosition::at($m, $veresegyhaz)->altitudeDeg;
$moonAltitudeAt = static fn (DateTimeImmutable $m): float => LunarPosition::at($m, $veresegyhaz)->altitudeDeg;

// 2026-08-17 sunset is 19:54 local; scan 19:24 -> 20:24 local, one second at a time.
$sunsetScan = $scanAltitude($sunAltitudeAt, new DateTimeImmutable('2026-08-17 19:24:00', $budapest), 3600, 1);
record(
    $sunsetScan['worst'] < 0.01,
    'sun.altitude_deg step over the sunset hour (1 s steps)',
    sprintf('largest = %.6f deg at %s (limit 0.01)', $sunsetScan['worst'], $sunsetScan['at'])
);

// The same for sunrise, and for the whole day at 10 s - this is where the 0.6712 deg jump was.
$sunriseScan = $scanAltitude($sunAltitudeAt, new DateTimeImmutable('2026-08-17 05:09:00', $budapest), 3600, 1);
record(
    $sunriseScan['worst'] < 0.01,
    'sun.altitude_deg step over the sunrise hour (1 s steps)',
    sprintf('largest = %.6f deg at %s (limit 0.01)', $sunriseScan['worst'], $sunriseScan['at'])
);

$dayScan = $scanAltitude($sunAltitudeAt, new DateTimeImmutable('2026-08-17 00:00:00', $budapest), 86400, 10);
record(
    $dayScan['worst'] < 0.05,
    'sun.altitude_deg step over a whole day (10 s steps)',
    sprintf('largest = %.6f deg at %s (limit 0.05, was 0.6712)', $dayScan['worst'], $dayScan['at'])
);

// The Moon shares the same refraction, and the tester measured the jump on it too.
$moonScan = $scanAltitude($moonAltitudeAt, new DateTimeImmutable('2026-08-17 21:00:00', $budapest), 3600, 1);
record(
    $moonScan['worst'] < 0.01,
    'moon.altitude_deg step around moonset (1 s steps)',
    sprintf('largest = %.6f deg at %s (limit 0.01)', $moonScan['worst'], $moonScan['at'])
);

$moonDayScan = $scanAltitude($moonAltitudeAt, new DateTimeImmutable('2026-08-17 00:00:00', $budapest), 86400, 10);
record(
    $moonDayScan['worst'] < 0.05,
    'moon.altitude_deg step over a whole day (10 s steps)',
    sprintf('largest = %.6f deg at %s (limit 0.05, was 0.6488)', $moonDayScan['worst'], $moonDayScan['at'])
);

// 13.6 The point of the whole fix: at the minute the response itself calls sunset, the sky is
//      golden. The expected value is taken from the response, not hard-coded, so the assertion
//      cannot drift away from the times we publish.
$goldenDays = [
    ['2026-08-17', 'the day the contract publishes'],
    ['2026-12-21', 'winter solstice, shortest day'],
    ['2026-06-21', 'summer solstice, longest day'],
    ['2026-03-29', 'spring DST switch (23 h day)'],
    ['2026-10-25', 'autumn DST switch (25 h day)'],
];

foreach ($goldenDays as [$date, $label]) {
    $times = Sky::snapshot(new DateTimeImmutable($date . ' 12:00:00', $budapest))['times'];

    foreach (['sunrise', 'sunset'] as $event) {
        $hhmm = $times[$event];
        if ($hhmm === null) {
            ok("$event on $date is present ($label)", false, 'null');
            continue;
        }

        $atEvent = Sky::snapshot(new DateTimeImmutable($date . ' ' . $hhmm . ':00', $budapest));
        ok(
            sprintf('%s on %s (%s) is golden', $event, $date, $hhmm),
            $atEvent['sky']['phase'] === 'golden',
            sprintf(
                'phase=%s alt=%.2f blend=%.3f (%s)',
                $atEvent['sky']['phase'],
                $atEvent['sun']['altitude_deg'],
                $atEvent['sky']['blend'],
                $label
            )
        );
    }
}

// And the published altitude at sunset is the ~-0.5 deg the contract now states, not -1.15.
$sunsetTimes = Sky::snapshot(new DateTimeImmutable('2026-08-17 12:00:00', $budapest))['times'];
$atSunset = Sky::snapshot(new DateTimeImmutable('2026-08-17 ' . $sunsetTimes['sunset'] . ':00', $budapest));
near('published Sun altitude at sunset 2026-08-17', $atSunset['sun']['altitude_deg'], -0.5, 0.25, ' deg');

// 13.7 Nothing above the horizon may have moved: the Horizons-checked sky is untouched.
near(
    'above the horizon refraction is unchanged (h=+10)',
    AstroMath::refractionDeg(10.0),
    1.02 / tan(deg2rad(10.0 + 10.3 / (10.0 + 5.11))) / 60.0 + 0.0019279 / 60.0,
    1e-12,
    ' deg'
);

// ---------------------------------------------------------------------------
section('14. BUG-0009 — time zone offset validation and the ambiguous DST hour');
// ---------------------------------------------------------------------------

/*
 * "+9999" used to reach DateTimeZone, which throws DateInvalidTimeZoneException (PHP >= 8.3).
 * That is not an InvalidArgumentException, so api/sky.php answered 500 with an empty body.
 * "+2400" and "+0099" were worse: accepted, and answered for a different instant.
 */
$invalidOffsets = [
    '2026-08-17T20:31:00+9999' => 'offset far out of range (the 500 in the report)',
    '2026-08-17T20:31:00-9999' => 'negative offset far out of range',
    '2026-08-17T20:31:00+2400' => 'offset 24 h - was silently accepted as a different day',
    '2026-08-17T20:31:00+0099' => 'minute part 99 - was silently read as +01:39',
    '2026-08-17T20:31:00+0060' => 'minute part 60 - was silently read as +01:00',
    '2026-08-17T20:31:00+1401' => 'one minute wider than the widest real offset',
    '2026-08-17T20:31:00-14:01' => 'same, negative, colon form',
    '2026-08-17T20:31:00+99:99' => 'out of range, colon form',
];

foreach ($invalidOffsets as $input => $why) {
    throwsInvalidArgument("rejects t=$input ($why)", static function () use ($input, $budapest): void {
        RequestTime::parse($input, $budapest);
    });
}

$validOffsets = [
    ['2026-08-17T20:31:00+14:00', '2026-08-17 06:31:00'],
    ['2026-08-17T20:31:00-14:00', '2026-08-18 10:31:00'],
    ['2026-08-17T20:31:00+1400', '2026-08-17 06:31:00'],
    ['2026-08-17T20:31:00+05:30', '2026-08-17 15:01:00'],
    ['2026-08-17T20:31:00-0530', '2026-08-18 02:01:00'],
    ['2026-08-17T20:31:00+00:00', '2026-08-17 20:31:00'],
];

foreach ($validOffsets as [$input, $expectedUtc]) {
    try {
        $parsed = RequestTime::parse($input, $budapest)->setTimezone(new DateTimeZone('UTC'));
        ok(
            "accepts a real offset t=$input",
            $parsed->format('Y-m-d H:i:s') === $expectedUtc,
            'parsed(UTC)=' . $parsed->format('Y-m-d H:i:s') . ' expected=' . $expectedUtc
        );
    } catch (Throwable $e) {
        ok("accepts a real offset t=$input", false, 'threw: ' . $e->getMessage());
    }
}

// The parse step must never raise anything other than InvalidArgumentException - that is what
// api/sky.php turns into a 400. Anything else is how the 500 with the empty body happened.
$onlyInvalidArgument = true;
$leaked = '';
foreach (array_merge(array_keys($invalidOffsets), [
    '2026-08-17T20:31:00+0000', '2026-08-17T20:31:00Z', '2026-02-29', '2026-08-17T20:31:00+13:59',
    '0000-00-00', '2026-08-17T20:31:00+00:60', '1900-01-01', '2100-01-01',
]) as $input) {
    try {
        RequestTime::parse($input, $budapest);
    } catch (InvalidArgumentException) {
        // expected shape of a client error
    } catch (Throwable $e) {
        $onlyInvalidArgument = false;
        $leaked = $input . ' -> ' . $e::class;
        break;
    }
}
ok('the parser only ever throws InvalidArgumentException', $onlyInvalidArgument, $leaked === '' ? '16 inputs, no other throwable' : $leaked);

// S3-2: the hour that exists twice. Documented in the contract as the SECOND (winter) reading.
// BUG-0015: until the fix these two assertions were green at 23:30 and red at 00:05 - section 15
// runs them again under simulated server clocks so that can never be a matter of luck again.
$ambiguous = RequestTime::parse('2026-10-25T02:30', $budapest);
ok(
    'the ambiguous autumn hour resolves to the winter (+01:00) reading',
    $ambiguous->format('P') === '+01:00',
    'offset=' . $ambiguous->format('P') . ' utc=' . $ambiguous->setTimezone(new DateTimeZone('UTC'))->format('H:i')
);

$firstOccurrence = RequestTime::parse('2026-10-25T02:30:00+02:00', $budapest);
ok(
    'the first occurrence is still reachable with an explicit offset',
    $ambiguous->getTimestamp() - $firstOccurrence->getTimestamp() === 3600,
    sprintf('difference = %d s (expected 3600)', $ambiguous->getTimestamp() - $firstOccurrence->getTimestamp())
);

// ---------------------------------------------------------------------------
section('15. BUG-0015 — ?t= must not depend on the server clock');
// ---------------------------------------------------------------------------

/*
 * The two assertions above used to be green at 23:30 and red at 00:05 on the very same code,
 * because RequestTime built its base from (new DateTimeImmutable('now', $zone))->setDate(...):
 * setDate() keeps the current time of day, and the DST flag of that intermediate value decided
 * how the ambiguous hour was resolved. A test that only looks at the current moment cannot see
 * that - which is exactly why it took a frontend developer working past midnight to find it.
 *
 * This section closes the hole three ways:
 *   15.1  the expected instants come from tzdata (DateTimeZone::getTransitions), not from the
 *         parser, so a parser that is confidently wrong cannot agree with itself;
 *   15.2  both edges of both transitions, so the gap and the doubled hour are pinned;
 *   15.3  the same assertions run again in child processes with a SIMULATED server clock;
 *   15.4  a structural guard: RequestTime must not read a clock at all.
 */

// 15.1 Independent reference: read the autumn transition straight out of the zone database.
$autumnTransitions = array_values(array_filter(
    $budapest->getTransitions(
        (new DateTimeImmutable('2026-10-20T00:00:00', new DateTimeZone('UTC')))->getTimestamp(),
        (new DateTimeImmutable('2026-10-30T00:00:00', new DateTimeZone('UTC')))->getTimestamp()
    ),
    static fn (array $t): bool => $t['isdst'] === false && $t['offset'] === 3600
));

ok(
    'tzdata really does have a fall-back transition on 2026-10-25',
    count($autumnTransitions) === 1,
    isset($autumnTransitions[0])
        ? 'ts=' . $autumnTransitions[0]['ts'] . ' (' . $autumnTransitions[0]['time'] . ') -> ' . $autumnTransitions[0]['abbr']
        : 'no CET transition found in the window'
);

// At the transition instant the local clock reads 03:00 CEST = 02:00 CET. So local 02:30 exists
// 30 minutes BEFORE it (first, +02:00) and 30 minutes AFTER it (second, +01:00). Neither number
// comes from RequestTime.
$fallBackTs = $autumnTransitions[0]['ts'] ?? 0;
$expectedSecond = $fallBackTs + 1800;
$expectedFirst = $fallBackTs - 1800;

$parsedSecond = RequestTime::parse('2026-10-25T02:30', $budapest);
$parsedFirst = RequestTime::parse('2026-10-25T02:30:00+02:00', $budapest);

ok(
    'the ambiguous hour lands on the instant tzdata calls the second occurrence',
    $parsedSecond->getTimestamp() === $expectedSecond,
    sprintf('parsed=%d expected=%d (%s)', $parsedSecond->getTimestamp(), $expectedSecond, $parsedSecond->format('c'))
);

ok(
    'the explicit +02:00 form lands on the instant tzdata calls the first occurrence',
    $parsedFirst->getTimestamp() === $expectedFirst,
    sprintf('parsed=%d expected=%d (%s)', $parsedFirst->getTimestamp(), $expectedFirst, $parsedFirst->format('c'))
);

// 15.2 Both edges of both transitions. The gap (spring) must be rejected, the doubled hour
// (autumn) must resolve to standard time, and the minutes on either side must be untouched.
$dstEdges = [
    // input,                     expected offset or null = must be rejected
    ['2026-03-29T01:59:59', '+01:00'],  // last instant before the spring gap
    ['2026-03-29T02:00:00', null],      // first instant inside the gap
    ['2026-03-29T02:30:00', null],      // the middle of the gap (the reported case)
    ['2026-03-29T02:59:59', null],      // last instant inside the gap
    ['2026-03-29T03:00:00', '+02:00'],  // first instant after the gap
    ['2026-10-25T01:59:59', '+02:00'],  // still unambiguous summer time
    ['2026-10-25T02:00:00', '+01:00'],  // first instant of the doubled hour -> second occurrence
    ['2026-10-25T02:59:59', '+01:00'],  // last instant of the doubled hour -> second occurrence
    ['2026-10-25T03:00:00', '+01:00'],  // unambiguous again
];

foreach ($dstEdges as [$input, $expectedOffset]) {
    try {
        $edge = RequestTime::parse($input, $budapest);
        ok(
            sprintf('DST edge t=%s -> %s', $input, $expectedOffset ?? 'rejected'),
            $expectedOffset !== null && $edge->format('P') === $expectedOffset,
            'offset=' . $edge->format('P') . ' utc=' . $edge->setTimezone(new DateTimeZone('UTC'))->format('H:i:s')
        );
    } catch (InvalidArgumentException $e) {
        ok(
            sprintf('DST edge t=%s -> %s', $input, $expectedOffset ?? 'rejected'),
            $expectedOffset === null,
            'rejected: ' . $e->getMessage()
        );
    }
}

// 15.3 The same questions, asked again from child processes whose clock is somewhere else.
// This is the only assertion in the suite that actually moves the server clock; everything else
// can only ever see the moment it happens to run at.
$faketime = trim((string) (@shell_exec('command -v faketime 2>/dev/null') ?? ''));

if ($faketime === '') {
    record(
        false,
        'a simulated server clock is available (faketime)',
        'faketime not found - install it with: sudo apt-get install -y faketime. Without it the '
        . 'clock-dependence of ?t= cannot be tested, and BUG-0015 could come back unnoticed.'
    );
} else {
    record(true, 'a simulated server clock is available (faketime)', $faketime);

    /*
     * Each clock is a moment that used to give a different answer:
     *   00:05  the window the bug report was filed from (00:00-02:59 answered +02:00)
     *   12:00  the middle of the day, where the suite always looked green
     *   23:30  the moment the previous agent ran the suite at
     *   the ambiguous hour itself, as the server clock - the nastiest base the old code could get
     *   the day of the spring transition
     */
    $simulatedClocks = [
        '2026-08-18 00:05:00',
        '2026-08-18 12:00:00',
        '2026-08-18 23:30:00',
        '2026-10-25 02:30:00',
        '2026-03-29 12:00:00',
    ];

    $childCode = <<<'PHP'
        require_once %s;
        $z = new DateTimeZone('Europe/Budapest');
        $out = ['clock' => (new DateTimeImmutable('now', $z))->format('Y-m-d H:i')];
        foreach (['2026-10-25T02:30', '2026-10-25T02:30:00+02:00', '2026-03-29T02:30'] as $in) {
            try {
                $r = \Sky\RequestTime::parse($in, $z);
                $out[$in] = ['offset' => $r->format('P'), 'ts' => $r->getTimestamp()];
            } catch (InvalidArgumentException $e) {
                $out[$in] = ['rejected' => true];
            }
        }
        echo json_encode($out);
        PHP;

    $childCode = sprintf($childCode, var_export(__DIR__ . '/../src/bootstrap.php', true));

    foreach ($simulatedClocks as $clock) {
        /*
         * -f '@<date>' is an ABSOLUTE fake time, and the env is scrubbed first: plain
         * `faketime "<date>"` computes an offset from the time it can see, so if this suite is
         * itself already running under faketime the children would silently inherit that offset
         * and every clock in the matrix would collapse back onto the real one. (That is not a
         * hypothetical - it happened while writing this, and the "really took effect" assertion
         * below is what caught it.)
         */
        $command = sprintf(
            'env -u LD_PRELOAD -u FAKETIME -u FAKETIME_FMT -u FAKETIME_DID_REEXEC '
            . 'faketime -f %s %s -r %s 2>/dev/null',
            escapeshellarg('@' . $clock),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($childCode)
        );

        /** @var array<string, mixed>|null $child */
        $child = json_decode((string) (@shell_exec($command) ?? ''), true);

        if (!is_array($child)) {
            record(false, "server clock $clock: the child process answered", 'no parsable output');

            continue;
        }

        // Without this the whole matrix could be five copies of the same real clock.
        ok(
            "server clock $clock: the simulated clock really took effect",
            $child['clock'] === substr($clock, 0, 16),
            'the child saw ' . $child['clock']
        );

        ok(
            "server clock $clock: the ambiguous autumn hour resolves to the winter (+01:00) reading",
            ($child['2026-10-25T02:30']['offset'] ?? null) === '+01:00'
                && ($child['2026-10-25T02:30']['ts'] ?? null) === $expectedSecond,
            'offset=' . ($child['2026-10-25T02:30']['offset'] ?? 'rejected')
                . ' ts=' . ($child['2026-10-25T02:30']['ts'] ?? '-') . ' expected=' . $expectedSecond
        );

        ok(
            "server clock $clock: the first occurrence is still reachable with an explicit offset",
            isset($child['2026-10-25T02:30']['ts'], $child['2026-10-25T02:30:00+02:00']['ts'])
                && $child['2026-10-25T02:30']['ts'] - $child['2026-10-25T02:30:00+02:00']['ts'] === 3600,
            sprintf(
                'difference = %s s (expected 3600)',
                isset($child['2026-10-25T02:30']['ts'], $child['2026-10-25T02:30:00+02:00']['ts'])
                    ? (string) ($child['2026-10-25T02:30']['ts'] - $child['2026-10-25T02:30:00+02:00']['ts'])
                    : '-'
            )
        );

        ok(
            "server clock $clock: the non-existent spring hour is still rejected",
            ($child['2026-03-29T02:30']['rejected'] ?? false) === true,
            'answer: ' . json_encode($child['2026-03-29T02:30'])
        );
    }
}

// 15.4 Structural guard. The behavioural checks above can only run where faketime exists; this
// one runs everywhere and fails on the old code at any hour of the day. Comments are skipped on
// purpose - the docblock in RequestTime quotes the broken line so it does not get reintroduced.
$requestTimeTokens = token_get_all((string) file_get_contents(__DIR__ . '/../src/RequestTime.php'));
$clockReads = [];

foreach ($requestTimeTokens as $token) {
    if (!is_array($token)) {
        continue;
    }

    [$id, $text] = $token;

    if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
        continue;
    }

    if ($id === T_CONSTANT_ENCAPSED_STRING
        && in_array(strtolower(trim($text, '\'"')), ['now', 'today', 'tomorrow', 'yesterday'], true)) {
        $clockReads[] = $text;
    }

    if ($id === T_STRING
        && in_array(strtolower($text), ['time', 'mktime', 'strtotime', 'microtime', 'hrtime', 'gettimeofday', 'getdate'], true)) {
        $clockReads[] = $text . '()';
    }
}

ok(
    'RequestTime reads no clock at all, so ?t= cannot depend on one',
    $clockReads === [],
    $clockReads === [] ? 'no clock read outside comments' : 'found: ' . implode(', ', $clockReads)
);

// ---------------------------------------------------------------------------
section('12. Performance (brief: the response must be generated under 200 ms)');
// ---------------------------------------------------------------------------

$start = hrtime(true);
for ($i = 0; $i < 10; $i++) {
    Sky::snapshot(new DateTimeImmutable('now', $budapest));
}
$averageMs = (hrtime(true) - $start) / 1e6 / 10.0;
record($averageMs < 200.0, 'Sky::snapshot() average over 10 runs', sprintf('%.1f ms (limit 200 ms)', $averageMs));

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\n" . str_repeat('=', 78) . "\n";
printf("TOTAL: %d passed, %d failed\n", Result::$passed, Result::$failed);

if (Result::$failed > 0) {
    echo "\nFAILURES:\n";
    foreach (Result::$failures as $failure) {
        echo '  - ' . $failure . "\n";
    }
}
echo str_repeat('=', 78) . "\n";

exit(Result::$failed === 0 ? 0 : 1);
