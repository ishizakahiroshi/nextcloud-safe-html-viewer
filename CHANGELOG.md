# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.2] - 2026-07-03

### Fixed

- App Store description rendered raw HTML tags as text: the App Store treats
  `info.xml` description as Markdown, so the description is now written in
  Markdown instead of HTML.

## [0.1.1] - 2026-07-03

### Changed

- Expanded the App Store description (features list, sandbox behaviour, limitations).
- Unified author/copyright attribution to "Hiroshi Ishizaka (ishizakahiroshi)" across `info.xml`, `composer.json`, `package.json` and `LICENSE`.

## [0.1.0] - 2026-06-19

### Added

- Initial public release of `safe_html_viewer`.
- File action registration for `.html` / `text/html` files.
- `/apps/safe_html_viewer/raw/{fileId}` endpoint returning HTML under strict CSP sandbox (`sandbox allow-scripts allow-popups`, no `allow-same-origin`).
- ACL enforcement via user folder view (only accessible files are returned).
- `RedactionService` with best-effort display-time redaction for:
  - Email addresses
  - Phone-like strings
  - IPv4 addresses
  - Private/localhost URLs
  - Credential query patterns (`password=`, `token=`, `api_key=`, ...)
  - Long opaque token-like strings
- Redaction only affects the served preview; original file content is untouched.
- Basic PHPUnit coverage for redaction rules.
- Documentation: README, SECURITY, CHANGELOG, AGENTS/CLAUDE guidance files.

### Security

- Sandbox is intentionally restrictive.
- Redaction is documented as best-effort (see README and SECURITY.md).

[0.1.0]: https://github.com/ishizakahiroshi/nextcloud-safe-html-viewer/releases/tag/v0.1.0
