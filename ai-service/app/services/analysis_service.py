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

        if not candidates and ai_result.get("is_valid_skin_image", False):
            warnings.append("Tidak ada kandidat visual yang cocok dengan mapping MVP.")

        return AnalyzeImageResponse(
            provider=self.client.provider,
            is_valid_skin_image=bool(ai_result.get("is_valid_skin_image", False)),
            candidates=candidates,
            warnings=warnings,
            raw_response=ai_result.get("raw_response", {}),
        )
