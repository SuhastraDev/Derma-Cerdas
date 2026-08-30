<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyimpan info edukasi kandidat visual yang berada di luar 16 penyakit
 * cakupan (tanpa basis gejala/CF sendiri) TETAPI keputusan akhir tetap
 * disandarkan pada salah satu dari 16 penyakit (mis. F04: CF gejala tinggi
 * mengalahkan kandidat visual). Sebelumnya info ini hilang total dari layar
 * karena panel edukasi hanya tampil untuk action educate_only/refer -
 * padahal action recommend_otc_mismatch (F04) juga bisa menyembunyikan
 * temuan visual yang relevan. Kolom ini murni tambahan: tidak pernah
 * mengubah disease_id/action/recommendations_snapshot yang sudah dihitung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultation_final_results', function (Blueprint $table): void {
            $table->jsonb('secondary_visual_note')->nullable()->after('recommendations_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('consultation_final_results', function (Blueprint $table): void {
            $table->dropColumn('secondary_visual_note');
        });
    }
};
