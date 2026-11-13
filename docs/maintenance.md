# Compatibility checks

The app declares Nextcloud 28 to 35. Nextcloud 33.0.10, 34.0.5 and 35.0.2 are checked with the manually dispatched `nextcloud-smoke.yml` workflow (it also runs on pushes to `ci/nc-compat-*` branches). Versions 28 to 32 are not part of that workflow.

## What the smoke workflow checks

On a disposable Nextcloud instance per major version (images pinned by digest):

- The runtime tree installs, enables, serves `js/main.js` unchanged, and disables.
- A logged-in session (cookie, no request token on the GET) can open `/preview/<fileId>` and `/raw/<fileId>`.
- `/raw` answers with `sandbox allow-scripts allow-popups` and without `allow-same-origin`; visible content stays and an email address is redacted.
- Another user gets 404 for the owner's file; an anonymous request is not served.

It runs the real browser path on purpose. Basic authentication skips parts of the access checks, so it would not show a controller attribute problem.

## Adding a Nextcloud version

1. Find the image tag and digest on Docker Hub (`library/nextcloud`, tag `<version>-apache`) and add a matrix entry.
2. Run the workflow. The workflow widens `max-version` inside the test copy only, so `appinfo/info.xml` is unchanged by the test.
3. Raise `max-version` in `appinfo/info.xml`, the README and the CHANGELOG only after the run passes.

## Notes for the login step

- Do not follow the redirect of the login POST (no `-L`); send `Origin` and `Referer` headers. A passing login answers 303 to `/apps/dashboard/`.
- Print the status and `Location`, never cookie values.
