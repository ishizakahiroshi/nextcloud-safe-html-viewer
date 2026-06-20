# nextcloud-safe-html-viewer 開発ガイド

> 最終更新: 2026-06-19(金) 22:31:23

> このリポジトリは **公開 OSS**。private 情報を含めず、fresh public clone でも成立する内容だけを置く。
> AI の個人グローバルルール（言語・確認フォーマット・スクリーンショット規約等）はリポジトリ外（各 AI ツールのグローバル設定）に置く。

## プロジェクト概要

**nextcloud-safe-html-viewer** — Nextcloud 上の HTML 資料を **CSP sandbox で安全に表示**し、必要に応じて **秘匿情報を表示時に redaction** して共有事故を減らす Nextcloud カスタムアプリ。単なる HTML viewer ではなく「best-effort safe preview」として打ち出す。

- `text/html` / `.html` の file action を登録し、`/apps/safe_html_viewer/raw/{fileId}` で `Content-Security-Policy: sandbox allow-scripts allow-popups`（`allow-same-origin` なし）付きの HTML を返す。
- Nextcloud ACL を尊重し、ログインユーザーから見える fileId のみ表示する。
- redaction は **表示時変換のみ**で原本ファイルを書き換えない。100% の漏洩防止保証ではなく best-effort であることを明示する。

**ライセンス**: AGPL-3.0-or-later（OSS core）。商用化する場合も App Store 内課金ではなく外部導線で扱う。

**現状**: 非公開の社内 codebase にある既存実装を、公開版 `safe_html_viewer` として切り出し中。詳細・実行順序は計画ファイル（`docs/local/` または `docs/` 配下の `plan_*.md`）を参照。

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

## ディレクトリ構成（想定）

```
nextcloud-safe-html-viewer/
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
├─ tests/               # PHPUnit（redaction ルール等）
├─ docs/                # 公開ドキュメント（release 手順など）
│  └─ local/            # plan / pending / reference 等・非公開（.gitignore 済み）
├─ screenshots/         # App Store 用スクリーンショット（架空サンプルのみ）
├─ README.md / SECURITY.md / CHANGELOG.md / LICENSE
├─ package.json / webpack.config.js
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

## 作業運用ルール（AI 共通）

- **ビルド・コミット・タグ・プッシュ・リリースはユーザーの明示指示があるまで AI から実行・提案しない**。
  - 対象: `npm run build` / `npx webpack` / `composer install` / `git commit` / `git push` / `git tag` / `occ app:enable` 等。
  - 例外: ユーザーが明示的に「ビルドして」「コミットして」等と指示した場合のみ。
  - 完了報告では「ビルドしますか?」「コミットしますか?」のような提案・確認質問を出さず、コード変更の要約だけ伝える。
- **コードの正しさ確認は対象外**（必要に応じ実行可）: `php -l`（構文チェック）、`npx eslint`、`vendor/bin/phpunit` 等のテスト・lint。
- **根本原因を優先**する。テストを通すための skip / ハードコード / 例外握り潰しをしない。やむを得ず場当たり対応する場合は (a) コメント・報告に明記し (b) `pending_*.md` に根本対応を残す。

## plan・docs 作業ルール（必須・自己完結）

`docs/` 配下の `.md` を新規作成・更新する作業、`plan_*.md` を作成・実行する作業に着手する前に、本セクションに従うこと。

### docs ファイル配置

| ディレクトリ | 内容 | 公開 |
|---|---|---|
| `docs/` 直下 | 公開ドキュメント（release 手順・アーキテクチャ説明など）。初見の管理者向け | 公開 |
| `docs/local/` | plan / pending / session / reference 等の内部メモ | **非公開**（`.gitignore` 済み） |
| `docs/local/archive/` | 対応完了済み・旧版ドキュメント置き場 | 非公開 |

> 内部計画・調査メモ（private 固有語や grep パターンを含み得るもの）は **必ず `docs/local/`** に置く。公開を意図したドキュメントだけ `docs/` 直下に置く。

### 命名規則（`.md` のみ対象）

| プレフィックス | 用途 |
|---|---|
| `plan_*.md` | 計画・対応中（着手予定の作業） |
| `bugfix_<topic>_YYYY-MM-DD.md` | 障害対応記録 |
| `pending_*.md` | 対応保留・外部依存待ち・将来再検討 |
| `setup_*.md` | 初回構築・環境設定（再実行が想定されない一回作業） |
| `manual_*.md` | 繰り返し参照する運用手順 |
| `reference_*.md` | 繰り返し参照する定義・一覧（更新義務あり） |
| なし | 記録用の通常ドキュメント |

### H1 ステータスラベル（必須）

H1 タイトル先頭にステータスラベルを付ける（`> ステータス:` 行は書かず H1 に集約）。

- `plan_*.md`: `[計画]` → `[実行中]` → `[様子見]` → `[完了]`（＋ `[廃止]`）
- `bugfix_*.md`: `[対応中]` → `[様子見]` → `[完了]`（＋ `[廃止]`）
- `pending_*.md`: `[保留]` 固定

### 最終更新日時（必須）

`docs/` 配下と `CLAUDE.md` 等すべての `.md` は、H1 見出し直後に `> 最終更新: YYYY-MM-DD(曜) HH:MM:SS` を記載する。

### context 配分（plan_*.md）

実装量が多い `plan_*.md` は **context（独立した実行単位）** を `C1`, `C2`, `C3` … の純粋連番で分割し、ファイル先頭（H1 直下・概要より前）に `## context配分` 表を必ず置く。

