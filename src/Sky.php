<?php

declare(strict_types=1);

namespace Sky;

/**
 * The single entry point of the astronomical core: one instant in, one array out,
 * shaped exactly like the JSON contract recorded in TASK-0003.
 *
 * Both index.php (server-side SVG rendering) and api/sky.php (the JSON the browser polls)
 * use this, so the page and the API can never disagree.
 */
final class Sky
{
    private function __construct()
    {
    }

    /**
     * Snapshot of the sky for an instant.
     *
     * @return array{
     *     generated_at: string,
     *     location: array{name: string, lat: float, lon: float, elevation_m: float, timezone: string},
     *     sun: array{altitude_deg: float, azimuth_deg: float, visible: bool},
     *     moon: array{altitude_deg: float, azimuth_deg: float, visible: bool, illumination: float,
     *                 waxing: bool, bright_limb_angle_deg: float, bright_limb_pa_deg: float,
     *                 distance_km: float},
     *     sky: array{phase: string, sun_altitude_deg: float, blend: float},
     *     palette: array<string, mixed>,
     *     times: array<string, string|null>
     * }
     */
    public static function snapshot(\DateTimeImmutable $when, ?Location $location = null): array
    {
        $location ??= Location::veresegyhaz();
        $timezone = $location->timezone();
        $local = $when->setTimezone($timezone);

        $sun = SolarPosition::at($when, $location);
        $moon = LunarPosition::at($when, $location);
        $palette = SkyPalette::fromSunAltitude($sun->altitudeDeg);

        return [
            'generated_at' => $local->format('c'),
            'location' => [
                'name' => $location->name,
                'lat' => $location->latitudeDeg,
                'lon' => $location->longitudeDeg,
                'elevation_m' => $location->elevationM,
                'timezone' => $location->timezoneId,
            ],
            'sun' => [
                'altitude_deg' => round($sun->altitudeDeg, 2),
                'azimuth_deg' => round($sun->azimuthDeg, 2),
                'visible' => $sun->altitudeDeg > 0.0,
            ],
            'moon' => [
                'altitude_deg' => round($moon->altitudeDeg, 2),
                'azimuth_deg' => round($moon->azimuthDeg, 2),
                'visible' => $moon->altitudeDeg > 0.0,
                'illumination' => round($moon->illumination, 3),
                'waxing' => $moon->waxing,
                'bright_limb_angle_deg' => round($moon->brightLimbAngleDeg, 2),
                'bright_limb_pa_deg' => round($moon->brightLimbPositionAngleDeg, 2),
                'distance_km' => round($moon->distanceKm, 1),
            ],
            'sky' => [
                'phase' => $palette->phase,
                'sun_altitude_deg' => round($sun->altitudeDeg, 2),
                'blend' => round($palette->blend, 3),
            ],
            // Finished colour tokens (design spec ch. 15). The interpolation lives on the
            // server only, so the server-rendered SVG and the one-minute JS refresh can
            // never disagree about a colour.
            'palette' => $palette->tokens($moon->altitudeDeg, $moon->illumination),
            'times' => self::times($local, $location),
        ];
    }

    /**
     * Rise/set and twilight times for the LOCAL calendar day of $local, as "HH:MM"
     * strings in the location's time zone. A value is null when the event does not
     * occur on that day - normal for the Moon roughly once a month, and for the
     * twilight boundaries around midsummer.
     *
     * The day window is built from local midnight to local midnight, so a DST day is
     * correctly 23 or 25 hours long.
     *
     * @return array<string, string|null>
     */
    private static function times(\DateTimeImmutable $local, Location $location): array
    {
        $dayStart = $local->setTime(0, 0, 0);
        $dayEnd = $dayStart->modify('+1 day');

        $startJd = AstroMath::julianDay($dayStart);
        $endJd = AstroMath::julianDay($dayEnd);

        $solar = SolarPosition::events($startJd, $endJd, $location);
        $lunar = LunarPosition::events($startJd, $endJd, $location);

        $timezone = $location->timezone();

        return [
            'sunrise' => self::formatTime($solar['sun']['rise'], $timezone),
            'sunset' => self::formatTime($solar['sun']['set'], $timezone),
            'moonrise' => self::formatTime($lunar['rise'], $timezone),
            'moonset' => self::formatTime($lunar['set'], $timezone),
            'civil_dawn' => self::formatTime($solar['civil']['rise'], $timezone),
            'civil_dusk' => self::formatTime($solar['civil']['set'], $timezone),
            'nautical_dawn' => self::formatTime($solar['nautical']['rise'], $timezone),
            'nautical_dusk' => self::formatTime($solar['nautical']['set'], $timezone),
            'astronomical_dawn' => self::formatTime($solar['astronomical']['rise'], $timezone),
            'astronomical_dusk' => self::formatTime($solar['astronomical']['set'], $timezone),
        ];
    }

    /** Julian Day (UT) -> "HH:MM" in $timezone, rounded to the nearest minute. */
    private static function formatTime(?float $jdUt, \DateTimeZone $timezone): ?string
    {
        if ($jdUt === null) {
            return null;
        }

        // +30 s so that truncation to whole minutes becomes rounding.
        return AstroMath::jdToUtc($jdUt + 30.0 / 86400.0)
            ->setTimezone($timezone)
            ->format('H:i');
    }
}
