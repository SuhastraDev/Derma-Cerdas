<?php

use App\Models\DatasetClassMapping;
use App\Models\Disease;
use App\Models\DiseaseMedicineRecommendation;
use App\Models\DiseaseSymptomRule;
use App\Models\Medicine;
use App\Support\DatasetDiseaseMapper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mengganti 2 dari 16 penyakit cakupan (keputusan produk, bukan perbaikan
 * bug): Kandidiasis kulit -> Iktiosis (tetap golongan swamedikasi), Karsinoma
 * sel basal -> Tanduk kulit (tetap golongan rujukan). Alasannya: kedua
 * pengganti punya pola visual yang jauh lebih unik (Iktiosis: sisik besar
 * kotak-kotak menutupi area luas; Tanduk kulit: proyeksi keratin 3-dimensi
 * yang tidak mirip apa pun di 15 penyakit lain), jadi lebih kecil kemungkinan
 * salah tertukar oleh AI visual maupun kombinasi gejala generik.
 *
 * QuestionBank.php (sumber kebenaran untuk instalasi baru) sudah diperbarui
 * di komit yang sama - migration ini murni menyalin perubahan itu ke baris
 * yang sudah ada di database production, memakai `migrate` (satu-satunya
 * mekanisme yang benar-benar jalan di deploy.yml) alih-alih `db:seed` yang
 * juga akan me-reset password admin ke default setiap kali dijalankan.
 *
 * Simptom model tidak menandai question_group/question_text/option_label/
 * option_explanation/display_order sebagai fillable (lihat migration
 * relabel-tidakyakin sebelumnya), jadi baris symptoms baru ditulis lewat
 * DB::table() langsung, bukan Eloquent create().
 */
