from __future__ import annotations

from app.schemas import AnalyzeImageRequest, AnalyzeImageResponse, ImageValidationResponse
from app.services.class_mapping import allowed_candidate_classes
from app.services.dataset_visual_index import DatasetVisualIndex
from app.services.groq_service import GroqVisualClient, normalize_candidates

# How many of the most visually similar dataset classes (out of the full ~200
# SD-198 catalogue) get offered to the model per photo, on top of Laravel's
# symptom-relevant hints. Keeps the prompt small and token usage predictable
# regardless of how many classes are mapped in the database.
VISUAL_CANDIDATE_LIMIT = 20
TOTAL_CANDIDATE_CAP = 24


class VisualAnalysisService:
    def __init__(self, client: GroqVisualClient, dataset_index: DatasetVisualIndex | None = None) -> None:
        self.client = client
        self.dataset_index = dataset_index or DatasetVisualIndex()

    def analyze(self, payload: AnalyzeImageRequest, validation: ImageValidationResponse) -> AnalyzeImageResponse:
        hint_classes = allowed_candidate_classes(payload.candidate_classes)

        # Search the whole indexed dataset (not just the textual hints) so the
        # model can be offered the class it actually looks like, even outside
        # Laravel's symptom-relevant shortlist - e.g. a condition with no
        # validated symptom/CF knowledge base yet, like Psoriasis.
        dataset_matches = self.dataset_index.search_base64(
            payload.image_base64, allowed_classes=[], limit=VISUAL_CANDIDATE_LIMIT
        )
        visual_match_classes = [str(match["dataset_class_name"]) for match in dataset_matches]

        candidate_classes = list(dict.fromkeys([*hint_classes, *visual_match_classes]))[:TOTAL_CANDIDATE_CAP]

        ai_result = self.client.analyze(payload.image_base64, candidate_classes, dataset_matches)
        warnings = [*validation.warnings, *ai_result.get("warnings", [])]
        candidates = normalize_candidates(ai_result.get("candidates", []), candidate_classes)
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
