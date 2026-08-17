from __future__ import annotations

import base64
from io import BytesIO

from fastapi.testclient import TestClient
from PIL import Image

from app.main import app
from app.schemas import AnalyzeImageRequest, ImageValidationResponse
from app.services.analysis_service import VisualAnalysisService
from app.services.class_mapping import allowed_candidate_classes
from app.services.dataset_visual_index import DatasetVisualIndex
from app.services.groq_service import GroqVisualClient
from app.services.nvidia_service import NvidiaVisualClient, normalize_candidates
from app.services.provider_factory import visual_client


client = TestClient(app)


def sample_image_base64(width: int = 256, height: int = 256) -> str:
    image = Image.new("RGB", (width, height), color=(210, 120, 105))
    buffer = BytesIO()
    image.save(buffer, format="PNG")

    return base64.b64encode(buffer.getvalue()).decode("ascii")


def test_health_endpoint() -> None:
    response = client.get("/health")

    assert response.status_code == 200
    assert response.json()["status"] == "ok"
    assert isinstance(response.json()["dataset_index_ready"], bool)
    assert response.json()["provider"] in {"nvidia", "groq"}


def test_provider_factory_selects_groq(monkeypatch) -> None:
    from app.config import settings

    original_provider = settings.ai_provider
    object.__setattr__(settings, "ai_provider", "groq")

    try:
        assert visual_client().provider == "groq"
    finally:
        object.__setattr__(settings, "ai_provider", original_provider)


def test_validate_image_accepts_png_base64() -> None:
    response = client.post("/validate-image", json={"image_base64": sample_image_base64()})

    assert response.status_code == 200
    payload = response.json()
    assert payload["is_valid"] is True
    assert payload["mime_type"] == "image/png"
    assert payload["width"] == 256
    assert payload["height"] == 256


def test_validate_image_rejects_invalid_base64() -> None:
    response = client.post("/validate-image", json={"image_base64": "not-a-valid-image-payload"})

    assert response.status_code == 200
    payload = response.json()
    assert payload["is_valid"] is False
    assert payload["warnings"]


def test_analyze_image_mock_mode_does_not_claim_valid_skin_image() -> None:
    from app.config import settings

    # Settings is a frozen dataclass; bypass __setattr__ directly since this
    # test must force mock mode regardless of whatever real API key is
    # configured in the local/CI .env.
    original_mock_mode = settings.ai_mock_mode
    object.__setattr__(settings, "ai_mock_mode", True)

    try:
        response = client.post(
            "/analyze-image",
            json={
                "consultation_id": "DC-TEST-001",
                "image_base64": sample_image_base64(),
                "candidate_classes": ["Tinea_Corporis", "Eczema"],
            },
        )
    finally:
        object.__setattr__(settings, "ai_mock_mode", original_mock_mode)

    assert response.status_code == 200
    payload = response.json()
    assert payload["provider"] == "nvidia"
    assert payload["is_valid_skin_image"] is False
    assert payload["candidates"] == []
    assert payload["warnings"]


def test_allowed_candidate_classes_preserves_production_classes_outside_mvp() -> None:
    classes = allowed_candidate_classes(["Vitiligo", "Basal_Cell_Carcinoma", "Eczema"])

    assert classes == ["Vitiligo", "Basal_Cell_Carcinoma", "Eczema"]


def test_normalize_candidates_preserves_allowed_production_class_for_laravel_mapping() -> None:
    candidates = normalize_candidates(
        [
            {"dataset_class_name": "Basal_Cell_Carcinoma", "visual_score": 0.99, "reason": "suspicious lesion"},
            {"dataset_class_name": "Urticaria", "visual_score": 0.70, "reason": "wheals"},
        ],
        allowed_classes=["Basal_Cell_Carcinoma", "Urticaria"],
    )

    assert len(candidates) == 2
    assert candidates[0].dataset_class_name == "Basal_Cell_Carcinoma"
    assert candidates[0].local_disease_code is None
    assert candidates[1].local_disease_code == "URTICARIA"


def test_nvidia_json_parser_handles_fenced_json() -> None:
    parsed = NvidiaVisualClient().parse_json_text(
        '```json\n{"is_valid_skin_image": true, "candidates": [], "warnings": []}\n```'
    )

    assert parsed["is_valid_skin_image"] is True


