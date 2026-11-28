<?php

use App\Enums\IrrigationAssetType;
use App\Services\Irrigation\IrrigationGeometryService;

function irrigationGeometry(): IrrigationGeometryService
{
    return new IrrigationGeometryService(maxRoutePoints: 1000, maxGeometryBytes: 65536);
}

test('accepts a point for a source and a line for a pipeline in longitude latitude order', function () {
    expect(irrigationGeometry()->error(['type' => 'Point', 'coordinates' => [75.8, 26.9]], IrrigationAssetType::Source))->toBeNull()
        ->and(irrigationGeometry()->error(['type' => 'LineString', 'coordinates' => [[75.8, 26.9], [75.81, 26.91]]], IrrigationAssetType::Pipeline))->toBeNull()
        ->and(irrigationGeometry()->error(['type' => 'Point', 'coordinates' => [-180, -90]], IrrigationAssetType::Source))->toBeNull()
        // Latitude first would put the point outside the latitude range.
        ->and(irrigationGeometry()->error(['type' => 'Point', 'coordinates' => [26.9, 95.0]], IrrigationAssetType::Source))
        ->toBe('irrigation.validation.geometry_coordinates');
});

test('reports the first problem with a geometry as a translation key', function (mixed $geometry, IrrigationAssetType $type, string $key) {
    expect(irrigationGeometry()->error($geometry, $type))->toBe('irrigation.validation.'.$key);
})->with([
    'text' => ['POINT(75.8 26.9)', IrrigationAssetType::Source, 'geometry_invalid'],
    'missing coordinates' => [['type' => 'Point'], IrrigationAssetType::Source, 'geometry_invalid'],
    'extra keys' => [['type' => 'Point', 'coordinates' => [75.8, 26.9], 'bbox' => [0, 0, 1, 1]], IrrigationAssetType::Source, 'geometry_invalid'],
    'line for a source' => [['type' => 'LineString', 'coordinates' => [[0, 0], [0, 1]]], IrrigationAssetType::Source, 'geometry_point'],
    'point for a pipeline' => [['type' => 'Point', 'coordinates' => [0, 0]], IrrigationAssetType::Pipeline, 'geometry_line'],
    'text numbers' => [['type' => 'Point', 'coordinates' => ['75.8', '26.9']], IrrigationAssetType::Source, 'geometry_coordinates'],
    'boolean number' => [['type' => 'Point', 'coordinates' => [true, 26.9]], IrrigationAssetType::Source, 'geometry_coordinates'],
    'three numbers' => [['type' => 'Point', 'coordinates' => [75.8, 26.9, 1.0]], IrrigationAssetType::Source, 'geometry_coordinates'],
    'keyed position' => [['type' => 'Point', 'coordinates' => ['lng' => 75.8, 'lat' => 26.9]], IrrigationAssetType::Source, 'geometry_coordinates'],
    'longitude 181' => [['type' => 'Point', 'coordinates' => [181, 0]], IrrigationAssetType::Source, 'geometry_coordinates'],
    'single point' => [['type' => 'LineString', 'coordinates' => [[0, 0]]], IrrigationAssetType::Pipeline, 'route_points_min'],
    'identical points' => [['type' => 'LineString', 'coordinates' => [[1, 1], [1.0, 1.0]]], IrrigationAssetType::Pipeline, 'route_points_min'],
    'consecutive duplicate' => [['type' => 'LineString', 'coordinates' => [[0, 0], [0, 1], [0, 1]]], IrrigationAssetType::Pipeline, 'route_consecutive'],
    'invalid vertex' => [['type' => 'LineString', 'coordinates' => [[0, 0], [0, 91]]], IrrigationAssetType::Pipeline, 'geometry_coordinates'],
    '1,001 points' => [['type' => 'LineString', 'coordinates' => array_map(fn (int $index): array => [0, $index / 10000], range(0, 1000))],
        IrrigationAssetType::Pipeline, 'route_points_max'],
    'longer than DECIMAL(12,2)' => [['type' => 'LineString', 'coordinates' => array_map(fn (int $index): array => [$index % 2 ? 180 : 0, 0], range(0, 599))],
        IrrigationAssetType::Pipeline, 'route_length'],
    'zero length after rounding' => [['type' => 'LineString', 'coordinates' => [[0, 0], [0, 0.00000001]]], IrrigationAssetType::Pipeline, 'route_length'],
]);

test('rejects geometry larger than the byte limit', function () {
    $service = new IrrigationGeometryService(maxRoutePoints: 1000, maxGeometryBytes: 100);
    $route = array_map(fn (int $index): array => [75.80012345, 26.9 + $index / 1000], range(0, 9));

    expect($service->error(['type' => 'LineString', 'coordinates' => $route], IrrigationAssetType::Pipeline))
        ->toBe('irrigation.validation.geometry_size');
});

test('measures routes with Haversine and rounds only the total', function () {
    // 0.001 degrees of latitude on a sphere of radius 6,371,008.8 m is 111.195 m.
    expect(irrigationGeometry()->lengthMeters([[0, 0], [0, 0.001]]))->toBe('111.20')
        ->and(irrigationGeometry()->lengthMeters([[0, 0], [0, 0.001], [0.001, 0.001]]))->toBe('222.39')
        ->and(irrigationGeometry()->lengthMeters([[75.8001, 26.9101], [75.8011, 26.9111]]))->toBe('148.98')
        // Three 0.334 m segments: rounding each first would give 0.99.
        ->and(irrigationGeometry()->lengthMeters([[0, 0], [0, 0.000003], [0, 0.000006], [0, 0.000009]]))->toBe('1.00');
});

test('normalizes coordinates to floats and keeps only type and coordinates', function () {
    expect(irrigationGeometry()->normalize(['type' => 'LineString', 'coordinates' => [[75, 26], [75.800123, 26.910145]]]))
        ->toBe(['type' => 'LineString', 'coordinates' => [[75.0, 26.0], [75.800123, 26.910145]]])
        ->and(irrigationGeometry()->normalize(['type' => 'Point', 'coordinates' => [75, 26]]))
        ->toBe(['type' => 'Point', 'coordinates' => [75.0, 26.0]]);
});
