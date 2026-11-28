<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('irrigation geometry, constraints and indexes work on a native MySQL or MariaDB database', function () {
    if (getenv('IRRIGATION_SPATIAL_DATABASE_TESTS') !== '1') {
        $this->markTestSkipped('Set IRRIGATION_SPATIAL_DATABASE_TESTS=1 to test an isolated temporary MySQL or MariaDB database.');
    }
    $originalConnection = config('database.default');
    $connection = config('database.connections.mysql');
    // Always a new, uniquely named database: the application's own database is never used.
    $database = 'bhoomitraa_irrigation_test_'.now()->format('YmdHis').'_'.bin2hex(random_bytes(3));
    $connection['database'] = $database;
    $connection['url'] = null;
    $server = new PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';charset=utf8mb4',
        $connection['username'], $connection['password'], $connection['options']);
    $server->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $created = false;

    try {
        $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
        config()->set('database.connections.irrigation_spatial_test', $connection);
        DB::setDefaultConnection('irrigation_spatial_test');
        // Only what irrigation needs: farmers, their tokens and the new table. (The full set can't run on a
        // fresh MySQL/MariaDB database: 2026_11_19_101201_upgrade_crop_activity_templates_for_applicability
        // drops an index a foreign key still needs.)
        $migrations = array_map(fn (string $file): string => database_path('migrations/'.$file), [
            '0001_01_01_000000_create_users_table.php',
            '2026_10_07_071759_create_personal_access_tokens_table.php',
            '2026_11_08_110755_add_mobile_status_and_last_login_at_to_users_table.php',
            '2026_11_08_111249_replace_name_with_first_name_and_last_name_in_users_table.php',
            '2026_11_08_113947_add_deleted_at_to_users_table.php',
            '2026_11_08_121556_make_user_email_nullable.php',
            '2026_11_25_164319_create_irrigation_assets_table.php',
        ]);
        expect(Artisan::call('migrate', ['--database' => 'irrigation_spatial_test', '--path' => $migrations, '--realpath' => true,
            '--force' => true, '--no-interaction' => true]))->toBe(0);
        $isMaria = DB::connection()->isMaria();

        // Schema: 17 columns, two named indexes, RESTRICT foreign key and five CHECK constraints.
        expect(Schema::getColumnListing('irrigation_assets'))->toBe(['id', 'user_id', 'name', 'asset_type', 'source_type',
            'pipeline_type', 'geometry', 'length_meters', 'diameter_mm', 'depth_meters', 'water_availability', 'ownership_type',
            'status', 'notes', 'created_at', 'updated_at', 'deleted_at']);
        expect(collect(Schema::getIndexes('irrigation_assets'))->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']])
            ->sortKeys()->all())->toBe([
                'idx_irrigation_user_assets' => ['user_id', 'deleted_at', 'asset_type'],
                'idx_irrigation_user_status' => ['user_id', 'deleted_at', 'status'],
                'primary' => ['id'],
            ]);
        $foreignKey = Schema::getForeignKeys('irrigation_assets')[0];
        expect([$foreignKey['columns'], $foreignKey['foreign_table'], strtolower($foreignKey['on_delete'])])->toBe([['user_id'], 'users', 'restrict']);
        expect(collect(DB::select("SELECT CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'irrigation_assets' AND CONSTRAINT_TYPE = 'CHECK' ORDER BY CONSTRAINT_NAME", [$database]))
            ->pluck('name')->all())->toBe(['chk_irrigation_assets_asset_type', 'chk_irrigation_assets_category',
                'chk_irrigation_assets_dimensions', 'chk_irrigation_assets_srid', 'chk_irrigation_assets_status']);
        if (! $isMaria) {
            expect((int) DB::selectOne("SELECT SRS_ID AS srid FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'irrigation_assets' AND COLUMN_NAME = 'geometry'", [$database])->srid)->toBe(4326);
        }

        // Through the API: [lng, lat] round-trips exactly, SRID is 4326, and the route length is calculated.
        $farmer = User::factory()->create();
        $token = $farmer->createToken('irrigation', ['frontend'])->plainTextToken;
        $point = [75.800123, 26.910145];
        $route = [[75.8001, 26.9101], [75.8021, 26.9091], [75.8031, 26.9121]];
        $source = $this->withToken($token)->postJson('/api/account/irrigation-assets', ['name' => 'Main Borewell', 'asset_type' => 'source',
            'source_type' => 'borewell', 'depth_meters' => 120.5, 'geometry' => ['type' => 'Point', 'coordinates' => $point]])
            ->assertCreated()->assertJsonPath('data.geometry', ['type' => 'Point', 'coordinates' => $point])->json('data.id');
        $pipeline = $this->withToken($token)->postJson('/api/account/irrigation-assets', ['name' => 'North Field Pipeline',
            'asset_type' => 'pipeline', 'pipeline_type' => 'underground', 'geometry' => ['type' => 'LineString', 'coordinates' => $route]])
            ->assertCreated()->assertJsonPath('data.geometry.coordinates', $route)->assertJsonPath('data.length_meters', '575.37')
            ->assertJsonPath('data.length_source', 'calculated')->json('data.id');
        $this->withToken($token)->getJson('/api/account/irrigation-assets/'.$source)->assertOk()->assertJsonPath('data.geometry.coordinates', $point);
        expect(collect(DB::select('SELECT ST_SRID(`geometry`) AS srid FROM irrigation_assets ORDER BY id'))->pluck('srid')->map(fn ($srid): int => (int) $srid)->all())
            ->toBe([4326, 4326]);
        if (! $isMaria) {
            // MySQL stores SRID 4326 in latitude-longitude axis order; GeoJSON longitude must become longitude.
            $axes = DB::selectOne('SELECT ST_Longitude(`geometry`) AS lng, ST_Latitude(`geometry`) AS lat FROM irrigation_assets WHERE id = ?', [$source]);
            expect([(float) $axes->lng, (float) $axes->lat])->toBe($point);
        }
        $this->withToken($token)->getJson('/api/account/irrigation-assets/map')->assertOk()->assertJsonCount(2, 'data.features')
            ->assertJsonPath('data.features.0.geometry.coordinates', $point)->assertJsonPath('data.features.1.geometry.coordinates', $route);
        $this->withToken($token)->getJson('/api/account/irrigation-assets/summary')->assertOk()
            ->assertJsonPath('data.total_pipeline_length_meters', '575.37')->assertJsonPath('data.mapped_assets', 2);
        $this->withToken($token)->getJson('/api/account/irrigation-assets?search=borewell')->assertOk()->assertJsonCount(1, 'data');

        // Removing the route clears the geometry and its calculated length.
        $this->withToken($token)->patchJson('/api/account/irrigation-assets/'.$pipeline, ['geometry' => null])->assertOk()
            ->assertJsonPath('data.geometry', null)->assertJsonPath('data.length_meters', null);
        expect(DB::selectOne('SELECT `geometry` IS NULL AS missing FROM irrigation_assets WHERE id = ?', [$pipeline])->missing)->toEqual(1);

        // The database rejects what the API would never write.
        foreach ([
            'SRID 0' => ['UPDATE irrigation_assets SET `geometry` = ST_GeomFromGeoJSON(?, 1, 0) WHERE id = ?', ['{"type":"Point","coordinates":[75.8,26.9]}', $source]],
            'category' => ["UPDATE irrigation_assets SET pipeline_type = 'surface' WHERE id = ?", [$source]],
            'asset type' => ["UPDATE irrigation_assets SET asset_type = 'well' WHERE id = ?", [$source]],
            'status' => ["UPDATE irrigation_assets SET status = 'lost' WHERE id = ?", [$source]],
            'dimension' => ['UPDATE irrigation_assets SET depth_meters = -1 WHERE id = ?', [$source]],
            'owner deletion' => ['DELETE FROM users WHERE id = ?', [$farmer->id]],
        ] as $case => [$sql, $bindings]) {
            expect(fn () => DB::statement($sql, $bindings))->toThrow(QueryException::class, null, $case);
        }
    } finally {
        DB::purge('irrigation_spatial_test');
        DB::setDefaultConnection($originalConnection);
        if ($created) {
            $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
        }
    }
});
