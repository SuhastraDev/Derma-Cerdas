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
from app.services.groq_service import GroqVisualClient, normalize_candidates


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
    response = client.post(
        "/analyze-image",
        json={
            "consultation_id": "DC-TEST-001",
            "image_base64": sample_image_base64(),
            "candidate_classes": ["Tinea_Corporis", "Eczema"],
        },
    )

    assert response.status_code == 200
    payload = response.json()
    assert payload["provider"] == "groq"
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


def test_groq_json_parser_handles_fenced_json() -> None:
    parsed = GroqVisualClient().parse_json_text(
        '```json\n{"is_valid_skin_image": true, "candidates": [], "warnings": []}\n```'
    )

    assert parsed["is_valid_skin_image"] is True


def test_groq_response_treats_candidate_output_as_valid_skin_image() -> None:
    payload = GroqVisualClient().response_from_text(
        '{"is_valid_skin_image": false, "candidates": ['
        '{"dataset_class_name": "Eczema", "visual_score": 0.42, "reason": "Area kulit tampak kemerahan"}'
        '], "warnings": ["Foto agak blur"]}'
    )

    assert payload["is_valid_skin_image"] is True
    assert payload["candidates"]


def test_groq_response_treats_skin_evidence_as_valid_without_candidates() -> None:
    payload = GroqVisualClient().response_from_text(
        '{"is_valid_skin_image": false, "skin_evidence_score": 0.72, '
        '"candidates": [], "warnings": ["Kulit terlihat, tetapi class tidak yakin"]}'
    )

    assert payload["is_valid_skin_image"] is True
    assert payload["candidates"] == []


def test_skin_filter_response_treats_body_area_as_valid() -> None:
    payload = GroqVisualClient().skin_filter_response_from_text(
        '{"is_valid_skin_image": false, "skin_evidence_score": 0.81, "warnings": []}'
    )

    assert payload["is_valid_skin_image"] is True


def test_skin_filter_response_accepts_non_json_human_skin_answer() -> None:
    payload = GroqVisualClient().skin_filter_response_from_text(
        "Ya, ini foto kulit manusia pada lengan dengan bercak putih seperti vitiligo."
    )

    assert payload["is_valid_skin_image"] is True


def test_skin_filter_response_rejects_non_json_clear_non_skin_answer() -> None:
    payload = GroqVisualClient().skin_filter_response_from_text(
        "False. Gambar ini adalah dokumen di layar, bukan kulit manusia."
    )

    assert payload["is_valid_skin_image"] is False


def test_skin_filter_rejects_explicit_non_skin_json() -> None:
    payload = GroqVisualClient().skin_filter_response_from_text(
        '{"is_valid_skin_image": false, "skin_evidence_score": 0.02, '
        '"contains_human_body_part": false, "contains_visible_skin": false, '
        '"warnings": ["Objek adalah dokumen"]}'
    )

    assert payload["is_valid_skin_image"] is False


def test_skin_filter_accepts_visible_skin_evidence_even_if_summary_flag_is_false() -> None:
    payload = GroqVisualClient().skin_filter_response_from_text(
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
        def search_base64(self, image_base64, allowed_classes):
            return []

    class QuotaExhaustedClient:
        provider = "groq"

        def __init__(self) -> None:
            self.skin_filter_calls = 0

        def analyze(self, image_base64, candidate_classes, dataset_matches):
            return {
                "provider_status": "quota_exceeded",
                "is_valid_skin_image": False,
                "candidates": [],
                "warnings": ["Kuota/limit Groq API telah habis."],
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


def test_groq_429_is_classified_as_quota_exhausted() -> None:
    response = GroqVisualClient().provider_error_response(
        RuntimeError("429 rate_limit_exceeded: rate limit reached"),
        "Groq API",
    )

    assert response["provider_status"] == "quota_exceeded"
    assert response["raw_response"]["error_code"] == "quota_exceeded"
    assert "API key dengan limit aktif" in response["warnings"][0]
