<?php

declare(strict_types=1);

namespace Sky;

/**
 * Turns one Sky::snapshot() array into the markup of the page: the CSS custom properties
 * that carry every server-computed value, and the inline SVG layers of the sky.
 *
 * DESIGN CONTRACT: Resources/design/egbolt-spec.md + Resources/design/tokens.md.
 * DATA CONTRACT:   Resources/tasks/TASK-0003-csillagaszati-mag.md, "API-szerzodes" v3.
 *
 * Two rules shape this class:
 *
 * 1. The page must be correct WITHOUT JavaScript. Everything the browser needs for a
 *    truthful first paint is rendered here; app.js only refreshes it once a minute.
 * 2. The client never interpolates a colour. Every hex comes from the API's `palette`
 *    block (src/SkyPalette.php), so the server-rendered SVG and the JS refresh cannot
 *    drift apart.
 *
 * The screen mapping (azimuth -> x, altitude -> k) lives here rather than in the API,
 * per the PM decision recorded in the TASK-0003 contract: it is presentation, not
 * astronomy. The same maths is mirrored in public/app.js.
 */
final class SkyRenderer
{
    /** Star field LCG seed. Fixed for all time - a time-based seed would reshuffle the sky. */
    public const STAR_SEED = 20260817;

    public const STAR_COUNT = 260;

    /** Above this normalised height a star is dropped and re-drawn (horizon extinction). */
    private const STAR_YK_MAX = 0.94;

    /**
     * Last-resort palette if even SkyPalette cannot run: the A8 (-18 deg) anchor,
     * identical to the "safety defaults" block of tokens.md ch. 12.
     */
    private const FALLBACK_PALETTE = [
        'stops' => ['#040814', '#060D1F', '#0A1430', '#101C40', '#17244B', '#23305A'],
        'glow_rgb' => '51 50 92',
        'glow_a' => 0.12,
        'ground_base' => '#05070E',
        'star_f' => 1.0,
        'star_dim' => 1.0,
        'moon_opacity' => 1.0,
        'plate_alpha' => 0.12,
        'vignette_alpha' => 0.10,
        'sun_core' => '#FFFEF4',
        'sun_edge' => '#FFF0B8',
        'sun_corona_k' => 2.6,
        'sun_corona_a' => 0.42,
    ];

    private const MONTHS_HU = [
        1 => 'január', 'február', 'március', 'április', 'május', 'június',
        'július', 'augusztus', 'szeptember', 'október', 'november', 'december',
    ];

    /** 16-point compass rose, Hungarian, starting at north (design spec 14.2). */
    private const DIRECTIONS_HU = [
        'északi', 'észak-északkeleti', 'északkeleti', 'kelet-északkeleti',
        'keleti', 'kelet-délkeleti', 'délkeleti', 'dél-délkeleti',
        'déli', 'dél-délnyugati', 'délnyugati', 'nyugat-délnyugati',
        'nyugati', 'nyugat-északnyugati', 'északnyugati', 'észak-északnyugati',
    ];

    private const PHASE_LABELS_HU = [
        'day' => 'Nappali égbolt',
        'golden' => 'Arany órai égbolt',
        'civil' => 'Polgári szürkület',
        'nautical' => 'Nautikai szürkület',
        'astronomical' => 'Csillagászati szürkület',
        'night' => 'Éjszakai égbolt',
    ];

    /** Distant ridge line, design spec 6.4. viewBox y=25 is the horizon. */
    private const RIDGE_FAR_PATH = 'M0,250 L0,40 C120,26 200,20 300,24 C400,28 470,40 570,38'
        . ' C670,36 730,22 830,22 C930,22 990,36 1090,38'
        . ' C1190,40 1250,26 1350,24 C1400,23 1420,30 1440,30 L1440,250 Z';

