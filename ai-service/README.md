# DermaCerdas AI Service

FastAPI service untuk Phase 5 DermaCerdas. Service ini menangani validasi gambar, analisis visual via provider vision (`AI_PROVIDER=nvidia` atau `AI_PROVIDER=groq`), parsing kandidat penyakit, fallback indeks dataset, dan mapping kandidat visual ke class SD-198 / disease lokal.

## Menjalankan Lokal

```powershell
cd ai-service
python -m venv .venv
.\.venv\Scripts\activate
pip install -r requirements.txt
copy .env.example .env
uvicorn app.main:app --reload --port 8001
```

Default `AI_MOCK_MODE=true`, jadi endpoint `/analyze-image` bisa dites tanpa API key.

Untuk NVIDIA NIM asli, isi `NVIDIA_API_KEY` (dari [build.nvidia.com](https://build.nvidia.com)), set `AI_PROVIDER=nvidia`, dan set `AI_MOCK_MODE=false`.

Untuk Groq vision, isi `GROQ_API_KEY`, set `AI_PROVIDER=groq`, pakai model default `qwen/qwen3.6-27b`, dan set `AI_MOCK_MODE=false`.

## Endpoint

- `GET /health`
- `POST /validate-image`
- `POST /analyze-image`

## Indeks visual dataset

Bangun indeks setelah folder SD-198 tersedia. Indeks menyimpan centroid fitur visual per class dan tidak menyalin file gambar.

```bash
python scripts/build_dataset_index.py --max-images-per-class 40
```

Endpoint `/health` menampilkan `dataset_index_ready=true` jika indeks berhasil dimuat.

## Catatan Safety

Output AI service hanya kandidat visual. Keputusan final tetap dilakukan Laravel melalui Certainty Factor, fusion, threshold, dataset scope, dan red flags.