def test_nvidia_response_filters_suggested_symptoms_to_available_question_codes() -> None:
    payload = NvidiaVisualClient().response_from_text(
        '{"is_valid_skin_image": true, "suggested_symptom_codes": ["G11", "UNKNOWN"], '
        '"candidates": [], "warnings": []}',
        allowed_symptom_codes=["G11", "G12"],
    )

    assert payload["suggested_symptom_codes"] == ["G11"]


def test_nvidia_response_recovers_python_style_json_after_model_reasoning() -> None:
    payload = NvidiaVisualClient().response_from_text(
        "Saya akan menilai gambar terlebih dahulu.\n"
        "{'is_valid_skin_image': True, 'skin_evidence_score': 0.82, "
        "'candidates': [{'dataset_class_name': 'Psoriasis', 'visual_score': 0.78, "
        "'reason': 'Bercak bersisik pada area kulit'}], "
        "'suggested_symptom_codes': ['G03', 'G11'], 'warnings': [],}",
        allowed_symptom_codes=["G03", "G11"],
    )

    assert payload["is_valid_skin_image"] is True
    assert payload["candidates"][0]["dataset_class_name"] == "Psoriasis"
    assert payload["suggested_symptom_codes"] == ["G03", "G11"]


def test_nvidia_completion_text_reads_reasoning_content_when_content_is_empty() -> None:
    text = NvidiaVisualClient().completion_text(
        {
            "choices": [
                {
                    "message": {
                        "content": None,
                        "reasoning_content": '{"is_valid_skin_image": true, "candidates": [], "warnings": []}',
                    }
                }
            ]
        }
    )

    assert NvidiaVisualClient().parse_json_text(text)["is_valid_skin_image"] is True


def test_nvidia_completion_text_flattens_content_blocks() -> None:
    text = NvidiaVisualClient().completion_text(
        {
            "choices": [
                {
                    "message": {
                        "content": [
                            {"type": "text", "text": '{"is_valid_skin_image": true'},
                            {"type": "text", "text": ', "candidates": [], "warnings": []}'},
                        ]
                    }
                }
            ]
        }
    )

    assert NvidiaVisualClient().parse_json_text(text)["is_valid_skin_image"] is True


def test_visual_response_schema_restricts_local_classes_and_symptoms() -> None:
    response_format = NvidiaVisualClient().visual_response_format(
        ["Psoriasis", "Dry_Skin_Eczema"],
        ["G03", "G11"],
    )
    schema = response_format["json_schema"]["schema"]

    assert response_format["type"] == "json_schema"
    assert schema["properties"]["candidates"]["items"]["properties"]["dataset_class_name"]["enum"] == [
        "Psoriasis",
        "Dry_Skin_Eczema",
    ]
    assert schema["properties"]["suggested_symptom_codes"]["items"]["enum"] == ["G03", "G11"]


def test_visual_prompt_contains_complaint_and_question_bank() -> None:
    prompt = NvidiaVisualClient().prompt(
        ["Psoriasis", "Dry_Skin_Eczema"],
        dataset_matches=[],
        complaint_text="Saya menduga psoriasis dengan bercak merah bersisik.",
        symptom_questions=[
            {"code": "G11", "name": "Bercak bersisik", "question": "Apakah bercak bersisik?"},
        ],
    )

    assert "Saya menduga psoriasis" in prompt
    assert "G11" in prompt
    assert "suggested_symptom_codes" in prompt


def test_nvidia_response_treats_candidate_output_as_valid_skin_image() -> None:
    payload = NvidiaVisualClient().response_from_text(
        '{"is_valid_skin_image": false, "candidates": ['
        '{"dataset_class_name": "Eczema", "visual_score": 0.42, "reason": "Area kulit tampak kemerahan"}'
        '], "warnings": ["Foto agak blur"]}'
    )

    assert payload["is_valid_skin_image"] is True
    assert payload["candidates"]