    /** Near tree line, design spec 6.4. */
    private const RIDGE_NEAR_PATH = 'M0,250 L0,72'
        . ' q26,-4 34,-18 q10,-18 24,-16 q16,2 22,18 q6,16 26,16 l84,0'
        . ' q22,-2 30,-22 q12,-30 30,-28 q20,2 26,30 q5,20 28,20 l96,0'
        . ' q24,-2 32,-20 q12,-26 28,-24 q18,2 24,26 q6,18 28,18 l88,0'
        . ' q26,-4 34,-24 q12,-32 30,-30 q20,2 26,32 q5,22 30,22 l92,0'
        . ' q22,-2 30,-18 q11,-24 27,-22 q17,2 23,24 q6,16 26,16 l104,0'
        . ' q24,-2 32,-22 q12,-28 29,-26 q19,2 25,28 q6,20 28,20 l118,0'
        . ' q24,-2 32,-20 q12,-26 28,-24 q18,2 24,26 q6,18 28,18 l74,0'
        . ' L1440,250 Z';

    /** The mare discs of design spec 6.2.3, in the moon viewBox units (r = 40). */
    private const MOON_MARIA = [
        [-12.0, -11.0, 10.0],
        [5.0, -17.0, 6.4],
        [12.0, 4.0, 8.8],
        [-7.0, 14.0, 7.2],
    ];

    /** @var array<string, mixed> */
    private array $data;

    /** @var array<string, mixed> */
    private array $palette;

    /** @var array{x: float, k: float, visible: bool, offscreen: ?string, depth: float, sub: float, azimuth: float, altitude: float} */
    private array $sunScreen;

    /** @var array{x: float, k: float, visible: bool, offscreen: ?string, depth: float, sub: float, azimuth: float, altitude: float} */
    private array $moonScreen;

    private string $state;

    /**
     * @param array<string, mixed> $snapshot Sky::snapshot() output, or null for the error state.
     */
    private function __construct(?array $snapshot)
    {
        if ($snapshot === null) {
            $this->state = 'error';
            $this->data = self::errorData();
        } else {
            $this->data = $snapshot;
            $this->state = ($snapshot['sun']['visible'] || $snapshot['moon']['visible']) ? 'ok' : 'empty';
        }

        /** @var array<string, mixed> $palette */
        $palette = $this->data['palette'];
        $this->palette = $palette;

        $this->sunScreen = self::screenCoordinates($this->data['sun']);
        $this->moonScreen = self::screenCoordinates($this->data['moon']);
    }

    /** @param array<string, mixed> $snapshot */
    public static function fromSnapshot(array $snapshot): self
    {
        return new self($snapshot);
    }

    /**
     * The state the design spec calls "szamitasi hiba" (7.3/a): a generic night sky with
     * no bodies, so that "Hello World" - the brief's primary promise - never fails to render.
     */
    public static function errorState(): self
    {
        return new self(null);
    }

    public function state(): string
    {
        return $this->state;
    }

    public function phase(): string
    {
        return (string) $this->data['sky']['phase'];
    }

    /** The colour painted behind the page before the first frame, so nothing flashes white. */
    public function bootstrapColor(): string
    {
        return (string) $this->palette['stops'][3];
    }

    // ---------------------------------------------------------------- custom properties

    /**
     * Every server-computed value, as the inline `style` of <main class="stage">.
     * app.js rewrites exactly this set on each refresh - nothing else.
     */
    public function styleAttribute(): string
    {
        $out = [];

        foreach ($this->paletteVariables() as $name => $value) {
            $out[] = $name . ':' . $value;
        }

        foreach ($this->bodyVariables() as $name => $value) {
            $out[] = $name . ':' . $value;
        }

        return implode(';', $out);
    }

