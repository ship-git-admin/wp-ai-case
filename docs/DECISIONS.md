# 判断記録（実装側）

設計キット v0.6 からの差分と、実装で決めたことを記録する。サイト固有の情報（ホスト名・実ACFのキー等）は書かない。

## D-001 CaseDocument に `relations` を追加 — 契約 v0.6.1 → v0.6.2（2026-11-18）
- 依頼者の指示：関連付け（お客様の声・担当スタッフ・監修者）は**対応必須**。ただし**紐付けがない場合は、そのまま下書きを作ってよい**（同日追記）。
- `contracts/case_document.schema.json` の `relations` はオブジェクトとして必須。3つの配列はどれも空でよい（空＝紐付けなし）。
  - `customer_voice_post_ids`、`staff`（`post_id` と `comment`）、`supervisor_post_ids`。
- 空の関連付けは ACF に**書き込まない**（新規なら未設定、既存の下書きなら値を保持）。
  - 担当スタッフは ACF 側で「最低1人」の設定。空のまま作った下書きは、管理画面で保存するときにスタッフの追加を求められる。
- 値は AI が推測しない。利用者・編集者が選んだ投稿IDだけを受け付ける（入力側の `case_input` への反映は C1-C で行う）。
- 紐付けがあるときは、投稿タイプ・公開状態・件数（ACF の min/max）を PHP が現在のACF定義と照合する。

## D-002 共通テンプレートの既知マッピングは「名前」で持つ
- `Mapping/ShipWorksTemplateV11` はフィールド名・レイアウト名だけを使い、field key は保存時に現在の定義から解決する。
- 同じテンプレート名でも構造が違えば `MAPPING_MISMATCH`（AI-010）で止まる。
- フィールド名の一覧は共通テンプレートの知識として公開リポジトリに含まれる。R1（公開）前に、公開してよいか確認する。

## D-003 行・グループは丸ごと置き換え、最上位の未指定フィールドは保持
- 最上位で指定しなかったフィールドは書かない（既存値を保持）。
- 新しく作るリピーター行・フレキシブル行・グループは、指定のない子フィールドを ACF の既定値で埋めてから保存する（古い値が残らないようにする）。

## D-004 テキストのタグ除去
- テーマが一部の text / textarea をエスケープせずに出力しているため、保存時に text は `sanitize_text_field`、textarea は `sanitize_textarea_field`、wysiwyg は `wp_kses_post` を必ず通す。

## D-005 事実は入力された値だけ
- 概要（Outline）には `status=provided` の事実だけを出す。費用・工期は画像からの推測を検証段階で拒否する。

## D-006 対応済みの型（C1-A 時点）
- text, textarea, wysiwyg, url, image, true_false, select, radio, group, repeater, flexible_content, relationship。
- 値を持たない型：tab, message, accordion。
- それ以外（gallery, number, post_object など）は「未対応」として停止する。設計候補にある gallery は未実装。
- 条件分岐の演算子は `==`, `!=`, `==empty`, `!=empty` のみ。他の演算子のフィールドに値を入れようとしたら停止する。

## D-007 スナップショットと復元
- DB トランザクションには頼らず、書き込み前に投稿メタを丸ごと控え、失敗・読み戻し不一致なら戻す。
- 手動復旧用に最新1件を投稿メタへ保存する（C1-D で専用テーブルへ移す予定）。

## D-008 開発専用ツールは `wp-ai-case/dev/` に置き、配布ZIPに含めない
- 合成データの投入・検証・後片付け、自己テスト、表示確認用の期限付きプレビュー。
- プレビューは印の付いた合成データの下書きだけ、トークンのハッシュのみ保存、最長2時間。DB の投稿状態は変更しない。

## D-009 マッピングの持ち方（C1-B）
- 既知マッピング（`ShipWorksTemplateV11`）は「テンプレートの論理名」で値ツリーを作り、`logical_schema()` でその論理構造を宣言する。
- サイトごとの実際の保存先は、版ごとに不変の **bindings**（論理ノード → field key / 名前の経路 / 型 / 変換）として DB（`wpaic_mappings`）に保存する。状態遷移は `wpaic_mapping_events` に記録する（契約 `acf_mapping` / `mapping_transition`）。
- 結び付けは AI を使わないルールだけで行う：前の版と同じキー → 同じ名前 → 見つからなければ「問題」。**別の親への移動は追わない**（未対応として止める）。
- 登録済みの変換：text→textarea、text/textarea→wysiwyg、select↔radio、group→repeater（1行）。これ以外の型変更は未対応。
- 文脈（context_hash）は「投稿タイプ＋実際に適用されるグループ（key と location）」の指紋。タームが違っても適用グループが同じなら同じ文脈。