def test_nvidia_response_treats_skin_evidence_as_valid_without_candidates() -> None:
    payload = NvidiaVisualClient().response_from_text(
        '{"is_valid_skin_image": false, "skin_evidence_score": 0.72, '
        '"candidates": [], "warnings": ["Kulit terlihat, tetapi class tidak yakin"]}'
    )

    assert payload["is_valid_skin_image"] is True
    assert payload["candidates"] == []


def test_skin_filter_response_treats_body_area_as_valid() -> None:
    payload = NvidiaVisualClient().skin_filter_response_from_text(
        '{"is_valid_skin_image": false, "skin_evidence_score": 0.81, "warnings": []}'
    )

    assert payload["is_valid_skin_image"] is True


def test_skin_filter_response_accepts_non_json_human_skin_answer() -> None:
    payload = NvidiaVisualClient().skin_filter_response_from_text(
        "Ya, ini foto kulit manusia pada lengan dengan bercak putih seperti vitiligo."
    )

    assert payload["is_valid_skin_image"] is True


def test_skin_filter_response_rejects_non_json_clear_non_skin_answer() -> None:
    payload = NvidiaVisualClient().skin_filter_response_from_text(
        "False. Gambar ini adalah dokumen di layar, bukan kulit manusia."
    )

    assert payload["is_valid_skin_image"] is False


def test_skin_filter_rejects_explicit_non_skin_json() -> None:
    payload = NvidiaVisualClient().skin_filter_response_from_text(
        '{"is_valid_skin_image": false, "skin_evidence_score": 0.02, '
        '"contains_human_body_part": false, "contains_visible_skin": false, '
        '"warnings": ["Objek adalah dokumen"]}'
    )

    assert payload["is_valid_skin_image"] is False


def test_skin_filter_accepts_visible_skin_evidence_even_if_summary_flag_is_false() -> None:
    payload = NvidiaVisualClient().skin_filter_response_from_text(
        '{"is_valid_skin_image": false, "skin_evidence_score": 0.82, '
        '"contains_human_body_part": true, "contains_visible_skin": true, '
        '"warnings": ["Bercak putih terlihat pada lengan"]}'
    )

    assert payload["is_valid_skin_image"] is True


def test_dataset_visual_index_builds_and_retrieves_matching_class(tmp_path) -> None:
    image_root = tmp_path / "images"
    red_class = image_root / "Red_Rash"
    blue_class = image_root / "Blue_Object"
    red_class.mkdir(parents=True)
    blue_class.mkdir(parents=True)
    Image.new("RGB", (128, 128), color=(210, 70, 60)).save(red_class / "red.jpg")
    Image.new("RGB", (128, 128), color=(30, 60, 210)).save(blue_class / "blue.jpg")

    visual_index = DatasetVisualIndex(image_root=image_root, index_path=tmp_path / "index.json")
    summary = visual_index.build()
    matches = visual_index.search_base64(
        sample_image_base64(),
        allowed_classes=["Red_Rash", "Blue_Object"],
        limit=2,
    )

    assert summary["indexed_classes"] == 2
    assert summary["indexed_images"] == 2
    assert matches[0]["dataset_class_name"] == "Red_Rash"
    assert matches[0]["similarity"] > matches[1]["similarity"]

    restricted_matches = visual_index.search_base64(
        sample_image_base64(),
        allowed_classes=["Red_Rash"],
        limit=2,
    )
    assert [match["dataset_class_name"] for match in restricted_matches] == ["Red_Rash"]


def test_quota_exhaustion_does_not_run_second_skin_filter() -> None:
    class EmptyDatasetIndex:
        def search_base64(self, image_base64, allowed_classes, limit=20):
            return []

    class QuotaExhaustedClient:
        provider = "nvidia"

        def __init__(self) -> None:
            self.skin_filter_calls = 0

        def analyze(
            self,
            image_base64,
            candidate_classes,
            dataset_matches,
            complaint_text="",
            symptom_questions=None,
        ):
            return {
                "provider_status": "quota_exceeded",
                "is_valid_skin_image": False,
                "candidates": [],
                "warnings": ["Kuota/limit NVIDIA NIM API telah habis."],
                "raw_response": {"error_code": "quota_exceeded"},
            }

        def validate_skin_image(self, image_base64):
            self.skin_filter_calls += 1
            return {"is_valid_skin_image": False, "warnings": [], "raw_response": {}}

    client_with_quota = QuotaExhaustedClient()
    response = VisualAnalysisService(client_with_quota, EmptyDatasetIndex()).analyze(
        AnalyzeImageRequest(
            consultation_id="DC-QUOTA-001",
            image_base64=sample_image_base64(),
            candidate_classes=["Eczema"],
        ),
        ImageValidationResponse(is_valid=True, mime_type="image/png", size_bytes=100),
    )

    assert response.provider_status == "quota_exceeded"
    assert response.is_valid_skin_image is False
    assert client_with_quota.skin_filter_calls == 0