    /**
     * Colour tokens straight from the API `palette` block. `--skyA-*` feeds the visible
     * gradient layer; `--skyB-*` is the spare layer app.js cross-fades to.
     *
     * @return array<string, string>
     */
    public function paletteVariables(): array
    {
        $vars = [];

        foreach ($this->palette['stops'] as $index => $stop) {
            $vars['--sky-' . $index] = (string) $stop;
            $vars['--skyA-' . $index] = (string) $stop;
            $vars['--skyB-' . $index] = (string) $stop;
        }

        $sunAltitude = (float) $this->data['sky']['sun_altitude_deg'];

        $vars['--glow-rgb'] = (string) $this->palette['glow_rgb'];
        $vars['--glow-a'] = self::num((float) $this->palette['glow_a']);
        $vars['--ground-base'] = (string) $this->palette['ground_base'];
        $vars['--dayness'] = self::num(self::clamp(($sunAltitude + 6.0) / 12.0, 0.0, 1.0));
        $vars['--dayness2'] = self::num(self::clamp(($sunAltitude + 12.0) / 18.0, 0.0, 1.0));
        $vars['--star-f'] = self::num((float) $this->palette['star_f']);
        $vars['--star-dim'] = self::num((float) $this->palette['star_dim']);
        $vars['--plate-alpha'] = self::num((float) $this->palette['plate_alpha']);
        $vars['--vignette-alpha'] = self::num((float) $this->palette['vignette_alpha']);
        $vars['--moon-opacity'] = self::num((float) $this->palette['moon_opacity']);
        $vars['--sun-core'] = (string) $this->palette['sun_core'];
        $vars['--sun-edge'] = (string) $this->palette['sun_edge'];
        $vars['--sun-corona-k'] = self::num((float) $this->palette['sun_corona_k']);
        $vars['--sun-corona-a'] = self::num((float) $this->palette['sun_corona_a']);

        return $vars;
    }

    /** @return array<string, string> */
    public function bodyVariables(): array
    {
        return [
            '--sun-x' => self::num($this->sunScreen['x']) . '%',
            '--sun-k' => self::num($this->sunScreen['k']),
            '--sun-vis' => $this->sunScreen['visible'] ? '1' : '0',
            '--sun-sub' => self::num($this->sunScreen['sub']),
            '--moon-x' => self::num($this->moonScreen['x']) . '%',
            '--moon-k' => self::num($this->moonScreen['k']),
            '--moon-vis' => $this->moonScreen['visible'] ? '1' : '0',
            '--moon-sub' => self::num($this->moonScreen['sub']),
            '--moon-illum' => self::num($this->moonIllumination()),
            '--moon-chi' => self::num($this->moonChiDeg()) . 'deg',
        ];
    }

    // ---------------------------------------------------------------- layers

    /** z0 - the sky gradient. Two stacked layers so a refresh can cross-fade (spec 7.4). */
    public function skyLayers(): string
    {
        return $this->skyLayer('a') . $this->skyLayer('b');
    }

    private function skyLayer(string $id): string
    {
        $stops = '';

        // Fixed stop positions (spec 5.1): only the colours ever change, never the offsets.
        foreach ([0, 30, 55, 75, 90, 100] as $index => $offset) {
            $stops .= '<stop class="s' . $index . '" offset="' . $offset . '%"/>';
        }

        return '<div class="sky sky--' . $id . '" data-sky="' . $id . '" aria-hidden="true">'
            . '<svg viewBox="0 0 100 100" preserveAspectRatio="none" focusable="false" aria-hidden="true">'
            . '<defs><linearGradient id="skyGrad-' . $id . '" x1="0" y1="0" x2="0" y2="1">'
            . $stops
            . '</linearGradient></defs>'
            . '<rect width="100" height="100" fill="url(#skyGrad-' . $id . ')"/>'
            . '</svg></div>';
    }

    /**
     * z10 - the star field. Deterministic, generated once from a fixed seed, so a refresh
     * never rearranges the sky (spec 6.3). Visibility is pure CSS from --star-f/--star-dim.
     */
    public function stars(): string
    {
        $stars = self::generateStars();
        $out = '<div class="stars" aria-hidden="true">';

        foreach ($stars as $star) {
            $style = '--x:' . self::num($star['x']) . '%'
                . ';--yk:' . self::num($star['yk'])
                . ';--o:' . self::num($star['o'])
                . ';--th:' . self::num($star['th']);

            $class = 'star star--s' . $star['sizeClass'] . ' star--' . $star['colorClass'];

            if ($star['big']) {
                $class .= ' star--big';
                $style .= ';--tw-dur:' . self::num($star['twDur']) . 's'
                    . ';animation-delay:' . self::num($star['twDelay']) . 's';
            }

            $out .= '<b class="' . $class . '" style="' . $style . '"></b>';
        }

        return $out . '</div>';
    }

