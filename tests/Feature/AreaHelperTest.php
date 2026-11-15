<?php

use App\Helpers\AreaHelper;

test('fixed application units convert to square metres without losing decimal precision', function (string $unit, string $squareMetres) {
    expect(AreaHelper::toSquareMeters('1', $unit))->toBe($squareMetres);
})->with([
    'hectare' => ['hectare', '10000'], 'acre' => ['acre', '4046.8564224'],
    'Kaccha Bigha' => ['bigha_kaccha', '1618.74256896'], 'Pakka Bigha' => ['bigha_pakka', '2529.285264'],
    'square metre' => ['sqm', '1'], 'square foot' => ['sqft', '0.09290304'],
    'square yard' => ['sqyd', '0.83612736'], 'guntha' => ['guntha', '101.17141056'],
    'legacy hectare' => ['ha', '10000'],
]);

test('conversions use square metres for zero totals and cross unit measurements', function (string $area, string $from, string $to, string $expected) {
    expect(AreaHelper::convert($area, $from, $to))->toBe($expected);
})->with([
    'hectares' => ['20000', 'sqm', 'hectare', '2'],
    'acres' => ['4046.8564224', 'sqm', 'acre', '1'],
    'Bigha variants' => ['1', 'bigha_pakka', 'bigha_kaccha', '1.5625'],
    'zero total' => ['0', 'hectare', 'bigha_pakka', '0'],
    'precision' => ['0.001', 'bigha_kaccha', 'sqm', '1.61874256896'],
]);

test('invalid areas return an unresolved result in every conversion direction', function (mixed $area) {
    expect(AreaHelper::toSquareMeters($area, 'hectare'))->toBeNull();
    expect(AreaHelper::fromSquareMeters($area, 'hectare'))->toBeNull();
    expect(AreaHelper::convert($area, 'acre', 'hectare'))->toBeNull();
})->with(['null' => [null], 'negative' => [-1], 'not numeric' => ['invalid'], 'empty' => [''],
    'infinity' => [INF], 'NaN' => [NAN], 'boolean' => [false], 'array' => [[]]]);

test('unsupported and ambiguous units never acquire an implicit conversion factor', function (?string $unit) {
    expect(AreaHelper::isSupported($unit))->toBeFalse();
    expect(AreaHelper::factor($unit))->toBeNull();
    expect(AreaHelper::toSquareMeters('1', $unit))->toBeNull();
    expect(AreaHelper::fromSquareMeters('1', $unit))->toBeNull();
    expect(AreaHelper::convert('1', 'hectare', $unit))->toBeNull();
})->with(['bigha', 'biswa', 'metric', 'imperial', 'unknown', null]);

test('nonpositive and invalid configured factors cannot be used', function (string $factor) {
    config()->set('area_units.units.hectare.square_meter_factor', $factor);

    expect(AreaHelper::factor('hectare'))->toBeNull();
    expect(AreaHelper::convert('1', 'hectare', 'sqm'))->toBeNull();
})->with(['0', '-1', 'NaN', 'not numeric']);
