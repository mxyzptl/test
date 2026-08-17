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
// Nothing may claim a position in the error state: there is none. An edge chip reading
// "Nap: E-i sav . 0 deg" would be a confident lie.
ok('the error state claims no azimuth', substr_count($error->edgeChips(), ' hidden>') === 4, 'chips and bands hidden');
ok('the error state shows no depth markers', substr_count($error->subMarkers(), ' hidden>') === 4, 'markers and lines hidden');

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
section('8. Compass labels: markup AND stylesheet together (spec 6.6, BUG-0011)');
// ---------------------------------------------------------------------------

/**
 * Why this section exists at all.
 *
 * BUG-0011 survived a fully green suite. Section 6 asserts that the five compass labels are
 * in the markup, and they were - the renderer emits "DNy" and "Ny" exactly as spec 6.6
 * requires. The stylesheet, however, carried `text-transform: uppercase` on .compass__label,
 * so the visitor read "DNY" and "NY". A test that looks only at the HTML is structurally
 * incapable of seeing that class of bug: the defect lives in the other file.
 *
 * So this section asks the question the way the screen asks it - what text ends up in front
 * of the visitor? - by reading the markup and the CSS together. `text-transform` is also an
 * INHERITED property, which is why the fix declares `none` explicitly instead of merely
 * deleting the line: an `uppercase` on any ancestor would otherwise reintroduce the bug, and
 * the assertions below would not catch a silent omission.
 */

/** Spec 6.6 / 8.1 / 13: the same five strings in all three places, byte for byte. */
$expectedCompassLabels = ['K', 'DK', 'D', 'DNy', 'Ny'];

/**
 * Collect the declarations of every innermost CSS rule as [selectorList, declarations].
 * Comments are stripped first (they mention `text-transform` on purpose). The pattern also
 * reaches rules nested inside @media, because `[^{}]` cannot cross a brace.
 *
 * @return list<array{0: string, 1: string}>
 */
$cssRules = static function (string $css): array {
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $matches, PREG_SET_ORDER);

    return array_map(static fn (array $m): array => [trim($m[1]), $m[2]], $matches);
};

/** The declared value of $property in $declarations, or null. Last one wins, as in the cascade. */
$declaredValue = static function (string $declarations, string $property): ?string {
    if (preg_match_all('/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:\s*([^;}]+)/i', $declarations, $m) < 1) {
        return null;
    }

    return strtolower(trim((string) end($m[1])));
};

/** Every rule that can reach an element carrying the .compass__label class. */
$labelRules = [];
foreach ($cssRules($css) as [$selectorList, $declarations]) {
    foreach (explode(',', $selectorList) as $selector) {
        if (preg_match('/\.compass__label(?![\w-])|\.compass__label--[\w-]+/', trim($selector)) === 1) {
            $labelRules[] = [trim($selectorList), $declarations];
            break;
        }
    }
}

ok(
    'the stylesheet actually has rules for .compass__label',
    count($labelRules) >= 1,
    count($labelRules) . ' rule(s) found — if this is 0, every assertion below is vacuous'
);

/**
 * Every case-changing declaration that reaches the labels, in source order. `capitalize` and
 * `lowercase` are listed too: they are just as wrong as `uppercase` ("DNY", "dny", "Dny").
 */
$caseChangers = [];
$effectiveTransform = null;
foreach ($labelRules as [$selectorList, $declarations]) {
    $transform = $declaredValue($declarations, 'text-transform');
    if ($transform !== null) {
        $effectiveTransform = $transform;
        if ($transform !== 'none') {
            $caseChangers[] = $selectorList . ' { text-transform: ' . $transform . ' }';
        }
    }

    foreach (['font-variant-caps', 'font-variant'] as $property) {
        $variant = $declaredValue($declarations, $property);
        if ($variant !== null && (str_contains($variant, 'small-caps') || str_contains($variant, 'petite-caps')
            || str_contains($variant, 'unicase') || str_contains($variant, 'titling-caps'))) {
            $caseChangers[] = $selectorList . ' { ' . $property . ': ' . $variant . ' }';
        }
    }
}

ok(
    'no case-changing declaration reaches .compass__label (BUG-0011)',
    $caseChangers === [],
    $caseChangers === [] ? 'no text-transform/small-caps other than none' : 'found: ' . implode(' | ', $caseChangers)
);
ok(
    '.compass__label declares text-transform: none explicitly (spec 6.6)',
    $effectiveTransform === 'none',
    'effective text-transform = ' . var_export($effectiveTransform, true)
        . ' — an inherited uppercase would re-break the labels unless none is stated'
);

