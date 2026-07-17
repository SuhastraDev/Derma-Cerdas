from __future__ import annotations

import base64
import binascii
from io import BytesIO

from PIL import Image, UnidentifiedImageError

from app.config import settings
from app.schemas import ImageValidationResponse


class ImageValidationError(ValueError):
    pass


class ImageValidator:
    def validate_base64(self, image_base64: str) -> ImageValidationResponse:
        raw = self.decode_base64(image_base64)
        size_bytes = len(raw)
        max_bytes = settings.max_image_size_mb * 1024 * 1024

        if size_bytes > max_bytes:
            raise ImageValidationError(f"Ukuran gambar melebihi {settings.max_image_size_mb} MB.")

        mime_type = self.detect_mime_type(raw)

        if mime_type not in settings.allowed_image_mime_types:
            raise ImageValidationError("Format gambar harus JPG, PNG, atau WEBP.")

        try:
            with Image.open(BytesIO(raw)) as image:
                width, height = image.size
                image.verify()
        except UnidentifiedImageError as exc:
            raise ImageValidationError("File bukan gambar yang valid.") from exc

        warnings = []
        if width < 224 or height < 224:
            warnings.append("Resolusi gambar rendah; hasil visual bisa kurang akurat.")

        return ImageValidationResponse(
            is_valid=True,
            mime_type=mime_type,
            size_bytes=size_bytes,
            width=width,
            height=height,
            warnings=warnings,
        )

    def decode_base64(self, image_base64: str) -> bytes:
        normalized = image_base64.strip()

        if "," in normalized and normalized.lower().startswith("data:"):
            normalized = normalized.split(",", 1)[1]

        try:
            return base64.b64decode(normalized, validate=True)
        except (binascii.Error, ValueError) as exc:
            raise ImageValidationError("Payload gambar base64 tidak valid.") from exc

    def detect_mime_type(self, raw: bytes) -> str | None:
        if raw.startswith(b"\xff\xd8\xff"):
            return "image/jpeg"

        if raw.startswith(b"\x89PNG\r\n\x1a\n"):
            return "image/png"

        if raw.startswith(b"RIFF") and raw[8:12] == b"WEBP":
            return "image/webp"

        return None
