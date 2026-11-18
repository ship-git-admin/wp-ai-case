#!/usr/bin/env python3
"""公開候補リポジトリの秘密・サイト固有情報の検査。

Git の追跡対象（未追跡を含む、.gitignore 除外は対象外）を走査し、
APIキー・秘密鍵・サーバー名・実サイトのACFキーなどの混入を検出する。
実行: python3 tools/secret_scan.py            （いまの作業ツリー）
      python3 tools/secret_scan.py --history  （Git の全履歴の全ファイル）
"""
import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
SELF = pathlib.Path(__file__).resolve()

# パターン自体がこのファイルに一致しないよう、分割して組み立てる。
PATTERNS = {
    "Anthropic APIキー": re.compile("sk" + r"-ant-[A-Za-z0-9_\-]{8,}"),
    "秘密鍵": re.compile("-----BEGIN " + r"[A-Z ]*PRIVATE KEY-----"),
    "GitHubトークン": re.compile(r"\bgh[pousr]_[A-Za-z0-9]{20,}"),
    "Xserver/ホスト名": re.compile(r"(check-xserver|xserver\.jp|xsrv\.jp|sv\d{3,6}\.)", re.I),
    "Xserverアカウント": re.compile(r"\bshipinc\d{4}\b", re.I),
    "Hetemlアカウント": re.compile(r"\bship\d{4}\b", re.I),
    "実サイトのACF field key": re.compile(r"\bfield_[0-9a-f]{13}\b"),
    "実サイトのACF group key": re.compile(r"\bgroup_[0-9a-f]{13}\b"),
    "実サイトのACF layout key": re.compile(r"\blayout_[0-9a-f]{13}\b"),
    "APIキーの実値定義": re.compile(r"define\(\s*['\"]WP_AI_ANTHROPIC_API_KEY['\"]\s*,\s*['\"](?!REPLACE_)[^'\"]+['\"]"),
}

FORBIDDEN_PATHS = ("PRIVATE_REFERENCE_NOT_FOR_GIT", "ORIGINAL_ATTACHMENTS", "_private", "_kit", "_ref_theme")
EXTRA_TERMS_FILE = ROOT / ".secret-scan-terms"  # 任意。1行1語（.gitignore 済み）で案件名などを追加できる。


def files():
    out = subprocess.run(
        ["git", "ls-files", "--cached", "--others", "--exclude-standard", "-z"],
        cwd=ROOT, check=True, capture_output=True,
    ).stdout.decode("utf-8")
    return [ROOT / p for p in out.split("\0") if p]


def load_patterns():
    patterns = dict(PATTERNS)
    if EXTRA_TERMS_FILE.exists():
        for line in EXTRA_TERMS_FILE.read_text(encoding="utf-8").splitlines():
            term = line.strip()
            if term and not term.startswith("#"):
                patterns[f"追加語:{term[:2]}…"] = re.compile(re.escape(term), re.I)
    return patterns


def scan_text(text, patterns):
    hits = []
    for lineno, line in enumerate(text.splitlines(), 1):
        for name, pattern in patterns.items():
            if pattern.search(line):
                hits.append((lineno, name))
    return hits


def scan_tree(patterns):
    findings = []
    for path in files():
        rel = path.relative_to(ROOT).as_posix()
        if any(part in rel for part in FORBIDDEN_PATHS):
            findings.append((rel, 0, "公開禁止のディレクトリ"))
            continue
        if path.resolve() == SELF or not path.is_file():
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        findings += [(rel, lineno, name) for lineno, name in scan_text(text, patterns)]
    return findings, len(files())


def scan_history(patterns):
    """全コミットに一度でも入ったファイル（blob）をすべて調べる。"""
    objs = subprocess.run(["git", "rev-list", "--objects", "--all"], cwd=ROOT, check=True, capture_output=True, text=True).stdout.splitlines()
    paths = {}
    for line in objs:
        parts = line.split(" ", 1)
        if len(parts) == 2:
            paths.setdefault(parts[0], parts[1])
    findings = []
    checked = 0
    self_rel = SELF.relative_to(ROOT).as_posix()
    for sha, rel in sorted(paths.items(), key=lambda x: x[1]):
        kind = subprocess.run(["git", "cat-file", "-t", sha], cwd=ROOT, capture_output=True, text=True).stdout.strip()
        if kind != "blob":
            continue
        if any(part in rel for part in FORBIDDEN_PATHS):
            findings.append((f"{rel}@{sha[:8]}", 0, "公開禁止のディレクトリ"))
            continue
        if rel == self_rel:
            continue
        data = subprocess.run(["git", "cat-file", "blob", sha], cwd=ROOT, capture_output=True).stdout
        checked += 1
        try:
            text = data.decode("utf-8")
        except UnicodeDecodeError:
            continue
        findings += [(f"{rel}@{sha[:8]}", lineno, name) for lineno, name in scan_text(text, patterns)]
    return findings, checked


def main() -> int:
    patterns = load_patterns()
    if "--history" in sys.argv[1:]:
        findings, n = scan_history(patterns)
        label = "history_blobs"
    else:
        findings, n = scan_tree(patterns)
        label = "scanned"
    for rel, lineno, name in findings:
        print(f"FOUND {name}: {rel}:{lineno}")
    print(f"{label}={n} findings={len(findings)}")
    return 1 if findings else 0


if __name__ == "__main__":
    sys.exit(main())