/** The visual intent of spec 6.6 must survive the fix: weight and tracking, not capitals. */
// Every rule selected by exactly `.compass__label` - the base one plus the landscape
// override - concatenated, so that neither can hide the other.
$baseRule = '';
foreach ($labelRules as [$selectorList, $declarations]) {
    if (trim($selectorList) === '.compass__label') {
        $baseRule .= ';' . $declarations;
    }
}
ok(
    'the visual intent is kept: font-weight 600 + letter-spacing .14em (spec 6.6)',
    $declaredValue($baseRule, 'font-weight') === 'var(--fw-semi)'
        && $declaredValue($baseRule, 'letter-spacing') === 'var(--ls-caps)',
    'font-weight=' . var_export($declaredValue($baseRule, 'font-weight'), true)
        . ' letter-spacing=' . var_export($declaredValue($baseRule, 'letter-spacing'), true)
);

$rootDeclarations = '';
foreach ($cssRules($css) as [$selectorList, $declarations]) {
    if (str_contains($selectorList, ':root')) {
        $rootDeclarations .= $declarations;
    }
}
ok(
    'the tokens behind that intent hold the spec values (--fw-semi: 600, --ls-caps: .14em)',
    $declaredValue($rootDeclarations, '--fw-semi') === '600' && $declaredValue($rootDeclarations, '--ls-caps') === '.14em',
    '--fw-semi=' . var_export($declaredValue($rootDeclarations, '--fw-semi'), true)
        . ' --ls-caps=' . var_export($declaredValue($rootDeclarations, '--ls-caps'), true)
);

/** Apply a CSS text-transform the way a browser would. */
$applyTextTransform = static function (string $text, ?string $transform): string {
    $upper = static fn (string $s): string => function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    $lower = static fn (string $s): string => function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);

    return match ($transform) {
        'uppercase' => $upper($text),
        'lowercase' => $lower($text),
        'capitalize' => function_exists('mb_convert_case')
            ? mb_convert_case($lower($text), MB_CASE_TITLE, 'UTF-8')
            : ucwords($lower($text)),
        default => $text,
    };
};

/**
 * The end-to-end assertion: take the labels out of the real markup, put the real stylesheet
 * on top of them, and compare what is left with the five strings of spec 6.6. This is the
 * one that would have failed before the fix ("DNy" -> "DNY").
 */
foreach (['ok' => $noon, 'error' => $error] as $label => $renderer) {
    preg_match_all(
        '/<span class="compass__label[^"]*"[^>]*>([^<]*)<\/span>/',
        $renderer->compass(),
        $labelMatches
    );
    $inMarkup = $labelMatches[1];

    ok(
        $label . ': the markup carries the five spec strings verbatim',
        $inMarkup === $expectedCompassLabels,
        'markup=[' . implode(' ', $inMarkup) . '] expected=[' . implode(' ', $expectedCompassLabels) . ']'
    );

    $onScreen = array_map(
        static fn (string $text): string => $applyTextTransform($text, $effectiveTransform),
        $inMarkup
    );
    ok(
        $label . ': what the visitor reads, after the stylesheet, is K DK D DNy Ny',
        $onScreen === $expectedCompassLabels,
        'on screen=[' . implode(' ', $onScreen) . '] expected=[' . implode(' ', $expectedCompassLabels) . ']'
            . ' (text-transform=' . var_export($effectiveTransform, true) . ')'
    );
    ok(
        $label . ': no all-caps digraph survives anywhere in the compass',
        !preg_match('/\bD?NY\b/', implode(' ', $onScreen)),
        'the Hungarian digraph "ny" takes a capital on its first letter only'
    );
}

/**
 * Self-check on the detector itself. An assertion that cannot fail is worth nothing, and the
 * assertions above are only as good as the little CSS parser behind them - so the parser is
 * fed known-bad stylesheets and required to reject every one of them. The fixtures are
 * literals on purpose: they must keep testing the detector even after public/style.css
 * changes, and they cover the three ways the bug could come back (the original declaration,
 * one hidden in a media query, and small-caps instead of a transform).
 */
