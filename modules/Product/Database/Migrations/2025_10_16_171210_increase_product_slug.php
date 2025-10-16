<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void {
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug', 255)->change();
        });
    }
    public function down(): void {
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug', 191)->change();
        });
    }
};
