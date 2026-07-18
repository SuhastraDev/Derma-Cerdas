<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hasil Konsultasi {{ $consultation->session_code }}</title>
    <style>
        * { box-sizing: border-box; }
        body { background: #f6f8fa; color: #1b2124; font-family: Arial, sans-serif; line-height: 1.55; margin: 0; padding: 28px; }
        .sheet { background: #fff; border: 1px solid #dbe6eb; border-radius: 14px; margin: 0 auto; max-width: 980px; overflow: hidden; }
        .header { background: #111; color: #fff; padding: 28px; }
        .header-meta { color: #d4d4d4; margin: 14px 0 0; }
        .code { display: inline-block; background: #fef3c7; color: #9a3412; padding: 7px 11px; border-radius: 999px; font-weight: 700; }
        .content { padding: 26px; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 18px 0; }
        .visual-grid { display: grid; grid-template-columns: 0.95fr 1.45fr; gap: 14px; margin: 16px 0 24px; }
        .dataset-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 10px; }
        .card { background: #f7fafc; border: 1px solid #dbe6eb; border-radius: 10px; padding: 14px; }
        .section { border-top: 1px solid #ebf2f5; margin-top: 24px; padding-top: 20px; }
        .status { border-left: 5px solid #f59e0b; background: #fff7ed; border-radius: 10px; margin: 14px 0; padding: 12px 14px; }
        .image-card { border: 1px solid #dbe6eb; border-radius: 10px; padding: 12px; background: #fff; }
        .image-card img { width: 100%; height: 230px; object-fit: cover; border-radius: 7px; border: 1px solid #ebf2f5; }
        .dataset-grid img { height: 125px; }
        .caption { color: #525f66; font-size: 11px; margin-top: 6px; word-break: break-word; }
        .label { color: #667780; font-size: 11px; text-transform: uppercase; font-weight: 700; letter-spacing: .04em; }
        .value { color: #1b2124; font-size: 24px; font-weight: 800; margin-top: 4px; }
        .recommendation { border: 1px solid #dbe6eb; border-radius: 10px; margin-top: 10px; padding: 14px; page-break-inside: avoid; }
        .warning { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; color: #9a3412; margin-top: 8px; padding: 8px 10px; }
        h1, h2, h3 { margin: 0 0 8px; }
        h1 { font-size: 27px; line-height: 1.2; }
        h2 { color: #1b2124; font-size: 18px; }
        p { margin: 0 0 10px; }
        .actions { margin-bottom: 20px; }
        button { background: #fb923c; border: 0; border-radius: 999px; color: white; cursor: pointer; font-weight: 700; padding: 10px 16px; }
        @media print {
            body { background: #fff; padding: 0; }
            .sheet { border: 0; border-radius: 0; max-width: none; }
            .actions { display: none; }
            .visual-grid { grid-template-columns: 1fr 1fr; }
            .image-card img { height: 180px; }
            .dataset-grid img { height: 95px; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" onclick="window.print()">Simpan sebagai PDF</button>
    </div>

    <main class="sheet">
        <header class="header">
            <div class="code">{{ $consultation->session_code }}</div>
            <h1 style="margin-top: 14px;">Hasil Konsultasi Awal DermaCerdas</h1>
            <p class="header-meta">
                Nama: <strong>{{ $consultation->visitor_name ?: '-' }}</strong><br>
                Tanggal: {{ optional($consultation->created_at)->format('d M Y H:i') }}<br>
                Status: {{ ucfirst($consultation->status) }}
            </p>
        </header>

        <div class="content">
            @if ($finalResult)
                <h2>Kemungkinan utama</h2>
                <p style="font-size: 20px;"><strong>{{ $finalResult->disease?->name_indonesian ?: $finalResult->disease?->name }}</strong></p>
                <div class="grid">
                    <div class="card"><div class="label">Visual</div><div class="value">{{ round($finalResult->visual_score * 100) }}%</div></div>
                    <div class="card"><div class="label">Gejala CF</div><div class="value">{{ round($finalResult->textual_cf * 100) }}%</div></div>
                    <div class="card"><div class="label">Fusion</div><div class="value">{{ round($finalResult->fusion_score * 100) }}%</div></div>
                </div>
                <div class="status">{{ $finalResult->explanation }}</div>

                <section class="section">
                    <h2>Keluhan pengguna</h2>
                    <div class="card">
                        {{ $consultation->complaint_text ?: 'Keluhan tidak tersedia.' }}
                    </div>
                    @if (count($complaintSummary ?? []))
                        <div style="margin-top: 12px;">
                            @foreach ($complaintSummary as $item)
                                <div class="card" style="margin-top: 8px;">{{ $item }}</div>
                            @endforeach
                        </div>
                    @endif
                </section>

                <section class="section">
                    <h2>Bukti visual dan pembanding dataset</h2>
                    <p>
                        Foto pengguna dibandingkan dengan contoh gambar dari dataset SD-198 pada class hasil utama.
                        Contoh dataset adalah pembanding visual, bukan diagnosis pasti.
                    </p>
                    <div class="visual-grid">
                        <div class="image-card">
                            <div class="label">Foto yang dikirim user</div>
                            @if ($uploadedImageUrl)
                                <img src="{{ $uploadedImageUrl }}" alt="Foto konsultasi user">
                            @else
                                <p>Foto tidak tersedia.</p>
                            @endif
                        </div>
                        <div class="image-card">
                            <div class="label">Contoh dataset SD-198</div>
                            @if (count($comparisonImages ?? []))
                                <div class="dataset-grid">
                                    @foreach ($comparisonImages as $image)
                                        <div>
                                            <img src="{{ $image['url'] }}" alt="Contoh dataset {{ $image['class_name'] }}">
                                            <div class="caption">
                                                {{ $image['class_name'] }}<br>
                                                {{ $image['file_name'] }}
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p>Contoh dataset belum tersedia di folder lokal.</p>
                            @endif
                        </div>
                    </div>
                </section>

                <section class="section">
                    <h2>Rekomendasi</h2>
                    @if (count($finalResult->recommendations_snapshot ?? []))
                        @foreach ($finalResult->recommendations_snapshot as $recommendation)
                            <article class="recommendation">
                                <h3>{{ $recommendation['medicine_name'] }}</h3>
                                <p class="caption">{{ $recommendation['category'] ?? '-' }} / {{ $recommendation['dosage_form'] ?? '-' }}</p>
                                <p>{{ $recommendation['usage_instruction'] ?? $recommendation['recommendation_note'] ?? '-' }}</p>
                                @if (! empty($recommendation['warnings']))
                                    <div class="warning"><strong>Peringatan:</strong> {{ $recommendation['warnings'] }}</div>
                                @endif
                            </article>
                        @endforeach
                    @else
                        <div class="status">Tidak ada rekomendasi obat mandiri pada hasil ini. Sistem hanya menampilkan obat jika skor aman, tidak ada red flag, dan mapping penyakit memperbolehkan swamedikasi.</div>
                    @endif
                </section>
            @endif

            <section class="section">
                <h2>Catatan keamanan</h2>
                <p>Dokumen ini adalah ringkasan skrining awal dan bukan diagnosis dokter. Jika keluhan memburuk, menyebar cepat, bernanah, disertai demam, nyeri hebat, atau terjadi pada bayi/ibu hamil/imunokompromais, segera periksa ke tenaga kesehatan.</p>
            </section>
        </div>
    </main>
</body>
</html>
