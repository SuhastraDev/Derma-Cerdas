<?php

namespace App\Support;

/**
  * Bank pertanyaan konsultasi dan matriks Certainty Factor untuk 16 kelas.
 *
 * RANCANGAN
 * ---------
 * Bank lama (G01-G20) berbentuk "apakah ada gejala X?" sehingga jawabannya
 * menumpuk, bukan memisahkan. Tiga pertanyaan pertamanya - kemerahan, gatal,
 * bersisik - bernilai positif pada SELURUH penyakit, jadi daya pisahnya nol.
 *
 * Bank ini menggantinya dengan pertanyaan pilihan ganda yang saling meniadakan.
 * Empat prinsip penyusunannya:
 *
 *   1. Tanyakan yang TIDAK terlihat pada foto - lokasi persis, perjalanan
 *      waktu, rasa nyeri, pemicu. Mengulang apa yang sudah tampak di citra
 *      hanya memboroskan pertanyaan.
 *   2. Urutkan menurut daya pisah. P1 (lokasi) memangkas 15 kelas menjadi
 *      rata-rata 3; "apakah gatal?" nyaris tidak memangkas apa pun.
 *   3. Dahulukan yang pasti diketahui pengguna (lokasi, lama keluhan) sebelum
 *      yang memerlukan penilaian (bentuk lesi, jenis sisik).
 *   4. Selalu sediakan "tidak yakin" bernilai nol - jawaban asal jauh lebih
 *      merusak daripada jawaban kosong.
 *
 * Setiap PILIHAN disimpan sebagai satu baris symptoms, sehingga skema dan
 * CertaintyFactorService tidak berubah: pilihan terpilih user_cf 1.0, sisanya 0.
 *
 * Nilai CF mengikuti pedoman pengodean yang sudah dipakai sebelumnya:
 * 0,80 sangat khas | 0,60 kuat | 0,40 sedang | 0,20 lemah.
 */
