# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 0.1.x   | :white_check_mark: |

## Reporting a Vulnerability

Please report security issues **privately** via GitHub Security Advisories (preferred) or by opening a private security issue if the repo supports it.

**Do not** use public issues for vulnerabilities.

Topics of special interest for this app:

- Bypass of the `sandbox` CSP (especially attempts to regain same-origin access)
- Leaks through redaction false-negatives that are trivial to trigger
- Path traversal or ACL bypass in the raw controller
- Issues allowing the served HTML to affect the surrounding Nextcloud UI

When reporting, please include:
- Nextcloud version + PHP version
- Steps to reproduce (use only fictional data)
- Impact assessment

We treat redaction bypasses as **informational / best-effort improvement** rather than critical security bugs, unless they also break the sandbox or ACL guarantees.

## Design Security Notes

- The raw route always returns `Content-Security-Policy: sandbox allow-scripts allow-popups` **without** `allow-same-origin`.
- File access goes exclusively through the caller's `IRootFolder` view → ACLs are inherited from Nextcloud.
- Redaction runs only in memory on the response path. No writes back to storage.
- This is explicitly documented as best-effort preview, not a confidentiality guarantee.

## History

See CHANGELOG.md for past releases.
