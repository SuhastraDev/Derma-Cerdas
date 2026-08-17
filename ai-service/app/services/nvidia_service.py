from __future__ import annotations

import ast
import base64
import json
import re
import time
from io import BytesIO
from typing import Any

import httpx
from PIL import Image, ImageOps

from app.config import settings
from app.schemas import VisualCandidate
from app.services.class_mapping import allowed_candidate_classes, normalize_class_name, resolve_mapping
from app.services.image_validation import ImageValidator

NVIDIA_CHAT_COMPLETIONS_URL = "https://integrate.api.nvidia.com/v1/chat/completions"

# Vision token cost scales with image size. A skin lesion photo does not need
# more than this to stay legible, and keeping requests smaller helps stay
# comfortably inside NVIDIA NIM's rate limit.
NVIDIA_MAX_IMAGE_DIMENSION = 768


class NvidiaVisualClient:
    provider = "nvidia"

    def analyze(
        self,
        image_base64: str,
        candidate_classes: list[str],
        dataset_matches: list[dict[str, Any]] | None = None,
        complaint_text: str = "",
        symptom_questions: list[dict[str, str]] | None = None,
    ) -> dict[str, Any]:
        classes = allowed_candidate_classes(candidate_classes)

        if settings.ai_mock_mode or not settings.nvidia_api_key:
            return self.mock_response(classes)

        return self.nvidia_response(
            image_base64,
            classes,
            dataset_matches or [],
            complaint_text=complaint_text,
            symptom_questions=symptom_questions or [],
        )

    def assess_red_flags(self, complaint_text: str, red_flags: list[dict[str, str]]) -> dict[str, Any]:
        """Text-only pass over the free-text complaint to flag danger signs the
        user already described, so the wizard doesn't re-ask what was already
        told. Never used to clear/skip a question - only to pre-fill positives,
        so a missed detection here still leaves the question for the user."""
        if not red_flags:
            return {"provider_status": "ok", "detected_codes": [], "warnings": [], "raw_response": {}}

        if settings.ai_mock_mode or not settings.nvidia_api_key:
            return {
                "provider_status": "mock_mode",
                "detected_codes": [],
                "warnings": ["AI_MOCK_MODE aktif; deteksi tanda bahaya otomatis tidak dijalankan."],
                "raw_response": {"mode": "mock"},
            }

        try:
            body = self.text_chat_completion(
                self.red_flag_prompt(complaint_text, red_flags),
                response_format=self.red_flag_response_format(red_flags),
            )
        except Exception as exc:
            return self.provider_error_response(exc, "NVIDIA NIM red flag assessment")

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
            "raw_response": {"text": text, "model": settings.nvidia_model_name},
        }

    def red_flag_prompt(self, complaint_text: str, red_flags: list[dict[str, str]]) -> str:
        items = "\n".join(f"- {rf['code']}: {rf['question']}" for rf in red_flags)

        return (
            "Anda membantu triase awal skrining kulit DermaCerdas, bukan menegakkan diagnosis. "
            "Berikut cerita keluhan yang ditulis pengguna sendiri:\n"
            f"\"{complaint_text}\"\n\n"
            "Berikut daftar pertanyaan tanda bahaya (kode: pertanyaan):\n"
            f"{items}\n\n"
            "Tugas: tentukan kode tanda bahaya mana saja yang SECARA JELAS DAN EKSPLISIT "
            "dikonfirmasi ADA dalam cerita tersebut, bukan sekadar mungkin atau tidak disebutkan. "
            "Kehati-hatian penting: lebih baik TIDAK menyertakan kode yang meragukan daripada salah "
            "menyertakan, karena pengguna tetap akan ditanya ulang untuk kode yang tidak kamu sertakan. "
            "Balas HANYA dengan JSON valid (tanpa markdown, tanpa penjelasan lain) dengan struktur persis: "
            '{"detected_codes": ["KODE1"], "warnings": []}. '
            "Jika tidak ada tanda bahaya yang jelas disebut, balas dengan detected_codes kosong."
        )

    def mock_response(self, classes: list[str]) -> dict[str, Any]:
        return {
            "provider_status": "mock_mode",
            "is_valid_skin_image": False,
            "candidates": [],
            "suggested_symptom_codes": [],
            "warnings": [
                "AI_MOCK_MODE aktif; validasi foto kulit tidak dijalankan agar sistem tidak memberi hasil visual palsu."
            ],
            "raw_response": {"mode": "mock"},
        }

    def nvidia_response(
        self,
        image_base64: str,
        classes: list[str],
        dataset_matches: list[dict[str, Any]],
        complaint_text: str = "",
        symptom_questions: list[dict[str, str]] | None = None,
    ) -> dict[str, Any]:
        data_url = self.image_data_url(image_base64)
        prompt = self.prompt(
            classes,
            dataset_matches,
            complaint_text=complaint_text,
            symptom_questions=symptom_questions or [],
        )

        symptom_codes = [
            str(question.get("code"))
            for question in (symptom_questions or [])
            if question.get("code")
        ]

        try:
            body = self.chat_completion(
                prompt,
                data_url,
                response_format=self.visual_response_format(classes, symptom_codes),
            )
        except Exception as exc:
            return self.provider_error_response(exc, "NVIDIA NIM API")

        text = self.completion_text(body)

        return self.response_from_text(
            text,
            allowed_symptom_codes=symptom_codes,
        )

    def validate_skin_image(self, image_base64: str) -> dict[str, Any]:
        if settings.ai_mock_mode or not settings.nvidia_api_key:
            return {
                "provider_status": "mock_mode",
                "is_valid_skin_image": False,
                "warnings": ["AI_MOCK_MODE aktif; filter kulit tidak dijalankan."],
                "raw_response": {"mode": "mock"},
            }

        data_url = self.image_data_url(image_base64)

        try:
            body = self.chat_completion(
                self.skin_filter_prompt(),
                data_url,
                response_format=self.skin_response_format(),
            )
        except Exception as exc:
            return self.provider_error_response(exc, "NVIDIA NIM skin filter")

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

                if max(image.size) > NVIDIA_MAX_IMAGE_DIMENSION:
                    image.thumbnail((NVIDIA_MAX_IMAGE_DIMENSION, NVIDIA_MAX_IMAGE_DIMENSION), Image.Resampling.LANCZOS)

                buffer = BytesIO()
                image.convert("RGB").save(buffer, format="JPEG", quality=85)

                return buffer.getvalue()
        except Exception:
            return raw

    def chat_completion(
        self,
        prompt: str,
        image_data_url: str,
        response_format: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        request_body: dict[str, Any] = {
            "model": settings.nvidia_model_name,
            "temperature": 0.1,
            "max_tokens": 700,
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

        try:
            return self.post_with_retry(request_body, timeout=45.0)
        except httpx.HTTPStatusError as exception:
            if response_format is None or not self.is_structured_output_rejection(exception):
                raise

            request_body.pop("response_format", None)

            return self.post_with_retry(request_body, timeout=45.0)

    def text_chat_completion(
        self,
        prompt: str,
        response_format: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        request_body: dict[str, Any] = {
            "model": settings.nvidia_model_name,
            "temperature": 0.1,
            "max_tokens": 256,
            "messages": [{"role": "user", "content": prompt}],
        }

        if response_format is not None:
            request_body["response_format"] = response_format

        try:
            return self.post_with_retry(request_body, timeout=25.0)
        except httpx.HTTPStatusError as exception:
            if response_format is None or not self.is_structured_output_rejection(exception):
                raise

            request_body.pop("response_format", None)

            return self.post_with_retry(request_body, timeout=25.0)

    def is_structured_output_rejection(self, exception: httpx.HTTPStatusError) -> bool:
        status_code = getattr(getattr(exception, "response", None), "status_code", None)
        detail = self.describe_http_error(exception).lower()

        return status_code in {400, 422} and any(
            marker in detail
            for marker in ("response_format", "json_schema", "structured output", "structured_output")
        )

    def visual_response_format(
        self,
        classes: list[str],
        symptom_codes: list[str],
    ) -> dict[str, Any]:
        candidate_class_schema = self.enum_schema(classes)
        symptom_code_schema = self.enum_schema(symptom_codes)

        return {
            "type": "json_schema",
            "json_schema": {
                "name": "dermacerdas_visual_analysis",
                "schema": {
                    "type": "object",
                    "properties": {
                        "is_valid_skin_image": {"type": "boolean"},
                        "skin_evidence_score": {"type": "number", "minimum": 0, "maximum": 1},
                        "candidates": {
                            "type": "array",
                            "maxItems": 3,
                            "items": {
                                "type": "object",
                                "properties": {
                                    "dataset_class_name": candidate_class_schema,
                                    "visual_score": {"type": "number", "minimum": 0, "maximum": 1},
                                    "reason": {"type": "string"},
                                },
                                "required": ["dataset_class_name", "visual_score", "reason"],
                                "additionalProperties": False,
                            },
                        },
                        "suggested_symptom_codes": {
                            "type": "array",
                            "maxItems": 8,
                            "items": symptom_code_schema,
                        },
                        "warnings": {
                            "type": "array",
                            "items": {"type": "string"},
                        },
                    },
                    "required": [
                        "is_valid_skin_image",
                        "skin_evidence_score",
                        "candidates",
                        "suggested_symptom_codes",
                        "warnings",
                    ],
                    "additionalProperties": False,
                },
            },
        }

    def skin_response_format(self) -> dict[str, Any]:
        return {
            "type": "json_schema",
            "json_schema": {
                "name": "dermacerdas_skin_validation",
                "schema": {
                    "type": "object",
                    "properties": {
                        "contains_human_body_part": {"type": "boolean"},
                        "contains_visible_skin": {"type": "boolean"},
                        "is_valid_skin_image": {"type": "boolean"},
                        "skin_evidence_score": {"type": "number", "minimum": 0, "maximum": 1},
                        "reason": {"type": "string"},
                        "warnings": {
                            "type": "array",
                            "items": {"type": "string"},
                        },
                    },
                    "required": [
                        "contains_human_body_part",
                        "contains_visible_skin",
                        "is_valid_skin_image",
                        "skin_evidence_score",
                        "reason",
                        "warnings",
                    ],
                    "additionalProperties": False,
                },
            },
        }

    def red_flag_response_format(self, red_flags: list[dict[str, str]]) -> dict[str, Any]:
        codes = [str(red_flag.get("code")) for red_flag in red_flags if red_flag.get("code")]

        return {
            "type": "json_schema",
            "json_schema": {
                "name": "dermacerdas_red_flag_assessment",
                "schema": {
                    "type": "object",
                    "properties": {
                        "detected_codes": {
                            "type": "array",
                            "items": self.enum_schema(codes),
                        },
                        "warnings": {
                            "type": "array",
                            "items": {"type": "string"},
                        },
                    },
                    "required": ["detected_codes", "warnings"],
                    "additionalProperties": False,
                },
            },
        }

    def enum_schema(self, values: list[str]) -> dict[str, Any]:
        unique_values = list(dict.fromkeys(value for value in values if value))

        if not unique_values:
            return {"type": "string"}

        return {"type": "string", "enum": unique_values}

    def post_with_retry(self, json_body: dict[str, Any], timeout: float) -> dict[str, Any]:
        """Retry once on NVIDIA-side transient errors (rate limit / 5xx).
        Client errors (4xx other than 429) are not retried - retrying a
        malformed request just wastes token budget for the same failure. Kept
        to 2 attempts with a short per-attempt timeout so the combined wait
        stays comfortably under Laravel's DERMACERDAS_AI_TIMEOUT, even if an
        attempt genuinely hangs rather than fast-failing."""
        attempts = 2
        backoff_seconds = 2.0

        for attempt in range(1, attempts + 1):
            response = httpx.post(
                NVIDIA_CHAT_COMPLETIONS_URL,
                headers={
                    "Authorization": f"Bearer {settings.nvidia_api_key}",
                    "Content-Type": "application/json",
                },
                json=json_body,
                timeout=timeout,
            )

            if response.status_code == 429 or response.status_code >= 500:
                if attempt < attempts:
                    time.sleep(backoff_seconds * attempt)
                    continue

            response.raise_for_status()

            return response.json()

        response.raise_for_status()

        return response.json()

    def completion_text(self, body: dict[str, Any]) -> str:
        choices = body.get("choices", [])

        if not choices:
            return ""

        content = choices[0].get("message", {}).get("content", "") or ""

        if isinstance(content, str):
            return content

        return json.dumps(content, ensure_ascii=False)

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
            "Kuota/limit NVIDIA NIM API telah habis. Tunggu kuota tersedia kembali atau gunakan API key dengan limit aktif."
            if quota_exceeded
            else f"{operation} sedang tidak tersedia. Coba kembali beberapa saat lagi."
        )

        return {
            "provider_status": provider_status,
            "is_valid_skin_image": False,
            "candidates": [],
            "suggested_symptom_codes": [],
            "warnings": [warning],
            "raw_response": {
                "error": error,
                "error_code": provider_status,
                "model": settings.nvidia_model_name,
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

    def response_from_text(
        self,
        text: str,
        allowed_symptom_codes: list[str] | None = None,
    ) -> dict[str, Any]:
        parsed = self.parse_json_text(text)

        raw_candidates = parsed.get("candidates", [])
        if not isinstance(raw_candidates, list):
            raw_candidates = []

        has_candidates = isinstance(raw_candidates, list) and len(raw_candidates) > 0
        skin_evidence_score = self.skin_evidence_score(parsed.get("skin_evidence_score"))
        is_valid_skin_image = bool(parsed.get("is_valid_skin_image", True)) or has_candidates or skin_evidence_score >= 0.35
        suggested_symptom_codes = parsed.get("suggested_symptom_codes", [])

        if not isinstance(suggested_symptom_codes, list):
            suggested_symptom_codes = []

        if allowed_symptom_codes:
            suggested_symptom_codes = [
                code
                for code in suggested_symptom_codes
                if isinstance(code, str) and code in allowed_symptom_codes
            ]

        suggested_symptom_codes = list(dict.fromkeys(suggested_symptom_codes))[:8]
        warnings = parsed.get("warnings", [])
        if not isinstance(warnings, list):
            warnings = []

        return {
            "provider_status": "ok",
            "is_valid_skin_image": is_valid_skin_image,
            "candidates": raw_candidates,
            "suggested_symptom_codes": suggested_symptom_codes,
            "warnings": [str(warning) for warning in warnings],
            "raw_response": {
                "text": text,
                "model": settings.nvidia_model_name,
                "skin_evidence_score": skin_evidence_score,
            },
        }

    def prompt(
        self,
        classes: list[str],
        dataset_matches: list[dict[str, Any]] | None = None,
        complaint_text: str = "",
        symptom_questions: list[dict[str, str]] | None = None,
    ) -> str:
        class_list = json.dumps(classes, ensure_ascii=False)
        symptom_list = json.dumps(symptom_questions or [], ensure_ascii=False)
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
            "Konteks keluhan pengguna berikut hanya digunakan untuk memilih pertanyaan yang paling relevan dan menilai kandidat awal, bukan untuk mengonfirmasi diagnosis: "
            f"{json.dumps(complaint_text, ensure_ascii=False)}. "
            "Daftar pertanyaan gejala lokal berikut adalah satu-satunya kode yang boleh disarankan: "
            f"{symptom_list}. Pilih maksimal 8 kode pertanyaan yang paling informatif dari daftar tersebut. "
            "Balas HANYA dengan JSON valid (tanpa markdown, tanpa penjelasan lain) dengan struktur persis: "
            '{"is_valid_skin_image": true, "skin_evidence_score": 0.86, "candidates": ['
            '{"dataset_class_name": "Tinea_Corporis", "visual_score": 0.74, '
            '"reason": "alasan visual singkat"}], "suggested_symptom_codes": ["G06", "G08"], "warnings": []}. '
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
            warning.startswith("Respons NVIDIA")
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
            warnings = ["Respons NVIDIA NIM skin filter tidak JSON valid, tetapi sinyal teks tetap terbaca."]

        return {
            "provider_status": "ok",
            "is_valid_skin_image": is_valid_skin_image,
            "warnings": warnings,
            "raw_response": {
                "text": text,
                "model": settings.nvidia_model_name,
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
        # Reasoning models may prefix the answer with a <think>...</think>
        # block before the actual JSON payload.
        if not isinstance(text, str):
            return {
                "is_valid_skin_image": False,
                "candidates": [],
                "warnings": ["Respons NVIDIA NIM tidak berbentuk teks JSON."],
            }

        cleaned = re.sub(r"<think>.*?</think>", "", text, flags=re.DOTALL | re.IGNORECASE).strip()

        fenced = re.search(r"```(?:json)?\s*(.*?)\s*```", cleaned, re.DOTALL | re.IGNORECASE)
        sources = [cleaned]

        if fenced:
            sources.insert(0, fenced.group(1))

        parsed_documents: list[dict[str, Any]] = []

        for source in sources:
            direct_data = self.try_parse_json(source) or self.try_parse_python_literal(source)

            if isinstance(direct_data, dict):
                parsed_documents.append(direct_data)

            for object_text in self.extract_json_objects(source):
                data = self.try_parse_json(object_text) or self.try_parse_python_literal(object_text)

                if isinstance(data, dict):
                    parsed_documents.append(data)

        if not parsed_documents:
            return {
                "is_valid_skin_image": False,
                "candidates": [],
                "warnings": ["Respons NVIDIA NIM tidak berbentuk JSON valid."],
            }

        def document_score(document: dict[str, Any]) -> int:
            recognized_keys = {
                "is_valid_skin_image",
                "skin_evidence_score",
                "candidates",
                "suggested_symptom_codes",
                "detected_codes",
                "contains_human_body_part",
                "contains_visible_skin",
            }

            return sum(1 for key in recognized_keys if key in document)

        return max(
            enumerate(parsed_documents),
            key=lambda item: (document_score(item[1]), item[0]),
        )[1]

    def try_parse_json(self, text: str | None) -> Any:
        if not text:
            return None

        try:
            return json.loads(text)
        except json.JSONDecodeError:
            return None

    def try_parse_python_literal(self, text: str | None) -> Any:
        if not text:
            return None

        try:
            return ast.literal_eval(text)
        except (SyntaxError, ValueError, TypeError):
            return None

    def extract_json_objects(self, text: str) -> list[str]:
        objects: list[str] = []
        start: int | None = None
        depth = 0
        quote: str | None = None
        escape = False

        for index, char in enumerate(text):
            if quote is not None:
                if escape:
                    escape = False
                elif char == "\\":
                    escape = True
                elif char == quote:
                    quote = None
                continue

            if char in {"\"", "'"}:
                quote = char
            elif char == "{" and depth == 0:
                start = index
                depth = 1
            elif char == "{" and depth > 0:
                depth += 1
            elif char == "}" and depth > 0:
                depth -= 1

                if depth == 0 and start is not None:
                    objects.append(text[start:index + 1])
                    start = None

        return objects

    def extract_first_json_object(self, text: str) -> str | None:
        """Return the first balanced object, tolerating prose around it."""
        return next(iter(self.extract_json_objects(text)), None)


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
                reason=str(raw.get("reason") or "Kandidat visual dari NVIDIA NIM."),
            )
        )

    return sorted(normalized, key=lambda item: item.visual_score, reverse=True)[:3]
