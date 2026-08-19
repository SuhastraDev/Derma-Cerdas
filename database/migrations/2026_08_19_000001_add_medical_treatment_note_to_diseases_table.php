<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Untuk penyakit bergolongan educate_only/refer, sistem tidak pernah
 * merekomendasikan obat bebas - tetapi pengguna tetap berhak tahu bagaimana
 * kondisi tersebut LAZIMNYA ditangani secara medis, agar mereka tahu apa yang
 * akan dihadapi saat periksa ke dokter. Kolom ini murni informasi edukatif
 * berbasis sumber rujukan, bukan instruksi swamedikasi - can_recommend_medicine
 * di FusionDecisionService tetap false untuk golongan ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diseases', function (Blueprint $table): void {
            $table->text('medical_treatment_note')->nullable()->after('source_note');
        });
    }

    public function down(): void
    {
        Schema::table('diseases', function (Blueprint $table): void {
            $table->dropColumn('medical_treatment_note');
        });
    }
};