- **context の種別は `plan` / `fix` の2値のみ**（`verify` / `survey` / `audit` 等は使わない）。
- `C2a` / `C2b` のような細分化表記は禁止。分割が要れば番号を振り直す。
- 「Step 1 / Step 2」表記は使わない（必ず C 番号）。
- 依存のある変更は先工程ほど若い番号に。同時着手できるものは `[並列OK]` を明示。
- 実行順序は `C1 → C2 → (C3, C4) → C5` のように矢印で示し、並列 OK を括弧でまとめる。
- context 完了時は `## context配分` 表の該当行を `plan` → `fix` に更新する（実行結果セクションへの記述だけでは不足）。

**並列 OK 条件**: ①編集ファイルが重複しない ②一方の成果物を他方が参照しない ③共有リソース（route / namespace / 設定スキーマ等）の同時変更でない。

**本プロジェクト固有の分割例**:
- C1: `appinfo/info.xml` / `routes.php` / `lib/AppInfo/Application.php` 骨格（他が依存）
- C2: `lib/Controller/ViewController.php` raw 表示 + CSP [C1 後]
- C3: `lib/Service/RedactionService.php` redaction 本体 + `tests/` [C2 後]
- C4: `js/src/main.js` file action 登録 [C1 と型同期、C2/C3 と並列OK]

### 自走・停止条件（plan 実行時）

plan に書かれた作業は「ユーザー承認済み」とみなし、C 単位で確認待ちを挟まず自走する（C 完了 → 次 C 自動進行）。軽微な迷い・命名揺れは plan 記載 or 既存実装に倣って自走し、確認に倒さない。**停止するのは次の3つのみ**:

1. plan 記載と矛盾する破壊的変更が必要になった。
2. 外部依存の致命的障害（Nextcloud API 非互換・PHP/webpack ビルド不能など plan 内で解決不能）。
3. 重大なテスト退行、または公開セキュリティ要件（sandbox CSP / ACL 尊重 / redaction best-effort・原本不変）と矛盾する実装が必要になった。

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
| 公開ドキュメント | `docs/`（`README.md` / `SECURITY.md` / `CHANGELOG.md`） |
| 内部計画・調査（非公開） | `docs/local/`（`.gitignore` 済み） |
