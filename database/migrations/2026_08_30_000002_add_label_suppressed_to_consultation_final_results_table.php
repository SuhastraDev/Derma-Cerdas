<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regresi produksi 2026-08-30: pengguna mengunggah foto bisul (di luar 16
 * penyakit) dan menjawab pertanyaan seadanya. Satu-satunya opsi bentuk yang
 * "paling mendekati" (Benjolan tunggal mengkilap) kebetulan menjadi gejala
 * paling khas Basal Cell Carcinoma (bobot pakar 0,80) - satu jawaban itu saja
 * sudah cukup melewati ambang CF tinggi, sehingga sistem menampilkan
 * "Karsinoma sel basal" walau cuma 1 dari 7 gejala penyakit itu yang cocok.
 *
 * Kolom ini menandai kapan nama penyakit HARUS disembunyikan dari tampilan
 * utama (diganti "Belum dapat dipastikan") karena keyakinannya dibangun dari
 * bukti yang terlalu tipis untuk menyebut satu nama spesifik - beda dari CF
 * rendah biasa (banyak gejala dijawab tapi memang lemah), yang tetap layak
 * menampilkan nama sebagai informasi awal. action/disease_id tidak berubah -
 * murni soal apa yang ditampilkan ke pengguna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultation_final_results', function (Blueprint $table): void {
            $table->boolean('label_suppressed')->default(false)->after('secondary_visual_note');
        });
    }

    public function down(): void
    {
        Schema::table('consultation_final_results', function (Blueprint $table): void {
            $table->dropColumn('label_suppressed');
        });
    }
};