class QuestionBank
{
    /**
     * 16 kelas ruang lingkup, dipetakan ke nama kelas SD-198.
     *
     * dataset_class_id memakai nomor resmi SD-198 yang sudah terpakai pada basis
     * data produksi. Vitiligo semula dipertimbangkan tetapi nomor resminya tidak
     * tersedia (kelasnya belum pernah diimpor dan classes.txt tidak disertakan),
     * sehingga digantikan Pityriasis Alba - secara klinis justru lebih tepat
     * untuk perannya, yaitu bercak putih yang paling sering dikira panu.
     *
     * @return array<int, array{code: string, name: string, name_indonesian: string, dataset_class: string, dataset_class_id: int, default_action: string, description: string}>
     */
    public static function diseases(): array
    {
        return [
            ['code' => 'ALLERGIC_CONTACT_DERMATITIS', 'name' => 'Allergic Contact Dermatitis', 'name_indonesian' => 'Dermatitis kontak alergi', 'dataset_class' => 'Allergic_Contact_Dermatitis', 'dataset_class_id' => 12, 'default_action' => 'recommend_otc', 'description' => 'Ruam gatal pada area yang bersentuhan dengan bahan pemicu seperti logam, kosmetik, sabun, atau tanaman.'],
            ['code' => 'TINEA_CORPORIS', 'name' => 'Tinea Corporis', 'name_indonesian' => 'Kurap badan', 'dataset_class' => 'Tinea_Corporis', 'dataset_class_id' => 182, 'default_action' => 'recommend_otc', 'description' => 'Infeksi jamur superfisial pada badan, khas berbentuk melingkar dengan tepi aktif.'],
            ['code' => 'TINEA_CRURIS', 'name' => 'Tinea Cruris', 'name_indonesian' => 'Jamur lipatan paha', 'dataset_class' => 'Tinea_Cruris', 'dataset_class_id' => 183, 'default_action' => 'recommend_otc', 'description' => 'Infeksi jamur pada lipatan paha yang gatal dan bertambah saat lembap.'],
            ['code' => 'TINEA_PEDIS', 'name' => 'Tinea Pedis', 'name_indonesian' => 'Kutu air', 'dataset_class' => 'Tinea_Pedis', 'dataset_class_id' => 186, 'default_action' => 'recommend_otc', 'description' => 'Infeksi jamur pada sela jari atau telapak kaki.'],
            ['code' => 'TINEA_VERSICOLOR', 'name' => 'Tinea Versicolor', 'name_indonesian' => 'Panu', 'dataset_class' => 'Tinea_Versicolor', 'dataset_class_id' => 187, 'default_action' => 'recommend_otc', 'description' => 'Infeksi jamur superfisial berupa bercak lebih terang atau gelap dengan sisik halus.'],

            ['code' => 'PSORIASIS', 'name' => 'Psoriasis', 'name_indonesian' => 'Psoriasis', 'dataset_class' => 'Psoriasis', 'dataset_class_id' => 153, 'default_action' => 'educate_only', 'description' => 'Penyakit kulit kronis berupa plak tebal kemerahan dengan sisik putih keperakan.'],
            ['code' => 'ACNE_VULGARIS', 'name' => 'Acne Vulgaris', 'name_indonesian' => 'Jerawat', 'dataset_class' => 'Acne_Vulgaris', 'dataset_class_id' => 2, 'default_action' => 'recommend_otc', 'description' => 'Peradangan kelenjar minyak dengan komedo dan bintil bernanah.'],
            ['code' => 'ECZEMA', 'name' => 'Eczema', 'name_indonesian' => 'Eksim', 'dataset_class' => 'Eczema', 'dataset_class_id' => 55, 'default_action' => 'recommend_otc', 'description' => 'Peradangan kulit kronis yang gatal, kering, dan kambuh-kambuhan.'],
            ['code' => 'URTICARIA', 'name' => 'Urticaria', 'name_indonesian' => 'Biduran', 'dataset_class' => 'Urticaria', 'dataset_class_id' => 193, 'default_action' => 'recommend_otc', 'description' => 'Bentol gatal yang muncul dan hilang dalam hitungan jam serta dapat berpindah.'],
            ['code' => 'CANDIDIASIS', 'name' => 'Candidiasis', 'name_indonesian' => 'Kandidiasis kulit', 'dataset_class' => 'Candidiasis', 'dataset_class_id' => 31, 'default_action' => 'recommend_otc', 'description' => 'Infeksi jamur pada lipatan kulit lembap, khas disertai bintik satelit di sekitarnya.'],
            ['code' => 'PITYRIASIS_ALBA', 'name' => 'Pityriasis Alba', 'name_indonesian' => 'Pitiriasis alba', 'dataset_class' => 'Pityriasis_Alba', 'dataset_class_id' => 146, 'default_action' => 'educate_only', 'description' => 'Bercak putih samar bersisik halus, umum pada anak dan sering dikira panu.'],
            ['code' => 'ONYCHOMYCOSIS', 'name' => 'Onychomycosis', 'name_indonesian' => 'Jamur kuku', 'dataset_class' => 'Onychomycosis', 'dataset_class_id' => 139, 'default_action' => 'educate_only', 'description' => 'Infeksi jamur pada kuku sehingga kuku menebal, rapuh, dan berubah warna.'],

            ['code' => 'KELOID', 'name' => 'Keloid', 'name_indonesian' => 'Keloid', 'dataset_class' => 'Keloid', 'dataset_class_id' => 93, 'default_action' => 'educate_only', 'description' => 'Jaringan parut menonjol yang tumbuh melebihi batas luka asal, lazim pada kulit berpigmen gelap.'],

            ['code' => 'IMPETIGO', 'name' => 'Impetigo', 'name_indonesian' => 'Impetigo', 'dataset_class' => 'Impetigo', 'dataset_class_id' => 89, 'default_action' => 'refer', 'description' => 'Infeksi bakteri menular dengan krusta kuning keemasan, memerlukan penanganan tenaga kesehatan.'],
            ['code' => 'HERPES_ZOSTER', 'name' => 'Herpes Zoster', 'name_indonesian' => 'Cacar ular', 'dataset_class' => 'Herpes_Zoster', 'dataset_class_id' => 83, 'default_action' => 'refer', 'description' => 'Reaktivasi virus varicella berupa gelembung bergerombol satu sisi tubuh disertai nyeri.'],
            ['code' => 'BASAL_CELL_CARCINOMA', 'name' => 'Basal Cell Carcinoma', 'name_indonesian' => 'Karsinoma sel basal', 'dataset_class' => 'Basal_Cell_Carcinoma', 'dataset_class_id' => 21, 'default_action' => 'refer', 'description' => 'Kanker kulit tersering, berupa benjolan mengkilap yang tidak sembuh dan kadang berdarah.'],
        ];
    }

