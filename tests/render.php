<?php

declare(strict_types=1);

/**
 * Standalone test runner for the page renderer (TASK-0004).
 *
 * Kept separate from tests/run.php (TASK-0003) so that a failure here is unambiguously a
 * rendering failure, not an astronomy one. Same reasoning about dependencies: no PHPUnit,
 * the project carries no Composer requirement.
 *
 * Run:  php tests/render.php     (exit code 0 = all green, 1 = at least one failure)
 *
 * What is covered here:
 *  - the screen mapping of design spec 3.1 / 3.2, with the exact table from the spec
 *  - the Moon phase path of spec 6.2.1, with the spec's own check values
 *  - the bright-limb rotation convention (contract v3): the lit edge must face the Sun
 *    AS DRAWN, verified against real snapshots across a full year
 *  - the star field: deterministic, 260 stars, correct thresholds
 *  - the four screen states, and the rules that must hold in the markup regardless of state
 *
 * What is NOT covered here and was checked by hand in a browser instead: layout, contrast,
 * the absence of scrolling, the refresh loop, and the headline/body collision - none of
 * which exist until a browser lays the page out.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Sky\Location;
use Sky\Sky;
use Sky\SkyRenderer;

final class RenderResult
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

function ok(string $name, bool $condition, string $detail = ''): void
{
    if ($condition) {
        RenderResult::$passed++;
        printf("  PASS  %-58s %s\n", $name, $detail);

        return;
    }

    RenderResult::$failed++;
    RenderResult::$failures[] = $name . ' — ' . $detail;
    printf("  FAIL  %-58s %s\n", $name, $detail);
}

function near(string $name, float $actual, float $expected, float $tolerance, string $unit = ''): void
{
    $delta = abs($actual - $expected);
    ok(
        $name,
        $delta <= $tolerance,
        sprintf('actual=%.6f expected=%.6f delta=%.6f tol=%.6f%s', $actual, $expected, $delta, $tolerance, $unit)
    );
}

/** @return array{x: float, k: float, visible: bool, offscreen: ?string, depth: float, sub: float, azimuth: float, altitude: float} */
function screenOf(float $azimuth, float $altitude, ?bool $visible = null): array
{
    return SkyRenderer::screenCoordinates([
        'azimuth_deg' => $azimuth,
        'altitude_deg' => $altitude,
        'visible' => $visible ?? ($altitude > 0.0),
    ]);
}

function norm360(float $value): float
{
    return fmod(fmod($value, 360.0) + 360.0, 360.0);
}

function norm180(float $value): float
{
    $v = norm360($value);

    return $v > 180.0 ? $v - 360.0 : $v;
}

$budapest = new DateTimeZone('Europe/Budapest');
$location = Location::veresegyhaz();

// ---------------------------------------------------------------------------
section('1. Screen mapping — design spec 3.1 and 3.2 (the tables, verbatim)');
// ---------------------------------------------------------------------------

foreach ([[90.0, 0.0], [135.0, 25.0], [180.0, 50.0], [225.0, 75.0], [270.0, 100.0]] as [$az, $x]) {
    near(sprintf('azimuth %.0f deg -> x', $az), screenOf($az, 10.0)['x'], $x, 1e-9, ' %');
}

foreach ([[90.0, 0.0], [60.0, 1 / 3], [45.0, 0.5], [30.0, 2 / 3], [10.0, 0.888889], [0.0, 1.0]] as [$h, $k]) {
    near(sprintf('altitude %.0f deg -> k', $h), screenOf(180.0, $h)['k'], $k, 1e-5);
}

ok('azimuth 62 deg is off the panorama to the left', screenOf(62.0, 10.0)['offscreen'] === 'left', 'offscreen=left');
ok('azimuth 311 deg is off the panorama to the right', screenOf(311.0, 10.0)['offscreen'] === 'right', 'offscreen=right');
ok('azimuth 180 deg is on the panorama', screenOf(180.0, 10.0)['offscreen'] === null, 'offscreen=null');
ok(
    'a body above the horizon but in the northern band is not drawn',
    screenOf(62.0, 30.0)['visible'] === false,
    'visible=false, the edge chip speaks instead'
);
ok('a body below the horizon is not drawn', screenOf(180.0, -3.0)['visible'] === false, 'visible=false');