return new class extends Migration
{
    public function up(): void
    {
        // Migration ini murni mentransformasi data LAMA yang sudah pernah
        // ter-seed di production (Kandidiasis/BCC dari sebelum keputusan
        // penggantian ini). Instalasi baru sama sekali tidak butuh ini -
        // QuestionBank.php dan DatabaseSeeder.php (komit yang sama) sudah
        // memuat Iktiosis/Tanduk kulit sejak awal lewat `db:seed`. Tanpa
        // penjaga ini, migration akan berjalan tanpa syarat di setiap
        // `RefreshDatabase` (termasuk database test yang belum di-seed sama
        // sekali) dan diam-diam membuat 2 baris `diseases`, merusak test lain
        // yang mengasumsikan tabel itu kosong pasca-migrate murni
        // (mis. DatasetLinkDiseasesCommandTest).
        $legacyDataExists = DB::table('diseases')->whereIn('code', ['CANDIDIASIS', 'BASAL_CELL_CARCINOMA'])->exists();

        if (! $legacyDataExists) {
            return;
        }

        $this->addNewSymptomOptions();
        $this->retireDisease('CANDIDIASIS');
        $this->retireDisease('BASAL_CELL_CARCINOMA');

        $ichthyosis = $this->createScopeDisease(
            code: 'ICHTHYOSIS',
            name: 'Ichthyosis',
            nameIndonesian: 'Iktiosis',
            description: 'Kulit kering kronis dengan sisik besar berbentuk kotak-kotak menyerupai sisik ikan, menutupi area tubuh luas, biasanya sejak kecil.',
            sourceNote: null,
            medicalTreatmentNote: null,
            defaultAction: 'recommend_otc',
            datasetClassName: 'Ichthyosis',
            datasetClassId: 88,
        );

        $cutaneousHorn = $this->createScopeDisease(
            code: 'CUTANEOUS_HORN',
            name: 'Cutaneous Horn',
            nameIndonesian: 'Tanduk kulit',
            description: 'Pertumbuhan keratin memanjang seperti tanduk kecil pada kulit, sering menandakan lesi prakanker atau kanker kulit di bawahnya.',
            sourceNote: 'DermNet cutaneous horn: https://dermnetnz.org/topics/cutaneous-horn; AAD actinic keratosis (kondisi dasar yang sering menyertai): https://www.aad.org/public/diseases/skin-cancer/actinic-keratosis',
            medicalTreatmentNote: 'Bukan kondisi yang diobati dengan krim bebas. Dokter kulit biasanya melakukan biopsi atau pengangkatan lesi untuk memastikan tidak ada keratosis aktinik, karsinoma sel skuamosa, atau kanker kulit lain di dasarnya - deteksi dini sangat memengaruhi hasil pengobatan.',
            defaultAction: 'refer',
            datasetClassName: 'Cutaneous_Horn',
            datasetClassId: 39,
        );

        $this->addSymptomRules($ichthyosis, [
            'P9_ANAK' => 0.40,
            'P1_BADAN' => 0.40,
            'P3_SISIKIKAN' => 0.80,
            'P4_GATAL' => 0.20,
            'P5_TAHUN' => 0.60,
            'P6_SIMETRIS' => 0.40,
        ]);

        $this->addSymptomRules($cutaneousHorn, [
            'P9_LANSIA' => 0.60,
            'P1_WAJAH' => 0.60,
            'P2_TANDUK' => 0.80,
            'P3_TIDAKADA' => 0.20,
            'P4_TIDAKTERASA' => 0.40,
            'P5_BULAN' => 0.60,
        ]);

        $this->addMedicineRecommendation($ichthyosis, 'Pelembap / emollient', 1, 'Pelembap/emolien rutin adalah terapi utama iktiosis.');
        $this->addMedicineRecommendation($ichthyosis, 'Edukasi hindari pemicu', 2, 'Hindari sabun yang mengeringkan kulit dan mandi air terlalu panas/lama.');
    }

    public function down(): void
    {
        DiseaseSymptomRule::query()
            ->whereIn('disease_id', Disease::query()->whereIn('code', ['ICHTHYOSIS', 'CUTANEOUS_HORN'])->pluck('id'))
            ->delete();

        DiseaseMedicineRecommendation::query()
            ->whereIn('disease_id', Disease::query()->whereIn('code', ['ICHTHYOSIS', 'CUTANEOUS_HORN'])->pluck('id'))
            ->delete();

        Disease::query()->whereIn('code', ['ICHTHYOSIS', 'CUTANEOUS_HORN'])->update(['is_active' => false]);

        DB::table('symptoms')->whereIn('code', ['P2_TANDUK', 'P3_SISIKIKAN'])->update(['is_active' => false]);

        // Candidiasis/BCC dan pemetaan dataset-nya tidak dikembalikan otomatis -
        // retirement mereka (rollback grup klinis) tidak reversibel dengan aman
        // tanpa tahu kondisi persis sebelum migration ini berjalan.
    }

    private function addNewSymptomOptions(): void
    {
        $rows = [
            [
                'code' => 'P2_TANDUK',
                'name' => 'Tonjolan keras memanjang seperti tanduk',
                'question' => 'Bagaimana bentuk keluhannya?',
                'question_group' => 'P2_BENTUK',
                'question_text' => 'Bagaimana bentuk keluhannya?',
                'option_label' => 'Tonjolan keras memanjang seperti tanduk',
                'option_explanation' => 'Menjulang dari kulit, keras seperti kuku atau tanduk kecil, biasanya di area yang sering terkena matahari',
                'display_order' => 211,
                'input_type' => 'choice',
                'is_red_flag_candidate' => false,
                'is_active' => true,
            ],
            [
                'code' => 'P3_SISIKIKAN',
                'name' => 'Sisik besar berbentuk kotak-kotak seperti sisik ikan',
                'question' => 'Bagaimana sisik atau permukaannya?',
                'question_group' => 'P3_SISIK',
                'question_text' => 'Bagaimana sisik atau permukaannya?',
                'option_label' => 'Sisik besar berbentuk kotak-kotak seperti sisik ikan',
                'option_explanation' => 'Menutupi area kulit yang luas, tampak kering di sela-sela sisiknya',
                'display_order' => 304,
                'input_type' => 'choice',
                'is_red_flag_candidate' => false,
                'is_active' => true,
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('symptoms')->where('code', $row['code'])->exists();

            if ($exists) {
                DB::table('symptoms')->where('code', $row['code'])->update([...$row, 'updated_at' => now()]);
            } else {
                DB::table('symptoms')->insert([...$row, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /**
     * Mencabut basis gejala/CF penyakit dan mengalihkan pemetaan kelas
     * datasetnya ke grup klinis yang benar - persis pola
     * DatabaseSeeder::retireOutOfScopeDiseases(), diterapkan sempit ke satu
     * kode saja lewat migration alih-alih menjalankan seeder penuh.
     */
    private function retireDisease(string $code): void
    {
        $disease = Disease::query()->where('code', $code)->first();

        if (! $disease) {
            return;
        }

        DiseaseSymptomRule::query()->where('disease_id', $disease->id)->delete();

        DatasetClassMapping::query()
            ->where('disease_id', $disease->id)
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

        $disease->is_active = false;
        $disease->save();
    }

    private function createScopeDisease(
        string $code,
        string $name,
        string $nameIndonesian,
        string $description,
        ?string $sourceNote,
        ?string $medicalTreatmentNote,
        string $defaultAction,
        string $datasetClassName,
        int $datasetClassId,
    ): Disease {
        $disease = Disease::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'slug' => \Illuminate\Support\Str::slug($code),
                'name_indonesian' => $nameIndonesian,
                'description' => $description,
                'source_note' => $sourceNote,
                'medical_treatment_note' => $medicalTreatmentNote,
                'severity_scope' => $defaultAction === 'refer' ? 'moderate' : 'mild',
                'default_action' => $defaultAction,
                'is_active' => true,
            ],
        );

        DatasetClassMapping::query()->updateOrCreate(
            ['dataset_class_name' => $datasetClassName],
            [
                'dataset_class_id' => $datasetClassId,
                'nama_indonesia' => $nameIndonesian,
                'scope_category' => match ($defaultAction) {
                    'refer' => 'rujuk',
                    'recommend_otc' => 'swamedikasi',
                    default => 'edukasi',
                },
                'boleh_rekomendasi_obat' => $defaultAction === 'recommend_otc',
                'default_action' => $defaultAction,
                'disease_id' => $disease->id,
            ],
        );

        return $disease;
    }

    /**
     * @param  array<string, float>  $weights  kode gejala => nilai CF pakar (0,20/0,40/0,60/0,80), sama pengodean dengan QuestionBank::beliefPair().
     */
    private function addSymptomRules(Disease $disease, array $weights): void
    {
        foreach ($weights as $code => $cf) {
            $symptomId = DB::table('symptoms')->where('code', $code)->value('id');

            if (! $symptomId) {
                continue;
            }

            [$mb, $md] = match (true) {
                $cf >= 1.00 => [1.00, 0.00],
                $cf >= 0.80 => [1.00, 0.20],
                $cf >= 0.60 => [0.80, 0.20],
                $cf >= 0.40 => [0.60, 0.20],
                default => [0.40, 0.20],
            };

            DiseaseSymptomRule::query()->updateOrCreate(
                ['disease_id' => $disease->id, 'symptom_id' => $symptomId],
                [
                    'mb' => $mb,
                    'md' => $md,
                    'expert_cf' => round($mb - $md, 2),
                    'is_required' => false,
                    'note' => 'Ditambahkan saat penggantian Kandidiasis/BCC (2026-08-31); bank pertanyaan pilihan ganda 16 kelas, pengodean 0,80/0,60/0,40/0,20.',
                ],
            );
        }
    }

    private function addMedicineRecommendation(Disease $disease, string $medicineName, int $priority, string $note): void
    {
        $medicine = Medicine::query()->where('name', $medicineName)->first();

        if (! $medicine) {
            return;
        }

        DiseaseMedicineRecommendation::query()->updateOrCreate(
            ['disease_id' => $disease->id, 'medicine_id' => $medicine->id],
            [
                'recommendation_note' => $note,
                'priority' => $priority,
                'is_active' => true,
            ],
        );
    }
};
