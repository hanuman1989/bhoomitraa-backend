<?php

namespace App\Services\Irrigation;

use App\Enums\IrrigationAssetType;

/**
 * Validates irrigation GeoJSON and measures pipeline routes. GeoJSON positions are
 * [longitude, latitude]. Problems are returned as translation keys rather than
 * thrown, so this class can be unit tested without the framework.
 */
class IrrigationGeometryService
{
    /** Mean Earth radius in metres. */
    public const EARTH_RADIUS_METERS = 6371008.8;

    /** Largest length DECIMAL(12,2) can store. */
    public const MAX_LENGTH_METERS = 9999999999.99;

    private int $maxRoutePoints;

    private int $maxGeometryBytes;

    public function __construct(?int $maxRoutePoints = null, ?int $maxGeometryBytes = null)
    {
        $this->maxRoutePoints = $maxRoutePoints ?? (int) config('irrigation.max_route_points');
        $this->maxGeometryBytes = $maxGeometryBytes ?? (int) config('irrigation.max_geometry_bytes');
    }

    public function maxRoutePoints(): int
    {
        return $this->maxRoutePoints;
    }

    /**
     * The first problem with a client geometry as a translation key, or null when it is
     * a valid Point (source) or LineString (pipeline) with only type and coordinates.
     */
    public function error(mixed $geometry, IrrigationAssetType $type): ?string
    {
        if (! is_array($geometry) || ! array_key_exists('type', $geometry) || ! array_key_exists('coordinates', $geometry)
            || array_diff(array_keys($geometry), ['type', 'coordinates']) !== []) {
            return 'irrigation.validation.geometry_invalid';
        }
        $json = json_encode($geometry);
        if ($json === false || strlen($json) > $this->maxGeometryBytes) {
            return 'irrigation.validation.geometry_size';
        }
        if ($type === IrrigationAssetType::Source) {
            if ($geometry['type'] !== 'Point') {
                return 'irrigation.validation.geometry_point';
            }

            return $this->isPosition($geometry['coordinates']) ? null : 'irrigation.validation.geometry_coordinates';
        }
        if ($geometry['type'] !== 'LineString') {
            return 'irrigation.validation.geometry_line';
        }

        return $this->routeError($geometry['coordinates']);
    }

    /**
     * A geometry error() accepted, keeping only type and coordinates as floats.
     *
     * @param  array{type: string, coordinates: array<mixed>}  $geometry
     * @return array{type: string, coordinates: array<mixed>}
     */
    public function normalize(array $geometry): array
    {
        $position = fn (array $point): array => [(float) $point[0], (float) $point[1]];

        return ['type' => $geometry['type'], 'coordinates' => $geometry['type'] === 'Point'
            ? $position($geometry['coordinates'])
            : array_map($position, $geometry['coordinates'])];
    }

    /**
     * Route length in metres by Haversine (R = 6,371,008.8 m): the segments are summed
     * unrounded and only the total is rounded to 2 decimals.
     *
     * @param  list<array{0: int|float, 1: int|float}>  $coordinates
     */
    public function lengthMeters(array $coordinates): string
    {
        $total = 0.0;
        for ($index = 1; $index < count($coordinates); $index++) {
            $total += $this->distanceMeters($coordinates[$index - 1], $coordinates[$index]);
        }

        return number_format(round($total, 2), 2, '.', '');
    }

    private function routeError(mixed $points): ?string
    {
        if (! is_array($points) || ! array_is_list($points)) {
            return 'irrigation.validation.geometry_coordinates';
        }
        foreach ($points as $point) {
            if (! $this->isPosition($point)) {
                return 'irrigation.validation.geometry_coordinates';
            }
        }
        if (count($points) < 2) {
            return 'irrigation.validation.route_points_min';
        }
        if (count($points) > $this->maxRoutePoints) {
            return 'irrigation.validation.route_points_max';
        }
        $points = $this->normalize(['type' => 'LineString', 'coordinates' => $points])['coordinates'];
        if (count(array_unique(array_map(fn (array $point): string => json_encode($point), $points))) < 2) {
            return 'irrigation.validation.route_points_min';
        }
        for ($index = 1; $index < count($points); $index++) {
            if ($points[$index] === $points[$index - 1]) {
                return 'irrigation.validation.route_consecutive';
            }
        }
        $length = (float) $this->lengthMeters($points);

        return $length > 0 && $length <= self::MAX_LENGTH_METERS ? null : 'irrigation.validation.route_length';
    }

    /** Exactly two finite JSON numbers: longitude in [-180, 180], latitude in [-90, 90]. */
    private function isPosition(mixed $point): bool
    {
        if (! is_array($point) || ! array_is_list($point) || count($point) !== 2) {
            return false;
        }
        foreach ($point as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                return false;
            }
        }

        return $point[0] >= -180 && $point[0] <= 180 && $point[1] >= -90 && $point[1] <= 90;
    }

    /**
     * @param  array{0: int|float, 1: int|float}  $from
     * @param  array{0: int|float, 1: int|float}  $to
     */
    private function distanceMeters(array $from, array $to): float
    {
        $fromLatitude = deg2rad((float) $from[1]);
        $toLatitude = deg2rad((float) $to[1]);
        $latitudeDelta = $toLatitude - $fromLatitude;
        $longitudeDelta = deg2rad((float) $to[0] - (float) $from[0]);
        $haversine = sin($latitudeDelta / 2) ** 2 + cos($fromLatitude) * cos($toLatitude) * sin($longitudeDelta / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(sqrt(max(0.0, min(1.0, $haversine))));
    }
}