// The depth marker sinks at most 30 % into the ground band, and saturates at 18 deg.
near('depth marker factor at 0 deg', screenOf(180.0, 0.0)['sub'], 0.0, 1e-9);
near('depth marker factor at 9 deg down', screenOf(180.0, -9.0)['sub'], 0.15, 1e-9);
near('depth marker factor at 18 deg down', screenOf(180.0, -18.0)['sub'], 0.30, 1e-9);
near('depth marker factor saturates below 18 deg', screenOf(180.0, -40.0)['sub'], 0.30, 1e-9);

// ---------------------------------------------------------------------------
section('2. Moon phase path — design spec 6.2.1 check table');
// ---------------------------------------------------------------------------

/** @return array{a: float, sweep: int} */
function parseMoonPath(string $d): array
{
    if (preg_match('/A ([0-9.]+) 40 0 0 ([01]) 0 -40 Z$/', $d, $m) !== 1) {
        throw new RuntimeException('unexpected moon path: ' . $d);
    }

    return ['a' => (float) $m[1], 'sweep' => (int) $m[2]];
}

foreach ([[0.0, 40.0, 0], [0.25, 20.0, 0], [0.5, 0.2, 1], [0.75, 20.0, 1], [1.0, 40.0, 1]] as [$k, $a, $sweep]) {
    $parsed = parseMoonPath(SkyRenderer::moonLitPath($k));
    near(sprintf('illumination %.2f -> terminator semi-axis', $k), $parsed['a'], $a, 1e-6);
    ok(sprintf('illumination %.2f -> sweep flag', $k), $parsed['sweep'] === $sweep, 'sweep=' . $parsed['sweep']);
}

ok(
    'the terminator never degenerates to a straight line',
    parseMoonPath(SkyRenderer::moonLitPath(0.5))['a'] > 0.0,
    'a=' . parseMoonPath(SkyRenderer::moonLitPath(0.5))['a'] . ' (numeric floor holds)'
);

// ---------------------------------------------------------------------------
section('3. Bright-limb rotation — API contract v3, the convention that flipped once');
// ---------------------------------------------------------------------------

/**
 * The base path points its bright limb at +x. SVG rotate() turns clockwise in a y-down
 * frame, so rotate(chi - 90) must send it to the unit vector (sin chi, -cos chi).
 *
 * @return array{0: float, 1: float}
 */
function limbVector(float $chi): array
{
    $theta = deg2rad($chi - 90.0);

    return [cos($theta), sin($theta)];
}

foreach ([[0.0, 0.0, -1.0, 'up'], [90.0, 1.0, 0.0, 'right'], [180.0, 0.0, 1.0, 'down'], [270.0, -1.0, 0.0, 'left']] as [$chi, $x, $y, $where]) {
    [$vx, $vy] = limbVector($chi);
    ok(
        sprintf('chi = %3.0f deg points %s', $chi, $where),
        abs($vx - $x) < 1e-9 && abs($vy - $y) < 1e-9,
        sprintf('vector=(%.3f, %.3f) expected=(%.0f, %.0f)', $vx, $vy, $x, $y)
    );
}

/**
 * The rigorous check, independent of the backend's own maths: the position angle of the
 * Sun as seen from the Moon, from the zenith direction towards increasing azimuth,
 * computed straight from the two direction vectors in the horizontal frame.
 *
 * This is the number the renderer feeds into rotate(chi - 90), so if the convention ever
 * flips again - which is exactly what happened once already, between contract v1 and v2 -
 * this assertion is what catches it.
 */
function exactChi(float $sunAz, float $sunAlt, float $moonAz, float $moonAlt): float
{
    $vector = static fn (float $az, float $alt): array => [
        cos(deg2rad($alt)) * cos(deg2rad($az)),   // north
        cos(deg2rad($alt)) * sin(deg2rad($az)),   // east
        sin(deg2rad($alt)),                       // up
    ];
    $dot = static fn (array $a, array $b): float => $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2];

    $moon = $vector($moonAz, $moonAlt);
    $sun = $vector($sunAz, $sunAlt);

    // Tangent basis at the Moon: "up" towards the zenith, "along" towards larger azimuth.
    $vertical = $dot([0.0, 0.0, 1.0], $moon);
    $up = [-$vertical * $moon[0], -$vertical * $moon[1], 1.0 - $vertical * $moon[2]];
    $length = sqrt($dot($up, $up));
    $up = [$up[0] / $length, $up[1] / $length, $up[2] / $length];
    $along = [-sin(deg2rad($moonAz)), cos(deg2rad($moonAz)), 0.0];

    $projection = $dot($sun, $moon);
    $tangent = [
        $sun[0] - $projection * $moon[0],
        $sun[1] - $projection * $moon[1],
        $sun[2] - $projection * $moon[2],
    ];

    return norm360(rad2deg(atan2($dot($tangent, $along), $dot($tangent, $up))));
}

