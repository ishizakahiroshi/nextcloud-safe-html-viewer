# nextcloud-safe-html-viewer 開発ガイド

> 最終更新: 2026-07-05(日) 00:00:00

> このリポジトリは **公開 OSS**。private 情報を含めず、fresh public clone でも成立する内容だけを置く。
> AI の個人グローバルルール（言語・確認フォーマット・スクリーンショット規約等）はリポジトリ外（各 AI ツールのグローバル設定）に置く。

## プロジェクト概要

**nextcloud-safe-html-viewer** — Nextcloud 上の HTML 資料を **CSP sandbox で安全に表示**し、必要に応じて **秘匿情報を表示時に redaction** して共有事故を減らす Nextcloud カスタムアプリ。単なる HTML viewer ではなく「best-effort safe preview」として打ち出す。

- `text/html` / `.html` の file action を登録し、`/apps/safe_html_viewer/raw/{fileId}` で `Content-Security-Policy: sandbox allow-scripts allow-popups`（`allow-same-origin` なし）付きの HTML を返す。
- Nextcloud ACL を尊重し、ログインユーザーから見える fileId のみ表示する。
- redaction は **表示時変換のみ**で原本ファイルを書き換えない。100% の漏洩防止保証ではなく best-effort であることを明示する。

**ライセンス**: AGPL-3.0-or-later（OSS core）。商用化する場合も App Store 内課金ではなく外部導線で扱う。

**現状**: 公開済み。Nextcloud App Store で `safe_html_viewer` として配布中（v0.1.2、https://apps.nextcloud.com/apps/safe_html_viewer ）。リリースはタグ駆動（`.github/workflows/release.yml`）。経緯・内部メモは `docs/local/` を参照。

## 用語・名称

| 項目 | 値 |
|------|------|
| プロダクト / リポジトリ名 | `nextcloud-safe-html-viewer` |
| Nextcloud app id | `safe_html_viewer` |
| PHP namespace | `SafeHtmlViewer`（`OCA\SafeHtmlViewer`） |
| file action id | `safe-html-viewer` |
| raw route | `/apps/safe_html_viewer/raw/{fileId}` |
| 実装元（private・非公開） | 社内 codebase の既存実装（公開 repo からは参照しない。詳細は `docs/local/`） |

> 移植元は非公開の社内実装という扱い。公開版のコード・ドキュメントに移植元の旧名や private 固有語を残さない。

## 技術スタック

| レイヤ | 採用 |
|------|------|
| アプリ本体 | PHP（Nextcloud App API） |
| 対応バージョン | Nextcloud 28+ / 33 動作前提 |
| ルーティング | `appinfo/routes.php` + `lib/Controller/` |
| redaction | `lib/Service/RedactionService.php`（表示時変換・原本不変） |
| フロント | `@nextcloud/files` v4（file action は `{ nodes }` シグネチャ）+ webpack ビルド |
| ビルド | `npm install && npx webpack --mode production` → `js/main.js` 生成 |
| テスト | PHPUnit（`tests/`） |

## ディレクトリ構成

```
nextcloud-safe-html-viewer/
├─ .github/workflows/release.yml       # タグ駆動リリース
├─ appinfo/
│  ├─ info.xml          # app id / version / summary / min-max version
│  └─ routes.php        # raw route 定義
├─ lib/
│  ├─ AppInfo/Application.php
│  ├─ Controller/ViewController.php   # raw 表示前に redaction 適用
│  └─ Service/RedactionService.php    # redaction ルール本体
├─ js/
│  ├─ src/main.js       # file action 登録（フロントソース）
│  └─ main.js           # webpack 生成物（配布物として追跡する）
├─ l10n/                # 翻訳（ja）
├─ tests/               # PHPUnit（Unit/Service/RedactionServiceTest.php 等）
├─ docs/                # 公開ドキュメント（release.md）
│  └─ local/            # plan / pending / reference 等・非公開（.gitignore 済み）
├─ screenshots/         # App Store 用スクリーンショット（架空サンプルのみ）
├─ README.md / SECURITY.md / CHANGELOG.md / LICENSE
├─ package.json / package-lock.json / webpack.config.js
├─ composer.json / phpunit.xml.dist
└─ CLAUDE.md / AGENTS.md / .gitignore
```

