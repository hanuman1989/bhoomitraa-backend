<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as LaravelValidator;

class CropFieldLayoutOptions
{
    public const VERSION = 1;

    public const CROP_SCOPES = ['all', 'specific'];

    public const FARMING_TYPES = [
        'open_field' => 'Open Farm', 'polyhouse' => 'Polyhouse', 'glasshouse' => 'Greenhouse / Glasshouse',
        'shade_net_house' => 'Shade-net House', 'net_house' => 'Net House', 'indoor' => 'Indoor Farming',
        'growing_shed' => 'Growing Shed', 'rooftop' => 'Rooftop Farming', 'container_farm' => 'Container Farming',
        'low_tunnel' => 'Low Tunnel', 'high_tunnel' => 'High Tunnel', 'other' => 'Other',
    ];

    public const GROWING_SYSTEMS = [
        'soil_based' => 'Soil Based', 'substrate_based' => 'Substrate Based', 'hydroponics' => 'Hydroponics',
        'aeroponics' => 'Aeroponics', 'aquaponics' => 'Aquaponics', 'other' => 'Other',
    ];

    public const DEFERRED_TYPES = ['paired_row', 'grouped_row', 'flat_bed', 'raised_bed_single_row',
        'ridge_furrow', 'trench', 'triangular', 'quincunx', 'contour', 'bower', 'floating_raft', 'custom'];

    /** Technical storage limits, not agricultural recommendations. */
    public const MAX_DISTANCE = 1000000;

    public const MAX_COUNT = 65535;

    /** @return array<string, array{label: string, fields: list<array<string, mixed>>}> */
    public static function layouts(): array
    {
        $row = self::distance('row_spacing_m', 'Row Spacing');
        $plant = self::distance('plant_spacing_m', 'Plant Spacing');
        $bed = self::distance('bed_width_m', 'Bed Width');
        $path = self::distance('path_width_m', 'Path Width', allowZero: true);
        $container = [self::count('plants_per_container', 'Plants per Container')];

        return [
            'random_distribution' => ['label' => 'Random Distribution', 'fields' => []],
            'continuous_row' => ['label' => 'Continuous Rows', 'fields' => [$row]],
            'individual_row' => ['label' => 'Individual Plant Rows', 'fields' => [$row, $plant]],
            'square' => ['label' => 'Square System', 'fields' => [self::distance('planting_distance_m', 'Planting Distance', displayUnit: 'm')]],
            'rectangular' => ['label' => 'Rectangular System', 'fields' => [$row, $plant]],
            'raised_bed_multi_row' => ['label' => 'Multi-row Raised Bed', 'fields' => [
                $bed, $path, self::count('rows_per_bed', 'Rows per Bed', 2), $row,
                ['key' => 'planting_pattern', 'label' => 'Planting Pattern', 'type' => 'select', 'required' => false,
                    'default' => 'individual_plants', 'options' => ['individual_plants', 'continuous_row'],
                    'display_unit' => null, 'storage_unit' => null],
                [...$plant, 'required' => false, 'required_when' => ['planting_pattern' => 'individual_plants']],
            ]],
            'pole_based' => ['label' => 'Pole-based Layout', 'fields' => [
                self::distance('support_row_spacing_m', 'Support Row Spacing', displayUnit: 'm'),
                self::distance('support_spacing_m', 'Support Spacing', displayUnit: 'm'),
                self::count('plants_per_support', 'Plants per Support'),
            ]],
            'trellis_based' => ['label' => 'Trellis-based Layout', 'fields' => [$row, $plant]],
            'rack_shelf' => ['label' => 'Rack & Shelf', 'fields' => [
                self::growingUnit(['bag', 'tray', 'block']), self::count('levels_per_rack', 'Levels per Rack'), self::count('units_per_level', 'Units per Level'),
            ]],
            'hanging_bag' => ['label' => 'Hanging Bag', 'fields' => [self::growingUnit(['bag']), self::count('units_per_support', 'Units per Support')]],
            'floor_bed' => ['label' => 'Floor Bed', 'fields' => [$bed, $path]],
            'channel_based' => ['label' => 'Hydroponic Channels', 'fields' => [self::count('sites_per_channel', 'Sites per Channel')]],
            'grow_bag' => ['label' => 'Grow Bag', 'fields' => $container],
            'container' => ['label' => 'Container', 'fields' => $container],
            'vertical_tower' => ['label' => 'Vertical Tower', 'fields' => [self::count('sites_per_tower', 'Sites per Tower')]],
            'tray' => ['label' => 'Tray', 'fields' => [self::count('cells_per_tray', 'Cells per Tray')]],
        ];
    }

