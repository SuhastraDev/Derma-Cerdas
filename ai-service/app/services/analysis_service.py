from __future__ import annotations

from app.schemas import AnalyzeImageRequest, AnalyzeImageResponse, ImageValidationResponse
from app.services.gemini_service import GeminiVisualClient, normalize_candidates


class VisualAnalysisService:
    def __init__(self, client: GeminiVisualClient) -> None:
        self.client = client

    def analyze(self, payload: AnalyzeImageRequest, validation: ImageValidationResponse) -> AnalyzeImageResponse:
        ai_result = self.client.analyze(payload.image_base64, payload.candidate_classes)
        warnings = [*validation.warnings, *ai_result.get("warnings", [])]
        candidates = normalize_candidates(ai_result.get("candidates", []))
        is_valid_skin_image = bool(ai_result.get("is_valid_skin_image", False))

        if not is_valid_skin_image and not candidates:
            skin_filter = self.client.validate_skin_image(payload.image_base64)
            is_valid_skin_image = bool(skin_filter.get("is_valid_skin_image", False))
            warnings = [*warnings, *skin_filter.get("warnings", [])]
            ai_result["skin_filter_raw_response"] = skin_filter.get("raw_response", {})

        if not candidates and is_valid_skin_image:
            warnings.append("Tidak ada kandidat visual yang cocok dengan mapping MVP.")

        return AnalyzeImageResponse(
            provider=self.client.provider,
            is_valid_skin_image=is_valid_skin_image,
            candidates=candidates,
            warnings=warnings,
            raw_response=ai_result.get("raw_response", {}),
        )
