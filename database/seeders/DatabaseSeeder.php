<?php

namespace Database\Seeders;

use App\Models\DatasetClassMapping;
use App\Models\Disease;
use App\Models\DiseaseMedicineRecommendation;
use App\Models\DiseaseSymptomRule;
use App\Models\Medicine;
use App\Models\RedFlag;
use App\Models\Setting;
use App\Models\Symptom;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->seedAdminUser();
        $diseases = $this->seedDiseases();
        $symptoms = $this->seedSymptoms();
        $this->seedRules($diseases, $symptoms);
        $medicines = $this->seedMedicines();
        $this->seedRecommendations($diseases, $medicines);
        $this->seedRedFlags();
        $this->seedSettings();
        $this->seedDatasetMappings($diseases);
    }

    private function seedAdminUser(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@dermacerdas.local'],
            [
                'name' => 'Admin DermaCerdas',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ],
        );
    }

    /**
     * @return array<string, Disease>
     */
    private function seedDiseases(): array
    {
        $rows = [
            ['Eczema', 'Eksim / dermatitis umum', 'Dermatitis ringan dengan gatal, kemerahan, dan kulit kering.'],
            ['Acute_Eczema', 'Eksim akut ringan', 'Eksim dengan ruam baru, kemerahan, gatal, dan kadang berair ringan.'],
            ['Dry_Skin_Eczema', 'Eksim kulit kering', 'Keluhan kulit kering, bersisik, dan gatal yang cocok untuk edukasi pelembap.'],
            ['Allergic_Contact_Dermatitis', 'Dermatitis kontak alergi', 'Ruam dan gatal setelah kontak bahan pemicu seperti sabun, kosmetik, logam, atau tanaman.'],
            ['Urticaria', 'Biduran / urtikaria ringan', 'Bentol gatal yang muncul-hilang, biasanya terkait alergi ringan atau pemicu tertentu.'],
            ['Candidiasis', 'Kandidiasis kulit ringan', 'Infeksi jamur superfisial terutama pada area lembap atau lipatan.'],
            ['Tinea_Corporis', 'Kurap badan', 'Infeksi jamur superfisial pada badan, sering berbentuk melingkar.'],
            ['Tinea_Cruris', 'Jamur lipatan paha ringan', 'Infeksi jamur pada lipatan paha yang gatal dan lembap.'],
            ['Tinea_Pedis', 'Kutu air / jamur kaki', 'Infeksi jamur pada sela jari atau telapak kaki.'],
            ['Tinea_Versicolor', 'Panu', 'Infeksi jamur superfisial dengan bercak lebih terang atau gelap dan sisik halus.'],
        ];

        $diseases = [];

        foreach ($rows as [$className, $nameIndonesian, $description]) {
            $code = Str::upper($className);
            $diseases[$code] = Disease::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => str_replace('_', ' ', $className),
                    'slug' => Str::slug($className),
                    'name_indonesian' => $nameIndonesian,
                    'description' => $description,
                    'severity_scope' => 'mild',
                    'default_action' => str_contains($code, 'DRY_SKIN') ? 'educate_only' : 'recommend_otc',
                    'is_active' => true,
                ],
            );
        }

        return $diseases;
    }

    /**
     * @return array<string, Symptom>
     */
    private function seedSymptoms(): array
    {
        $rows = [
            ['ITCHING', 'Gatal', 'Apakah area kulit terasa gatal?', 'scale'],
            ['RED_RASH', 'Ruam kemerahan', 'Apakah tampak ruam atau kemerahan pada area kulit?', 'scale'],
            ['DRY_SCALY_SKIN', 'Kulit kering/bersisik', 'Apakah kulit terasa kering, kasar, atau bersisik?', 'scale'],
            ['VESICLES_OOZING', 'Bintil/berair ringan', 'Apakah ada bintil kecil atau cairan ringan pada ruam?', 'scale'],
            ['CONTACT_TRIGGER', 'Riwayat kontak pemicu', 'Apakah keluhan muncul setelah kontak sabun, kosmetik, logam, tanaman, atau bahan tertentu?', 'scale'],
            ['WHEALS_COME_GO', 'Bentol hilang timbul', 'Apakah bentol gatal muncul lalu menghilang atau berpindah?', 'scale'],
            ['RING_SHAPED_EDGE', 'Lesi melingkar', 'Apakah ruam tampak melingkar dengan tepi lebih merah/aktif?', 'scale'],
            ['MOIST_FOLD_RASH', 'Ruam area lembap/lipatan', 'Apakah keluhan dominan di area lembap atau lipatan kulit?', 'scale'],
            ['FOOT_SCALING', 'Sisik/gatal kaki', 'Apakah ada sisik, pecah-pecah, atau gatal di sela jari/telapak kaki?', 'scale'],
            ['WHITE_BROWN_PATCHES', 'Bercak putih/cokelat halus', 'Apakah ada bercak putih/cokelat dengan sisik halus?', 'scale'],
            ['BURNING_STINGING', 'Perih/panas ringan', 'Apakah area terasa perih atau panas ringan?', 'scale'],
            ['RECURRENT_OR_DAYS', 'Durasi beberapa hari', 'Apakah keluhan sudah berlangsung beberapa hari dan tidak berat?', 'scale'],
        ];

        $symptoms = [];

        foreach ($rows as [$code, $name, $question, $inputType]) {
            $symptoms[$code] = Symptom::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'question' => $question,
                    'input_type' => $inputType,
                    'is_red_flag_candidate' => false,
                    'is_active' => true,
                ],
            );
        }

        return $symptoms;
    }

    /**
     * @param array<string, Disease> $diseases
     * @param array<string, Symptom> $symptoms
     */
    private function seedRules(array $diseases, array $symptoms): void
    {
        $rules = [
            'ECZEMA' => ['ITCHING' => 0.70, 'RED_RASH' => 0.65, 'DRY_SCALY_SKIN' => 0.70, 'RECURRENT_OR_DAYS' => 0.40],
            'ACUTE_ECZEMA' => ['ITCHING' => 0.70, 'RED_RASH' => 0.70, 'VESICLES_OOZING' => 0.60, 'BURNING_STINGING' => 0.35],
            'DRY_SKIN_ECZEMA' => ['ITCHING' => 0.55, 'DRY_SCALY_SKIN' => 0.85, 'RED_RASH' => 0.35, 'RECURRENT_OR_DAYS' => 0.30],
            'ALLERGIC_CONTACT_DERMATITIS' => ['CONTACT_TRIGGER' => 0.85, 'ITCHING' => 0.70, 'RED_RASH' => 0.65, 'BURNING_STINGING' => 0.45],
            'URTICARIA' => ['WHEALS_COME_GO' => 0.90, 'ITCHING' => 0.80, 'CONTACT_TRIGGER' => 0.45, 'RED_RASH' => 0.35],
            'CANDIDIASIS' => ['MOIST_FOLD_RASH' => 0.80, 'ITCHING' => 0.65, 'RED_RASH' => 0.55, 'BURNING_STINGING' => 0.45],
            'TINEA_CORPORIS' => ['RING_SHAPED_EDGE' => 0.90, 'ITCHING' => 0.60, 'RED_RASH' => 0.55, 'DRY_SCALY_SKIN' => 0.45],
            'TINEA_CRURIS' => ['MOIST_FOLD_RASH' => 0.75, 'ITCHING' => 0.65, 'RED_RASH' => 0.55, 'RING_SHAPED_EDGE' => 0.50],
            'TINEA_PEDIS' => ['FOOT_SCALING' => 0.90, 'ITCHING' => 0.55, 'DRY_SCALY_SKIN' => 0.50, 'BURNING_STINGING' => 0.35],
            'TINEA_VERSICOLOR' => ['WHITE_BROWN_PATCHES' => 0.90, 'DRY_SCALY_SKIN' => 0.45, 'ITCHING' => 0.30, 'RECURRENT_OR_DAYS' => 0.30],
        ];

        foreach ($rules as $diseaseCode => $symptomRules) {
            foreach ($symptomRules as $symptomCode => $mb) {
                $md = $mb >= 0.80 ? 0.05 : 0.10;

                DiseaseSymptomRule::query()->updateOrCreate(
                    [
                        'disease_id' => $diseases[$diseaseCode]->id,
                        'symptom_id' => $symptoms[$symptomCode]->id,
                    ],
                    [
                        'mb' => $mb,
                        'md' => $md,
                        'expert_cf' => round($mb - $md, 2),
                        'is_required' => $mb >= 0.80,
                        'note' => 'Rule awal MVP; perlu validasi pakar/apoteker sebelum klaim klinis final.',
                    ],
                );
            }
        }
    }

    /**
     * @return array<string, Medicine>
     */
    private function seedMedicines(): array
    {
        $rows = [
            'EMOLLIENT' => ['Pelembap / emollient', 'non_obat', 'krim/lotion', 'Gunakan rutin pada area kering setelah mandi dan saat kulit terasa kering.', 'Hentikan jika iritasi memburuk.', false],
            'CALAMINE' => ['Calamine lotion', 'anti_gatal_topikal', 'lotion', 'Oleskan tipis pada area gatal ringan sesuai petunjuk kemasan.', 'Tidak untuk luka terbuka luas, mata, atau mukosa.', true],
            'CLOTRIMAZOLE' => ['Clotrimazole topikal', 'antijamur_topikal', 'krim', 'Oleskan tipis pada area jamur ringan sesuai petunjuk kemasan.', 'Rujuk jika luas, bernanah, nyeri berat, atau tidak membaik.', true],
            'MICONAZOLE' => ['Miconazole topikal', 'antijamur_topikal', 'krim/bedak', 'Gunakan pada area jamur ringan dan jaga area tetap kering.', 'Hindari area mata dan luka terbuka.', true],
            'KETOCONAZOLE_TOPICAL' => ['Ketoconazole topikal', 'antijamur_topikal', 'sampo/krim', 'Gunakan sesuai petunjuk kemasan untuk bercak jamur superfisial.', 'Rujuk jika keluhan luas, berulang berat, atau mengenai area sensitif.', true],
            'AVOID_TRIGGER' => ['Edukasi hindari pemicu', 'edukasi', 'non-obat', 'Hindari bahan pemicu, jangan menggaruk, dan gunakan sabun lembut.', 'Segera periksa jika muncul red flags.', false],
        ];

        $medicines = [];

        foreach ($rows as $code => [$name, $category, $form, $usage, $warnings, $limited]) {
            $medicines[$code] = Medicine::query()->updateOrCreate(
                ['name' => $name],
                [
                    'category' => $category,
                    'dosage_form' => $form,
                    'usage_instruction' => $usage,
                    'warnings' => $warnings,
                    'source_note' => 'Rujukan awal: Pedoman penggunaan obat bebas/bebas terbatas Kemenkes dan verifikasi BPOM. Validasi pakar tetap diperlukan.',
                    'is_limited_otc' => $limited,
                    'is_active' => true,
                ],
            );
        }

        return $medicines;
    }

    /**
     * @param array<string, Disease> $diseases
     * @param array<string, Medicine> $medicines
     */
    private function seedRecommendations(array $diseases, array $medicines): void
    {
        $links = [
            'ECZEMA' => ['EMOLLIENT', 'CALAMINE', 'AVOID_TRIGGER'],
            'ACUTE_ECZEMA' => ['EMOLLIENT', 'CALAMINE', 'AVOID_TRIGGER'],
            'DRY_SKIN_ECZEMA' => ['EMOLLIENT', 'AVOID_TRIGGER'],
            'ALLERGIC_CONTACT_DERMATITIS' => ['AVOID_TRIGGER', 'CALAMINE', 'EMOLLIENT'],
            'URTICARIA' => ['CALAMINE', 'AVOID_TRIGGER'],
            'CANDIDIASIS' => ['CLOTRIMAZOLE', 'MICONAZOLE'],
            'TINEA_CORPORIS' => ['CLOTRIMAZOLE', 'MICONAZOLE'],
            'TINEA_CRURIS' => ['CLOTRIMAZOLE', 'MICONAZOLE'],
            'TINEA_PEDIS' => ['MICONAZOLE', 'CLOTRIMAZOLE'],
            'TINEA_VERSICOLOR' => ['KETOCONAZOLE_TOPICAL', 'CLOTRIMAZOLE'],
        ];

        foreach ($links as $diseaseCode => $medicineCodes) {
            foreach ($medicineCodes as $priority => $medicineCode) {
                DiseaseMedicineRecommendation::query()->updateOrCreate(
                    [
                        'disease_id' => $diseases[$diseaseCode]->id,
                        'medicine_id' => $medicines[$medicineCode]->id,
                    ],
                    [
                        'recommendation_note' => 'Tampilkan hanya jika skor melewati threshold dan tidak ada red flags.',
                        'priority' => $priority + 1,
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    private function seedRedFlags(): void
    {
        $rows = [
            ['FEVER_HIGH', 'Apakah disertai demam tinggi?', 'Ada tanda risiko infeksi sistemik. Segera konsultasi ke dokter/puskesmas.', 'refer'],
            ['SEVERE_PAIN', 'Apakah area kulit terasa sangat nyeri?', 'Nyeri berat tidak aman untuk swamedikasi. Perlu pemeriksaan langsung.', 'refer'],
            ['FAST_SPREADING_SWELLING', 'Apakah bengkak/kemerahan menyebar cepat?', 'Penyebaran cepat perlu evaluasi medis.', 'refer'],
            ['PUS_OR_WIDE_INFECTION', 'Apakah ada nanah luas atau luka bernanah?', 'Nanah luas bukan target rekomendasi obat bebas terbatas.', 'refer'],
            ['OPEN_WOUND_LARGE', 'Apakah terdapat luka terbuka yang luas?', 'Luka luas memerlukan penilaian risiko infeksi dan perawatan luka.', 'refer'],
            ['BLACKENED_SKIN', 'Apakah kulit tampak menghitam atau seperti jaringan mati?', 'Kemungkinan kondisi serius. Segera cari bantuan medis.', 'urgent_refer'],
            ['WIDESPREAD_RASH', 'Apakah ruam menyebar hampir ke seluruh tubuh?', 'Ruam luas dapat terkait reaksi sistemik.', 'refer'],
            ['BREATHING_OR_FACE_SWELLING', 'Apakah ada sesak napas atau bengkak pada bibir, mata, atau wajah?', 'Kemungkinan alergi berat. Segera cari bantuan medis.', 'urgent_refer'],
            ['SUSPICIOUS_LESION', 'Apakah lesi berdarah, berubah cepat, atau tampak mencurigakan?', 'Lesi mencurigakan perlu pemeriksaan dokter.', 'refer'],
            ['SENSITIVE_AREA_SEVERE', 'Apakah keluhan berat berada di area mata, kelamin, atau wajah?', 'Area sensitif perlu kehati-hatian dan pemeriksaan langsung.', 'refer'],
            ['NO_IMPROVEMENT', 'Apakah keluhan tidak membaik setelah beberapa hari perawatan mandiri?', 'Kegagalan swamedikasi perlu evaluasi profesional.', 'refer'],
            ['VULNERABLE_PATIENT', 'Apakah pasien bayi, ibu hamil, lansia rentan, diabetes, atau daya tahan tubuh lemah?', 'Kelompok rentan memiliki risiko komplikasi lebih tinggi.', 'refer'],
        ];

        foreach ($rows as [$code, $question, $message, $severity]) {
            RedFlag::query()->updateOrCreate(
                ['code' => $code],
                [
                    'question' => $question,
                    'action_message' => $message,
                    'severity' => $severity,
                    'is_active' => true,
                ],
            );
        }
    }

    private function seedSettings(): void
    {
        $settings = [
            ['visual_weight', ['value' => 0.4], 'decision', 'Bobot awal skor visual Gemini.'],
            ['text_weight', ['value' => 0.6], 'decision', 'Bobot awal skor tekstual Certainty Factor.'],
            ['decision_threshold', ['value' => 0.6], 'decision', 'Ambang minimal rekomendasi obat.'],
            ['max_image_size_mb', ['value' => 5], 'upload', 'Ukuran gambar maksimal untuk konsultasi.'],
            ['gemini_model_name', ['value' => 'gemini-1.5-flash'], 'ai', 'Nama model Gemini default untuk analisis visual.'],
        ];

        foreach ($settings as [$key, $value, $group, $description]) {
            Setting::query()->updateOrCreate(
                ['key' => $key],
                compact('value', 'group', 'description'),
            );
        }
    }

    /**
     * @param array<string, Disease> $diseases
     */
    private function seedDatasetMappings(array $diseases): void
    {
        $classNames = [
            'ECZEMA' => ['Eczema', 'Eksim / dermatitis umum'],
            'ACUTE_ECZEMA' => ['Acute_Eczema', 'Eksim akut ringan'],
            'DRY_SKIN_ECZEMA' => ['Dry_Skin_Eczema', 'Eksim kulit kering'],
            'ALLERGIC_CONTACT_DERMATITIS' => ['Allergic_Contact_Dermatitis', 'Dermatitis kontak alergi'],
            'URTICARIA' => ['Urticaria', 'Biduran / urtikaria ringan'],
            'CANDIDIASIS' => ['Candidiasis', 'Kandidiasis kulit ringan'],
            'TINEA_CORPORIS' => ['Tinea_Corporis', 'Kurap badan'],
            'TINEA_CRURIS' => ['Tinea_Cruris', 'Jamur lipatan paha ringan'],
            'TINEA_PEDIS' => ['Tinea_Pedis', 'Kutu air / jamur kaki'],
            'TINEA_VERSICOLOR' => ['Tinea_Versicolor', 'Panu'],
        ];

        $index = 1;

        foreach ($classNames as $diseaseCode => [$className, $namaIndonesia]) {
            DatasetClassMapping::query()->updateOrCreate(
                ['dataset_class_name' => $className],
                [
                    'dataset_class_id' => $index,
                    'nama_indonesia' => $namaIndonesia,
                    'scope_category' => 'swamedikasi',
                    'boleh_rekomendasi_obat' => $diseaseCode !== 'DRY_SKIN_ECZEMA',
                    'default_action' => $diseaseCode === 'DRY_SKIN_ECZEMA' ? 'educate_only' : 'recommend_otc',
                    'disease_id' => $diseases[$diseaseCode]->id,
                    'risk_note' => 'Mapping MVP awal. Sesuaikan dataset_class_id setelah import classes.txt SD-198 asli.',
                ],
            );

            $index++;
        }
    }
}