    /** @return array<string, mixed> */
    public static function definition(string $type): array
    {
        $definition = self::layouts()[$type] ?? null;

        return $definition === null ? [] : ['value' => $type, ...$definition, 'configuration_version' => self::VERSION, 'preview_key' => $type];
    }

    /** @param array<string, mixed> $configuration @return array<string, list<string>> */
    public static function configurationErrors(string $type, array $configuration): array
    {
        if (! array_key_exists($type, self::layouts())) {
            return ['layout_type' => ['The selected layout type is not supported.']];
        }
        $fields = self::layouts()[$type]['fields'];
        $rules = ['configuration' => ['present', 'array']];
        foreach ($fields as $field) {
            $required = $field['required'] || (isset($field['required_when']) &&
                ($configuration['planting_pattern'] ?? 'individual_plants') === 'individual_plants');
            $fieldRules = [$required ? 'required' : 'sometimes'];
            if ($field['type'] === 'select') {
                $fieldRules[] = Rule::in($field['options']);
            } else {
                $fieldRules = [...$fieldRules, $field['type'] === 'integer' ? 'integer' : 'numeric',
                    'min:'.$field['min'], 'max:'.$field['max']];
            }
            $rules['configuration.'.$field['key']] = $fieldRules;
        }
        $validator = Validator::make(['configuration' => $configuration], $rules);
        $validator->after(function (LaravelValidator $validator) use ($configuration, $fields, $type): void {
            foreach (array_diff(array_keys($configuration), array_column($fields, 'key')) as $key) {
                $validator->errors()->add('configuration.'.$key, 'This configuration field is not allowed for the selected layout type.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($type === 'raised_bed_multi_row') {
                if (($configuration['rows_per_bed'] - 1) * $configuration['row_spacing_m'] > $configuration['bed_width_m'] + 1e-9) {
                    $validator->errors()->add('configuration.row_spacing_m', 'The configured rows do not fit within the bed width.');
                }
                if (($configuration['planting_pattern'] ?? 'individual_plants') === 'continuous_row' && array_key_exists('plant_spacing_m', $configuration)) {
                    $validator->errors()->add('configuration.plant_spacing_m', 'Plant spacing is not applicable to continuous rows.');
                }
            }
        });

        return $validator->errors()->messages();
    }

    /** @param array<string, mixed> $configuration @return array<string, mixed> */
    public static function normalize(string $type, array $configuration): array
    {
        if ($type === 'raised_bed_multi_row') {
            $configuration['planting_pattern'] ??= 'individual_plants';
        }
        foreach (self::layouts()[$type]['fields'] as $field) {
            if (array_key_exists($field['key'], $configuration) && $field['type'] !== 'select') {
                $configuration[$field['key']] = $field['type'] === 'integer' ? (int) $configuration[$field['key']] : (float) $configuration[$field['key']];
            }
        }

        return $configuration;
    }

    /** @return array<string, mixed> */
    private static function distance(string $key, string $label, bool $allowZero = false, string $displayUnit = 'cm'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'number', 'required' => true, 'display_unit' => $displayUnit,
            'storage_unit' => 'm', 'min' => $allowZero ? 0 : 0.000001, 'max' => self::MAX_DISTANCE];
    }

    /** @return array<string, mixed> */
    private static function count(string $key, string $label, int $minimum = 1): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'integer', 'required' => true, 'display_unit' => 'count',
            'storage_unit' => 'count', 'min' => $minimum, 'max' => self::MAX_COUNT];
    }

    /** @param list<string> $options @return array<string, mixed> */
    private static function growingUnit(array $options): array
    {
        return ['key' => 'growing_unit_type', 'label' => 'Growing Unit Type', 'type' => 'select', 'required' => true,
            'options' => $options, 'display_unit' => null, 'storage_unit' => null];
    }
}
