from __future__ import annotations

from fastapi import FastAPI, HTTPException

from app.config import settings
from app.schemas import (
    AnalyzeImageRequest,
    AnalyzeImageResponse,
    AssessRedFlagsRequest,
    AssessRedFlagsResponse,
    ImageValidationRequest,
    ImageValidationResponse,
)
from app.services.analysis_service import VisualAnalysisService
from app.services.dataset_visual_index import DatasetVisualIndex
from app.services.cerebras_service import CerebrasVisualClient
from app.services.image_validation import ImageValidationError, ImageValidator

app = FastAPI(title=settings.app_name, version="0.1.0")


@app.get("/health")
def health() -> dict[str, str | bool]:
    return {
        "status": "ok",
        "service": settings.app_name,
        "environment": settings.app_env,
        "mock_mode": settings.ai_mock_mode,
        "dataset_index_ready": DatasetVisualIndex().status()["ready"],
    }


@app.post("/validate-image", response_model=ImageValidationResponse)
def validate_image(payload: ImageValidationRequest) -> ImageValidationResponse:
    validator = ImageValidator()

    try:
        return validator.validate_base64(payload.image_base64)
    except ImageValidationError as exc:
        return ImageValidationResponse(is_valid=False, warnings=[str(exc)])


@app.post("/analyze-image", response_model=AnalyzeImageResponse)
def analyze_image(payload: AnalyzeImageRequest) -> AnalyzeImageResponse:
    validator = ImageValidator()

    try:
        validation = validator.validate_base64(payload.image_base64)
    except ImageValidationError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc

    if not validation.is_valid:
        raise HTTPException(status_code=422, detail="Gambar tidak valid untuk dianalisis.")

    service = VisualAnalysisService(CerebrasVisualClient())
    return service.analyze(payload, validation)


@app.post("/assess-red-flags", response_model=AssessRedFlagsResponse)
def assess_red_flags(payload: AssessRedFlagsRequest) -> AssessRedFlagsResponse:
    result = CerebrasVisualClient().assess_red_flags(
        payload.complaint_text,
        [red_flag.model_dump() for red_flag in payload.red_flags],
    )
    provider_status = str(result.get("provider_status", "ok"))

    return AssessRedFlagsResponse(
        provider_status=provider_status,
        detected_codes=result.get("detected_codes", []) if provider_status == "ok" else [],
        warnings=result.get("warnings", []),
        raw_response=result.get("raw_response", {}),
    )
