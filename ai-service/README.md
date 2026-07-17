# DermaCerdas AI Service

FastAPI service untuk Phase 5 DermaCerdas. Service ini menangani validasi gambar, analisis visual via Gemini, parsing kandidat penyakit, dan mapping kandidat visual ke class SD-198 / disease lokal.

## Menjalankan Lokal

```powershell
cd ai-service
python -m venv .venv
.\.venv\Scripts\activate
pip install -r requirements.txt
copy .env.example .env
uvicorn app.main:app --reload --port 8001
```

Default `AI_MOCK_MODE=true`, jadi endpoint `/analyze-image` bisa dites tanpa API key. Untuk Gemini asli, isi `GEMINI_API_KEY` dan set `AI_MOCK_MODE=false`.

## Endpoint

- `GET /health`
- `POST /validate-image`
- `POST /analyze-image`

## Catatan Safety

Output AI service hanya kandidat visual. Keputusan final tetap dilakukan Laravel melalui Certainty Factor, fusion, threshold, dataset scope, dan red flags.
