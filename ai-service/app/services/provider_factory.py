from __future__ import annotations

from app.config import settings
from app.services.groq_service import GroqVisualClient
from app.services.nvidia_service import NvidiaVisualClient


def visual_client() -> GroqVisualClient | NvidiaVisualClient:
    if settings.ai_provider == "groq":
        return GroqVisualClient()

    return NvidiaVisualClient()
