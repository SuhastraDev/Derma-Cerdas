from __future__ import annotations

from app.schemas import AnalyzeImageRequest, AnalyzeImageResponse, ImageValidationResponse
from app.services.class_mapping import allowed_candidate_classes
from app.services.dataset_visual_index import DatasetVisualIndex
from app.services.nvidia_service import NvidiaVisualClient, normalize_candidates

# How many of the most visually similar dataset classes (out of the full ~200
# SD-198 catalogue) get offered to the model per photo, on top of Laravel's
# symptom-relevant hints. Keeps the prompt small and token usage predictable
# regardless of how many classes are mapped in the database.
VISUAL_CANDIDATE_LIMIT = 20
TOTAL_CANDIDATE_CAP = 48


class VisualAnalysisService:
    def __init__(self, client: NvidiaVisualClient, dataset_index: DatasetVisualIndex | None = None) -> None:
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

        ai_result = self.client.analyze(
            payload.image_base64,
            candidate_classes,
            dataset_matches,
            complaint_text=payload.complaint_text,
            symptom_questions=payload.symptom_questions,
        )
        warnings = [*validation.warnings, *ai_result.get("warnings", [])]
        candidates = normalize_candidates(ai_result.get("candidates", []), candidate_classes)
        is_valid_skin_image = bool(ai_result.get("is_valid_skin_image", False))
        provider_status = str(ai_result.get("provider_status", "ok"))
        raw_response = dict(ai_result.get("raw_response", {}))
        raw_response["dataset_retrieval"] = dataset_matches

        if provider_status == "ok" and is_valid_skin_image and not candidates:
            candidates = self.dataset_fallback_candidates(dataset_matches, candidate_classes)
            if candidates:
                warnings.append(
                    "NVIDIA NIM tidak menghasilkan JSON kandidat yang valid; sistem memakai kandidat fallback dari indeks visual dataset."
                )

        allowed_symptom_codes = {
            str(question.get("code"))
            for question in payload.symptom_questions
            if question.get("code")
        }
        suggested_symptom_codes = list(
            dict.fromkeys(
                code
                for code in (ai_result.get("suggested_symptom_codes", []) or [])
                if isinstance(code, str) and code in allowed_symptom_codes
            )
        )[:8]

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
            suggested_symptom_codes=suggested_symptom_codes,
            warnings=warnings,
            raw_response=raw_response,
        )

    def dataset_fallback_candidates(
        self,
        dataset_matches: list[dict],
        candidate_classes: list[str],
    ) -> list:
        fallback = []

        for match in dataset_matches[:6]:
            class_name = str(match.get("dataset_class_name", "")).strip()

            if not class_name:
                continue

            try:
                similarity = float(match.get("similarity", 0))
            except (TypeError, ValueError):
                similarity = 0.0

            if similarity < 0.88:
                continue

            # The handmade dataset index is only a retrieval hint, not a
            # trained dermatology classifier. Keep its confidence moderate.
            fallback.append(
                {
                    "dataset_class_name": class_name,
                    "visual_score": round(min(0.68, max(0.45, 0.45 + ((similarity - 0.88) * 2.0))), 4),
                    "reason": (
                        "Fallback indeks dataset: pola warna/tekstur foto paling mirip dengan "
                        f"class {class_name} (similarity {similarity:.4f}); perlu konfirmasi gejala."
                    ),
                }
            )

            if len(fallback) >= 3:
                break

        return normalize_candidates(fallback, candidate_classes)
