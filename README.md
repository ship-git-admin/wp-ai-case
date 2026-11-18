# wp-ai-case（開発中）

施工事例（`works`）の下書きを、ACF の**現在の定義**に合わせて安全に作成・保存する WordPress プラグイン。
現在の段階は **C0（骨組み・契約・検査）と C1-A（AIを使わない保存・再読込・表示の実証）** です。AI 接続・顧客向け画面・MCP はまだありません。

## 構成
- `wp-ai-case/` … プラグイン本体（`src/`）、契約 Schema（`contracts/`）
- `wp-ai-case/dev/` … 開発専用（合成データ、自己テスト、表示確認用プレビュー）。**配布ZIPに含めない**
- `tests/contracts/` … 契約 Schema の静的検査
- `tools/secret_scan.py` … 秘密・サイト固有情報の混入検査
- `docs/DECISIONS.md` … 実装で決めたこと

## 検査
```bash
python3 tests/contracts/test_contracts.py
```
```bash
python3 tools/secret_scan.py
```

WordPress 上の試験（ACF Pro が有効な検証環境で、管理者ユーザーを指定）:
```bash
wp ai-case selftest --group=all --user=<admin>
```

## 守ること
- 下書きだけを作成・編集する。公開・予約・非公開・削除、ACF 定義やテーマの変更機能は作らない。
- 実サイトの ACF 原本・ホスト名・APIキー・顧客データをこのリポジトリに入れない。