    /**
     * Pertanyaan beserta pilihan jawabannya.
     *
     * 'always' menandai pertanyaan yang selalu diajukan; sisanya dipilih adaptif
     * hanya bila masih memisahkan kandidat yang tersisa.
     *
     * @return array<string, array{order: int, always: bool, text: string, options: array<string, array{0: string, 1: string}>}>
     */
    public static function questions(): array
    {
        return [
            'P1_LOKASI' => [
                'order' => 1,
                'always' => true,
                'text' => 'Di bagian tubuh mana keluhan utamanya?',
                'options' => [
                    'P1_KUKU' => ['Kuku tangan atau kaki', 'Kuku menebal, rapuh, atau berubah warna'],
                    'P1_KAKI' => ['Sela jari atau telapak kaki', 'Di antara jari kaki atau bagian bawah telapak'],
                    'P1_LIPATAN' => ['Lipatan paha, ketiak, atau bawah payudara', 'Area kulit yang saling bertemu dan sering lembap'],
                    'P1_SIKULUTUT' => ['Siku atau lutut', 'Bagian luar sendi yang menonjol'],
                    'P1_WAJAH' => ['Wajah atau leher', 'Termasuk dahi, pipi, hidung, dan sekitar mulut'],
                    'P1_BADAN' => ['Badan, punggung, atau lengan', 'Area tubuh yang luas dan tertutup pakaian'],
                    'P1_KONTAK' => ['Hanya di tempat yang bersentuhan sesuatu', 'Misalnya bekas jam tangan, ikat pinggang, atau area yang terkena kosmetik'],
                    'P1_TIDAKYAKIN' => ['Tidak yakin', 'Lewati bila ragu — jawaban asal justru menyesatkan'],
                ],
            ],
            'P2_BENTUK' => [
                'order' => 2,
                'always' => true,
                'text' => 'Bagaimana bentuk keluhannya?',
                'options' => [
                    'P2_CINCIN' => ['Melingkar seperti cincin', 'Tepinya lebih merah dan menonjol, bagian tengah tampak lebih bersih'],
                    'P2_PLAK' => ['Plak tebal yang menonjol', 'Terasa tebal bila diraba dan naik dari permukaan kulit sekitar'],
                    'P2_DATAR' => ['Bercak datar', 'Warnanya berbeda dari kulit sekitar, tetapi rata dan tidak menonjol'],
                    'P2_JERAWAT' => ['Bintil dan komedo', 'Ada komedo hitam atau putih, sebagian bernanah'],
                    'P2_BENJOLAN' => ['Benjolan tunggal mengkilap', 'Seperti mutiara atau lilin, kadang tampak pembuluh darah halus di atasnya'],
                    'P2_BENTOL' => ['Bentol menonjol yang hilang timbul', 'Muncul lalu menghilang dalam hitungan jam dan bisa berpindah tempat'],
                    'P2_GELEMBUNG' => ['Gelembung kecil bergerombol', 'Berisi cairan bening, mengelompok rapat di satu area'],
                    'P2_KERAK' => ['Luka berkerak kuning', 'Seperti madu yang mengering di atas luka'],
                    'P2_MERAHLUAS' => ['Merah luas tanpa batas jelas', 'Kemerahan menyebar dan tepinya sulit ditentukan'],
                    'P2_KUKUBERUBAH' => ['Bukan ruam, tapi kuku yang berubah', 'Kuku menebal, rapuh, atau berubah warna, tanpa ruam pada kulit di sekitarnya'],
                    'P2_TIDAKYAKIN' => ['Tidak yakin', 'Lewati bila ragu'],
                ],
            ],
            'P3_SISIK' => [
                'order' => 3,
                'always' => false,
                'text' => 'Bagaimana sisik atau permukaannya?',
                'options' => [
                    'P3_TIDAKADA' => ['Tidak ada sisik sama sekali', 'Permukaannya halus seperti kulit biasa'],
                    'P3_HALUS' => ['Halus seperti bedak', 'Sisik tipis yang baru terlihat jelas saat digaruk pelan'],
                    'P3_TEBAL' => ['Tebal, putih keperakan', 'Mengelupas berlapis seperti serpihan lilin'],
                    'P3_KERING' => ['Kering dan pecah-pecah', 'Kulit terasa kaku, retak, kadang perih'],
                    'P3_TIDAKYAKIN' => ['Tidak yakin', 'Lewati bila ragu'],
                ],
            ],
            'P4_RASA' => [
                'order' => 4,
                'always' => false,
                'text' => 'Apa yang paling Anda rasakan pada area itu?',
                'options' => [
                    'P4_GATAL' => ['Gatal', 'Ingin menggaruk, kadang sampai mengganggu tidur'],
                    'P4_NYERI' => ['Nyeri atau panas menusuk', 'Kadang sudah terasa sebelum ruamnya muncul'],
                    'P4_PERIH' => ['Perih atau terbakar', 'Terutama saat terkena air atau keringat'],
                    'P4_TIDAKTERASA' => ['Tidak gatal dan tidak nyeri', 'Hanya terlihat, tanpa keluhan rasa'],
                    'P4_TIDAKYAKIN' => ['Tidak yakin', 'Lewati bila ragu'],
                ],
            ],
            'P5_WAKTU' => [
                'order' => 5,
                'always' => false,
                'text' => 'Sudah berapa lama, dan bagaimana perjalanannya?',
                'options' => [
                    'P5_JAM' => ['Muncul dan hilang dalam hitungan jam', 'Berpindah-pindah tempat, tidak menetap di satu titik'],
                    'P5_HARI' => ['Beberapa hari, menyebar cepat', 'Bertambah luas dari hari ke hari'],
                    'P5_MINGGU' => ['Beberapa minggu, meluas perlahan', 'Bertambah sedikit demi sedikit'],
                    'P5_BULAN' => ['Berbulan-bulan, tidak sembuh, kadang berdarah', 'Menetap dan tidak membaik dengan obat apa pun'],
                    'P5_TAHUN' => ['Bertahun-tahun, kambuh-kambuhan', 'Hilang lalu muncul lagi di tempat yang sama'],
                    'P5_TIDAKYAKIN' => ['Tidak yakin', 'Lewati bila ragu'],
                ],
            ],
            'P6_SEBARAN' => [
                'order' => 6,
                'always' => false,
                'text' => 'Bagaimana sebarannya di tubuh?',
                'options' => [
                    'P6_SATUSISI' => ['Hanya satu sisi tubuh, mengikuti garis atau pita', 'Tidak melewati garis tengah tubuh'],
                    'P6_SIMETRIS' => ['Muncul di kedua sisi tubuh', 'Misalnya kedua siku atau kedua lengan'],
                    'P6_SETEMPAT' => ['Hanya di satu tempat saja', 'Tidak ada di bagian tubuh lain'],
                    'P6_TERSEBAR' => ['Beberapa titik terpisah, tidak beraturan', 'Muncul di titik-titik berbeda yang tidak simetris, bisa juga berpindah-pindah'],
                    'P6_TIDAKYAKIN' => ['Tidak yakin', 'Lewati bila ragu'],
                ],
            ],
            'P7_PEMICU' => [
                'order' => 7,
                'always' => false,
                'text' => 'Apakah ada yang memicunya?',
                'options' => [
                    'P7_KONTAK' => ['Setelah kontak logam, kosmetik, sabun, atau tanaman', 'Keluhan muncul di tempat yang bersentuhan bahan tersebut'],
                    'P7_KERINGAT' => ['Bertambah saat berkeringat atau lembap', 'Memburuk setelah olahraga atau memakai pakaian ketat'],
                    'P7_BEKASLUKA' => ['Muncul di bekas luka, tindikan, atau operasi', 'Tumbuh pada tempat kulit pernah terluka, termasuk bekas jerawat'],
                    'P7_OBAT' => ['Setelah minum obat baru', 'Muncul dalam beberapa hari setelah mulai obat'],
                    'P7_TIDAKADA' => ['Tidak ada pemicu yang jelas', 'Muncul begitu saja'],
                    'P7_TIDAKYAKIN' => ['Tidak yakin', 'Lewati bila ragu'],
                ],
            ],
            'P9_USIA' => [
                'order' => 9,
                'always' => false,
                'text' => 'Berapa usia orang yang mengalami keluhan ini?',
                'options' => [
                    'P9_ANAK' => ['Anak (di bawah 12 tahun)', 'Beberapa kondisi kulit hampir khas usia anak'],
                    'P9_REMAJA' => ['Remaja (12-18 tahun)', 'Perubahan hormon memengaruhi kelenjar minyak'],
                    'P9_DEWASA' => ['Dewasa (19-50 tahun)', 'Rentang usia paling luas; sebagian besar kondisi kulit tidak terikat usia tertentu di sini'],
                    'P9_LANSIA' => ['Di atas 50 tahun', 'Paparan matahari bertahun-tahun mulai berdampak'],
                    'P9_TIDAKYAKIN' => ['Tidak ingin menyebutkan', 'Lewati bila tidak nyaman'],
                ],
            ],
            'P8_CIRI' => [
                'order' => 8,
                'always' => false,
                'text' => 'Apakah ada ciri berikut?',
                'options' => [
                    'P8_TENGAHBERSIH' => ['Bagian tengah lebih bersih daripada tepinya', 'Tepi tampak aktif, tengahnya menyerupai kulit normal'],
                    'P8_SATELIT' => ['Ada bintik-bintik kecil di sekitar bercak utama', 'Seperti percikan kecil mengelilingi bercak besar'],
                    'P8_KOMEDO' => ['Ada komedo hitam dan putih', 'Titik-titik kecil tersumbat pada pori'],
                    'P8_TIDAKADA' => ['Tidak ada yang cocok', 'Tidak satu pun ciri di atas terlihat'],
                ],
            ],
        ];
    }

