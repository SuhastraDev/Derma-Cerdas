from __future__ import annotations

import base64
import json
import sys
from pathlib import Path


AI_SERVICE_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(AI_SERVICE_ROOT))

from app.services.cerebras_service import CerebrasVisualClient


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python scripts/diagnose_skin_image.py <image-path>")

    image_path = Path(sys.argv[1]).resolve()

    if not image_path.is_file():
        raise SystemExit(f"Image not found: {image_path}")

    encoded = base64.b64encode(image_path.read_bytes()).decode("ascii")
    result = CerebrasVisualClient().validate_skin_image(encoded)
    print(json.dumps(result, ensure_ascii=True, indent=2))


if __name__ == "__main__":
    main()
