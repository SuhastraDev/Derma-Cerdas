<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto obat diambil dari Wikimedia Commons berlisensi CC BY-SA, yang
 * mensyaratkan atribusi eksplisit - kolom ini terpisah dari source_note
 * (yang berisi rujukan dosis/aturan pakai) supaya kreditnya tidak tercampur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table): void {
            $table->string('image_credit')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('medicines', function (Blueprint $table): void {
            $table->dropColumn('image_credit');
        });
    }
};
