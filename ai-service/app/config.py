from __future__ import annotations

import os
from pathlib import Path
from dataclasses import dataclass


def load_env_file() -> None:
    env_path = Path(__file__).resolve().parents[1] / ".env"

    if not env_path.exists():
        return

    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()

        if not line or line.startswith("#") or "=" not in line:
            continue

        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


load_env_file()


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