    /**
     * z20 - the horizon glow at the Sun's azimuth. Stays put after sunset; that glow is
     * what makes twilight believable (spec 5.6).
     */
    public function glow(): string
    {
        // Never hidden. Even with the Sun below the horizon or just past the edge of the
        // panorama the glow stays at its azimuth (clamped to the edge) - that continuity
        // is what makes twilight read as twilight instead of popping off at 270 degrees.
        return '<div class="glow glow--sun" id="glow-sun" aria-hidden="true"></div>';
    }

    /**
     * z30 - the Sun and Moon discs.
     *
     * Both nodes are always rendered and simply hidden when the body is below the horizon
     * or outside the panorama, so a refresh only has to flip attributes - it never builds
     * markup, and there is nothing to go missing.
     */
    public function bodies(): string
    {
        return '<div class="bodies" aria-hidden="true">'
            . $this->sunBody()
            . $this->moonBody()
            . '</div>';
    }

    private function sunBody(): string
    {
        return '<div class="body body--sun" id="body-sun"' . self::hiddenIf(!$this->sunScreen['visible']) . '>'
            . '<svg class="sun__corona" viewBox="-50 -50 100 100" focusable="false" aria-hidden="true">'
            . '<defs><radialGradient id="sunCorona">'
            . '<stop offset="0%" class="c0"/><stop offset="26%" class="c1"/>'
            . '<stop offset="58%" class="c2"/><stop offset="100%" class="c3"/>'
            . '</radialGradient></defs>'
            . '<circle cx="0" cy="0" r="50" fill="url(#sunCorona)"/>'
            . '</svg>'
            . '<svg class="sun__disc" viewBox="-50 -50 100 100" focusable="false" aria-hidden="true">'
            . '<defs><radialGradient id="sunCore">'
            . '<stop offset="0%" class="k0"/><stop offset="62%" class="k1"/><stop offset="100%" class="k2"/>'
            . '</radialGradient></defs>'
            . '<circle class="sun__core" cx="0" cy="0" r="26" fill="url(#sunCore)"/>'
            . '<circle class="sun__rim" cx="0" cy="0" r="26" fill="none" stroke-width="1.5"/>'
            . '</svg></div>';
    }

    private function moonBody(): string
    {
        $illumination = $this->moonIllumination();
        $rotation = self::num($this->moonChiDeg() - 90.0);
        $path = self::moonLitPath($illumination);

        $maria = '';
        foreach (self::MOON_MARIA as [$cx, $cy, $r]) {
            $maria .= '<circle cx="' . self::num($cx) . '" cy="' . self::num($cy)
                . '" r="' . self::num($r) . '"/>';
        }

        // The maria sit OUTSIDE the rotation and are clipped to the (rotated) lit path, so
        // the Moon's face always stands the same way up while only the lighting turns (6.2.3).
        return '<div class="body body--moon" id="body-moon"'
            . self::hiddenIf(!$this->moonScreen['visible']) . '>'
            . '<div class="moon__glow"></div>'
            . '<svg class="moon__disc" viewBox="-50 -50 100 100" focusable="false" aria-hidden="true">'
            . '<defs>'
            . '<radialGradient id="moonLit" cx="50%" cy="50%" r="70%" fx="25%" fy="25%">'
            . '<stop offset="0%" class="m0"/><stop offset="70%" class="m1"/><stop offset="100%" class="m2"/>'
            . '</radialGradient>'
            . '<clipPath id="moonLitClip">'
            . '<path id="moonLitClipPath" d="' . $path . '" transform="rotate(' . $rotation . ')"/>'
            . '</clipPath>'
            . '</defs>'
            . '<circle class="moon__earthshine" cx="0" cy="0" r="40"/>'
            . '<g id="moonLitGroup" transform="rotate(' . $rotation . ')">'
            . '<path id="moonLitPath" class="moon__lit" d="' . $path . '" fill="url(#moonLit)"/>'
            . '</g>'
            . '<g class="moon__maria" clip-path="url(#moonLitClip)">' . $maria . '</g>'
            . '<circle class="moon__limb" cx="0" cy="0" r="40" fill="none" stroke-width=".8"/>'
            . '</svg></div>';
    }

