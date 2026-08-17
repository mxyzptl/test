<?php

declare(strict_types=1);

namespace Sky;

/**
 * An observing site. The project observes from ONE fixed place (Veresegyhaz) - see
 * Resources/brief.md, "Out of scope: helyvalaszto, geolokacio". The class is still a
 * value object rather than a bag of constants so that the tests can cross-check the
 * algorithms against reference values published for other sites.
 *
 * Conventions: latitude north-positive, longitude EAST-positive, elevation in metres.
 */
final class Location
{
    public const VERESEGYHAZ_NAME = 'Veresegyház';
    public const VERESEGYHAZ_LATITUDE = 47.6489;
    public const VERESEGYHAZ_LONGITUDE = 19.2836;
    public const VERESEGYHAZ_ELEVATION_M = 140.0;
    public const VERESEGYHAZ_TIMEZONE = 'Europe/Budapest';

    /** Flattening factor of the IAU 1976 ellipsoid: b/a. */
    private const EARTH_FLATTENING = 0.99664719;

    public function __construct(
        public readonly string $name,
        public readonly float $latitudeDeg,
        public readonly float $longitudeDeg,
        public readonly float $elevationM,
        public readonly string $timezoneId,
    ) {
        if ($latitudeDeg < -90.0 || $latitudeDeg > 90.0) {
            throw new \InvalidArgumentException('Latitude must be between -90 and 90 degrees.');
        }
        if ($longitudeDeg < -180.0 || $longitudeDeg > 180.0) {
            throw new \InvalidArgumentException('Longitude must be between -180 and 180 degrees.');
        }
    }

    /** The one location this product renders. */
    public static function veresegyhaz(): self
    {
        return new self(
            self::VERESEGYHAZ_NAME,
            self::VERESEGYHAZ_LATITUDE,
            self::VERESEGYHAZ_LONGITUDE,
            self::VERESEGYHAZ_ELEVATION_M,
            self::VERESEGYHAZ_TIMEZONE,
        );
    }

    public function timezone(): \DateTimeZone
    {
        return new \DateTimeZone($this->timezoneId);
    }

    /**
     * Dip of the horizon caused by the observer's elevation, in degrees (always >= 0).
     * Classic refracted-horizon approximation: 0.0293 * sqrt(h[m]).
     * At 140 m this is 0.35 deg, which moves sunrise/sunset by roughly 2-3 minutes -
     * small, but larger than the accuracy we claim, so it is not dropped.
     */
    public function horizonDipDeg(): float
    {
        return $this->elevationM > 0.0 ? 0.0293 * sqrt($this->elevationM) : 0.0;
    }

    /**
     * rho * sin(phi') - the observer's geocentric quantity used by the parallax
     * correction. Meeus ch. 11.
     */
    public function rhoSinPhiPrime(): float
    {
        $u = atan(self::EARTH_FLATTENING * AstroMath::tanDeg($this->latitudeDeg));

        return self::EARTH_FLATTENING * sin($u)
            + ($this->elevationM / (AstroMath::EARTH_RADIUS_KM * 1000.0)) * AstroMath::sinDeg($this->latitudeDeg);
    }

    /** rho * cos(phi'). Meeus ch. 11. */
    public function rhoCosPhiPrime(): float
    {
        $u = atan(self::EARTH_FLATTENING * AstroMath::tanDeg($this->latitudeDeg));

        return cos($u)
            + ($this->elevationM / (AstroMath::EARTH_RADIUS_KM * 1000.0)) * AstroMath::cosDeg($this->latitudeDeg);
    }
}
