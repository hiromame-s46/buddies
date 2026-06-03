# Changelog

## v2.1.0 - 2026-06-03

### Security

- Removed hard-coded passwords from admin utility pages.
- Added environment-variable based admin password configuration.
- Tightened API CORS handling to avoid wildcard credentialed CORS.
- Added security headers for API, admin tools, and image proxy responses.
- Added HTTPS-only image proxy input validation and response size limit.
- Added secure cookie options for session cookies and logout cleanup.
- Expanded `.gitignore` for secrets, uploads, logs, DB dumps, local caches, and dependencies.
- Added `SECURITY.md` for private vulnerability reporting.
- Added GitHub Actions security checks for PHP syntax and common secret patterns.

### Documentation

- Rebuilt `README.md` with Japanese and English project overview.
- Added setup, environment variable, security, open-source hygiene, and release process documentation.

### Notes

- The experimental LiveSoul-like venue seat layout work is not included in this release.
- Existing NEXT LIVE and history behavior remains based on the stable main branch.
