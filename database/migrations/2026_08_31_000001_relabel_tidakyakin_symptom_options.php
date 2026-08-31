<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Regresi produksi berulang (bisul -> Basal Cell Carcinoma, dkk): opsi
 * pelarian "Tidak yakin" di tiap kelompok pertanyaan gejala sudah ada sejak
 * awal dan memang tidak pernah menyumbang CF ke penyakit manapun - tapi
 * labelnya membingkai untuk "saya ragu dengan gejala saya sendiri", bukan
 * "saya yakin dengan gejala saya, tapi tidak ada pilihan yang cocok". User
 * yang YAKIN melihat benjolan berisi nanah tidak terpikir memilih "Tidak
 * yakin", dan malah memaksa pilih opsi yang paling mendekati (mis. "Benjolan
 * tunggal mengkilap") - persis mekanisme yang berulang kali menghasilkan
 * label penyakit yang salah dari bukti tipis.
 *
 * QuestionBank::questions() (sumber kebenaran untuk seeding awal) sudah
 * diperbarui teksnya, tapi baris `symptoms` yang sudah ada di production
 * tidak otomatis ikut berubah - deploy.yml hanya menjalankan `migrate`,
 * bukan `db:seed` (dan memang tidak boleh: DatabaseSeeder::seedAdminUser()
 * me-reset password admin ke default setiap kali seeder penuh dijalankan).
 * Migration data sempit ini menyalin ulang teks 7 opsi itu saja, memakai
 * mekanisme `migrate` yang sudah ada di pipeline deploy tanpa risiko apa pun
 * ke data lain.
 */
return new class extends Migration
{
    private const RELABELED = [
        'P1_TIDAKYAKIN' => [
            'Tidak yakin / tidak ada yang cocok',
            'Pilih ini juga kalau Anda yakin dengan lokasinya tapi tidak ada pilihan di atas yang pas — jawaban asal justru menyesatkan',
        ],
        'P2_TIDAKYAKIN' => [
            'Tidak yakin / tidak ada yang cocok',
            'Pilih ini juga kalau Anda yakin dengan bentuknya tapi tidak ada pilihan di atas yang pas — jangan paksa pilih yang paling mendekati',
        ],
        'P3_TIDAKYAKIN' => [
            'Tidak yakin / tidak ada yang cocok',
            'Pilih ini juga kalau tidak ada pilihan di atas yang menggambarkan permukaannya dengan tepat',
        ],
        'P4_TIDAKYAKIN' => [
            'Tidak yakin / tidak ada yang cocok',
            'Pilih ini juga kalau rasanya tidak digambarkan tepat oleh pilihan di atas',
        ],
        'P5_TIDAKYAKIN' => [
            'Tidak yakin / tidak ada yang cocok',
            'Pilih ini juga kalau perjalanannya tidak digambarkan tepat oleh pilihan di atas',
        ],
        'P6_TIDAKYAKIN' => [
            'Tidak yakin / tidak ada yang cocok',
            'Pilih ini juga kalau sebarannya tidak digambarkan tepat oleh pilihan di atas',
        ],
        'P7_TIDAKYAKIN' => [
            'Tidak yakin / tidak ada yang cocok',
            'Pilih ini juga kalau tidak ada pemicu yang digambarkan tepat oleh pilihan di atas',
        ],
    ];

    public function up(): void
    {
        foreach (self::RELABELED as $code => [$label, $explanation]) {
            DB::table('symptoms')
                ->where('code', $code)
                ->update([
                    'name' => $label,
                    'option_label' => $label,
                    'option_explanation' => $explanation,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        DB::table('symptoms')
            ->whereIn('code', array_keys(self::RELABELED))
            ->update([
                'name' => 'Tidak yakin',
                'option_label' => 'Tidak yakin',
                'option_explanation' => 'Lewati bila ragu',
                'updated_at' => now(),
            ]);
    }
};
