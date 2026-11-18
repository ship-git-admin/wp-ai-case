# リリースと更新・巻き戻しの手順

## 前提
- 配布は GitHub の **公開** リポジトリのリリースに添付した ZIP（`wp-ai-case-X.Y.Z.zip`）だけ。各サイトのプラグインは同梱の Plugin Update Checker で12時間ごとにリリースを確認し、WordPress の「更新」画面に表示する。
- GitHub が自動で作るソース一式の ZIP は使わない（開発用のファイルを含むため。プラグインは添付の ZIP がないリリースを無視する）。
- 認証トークンは使わない（各サイトに秘密を置かない）。リポジトリが非公開の間は、各サイトに更新は表示されない。
- **リポジトリの公開・リリースの公開・顧客サイトへの導入は、それぞれ人の事前承認を得てから行う。**

## 版を出す
1. `wp-ai-case/wp-ai-case.php` のヘッダー `Version`・定数 `WP_AI_CASE_VERSION`・`readme.txt` の `Stable tag` を同じ X.Y.Z にし、`CHANGELOG.md` と `readme.txt` の Changelog を書く。
2. 手元で検査する。
   - `python3 tests/contracts/test_contracts.py`
   - `python3 tools/secret_scan.py` と `python3 tools/secret_scan.py --history`
   - `python3 tools/build_release.py --expect-tag vX.Y.Z`
3. ステージングで自己テスト（`wp ai-case selftest --user=<管理者>`）と、画面の確認（作成・修正・保存・プレビュー、PC/スマホ）。
4. タグ `vX.Y.Z` を push すると、CI が同じ検査をして **下書き（draft）** のリリースに ZIP・manifest・SHA-256 を添付する。
5. 人が内容（添付の ZIP の SHA-256・変更履歴）を確認して **Publish release**。ここで初めて各サイトに更新が表示される。

## 各サイトでの更新
- 管理画面「プラグイン」または「ダッシュボード > 更新」で「今すぐ更新」（1クリック）。WP-CLI なら `wp plugin update wp-ai-case`。
- 更新しても、マッピング・ジョブ・新規作成枠・使用量（独自テーブル）と、作った下書き・設定（wp-config.php の定数）は残る。テーブルの更新が必要な版では、読み込み時に自動で更新する（`Installer::maybe_install`）。
- 更新後に「AI施工事例 > 接続・診断」と「マッピング」を開き、状態が「正常／利用できます」であることを確かめる。ACF の構造との不一致が出たら、マッピング画面の手順で作り直す（保存は自動で止まる）。

## 失敗したとき
- ZIP の取得・展開に失敗した場合、WordPress は元の版のまま（または自動で元に戻す）。プラグインは前の版で動き続ける。
- 原因（ネットワーク・ZIP の破損・権限）を確かめて、もう一度更新する。

## 巻き戻し（前の版に戻す）
1. GitHub のリリース一覧から、前の版の `wp-ai-case-X.Y.Z.zip` を取得する（SHA-256 を確認）。
2. 管理画面「プラグイン > 新規追加 > プラグインのアップロード」でその ZIP を選び、「現在のものをアップロードしたもので置き換える」。WP-CLI なら `wp plugin install wp-ai-case-X.Y.Z.zip --force`。
3. データ（マッピング・下書き・枠）は残る。新しい版でテーブルの形が変わっていた場合は、前の版が使わない列は無視される（列を消さない作りにする）。
4. 全サイトで問題がある版は、GitHub でそのリリースを Draft に戻し（各サイトの「最新」から外す）、修正版を出す。
