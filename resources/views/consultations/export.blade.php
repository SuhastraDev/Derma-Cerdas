<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hasil Konsultasi {{ $consultation->session_code }}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #171717; margin: 32px; line-height: 1.55; }
        .header { border-bottom: 2px solid #f59e0b; padding-bottom: 16px; margin-bottom: 24px; }
        .code { display: inline-block; background: #fef3c7; color: #9a3412; padding: 6px 10px; border-radius: 999px; font-weight: 700; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 18px 0; }
        .card { border: 1px solid #e5e5e5; border-radius: 12px; padding: 14px; }
        .label { color: #737373; font-size: 12px; text-transform: uppercase; font-weight: 700; }
        .value { font-size: 22px; font-weight: 700; margin-top: 4px; }
        h1, h2 { margin: 0 0 8px; }
        ul { padding-left: 20px; }
        .actions { margin-bottom: 20px; }
        button { background: #fb923c; border: 0; border-radius: 999px; color: white; cursor: pointer; font-weight: 700; padding: 10px 16px; }
        @media print {
            body { margin: 18mm; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" onclick="window.print()">Simpan sebagai PDF</button>
    </div>

    <header class="header">
        <div class="code">{{ $consultation->session_code }}</div>
        <h1 style="margin-top: 14px;">Hasil Konsultasi Awal DermaCerdas</h1>
        <p>
            Nama: <strong>{{ $consultation->visitor_name ?: '-' }}</strong><br>
            Tanggal: {{ optional($consultation->created_at)->format('d M Y H:i') }}<br>
            Status: {{ $consultation->status }}
        </p>
    </header>

    @if ($finalResult)
        <h2>Kemungkinan utama</h2>
        <p><strong>{{ $finalResult->disease?->name_indonesian ?: $finalResult->disease?->name }}</strong></p>
        <div class="grid">
            <div class="card"><div class="label">Visual</div><div class="value">{{ round($finalResult->visual_score * 100) }}%</div></div>
            <div class="card"><div class="label">Gejala CF</div><div class="value">{{ round($finalResult->textual_cf * 100) }}%</div></div>
            <div class="card"><div class="label">Fusion</div><div class="value">{{ round($finalResult->fusion_score * 100) }}%</div></div>
        </div>
        <p>{{ $finalResult->explanation }}</p>

        <h2>Rekomendasi</h2>
        @if (count($finalResult->recommendations_snapshot ?? []))
            <ul>
                @foreach ($finalResult->recommendations_snapshot as $recommendation)
                    <li>
                        <strong>{{ $recommendation['medicine_name'] }}</strong>:
                        {{ $recommendation['usage_instruction'] ?? '-' }}
                        @if (! empty($recommendation['warnings']))
                            <br>Peringatan: {{ $recommendation['warnings'] }}
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p>Tidak ada rekomendasi obat mandiri pada hasil ini.</p>
        @endif
    @endif

    <h2>Catatan keamanan</h2>
    <p>Dokumen ini adalah ringkasan skrining awal dan bukan diagnosis dokter.</p>
</body>
</html>
