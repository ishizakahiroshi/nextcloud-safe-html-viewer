# Release Process (for maintainers)

This document is for people who cut releases of nextcloud-safe-html-viewer.

> **Always run the 公開前チェックリスト from CLAUDE.md (and at the top of plan_*.md) before any commit or release.**

## Prerequisites

- Clean working tree
- `npm ci && npm run build` succeeds and `js/main.js` is up to date
- `php -l lib/**/*.php appinfo/*.php` passes
- All tests green
- No private strings in the tree (use the rg check from the checklist)

## Steps

1. Update version in:
   - `appinfo/info.xml`
   - `package.json`
   - `CHANGELOG.md` (add new section)

2. Commit the version bump as `chore(release): vX.Y.Z`

3. Tag: `git tag -a vX.Y.Z -m "vX.Y.Z"`

4. Push: `git push && git push --tags`

5. GitHub Actions (`.github/workflows/release.yml`) runs on the tag push and will:
   - Build the tarball
   - Create the GitHub Release and attach `safe_html_viewer-vX.Y.Z.tar.gz`
   - If the App Store secrets are configured (see below), sign the tarball and
     push the release to the Nextcloud App Store automatically. Without the
     secrets that step is skipped and only the GitHub Release is produced.

## Nextcloud App Store

### One-time setup (manual — only the app owner can do this)

1. Log in to https://apps.nextcloud.com with your GitHub account and make your
   email address public on your GitHub profile.
2. Generate a signing key + CSR. Keep the `.key` secret and never commit it:
   ```sh
   mkdir -p ~/.nextcloud/certificates
   openssl req -nodes -newkey rsa:4096 \
     -keyout ~/.nextcloud/certificates/safe_html_viewer.key \
     -out ~/.nextcloud/certificates/safe_html_viewer.csr \
     -subj "/CN=safe_html_viewer"
   ```
3. Submit the `.csr` as a pull request to
   https://github.com/nextcloud/app-certificate-requests and wait for it to be
   signed/merged. You will receive `safe_html_viewer.crt`.
4. Register as the app owner at https://apps.nextcloud.com — paste the `.crt`
   contents and the app-id signature:
   ```sh
   echo -n "safe_html_viewer" \
     | openssl dgst -sha512 -sign ~/.nextcloud/certificates/safe_html_viewer.key \
     | openssl base64
   ```
5. Create an App Store token at https://apps.nextcloud.com/account/token.
6. Add these repository secrets (Settings -> Secrets and variables -> Actions):
   - `APPSTORE_TOKEN` — the token from step 5
   - `APP_PRIVATE_KEY` — the full contents of `safe_html_viewer.key`

### Per release

With the secrets in place, pushing a `vX.Y.Z` tag publishes to the App Store
automatically. Listing metadata (App ID `safe_html_viewer`, license
AGPL-3.0-or-later, summary/description, screenshot URL) comes from
`appinfo/info.xml`. Add screenshots under `screenshots/` and re-enable the
`<screenshot>` element in `appinfo/info.xml` before the first App Store
submission.

If you prefer a manual submission, build the tarball, then go to
https://apps.nextcloud.com/developer/apps and upload it there with the same
metadata (fictional screenshots only).

## App Store notes

- This app is published as free OSS core.
- Paid support, policy packs, or Pro features are handled **outside** the App Store (website, email, etc.).
- Never put customer-specific strings into the App Store description.

## Post release

- Announce on the usual channels (if any).
- Update the "latest" pointers if needed.

See also the internal plan file for historical context.