$detectorFixtures = [
    'the original BUG-0011 declaration' => ['.compass__label { font-weight: 600; text-transform: uppercase; }', 'uppercase'],
    'a transform hidden inside a media query' => ['@media (min-width: 700px) { .compass__label { text-transform: uppercase; } }', 'uppercase'],
    'a lowercase transform' => ['.compass__label { text-transform: lowercase; }', 'lowercase'],
    'a transform behind a comment' => ["/* text-transform: none; */\n.compass__label { text-transform: capitalize; }", 'capitalize'],
];

foreach ($detectorFixtures as $what => [$fixture, $expectedDetection]) {
    $detected = null;
    foreach ($cssRules($fixture) as [$selectorList, $declarations]) {
        if (preg_match('/\.compass__label(?![\w-])/', $selectorList) === 1) {
            $detected = $declaredValue($declarations, 'text-transform') ?? $detected;
        }
    }
    ok(
        'self-check: the detector catches ' . $what,
        $detected === $expectedDetection && $applyTextTransform('DNy', $detected) !== 'DNy',
        'detected=' . var_export($detected, true) . ' -> "DNy" would render as "'
            . $applyTextTransform('DNy', $detected) . '"'
    );
}

$smallCapsFixture = '.compass__label { font-variant-caps: small-caps; }';
$smallCapsCaught = false;
foreach ($cssRules($smallCapsFixture) as [$selectorList, $declarations]) {
    $smallCapsCaught = $smallCapsCaught
        || str_contains((string) $declaredValue($declarations, 'font-variant-caps'), 'small-caps');
}
ok('self-check: the detector catches small-caps too', $smallCapsCaught, 'spec 6.6 forbids it as well');

// ---------------------------------------------------------------------------
section('9. Stylesheet rules the browser will not forgive (BUG-0013, BUG-0014)');
// ---------------------------------------------------------------------------

/**
 * What this section can and cannot do.
 *
 * It CANNOT prove that `prefers-contrast: more` makes the plate darker. Only a browser can:
 * the cascade, the media query and the custom-property cycle all live in the engine, and
 * BUG-0013 is the proof - the block WAS in the stylesheet, section 7 asserted its presence,
 * and the visitor still got a LIGHTER plate. That measurement is tests/browser-check.mjs
 * (matchMedia + getComputedStyle, against the deployed URL).
 *
 * What it CAN do is catch the two shapes of mistake that produced the bugs, cheaply, on every
 * run, without a browser:
 *  - a custom property declared in terms of itself (a cycle: the declaration is dropped at
 *    computed-value time and the property silently falls back to the inherited value),
 *  - the arithmetic behind spec 10.3: what contrast a given plate alpha actually yields,
 *  - and the two positioning/animation mistakes that made the error chip cover the compass.
 */

/** Every `--x: ... var(--x) ...` in the stylesheet - i.e. a custom property defined by itself. */
$selfReferentialCustomProps = static function (string $css) use ($cssRules): array {
    $found = [];
    foreach ($cssRules($css) as [$selectorList, $declarations]) {
        preg_match_all('/(?:^|;)\s*(--[\w-]+)\s*:\s*([^;}]+)/', $declarations, $m, PREG_SET_ORDER);
        foreach ($m as $declaration) {
            $name = $declaration[1];
            $value = $declaration[2];
            if (preg_match('/var\(\s*' . preg_quote($name, '/') . '(?![\w-])/', $value) === 1) {
                $found[] = trim($selectorList) . ' { ' . $name . ': ' . trim($value) . ' }';
            }
        }
    }

    return $found;
};

$customPropDeclarationCount = 0;
foreach ($cssRules($css) as [, $declarations]) {
    $customPropDeclarationCount += preg_match_all('/(?:^|;)\s*--[\w-]+\s*:/', $declarations);
}
ok(
    'the stylesheet actually declares custom properties',
    $customPropDeclarationCount >= 20,
    $customPropDeclarationCount . ' declaration(s) seen — if this were 0 the check below would be vacuous'
);

$cycles = $selfReferentialCustomProps($css);
ok(
    'no custom property is declared in terms of itself (BUG-0013)',
    $cycles === [],
    $cycles === []
        ? 'no calc(var(--x) + …) inside --x'
        : 'cycle(s): ' . implode(' | ', $cycles)
);

