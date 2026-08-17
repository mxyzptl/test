<?php

declare(strict_types=1);

namespace Sky;

/**
 * Turns the Sun's apparent altitude (and, for two tokens, the Moon) into everything the
 * renderer needs to colour the sky: a named phase, a continuous blend factor, and the
 * ready-made colour tokens of Resources/design/egbolt-spec.md.
 *
 * WHY THE COLOURS ARE COMPUTED HERE and not in the browser: the interpolation is
 * smoothstep over nine anchors, blended in linear light (spec 5.3). Implementing that
 * twice - once in PHP for the server-rendered SVG, once in JS for the one-minute refresh -
 * would mean two implementations of the same maths drifting apart. The API hands out
 * finished hex values; the client only writes them into CSS custom properties.
 *
 * Band boundaries follow design spec 5.4, which is why "golden" owns its upper edge
 * (+6 deg) while every other band owns its lower edge.
 */
final class SkyPalette
{
    public const PHASE_NIGHT = 'night';
    public const PHASE_ASTRONOMICAL = 'astronomical';
    public const PHASE_NAUTICAL = 'nautical';
    public const PHASE_CIVIL = 'civil';
    public const PHASE_GOLDEN = 'golden';
    public const PHASE_DAY = 'day';

    /**
     * Ordered from darkest to brightest:
     * [phase, lower bound (inclusive), upper bound, is the upper bound inclusive?].
     */
    private const BANDS = [
        [self::PHASE_NIGHT, -90.0, -18.0, false],
        [self::PHASE_ASTRONOMICAL, -18.0, -12.0, false],
        [self::PHASE_NAUTICAL, -12.0, -6.0, false],
        [self::PHASE_CIVIL, -6.0, -0.833, false],
        [self::PHASE_GOLDEN, -0.833, 6.0, true],
        [self::PHASE_DAY, 6.0, 90.0, true],
    ];

    /**
     * The nine sky anchors of design spec 5.2 + 5.6, ascending by Sun altitude.
     * [altitude, six gradient stops (zenith -> horizon), glow colour, glow alpha]
     */
    private const SKY_ANCHORS = [
        [-30.0, ['#02040B', '#030611', '#050A1C', '#080E27', '#0B1330', '#101A3C'], '#1A2148', 0.06],
        [-18.0, ['#040814', '#060D1F', '#0A1430', '#101C40', '#17244B', '#23305A'], '#33325C', 0.12],
        [-12.0, ['#071132', '#0E1C43', '#182B5A', '#2B3A6B', '#45416F', '#6A4F66'], '#7C4A52', 0.26],
        [-6.0, ['#0D1F4C', '#1B3268', '#38487E', '#6A5480', '#A8636B', '#D2825F'], '#E9713F', 0.46],
        [-0.833, ['#16326A', '#2C5089', '#6C6A99', '#BC7C74', '#E9905A', '#FBBE72'], '#FF9E52', 0.58],
        [2.0, ['#173F7C', '#33619E', '#7E82AE', '#C89A88', '#EFAE6E', '#FBD68C'], '#FFC27A', 0.52],
        [6.0, ['#14498F', '#2F6CB0', '#6D96C9', '#B0B7D0', '#E2CBB4', '#F6DFAF'], '#FFD79A', 0.40],
        [20.0, ['#1257B6', '#2F7CD2', '#6BA6E0', '#A3C8EC', '#CFE1F1', '#EAEDEE'], '#FFF0C8', 0.24],
        [50.0, ['#0E56BE', '#2B7BDA', '#66A8E9', '#9DCAF2', '#C8E2F8', '#E4F0FA'], '#FFF4D6', 0.18],
    ];

    /**
     * Solar disc and corona anchors, design spec 6.1, ascending by altitude.
     * [altitude, core colour, edge colour, corona/disc diameter ratio, corona alpha]
     */
    private const SUN_DISC_ANCHORS = [
        [0.0, '#FFC46A', '#F5762A', 4.2, 0.78],
        [2.0, '#FFE7A8', '#FFB44E', 3.7, 0.68],
        [6.0, '#FFF6DC', '#FFD98A', 3.1, 0.55],
        [20.0, '#FFFEF4', '#FFF0B8', 2.6, 0.42],
    ];

    /** Ground silhouette endpoints, design spec 6.6. */
    private const GROUND_DAY = '#0B140C';
    private const GROUND_NIGHT = '#05070E';

    private function __construct(
        public readonly string $phase,
        public readonly float $blend,
        public readonly float $sunAltitudeDeg,
    ) {
    }

    /** @param float $sunAltitudeDeg the APPARENT (refracted) altitude of the Sun */
    public static function fromSunAltitude(float $sunAltitudeDeg): self
    {
        $altitude = ColorMath::clamp($sunAltitudeDeg, -90.0, 90.0);

        foreach (self::BANDS as [$phase, $lower, $upper, $upperInclusive]) {
            if ($altitude < $upper || ($upperInclusive && $altitude <= $upper)) {
                return new self($phase, ColorMath::clamp(($altitude - $lower) / ($upper - $lower), 0.0, 1.0), $sunAltitudeDeg);
            }
        }

        return new self(self::PHASE_DAY, 1.0, $sunAltitudeDeg);
    }

