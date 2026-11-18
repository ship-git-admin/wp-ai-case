#!/usr/bin/env python3
"""配布用 ZIP（wp-ai-case-<version>.zip）を作り、中身を検査する。

- ZIP の中は常に wp-ai-case/ から始まる（WordPress がこの名前のフォルダに展開する）。
- 含めないもの：dev/（開発専用：模擬AI・自己テスト・合成データ）、Markdown（.md）、.DS_Store など。
- 検査：必須ファイル・ライセンス、秘密やサイト固有情報（tools/secret_scan.py と同じ規則）、
  同梱ライブラリの指紋（vendor.lock.json）、プラグインのヘッダー、版の一致（ヘッダー・定数・readme・タグ）、PHP の構文。
- 再現可能：同じ入力からは同じ ZIP（ファイルの順・日時・権限を固定）。ZIP と各ファイルの SHA-256 を manifest に書く。

使い方:
  python3 tools/build_release.py --expect-tag v0.1.0        # リリース（CI）
  python3 tools/build_release.py --rehearsal-version 0.1.1  # 更新の予行演習用（版だけ書き換えた ZIP。配布しない）
"""
import argparse
import hashlib
import json
import pathlib
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
SRC = ROOT / "wp-ai-case"
sys.path.insert(0, str(ROOT / "tools"))
import secret_scan  # noqa: E402

EXCLUDE_DIRS = {"dev", ".git", "node_modules", "tests"}
EXCLUDE_SUFFIXES = {".md", ".markdown"}
EXCLUDE_NAMES = {".DS_Store", ".gitignore", ".gitattributes", "Thumbs.db"}
REQUIRED = [
    "wp-ai-case.php",
    "readme.txt",
    "license.txt",
    "THIRD_PARTY_NOTICES.txt",
    "vendor/plugin-update-checker/license.txt",
    "vendor/plugin-update-checker/plugin-update-checker.php",
]
HEADERS = ["Plugin Name", "Version", "Requires at least", "Requires PHP", "License", "Update URI"]
FIXED_DATE = (1980, 1, 1, 0, 0, 0)
SEMVER = re.compile(r"^\d+\.\d+\.\d+$")


def fail(msg: str) -> None:
    print(f"NG: {msg}")
    sys.exit(1)


def read_versions(base: pathlib.Path) -> dict:
    main = (base / "wp-ai-case.php").read_text(encoding="utf-8")
    readme = (base / "readme.txt").read_text(encoding="utf-8")
    header = re.search(r"^\s*\*\s*Version:\s*(\S+)", main, re.M)
    const = re.search(r"define\(\s*'WP_AI_CASE_VERSION',\s*'([^']+)'\s*\)", main)
    stable = re.search(r"^Stable tag:\s*(\S+)", readme, re.M)
    return {
        "header": header.group(1) if header else None,
        "constant": const.group(1) if const else None,
        "readme": stable.group(1) if stable else None,
    }


def set_version(base: pathlib.Path, version: str) -> None:
    main = base / "wp-ai-case.php"
    text = main.read_text(encoding="utf-8")
    text = re.sub(r"(^\s*\*\s*Version:\s*)\S+", lambda m: m.group(1) + version, text, count=1, flags=re.M)
    text = re.sub(r"(define\(\s*'WP_AI_CASE_VERSION',\s*')[^']+", lambda m: m.group(1) + version, text, count=1)
    main.write_text(text, encoding="utf-8")
    readme = base / "readme.txt"
    text = readme.read_text(encoding="utf-8")
    text = re.sub(r"(^Stable tag:\s*)\S+", lambda m: m.group(1) + version, text, count=1, flags=re.M)
    readme.write_text(text, encoding="utf-8")


def included(rel: pathlib.PurePosixPath) -> bool:
    if any(part in EXCLUDE_DIRS for part in rel.parts):
        return False
    if rel.name in EXCLUDE_NAMES or rel.suffix.lower() in EXCLUDE_SUFFIXES:
        return False
    return True


def stage(dest: pathlib.Path) -> list:
    files = []
    for path in sorted(SRC.rglob("*")):
        if not path.is_file():
            continue
        rel = pathlib.PurePosixPath(path.relative_to(SRC).as_posix())
        if not included(rel):
            continue
        target = dest / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(path, target)
        files.append(rel)
    return files


def vendor_hash(base: pathlib.Path) -> str:
    root = base / "vendor" / "plugin-update-checker"
    h = hashlib.sha256()
    for p in sorted(x for x in root.rglob("*") if x.is_file()):
        h.update(p.relative_to(root).as_posix().encode())
        h.update(b"\0")
        h.update(hashlib.sha256(p.read_bytes()).hexdigest().encode())
        h.update(b"\n")
    return h.hexdigest()


