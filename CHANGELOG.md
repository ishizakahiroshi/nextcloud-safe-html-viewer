# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.3] - 2026-07-27

### Fixed

- Non-ASCII content (Japanese, other non-Latin scripts, emoji) was served as decimal
  numeric character references. `DOMDocument::saveHTML()` escapes every code point
  >= 0x80, and browsers do not decode those inside `<script>` / `<style>` bodies, so
  previews of pages that build labels in JavaScript or set CSS `content` rendered raw
  `&#21517;` text and broken layout. Non-ASCII runs are now placeholder-swapped around
  serialization, so the served preview keeps the original UTF-8.
- ISO dates in prose were redacted as phone numbers. The phone pattern allows spaces
  and parentheses, so a match carried its surrounding padding and the ISO-date guard
  never fired: `検証資料 2026-07-17` became `検証資料[REDACTED-PHONE]`. Padding is now
  split off before the date check and re-emitted, which also preserves the spacing
  around genuinely redacted phone numbers.
- A leading UTF-8 BOM made the preview come back empty. The BOM ended up between the
  internal encoding declaration and the document, so parsing stopped right after it —
  which hit every file written by an editor that emits a BOM by default (Notepad,
  Excel's web export, PowerShell's `ConvertTo-Html`).
- Base64 `data:` URI payloads were redacted as opaque secrets, blanking out inline
  images. Such a payload is encoded binary, so the heuristics cannot recognise a secret
  in it but do match it by accident; base64 data URIs are now passed through. Data URIs
  that are not base64 stay in scope, since those carry readable text.
- Redaction walked only the first top-level node, so content following a `<script>` or
  `<style>` sibling was served unredacted.
- The `<?xml encoding="utf-8" ?>` prefix used to force UTF-8 parsing leaked into previews.
- Credential patterns (`password=`, `token=`, ...) were only matched inside query
  strings, and the replacement dropped the parameter name. The value is now matched
  as bounded ASCII, so prose in scripts without ASCII word breaks (Japanese, Chinese)
  is not swallowed up to the end of the text node.
- Long-token heuristic no longer swallows a host plus path as a single secret.
- Private/localhost URL matching required a real host boundary, so public hosts such as
  `internal.example.com` are no longer treated as private.
- Bare IPv4 redaction no longer overrides the `[REDACTED-PRIVATE-URL]` label.

### Changed

- The preview endpoint now serves HTML only (`.html` / `.htm` / `text/html` /
  `application/xhtml+xml`) and returns 415 otherwise, rejects files over 5 MiB with 413,
  and maps file-access failures to 404/500 instead of surfacing an exception.
- Content-Security-Policy is extended with `frame-ancestors 'self'; base-uri 'none'`
  alongside the existing `sandbox allow-scripts allow-popups` (still no `allow-same-origin`).
- File action id handling accepts large numeric ids as strings instead of `parseInt`,
  and preview tabs are opened with `noopener,noreferrer`.

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