    /** @return list<string> all phase names, darkest first */
    public static function phases(): array
    {
        return array_map(static fn (array $band): string => $band[0], self::BANDS);
    }

    /** dayness — ground tint and corona, design spec 5.5. */
    public function dayness(): float
    {
        return ColorMath::clamp(($this->sunAltitudeDeg + 6.0) / 12.0, 0.0, 1.0);
    }

    /** dayness2 — plate and vignette opacity, design spec 5.5. */
    public function dayness2(): float
    {
        return ColorMath::clamp(($this->sunAltitudeDeg + 12.0) / 18.0, 0.0, 1.0);
    }

    /** star_f — how far the stars have come out, design spec 5.5. */
    public function starVisibility(): float
    {
        return ColorMath::clamp((-$this->sunAltitudeDeg - 4.0) / 14.0, 0.0, 1.0);
    }

    /**
     * The finished colour tokens for the renderer (design spec 15). Every value here is
     * ready to be written straight into a CSS custom property.
     *
     * @param float $moonAltitudeDeg apparent altitude of the Moon, degrees
     * @param float $moonIllumination illuminated fraction, 0..1
     *
     * @return array{
     *     stops: list<string>, glow_rgb: string, glow_a: float, ground_base: string,
     *     star_f: float, star_dim: float, moon_opacity: float, plate_alpha: float,
     *     vignette_alpha: float, sun_core: string, sun_edge: string,
     *     sun_corona_k: float, sun_corona_a: float
     * }
     */
    public function tokens(float $moonAltitudeDeg, float $moonIllumination): array
    {
        [$skyLow, $skyHigh, $skyT] = self::bracket(self::SKY_ANCHORS, $this->sunAltitudeDeg);
        [$discLow, $discHigh, $discT] = self::bracket(self::SUN_DISC_ANCHORS, $this->sunAltitudeDeg);

        $stops = [];
        foreach ($skyLow[1] as $index => $fromStop) {
            $stops[] = ColorMath::mixLinearSrgb($fromStop, $skyHigh[1][$index], $skyT);
        }

        $dayness2 = $this->dayness2();

        // Moonlight washes out the faintest stars, but only while the Moon is actually up.
        $starDim = $moonAltitudeDeg > 0.0
            ? 1.0 - 0.35 * $moonIllumination * ColorMath::clamp($moonAltitudeDeg / 15.0, 0.0, 1.0)
            : 1.0;

        return [
            'stops' => $stops,
            'glow_rgb' => self::toRgbTriplet(ColorMath::mixLinearSrgb($skyLow[2], $skyHigh[2], $skyT)),
            'glow_a' => round($skyLow[3] + ($skyHigh[3] - $skyLow[3]) * $skyT, 3),
            'ground_base' => ColorMath::mixOklab(self::GROUND_DAY, self::GROUND_NIGHT, $this->dayness()),
            'star_f' => round($this->starVisibility(), 3),
            'star_dim' => round($starDim, 3),
            'moon_opacity' => round(1.0 - $dayness2 * (0.55 - 0.40 * $moonIllumination), 3),
            'plate_alpha' => round(0.12 + 0.30 * $dayness2, 3),
            'vignette_alpha' => round(0.10 + 0.12 * $dayness2, 3),
            'sun_core' => ColorMath::mixLinearSrgb($discLow[1], $discHigh[1], $discT),
            'sun_edge' => ColorMath::mixLinearSrgb($discLow[2], $discHigh[2], $discT),
            'sun_corona_k' => round($discLow[3] + ($discHigh[3] - $discLow[3]) * $discT, 3),
            'sun_corona_a' => round($discLow[4] + ($discHigh[4] - $discLow[4]) * $discT, 3),
        ];
    }

    /**
     * Pick the two anchors that bracket $altitude and return the smoothstepped blend
     * factor between them. Outside the table the outermost anchor is held, which is what
     * the spec's "clamp(a, -30, +50)" asks for.
     *
     * @param list<array<int, mixed>> $anchors ascending by anchor altitude, altitude at index 0
     *
     * @return array{0: array<int, mixed>, 1: array<int, mixed>, 2: float}
     */
    private static function bracket(array $anchors, float $altitude): array
    {
        $last = count($anchors) - 1;

        if ($altitude <= $anchors[0][0]) {
            return [$anchors[0], $anchors[0], 0.0];
        }
        if ($altitude >= $anchors[$last][0]) {
            return [$anchors[$last], $anchors[$last], 0.0];
        }

        for ($i = 0; $i < $last; $i++) {
            $lowAltitude = (float) $anchors[$i][0];
            $highAltitude = (float) $anchors[$i + 1][0];

            if ($altitude <= $highAltitude) {
                $t = ($altitude - $lowAltitude) / ($highAltitude - $lowAltitude);

                return [$anchors[$i], $anchors[$i + 1], ColorMath::smoothstep($t)];
            }
        }

        return [$anchors[$last], $anchors[$last], 0.0];
    }

    /** "#FFF4D6" -> "255 244 214", the shape CSS `rgb(R G B / a)` wants. */
    private static function toRgbTriplet(string $hex): string
    {
        $srgb = ColorMath::hexToSrgb($hex);

        return implode(' ', array_map(static fn (float $c): string => (string) (int) round($c * 255.0), $srgb));
    }
}