def test_dataset_retrieval_becomes_visual_fallback_when_nvidia_returns_no_candidates() -> None:
    class RetrievalIndex:
        def search_base64(self, image_base64, allowed_classes, limit=20):
            return [
                {"dataset_class_name": "Psoriasis", "similarity": 0.951, "sample_files": ["pso.jpg"]},
                {"dataset_class_name": "Dry_Skin_Eczema", "similarity": 0.944, "sample_files": ["dry.jpg"]},
            ]

    class EmptyCandidateClient:
        provider = "nvidia"

        def analyze(
            self,
            image_base64,
            candidate_classes,
            dataset_matches,
            complaint_text="",
            symptom_questions=None,
        ):
            return {
                "provider_status": "ok",
                "is_valid_skin_image": True,
                "candidates": [],
                "suggested_symptom_codes": [],
                "warnings": ["Respons NVIDIA NIM tidak berbentuk JSON valid."],
                "raw_response": {"text": "not json"},
            }

        def validate_skin_image(self, image_base64):
            raise AssertionError("skin filter should not run when image is already valid")

    response = VisualAnalysisService(EmptyCandidateClient(), RetrievalIndex()).analyze(
        AnalyzeImageRequest(
            consultation_id="DC-FALLBACK-001",
            image_base64=sample_image_base64(),
            candidate_classes=["Psoriasis", "Dry_Skin_Eczema"],
        ),
        ImageValidationResponse(is_valid=True, mime_type="image/png", size_bytes=100),
    )

    assert response.provider_status == "ok"
    assert response.is_valid_skin_image is True
    assert response.candidates
    assert response.candidates[0].dataset_class_name == "Psoriasis"
    assert response.candidates[0].visual_score <= 0.68
    assert any("fallback dari indeks visual dataset" in warning for warning in response.warnings)


def test_dataset_retrieval_keeps_consultation_running_when_provider_is_unavailable() -> None:
    class RetrievalIndex:
        def search_base64(self, image_base64, allowed_classes, limit=20):
            return [
                {"dataset_class_name": "Tinea_Corporis", "similarity": 0.94, "sample_files": ["tinea.jpg"]},
            ]

    class BusyProviderClient:
        provider = "groq"
        visual_candidate_limit = 12
        total_candidate_cap = 28

        def analyze(
            self,
            image_base64,
            candidate_classes,
            dataset_matches,
            complaint_text="",
            symptom_questions=None,
        ):
            return {
                "provider_status": "unavailable",
                "is_valid_skin_image": False,
                "candidates": [],
                "suggested_symptom_codes": [],
                "warnings": ["Groq vision API sedang tidak tersedia."],
                "raw_response": {"error": "503 over capacity"},
            }

        def validate_skin_image(self, image_base64):
            raise AssertionError("provider skin filter should not run during dataset fallback")

    response = VisualAnalysisService(BusyProviderClient(), RetrievalIndex()).analyze(
        AnalyzeImageRequest(
            consultation_id="DC-DEGRADED-001",
            image_base64=sample_image_base64(),
            candidate_classes=["Tinea_Corporis"],
        ),
        ImageValidationResponse(is_valid=True, mime_type="image/png", size_bytes=100),
    )

    assert response.provider_status == "ok"
    assert response.is_valid_skin_image is True
    assert response.candidates[0].dataset_class_name == "Tinea_Corporis"
    assert response.raw_response["provider_status_before_dataset_fallback"] == "unavailable"