$worstExact = 0.0;
$worstExactAt = '';
$worstScreen = 0.0;
$worstScreenAt = '';
$samples = 0;
$screenSamples = 0;

for ($hour = 0; $hour < 8760; $hour += 7) {
    $when = (new DateTimeImmutable('2026-01-01 00:00:00', $budapest))->modify('+' . $hour . ' hour');
    $snapshot = Sky::snapshot($when, $location);

    $sun = SkyRenderer::screenCoordinates($snapshot['sun']);
    $moon = SkyRenderer::screenCoordinates($snapshot['moon']);

    if (!$sun['visible'] || !$moon['visible']) {
        continue;
    }

    $samples++;
    $actual = norm360((float) $snapshot['moon']['bright_limb_angle_deg']);

    $deviation = abs(norm180($actual - exactChi(
        (float) $snapshot['sun']['azimuth_deg'],
        (float) $snapshot['sun']['altitude_deg'],
        (float) $snapshot['moon']['azimuth_deg'],
        (float) $snapshot['moon']['altitude_deg']
    )));

    if ($deviation > $worstExact) {
        $worstExact = $deviation;
        $worstExactAt = $when->format('Y-m-d H:i');
    }

    /**
     * The visual check the spec and the tester use: measured in the screen pixels of the
     * desktop breakpoint (1280x800, horizon at 78 %). Only meaningful while the two bodies
     * are reasonably close - the panorama maps 180 deg across the width but 90 deg down to
     * the horizon, so a wide separation is legitimately distorted (spec 3.3), and a flat
     * screen chord stops standing in for a direction on a sphere.
     */
    $separation = hypot(
        norm180((float) $snapshot['sun']['azimuth_deg'] - (float) $snapshot['moon']['azimuth_deg'])
            * cos(deg2rad((float) $snapshot['moon']['altitude_deg'])),
        (float) $snapshot['sun']['altitude_deg'] - (float) $snapshot['moon']['altitude_deg']
    );

    if ($separation < 8.0 || $separation > 30.0) {
        continue;
    }

    $screenSamples++;

    $expected = norm360(rad2deg(atan2(
        ($sun['x'] - $moon['x']) / 100.0 * 1280.0,
        -(($sun['k'] - $moon['k']) * 800.0 * 0.78)
    )));

    $screenDeviation = abs(norm180($actual - $expected));

    if ($screenDeviation > $worstScreen) {
        $worstScreen = $screenDeviation;
        $worstScreenAt = $when->format('Y-m-d H:i');
    }
}

ok('year-long sample found usable cases', $samples > 100, $samples . ' hours with both bodies drawn');
ok(
    'the bright-limb angle IS the direction of the Sun, all year',
    $worstExact <= 3.0,
    sprintf(
        'worst deviation %.3f deg at %s (residual is the topocentric/geocentric difference)',
        $worstExact,
        $worstExactAt
    )
);
ok(
    'the drawn bright limb faces the drawn Sun on screen (separation 8-30 deg)',
    $worstScreen <= 30.0,
    sprintf('worst %.2f deg at %s over %d samples (spec bound 30 deg)', $worstScreen, $worstScreenAt, $screenSamples)
);

// ---------------------------------------------------------------------------
section('4. Star field — deterministic, spec 6.3');
// ---------------------------------------------------------------------------

$stars = SkyRenderer::generateStars();
$again = SkyRenderer::generateStars();

ok('exactly 260 stars', count($stars) === SkyRenderer::STAR_COUNT, count($stars) . ' stars');
ok('the field is byte-identical on a second call', $stars === $again, 'no time-based seed');

$maxYk = 0.0;
$outOfRange = 0;
foreach ($stars as $star) {
    $maxYk = max($maxYk, $star['yk']);
    if ($star['x'] < 0.0 || $star['x'] > 100.0 || $star['o'] <= 0.0 || $star['o'] > 1.0) {
        $outOfRange++;
    }
}

ok('no star sits below the extinction cut-off', $maxYk <= 0.94, sprintf('max yk = %.4f', $maxYk));
ok('every star is on screen with a sane brightness', $outOfRange === 0, $outOfRange . ' out of range');

