from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path


AI_SERVICE_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(AI_SERVICE_ROOT))

from app.services.dataset_visual_index import DatasetVisualIndex


def main() -> None:
    parser = argparse.ArgumentParser(description="Build visual centroid index for the SD-198 dataset.")
    parser.add_argument("--max-images-per-class", type=int, default=40)
    args = parser.parse_args()

    summary = DatasetVisualIndex().build(max_images_per_class=max(1, args.max_images_per_class))
    print(json.dumps(summary, ensure_ascii=True))


if __name__ == "__main__":
    main()
