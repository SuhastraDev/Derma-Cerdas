from __future__ import annotations

import base64
from io import BytesIO

from fastapi.testclient import TestClient
from PIL import Image

from app.main import app
from app.services.gemini_service import GeminiVisualClient, normalize_candidates


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
    assert payload["provider"] == "gemini"
    assert payload["is_valid_skin_image"] is False
    assert payload["candidates"] == []
    assert payload["warnings"]


def test_normalize_candidates_discards_unknown_dataset_classes() -> None:
    candidates = normalize_candidates(
        [
            {"dataset_class_name": "Unknown_Class", "visual_score": 0.99, "reason": "unknown"},
            {"dataset_class_name": "Urticaria", "visual_score": 0.70, "reason": "wheals"},
        ]
    )

    assert len(candidates) == 1
    assert candidates[0].local_disease_code == "URTICARIA"


def test_gemini_json_parser_handles_fenced_json() -> None:
    parsed = GeminiVisualClient().parse_json_text(
        '```json\n{"is_valid_skin_image": true, "candidates": [], "warnings": []}\n```'
    )

    assert parsed["is_valid_skin_image"] is True