    /**
     * The lit fraction of the disc as a circular arc closed by a half-ellipse terminator
     * (spec 6.2.1). In this base orientation the bright limb faces +x, i.e. to the right.
     */
    public static function moonLitPath(float $illumination): string
    {
        $r = 40.0;
        $k = self::clamp($illumination, 0.0, 1.0);

        // Numeric floor: with rx = 0 the ellipse arc would degenerate into a straight line.
        $a = max($r * abs(1.0 - 2.0 * $k), $r * 0.005);
        $sweep = $k < 0.5 ? 0 : 1;

        return 'M 0 -40 A 40 40 0 0 1 0 40 A ' . self::num($a) . ' 40 0 0 ' . $sweep . ' 0 -40 Z';
    }

    /** z40 + the ground fill underneath it. */
    public function ridge(): string
    {
        // One shared pair of paths, three viewBox crops (spec 6.4); CSS picks the crop that
        // matches the breakpoint so the trees never look stretched.
        $defs = '<svg class="ridge__defs" width="0" height="0" focusable="false" aria-hidden="true">'
            . '<defs>'
            . '<path id="ridgeFar" d="' . self::RIDGE_FAR_PATH . '"/>'
            . '<path id="ridgeNear" d="' . self::RIDGE_NEAR_PATH . '"/>'
            . '</defs></svg>';

        $crops = [
            'm' => '380 0 700 250',
            't' => '180 0 1080 250',
            'd' => '0 0 1440 250',
        ];

        $svgs = '';
        foreach ($crops as $key => $viewBox) {
            $svgs .= '<svg class="ridge__svg ridge__svg--' . $key . '" viewBox="' . $viewBox . '"'
                . ' preserveAspectRatio="none" focusable="false" aria-hidden="true">'
                . '<use class="ridge__far" href="#ridgeFar"/>'
                . '<use class="ridge__near" href="#ridgeNear"/>'
                . '</svg>';
        }

        return '<div class="ground" aria-hidden="true"></div>'
            . $defs
            . '<div class="ridge" aria-hidden="true">' . $svgs . '</div>'
            . '<div class="haze" aria-hidden="true"></div>';
    }

    /** z50 - the compass scale. Fixed positions, never computed (spec 6.6). */
    public function compass(): string
    {
        $out = '<div class="compass" aria-hidden="true">';

        // 24 intervals over the 180 degree panorama = one mark every 7.5 degrees; the five
        // that coincide with a labelled direction become major ticks, leaving 20 minor ones
        // (spec 6.6 / component table 8).
        for ($i = 0; $i <= 24; $i++) {
            if ($i % 6 === 0) {
                continue;
            }

            $out .= '<i class="compass__tick" style="left:' . self::num($i * 100.0 / 24.0) . '%"></i>';
        }

        $labels = [['K', 0.0, 'start'], ['DK', 25.0, 'mid'], ['D', 50.0, 'mid'],
            ['DNy', 75.0, 'mid'], ['Ny', 100.0, 'end']];

        foreach ($labels as [$text, $left, $align]) {
            $out .= '<i class="compass__major compass__major--' . $align . '"'
                . ' style="left:' . self::num($left) . '%"></i>'
                . '<span class="compass__label compass__label--' . $align . '"'
                . ' style="left:' . self::num($left) . '%">' . $text . '</span>';
        }

        return $out . '</div>';
    }

    /** z55 - depth markers for a body that is below the horizon but still on the panorama. */
    public function subMarkers(): string
    {
        $out = '';

        foreach ([['sun', $this->sunScreen], ['moon', $this->moonScreen]] as [$name, $screen]) {
            // A marker only makes sense for a body that is below the horizon but still
            // somewhere on the panorama; outside 90-270 degrees the edge chip speaks instead.
            $hidden = self::hiddenIf($screen['visible'] || $screen['offscreen'] !== null);
            $deep = $screen['depth'] > 12.0 ? ' submarker--deep' : '';

            $out .= '<i class="subline subline--' . $name . '" id="subline-' . $name . '"' . $hidden . '></i>'
                . '<div class="submarker submarker--' . $name . $deep . '" id="submarker-' . $name . '"'
                . $hidden . '>'
                . '<svg viewBox="0 0 100 100" focusable="false" aria-hidden="true">'
                . '<circle cx="50" cy="50" r="46" fill="none" stroke="currentColor"'
                . ' stroke-width="1.5" stroke-dasharray="2 5"/></svg>'
                . '</div>';
        }

        return '<div class="submarkers" aria-hidden="true">' . $out . '</div>';
    }