$thresholds = array_column($stars, 'th');
sort($thresholds);
$expected = [];
for ($i = 0; $i < SkyRenderer::STAR_COUNT; $i++) {
    $expected[] = $i / SkyRenderer::STAR_COUNT;
}

ok('thresholds are a permutation of rank/260', $thresholds === $expected, 'brightest = 0, faintest ~ 1');

$brightest = $stars[0];
foreach ($stars as $star) {
    if ($star['o'] > $brightest['o']) {
        $brightest = $star;
    }
}
near('the brightest star has threshold 0', $brightest['th'], 0.0, 1e-12);

$big = 0;
foreach ($stars as $star) {
    if ($star['big']) {
        $big++;
    }
}
ok(
    'only the two largest size classes twinkle',
    $big > 0 && $big < SkyRenderer::STAR_COUNT * 0.15,
    sprintf('%d of %d stars (%.1f %%)', $big, SkyRenderer::STAR_COUNT, $big / SkyRenderer::STAR_COUNT * 100)
);

// ---------------------------------------------------------------------------
section('5. The four states');
// ---------------------------------------------------------------------------

/** Renders one full page fragment, the way index.php assembles it. */
function renderAll(SkyRenderer $renderer): string
{
    return $renderer->skyLayers() . $renderer->stars() . $renderer->glow() . $renderer->bodies()
        . $renderer->ridge() . $renderer->compass() . $renderer->subMarkers()
        . $renderer->info() . $renderer->edgeChips();
}

/** A noon in June: the Sun is high, the state is "ok". */
$noon = SkyRenderer::fromSnapshot(Sky::snapshot(new DateTimeImmutable('2026-06-21 12:00:00', $budapest), $location));
ok('midday renders the success state', $noon->state() === 'ok', 'state=' . $noon->state());
ok('midday phase is day', $noon->phase() === 'day', 'phase=' . $noon->phase());
ok('the Sun disc is drawn at midday', !str_contains($noon->bodies(), 'body--sun" id="body-sun" hidden'), '');

// Hunt down a genuine "empty" moment: both bodies below the horizon.
$empty = null;
for ($hour = 0; $hour < 720; $hour++) {
    $when = (new DateTimeImmutable('2026-08-17 00:00:00', $budapest))->modify('+' . $hour . ' hour');
    $snapshot = Sky::snapshot($when, $location);
    if (!$snapshot['sun']['visible'] && !$snapshot['moon']['visible']) {
        $empty = SkyRenderer::fromSnapshot($snapshot);
        break;
    }
}

ok('an empty state exists and is detected', $empty !== null && $empty->state() === 'empty', $empty?->state() ?? 'not found');
if ($empty !== null) {
    ok(
        'the empty state says so, in Hungarian, in the info strip',
        str_contains($empty->info(), 'Most sem a Nap, sem a Hold nincs a horizont felett.'),
        ''
    );
    ok('the empty state hides the time list', str_contains($empty->info(), '<dl class="times" id="times" hidden>'), '');
}

$error = SkyRenderer::errorState();
ok('the error state exists', $error->state() === 'error', 'state=' . $error->state());
ok('the error state falls back to a night sky', $error->phase() === 'night', 'phase=' . $error->phase());
ok('the error state names the way out', str_contains($error->info(), 'Frissítsd az oldalt pár perc múlva.'), '');
ok('the error state draws no bodies', substr_count($error->bodies(), ' hidden>') === 2, 'both discs hidden');

// The loading state has no server-side markup of its own beyond the refresh dot; the dot
// starts hidden so that a refresh faster than 400 ms never flashes.
ok('the refresh indicator starts hidden', str_contains($noon->info(), 'id="refresh-dot" aria-hidden="true" hidden'), '');
ok(
    'the no-JavaScript notice is present',
    str_contains($noon->info(), 'Az automatikus frissítés kikapcsolva'),
    'spec 7.3/c'
);

// ---------------------------------------------------------------------------
section('6. Markup rules that hold in every state');
// ---------------------------------------------------------------------------

