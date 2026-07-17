from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Settings:
    app_name: str = os.getenv("APP_NAME", "DermaCerdas AI Service")
    app_env: str = os.getenv("APP_ENV", "local")
    ai_mock_mode: bool = os.getenv("AI_MOCK_MODE", "true").lower() in {"1", "true", "yes", "on"}
    gemini_api_key: str | None = os.getenv("GEMINI_API_KEY") or None
    gemini_model_name: str = os.getenv("GEMINI_MODEL_NAME", "gemini-2.5-flash")
    max_image_size_mb: int = int(os.getenv("MAX_IMAGE_SIZE_MB", "5"))
    allowed_image_mime_types: tuple[str, ...] = tuple(
        item.strip()
        for item in os.getenv("ALLOWED_IMAGE_MIME_TYPES", "image/jpeg,image/png,image/webp").split(",")
        if item.strip()
    )


settings = Settings()
