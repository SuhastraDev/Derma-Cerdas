from __future__ import annotations

import time
import re
from typing import Any

import httpx

from app.config import settings
from app.services.nvidia_service import NvidiaVisualClient

GROQ_CHAT_COMPLETIONS_URL = "https://api.groq.com/openai/v1/chat/completions"


class GroqVisualClient(NvidiaVisualClient):
    provider = "groq"
    max_image_dimension = 512
    visual_candidate_limit = 12
    total_candidate_cap = 28

    def api_keys(self) -> list[str]:
        """Seluruh kunci yang tersedia, berurutan.

        GROQ_API_KEYS (dipisah koma) diutamakan; GROQ_API_KEY tunggal tetap
        didukung agar konfigurasi lama tidak rusak. Penjaga mode mock WAJIB
        memakai daftar ini - memeriksa groq_api_key saja membuat sistem diam-diam
        masuk mode mock ketika pengguna hanya mengisi GROQ_API_KEYS.
        """
        kunci = [k for k in settings.groq_api_keys if k]

        if kunci:
            return kunci

        return [settings.groq_api_key] if settings.groq_api_key else []

    def analyze(
        self,
        image_base64: str,
        candidate_classes: list[str],
        dataset_matches: list[dict[str, Any]] | None = None,
        complaint_text: str = "",
        symptom_questions: list[dict[str, str]] | None = None,
    ) -> dict[str, Any]:
        if settings.ai_mock_mode or not self.api_keys():
            return self.mock_response(candidate_classes)

        return self.provider_response(
            image_base64,
            candidate_classes,
            dataset_matches or [],
            complaint_text=complaint_text,
            symptom_questions=symptom_questions or [],
        )

    def assess_red_flags(self, complaint_text: str, red_flags: list[dict[str, str]]) -> dict[str, Any]:
        if not red_flags:
            return {"provider_status": "ok", "detected_codes": [], "warnings": [], "raw_response": {}}

        if settings.ai_mock_mode or not self.api_keys():
            return {
                "provider_status": "mock_mode",
                "detected_codes": [],
                "warnings": ["AI_MOCK_MODE aktif; deteksi tanda bahaya otomatis tidak dijalankan."],
                "raw_response": {"mode": "mock"},
            }

        try:
            body = self.text_chat_completion(
                self.red_flag_prompt(complaint_text, red_flags),
                response_format={"type": "json_object"},
            )
        except Exception as exc:
            return self.provider_error_response(exc, "Groq red flag assessment")

        text = self.completion_text(body)
        parsed = self.parse_json_text(text)
        raw_codes = parsed.get("detected_codes", [])
        if not isinstance(raw_codes, list):
            raw_codes = []

        valid_codes = {rf["code"] for rf in red_flags}
        detected_codes = [
            code for code in raw_codes if isinstance(code, str) and code in valid_codes
        ]

        warnings = parsed.get("warnings", [])
        if not isinstance(warnings, list):
            warnings = []

        return {
            "provider_status": "ok",
            "detected_codes": detected_codes,
            "warnings": [str(warning) for warning in warnings],
            "raw_response": {"text": text, "model": settings.groq_model_name},
        }

    def provider_response(
        self,
        image_base64: str,
        classes: list[str],
        dataset_matches: list[dict[str, Any]],
        complaint_text: str = "",
        symptom_questions: list[dict[str, str]] | None = None,
    ) -> dict[str, Any]:
        symptom_codes = [
            str(question.get("code"))
            for question in (symptom_questions or [])
            if question.get("code")
        ]

        try:
            body = self.chat_completion(
                self.prompt(
                    classes,
                    dataset_matches,
                    complaint_text=complaint_text,
                    symptom_questions=symptom_questions or [],
                ),
                self.image_data_url(image_base64),
                response_format={"type": "json_object"},
            )
        except Exception as exc:
            return self.provider_error_response(exc, "Groq vision API")

        return self.response_from_text(
            self.completion_text(body),
            allowed_symptom_codes=symptom_codes,
        )

    def validate_skin_image(self, image_base64: str) -> dict[str, Any]:
        if settings.ai_mock_mode or not self.api_keys():
            return {
                "provider_status": "mock_mode",
                "is_valid_skin_image": False,
                "warnings": ["AI_MOCK_MODE aktif; filter kulit tidak dijalankan."],
                "raw_response": {"mode": "mock"},
            }

        try:
            body = self.chat_completion(
                self.skin_filter_prompt(),
                self.image_data_url(image_base64),
                response_format={"type": "json_object"},
            )
        except Exception as exc:
            return self.provider_error_response(exc, "Groq skin filter")

        return self.skin_filter_response_from_text(self.completion_text(body))

    def chat_completion(
        self,
        prompt: str,
        image_data_url: str,
        response_format: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        request_body: dict[str, Any] = {
            "model": settings.groq_model_name,
            "temperature": 0.1,
            "max_completion_tokens": 450,
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
        }

        if response_format is not None:
            request_body["response_format"] = response_format

        return self.post_with_retry(request_body, timeout=45.0)

    def text_chat_completion(
        self,
        prompt: str,
        response_format: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        request_body: dict[str, Any] = {
            "model": settings.groq_model_name,
            "temperature": 0.1,
            "max_completion_tokens": 160,
            "reasoning_effort": "none",
            "messages": [{"role": "user", "content": prompt}],
        }

        if response_format is not None:
            request_body["response_format"] = response_format

        return self.post_with_retry(request_body, timeout=25.0)

    def post_with_retry(self, json_body: dict[str, Any], timeout: float) -> dict[str, Any]:
        """Coba tiap kunci berurutan; kuota habis pada satu kunci bukan alasan menyerah.

        Menunggu reset kuota bisa memakan puluhan menit, sedangkan berpindah ke
        kunci berikutnya nyaris tanpa biaya. Karena itu 429 langsung memicu
        perpindahan kunci, dan jeda mundur hanya dipakai saat kunci terakhir pun
        menolak - atau saat penyebabnya memang gangguan sementara (5xx).
        """
        kunci_tersedia = self.api_keys()

        if not kunci_tersedia:
            raise RuntimeError("Tidak ada GROQ_API_KEY/GROQ_API_KEYS yang terisi.")

        attempts = 3
        fallback_backoffs = [0.0, 8.0, 16.0]
        response: httpx.Response | None = None

        for indeks, kunci in enumerate(kunci_tersedia):
            kunci_terakhir = indeks == len(kunci_tersedia) - 1

            for attempt in range(1, attempts + 1):
                response = httpx.post(
                    GROQ_CHAT_COMPLETIONS_URL,
                    headers={
                        "Authorization": f"Bearer {kunci}",
                        "Content-Type": "application/json",
                    },
                    json=json_body,
                    timeout=timeout,
                )

                if response.status_code == 429:
                    if not kunci_terakhir:
                        break  # kunci ini habis - langsung pakai kunci berikutnya

                    if attempt < attempts:
                        time.sleep(self.retry_after_seconds(response, fallback_backoffs[attempt]))
                        continue

                elif response.status_code >= 500 and attempt < attempts:
                    time.sleep(self.retry_after_seconds(response, fallback_backoffs[attempt]))
                    continue

                response.raise_for_status()

                return response.json()

        assert response is not None
        response.raise_for_status()

        return response.json()

    def retry_after_seconds(self, response: httpx.Response, fallback_seconds: float) -> float:
        retry_after = response.headers.get("retry-after")

        if retry_after:
            try:
                return min(35.0, max(1.0, float(retry_after)))
            except ValueError:
                pass

        match = re.search(r"try again in ([0-9.]+)s", response.text, re.IGNORECASE)

        if match:
            return min(35.0, max(1.0, float(match.group(1)) + 1.0))

        return fallback_seconds

    def provider_error_response(self, exception: Exception, operation: str) -> dict[str, Any]:
        response = super().provider_error_response(exception, operation)
        response["raw_response"]["model"] = settings.groq_model_name
        response["warnings"] = [
            warning.replace("NVIDIA NIM API", "Groq API").replace("NVIDIA NIM", "Groq")
            for warning in response["warnings"]
        ]

        return response
