# Derma-Cerdas

Sistem Pendukung Keputusan multimodal untuk rekomendasi Obat Bebas Terbatas pada
penyakit kulit ringan. Pengguna mengunggah foto kulit dan menjawab pertanyaan
gejala; sistem menggabungkan keduanya melalui aturan fusi, lalu menyarankan obat
bebas terbatas, memberi edukasi, atau mengarahkan ke tenaga kesehatan.

Sistem ini merupakan produk penelitian skripsi Program Studi Teknik Informatika,
Universitas Bina Darma, dan **bukan alat diagnosis**. Hasilnya bersifat skrining
awal dan tidak menggantikan pemeriksaan tenaga kesehatan.

Alamat: <https://dermacerdas.duckdns.org>

---

## Cara kerja singkat

Dua modalitas diproses terpisah, lalu keputusannya dipertemukan melalui aturan
— bukan dijumlahkan atau dirata-ratakan.

```
Foto  ──►  model multimodal  ──►  kandidat visual (Pv) + skor
                                              │
                                              ▼
                                    ATURAN FUSI (F01–F13)
                                              ▲
                                              │
9 pertanyaan ──►  Forward Chaining  ──►  kandidat teks (Pt) + CF
                  + Certainty Factor
```

**Modalitas teks.** Sembilan pertanyaan pilihan ganda yang saling meniadakan.
Setiap jawaban punya nilai keyakinan pakar, dikalikan tingkat keyakinan pengguna,
lalu digabungkan antar-gejala dengan kombinasi paralel MYCIN.

**Modalitas visual.** Foto dikirim ke layanan Python terpisah yang memanggil
model bahasa besar multimodal. Daftar kelas yang boleh dipilih model dipatok —
pelebaran ruang kandidat terukur menurunkan ketepatan secara tajam. Model juga
diperintahkan menolak menjawab bila foto tidak cocok dengan kelas mana pun.

**Fusi.** Sebelas aturan membandingkan identitas kedua kandidat dan ambang
keyakinan. Kode aturan yang berlaku disimpan pada setiap hasil sehingga keputusan
dapat ditelusuri kembali. Nilai yang ditampilkan berasal dari modalitas yang
menentukan keputusan, bukan gabungan aritmetika keduanya.

**Pengaman.** Dua belas pertanyaan tanda bahaya; satu jawaban positif memicu
aturan F07 yang menghentikan seluruh rekomendasi obat. Terpisah dari itu,
penggolongan penyakit memastikan penyakit yang tidak boleh ditangani sendiri
tidak pernah memperoleh rekomendasi obat, berapa pun nilai keyakinannya.

## Isi basis pengetahuan

| | Jumlah |
| --- | --- |
| Penyakit dengan basis Certainty Factor | 16 |
| Kelas payung (visual saja, tanpa CF) | 2 |
| Pertanyaan konsultasi | 9 |
| Pilihan jawaban | 57 |
| Nilai keyakinan pakar | 121 |
| Obat dan tindakan non-obat | 10 |
| Pertanyaan tanda bahaya | 12 |
| Kelas SD-198 yang ditawarkan ke model visual | 31 |

Nilai keyakinan diturunkan dari ungkapan pada literatur klinis (StatPearls)
melalui pedoman pengodean: *hallmark/characteristic* → 0,80, *common/often* →
0,60, *may occur* → 0,40, *nonspecific* → 0,20. Rujukan tata laksana obat
bersumber dari Informatorium Obat Nasional Indonesia dan Farmakope Indonesia.

Seluruh isi basis pengetahuan dapat dikelola lewat panel administrator tanpa
mengubah kode program. Sumber awalnya ada di `app/Support/QuestionBank.php`.

---

## Lingkungan

Versi berikut adalah yang benar-benar berjalan pada peladen produksi.

### Peladen

| Komponen | Versi |
| --- | --- |
| Sistem operasi | Ubuntu 24.04.4 LTS |
| Web server | Nginx 1.24.0 |
| Alamat | https://dermacerdas.duckdns.org |

### Aplikasi utama

| Komponen | Versi |
| --- | --- |
| PHP | 8.4.24 |
| Laravel | 12.64.0 |
| Inertia.js (Laravel) | 2.x |
| Node.js | 22.23.1 |
| React | 19.x |
| TypeScript | 5.x |
| Tailwind CSS | 3.x |
| Vite | 7.x |

### Layanan analisis citra

| Komponen | Versi |
| --- | --- |
| Python | 3.12.3 |
| FastAPI | 0.139.2 |
| Uvicorn | 0.51.0 |
| Pydantic | 2.13.4 |
| Pillow | 12.3.0 |
| Model multimodal | `qwen/qwen3.6-27b` melalui Groq |

Layanan ini dipisahkan dari aplikasi utama dan berkomunikasi lewat HTTP, sehingga
penyedia model dapat ditukar melalui berkas konfigurasi tanpa menyentuh kode
aplikasi. Penyedia lain yang didukung: NVIDIA NIM dan Google Gemini.

### Basis data

| Komponen | Versi |
| --- | --- |
| PostgreSQL | 17.6 |

Dihosting terpisah dari peladen aplikasi.

### Dataset

Citra uji berasal dari **SD-198** (Sun dkk, 2016), berisi 6.584 citra klinis pada
198 kategori penyakit kulit. Dataset tidak disertakan dalam repositori ini dan
harus disiapkan sendiri di `datasets/sd-198/images/<Nama_Kelas>/`.

---

## Menjalankan secara lokal

Prasyarat: PHP 8.2+, Composer, Node.js 20+, PostgreSQL, dan Python 3.12+.

