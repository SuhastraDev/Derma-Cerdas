from __future__ import annotations

import json
import math
from io import BytesIO
from pathlib import Path
from typing import Any

from PIL import Image, ImageFilter, ImageOps

from app.config import settings
from app.services.class_mapping import normalize_class_name
from app.services.image_validation import ImageValidator


SUPPORTED_EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp"}


def normalized_vector(values: list[float]) -> list[float]:
    magnitude = math.sqrt(sum(value * value for value in values))

    if magnitude == 0:
        return values

    return [round(value / magnitude, 7) for value in values]


def image_feature(image: Image.Image) -> list[float]:
    rgb = image.convert("RGB")
    working_image = ImageOps.fit(rgb, (96, 96), method=Image.Resampling.BILINEAR)
    thumbnail = working_image.resize((12, 12), Image.Resampling.LANCZOS)
    if hasattr(thumbnail, "get_flattened_data"):
        spatial = [
            channel / 255.0
            for pixel in thumbnail.get_flattened_data()
            for channel in pixel
        ]
    else:
        spatial = [channel / 255.0 for pixel in thumbnail.getdata() for channel in pixel]

    hsv = working_image.convert("HSV")
    hsv_histogram = hsv.histogram()
    pixel_count = float(96 * 96)
    color_histogram: list[float] = []

    for channel in range(3):
        channel_histogram = hsv_histogram[channel * 256 : (channel + 1) * 256]
        for start in range(0, 256, 16):
            color_histogram.append(sum(channel_histogram[start : start + 16]) / pixel_count)

    edges = working_image.convert("L").filter(ImageFilter.FIND_EDGES)
    edge_histogram = edges.histogram()
    texture = [sum(edge_histogram[start : start + 16]) / pixel_count for start in range(0, 256, 16)]

    return normalized_vector([*spatial, *color_histogram, *texture])


class DatasetVisualIndex:
    def __init__(self, image_root: Path | None = None, index_path: Path | None = None) -> None:
        self.image_root = image_root or settings.dataset_image_root
        self.index_path = index_path or settings.dataset_index_path
        self._entries: list[dict[str, Any]] | None = None

    def build(self, max_images_per_class: int = 40) -> dict[str, int]:
        entries: list[dict[str, Any]] = []
        indexed_images = 0
        skipped_images = 0

        if not self.image_root.is_dir():
            raise FileNotFoundError(f"Folder dataset tidak ditemukan: {self.image_root}")

        for class_directory in sorted(path for path in self.image_root.iterdir() if path.is_dir()):
            vectors: list[list[float]] = []
            samples: list[str] = []

            files = sorted(
                path
                for path in class_directory.iterdir()
                if path.is_file() and path.suffix.lower() in SUPPORTED_EXTENSIONS
            )

            for image_path in files:
                if len(vectors) >= max_images_per_class:
                    break

                try:
                    with Image.open(image_path) as image:
                        vectors.append(image_feature(image))
                    indexed_images += 1

                    if len(samples) < 3:
                        samples.append(image_path.name)
                except (OSError, ValueError):
                    skipped_images += 1

            if not vectors:
                continue

            centroid = normalized_vector(
                [sum(vector[index] for vector in vectors) / len(vectors) for index in range(len(vectors[0]))]
            )
            entries.append(
                {
                    "dataset_class_name": class_directory.name,
                    "image_count": len(vectors),
                    "sample_files": samples,
                    "centroid": centroid,
                }
            )

        payload = {
            "version": 1,
            "feature": "spatial-rgb+hsv-histogram+edge-histogram",
            "classes": entries,
        }
        self.index_path.parent.mkdir(parents=True, exist_ok=True)
        temporary_path = self.index_path.with_suffix(self.index_path.suffix + ".tmp")
        temporary_path.write_text(json.dumps(payload, separators=(",", ":")), encoding="utf-8")
        temporary_path.replace(self.index_path)
        self._entries = entries

        return {
            "indexed_classes": len(entries),
            "indexed_images": indexed_images,
            "skipped_images": skipped_images,
        }

    def search_base64(
        self,
        image_base64: str,
        allowed_classes: list[str],
        limit: int = 12,
    ) -> list[dict[str, Any]]:
        entries = self.entries()

        if not entries:
            return []

        raw = ImageValidator().decode_base64(image_base64)

        with Image.open(BytesIO(raw)) as image:
            query = image_feature(image)

        allowed = {normalize_class_name(class_name) for class_name in allowed_classes}
        matches: list[dict[str, Any]] = []

        for entry in entries:
            class_name = str(entry.get("dataset_class_name", ""))

            if allowed and normalize_class_name(class_name) not in allowed:
                continue

            centroid = entry.get("centroid", [])

            if not isinstance(centroid, list) or len(centroid) != len(query):
                continue

            similarity = max(0.0, min(1.0, sum(left * right for left, right in zip(query, centroid))))
            matches.append(
                {
                    "dataset_class_name": class_name,
                    "similarity": round(similarity, 4),
                    "sample_files": entry.get("sample_files", []),
                }
            )

        return sorted(matches, key=lambda item: item["similarity"], reverse=True)[:limit]

    def entries(self) -> list[dict[str, Any]]:
        if self._entries is not None:
            return self._entries

        if not self.index_path.is_file():
            self._entries = []
            return self._entries

        try:
            payload = json.loads(self.index_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            self._entries = []
            return self._entries

        classes = payload.get("classes", [])
        self._entries = classes if isinstance(classes, list) else []

        return self._entries

    def status(self) -> dict[str, Any]:
        entries = self.entries()

        return {
            "ready": bool(entries),
            "class_count": len(entries),
            "index_path": str(self.index_path),
        }
