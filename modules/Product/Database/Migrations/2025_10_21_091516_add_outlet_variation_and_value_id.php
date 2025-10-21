<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //
        Schema::table('product_variants', function (Blueprint $table) {
            $table->bigInteger('outlet_variation_id')->nullable();
        });
        Schema::table('variation_values', function (Blueprint $table) {
            $table->bigInteger('outlet_value_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('outlet_variation_id');
        });

        Schema::table('variation_values', function (Blueprint $table) {
            $table->dropColumn('outlet_value_id');
        });
    }
};