def check(base: pathlib.Path, files: list, version: str) -> None:
    names = {f.as_posix() for f in files}
    for req in REQUIRED:
        if req not in names:
            fail(f"必須ファイルがありません: {req}")
    for f in names:
        if f.endswith((".md", ".markdown")) or f.startswith("dev/") or "/dev/" in f:
            fail(f"含めてはいけないファイル: {f}")

    main = (base / "wp-ai-case.php").read_text(encoding="utf-8")
    for h in HEADERS:
        if not re.search(rf"^\s*\*\s*{re.escape(h)}:\s*\S", main, re.M):
            fail(f"プラグインのヘッダーがありません: {h}")
    versions = read_versions(base)
    if len(set(versions.values())) != 1 or versions["header"] != version:
        fail(f"版が一致しません: {versions} / {version}")

    lock = json.loads((ROOT / "vendor.lock.json").read_text(encoding="utf-8"))["plugin-update-checker"]
    if vendor_hash(base) != lock["tree_sha256"]:
        fail("同梱の Plugin Update Checker が vendor.lock.json と一致しません")

    patterns = secret_scan.load_patterns()
    for f in files:
        try:
            text = (base / f).read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        for lineno, line in enumerate(text.splitlines(), 1):
            for name, pattern in patterns.items():
                if pattern.search(line):
                    fail(f"秘密・サイト固有情報の疑い（{name}）: {f}:{lineno}")

    php = shutil.which("php")
    if php:
        for f in files:
            if f.suffix == ".php":
                r = subprocess.run([php, "-l", str(base / f)], capture_output=True, text=True)
                if r.returncode != 0:
                    fail(f"PHP の構文エラー: {f}")
    else:
        print("注意: php が見つからないため構文検査を省略しました")


def write_zip(base: pathlib.Path, files: list, out: pathlib.Path) -> None:
    with zipfile.ZipFile(out, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for d in sorted({p for f in files for p in f.parents if str(p) != "."}, key=lambda p: p.as_posix()):
            info = zipfile.ZipInfo(f"wp-ai-case/{d.as_posix()}/", date_time=FIXED_DATE)
            info.external_attr = (0o40755 << 16) | 0x10
            z.writestr(info, b"")
        root = zipfile.ZipInfo("wp-ai-case/", date_time=FIXED_DATE)
        root.external_attr = (0o40755 << 16) | 0x10
        z.writestr(root, b"")
        for f in sorted(files, key=lambda p: p.as_posix()):
            info = zipfile.ZipInfo(f"wp-ai-case/{f.as_posix()}", date_time=FIXED_DATE)
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(info, (base / f).read_bytes(), compresslevel=9)


def sha256(path: pathlib.Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--expect-tag", help="リリースのタグ（vX.Y.Z）。版と一致しなければ止める")
    ap.add_argument("--rehearsal-version", help="予行演習用に版だけ書き換える（X.Y.Z）。配布しない")
    ap.add_argument("--out", default=str(ROOT / "dist"))
    args = ap.parse_args()

    out_dir = pathlib.Path(args.out)
    with tempfile.TemporaryDirectory() as tmp:
        base = pathlib.Path(tmp) / "wp-ai-case"
        files = stage(base)
        if args.rehearsal_version:
            if not SEMVER.match(args.rehearsal_version):
                fail("予行演習の版は X.Y.Z")
            set_version(base, args.rehearsal_version)
            out_dir = out_dir / "rehearsal"
        version = read_versions(base)["header"] or ""
        if not SEMVER.match(version):
            fail(f"配布する版は X.Y.Z（開発版 {version} は配布しない）")
        if args.expect_tag and args.expect_tag != f"v{version}":
            fail(f"タグ {args.expect_tag} と版 {version} が一致しません")
        check(base, files, version)

        out_dir.mkdir(parents=True, exist_ok=True)
        zip_path = out_dir / f"wp-ai-case-{version}.zip"
        write_zip(base, files, zip_path)
        commit = subprocess.run(["git", "rev-parse", "HEAD"], cwd=ROOT, capture_output=True, text=True).stdout.strip()
        dirty = bool(subprocess.run(["git", "status", "--porcelain"], cwd=ROOT, capture_output=True, text=True).stdout.strip())
        manifest = {
            "name": "wp-ai-case",
            "version": version,
            "rehearsal": bool(args.rehearsal_version),
            "source_commit": commit,
            "source_dirty": dirty,
            "zip": zip_path.name,
            "zip_sha256": sha256(zip_path),
            "vendor": json.loads((ROOT / "vendor.lock.json").read_text(encoding="utf-8")),
            "files": [
                {"path": f"wp-ai-case/{f.as_posix()}", "sha256": sha256(base / f), "size": (base / f).stat().st_size}
                for f in sorted(files, key=lambda p: p.as_posix())
            ],
        }
        (out_dir / f"wp-ai-case-{version}.manifest.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        (out_dir / f"wp-ai-case-{version}.zip.sha256").write_text(f"{manifest['zip_sha256']}  {zip_path.name}\n", encoding="utf-8")
    print(f"OK: {zip_path.relative_to(ROOT) if zip_path.is_relative_to(ROOT) else zip_path} files={len(files)} sha256={manifest['zip_sha256']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
