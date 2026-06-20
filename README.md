# Safe HTML Viewer

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)

Nextcloud app to **safely preview HTML files** stored in your instance.

- Opens `.html` / `text/html` files via a dedicated route under strict **CSP sandbox** (`sandbox allow-scripts allow-popups`, **no `allow-same-origin`**).
- Applies **best-effort redaction** of emails, phone numbers, private IPs/URLs, and credential-like strings at *display time only*.
- The original file is **never modified**.
- Respects Nextcloud ACLs (only files you can see are served).

Intended as a **"best-effort safe preview"** helper to lower the chance of accidental secret leaks when sharing or viewing HTML documents inside Nextcloud. It is **not** a full DLP or security product.

## Features (MVP)

- File action on HTML files (right-click → "Safe HTML preview")
- Sandboxed rendering (scripts inside the previewed HTML can run, but the preview page cannot access your Nextcloud cookies or same-origin APIs)
- Redaction rules (applied on the fly):
  - Email addresses
  - Phone-like numbers
  - IPv4 addresses
  - Private network URLs (`192.168.x.x`, `10.x`, `localhost`, etc.)
  - Common secret patterns (`password=`, `token=`, `api_key=`, long base64-ish strings)
- Clean separation: redaction happens in the viewer layer only

## Limitations (important)

- Redaction is heuristic-based and best-effort. Sophisticated or obfuscated secrets can still leak.
- JavaScript inside the viewed HTML runs inside the sandbox. Malicious HTML can still attempt other things (popups, etc.).
- Not a replacement for proper secret management or access control.
- Only HTML is supported in v0.x. Markdown, PDF, Office formats are out of scope for initial release.

See [SECURITY.md](./SECURITY.md) for reporting and more details.

## Installation

### From GitHub Release (recommended for manual install)

1. Go to Releases and download the `safe_html_viewer-vX.Y.Z.tar.gz` (or .tar.gz.sig if you verify signature).
2. Extract to your Nextcloud `apps/` directory as `safe_html_viewer/`.
3. Run:
   ```sh
   cd /path/to/nextcloud
   php occ app:enable safe_html_viewer
   ```
4. (Optional) For production, run `php occ maintenance:repair` and clear caches if needed.

### From source (developers)

```sh
git clone https://github.com/ishizakahiroshi/nextcloud-safe-html-viewer.git safe_html_viewer
cd safe_html_viewer
npm install
npm run build   # produces js/main.js
```

Place the folder under `apps/safe_html_viewer` of your Nextcloud and enable the app.

Requires:
- Nextcloud 28 – 33
- PHP 8.0+

## Usage

1. Upload an `.html` file.
2. In the Files app, right-click the file → **Safe HTML preview** (or click the file action).
3. A new tab opens with the sandboxed + redacted content.

Example before/after (fictional data):

**Original snippet**
```html
<p>Admin contact: admin@internal.corp.example</p>
<p>API endpoint: https://192.168.10.50/api?token=eyJhbGciOi...</p>
```

**After redaction (what the viewer shows)**
```html
<p>Admin contact: [REDACTED-EMAIL]</p>
<p>API endpoint: [REDACTED-PRIVATE-URL]?token=[REDACTED]</p>
```

## Configuration

Currently no admin UI or per-user settings (MVP).

Redaction rules are fixed in `lib/Service/RedactionService.php`. Pull requests to improve heuristics are welcome.

## Development

See the internal plan and `CLAUDE.md` (not shipped in releases).

Build the frontend:
```sh
npm install
npm run build
```

Run unit tests (after `composer install`):
```sh
vendor/bin/phpunit
```

## License

AGPL-3.0-or-later

See [LICENSE](./LICENSE).

## Security

See [SECURITY.md](./SECURITY.md).

## Contributing

Issues and PRs welcome on GitHub.

When contributing, remember:
- Never include real customer data, domains, or credentials in examples or tests.
- Keep the sandbox strict (`allow-same-origin` must stay disabled).
- Redaction must remain display-time only.
