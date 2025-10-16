<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::table('product_translations', function (Blueprint $table) {
            $table->string('name', 255)->change();
        });
    }
    public function down(): void {
        Schema::table('product_translations', function (Blueprint $table) {
            $table->string('name', 191)->change();
        });
    }
};