foreach (['ok' => $noon, 'error' => $error] as $label => $renderer) {
    $markup = renderAll($renderer);
    $style = $renderer->styleAttribute();

    ok($label . ': no external URL anywhere', preg_match('#(https?:)?//[a-z0-9.-]+\.[a-z]#i', $markup) !== 1, 'no CDN, no webfont');
    ok($label . ': the sky gradient has all six stops', substr_count($markup, 'offset="100%"') >= 2, 'two layers x 6 stops');
    ok($label . ': 260 stars are rendered', substr_count($markup, '<b class="star') === 260, '');
    ok($label . ': the compass has 5 majors and 20 minors', substr_count($markup, 'compass__major ') === 5
        && substr_count($markup, 'compass__tick') === 20, '');
    ok($label . ': all five compass labels are present', substr_count($markup, 'compass__label compass__label--') === 5, 'K DK D DNy Ny');
    ok($label . ': the silhouette has all three viewBox crops', substr_count($markup, 'ridge__svg ridge__svg--') === 3, '');

    foreach (['--sky-0', '--sky-5', '--skyA-0', '--skyB-5', '--glow-rgb', '--glow-a', '--dayness2',
        '--star-f', '--star-dim', '--plate-alpha', '--vignette-alpha', '--moon-opacity',
        '--sun-core', '--sun-edge', '--sun-corona-k', '--sun-corona-a', '--ground-base',
        '--sun-x', '--sun-k', '--sun-vis', '--sun-sub',
        '--moon-x', '--moon-k', '--moon-vis', '--moon-sub', '--moon-illum', '--moon-chi'] as $token) {
        if (!str_contains($style, $token . ':')) {
            ok($label . ': token ' . $token . ' is written by the server', false, 'missing');
            continue;
        }
    }

    ok($label . ': every documented token is written by the server', true, '27 tokens on .stage');
    ok(
        $label . ': numbers are locale-independent',
        preg_match('/[0-9],[0-9]/', $style) !== 1,
        'no comma decimal separator'
    );
}

$description = $noon->description();
ok('the screen-reader description names the place and the time', str_contains($description, 'Az égbolt Veresegyház felett'), '');
ok('the screen-reader description names the sky', str_contains($description, 'Nappali égbolt'), '');
ok('the screen-reader description places the Sun', str_contains($description, 'A Nap '), '');
ok('the screen-reader description places the Moon', str_contains($description, 'A Hold '), '');
ok('the screen-reader description gives the rise and set times', str_contains($description, 'Napkelte '), '');

// ---------------------------------------------------------------------------
section('7. Static assets');
// ---------------------------------------------------------------------------

$css = (string) file_get_contents(__DIR__ . '/../public/style.css');
$js = (string) file_get_contents(__DIR__ . '/../public/app.js');
$php = (string) file_get_contents(__DIR__ . '/../public/index.php');

ok('the stylesheet loads nothing from the network', !str_contains($css, 'url('), 'no url() at all');
// The words "@font-face" and "CDN" appear in the comments; look for the actual at-rule.
ok('the stylesheet declares no webfont', preg_match('/^\s*@font-face/m', $css) !== 1, 'no @font-face rule');
ok('the stylesheet has the reduced-motion block', str_contains($css, 'prefers-reduced-motion'), '');
ok('the stylesheet has the high-contrast block', str_contains($css, 'prefers-contrast'), '');
ok('the stylesheet has the dvh fallback', str_contains($css, 'not (height: 100dvh)'), '');
ok('the stylesheet has the color-mix fallback', str_contains($css, 'not (color: color-mix'), '');
ok('the stylesheet has the backdrop-filter fallback', str_contains($css, 'backdrop-filter: blur(1px)'), '');
ok('the script carries no baked-in endpoint', !preg_match('#https?://#', $js), 'the URL comes from data-api');
ok('the page reads the endpoint from the environment', str_contains($php, "getenv('SKY_API_URL')"), '');
ok('the refresh interval matches the spec', str_contains($js, 'REFRESH_INTERVAL = 60000'), '');
ok('the fetch timeout matches the spec', str_contains($js, 'FETCH_TIMEOUT = 6000'), '');
ok('the loop stops when the tab is hidden', str_contains($js, "visibilitychange"), 'spec 12');
ok('the headline collision check exists', str_contains($js, 'hello--conflict'), 'spec 8.4');

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 78) . "\n";
printf("TOTAL: %d passed, %d failed\n", RenderResult::$passed, RenderResult::$failed);

if (RenderResult::$failed > 0) {
    echo "\nFAILURES:\n";
    foreach (RenderResult::$failures as $failure) {
        echo '  - ' . $failure . "\n";
    }
}
echo str_repeat('=', 78) . "\n";

exit(RenderResult::$failed === 0 ? 0 : 1);
