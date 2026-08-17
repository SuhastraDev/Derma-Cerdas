from __future__ import annotations

from pydantic import BaseModel, Field


class ImageValidationRequest(BaseModel):
    image_base64: str = Field(..., min_length=16)


class ImageValidationResponse(BaseModel):
    is_valid: bool
    mime_type: str | None = None
    size_bytes: int = 0
    width: int | None = None
    height: int | None = None
    warnings: list[str] = Field(default_factory=list)


class AnalyzeImageRequest(BaseModel):
    consultation_id: str
    image_base64: str = Field(..., min_length=16)
    candidate_classes: list[str] = Field(default_factory=list)
    complaint_text: str = ""
    symptom_questions: list[dict[str, str]] = Field(default_factory=list)
    # Bila True, model HANYA boleh memilih dari candidate_classes; hasil indeks
    # visual tidak ditambahkan ke daftar. Diperlukan untuk pengukuran akurasi
    # yang terkendali, karena penambahan otomatis menyuntikkan tebakan indeks
    # (recall@1 3,5%) ke dalam ruang pilihan model.
    strict_candidates: bool = False


class VisualCandidate(BaseModel):
    dataset_class_name: str
    local_disease_code: str | None = None
    visual_score: float = Field(..., ge=0, le=1)
    reason: str


class AnalyzeImageResponse(BaseModel):
    provider: str
    provider_status: str = "ok"
    is_valid_skin_image: bool
    candidates: list[VisualCandidate] = Field(default_factory=list)
    suggested_symptom_codes: list[str] = Field(default_factory=list)
    warnings: list[str] = Field(default_factory=list)
    raw_response: dict = Field(default_factory=dict)


class RedFlagQuestion(BaseModel):
    code: str
    question: str


class AssessRedFlagsRequest(BaseModel):
    complaint_text: str = Field(..., min_length=1)
    red_flags: list[RedFlagQuestion] = Field(default_factory=list)


class AssessRedFlagsResponse(BaseModel):
    provider: str = "nvidia"
    provider_status: str = "ok"
    detected_codes: list[str] = Field(default_factory=list)
    warnings: list[str] = Field(default_factory=list)
    raw_response: dict = Field(default_factory=dict)