## セキュリティ・公開設計の中核（最優先・常時遵守）

このリポジトリは非公開の社内 codebase から切り出した **公開 OSS**。次を絶対条件とする。

- **private 情報を一切コミットしない**: 顧客名・実ドメイン・サーバー IP / ホスト名・認証情報・実 fileId・運用ログ・移植元の社内固有語（具体的な禁止語リストは非公開の `docs/local/reference_pre-publish-check.md` を参照）。
- **サンプルは架空のものだけ使う**。README・テスト・スクリーンショットに private 実例を載せない。
- **CSP sandbox を弱めない**: `allow-same-origin` を付けない。HTML 内 JS から Nextcloud cookie / DOM / same-origin API を隔離する設計を崩す変更はしない。
- **ACL を尊重する**: ログインユーザーから見えない fileId を表示しない。
- **redaction は best-effort**であり security 保証ではない旨を README / SECURITY.md に明記し続ける。原本ファイルは書き換えない。
- コミット前に必ず「公開前チェックリスト」（後述）を実行する。

## AI 作業共通ルール

ビルド・コミット禁止、secrets-scan 責務、根本原因優先、plan/bugfix/pending md の作成ルール（命名規則・H1 ステータスラベル・最終更新行・context 配分・自走原則）等の AI 作業共通ルールは、各利用者のグローバル AI 設定に従う（作者環境の例: `~/.claude/CLAUDE.md` および `~/.claude/guides/` の `plan_rules.md` 等）。

**本プロジェクト固有の運用**（グローバル側が「各プロジェクトの CLAUDE.md を参照」とする項目）:

- docs 配置: 公開ドキュメントのみ `docs/` 直下。plan / pending / session / reference 等の内部メモ（private 固有語や grep パターンを含み得るもの）は **必ず `docs/local/`**（`.gitignore` 済み・非公開）、対応完了済み・旧版は `docs/local/archive/` へ。
- コードの正しさ確認は指示不要で実行可: `php -l` / `npx eslint` / `vendor/bin/phpunit` 等のテスト・lint。
- plan 自走の停止条件 3 番目（プロジェクト固有分）: 重大なテスト退行、または公開セキュリティ要件（sandbox CSP / ACL 尊重 / redaction best-effort・原本不変）と矛盾する実装が必要になった。
- context 分割例: C1 `appinfo/info.xml` / `routes.php` / `lib/AppInfo/Application.php` 骨格（他が依存）→ C2 `lib/Controller/ViewController.php` raw 表示 + CSP [C1 後] → C3 `lib/Service/RedactionService.php` + `tests/` [C2 後] → C4 `js/src/main.js` file action 登録 [C1 と型同期、C2/C3 と並列OK]。

## 公開前チェックリスト（コミット前に必ず実行）

- 認証情報・社内固有語が grep で出ない（一般語に加え、プロジェクト固有の禁止語リストは `docs/local/reference_pre-publish-check.md` を使う。テストの架空サンプルは除く）。
- `git status --short` で意図したファイルだけが変更されている。
- `npm install && npx webpack --mode production` が通り `js/main.js` が生成される。
- `php -l` が全 PHP ファイルで通る。
- Nextcloud 33 に手動配置して `occ app:enable safe_html_viewer` が通る。
- `.html` クリックまたは file action で sandbox 表示になる（CSP に `allow-same-origin` が無い）。
- redaction の before / after サンプルが README に載っている（架空サンプル）。

## 参照リンク

| 項目 | パス |
|------|------|
| Codex 用エントリポイント | [AGENTS.md](AGENTS.md)（ローカル補足があれば `AGENTS.local.md`） |
| 公開ドキュメント | ルートの `README.md` / `SECURITY.md` / `CHANGELOG.md`、`docs/release.md` |
| 内部計画・調査（非公開） | `docs/local/`（`.gitignore` 済み） |
