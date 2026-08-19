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
use App\Support\DatasetDiseaseMapper;
use App\Support\QuestionBank;
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

        $scopeDiseases = $this->seedScopeDiseases();
        $this->seedRecommendations($scopeDiseases, $medicines);
        $options = $this->seedQuestionBank();
        $this->seedQuestionRules($scopeDiseases, $options);
        $this->retireOutOfScopeDiseases(array_keys($scopeDiseases));
        $this->deactivateOrphanedSymptoms(array_keys($options));
    }

    /**
     * 16 kelas ruang lingkup beserta pemetaannya ke kelas SD-198.
     *
     * @return array<string, Disease>
     */
    private function seedScopeDiseases(): array
    {
        $diseases = [];

        foreach (QuestionBank::diseases() as $row) {
            $disease = Disease::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'slug' => Str::slug($row['code']),
                    'name_indonesian' => $row['name_indonesian'],
                    'description' => $row['description'],
                    'severity_scope' => $row['default_action'] === 'refer' ? 'moderate' : 'mild',
                    'default_action' => $row['default_action'],
                    'is_active' => true,
                ],
            );

            DatasetClassMapping::query()->updateOrCreate(
                ['dataset_class_name' => $row['dataset_class']],
                [
                    'dataset_class_id' => $row['dataset_class_id'],
                    'nama_indonesia' => $row['name_indonesian'],
                    'scope_category' => match ($row['default_action']) {
                        'refer' => 'rujuk',
                        'recommend_otc' => 'swamedikasi',
                        default => 'edukasi',
                    },
                    'boleh_rekomendasi_obat' => $row['default_action'] === 'recommend_otc',
                    'default_action' => $row['default_action'],
                    'disease_id' => $disease->id,
                ],
            );

            $diseases[$row['code']] = $disease;
        }

        return $diseases;
    }

    /**
     * Setiap PILIHAN jawaban disimpan sebagai satu baris symptoms, sehingga
     * CertaintyFactorService tidak perlu diubah: pilihan terpilih bernilai
     * user_cf 1.0, sisanya 0.
     *
     * @return array<string, Symptom>
     */
    private function seedQuestionBank(): array
    {
        $options = [];

        foreach (QuestionBank::questions() as $group => $question) {
            $urutan = 0;

            foreach ($question['options'] as $code => [$label, $explanation]) {
                $urutan++;
                $options[$code] = Symptom::query()->updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $label,
                        'question' => $question['text'],
                        'question_group' => $group,
                        'question_text' => $question['text'],
                        'option_label' => $label,
                        'option_explanation' => $explanation,
                        'display_order' => $question['order'] * 100 + $urutan,
                        'input_type' => 'choice',
                        'is_red_flag_candidate' => false,
                        'is_active' => true,
                    ],
                );
            }
        }

        return $options;
    }

    /**
     * Matriks CF pakar untuk bank pertanyaan baru.
     *
     * is_required sengaja tidak dipakai sama sekali. Gerbang "wajib" pada bank
     * lama memaksa CF menjadi nol begitu satu gejala tidak terjawab, sehingga
     * penyakit dengan banyak gejala wajib nyaris mustahil menang melawan
     * penyakit tanpa gejala wajib. Pada pilihan ganda gerbang itu tidak
     * diperlukan: memilih satu lokasi otomatis menihilkan lokasi lainnya.
     *
     * @param  array<string, Disease>  $diseases
     * @param  array<string, Symptom>  $options
     */
    private function seedQuestionRules(array $diseases, array $options): void
    {
        foreach (QuestionBank::matrix() as $diseaseCode => $optionCfs) {
            $disease = $diseases[$diseaseCode] ?? null;

            if (! $disease) {
                continue;
            }

            DiseaseSymptomRule::query()
                ->where('disease_id', $disease->id)
                ->whereNotIn('symptom_id', collect($options)->pluck('id')->all())
                ->delete();

            foreach ($optionCfs as $optionCode => $cf) {
                $option = $options[$optionCode] ?? null;

                if (! $option) {
                    continue;
                }

                [$mb, $md] = QuestionBank::beliefPair($cf);

                DiseaseSymptomRule::query()->updateOrCreate(
                    ['disease_id' => $disease->id, 'symptom_id' => $option->id],
                    [
                        'mb' => $mb,
                        'md' => $md,
                        'expert_cf' => round($mb - $md, 2),
                        'is_required' => false,
                        'note' => 'Bank pertanyaan pilihan ganda 16 kelas; pengodean 0,80/0,60/0,40/0,20.',
                    ],
                );
            }
        }
    }

    /**
     * Penyakit di luar 16 kelas ruang lingkup dinonaktifkan dan aturan gejalanya
     * dicabut, agar hanya kelas yang basis pengetahuannya disusun yang ikut
     * dinilai. Kelas dataset miliknya dialihkan ke grup klinis yang benar.
     *
     * @param  array<int, string>  $scopeCodes
     */
    private function retireOutOfScopeDiseases(array $scopeCodes): void
    {
        $ids = Disease::query()->whereNotIn('code', $scopeCodes)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DiseaseSymptomRule::query()->whereIn('disease_id', $ids)->delete();

        DatasetClassMapping::query()
            ->whereIn('disease_id', $ids)
            ->get()
            ->each(function (DatasetClassMapping $mapping): void {
                try {
                    $payload = DatasetDiseaseMapper::payloadFor($mapping->dataset_class_name);
                } catch (\InvalidArgumentException) {
                    return;
                }

                $group = Disease::query()->firstOrNew(['code' => $payload['disease']['code']]);
                $group->fill($payload['disease'])->save();

                $mapping->fill($payload['mapping']);
                $mapping->disease_id = $group->id;
                $mapping->save();
            });

        Disease::query()
            ->whereIn('id', $ids)
            ->whereNotIn('code', $scopeCodes)
            ->update(['is_active' => false]);
    }

    /**
     * Lima penyakit MVP awal (Eczema, Acute_Eczema, Dry_Skin_Eczema, Urticaria,
     * Candidiasis) berada di luar ruang lingkup naskah (P01-P05) dan nilai MB/MD
     * warisannya tidak dapat ditelusuri ke sumber mana pun. Selama mereka masih
     * memiliki aturan gejala, dua hal merusak hasil:
     *
     * 1. Mereka ikut dinilai pada rankDiseases() tanpa satu pun gejala wajib
     *    (mb < 0,80), sehingga tidak pernah bisa di-nol-kan oleh
     *    missing_required_symptoms. Sementara kelima penyakit naskah justru
     *    memiliki 1-5 gejala wajib. Akibatnya Eczema hampir selalu menang hanya
     *    dari "gatal" + "kemerahan" (CF 0,82), termasuk mengalahkan P01-P05.
     * 2. Penjaga pada dataset:import-classes menganggap penyakit yang punya
     *    aturan gejala sebagai "tervalidasi" dan tidak pernah melipat kelas
     *    datasetnya ke grup klinis yang benar.
     *
     * Aturannya dicabut dan penyakitnya dinonaktifkan agar hanya P01-P05 yang
     * memiliki basis pengetahuan gejala/CF, sesuai Subbab 3.2.3.4 naskah.
     */
    private function retireLegacyMvpDiseases(): void
    {
        $diseaseIds = Disease::query()
            ->whereIn('code', ['ECZEMA', 'ACUTE_ECZEMA', 'DRY_SKIN_ECZEMA', 'URTICARIA', 'CANDIDIASIS'])
            ->pluck('id');

        if ($diseaseIds->isEmpty()) {
            return;
        }

        DiseaseSymptomRule::query()->whereIn('disease_id', $diseaseIds)->delete();

        // Kelas dataset milik penyakit yang dipensiunkan dialihkan ke grup klinis
        // yang benar (mis. Eczema -> DERMATITIS_ECZEMA, educate_only), sama seperti
        // yang dilakukan perintah dataset:import-classes. Tanpa ini mapping-nya
        // menunjuk penyakit nonaktif dan kandidat visualnya hilang begitu saja.
        DatasetClassMapping::query()
            ->whereIn('disease_id', $diseaseIds)
            ->get()
            ->each(function (DatasetClassMapping $mapping): void {
                $payload = DatasetDiseaseMapper::payloadFor($mapping->dataset_class_name);

                $group = Disease::query()->firstOrNew(['code' => $payload['disease']['code']]);
                $group->fill($payload['disease'])->save();

                $mapping->fill($payload['mapping']);
                $mapping->disease_id = $group->id;
                $mapping->save();
            });

        Disease::query()->whereIn('id', $diseaseIds)->update(['is_active' => false]);
    }

    /**
     * Gejala lama yang kehilangan seluruh keterkaitan aturan setelah rule base
     * lima penyakit naskah diganti ke G01-G20 (Subbab 3.2.3.4). Dinonaktifkan
     * agar tidak lagi ditanyakan ke pengguna tanpa memengaruhi hasil apa pun.
     */
    /**
     * Nonaktifkan gejala warisan bank lama yang tidak lagi terhubung ke penyakit
     * aktif mana pun.
     *
     * Seluruh pilihan pada bank pertanyaan berjalan dikecualikan, bukan hanya
     * yang memiliki aturan CF. Pilihan penyelamat seperti "Tidak yakin" dan
     * "Tidak ada pemicu yang jelas" memang sengaja bernilai nol untuk semua
     * penyakit, sehingga tanpa pengecualian ini pilihan tersebut ikut
     * dinonaktifkan dan hilang dari layar. Akibatnya pengguna yang keluhannya
     * memang tidak punya pemicu terpaksa memilih jawaban yang salah, dan
     * jawaban salah itu langsung menjadi bukti CF yang keliru.
     *
     * @param  array<int, string>  $kodePilihanBerjalan
     */
    private function deactivateOrphanedSymptoms(array $kodePilihanBerjalan): void
    {
        Symptom::query()
            ->where('is_active', true)
            ->whereNotIn('code', $kodePilihanBerjalan)
            ->whereDoesntHave('diseaseRules', fn ($query) => $query->whereHas('disease', fn ($q) => $q->where('is_active', true)))
            ->update(['is_active' => false]);
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
            'BENZOYL_PEROXIDE' => ['Benzoil peroksida 2,5%', 'anti_jerawat_topikal', 'gel/krim', 'Oleskan tipis pada area berjerawat setelah wajah dibersihkan dan dikeringkan. Mulai tiap dua malam sekali, lalu naikkan menjadi satu sampai dua kali sehari bila kulit sudah terbiasa. Hasil biasanya baru terlihat setelah 4 sampai 6 minggu.', 'Kulit kering dan mengelupas ringan lazim terjadi; kurangi frekuensi bila berlebihan. Dapat memutihkan kain dan rambut. Hindari mata, bibir, dan mukosa.', true],
            'CETIRIZINE' => ['Cetirizine 10 mg', 'antihistamin_oral', 'tablet', 'Satu tablet 10 mg sekali sehari untuk dewasa.', 'Dapat menimbulkan kantuk; hindari mengemudi bila terasa mengantuk. Hentikan dan periksa bila bentol disertai sesak, bengkak bibir atau wajah.', true],
            'LORATADINE' => ['Loratadine 10 mg', 'antihistamin_oral', 'tablet', 'Satu tablet 10 mg sekali sehari untuk dewasa.', 'Umumnya tidak menyebabkan kantuk. Hentikan dan periksa bila bentol disertai sesak, bengkak bibir atau wajah.', true],
            'HYDROCORTISONE' => ['Hidrokortison 1%', 'kortikosteroid_topikal_ringan', 'krim', 'Oleskan tipis satu sampai dua kali sehari pada area yang meradang.', 'JANGAN dipakai lebih dari 7 hari tanpa arahan dokter atau apoteker. Hentikan begitu keluhan mereda. Tidak untuk infeksi jamur, luka terbuka, atau area wajah dan lipatan tanpa arahan tenaga kesehatan.', true],
        ];

        // Rujukan per obat. Aturan pakai dan batas durasi di atas disalin dari
        // sumber ini, bukan disusun sendiri - kesalahan dosis berakibat langsung
        // pada tubuh pengguna. Verifikasi apoteker tetap wajib sebelum klinis.
        $sources = [
            'BENZOYL_PEROXIDE' => 'NHS, How and when to use benzoyl peroxide (nhs.uk/medicines/benzoyl-peroxide); StatPearls Benzoyl Peroxide (NBK537220); DermNet, Benzoyl peroxide.',
            'CETIRIZINE' => 'StatPearls Cetirizine (NBK549776); NHS Notts APC, Urticaria and/or angioedema in adults primary care pathway.',
            'LORATADINE' => 'StatPearls Loratadine (NBK542278); NHS Notts APC, Urticaria and/or angioedema in adults primary care pathway.',
            'HYDROCORTISONE' => 'NHS, Hydrocortisone for skin (nhs.uk/medicines/hydrocortisone-skin-cream) - batas 7 hari tanpa arahan tenaga kesehatan; MedlinePlus Hydrocortisone Topical.',
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
                    'source_note' => $sources[$code] ?? 'Rujukan awal: pedoman penggunaan obat bebas/bebas terbatas dan verifikasi BPOM. Validasi apoteker tetap diperlukan sebelum dipakai klinis.',
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
            'ECZEMA' => ['EMOLLIENT', 'HYDROCORTISONE', 'CALAMINE', 'AVOID_TRIGGER'],
            'ACUTE_ECZEMA' => ['EMOLLIENT', 'CALAMINE', 'AVOID_TRIGGER'],
            'DRY_SKIN_ECZEMA' => ['EMOLLIENT', 'AVOID_TRIGGER'],
            'ALLERGIC_CONTACT_DERMATITIS' => ['AVOID_TRIGGER', 'HYDROCORTISONE', 'CALAMINE', 'EMOLLIENT'],
            'URTICARIA' => ['CETIRIZINE', 'LORATADINE', 'CALAMINE', 'AVOID_TRIGGER'],
            'CANDIDIASIS' => ['CLOTRIMAZOLE', 'MICONAZOLE'],
            'ACNE_VULGARIS' => ['BENZOYL_PEROXIDE'],
            'TINEA_CORPORIS' => ['CLOTRIMAZOLE', 'MICONAZOLE'],
            'TINEA_CRURIS' => ['CLOTRIMAZOLE', 'MICONAZOLE'],
            'TINEA_PEDIS' => ['MICONAZOLE', 'CLOTRIMAZOLE'],
            'TINEA_VERSICOLOR' => ['KETOCONAZOLE_TOPICAL', 'CLOTRIMAZOLE'],
        ];

        foreach ($links as $diseaseCode => $medicineCodes) {
            // Sebagian penyakit baru dibuat pada seedScopeDiseases(), sesudah
            // metode ini berjalan. Pemanggilan kedua di run() yang mengisinya.
            if (! isset($diseases[$diseaseCode])) {
                continue;
            }

            foreach ($medicineCodes as $priority => $medicineCode) {
                if (! isset($medicines[$medicineCode])) {
                    continue;
                }

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
        // dataset_class_id memakai nomor resmi classes.txt SD-198 (bukan index sekuensial)
        // supaya tidak bertabrakan dengan class lain saat dataset:import-classes dijalankan.
        $classNames = [
            'ECZEMA' => ['Eczema', 'Eksim / dermatitis umum', 55],
            'ACUTE_ECZEMA' => ['Acute_Eczema', 'Eksim akut ringan', 11],
            'DRY_SKIN_ECZEMA' => ['Dry_Skin_Eczema', 'Eksim kulit kering', 51],
            'ALLERGIC_CONTACT_DERMATITIS' => ['Allergic_Contact_Dermatitis', 'Dermatitis kontak alergi', 12],
            'URTICARIA' => ['Urticaria', 'Biduran / urtikaria ringan', 193],
            'CANDIDIASIS' => ['Candidiasis', 'Kandidiasis kulit ringan', 31],
            'TINEA_CORPORIS' => ['Tinea_Corporis', 'Kurap badan', 182],
            'TINEA_CRURIS' => ['Tinea_Cruris', 'Jamur lipatan paha ringan', 183],
            'TINEA_PEDIS' => ['Tinea_Pedis', 'Kutu air / jamur kaki', 186],
            'TINEA_VERSICOLOR' => ['Tinea_Versicolor', 'Panu', 187],
        ];

        foreach ($classNames as $diseaseCode => [$className, $namaIndonesia, $classId]) {
            DatasetClassMapping::query()->updateOrCreate(
                ['dataset_class_name' => $className],
                [
                    'dataset_class_id' => $classId,
                    'nama_indonesia' => $namaIndonesia,
                    'scope_category' => 'swamedikasi',
                    'boleh_rekomendasi_obat' => $diseaseCode !== 'DRY_SKIN_ECZEMA',
                    'default_action' => $diseaseCode === 'DRY_SKIN_ECZEMA' ? 'educate_only' : 'recommend_otc',
                    'disease_id' => $diseases[$diseaseCode]->id,
                    'risk_note' => 'Basis pengetahuan tervalidasi (naskah/MVP), bukan grup klinis generik dataset:link-diseases.',
                ],
            );
        }
    }

    /**
     * 20 gejala baku sesuai Tabel 3.4 naskah skripsi (kode G01-G20).
     *
     * @return array<string, Symptom>
     */
    private function seedNaskahSymptoms(): array
    {
        $rows = [
            ['G01', 'Kulit kemerahan', 'Apakah kulit tampak kemerahan (erythema)?'],
            ['G02', 'Gatal', 'Apakah area kulit terasa gatal (pruritus)?'],
            ['G03', 'Kulit bersisik', 'Apakah kulit tampak bersisik (scaling)?'],
            ['G04', 'Lepuh kecil berisi cairan', 'Apakah muncul lepuh kecil berisi cairan (vesicle)?'],
            ['G05', 'Pembengkakan ringan', 'Apakah ada pembengkakan ringan (edema) pada area kulit?'],
            ['G06', 'Lesi berbentuk cincin', 'Apakah ruam berbentuk seperti cincin atau lingkaran (annular lesion)?'],
            ['G07', 'Bagian tengah lesi tampak lebih bersih', 'Apakah bagian tengah ruam tampak lebih bersih/normal dibanding tepinya (central clearing)?'],
            ['G08', 'Batas lesi terlihat jelas', 'Apakah tepi atau batas ruam terlihat jelas (well-demarcated border)?'],
            ['G09', 'Kulit pecah-pecah pada sela jari kaki', 'Apakah kulit pecah-pecah (fissure) di sela jari kaki?'],
            ['G10', 'Lesi berada pada lipatan paha', 'Apakah keluhan berada pada lipatan paha (inguinal)?'],
            ['G11', 'Bercak putih atau kecokelatan', 'Apakah ada bercak putih atau kecokelatan pada kulit?'],
            ['G12', 'Gatal bertambah setelah berkeringat', 'Apakah gatal bertambah setelah berkeringat?'],
            ['G13', 'Riwayat kontak dengan alergen', 'Apakah keluhan muncul setelah kontak sabun, kosmetik, logam, tanaman, atau bahan pemicu tertentu?'],
            ['G14', 'Kulit terasa perih atau terbakar', 'Apakah kulit terasa perih atau seperti terbakar (burning sensation)?'],
            ['G15', 'Lesi berada pada telapak kaki', 'Apakah keluhan berada pada telapak kaki (plantar)?'],
            ['G16', 'Lesi berada pada badan atau lengan', 'Apakah keluhan berada pada badan atau lengan (trunk/extremity)?'],
            ['G17', 'Rasa nyeri ringan pada lesi', 'Apakah terasa nyeri ringan pada area tersebut?'],
            ['G18', 'Kulit terasa kering', 'Apakah kulit terasa kering (xerosis)?'],
            ['G19', 'Lesi bertambah luas secara perlahan', 'Apakah ruam bertambah luas secara perlahan?'],
            ['G20', 'Lesi hanya pada area yang terkena paparan', 'Apakah keluhan hanya muncul pada area yang terkena bahan pemicu (localized lesion)?'],
        ];

        $symptoms = [];

        foreach ($rows as [$code, $name, $question]) {
            $symptoms[$code] = Symptom::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'question' => $question,
                    'input_type' => 'scale',
                    'is_red_flag_candidate' => false,
                    'is_active' => true,
                ],
            );
        }

        return $symptoms;
    }

    /**
     * Matriks nilai CF pakar (Tabel 3.11) untuk lima penyakit ruang lingkup naskah skripsi
     * (P01 Dermatitis Kontak Alergi, P02 Tinea Corporis, P03 Tinea Cruris, P04 Tinea Pedis,
     * P05 Pityriasis/Tinea Versicolor). Nilai MB/MD diturunkan dari CF pakar mengikuti
     * pedoman pengodean Tabel 3.8 (CF 0,80 -> MB 1,00/MD 0,20; CF 0,60 -> MB 0,80/MD 0,20;
     * CF 0,40 -> MB 0,60/MD 0,20; CF 0,20 -> MB 0,40/MD 0,20), kecuali dua nilai adopsi
     * langsung (CF 1,00 -> MB 1,00/MD 0,00) untuk G03 dan G11 pada P05. Gejala wajib
     * (is_required) adalah gejala dengan CF pakar >= 0,80 sesuai Subbab 3.2.3.4 butir 4.
     *
     * Menggantikan seluruh basis_pengetahuan lama pada kelima penyakit ini agar konsisten
     * dengan naskah; penyakit di luar ruang lingkup (Eczema, Urticaria, Candidiasis, dst.)
     * tidak disentuh dan tetap memakai gejala/aturan lama sebagai cakupan tambahan.
     *
     * @param array<string, Disease> $diseases
     * @param array<string, Symptom> $naskahSymptoms
     */
    private function seedNaskahRules(array $diseases, array $naskahSymptoms): void
    {
        $matrix = [
            'ALLERGIC_CONTACT_DERMATITIS' => [ // P01
                'G01' => 0.60, 'G02' => 0.60, 'G03' => 0.40, 'G04' => 0.60,
                'G05' => 0.60, 'G13' => 0.80, 'G14' => 0.40, 'G20' => 0.60,
            ],
            'TINEA_CORPORIS' => [ // P02
                'G01' => 0.60, 'G02' => 0.60, 'G03' => 0.80, 'G04' => 0.40,
                'G06' => 0.80, 'G07' => 0.80, 'G08' => 0.80, 'G16' => 0.40, 'G19' => 0.80,
            ],
            'TINEA_CRURIS' => [ // P03
                'G01' => 0.60, 'G02' => 0.60, 'G03' => 0.60, 'G06' => 0.80,
                'G07' => 0.80, 'G08' => 0.80, 'G10' => 0.80, 'G12' => 0.40, 'G17' => 0.40, 'G19' => 0.40,
            ],
            'TINEA_PEDIS' => [ // P04
                'G01' => 0.60, 'G02' => 0.60, 'G03' => 0.60, 'G04' => 0.60,
                'G08' => 0.60, 'G09' => 0.80, 'G12' => 0.40, 'G14' => 0.40, 'G15' => 0.80, 'G18' => 0.40,
            ],
            'TINEA_VERSICOLOR' => [ // P05
                'G01' => 0.40, 'G02' => 0.20, 'G03' => 1.00, 'G08' => 0.80,
                'G11' => 1.00, 'G12' => 0.80, 'G16' => 0.60, 'G19' => 0.40,
            ],
        ];

        foreach ($matrix as $diseaseCode => $symptomCfs) {
            $disease = $diseases[$diseaseCode];

            // Bersihkan basis_pengetahuan lama (kode gejala non-naskah) khusus penyakit ini
            // agar rule base persis mengikuti Tabel 3.11, tanpa mengganggu penyakit lain.
            DiseaseSymptomRule::query()
                ->where('disease_id', $disease->id)
                ->whereNotIn('symptom_id', collect($naskahSymptoms)->pluck('id')->all())
                ->delete();

            foreach ($symptomCfs as $symptomCode => $cf) {
                [$mb, $md] = match (true) {
                    $cf >= 1.00 => [1.00, 0.00],
                    $cf >= 0.80 => [1.00, 0.20],
                    $cf >= 0.60 => [0.80, 0.20],
                    $cf >= 0.40 => [0.60, 0.20],
                    default => [0.40, 0.20],
                };

                DiseaseSymptomRule::query()->updateOrCreate(
                    [
                        'disease_id' => $disease->id,
                        'symptom_id' => $naskahSymptoms[$symptomCode]->id,
                    ],
                    [
                        'mb' => $mb,
                        'md' => $md,
                        'expert_cf' => round($mb - $md, 2),
                        'is_required' => $cf >= 0.80,
                        'note' => 'Tabel 3.11 naskah skripsi (matriks nilai CF pakar), pengodean Tabel 3.8.',
                    ],
                );
            }
        }
    }
}