/** Self-check: the detector must reject the three shapes this bug can take, and accept the fix. */
$cycleFixtures = [
    'the original BUG-0013 declaration' =>
        ['.hello { --plate-alpha: calc(var(--plate-alpha) + .18); }', true],
    'a cycle hidden inside a media query' =>
        ['@media (prefers-contrast: more) { .hello { --plate-alpha: calc(var(--plate-alpha) + .18); } }', true],
    'a cycle written with loose whitespace' =>
        ['.hello { --plate-alpha: calc( var( --plate-alpha ) + .18 ); }', true],
    'the fix itself (must NOT be flagged)' =>
        ['.hello { --plate-alpha-eff: min(1, calc(var(--plate-alpha) + var(--plate-boost))); }', false],
];
foreach ($cycleFixtures as $what => [$fixture, $shouldFlag]) {
    $flagged = $selfReferentialCustomProps($fixture) !== [];
    ok(
        'self-check: the cycle detector handles ' . $what,
        $flagged === $shouldFlag,
        'flagged=' . var_export($flagged, true) . ' expected=' . var_export($shouldFlag, true)
    );
}

/**
 * Spec 10.3's own arithmetic: white text on a plate of `rgb(5 10 22 / alpha)` over a backdrop.
 * The backdrop-filter's darkening is deliberately NOT counted (as in the spec's table), so the
 * number is the pessimistic one.
 */
