<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sembilan dari enam belas penyakit ber-basis Certainty Factor tidak memiliki
 * source_note, termasuk penyakit yang paling sering ditemui: kurap badan,
 * jerawat, eksim, dan panu. Akibatnya nilai keyakinan pada halaman pengelolaan
 * basis pengetahuan tidak dapat ditelusuri ke sumber klinis mana pun, padahal
 * Tabel 3.4 naskah kini mencantumkan rujukan untuk seluruh enam belas penyakit.
 *
 * Migration ini mengisi kekosongan itu memakai rujukan StatPearls yang sama
 * dengan yang tercantum pada naskah. Nomor Bookshelf setiap entri sudah
 * diperiksa langsung ke sumbernya.
 *
 * Hanya baris yang source_note-nya masih kosong yang diisi, sehingga tujuh
 * penyakit yang sudah memiliki rujukan (CDC, DermNet, AAD) tidak tertimpa.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const SOURCES = [
        'ECZEMA' => 'StatPearls Atopic dermatitis (Kolb & Ferrer-Bruker, 2023): https://www.ncbi.nlm.nih.gov/books/NBK448071/',
        'ALLERGIC_CONTACT_DERMATITIS' => 'StatPearls Contact dermatitis (Litchman dkk, 2023): https://www.ncbi.nlm.nih.gov/books/NBK459230/',
        'URTICARIA' => 'StatPearls Chronic urticaria (Mehta dkk, 2026): https://www.ncbi.nlm.nih.gov/books/NBK555910/',
        'ICHTHYOSIS' => 'StatPearls Hereditary and acquired ichthyosis vulgaris (Majmundar & Baxi, 2023): https://www.ncbi.nlm.nih.gov/books/NBK562318/',
        'TINEA_CORPORIS' => 'StatPearls Tinea corporis (Yee dkk, 2025): https://www.ncbi.nlm.nih.gov/books/NBK544360/',
        'TINEA_CRURIS' => 'StatPearls Tinea cruris (Pippin dkk, 2023): https://www.ncbi.nlm.nih.gov/books/NBK554602/',
        'TINEA_PEDIS' => 'StatPearls Tinea pedis (Nigam dkk, 2023): https://www.ncbi.nlm.nih.gov/books/NBK470421/',
        'TINEA_VERSICOLOR' => 'StatPearls Tinea versicolor (Karray & McKinney, 2024): https://www.ncbi.nlm.nih.gov/books/NBK482500/',
        'ACNE_VULGARIS' => 'StatPearls Acne vulgaris (Sutaria dkk, 2023): https://www.ncbi.nlm.nih.gov/books/NBK459173/',
    ];

    public function up(): void
    {
        foreach (self::SOURCES as $code => $note) {
            DB::table('diseases')
                ->where('code', $code)
                ->where(function ($query): void {
                    $query->whereNull('source_note')->orWhere('source_note', '');
                })
                ->update(['source_note' => $note, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Hanya mengosongkan kembali baris yang persis berisi teks dari
        // migration ini, agar catatan yang mungkin disunting administrator
        // sesudahnya tidak ikut terhapus.
        foreach (self::SOURCES as $code => $note) {
            DB::table('diseases')
                ->where('code', $code)
                ->where('source_note', $note)
                ->update(['source_note' => null, 'updated_at' => now()]);
        }
    }
};
