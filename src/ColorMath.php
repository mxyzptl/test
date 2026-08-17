<?php

declare(strict_types=1);

namespace Sky;

/**
 * Colour helpers for the sky palette.
 *
 * Two things matter here, both required by Resources/design/egbolt-spec.md:
 *   - blending happens in LINEAR light, not on raw hex, otherwise the twilight gradient
 *     turns muddy grey halfway between two anchors (spec 5.3);
 *   - the ground colour is an OKLab mix, because that is what the CSS `color-mix(in oklab,
 *     ...)` in the spec does, and the whole point of computing it here is that the client
 *     and the server must not produce two slightly different colours (spec 5.3, 6.6).
 */
final class ColorMath
{
    private function __construct()
    {
    }

    /**
     * "#RRGGBB" -> [r, g, b] as sRGB in 0..1.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public static function hexToSrgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) !== 6 || preg_match('/^[0-9A-Fa-f]{6}$/', $hex) !== 1) {
            throw new \InvalidArgumentException('Expected a "#RRGGBB" colour, got: ' . $hex);
        }

        return [
            hexdec(substr($hex, 0, 2)) / 255.0,
            hexdec(substr($hex, 2, 2)) / 255.0,
            hexdec(substr($hex, 4, 2)) / 255.0,
        ];
    }

    /** @param array{0: float, 1: float, 2: float} $srgb */
    public static function srgbToHex(array $srgb): string
    {
        $out = '#';
        foreach ($srgb as $channel) {
            $out .= sprintf('%02X', (int) round(max(0.0, min(1.0, $channel)) * 255.0));
        }

        return $out;
    }

    /** sRGB channel (0..1) -> linear light. */
    public static function toLinear(float $channel): float
    {
        return $channel <= 0.04045
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4;
    }

    /** Linear light -> sRGB channel (0..1). */
    public static function toSrgb(float $linear): float
    {
        return $linear <= 0.0031308
            ? $linear * 12.92
            : 1.055 * $linear ** (1.0 / 2.4) - 0.055;
    }

    /**
     * Blend two "#RRGGBB" colours in linear light. $t = 0 gives $from, $t = 1 gives $to.
     * This is LERP_LINEAR_SRGB of the design spec, section 5.3.
     */
    public static function mixLinearSrgb(string $from, string $to, float $t): string
    {
        $a = self::hexToSrgb($from);
        $b = self::hexToSrgb($to);

        $result = [];
        for ($i = 0; $i < 3; $i++) {
            $fromLinear = self::toLinear($a[$i]);
            $toLinear = self::toLinear($b[$i]);
            $result[$i] = self::toSrgb($fromLinear + ($toLinear - $fromLinear) * $t);
        }

        return self::srgbToHex($result);
    }

    /**
     * The server-side equivalent of CSS `color-mix(in oklab, $from <weight>%, $to)`:
     * $weight is the share of $from.
     */
    public static function mixOklab(string $from, string $to, float $weight): string
    {
        $weight = max(0.0, min(1.0, $weight));

        $a = self::srgbToOklab(self::hexToSrgb($from));
        $b = self::srgbToOklab(self::hexToSrgb($to));

        $mixed = [];
        for ($i = 0; $i < 3; $i++) {
            $mixed[$i] = $b[$i] + ($a[$i] - $b[$i]) * $weight;
        }

        return self::srgbToHex(self::oklabToSrgb($mixed));
    }

    /**
     * Smoothstep: zero derivative at both ends, so the sky does not visibly "kink" when it
     * crosses an anchor altitude. Design spec 5.3, step 4.
     */
    public static function smoothstep(float $t): float
    {
        $t = max(0.0, min(1.0, $t));

        return $t * $t * (3.0 - 2.0 * $t);
    }

    public static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    /**
     * sRGB (0..1) -> OKLab. Bjorn Ottosson's matrices.
     *
     * @param array{0: float, 1: float, 2: float} $srgb
     *
     * @return array{0: float, 1: float, 2: float} [L, a, b]
     */
    private static function srgbToOklab(array $srgb): array
    {
        $r = self::toLinear($srgb[0]);
        $g = self::toLinear($srgb[1]);
        $b = self::toLinear($srgb[2]);

        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;

        $l = self::cbrt($l);
        $m = self::cbrt($m);
        $s = self::cbrt($s);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }

    /**
     * OKLab -> sRGB (0..1).
     *
     * @param array{0: float, 1: float, 2: float} $oklab
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private static function oklabToSrgb(array $oklab): array
    {
        [$lightness, $aAxis, $bAxis] = $oklab;

        $l = ($lightness + 0.3963377774 * $aAxis + 0.2158037573 * $bAxis) ** 3;
        $m = ($lightness - 0.1055613458 * $aAxis - 0.0638541728 * $bAxis) ** 3;
        $s = ($lightness - 0.0894841775 * $aAxis - 1.2914855480 * $bAxis) ** 3;

        return [
            self::toSrgb(4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s),
            self::toSrgb(-1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s),
            self::toSrgb(-0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s),
        ];
    }

    /** Cube root that keeps the sign (the inputs can be slightly negative after the matrix). */
    private static function cbrt(float $value): float
    {
        return $value < 0.0 ? -((-$value) ** (1.0 / 3.0)) : $value ** (1.0 / 3.0);
    }
}
