from __future__ import annotations

import re
from dataclasses import dataclass


@dataclass(frozen=True)
class DiseaseMapping:
    dataset_class_name: str
    local_disease_code: str
    nama_indonesia: str
    scope_category: str
    default_action: str


MVP_MAPPINGS: dict[str, DiseaseMapping] = {
    "eczema": DiseaseMapping("Eczema", "ECZEMA", "Eksim / dermatitis umum", "swamedikasi", "recommend_otc"),
    "acute_eczema": DiseaseMapping("Acute_Eczema", "ACUTE_ECZEMA", "Eksim akut ringan", "swamedikasi", "recommend_otc"),
    "dry_skin_eczema": DiseaseMapping("Dry_Skin_Eczema", "DRY_SKIN_ECZEMA", "Eksim kulit kering", "swamedikasi", "educate_only"),
    "allergic_contact_dermatitis": DiseaseMapping(
        "Allergic_Contact_Dermatitis",
        "ALLERGIC_CONTACT_DERMATITIS",
        "Dermatitis kontak alergi",
        "swamedikasi",
        "recommend_otc",
    ),
    "urticaria": DiseaseMapping("Urticaria", "URTICARIA", "Biduran / urtikaria ringan", "swamedikasi", "recommend_otc"),
    "candidiasis": DiseaseMapping("Candidiasis", "CANDIDIASIS", "Kandidiasis kulit ringan", "swamedikasi", "recommend_otc"),
    "tinea_corporis": DiseaseMapping("Tinea_Corporis", "TINEA_CORPORIS", "Kurap badan", "swamedikasi", "recommend_otc"),
    "tinea_cruris": DiseaseMapping("Tinea_Cruris", "TINEA_CRURIS", "Jamur lipatan paha ringan", "swamedikasi", "recommend_otc"),
    "tinea_pedis": DiseaseMapping("Tinea_Pedis", "TINEA_PEDIS", "Kutu air / jamur kaki", "swamedikasi", "recommend_otc"),
    "tinea_versicolor": DiseaseMapping("Tinea_Versicolor", "TINEA_VERSICOLOR", "Panu", "swamedikasi", "recommend_otc"),
}


def normalize_class_name(value: str) -> str:
    return value.strip().replace("-", "_").replace(" ", "_").lower()


def resolve_mapping(dataset_class_name: str) -> DiseaseMapping | None:
    return MVP_MAPPINGS.get(normalize_class_name(dataset_class_name))


def allowed_candidate_classes(candidate_classes: list[str]) -> list[str]:
    if not candidate_classes:
        return [mapping.dataset_class_name for mapping in MVP_MAPPINGS.values()]

    resolved: list[str] = []
    for class_name in candidate_classes:
        cleaned = str(class_name).strip()

        if not re.fullmatch(r"[A-Za-z0-9_().&' -]{1,160}", cleaned):
            continue

        mapping = resolve_mapping(cleaned)
        if mapping:
            resolved.append(mapping.dataset_class_name)
        else:
            resolved.append(cleaned)

    return list(dict.fromkeys(resolved)) or [mapping.dataset_class_name for mapping in MVP_MAPPINGS.values()]