$contrastOverBackdrop = static function (float $alpha, array $backdrop): float {
    $plate = [5, 10, 22];
    $luminance = static function (array $rgb): float {
        $channel = static function (float $value): float {
            $v = $value / 255;

            return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
    };

    $composite = [];
    foreach ([0, 1, 2] as $i) {
        $composite[$i] = $alpha * $plate[$i] + (1 - $alpha) * $backdrop[$i];
    }

    return 1.05 / ($luminance($composite) + 0.05);
};

/** The worst case of the whole design: the Sun's near-white corona right behind the headline. */
$corona = [255, 248, 224];

// The tokens the cascade is built from, read from the stylesheet rather than retyped here.
$conflictAlpha = (float) $declaredValue($rootDeclarations, '--conflict-alpha');
ok(
    '--conflict-alpha is the spec 8.4 value',
    abs($conflictAlpha - 0.62) < 1e-9,
    '--conflict-alpha=' . var_export($declaredValue($rootDeclarations, '--conflict-alpha'), true)
);

/** The surcharge declared inside the prefers-contrast block, whatever property carries it. */
$contrastBoost = null;
foreach ($cssRules($css) as [$selectorList, $declarations]) {
    if (str_contains($selectorList, 'prefers-contrast') && str_contains($selectorList, '.hello')) {
        $contrastBoost = $declaredValue($declarations, '--plate-boost') ?? $contrastBoost;
    }
}
ok(
    'the prefers-contrast block raises the plate by the spec 10.3 surcharge (+.18)',
    $contrastBoost !== null && abs((float) $contrastBoost - 0.18) < 1e-9,
    '--plate-boost=' . var_export($contrastBoost, true)
);
$helloRule = '';
foreach ($cssRules($css) as [$selectorList, $declarations]) {
    if (trim($selectorList) === '.hello') {
        $helloRule .= ';' . $declarations;
    }
}
ok(
    'the plate is painted from the effective alpha, not from the raw one',
    str_contains((string) $declaredValue($helloRule, 'background'), 'var(--plate-alpha-eff)'),
    'background=' . var_export($declaredValue($helloRule, 'background'), true)
);

$alphaWithContrast = min(1.0, $conflictAlpha + (float) $contrastBoost);
near('the high-contrast collision alpha is the spec 10.3 value', $alphaWithContrast, 0.80, 1e-9);
ok(
    'white on that plate clears AA over the Sun\'s corona (spec 10.3, worst case)',
    $contrastOverBackdrop($alphaWithContrast, $corona) >= 4.5,
    sprintf('alpha=%.2f -> %.2f : 1 (AA needs 4.5)', $alphaWithContrast, $contrastOverBackdrop($alphaWithContrast, $corona))
);
ok(
    'the same maths still passes the plain collision case (0.62, no preference)',
    $contrastOverBackdrop(0.62, $corona) >= 4.5,
    sprintf('%.2f : 1 — the spec\'s table says 5.92', $contrastOverBackdrop(0.62, $corona))
);
ok(
    'self-check: the maths does fail the broken 0.42 that BUG-0013 produced',
    $contrastOverBackdrop(0.42, $corona) < 4.5,
    sprintf('%.2f : 1 — this is what the high-contrast user was getting', $contrastOverBackdrop(0.42, $corona))
);

/** ------------------------------------------------------------ the error chip (BUG-0014) */

$chipRule = '';
foreach ($cssRules($css) as [$selectorList, $declarations]) {
    if (trim($selectorList) === '.errorchip') {
        $chipRule .= ';' . $declarations;
    }
}
ok('the stylesheet actually has a rule for .errorchip', $chipRule !== '', 'without it the checks below are vacuous');

/**
 * An entry animation that fills forwards keeps `opacity: 1` with animation-origin priority,
 * which outranks every normal declaration - including .errorchip--faded. That is why the chip
 * stayed fully opaque for eight minutes instead of fading after eight seconds (S3-1).
 */
$animationFillsForward = static function (?string $animation): bool {
    if ($animation === null) {
        return false;
    }

    return preg_match('/(?<![\w-])(forwards|both)(?![\w-])/', $animation) === 1;
};
ok(
    'the chip\'s entry animation does not fill forwards (S3-1)',
    !$animationFillsForward($declaredValue($chipRule, 'animation')),
    'animation=' . var_export($declaredValue($chipRule, 'animation'), true)
        . ' — a forwards/both fill would freeze opacity: 1 and defeat .errorchip--faded'
);
ok(
    'self-check: the fill detector catches the old declaration',
    $animationFillsForward('chip-in var(--dur-chip-in) var(--ease-out) both')
        && $animationFillsForward('chip-in 240ms ease forwards')
        && !$animationFillsForward('chip-in 240ms ease'),
    'both/forwards flagged, plain animation not'
);
$fadedRule = '';
foreach ($cssRules($css) as [$selectorList, $declarations]) {
    if (str_contains($selectorList, '.errorchip--faded')) {
        $fadedRule .= ';' . $declarations;
    }
}
$fadedOpacity = $declaredValue($fadedRule, 'opacity');
ok(
    'the fade class is still there and still dims the chip',
    $fadedOpacity !== null && (float) $fadedOpacity < 1.0,
    'opacity=' . var_export($fadedOpacity, true) . ' — spec 7.3/b: the message fades, the button stays'
);

/**
 * An absolutely positioned box is shrink-to-fit. With `left: 50%` and no `right`, the width
 * available to it is only half the viewport - which is what broke the message into five lines
 * inside a 205px box at 375px (S2-2). Anchoring both edges gives it the whole width.
 */
$halfWidthTrap = static function (string $rule) use ($declaredValue): bool {
    $left = $declaredValue($rule, 'left');
    $right = $declaredValue($rule, 'right');

    return $left !== null && str_contains($left, '50%') && $right === null;
};
ok(
    'the chip is not squeezed into half the viewport (S2-2)',
    !$halfWidthTrap($chipRule),
    'left=' . var_export($declaredValue($chipRule, 'left'), true)
        . ' right=' . var_export($declaredValue($chipRule, 'right'), true)
);
ok(
    'self-check: the half-width detector catches the old declaration',
    $halfWidthTrap('left: 50%; transform: translateX(-50%);') && !$halfWidthTrap('left: 0; right: 0; margin-inline: auto;'),
    'old rule flagged, new rule not'
);

/**
 * The chip clears the .info strip by the strip's MEASURED height, not by a constant. The
 * stylesheet reads --info-h; app.js is the only thing that can write it. Neither half is worth
 * anything alone, so both are asserted here - the lesson of BUG-0011, applied across files.
 */
ok(
    'the chip clears the info strip by its measured height (S3-5)',
    str_contains((string) $declaredValue($chipRule, 'bottom'), 'var(--info-h'),
    'bottom=' . var_export($declaredValue($chipRule, 'bottom'), true)
);
ok(
    'the script measures the info strip and publishes --info-h',
    str_contains($js, "setProperty('--info-h'") && str_contains($js, 'getBoundingClientRect'),
    'without the writer the CSS fallback would silently take over'
);
ok(
    'the measurement is refreshed on resize and orientation change',
    str_contains($js, 'onViewportChange') && str_contains($js, 'measureInfoStrip'),
    'spec 8.2: the strip is a 2x2 grid on a phone and one row above 768px'
);
ok(
    'the chip is still shown for both failure paths and the 8s fade is still armed',
    str_contains($js, 'CHIP_FADE_AFTER = 8000') && str_contains($js, "chip.classList.add('errorchip--faded')"),
    'spec 7.3/b'
);

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
