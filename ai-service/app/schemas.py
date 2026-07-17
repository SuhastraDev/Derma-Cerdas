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


class VisualCandidate(BaseModel):
    dataset_class_name: str
    local_disease_code: str | None = None
    visual_score: float = Field(..., ge=0, le=1)
    reason: str


class AnalyzeImageResponse(BaseModel):
    provider: str
    is_valid_skin_image: bool
    candidates: list[VisualCandidate] = Field(default_factory=list)
    warnings: list[str] = Field(default_factory=list)
    raw_response: dict = Field(default_factory=dict)
