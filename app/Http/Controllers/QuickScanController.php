<?php

namespace App\Http\Controllers;

use App\Services\AiVisualService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mode Foto (beta): jalur TERPISAH dari konsultasi utama (ConsultationController).
 * User upload foto saja, tanpa gejala/CF - AI dipersilakan menyebut sendiri
 * kemungkinan kondisi kulit apa pun (tidak dikekang 16 penyakit/dataset SD-198).
 *
 * Sengaja stateless (tidak disimpan ke database, tidak ada kode sesi/riwayat):
 * fitur beta ini tidak boleh bersinggungan sama sekali dengan alur CF/fusion/
 * rekomendasi obat yang jadi inti sistem - lihat AiVisualService::analyzeOpen().
 * Hasilnya murni referensi edukasi mentah dengan disclaimer eksplisit di UI,
 * bukan diagnosis dan tidak pernah membawa rekomendasi obat.
 */
class QuickScanController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('QuickScan/Index', [
            'result' => null,
        ]);
    }

    public function store(Request $request, AiVisualService $aiVisualService): Response
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $imagePath = $validated['image']->store('quick-scans', 'public');

        try {
            $analysis = $aiVisualService->analyzeOpen($imagePath);
        } finally {
            // Stateless: foto cuma dipakai sekali untuk request ini, tidak
            // disimpan untuk riwayat (beda dari konsultasi utama yang memang
            // butuh foto tersimpan untuk hasil bisa dibuka ulang lewat kode).
            Storage::disk('public')->delete($imagePath);
        }

        return Inertia::render('QuickScan/Index', [
            'result' => $analysis,
        ]);
    }
}