```bash
# 1. aplikasi utama
composer run setup      # install, salin .env, generate key, migrate, build
php artisan db:seed     # isi basis pengetahuan awal

# 2. layanan analisis citra
cd ai-service
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env    # isi GROQ_API_KEYS
uvicorn app.main:app --port 8001

# 3. jalankan pengembangan
composer run dev        # server, queue, log, dan Vite sekaligus
```

> `php artisan db:seed` menjalankan seluruh seeder, termasuk yang mengatur ulang
> kata sandi administrator ke nilai bawaan. Jangan dijalankan pada produksi;
> perubahan data di sana dilakukan lewat migration.

### Variabel lingkungan

Aplikasi utama (`.env`):

| Nama | Keterangan |
| --- | --- |
| `APP_URL` | alamat aplikasi |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | koneksi PostgreSQL |
| `DERMACERDAS_AI_SERVICE_URL` | alamat layanan analisis citra |
| `DERMACERDAS_AI_TIMEOUT` | batas waktu panggilan, detik |
| `CONTACT_MAIL_TO` | tujuan formulir kontak |

Layanan analisis citra (`ai-service/.env`):

| Nama | Keterangan |
| --- | --- |
| `AI_PROVIDER` | `groq`, `nvidia`, atau `gemini` |
| `GROQ_API_KEYS` | satu atau beberapa kunci, dipisah koma |
| `GROQ_MODEL_NAME` | nama model, mis. `qwen/qwen3.6-27b` |
| `AI_MOCK_MODE` | matikan pada produksi |
| `MAX_IMAGE_SIZE_MB` | batas ukuran unggahan |

> Batas laju Groq: 1.000 permintaan per hari dan **8.000 token per menit** untuk
> setiap kunci. Satu analisis citra memakan sekitar 2.000 token, sehingga aman
> pada sekitar tiga permintaan per menit per kunci. Melebihi batas itu membuat
> sistem turun ke indeks visual cadangan yang jauh kurang tepat — keadaan
> tersebut ditandai `dataset_fallback` dan diberitahukan kepada pengguna.

---

## Pengujian

```bash
php artisan test              # seluruh berkas uji
php artisan test tests/Unit   # unit saja
```

Berkas uji yang paling penting:

| Berkas | Isi |
| --- | --- |
| `tests/Unit/FusionDecisionServiceTest.php` | seluruh aturan fusi, termasuk uji regresi kasus produksi |
| `tests/Unit/CertaintyFactorServiceTest.php` | rumus kombinasi paralel |
| `tests/Feature/ConsultationFlowTest.php` | alur konsultasi ujung ke ujung |

---

## Struktur

| Direktori | Isi |
| --- | --- |
| `app/Services/` | seluruh logika inti — lihat catatan di bawah |
| `app/Support/QuestionBank.php` | sumber awal 16 penyakit, 9 pertanyaan, dan matriks nilai keyakinan |
| `ai-service/` | layanan analisis citra berbasis FastAPI |
| `database/migrations/` | skema dan perubahan data |
| `resources/js/Pages/` | antarmuka React |
| `.github/workflows/deploy.yml` | penerapan otomatis ke produksi |

### Catatan penting bagi pembaca kode

**Urutan pemeriksaan aturan fusi tidak berada di `FusionDecisionService`.** Kelas
tersebut hanya menyediakan keputusan tiap aturan; rantai yang menentukan aturan
mana yang menang ada di `ConsultationWorkflowService::storeFinalResult()`, dengan
urutan F11 → F09 → F08 → `decide()` yang berisi F07, F13, lalu F01–F06. Membaca
`FusionDecisionService` sendirian akan memberi gambaran yang keliru.

Aturan yang berlaku: **F01–F09, F11, dan F13**. `F10` masih ada di kode tetapi
tidak pernah dipanggil dari alur konsultasi, dan `F12` tidak pernah
diimplementasikan — karena itu penomorannya melompat.

**Mode Foto** (`app/Http/Controllers/QuickScanController.php`) adalah jalur beta
yang sepenuhnya terpisah: hanya menerima foto, tidak melalui Certainty Factor
maupun aturan fusi, tidak disimpan ke basis data, dan tidak pernah menghasilkan
rekomendasi obat.

---

## Keterbatasan yang diketahui

Dicatat terbuka karena memengaruhi cara membaca keluaran sistem.

- **Nilai keyakinan hanya berisi bukti yang mendukung.** Measure of Disbelief
  ditetapkan tetap 0,20 pada seluruh 121 aturan, sehingga tidak ada jawaban yang
  dapat menurunkan keyakinan terhadap suatu penyakit. Akibatnya nilai gabungan
  seluruh kandidat cenderung menumpuk mendekati 100 persen dan selisih
  antarpenyakit menjadi sempit.
- **Ambang keyakinan tinggi mudah dilampaui.** Sebagai akibat butir di atas, dua
  jawaban bernilai sedang sudah cukup melewati ambang 0,60.
- **Penolakan kondisi di luar ruang lingkup masih lemah.** Sebagian besar citra
  di luar 16 penyakit tetap dipaksakan menjadi salah satu kelas yang tersedia.
- **Ruang kandidat visual lebih luas dari ruang lingkup Certainty Factor.** Dua
  kelas payung membuat model ditawari 31 kategori, bukan 16, dan pelebaran ini
  terukur menurunkan ketepatan.
- **Bergantung pada penyedia model pihak ketiga**, baik dari sisi ketersediaan
  maupun kuota.

---

## Lisensi

Kerangka kerja Laravel berlisensi [MIT](https://opensource.org/licenses/MIT).
Kode aplikasi ini disusun untuk keperluan penelitian skripsi.