    /** z90 - edge chips for a body outside the 90-270 degree panorama (spec 6.7). */
    public function edgeChips(): string
    {
        $bands = '';
        foreach (['left', 'right'] as $side) {
            $onSide = $this->sunScreen['offscreen'] === $side || $this->moonScreen['offscreen'] === $side;
            $bands .= '<i class="edgeband edgeband--' . $side . '" id="edgeband-' . $side . '"'
                . self::hiddenIf(!$onSide) . '></i>';
        }

        $chips = '';
        foreach ([['Nap', 'sun', $this->sunScreen], ['Hold', 'moon', $this->moonScreen]] as [$label, $key, $screen]) {
            $side = $screen['offscreen'] ?? 'left';

            $chips .= '<div class="edgechip edgechip--' . $side . '" id="edgechip-' . $key . '"'
                . self::hiddenIf($screen['offscreen'] === null) . '>'
                . htmlspecialchars(
                    $label . ': É-i sáv · ' . self::degrees($screen['azimuth']) . '°',
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                )
                . '</div>';
        }

        return '<div class="edgechips" aria-hidden="true">' . $bands . $chips . '</div>';
    }

    private static function hiddenIf(bool $hidden): string
    {
        return $hidden ? ' hidden' : '';
    }

    /** z80 - the time strip, plus whatever state message belongs there. */
    public function info(): string
    {
        // Every branch is rendered and the irrelevant ones are hidden, so a refresh that
        // moves between states (e.g. moonrise turning "empty" into "ok") only toggles
        // attributes.
        $isNote = $this->state !== 'ok';

        return '<div class="info">'
            . $this->noteBlock()
            . $this->timesList($isNote)
            . '<div class="info__status">'
            . '<span class="refresh-dot" id="refresh-dot" aria-hidden="true" hidden></span>'
            . '</div>'
            . '<noscript><span class="info__noscript">Az automatikus frissítés kikapcsolva'
            . ' (nincs JavaScript). Frissítsd az oldalt az aktuális állásért.</span></noscript>'
            . '</div>';
    }

