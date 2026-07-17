from __future__ import annotations

from fastapi import FastAPI, HTTPException

from app.config import settings
from app.schemas import AnalyzeImageRequest, AnalyzeImageResponse, ImageValidationRequest, ImageValidationResponse
from app.services.analysis_service import VisualAnalysisService
from app.services.gemini_service import GeminiVisualClient
from app.services.image_validation import ImageValidationError, ImageValidator

app = FastAPI(title=settings.app_name, version="0.1.0")


@app.get("/health")
def health() -> dict[str, str | bool]:
    return {
        "status": "ok",
        "service": settings.app_name,
        "environment": settings.app_env,
        "mock_mode": settings.ai_mock_mode,
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

    service = VisualAnalysisService(GeminiVisualClient())
    return service.analyze(payload, validation)
