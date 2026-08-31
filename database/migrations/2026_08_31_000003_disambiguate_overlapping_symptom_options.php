<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kasus produksi: foto Ichthyosis asli + gejala yang sudah dijawab benar
 * tetap tidak yakin karena user bingung memilih di antara opsi yang
 * kalimatnya tumpang tindih. Tiga celah konkret ditemukan:
 *
 * - P1_BADAN tidak menyebut tungkai/betis secara eksplisit, sedangkan
 *   P1_KAKI berbunyi "sela jari atau telapak kaki" - user dengan keluhan
 *   di betis/tungkai (bukan sela jari atau telapak) tidak tahu harus pilih
 *   yang mana.
 * - P3_KERING ("kering dan pecah-pecah") dan P3_SISIKIKAN ("sisik besar
 *   kotak-kotak seperti sisik ikan, kering di sela-selanya") sama-sama
 *   menyebut "kering", padahal keduanya seharusnya saling meniadakan -
 *   pembedanya adalah ada/tidaknya pola geometris besar, bukan kering
 *   atau tidaknya.
 * - P4_GATAL vs P4_TIDAKTERASA tidak punya batas yang jelas untuk gatal
 *   ringan (mis. iktiosis: gatal ringan, CF lemah) - user dengan gatal
 *   ringan tidak tahu harus pilih "Gatal" atau "Tidak gatal dan tidak
 *   nyeri".
 *
 * Perbaikan ini murni penjelasan (option_label/option_explanation),
 * kodenya tetap sama sehingga tidak menyentuh matrix CF ataupun logika
 * fusion. Sama seperti 2026_08_31_000001, deploy.yml hanya menjalankan
 * `migrate` (bukan `db:seed`), jadi baris `symptoms` yang sudah ada di
 * production perlu ditimpa lewat migration data sempit ini.
 */
return new class extends Migration
{
    private const RELABELED = [
        'P1_KAKI' => [
            'Sela jari kaki atau telapak kaki',
            'Khusus di antara jari-jari kaki atau bagian bawah telapak — bukan tungkai/betis, itu termasuk pilihan "Badan, punggung, tungkai, atau lengan" di bawah',
        ],
        'P1_BADAN' => [
            'Badan, punggung, tungkai, atau lengan',
            'Area tubuh yang luas dan tertutup pakaian, termasuk betis/tungkai kaki (bukan telapak atau sela jari). Kalau menyebar di banyak area, pilih yang paling luas atau paling mengganggu',
        ],
        'P3_KERING' => [
            'Kering dan pecah-pecah, tanpa pola kotak-kotak',
            'Kulit terasa kaku, retak, kadang perih — permukaannya tidak membentuk pola geometris besar. Kalau sudah terlihat pola kotak-kotak seperti sisik ikan, pilih opsi di bawah ini, bukan opsi ini',
        ],
        'P3_SISIKIKAN' => [
            'Sisik besar berbentuk kotak-kotak seperti sisik ikan',
            'Pola geometris besar menyerupai sisik ikan, menutupi area kulit yang luas — kulit di sela-sela sisiknya juga terasa kering, tapi ciri utamanya adalah pola kotak-kotaknya, bukan sekadar kering',
        ],
        'P4_GATAL' => [
            'Gatal',
            'Ingin menggaruk, dari yang ringan sesekali sampai mengganggu tidur — pilih ini walau gatalnya cuma ringan, asal memang ada dorongan menggaruk',
        ],
        'P4_TIDAKTERASA' => [
            'Tidak gatal dan tidak nyeri',
            'Hanya terlihat atau terasa kering/kencang saja, tanpa dorongan menggaruk maupun rasa nyeri',
        ],
    ];

    private const REVERTED = [
        'P1_KAKI' => ['Sela jari atau telapak kaki', 'Di antara jari kaki atau bagian bawah telapak'],
        'P1_BADAN' => ['Badan, punggung, atau lengan', 'Area tubuh yang luas dan tertutup pakaian'],
        'P3_KERING' => ['Kering dan pecah-pecah', 'Kulit terasa kaku, retak, kadang perih'],
        'P3_SISIKIKAN' => ['Sisik besar berbentuk kotak-kotak seperti sisik ikan', 'Menutupi area kulit yang luas, tampak kering di sela-sela sisiknya'],
        'P4_GATAL' => ['Gatal', 'Ingin menggaruk, kadang sampai mengganggu tidur'],
        'P4_TIDAKTERASA' => ['Tidak gatal dan tidak nyeri', 'Hanya terlihat, tanpa keluhan rasa'],
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
        foreach (self::REVERTED as $code => [$label, $explanation]) {
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
};