    private function noteBlock(): string
    {
        $error = $this->state === 'error';

        if ($error) {
            $main = 'Az égbolt most nem számolható ki.';
            $sub = 'Frissítsd az oldalt pár perc múlva. Addig egy általános éjszakai eget mutatunk.';
        } else {
            $main = 'Most sem a Nap, sem a Hold nincs a horizont felett.';
            $sub = 'Legközelebb: napkelte ' . $this->timeText('sunrise')
                . ' · holdkelte ' . $this->timeText('moonrise', 'ma nincs');
        }

        return '<div class="info__note' . ($error ? ' info__note--error' : '') . '"'
            . ' id="info-note" role="status"' . self::hiddenIf($this->state === 'ok') . '>'
            . '<span class="info__note-main" id="info-note-main">'
            . htmlspecialchars($main, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
            . '<span class="info__note-sub" id="info-note-sub">'
            . htmlspecialchars($sub, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
            . '</div>';
    }

    private function timesList(bool $hidden): string
    {
        $cells = [
            ['sunrise', 'Napkelte', '↑', $this->timeText('sunrise')],
            ['sunset', 'Napnyugta', '↓', $this->timeText('sunset')],
            ['moonrise', 'Holdkelte', '☾↑', $this->timeText('moonrise', 'ma nincs')],
            ['moonset', 'Holdnyugta', '☾↓', $this->timeText('moonset', 'ma nincs')],
        ];

        $out = '<dl class="times" id="times"' . self::hiddenIf($hidden) . '>';

        foreach ($cells as [$key, $label, $short, $value]) {
            $out .= '<div class="times__cell">'
                . '<dt><span class="times__label">' . $label . '</span>'
                . '<span class="times__label-short" aria-hidden="true">' . $short . '</span></dt>'
                . '<dd id="time-' . $key . '">'
                . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</dd>'
                . '</div>';
        }

        return $out . '</dl>';
    }

    /**
     * The screen-reader replacement for the whole graphic (spec 14.2). Rebuilt by app.js
     * on every refresh, from the same fields.
     */
    public function description(): string
    {
        if ($this->state === 'error') {
            return 'Az égbolt most nem számolható ki. Frissítsd az oldalt pár perc múlva.';
        }

        $moment = new \DateTimeImmutable((string) $this->data['generated_at']);

        $text = sprintf(
            'Az égbolt Veresegyház felett, %d. %s %d. %s-kor. %s. ',
            (int) $moment->format('Y'),
            self::MONTHS_HU[(int) $moment->format('n')],
            (int) $moment->format('j'),
            $moment->format('H:i'),
            self::PHASE_LABELS_HU[$this->phase()] ?? 'Égbolt'
        );

        $text .= self::bodyDescription('A Nap', $this->data['sun'], null) . '. ';
        $text .= self::bodyDescription('A Hold', $this->data['moon'], $this->data['moon']) . '. ';

        if ($this->data['moon']['illumination'] === null) {
            $text .= 'A Hold fázisa most nem határozható meg pontosan. ';
        }

        $text .= sprintf(
            'Napkelte %s, napnyugta %s.',
            $this->timeText('sunrise'),
            $this->timeText('sunset')
        );

        return $text;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed>|null $moon when set, the phase information is appended
     */
    private static function bodyDescription(string $name, array $body, ?array $moon): string
    {
        $altitude = (float) $body['altitude_deg'];
        $direction = self::DIRECTIONS_HU[(int) round(((float) $body['azimuth_deg']) / 22.5) % 16];

        if (!$body['visible']) {
            return sprintf('%s %d fokkal a horizont alatt van, %s irányban', $name, (int) round(-$altitude), $direction);
        }

        $text = sprintf('%s %d fokkal a horizont felett, %s irányban', $name, (int) round($altitude), $direction);

        if ($moon !== null && $moon['illumination'] !== null) {
            $text .= sprintf(
                ', %d százalékban megvilágítva, %s',
                (int) round(((float) $moon['illumination']) * 100.0),
                $moon['waxing'] ? 'növekvő fázisban' : 'fogyó fázisban'
            );
        }

        return $text;
    }

    private function timeText(string $key, string $missing = '—'): string
    {
        $value = $this->data['times'][$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $missing;
    }

    private function moonIllumination(): float
    {
        $value = $this->data['moon']['illumination'] ?? null;

        // Spec 7.3/d: an unusable illumination is not an error state, it falls back to a half moon.
        return is_numeric($value) ? self::clamp((float) $value, 0.0, 1.0) : 0.5;
    }

    /**
     * The bright-limb angle in the RENDERER's own frame: measured from the zenith towards
     * increasing azimuth, i.e. clockwise on screen. SVG unit vector (sin X, -cos X).
     * This is contract v3 (TASK-0003) - v1 had the opposite sense.
     */
    private function moonChiDeg(): float
    {
        $value = $this->data['moon']['bright_limb_angle_deg'] ?? null;

        if (is_numeric($value)) {
            return fmod(fmod((float) $value, 360.0) + 360.0, 360.0);
        }

        // Fallback (spec 6.2.2): a waxing Moon is east of the Sun, so the Sun - and with it
        // the bright limb - sits towards the larger azimuth, which is screen right.
        return ($this->data['moon']['waxing'] ?? true) ? 100.0 : 280.0;
    }

    /**
     * Azimuth/altitude -> the dimensionless screen coordinates of design spec 3.
     *
     * @param array<string, mixed> $body
     * @return array{x: float, k: float, visible: bool, offscreen: ?string, depth: float, sub: float, azimuth: float, altitude: float}
     */
    public static function screenCoordinates(array $body): array
    {
        $azimuth = fmod(fmod((float) $body['azimuth_deg'], 360.0) + 360.0, 360.0);
        $altitude = (float) $body['altitude_deg'];

        $offscreen = null;
        if ($azimuth < 90.0) {
            $offscreen = 'left';
        } elseif ($azimuth > 270.0) {
            $offscreen = 'right';
        }

        $depth = max(0.0, -$altitude);

        return [
            'x' => self::clamp(($azimuth - 90.0) / 180.0 * 100.0, 0.0, 100.0),
            'k' => self::clamp(1.0 - $altitude / 90.0, 0.0, 1.0),
            'visible' => ((bool) $body['visible']) && $offscreen === null,
            'offscreen' => $offscreen,
            'depth' => $depth,
            'sub' => 0.30 * min($depth / 18.0, 1.0),
            'azimuth' => $azimuth,
            'altitude' => $altitude,
        ];
    }

    /**
     * The star field of spec 6.3. The LCG and every constant are fixed, so this returns
     * byte-identical output on every request and on every server.
     *
     * @return list<array{x: float, yk: float, o: float, th: float, sizeClass: int, colorClass: string, big: bool, twDur: float, twDelay: float}>
     */
    public static function generateStars(): array
    {
        $seed = self::STAR_SEED;
        $next = static function () use (&$seed): float {
            $seed = ($seed * 1103515245 + 12345) % 2147483648;

            return $seed / 2147483648;
        };

        $stars = [];

        while (count($stars) < self::STAR_COUNT) {
            $u1 = $next();
            $u2 = $next();
            $u3 = $next();
            $u4 = $next();

            $yk = $u2 ** 0.85;

            // Near the horizon the atmosphere swallows the light: drop and re-draw.
            if ($yk > self::STAR_YK_MAX) {
                continue;
            }

            if ($u3 < 0.70) {
                $sizeClass = 1;
            } elseif ($u3 < 0.92) {
                $sizeClass = 2;
            } elseif ($u3 < 0.98) {
                $sizeClass = 3;
            } else {
                $sizeClass = 4;
            }

            if ($u4 < 0.20) {
                $colorClass = 'blue';
            } elseif ($u4 < 0.32) {
                $colorClass = 'warm';
            } else {
                $colorClass = 'white';
            }

            $stars[] = [
                'x' => $u1 * 100.0,
                'yk' => $yk,
                // Second factor is atmospheric extinction: low stars are dimmer.
                'o' => (0.35 + 0.60 * $u4) * (0.35 + 0.65 * (1.0 - $yk)),
                'th' => 0.0,
                'sizeClass' => $sizeClass,
                'colorClass' => $colorClass,
                'big' => $sizeClass >= 3,
                'twDur' => 4.5 + $u1 * 3.0,
                'twDelay' => $u2 * -6.0,
            ];
        }

        // --th is the star's rank by brightness: as the sky darkens the brightest appear first.
        $order = array_keys($stars);
        usort($order, static fn (int $a, int $b): int => $stars[$b]['o'] <=> $stars[$a]['o']);

        foreach ($order as $rank => $index) {
            $stars[$index]['th'] = $rank / self::STAR_COUNT;
        }

        return $stars;
    }

    /** @return array<string, mixed> */
    private static function errorData(): array
    {
        try {
            $palette = SkyPalette::fromSunAltitude(-18.0)->tokens(-90.0, 0.5);
        } catch (\Throwable) {
            $palette = self::FALLBACK_PALETTE;
        }

        return [
            'generated_at' => (new \DateTimeImmutable('now'))->format('c'),
            'sun' => ['altitude_deg' => -18.0, 'azimuth_deg' => 0.0, 'visible' => false],
            'moon' => ['altitude_deg' => -18.0, 'azimuth_deg' => 0.0, 'visible' => false,
                'illumination' => 0.5, 'waxing' => true, 'bright_limb_angle_deg' => 100.0],
            'sky' => ['phase' => 'night', 'sun_altitude_deg' => -18.0, 'blend' => 0.0],
            'palette' => $palette,
            'times' => ['sunrise' => null, 'sunset' => null, 'moonrise' => null, 'moonset' => null],
        ];
    }

    /** Fixed-precision, locale-independent number formatting for CSS and SVG. */
    private static function num(float $value, int $decimals = 4): string
    {
        $text = number_format($value, $decimals, '.', '');

        if (!str_contains($text, '.')) {
            return $text;
        }

        return rtrim(rtrim($text, '0'), '.') ?: '0';
    }

    private static function degrees(float $value): string
    {
        return (string) (int) round($value);
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
