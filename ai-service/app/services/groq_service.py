from __future__ import annotations

import base64
import json
import re
from io import BytesIO
from typing import Any

import httpx
from PIL import Image, ImageOps

from app.config import settings
from app.schemas import VisualCandidate
from app.services.class_mapping import allowed_candidate_classes, normalize_class_name, resolve_mapping
from app.services.image_validation import ImageValidator

GROQ_CHAT_COMPLETIONS_URL = "https://api.groq.com/openai/v1/chat/completions"

# Vision token cost scales with image size. A skin lesion photo does not need
# more than this to stay legible, and keeping requests smaller helps avoid
# Groq's per-minute token rate limit.
GROQ_MAX_IMAGE_DIMENSION = 768


class GroqVisualClient:
    provider = "groq"

    def analyze(
        self,
        image_base64: str,
        candidate_classes: list[str],
        dataset_matches: list[dict[str, Any]] | None = None,
    ) -> dict[str, Any]:
        classes = allowed_candidate_classes(candidate_classes)

        if settings.ai_mock_mode or not settings.groq_api_key:
            return self.mock_response(classes)

        return self.groq_response(image_base64, classes, dataset_matches or [])

    def mock_response(self, classes: list[str]) -> dict[str, Any]:
        return {
            "provider_status": "mock_mode",
            "is_valid_skin_image": False,
            "candidates": [],
            "warnings": [
                "AI_MOCK_MODE aktif; validasi foto kulit tidak dijalankan agar sistem tidak memberi hasil visual palsu."
            ],
            "raw_response": {"mode": "mock"},
        }

    def groq_response(
        self,
        image_base64: str,
        classes: list[str],
        dataset_matches: list[dict[str, Any]],
    ) -> dict[str, Any]:
        data_url = self.image_data_url(image_base64)
        prompt = self.prompt(classes, dataset_matches)

        try:
            body = self.chat_completion(prompt, data_url)
        except Exception as exc:
            return self.provider_error_response(exc, "Groq API")

        text = self.completion_text(body)

        return self.response_from_text(text)

    def validate_skin_image(self, image_base64: str) -> dict[str, Any]:
        if settings.ai_mock_mode or not settings.groq_api_key:
            return {
                "provider_status": "mock_mode",
                "is_valid_skin_image": False,
                "warnings": ["AI_MOCK_MODE aktif; filter kulit tidak dijalankan."],
                "raw_response": {"mode": "mock"},
            }

        data_url = self.image_data_url(image_base64)

        try:
            body = self.chat_completion(self.skin_filter_prompt(), data_url)
        except Exception as exc:
            return self.provider_error_response(exc, "Groq skin filter")

        text = self.completion_text(body)

        return self.skin_filter_response_from_text(text)

    def image_data_url(self, image_base64: str) -> str:
        raw = ImageValidator().decode_base64(image_base64)
        resized = self.downscale(raw)

        return f"data:image/jpeg;base64,{base64.b64encode(resized).decode('ascii')}"

    def downscale(self, raw: bytes) -> bytes:
        try:
            with Image.open(BytesIO(raw)) as image:
                image = ImageOps.exif_transpose(image) or image

                if max(image.size) > GROQ_MAX_IMAGE_DIMENSION:
                    image.thumbnail((GROQ_MAX_IMAGE_DIMENSION, GROQ_MAX_IMAGE_DIMENSION), Image.Resampling.LANCZOS)

                buffer = BytesIO()
                image.convert("RGB").save(buffer, format="JPEG", quality=85)

                return buffer.getvalue()
        except Exception:
            return raw

    def chat_completion(self, prompt: str, image_data_url: str) -> dict[str, Any]:
        response = httpx.post(
            GROQ_CHAT_COMPLETIONS_URL,
            headers={
                "Authorization": f"Bearer {settings.groq_api_key}",
                "Content-Type": "application/json",
            },
            json={
                "model": settings.groq_model_name,
                "temperature": 0.2,
                # Qwen3's chain-of-thought reasoning burns hundreds to thousands of
                # extra completion tokens per call and isn't needed for this
                # classification task; disabling it keeps calls well inside Groq's
                # per-minute token budget and avoids <think> blocks breaking JSON parsing.
                "reasoning_effort": "none",
                "messages": [
                    {
                        "role": "user",
                        "content": [
                            {"type": "text", "text": prompt},
                            {"type": "image_url", "image_url": {"url": image_data_url}},
                        ],
                    }
                ],
            },
            timeout=60.0,
        )
        response.raise_for_status()

        return response.json()

    def completion_text(self, body: dict[str, Any]) -> str:
        choices = body.get("choices", [])

        if not choices:
            return ""

        return choices[0].get("message", {}).get("content", "") or ""

    def provider_error_response(self, exception: Exception, operation: str) -> dict[str, Any]:
        error = self.describe_http_error(exception)
        normalized = error.lower()
        quota_exceeded = (
            "429" in normalized
            or "rate_limit" in normalized
            or "rate limit" in normalized
            or "quota" in normalized
        )
        provider_status = "quota_exceeded" if quota_exceeded else "unavailable"
        warning = (
            "Kuota/limit Groq API telah habis. Tunggu kuota tersedia kembali atau gunakan API key dengan limit aktif."
            if quota_exceeded
            else f"{operation} sedang tidak tersedia. Coba kembali beberapa saat lagi."
        )

        return {
            "provider_status": provider_status,
            "is_valid_skin_image": False,
            "candidates": [],
            "warnings": [warning],
            "raw_response": {
                "error": error,
                "error_code": provider_status,
                "model": settings.groq_model_name,
            },
        }

    def describe_http_error(self, exception: Exception) -> str:
        response = getattr(exception, "response", None)

        if response is not None:
            try:
                return f"{response.status_code} {response.text}"
            except Exception:
                pass

        return str(exception)

    def response_from_text(self, text: str) -> dict[str, Any]:
        parsed = self.parse_json_text(text)

        raw_candidates = parsed.get("candidates", [])
        has_candidates = isinstance(raw_candidates, list) and len(raw_candidates) > 0
        skin_evidence_score = self.skin_evidence_score(parsed.get("skin_evidence_score"))
        is_valid_skin_image = bool(parsed.get("is_valid_skin_image", True)) or has_candidates or skin_evidence_score >= 0.35

        return {
            "provider_status": "ok",
            "is_valid_skin_image": is_valid_skin_image,
            "candidates": raw_candidates,
            "warnings": parsed.get("warnings", []),
            "raw_response": {
                "text": text,
                "model": settings.groq_model_name,
                "skin_evidence_score": skin_evidence_score,
            },
        }

    def prompt(self, classes: list[str], dataset_matches: list[dict[str, Any]] | None = None) -> str:
        class_list = json.dumps(classes, ensure_ascii=False)
        retrieval_hints = json.dumps(
            [
                {
                    "dataset_class_name": match.get("dataset_class_name"),
                    "similarity": match.get("similarity"),
                }
                for match in (dataset_matches or [])
            ],
            ensure_ascii=False,
        )

        return (
            "Anda adalah komponen visual screening DermaCerdas, bukan dokter. "
            "Tugas pertama adalah FILTER KULIT: tentukan apakah gambar menampilkan area kulit/tubuh manusia. "
            "Gunakan filter yang longgar untuk mencegah false negative. Bagian tubuh seperti tangan, kaki, wajah, leher, "
            "punggung, perut, paha, bokong, kulit kepala, kuku, atau lipatan tubuh harus dianggap valid jika kulit terlihat. "
            "Foto close-up, blur ringan, pencahayaan kurang ideal, atau lesi tidak jelas tetap valid selama area kulit manusia terlihat. "
            "Set is_valid_skin_image false hanya jika objek utama jelas bukan manusia/area kulit, misalnya makanan, dokumen, benda, "
            "layar, pemandangan, atau file rusak. Jangan mensyaratkan penyakit terlihat jelas untuk menyatakan kulit valid. "
            "Isi skin_evidence_score 0.0 sampai 1.0 berdasarkan keyakinan bahwa gambar berisi kulit/tubuh manusia. "
            "Tugas kedua adalah kandidat awal: jika filter kulit valid, pilih maksimal 3 kandidat dari daftar class berikut bila ada yang mirip. "
            "Salin dataset_class_name persis dari daftar dan jangan membuat nama class baru: "
            f"{class_list}. "
            "Sistem lokal juga menghitung kemiripan warna, pola spasial, dan tekstur terhadap centroid gambar dataset. "
            f"Hint retrieval dataset: {retrieval_hints}. "
            "Hint ini hanya shortlist pendukung, bukan diagnosis; abaikan bila bertentangan dengan tampilan gambar. "
            "Balas HANYA dengan JSON valid (tanpa markdown, tanpa penjelasan lain) dengan struktur persis: "
            '{"is_valid_skin_image": true, "skin_evidence_score": 0.86, "candidates": ['
            '{"dataset_class_name": "Tinea_Corporis", "visual_score": 0.74, '
            '"reason": "alasan visual singkat"}], "warnings": []}. '
            "Jika gambar valid sebagai kulit tetapi tidak cocok dengan daftar class, tetap set is_valid_skin_image true, "
            "skin_evidence_score tinggi, candidates kosong, dan jelaskan kualitas/keterbatasan di warnings."
        )

    def skin_evidence_score(self, value: Any) -> float:
        try:
            score = float(value)
        except (TypeError, ValueError):
            return 0.0

        return max(0.0, min(1.0, score))

    def skin_filter_prompt(self) -> str:
        return (
            "Anda adalah filter visual khusus yang hanya menentukan apakah gambar berisi bagian tubuh dan kulit manusia. "
            "Jangan menilai penyakit dan jangan butuh lesi yang jelas. "
            "Jawab true jika terlihat kulit manusia atau bagian tubuh manusia seperti tangan, kaki, wajah, leher, lengan, paha, "
            "punggung, perut, bokong, kulit kepala, kuku, atau lipatan tubuh, termasuk bila ada bercak putih/cokelat, ruam, luka, "
            "bercak putih vitiligo, warna kulit tidak merata, blur ringan, crop close-up, atau pencahayaan kurang ideal. "
            "Jawab false hanya jika objek utama jelas bukan manusia/area kulit, misalnya makanan, dokumen, benda, layar, pemandangan, "
            "atau file tidak dapat dinilai. "
            "Contoh valid: lengan dengan bercak putih seperti vitiligo, close-up kaki dengan ruam, wajah berjerawat, kuku, "
            "kulit kepala, atau lipatan tubuh. Contoh tidak valid: dokumen, tangkapan layar, makanan, kendaraan, dan pemandangan. "
            "Nilai contains_human_body_part dan contains_visible_skin secara terpisah sebelum menyimpulkan. "
            "Jika keduanya true maka is_valid_skin_image juga harus true, walaupun penyakit tidak dikenali. "
            "Balas HANYA dengan JSON valid (tanpa markdown, tanpa penjelasan lain) dengan struktur persis: "
            '{"contains_human_body_part": true, "contains_visible_skin": true, "is_valid_skin_image": true, '
            '"skin_evidence_score": 0.9, "reason": "alasan singkat", "warnings": []}.'
        )

    def skin_filter_response_from_text(self, text: str) -> dict[str, Any]:
        parsed = self.parse_json_text(text)
        parse_failed = any(
            warning.startswith("Respons Groq")
            for warning in parsed.get("warnings", [])
        )
        skin_evidence_score = self.skin_evidence_score(parsed.get("skin_evidence_score"))
        text_signal = self.skin_text_signal(text) if parse_failed else None
        has_anatomical_skin_evidence = (
            parsed.get("contains_human_body_part") is True
            and parsed.get("contains_visible_skin") is True
        )
        is_valid_skin_image = (
            bool(parsed.get("is_valid_skin_image", False))
            or skin_evidence_score >= 0.35
            or has_anatomical_skin_evidence
            or text_signal is True
        )
        warnings = parsed.get("warnings", [])

        if parse_failed and text_signal is not None:
            warnings = ["Respons Groq skin filter tidak JSON valid, tetapi sinyal teks tetap terbaca."]

        return {
            "provider_status": "ok",
            "is_valid_skin_image": is_valid_skin_image,
            "warnings": warnings,
            "raw_response": {
                "text": text,
                "model": settings.groq_model_name,
                "skin_evidence_score": skin_evidence_score,
                "contains_human_body_part": parsed.get("contains_human_body_part"),
                "contains_visible_skin": parsed.get("contains_visible_skin"),
                "skin_text_signal": text_signal,
            },
        }

    def skin_text_signal(self, text: str) -> bool | None:
        normalized = text.lower()
        positive_patterns = [
            "true",
            "valid",
            "kulit",
            "skin",
            "human",
            "manusia",
            "body",
            "tubuh",
            "arm",
            "lengan",
            "hand",
            "tangan",
            "leg",
            "kaki",
            "vitiligo",
            "bercak putih",
        ]
        negative_patterns = [
            "false",
            "not skin",
            "bukan kulit",
            "tidak ada kulit",
            "non-skin",
            "object",
            "benda",
            "document",
            "dokumen",
            "food",
            "makanan",
            "screen",
            "layar",
            "landscape",
            "pemandangan",
        ]

        has_positive = any(pattern in normalized for pattern in positive_patterns)
        has_negative = any(pattern in normalized for pattern in negative_patterns)

        if has_positive and not has_negative:
            return True

        if has_negative and not has_positive:
            return False

        return None

    def parse_json_text(self, text: str) -> dict[str, Any]:
        # Reasoning models such as Qwen may prefix the answer with a
        # <think>...</think> block before the actual JSON payload.
        cleaned = re.sub(r"<think>.*?</think>", "", text, flags=re.DOTALL | re.IGNORECASE).strip()

        fenced = re.search(r"```(?:json)?\s*(.*?)\s*```", cleaned, re.DOTALL | re.IGNORECASE)

        if fenced:
            cleaned = fenced.group(1)

        data = self.try_parse_json(cleaned)

        if data is None:
            data = self.try_parse_json(self.extract_first_json_object(cleaned))

        if data is None:
            return {
                "is_valid_skin_image": False,
                "candidates": [],
                "warnings": ["Respons Groq tidak berbentuk JSON valid."],
            }

        if not isinstance(data, dict):
            return {
                "is_valid_skin_image": False,
                "candidates": [],
                "warnings": ["Respons Groq tidak sesuai struktur yang diminta."],
            }

        return data

    def try_parse_json(self, text: str | None) -> Any:
        if not text:
            return None

        try:
            return json.loads(text)
        except json.JSONDecodeError:
            return None

    def extract_first_json_object(self, text: str) -> str | None:
        """Scan for the first balanced {...} object, tolerating any prose around it."""
        start = text.find("{")

        if start == -1:
            return None

        depth = 0
        in_string = False
        escape = False

        for index in range(start, len(text)):
            char = text[index]

            if in_string:
                if escape:
                    escape = False
                elif char == "\\":
                    escape = True
                elif char == '"':
                    in_string = False
                continue

            if char == '"':
                in_string = True
            elif char == "{":
                depth += 1
            elif char == "}":
                depth -= 1

                if depth == 0:
                    return text[start:index + 1]

        return None


def normalize_candidates(
    raw_candidates: list[dict[str, Any]],
    allowed_classes: list[str] | None = None,
) -> list[VisualCandidate]:
    normalized: list[VisualCandidate] = []
    allowed = allowed_candidate_classes(allowed_classes or [])
    canonical_names = {normalize_class_name(class_name): class_name for class_name in allowed}

    for raw in raw_candidates:
        class_name = str(raw.get("dataset_class_name", "")).strip()
        canonical_name = canonical_names.get(normalize_class_name(class_name))

        if not canonical_name:
            continue

        mapping = resolve_mapping(canonical_name)

        try:
            score = max(0.0, min(1.0, float(raw.get("visual_score", 0))))
        except (TypeError, ValueError):
            score = 0.0

        normalized.append(
            VisualCandidate(
                dataset_class_name=mapping.dataset_class_name if mapping else canonical_name,
                local_disease_code=mapping.local_disease_code if mapping else None,
                visual_score=round(score, 4),
                reason=str(raw.get("reason") or "Kandidat visual dari Groq."),
            )
        )

    return sorted(normalized, key=lambda item: item.visual_score, reverse=True)[:3]
