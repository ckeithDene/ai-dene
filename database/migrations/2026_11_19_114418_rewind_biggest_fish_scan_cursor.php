<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Orders already walked while ChatGPT had no tokens are checked again.
     * Customers with a finished review stay finished.
     */
    public function up(): void
    {
        DB::table('biggest_fish_scans')->update([
            'last_order_id' => 0,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The previous order id was not stored.
    }
};
