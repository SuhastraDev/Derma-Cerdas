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

        response = client.models.generate_content(
            model=settings.gemini_model_name,
            contents=[prompt, image],
        )

        text = getattr(response, "text", "") or ""
        parsed = self.parse_json_text(text)

        return {
            "is_valid_skin_image": bool(parsed.get("is_valid_skin_image", True)),
            "candidates": parsed.get("candidates", []),
            "warnings": parsed.get("warnings", []),
            "raw_response": {
                "text": text,
                "model": settings.gemini_model_name,
            },
        }

    def prompt(self, classes: list[str]) -> str:
        class_list = ", ".join(classes)

        return (
            "Anda adalah komponen visual screening DermaCerdas, bukan dokter. "
            "Analisis gambar kulit hanya untuk kandidat awal. "
            "Pilih maksimal 3 kandidat dari daftar class berikut: "
            f"{class_list}. "
            "Balas hanya JSON valid dengan struktur: "
            '{"is_valid_skin_image": true, "candidates": ['
            '{"dataset_class_name": "Tinea_Corporis", "visual_score": 0.74, '
            '"reason": "alasan visual singkat"}], "warnings": []}. '
            "Jika gambar bukan kulit atau kualitas buruk, set is_valid_skin_image false."
        )

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
