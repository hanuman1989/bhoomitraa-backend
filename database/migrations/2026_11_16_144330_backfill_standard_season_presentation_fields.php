<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (['rabi' => [10, 3, 'sprout'], 'kharif' => [6, 10, 'sun'], 'zaid' => [3, 6, 'leaf']] as $slug => [$start, $end, $icon]) {
                DB::table('seasons')->where('slug', $slug)->whereNull('start_month')->whereNull('end_month')
                    ->update(['start_month' => $start, 'end_month' => $end]);
                DB::table('seasons')->where('slug', $slug)->whereNull('icon')->update(['icon' => $icon]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Defaults cannot be distinguished safely from later admin choices. Schema rollback removes only the three presentation columns.
    }
};
