<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Changes products.minimum_stock's column DEFAULT from 0 to 5, for every
 * NEW product created from now on. Deliberately does not UPDATE a single
 * existing row - a product already sitting at 0 (or any other value) may
 * have had that set deliberately, and this has no way to tell "left at
 * the old default" apart from "chosen on purpose", so it leaves every
 * existing row exactly as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('minimum_stock', 15, 4)->default(5)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('minimum_stock', 15, 4)->default(0)->change();
        });
    }
};