def test_nvidia_429_is_classified_as_quota_exhausted() -> None:
    response = NvidiaVisualClient().provider_error_response(
        RuntimeError("429 rate_limit_exceeded: rate limit reached"),
        "NVIDIA NIM API",
    )

    assert response["provider_status"] == "quota_exceeded"
    assert response["raw_response"]["error_code"] == "quota_exceeded"
    assert "API key dengan limit aktif" in response["warnings"][0]


def test_post_with_retry_recovers_from_a_transient_503(monkeypatch) -> None:
    import httpx

    from app.services import nvidia_service as nvidia_service_module

    calls: list[int] = []

    class FakeResponse:
        def __init__(self, status_code: int, payload: dict) -> None:
            self.status_code = status_code
            self._payload = payload

        def json(self):
            return self._payload

        def raise_for_status(self):
            if self.status_code >= 400:
                raise httpx.HTTPStatusError("error", request=None, response=self)

    def fake_post(url, headers, json, timeout):
        calls.append(1)

        if len(calls) == 1:
            return FakeResponse(503, {"error": {"message": "over capacity"}})

        return FakeResponse(200, {"choices": [{"message": {"content": "ok"}}]})

    monkeypatch.setattr(nvidia_service_module.time, "sleep", lambda seconds: None)
    monkeypatch.setattr(nvidia_service_module.httpx, "post", fake_post)

    body = NvidiaVisualClient().post_with_retry({"model": "test"}, timeout=5.0)

    assert len(calls) == 2
    assert body["choices"][0]["message"]["content"] == "ok"


def test_post_with_retry_gives_up_after_repeated_503(monkeypatch) -> None:
    import httpx

    from app.services import nvidia_service as nvidia_service_module

    calls: list[int] = []

    class FakeResponse:
        status_code = 503

        def json(self):
            return {"error": {"message": "over capacity"}}

        def raise_for_status(self):
            raise httpx.HTTPStatusError("error", request=None, response=self)

    def fake_post(url, headers, json, timeout):
        calls.append(1)

        return FakeResponse()

    monkeypatch.setattr(nvidia_service_module.time, "sleep", lambda seconds: None)
    monkeypatch.setattr(nvidia_service_module.httpx, "post", fake_post)

    try:
        NvidiaVisualClient().post_with_retry({"model": "test"}, timeout=5.0)
        raised = False
    except httpx.HTTPStatusError:
        raised = True

    assert raised is True
    assert len(calls) == 2


def test_groq_chat_completion_uses_openai_compatible_vision_body(monkeypatch) -> None:
    from app.config import settings
    from app.services import groq_service as groq_service_module

    captured: dict = {}
    original_api_key = settings.groq_api_key
    object.__setattr__(settings, "groq_api_key", "test-key")

    class FakeResponse:
        status_code = 200

        def json(self):
            return {"choices": [{"message": {"content": '{"is_valid_skin_image": true, "candidates": [], "warnings": []}'}}]}

        def raise_for_status(self):
            return None

    def fake_post(url, headers, json, timeout):
        captured["url"] = url
        captured["headers"] = headers
        captured["json"] = json
        captured["timeout"] = timeout
        return FakeResponse()

    monkeypatch.setattr(groq_service_module.httpx, "post", fake_post)

    try:
        GroqVisualClient().chat_completion(
            "Balas JSON",
            f"data:image/jpeg;base64,{sample_image_base64()}",
            response_format={"type": "json_object"},
        )
    finally:
        object.__setattr__(settings, "groq_api_key", original_api_key)

    assert captured["url"] == "https://api.groq.com/openai/v1/chat/completions"
    assert captured["headers"]["Authorization"] == "Bearer test-key"
    assert captured["json"]["model"] == settings.groq_model_name
    assert captured["json"]["response_format"] == {"type": "json_object"}
    assert captured["json"]["messages"][0]["content"][1]["type"] == "image_url"
    assert captured["json"]["max_completion_tokens"] == 450


def test_groq_retry_after_parser_reads_provider_wait_hint() -> None:
    import httpx

    response = httpx.Response(
        429,
        text='{"error":{"message":"Please try again in 20.3325s."}}',
    )

    assert GroqVisualClient().retry_after_seconds(response, fallback_seconds=8.0) == 21.3325