## D-010 自動で続けてよい変更は「互換」だけ
- マッピングが使うフィールド・レイアウトの定義が1つも変わらず、使っていない部分だけが変わった場合のみ、構造検証を通して自動で新しい版に引き継ぐ（イベント `compatible_update`）。
- それ以外（キーだけの違い・根本変更・未対応・判断できない）は、その文脈の保存を止め、提案 → 構造検証 → ステージング保存・読み戻し → 実表示の確認 → 管理者承認 を経て再開する。
- テーマだけが変わった場合（schema hash は同じ）は保存を止めず、表示の状態を `recheck_required` として区別する。

## D-011 管理者の操作は当面 WP-CLI（GUI は C1-C）
- `wp ai-case mapping status|propose|list|show|history|confirm-display|approve|retire|rollback`。いずれも `manage_options` が必要。
- 管理画面（旧/新の対比・承認ボタン）は C1-C で、顧客向け画面・接続診断と一緒に作る。

## D-012 ステージング検証用の合成下書き作成は、まだ開発ツール（dev/）
- `wp ai-case poc verify-mapping` は合成データと合成画像を使うため dev/ にある。配布版でも管理者がステージングで検証できるよう、C1-C で合成画像を同梱して本体へ移す。

## D-013 契約 v0.6.2（acf_mapping）
- bindings に `layout_key`（レイアウトの結び付け用）、validation に `tested_theme_fingerprint`、未対応時の `problems` を追加。

## D-014 接続診断（C1-C 前半）
- 生成前のローカル確認（`PreflightService`）は外部通信0・ジョブ0・投稿0。順番：権限 → APIキー（AI-001）→ モデル（AI-004）→ 直前の接続テストで分かった認証・権限・モデルの失敗（AI-002/003/004）→ ACFマッピング（AI-010）。
- 管理者のローカル診断（`DiagnosticsService`、契約 `diagnostic_result`）は外部通信をしない。「キー設定済み」を「接続成功」と表示しない。
- 接続テスト（`ProviderProbe`）は管理者の明示操作だけ。費用の可能性への同意、nonce、300秒の間隔、1日10回まで。ユーザーのデータは送らない。結果は分類コードと日時だけを保存し、24時間かモデル変更で「未確認」に戻す。
- エラーは AI-001〜018 の固定文（`ErrorCatalog`）だけで表示し、詳細はサポートID（uuid v4）で管理者だけが参照する。記録する補足情報は許可したキーだけで、秘密・URL・パス・IP・PHP版を消す（`Redactor`）。
- APIキーは `wp-config.php` の定数 `WP_AI_ANTHROPIC_API_KEY` だけから読む。管理画面での入力・保存・表示はしない。

## D-015 顧客向けの通信は admin-ajax（REST に頼らない）
- 共通テンプレートのサイトには、管理者以外の REST API を止める Code Snippet（oEmbed・Contact Form 7 以外は `rest_disabled`）が入っている。
- そのため顧客向けの操作は `admin-ajax.php`（ログインCookie＋nonce＋施工事例の編集権限、未ログイン用は登録しない）で行う。REST は管理者専用の診断・サポート記録だけ。
- ローカル診断で「管理者以外の REST が止められているか」を確認して表示する。

## D-016 接続テストの送信内容
- 認証とモデルの到達性だけを確かめる最小の呼び出しにする（Models API でモデルを1件取得する想定）。トークンは消費しない見込みだが、レート消費の可能性があるため費用の注意と同意は残す。

## D-017 Claude API の呼び出し方法（確認中）
- 公式の PHP SDK は Composer で入れる形式。WordPress プラグインとして多数のサイトへ配布する場合の扱い（SDK を名前空間を変えて同梱するか、WordPress の HTTP API で直接呼ぶか）を依頼者に確認中。それまでは `PendingProvider`（外部通信なし）を使う。
