#!/usr/bin/env python3
"""契約Schemaの静的検査（WordPress上の試験ではない）。

- contracts/*.schema.json が JSON Schema 2020-12 として正しい
- tests/contracts/examples/*.valid.json は通り、*.invalid.json は落ちる
- プラグインの合成フィクスチャ（@img: 等を置き換えたもの）が case_document に適合する

実行: python3 tests/contracts/test_contracts.py   （要 jsonschema）
"""
import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
CONTRACTS = ROOT / "wp-ai-case" / "contracts"
EXAMPLES = ROOT / "tests" / "contracts" / "examples"
FIXTURES = ROOT / "wp-ai-case" / "dev" / "fixtures"


def main() -> int:
    try:
        from jsonschema import Draft202012Validator, FormatChecker
    except ImportError:
        print("jsonschema が必要です: pip install jsonschema")
        return 2

    schemas = {}
    for path in sorted(CONTRACTS.glob("*.schema.json")):
        schema = json.loads(path.read_text(encoding="utf-8"))
        Draft202012Validator.check_schema(schema)
        schemas[path.name.replace(".schema.json", "")] = schema

    fmt = FormatChecker()
    failures = []
    counts = {"valid": 0, "invalid": 0, "fixture": 0}

    for path in sorted(EXAMPLES.glob("*.json")):
        stem = path.name.split(".")[0]
        if stem == "case_input_revision":
            stem = "case_input"
        schema = schemas.get(stem)
        if schema is None:
            failures.append(f"Schema がない例: {path.name}")
            continue
        errors = list(Draft202012Validator(schema, format_checker=fmt).iter_errors(json.loads(path.read_text(encoding="utf-8"))))
        if ".invalid." in path.name:
            counts["invalid"] += 1
            if not errors:
                failures.append(f"落ちるべき例が通った: {path.name}")
        else:
            counts["valid"] += 1
            if errors:
                failures.append(f"通るべき例が落ちた: {path.name}: {errors[0].message}")

    labels = {}
    for path in sorted(FIXTURES.glob("*.json")):
        text = path.read_text(encoding="utf-8")

        def img(match):
            labels.setdefault(match.group(1), 101 + len(labels))
            return str(labels[match.group(1)])

        text = re.sub(r'"@img:([a-z0-9_]+)"', img, text)
        text = text.replace('"@voice"', "501").replace('"@staff"', "601").replace('"@supervisor"', "601")
        doc = json.loads(text)
        errors = list(Draft202012Validator(schemas["case_document"], format_checker=fmt).iter_errors(doc))
        counts["fixture"] += 1
        if errors:
            failures.append(f"フィクスチャが case_document に適合しない: {path.name}: {errors[0].message}")

    print(f"schemas={len(schemas)} valid={counts['valid']} invalid={counts['invalid']} fixtures={counts['fixture']}")
    for failure in failures:
        print("FAIL", failure)
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
