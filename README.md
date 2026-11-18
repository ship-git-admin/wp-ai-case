# wp-ai-case（開発中）

施工事例（`works`）の下書きを、ACF の**現在の定義**に合わせて安全に作成・保存する WordPress プラグイン。
現在の段階は **C0〜C1-C**（保存の実証、ACF の差分・根本変更からの復旧、接続診断、顧客向けの作成画面・生成・修正・下書き保存）です。新規作成枠・費用の上限・配布（C1-D / R1）と MCP（M1）はまだありません。

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

## 使い方（概要）
- 「AI施工事例 > 新規作成」：一次情報・写真・同意を入力 → AI が下書き案を作成 → 画面で修正・AI で修正・元に戻す → 「下書きとして保存」。
  - 「既存の下書きを修正」：この画面で保存した下書きを開き、修正して「下書きを更新」。変わった部分だけを書き換え、保存後に編集画面で変えた部分に重なる修正は保存しない。
  - 新規作成は1サイト・暦月あたり5件まで（作成の開始で予約、保存成功で確定、取消し・期限切れで戻る）。修正・再試行は数えない。
- 「AI施工事例 > 接続・診断」（管理者）：使う AI と APIキー（`wp-config.php`）・モデル・ACFマッピング・REST・新規作成枠・推定費用・後片付けの確認と接続テスト。
- 「AI施工事例 > マッピング」（管理者）：ACFの構造と保存先の対応の確認・提案・検証用の下書きでの保存確認・表示確認・承認・退役・巻き戻し・履歴。

## 使う AI の切り替え（`wp-config.php`）
Claude（既定）と OpenAI を切り替えられます。モデルはそれぞれ固定です。キーは選んだ AI の定数だけを読みます。

| 定数 | 値 | 内容 |
|---|---|---|
| `WP_AI_CASE_PROVIDER` | `anthropic`（既定）／`openai` | 使う AI |
| `WP_AI_ANTHROPIC_API_KEY` | （キー） | Claude（Claude Haiku 5.5）を使うとき |
| `WP_AI_OPENAI_API_KEY` | （キー） | OpenAI（GPT-6 Luna）を使うとき |

切り替えたら「AI施工事例 > 接続・診断」で接続テストを行ってください。作成画面の同意文（送信先）も切り替わります。

## 上限の設定（任意。`wp-config.php`）
| 定数 | 既定 | 内容 |
|---|---|---|
| `WP_AI_CASE_MONTHLY_QUOTA` | 5 | 1サイト・暦月あたりの新規作成枠 |
| `WP_AI_CASE_MONTHLY_USD_LIMIT` | 1.0 | 月の推定費用（USD）の上限。超える見込みなら送信しない（AI-014）。0 で上限なし |
| `WP_AI_CASE_REQUEST_USD_LIMIT` | 0.2 | 1回の依頼の推定費用（USD）の上限 |
| `WP_AI_CASE_DAILY_REVISE_LIMIT` | 50 | 1日のAIによる修正の回数 |

推定費用は応答のトークン数と料金表（版付き）から計算した目安です。全体の実額は AI サービス側（Anthropic の Workspace／OpenAI のプロジェクト）の上限でも守ってください。

## 定期の後片付け
期限切れのジョブ・新規作成枠の回収・保存の中断からの復帰・古い記録の削除を行います（外部通信なし）。WP-Cron（毎時）で動きますが、Basic認証などで WP-Cron が動かない環境では、サーバーの cron から毎時実行してください。

```bash
wp ai-case maintenance
```

## ACFマッピングの運用（管理者）
保存は「その文脈の承認済みマッピングの版」と現在のACF定義が一致するときだけ行われます。

管理画面「AI施工事例 > マッピング」で、提案 → 検証用の下書き（トップの写真3パターン）で保存・読み戻し → プレビューで実表示を確認 → 承認、の順に進められます。WP-CLI でも同じ操作ができます。

```bash
wp ai-case mapping status --user=<admin>
```
```bash
wp ai-case mapping propose --user=<admin>
```
提案した版は、ステージングで保存・読み戻し → 実表示の確認（`mapping confirm-display`）→ 承認（`mapping approve`）の順に進めます。履歴は `mapping history <mapping_id>`、以前の版へ戻すときは `mapping rollback` を使います。

## 配布と更新
配布用の ZIP は `python3 tools/build_release.py --expect-tag vX.Y.Z` で作り、検査します（dev/ と Markdown は含めません）。タグを push すると CI が下書きのリリースを作り、人が確認して公開すると各サイトの更新画面に表示されます。手順・失敗時・巻き戻しは [docs/RELEASE.md](docs/RELEASE.md)、脆弱性の報告は [SECURITY.md](SECURITY.md)。

## 守ること
- 下書きだけを作成・編集する。公開・予約・非公開・削除、ACF 定義やテーマの変更機能は作らない。
- 実サイトの ACF 原本・ホスト名・APIキー・顧客データをこのリポジトリに入れない。
