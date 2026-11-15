<?php

namespace App\Helpers;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\RoundingMode;

class AreaHelper
{
    private const CALCULATION_SCALE = 12;

    /** @return array<string, array{label: string, label_hi: string, square_meter_factor: string, definition?: string}> */
    public static function units(): array
    {
        return config('area_units.units', []);
    }

    /** @return list<string> */
    public static function supportedUnits(): array
    {
        return array_keys(self::units());
    }

    public static function canonicalUnit(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }

        if (array_key_exists($unit, self::units())) {
            return $unit;
        }

        $aliases = config('area_units.legacy_aliases', []);
        $canonicalUnit = $aliases[$unit] ?? null;

        return is_string($canonicalUnit) && array_key_exists($canonicalUnit, self::units())
            ? $canonicalUnit
            : null;
    }

    public static function isSupported(?string $unit): bool
    {
        return self::factor($unit) !== null;
    }

    public static function factor(?string $unit): ?string
    {
        $canonicalUnit = self::canonicalUnit($unit);
        $factor = $canonicalUnit === null ? null : self::units()[$canonicalUnit]['square_meter_factor'] ?? null;

        return is_string($factor) && is_numeric($factor) && is_finite((float) $factor) && (float) $factor > 0
            ? $factor
            : null;
    }

    public static function toSquareMeters(mixed $area, ?string $unit): ?string
    {
        $factor = self::factor($unit);
        $value = self::decimal($area);
        if ($factor === null || $value === null) {
            return null;
        }

        return (string) $value->multipliedBy($factor)->stripTrailingZeros();
    }

    public static function fromSquareMeters(mixed $area, ?string $unit): ?string
    {
        $factor = self::factor($unit);
        $value = self::decimal($area);
        if ($factor === null || $value === null) {
            return null;
        }

        return self::format($value->dividedBy($factor, self::CALCULATION_SCALE, RoundingMode::HALF_UP));
    }

    public static function convert(mixed $area, ?string $fromUnit, ?string $toUnit): ?string
    {
        $squareMeters = self::toSquareMeters($area, $fromUnit);

        return $squareMeters === null ? null : self::fromSquareMeters($squareMeters, $toUnit);
    }

    private static function decimal(mixed $area): ?BigDecimal
    {
        if (! is_string($area) && ! is_int($area) && ! is_float($area)) {
            return null;
        }
        if (is_float($area) && ! is_finite($area)) {
            return null;
        }

        $area = is_string($area) ? trim($area) : (string) $area;
        if ($area === '' || ! is_numeric($area)) {
            return null;
        }

        try {
            $decimal = BigDecimal::of($area);
        } catch (NumberFormatException) {
            return null;
        }

        return $decimal->isNegative() ? null : $decimal;
    }

    private static function format(BigDecimal $value): string
    {
        return (string) $value->toScale(self::CALCULATION_SCALE, RoundingMode::HALF_UP)->stripTrailingZeros();
    }
}
