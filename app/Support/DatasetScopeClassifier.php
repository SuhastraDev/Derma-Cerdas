<?php

namespace App\Support;

use Illuminate\Support\Str;

class DatasetScopeClassifier
{
    /**
     * @return array{scope_category: string, boleh_rekomendasi_obat: bool, default_action: string, risk_note: string}
     */
    public static function classify(string $className): array
    {
        $name = Str::of($className)->lower()->replace(['_', '-', '(', ')'], ' ')->squish()->toString();

        $referKeywords = [
            'carcinoma',
            'melanoma',
            'bowen',
            'lymphoma',
            'metastatic',
            'keratoacanthoma',
            'cutaneous t cell',
            'vasculitis',
            'lupus',
            'behcet',
            'erythroderma',
            'drug eruption',
            'pyoderma',
            'cellulitis',
            'wound infection',
            'ulcer',
            'mal perforans',
            'herpes zoster',
            'herpes simplex',
            'varicella',
            'kerion',
            'hidradenitis',
            'paronychia',
            'stasis ulcer',
            'mucha habermann',
            'histiocytosis',
            'lymphomatoid',
            'morphea',
            'radiodermatitis',
        ];

        $selfCareKeywords = [
            'acne vulgaris',
            'pomade acne',
            'steroid acne',
            'eczema',
            'dermatitis',
            'urticaria',
            'candidiasis',
            'tinea',
            'onychomycosis',
            'pityrosporum',
            'seborrheic dermatitis',
            'perioral dermatitis',
            'pitted keratolysis',
            'callus',
            'keratosis pilaris',
            'xerosis',
            'dry skin',
            'pityriasis alba',
            'pityriasis rosea',
            'rosacea',
            'molluscum',
            'verruca',
            'impetigo',
            'angular cheilitis',
            'aphthous ulcer',
        ];

        $excludeKeywords = [
            'androgenetic alopecia',
            'alopecia areata',
            'scarring alopecia',
            'clubbing',
            'toe deformity',
            'racquet nail',
            'beau',
            'terry',
            'half and half',
            'koilonychia',
        ];

        if (self::containsAny($name, $referKeywords)) {
            return [
                'scope_category' => 'rujuk',
                'boleh_rekomendasi_obat' => false,
                'default_action' => 'refer',
                'risk_note' => 'Kondisi ini berpotensi membutuhkan pemeriksaan langsung atau terapi resep. Sistem hanya memberi arahan rujukan.',
            ];
        }

        if (self::containsAny($name, $excludeKeywords)) {
            return [
                'scope_category' => 'exclude',
                'boleh_rekomendasi_obat' => false,
                'default_action' => 'educate_only',
                'risk_note' => 'Class ini tidak dipakai untuk rekomendasi obat mandiri pada scope MVP.',
            ];
        }

        if (self::containsAny($name, $selfCareKeywords)) {
            return [
                'scope_category' => 'swamedikasi',
                'boleh_rekomendasi_obat' => true,
                'default_action' => 'recommend_otc',
                'risk_note' => 'Masuk scope keluhan ringan. Rekomendasi obat tetap dibatasi oleh red flag, skor keyakinan, dan aturan keamanan.',
            ];
        }

        return [
            'scope_category' => 'edukasi',
            'boleh_rekomendasi_obat' => false,
            'default_action' => 'educate_only',
            'risk_note' => 'Belum masuk daftar swamedikasi aman. Sistem memberi edukasi/perawatan umum sampai diverifikasi admin.',
        ];
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private static function containsAny(string $name, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($name, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