    /**
     * Matriks nilai CF pakar: kode penyakit => (kode pilihan => nilai CF).
     *
     * Pengodean: 0,80 sangat khas | 0,60 kuat | 0,40 sedang | 0,20 lemah.
     * Pilihan "tidak yakin" sengaja tidak pernah muncul di sini sehingga
     * nilainya nol untuk seluruh penyakit.
     *
     * @return array<string, array<string, float>>
     */
    public static function matrix(): array
    {
        return [
            'ALLERGIC_CONTACT_DERMATITIS' => [
                'P1_KONTAK' => 0.80, 'P1_WAJAH' => 0.20, 'P1_BADAN' => 0.20,
                'P2_MERAHLUAS' => 0.60, 'P3_KERING' => 0.40,
                'P4_GATAL' => 0.60, 'P4_PERIH' => 0.40,
                'P5_HARI' => 0.40, 'P5_MINGGU' => 0.40,
                'P6_SETEMPAT' => 0.40, 'P7_KONTAK' => 0.80,
            ],
            'TINEA_CORPORIS' => [
                'P1_BADAN' => 0.60, 'P2_CINCIN' => 0.80,
                'P3_HALUS' => 0.40, 'P3_KERING' => 0.20,
                'P4_GATAL' => 0.60, 'P5_MINGGU' => 0.60,
                'P7_KERINGAT' => 0.40, 'P8_TENGAHBERSIH' => 0.80,
            ],
            'TINEA_CRURIS' => [
                'P1_LIPATAN' => 0.80, 'P2_CINCIN' => 0.60,
                'P3_HALUS' => 0.40, 'P4_GATAL' => 0.60,
                'P5_MINGGU' => 0.40, 'P7_KERINGAT' => 0.60,
                'P8_TENGAHBERSIH' => 0.60,
            ],
            'TINEA_PEDIS' => [
                'P1_KAKI' => 0.80, 'P2_MERAHLUAS' => 0.20,
                'P3_KERING' => 0.60, 'P4_GATAL' => 0.60, 'P4_PERIH' => 0.40,
                'P5_MINGGU' => 0.40, 'P5_TAHUN' => 0.20, 'P7_KERINGAT' => 0.40,
            ],
            'TINEA_VERSICOLOR' => [
                'P1_BADAN' => 0.60, 'P2_DATAR' => 0.80,
                'P3_HALUS' => 0.80, 'P4_GATAL' => 0.20,
                'P5_MINGGU' => 0.40, 'P5_TAHUN' => 0.20, 'P6_TERSEBAR' => 0.40, 'P7_KERINGAT' => 0.60,
            ],
            'PSORIASIS' => [
                'P1_SIKULUTUT' => 0.80, 'P1_BADAN' => 0.20,
                'P2_PLAK' => 0.80, 'P3_TEBAL' => 0.80,
                'P4_GATAL' => 0.40, 'P5_TAHUN' => 0.80, 'P6_SIMETRIS' => 0.40,
            ],
            'ACNE_VULGARIS' => [
                'P9_REMAJA' => 0.60,
                'P1_WAJAH' => 0.80, 'P2_JERAWAT' => 0.80,
                'P4_NYERI' => 0.20, 'P5_MINGGU' => 0.20, 'P5_TAHUN' => 0.40,
                'P8_KOMEDO' => 0.80,
            ],
            'ECZEMA' => [
                'P1_BADAN' => 0.40, 'P1_SIKULUTUT' => 0.20, 'P1_WAJAH' => 0.20,
                'P2_MERAHLUAS' => 0.60, 'P3_KERING' => 0.60,
                'P4_GATAL' => 0.80, 'P5_TAHUN' => 0.60, 'P6_SIMETRIS' => 0.40,
            ],
            'URTICARIA' => [
                'P1_BADAN' => 0.40, 'P2_BENTOL' => 0.80,
                'P3_TIDAKADA' => 0.60, 'P4_GATAL' => 0.80,
                'P5_JAM' => 0.80, 'P6_TERSEBAR' => 0.40, 'P7_OBAT' => 0.40, 'P7_KONTAK' => 0.20,
            ],
            'CANDIDIASIS' => [
                'P1_LIPATAN' => 0.80, 'P2_MERAHLUAS' => 0.40,
                'P4_GATAL' => 0.40, 'P4_PERIH' => 0.60,
                'P5_MINGGU' => 0.40, 'P7_KERINGAT' => 0.60, 'P8_SATELIT' => 0.80,
            ],
            'PITYRIASIS_ALBA' => [
                'P9_ANAK' => 0.60,
                'P1_WAJAH' => 0.60, 'P1_BADAN' => 0.40,
                'P2_DATAR' => 0.80, 'P3_HALUS' => 0.60,
                'P4_TIDAKTERASA' => 0.40, 'P4_GATAL' => 0.20,
                'P5_MINGGU' => 0.40, 'P5_TAHUN' => 0.20, 'P6_SIMETRIS' => 0.20,
            ],
            'ONYCHOMYCOSIS' => [
                'P1_KUKU' => 0.80, 'P2_KUKUBERUBAH' => 0.80, 'P3_TEBAL' => 0.20,
                'P4_TIDAKTERASA' => 0.40, 'P5_TAHUN' => 0.60,
            ],
            'KELOID' => [
                'P9_REMAJA' => 0.20,
                'P1_BADAN' => 0.60, 'P1_WAJAH' => 0.40,
                'P2_PLAK' => 0.60, 'P3_TIDAKADA' => 0.60,
                'P4_GATAL' => 0.40, 'P4_NYERI' => 0.20,
                'P5_TAHUN' => 0.60, 'P6_SETEMPAT' => 0.60,
                'P7_BEKASLUKA' => 0.80,
            ],
            'IMPETIGO' => [
                'P9_ANAK' => 0.60,
                'P1_WAJAH' => 0.60, 'P2_KERAK' => 0.80,
                'P4_GATAL' => 0.40, 'P5_HARI' => 0.80, 'P6_SETEMPAT' => 0.40,
            ],
            'HERPES_ZOSTER' => [
                'P9_LANSIA' => 0.40,
                'P1_BADAN' => 0.60, 'P2_GELEMBUNG' => 0.80,
                'P4_NYERI' => 0.80, 'P5_HARI' => 0.60, 'P6_SATUSISI' => 0.80,
            ],
            'BASAL_CELL_CARCINOMA' => [
                'P9_LANSIA' => 0.60,
                'P1_WAJAH' => 0.80, 'P2_BENJOLAN' => 0.80,
                'P3_TIDAKADA' => 0.40, 'P4_TIDAKTERASA' => 0.60,
                'P5_BULAN' => 0.80, 'P6_SETEMPAT' => 0.60,
            ],
        ];
    }

    /**
     * Nilai MB/MD diturunkan dari CF pakar memakai pedoman pengodean yang sama
     * seperti sebelumnya, agar setiap angka tetap dapat ditelusuri.
     *
     * @return array{0: float, 1: float}
     */
    public static function beliefPair(float $cf): array
    {
        return match (true) {
            $cf >= 1.00 => [1.00, 0.00],
            $cf >= 0.80 => [1.00, 0.20],
            $cf >= 0.60 => [0.80, 0.20],
            $cf >= 0.40 => [0.60, 0.20],
            default => [0.40, 0.20],
        };
    }
}
