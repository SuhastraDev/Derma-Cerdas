from __future__ import annotations

from app.schemas import AnalyzeImageRequest, AnalyzeImageResponse, ImageValidationResponse
from app.services.class_mapping import allowed_candidate_classes
from app.services.dataset_visual_index import DatasetVisualIndex
from app.services.gemini_service import GeminiVisualClient, normalize_candidates


class VisualAnalysisService:
    def __init__(self, client: GeminiVisualClient, dataset_index: DatasetVisualIndex | None = None) -> None:
        self.client = client
        self.dataset_index = dataset_index or DatasetVisualIndex()

    def analyze(self, payload: AnalyzeImageRequest, validation: ImageValidationResponse) -> AnalyzeImageResponse:
        allowed_classes = allowed_candidate_classes(payload.candidate_classes)
        dataset_matches = self.dataset_index.search_base64(payload.image_base64, allowed_classes)
        ai_result = self.client.analyze(payload.image_base64, allowed_classes, dataset_matches)
        warnings = [*validation.warnings, *ai_result.get("warnings", [])]
        candidates = normalize_candidates(ai_result.get("candidates", []), allowed_classes)
        is_valid_skin_image = bool(ai_result.get("is_valid_skin_image", False))
        provider_status = str(ai_result.get("provider_status", "ok"))
        raw_response = dict(ai_result.get("raw_response", {}))
        raw_response["dataset_retrieval"] = dataset_matches

        if provider_status == "ok" and not is_valid_skin_image and not candidates:
            skin_filter = self.client.validate_skin_image(payload.image_base64)
            provider_status = str(skin_filter.get("provider_status", "ok"))
            is_valid_skin_image = bool(skin_filter.get("is_valid_skin_image", False))
            warnings = [*warnings, *skin_filter.get("warnings", [])]
            raw_response["skin_filter"] = skin_filter.get("raw_response", {})

        if not candidates and is_valid_skin_image:
            warnings.append("Tidak ada kandidat visual yang cocok dengan mapping MVP.")

        return AnalyzeImageResponse(
            provider=self.client.provider,
            provider_status=provider_status,
            is_valid_skin_image=is_valid_skin_image,
            candidates=candidates,
            warnings=warnings,
            raw_response=raw_response,
        )
