# Security Policy

## Supported Versions

Security fixes are handled on the latest `main` branch and the latest release tag.

## Reporting a Vulnerability

Do not disclose vulnerabilities in public issues or discussions.

Please report security concerns privately to the repository owner/maintainer with:

- Affected page or endpoint
- Reproduction steps
- Impact assessment
- Whether any credentials, tokens, or personal data may be involved

If you accidentally exposed secrets, rotate them immediately before opening a report.

## Sensitive Data

Never commit:

- Database credentials or `config.php`
- `.env` files
- API keys, tokens, certificates, or private keys
- User uploads
- Database dumps
- Production logs

## Operational Expectations

- Run the app over HTTPS in production.
- Configure admin passwords through environment variables.
- Restrict API CORS origins to trusted origins.
- Keep uploaded files and generated runtime markers outside Git.
- Review community event/form features carefully because they can contain user-generated personal data.
