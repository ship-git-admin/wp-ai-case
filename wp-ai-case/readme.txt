=== WP AI Case ===
Contributors: shipinc
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.1-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

入力した一次情報と写真から、AI（Claude）で施工事例の下書きを作成・修正し、ACF の現在の定義に合わせて安全に保存します。

== Description ==

* 「AI施工事例 > 新規作成」：一次情報・写真・外部送信への同意 → AI が下書き案 → 画面で修正・AIで修正（選んだ部分だけ）・元に戻す → 下書きとして保存。既存の下書きの修正もできます。
* 「AI施工事例 > 接続・診断」（管理者）：APIキーの有無・ACFマッピング・新規作成枠・推定費用などのローカル診断と、明示操作の接続テスト。
* 「AI施工事例 > マッピング」（管理者）：ACF の構造と保存先の対応の提案・検証・承認。
* 公開・予約・非公開・削除はしません。ACF の定義やテーマは変更しません。
* Advanced Custom Fields PRO が必要です。Claude API のキーは wp-config.php の WP_AI_ANTHROPIC_API_KEY に定義します（画面では入力しません）。
* 更新は GitHub の公開リリースから、WordPress の更新画面で行います（同梱の Plugin Update Checker、MIT）。

== Changelog ==

= 0.1.0 =
* C0: 骨組み、契約 Schema、CaseDocument 検証
* C1-A: ACF 保存アダプタ（12型）、既知マッピング、下書き保存・読み戻し・スナップショット復元
* C1-B: ACF の構造変更の検知・停止・再マッピング・承認・巻き戻し
* C1-C: 接続診断・安全なエラー表示、作成画面、生成ジョブ（分割解析）、型付き修正、既存の下書きの修正、管理画面「マッピング」とステージング検証
* C1-D: 新規作成枠（月5件）、費用の上限（推定）、定期の後片付け
* R1: GitHub リリースからの更新（Plugin Update Checker v5.7）、配布 ZIP の作成・検査
