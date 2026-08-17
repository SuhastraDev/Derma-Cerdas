<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank pertanyaan baru berbentuk pilihan ganda yang saling meniadakan, bukan
 * daftar "apakah ada gejala X?" yang menumpuk. Setiap PILIHAN JAWABAN disimpan
 * sebagai satu baris symptoms, sehingga CertaintyFactorService dan tabel
 * disease_symptom_rules tidak perlu diubah sama sekali: pilihan yang dipilih
 * bernilai user_cf 1.0, sisanya 0.
 *
 * Kolom di bawah hanya menambah informasi penyajian - pertanyaan mana yang
 * menaungi sebuah pilihan, label singkatnya, dan penjelasan satu barisnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('symptoms', function (Blueprint $table): void {
            $table->string('question_group')->nullable()->index()->after('question');
            $table->text('question_text')->nullable()->after('question_group');
            $table->string('option_label')->nullable()->after('question_text');
            $table->text('option_explanation')->nullable()->after('option_label');
            $table->unsignedInteger('display_order')->default(0)->after('option_explanation');
        });
    }

    public function down(): void
    {
        Schema::table('symptoms', function (Blueprint $table): void {
            $table->dropColumn([
                'question_group',
                'question_text',
                'option_label',
                'option_explanation',
                'display_order',
            ]);
        });
    }
};
