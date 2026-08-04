from __future__ import annotations

import json
import re
from io import BytesIO
from typing import Any

from PIL import Image

from app.config import settings
from app.schemas import VisualCandidate
from app.services.class_mapping import allowed_candidate_classes, resolve_mapping
from app.services.image_validation import ImageValidator


class GeminiVisualClient:
    provider = "gemini"

    def analyze(self, image_base64: str, candidate_classes: list[str]) -> dict[str, Any]:
        classes = allowed_candidate_classes(candidate_classes)

        if settings.ai_mock_mode or not settings.gemini_api_key:
            return self.mock_response(classes)

        return self.gemini_response(image_base64, classes)

    def mock_response(self, classes: list[str]) -> dict[str, Any]:
        return {
            "is_valid_skin_image": False,
            "candidates": [],
            "warnings": [
                "AI_MOCK_MODE aktif; validasi foto kulit tidak dijalankan agar sistem tidak memberi hasil visual palsu."
            ],
            "raw_response": {"mode": "mock"},
        }

    def gemini_response(self, image_base64: str, classes: list[str]) -> dict[str, Any]:
        try:
            from google import genai
        except ImportError as exc:
            raise RuntimeError("Package google-genai belum terinstall.") from exc

        raw = ImageValidator().decode_base64(image_base64)
        image = Image.open(BytesIO(raw))
        client = genai.Client(api_key=settings.gemini_api_key)
        prompt = self.prompt(classes)

        try:
            response = client.models.generate_content(
                model=settings.gemini_model_name,
                contents=[prompt, image],
            )
        except Exception as exc:
            return {
                "is_valid_skin_image": False,
                "candidates": [],
                "warnings": [f"Gemini API gagal: {exc}"],
                "raw_response": {"error": str(exc), "model": settings.gemini_model_name},
            }

        text = getattr(response, "text", "") or ""

        return self.response_from_text(text)

    def validate_skin_image(self, image_base64: str) -> dict[str, Any]:
        if settings.ai_mock_mode or not settings.gemini_api_key:
            return {
                "is_valid_skin_image": False,
                "warnings": ["AI_MOCK_MODE aktif; filter kulit tidak dijalankan."],
                "raw_response": {"mode": "mock"},
            }

        try:
            from google import genai
        except ImportError as exc:
            raise RuntimeError("Package google-genai belum terinstall.") from exc

        raw = ImageValidator().decode_base64(image_base64)
        image = Image.open(BytesIO(raw))
        client = genai.Client(api_key=settings.gemini_api_key)

        try:
            response = client.models.generate_content(
                model=settings.gemini_model_name,
                contents=[self.skin_filter_prompt(), image],
            )
        except Exception as exc:
            return {
                "is_valid_skin_image": False,
                "warnings": [f"Gemini skin filter gagal: {exc}"],
                "raw_response": {"error": str(exc), "model": settings.gemini_model_name},
            }

        text = getattr(response, "text", "") or ""

        return self.skin_filter_response_from_text(text)

    def response_from_text(self, text: str) -> dict[str, Any]:
        parsed = self.parse_json_text(text)

        raw_candidates = parsed.get("candidates", [])
        has_candidates = isinstance(raw_candidates, list) and len(raw_candidates) > 0
        skin_evidence_score = self.skin_evidence_score(parsed.get("skin_evidence_score"))
        is_valid_skin_image = bool(parsed.get("is_valid_skin_image", True)) or has_candidates or skin_evidence_score >= 0.35

        return {
            "is_valid_skin_image": is_valid_skin_image,
            "candidates": raw_candidates,
            "warnings": parsed.get("warnings", []),
            "raw_response": {
                "text": text,
                "model": settings.gemini_model_name,
                "skin_evidence_score": skin_evidence_score,
            },
        }

    def prompt(self, classes: list[str]) -> str:
        class_list = ", ".join(classes)

        return (
            "Anda adalah komponen visual screening DermaCerdas, bukan dokter. "
            "Tugas pertama adalah FILTER KULIT: tentukan apakah gambar menampilkan area kulit/tubuh manusia. "
            "Gunakan filter yang longgar untuk mencegah false negative. Bagian tubuh seperti tangan, kaki, wajah, leher, "
            "punggung, perut, paha, bokong, kulit kepala, kuku, atau lipatan tubuh harus dianggap valid jika kulit terlihat. "
            "Foto close-up, blur ringan, pencahayaan kurang ideal, atau lesi tidak jelas tetap valid selama area kulit manusia terlihat. "
            "Set is_valid_skin_image false hanya jika objek utama jelas bukan manusia/area kulit, misalnya makanan, dokumen, benda, "
            "layar, pemandangan, atau file rusak. Jangan mensyaratkan penyakit terlihat jelas untuk menyatakan kulit valid. "
            "Isi skin_evidence_score 0.0 sampai 1.0 berdasarkan keyakinan bahwa gambar berisi kulit/tubuh manusia. "
            "Tugas kedua adalah kandidat awal: jika filter kulit valid, pilih maksimal 3 kandidat dari daftar class berikut bila ada yang mirip: "
            f"{class_list}. "
            "Balas hanya JSON valid dengan struktur: "
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
            "Anda hanya bertugas memfilter apakah gambar berisi area kulit/tubuh manusia. "
            "Jangan menilai penyakit dan jangan butuh lesi yang jelas. "
            "Jawab true jika terlihat kulit manusia atau bagian tubuh manusia seperti tangan, kaki, wajah, leher, lengan, paha, "
            "punggung, perut, bokong, kulit kepala, kuku, atau lipatan tubuh, termasuk bila ada bercak putih/cokelat, ruam, luka, "
            "blur ringan, crop close-up, atau pencahayaan kurang ideal. "
            "Jawab false hanya jika objek utama jelas bukan manusia/area kulit, misalnya makanan, dokumen, benda, layar, pemandangan, "
            "atau file tidak dapat dinilai. "
            "Balas hanya JSON valid: "
            '{"is_valid_skin_image": true, "skin_evidence_score": 0.0, "warnings": []}.'
        )

    def skin_filter_response_from_text(self, text: str) -> dict[str, Any]:
        parsed = self.parse_json_text(text)
        skin_evidence_score = self.skin_evidence_score(parsed.get("skin_evidence_score"))
        is_valid_skin_image = bool(parsed.get("is_valid_skin_image", False)) or skin_evidence_score >= 0.35

        return {
            "is_valid_skin_image": is_valid_skin_image,
            "warnings": parsed.get("warnings", []),
            "raw_response": {
                "text": text,
                "model": settings.gemini_model_name,
                "skin_evidence_score": skin_evidence_score,
            },
        }

    def parse_json_text(self, text: str) -> dict[str, Any]:
        cleaned = text.strip()
        fenced = re.search(r"```(?:json)?\s*(.*?)\s*```", cleaned, re.DOTALL | re.IGNORECASE)

        if fenced:
            cleaned = fenced.group(1)

        try:
            data = json.loads(cleaned)
        except json.JSONDecodeError:
            return {
                "is_valid_skin_image": False,
                "candidates": [],
                "warnings": ["Respons Gemini tidak berbentuk JSON valid."],
            }

        if not isinstance(data, dict):
            return {
                "is_valid_skin_image": False,
                "candidates": [],
                "warnings": ["Respons Gemini tidak sesuai struktur yang diminta."],
            }

        return data


def normalize_candidates(raw_candidates: list[dict[str, Any]]) -> list[VisualCandidate]:
    normalized: list[VisualCandidate] = []

    for raw in raw_candidates:
        class_name = str(raw.get("dataset_class_name", "")).strip()
        mapping = resolve_mapping(class_name)

        if not mapping:
            continue

        score = max(0.0, min(1.0, float(raw.get("visual_score", 0))))
        normalized.append(
            VisualCandidate(
                dataset_class_name=mapping.dataset_class_name,
                local_disease_code=mapping.local_disease_code,
                visual_score=round(score, 4),
                reason=str(raw.get("reason") or "Kandidat visual dari Gemini."),
            )
        )

    return sorted(normalized, key=lambda item: item.visual_score, reverse=True)[:3]
