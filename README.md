# DocuSeal Bridge for WordPress (Beta)

Open-source WordPress integration connecting **Elementor Pro**, **Contact Form 7**, and **Gravity Forms** submissions to a configurable DocuSeal server.

**Status: v0.8.0-beta.1 — experimental, not production-ready.** The integrations have not been end-to-end tested against all three form providers. Do not use with real contracts or personal data before validation.

## Features (implemented; require integration testing)

- Configurable DocuSeal HTTPS endpoint and API token
- Form-to-template routing and field mapping
- Calculation pipelines and conditional mapping
- Annual sequential contract identifier (`SH-YYYY-NNNNNN`; currently hard-coded)
- Elementor Pro, Contact Form 7 and Gravity Forms hooks

## Installation for development

1. Create a WordPress staging installation with one of the supported form plugins.
2. Copy this repository directory into `wp-content/plugins/elementor-docuseal-bridge/`.
3. Activate **Elementor DocuSeal Bridge**.
4. Open **Settings → DocuSeal Bridge**; configure a test DocuSeal instance and test template.
5. Submit test-only data and verify mapping, document creation and retries.

## Security and release caveats

- API credentials are stored in WordPress options; use dedicated test credentials and restrict administrator access. Never commit credentials, exports or production form data.
- A timeout after DocuSeal accepts a request may lead to duplicate signature requests on retry. Idempotency needs strengthening.
- Contract ID prefix is currently `SH`, not yet configurable for general-purpose use.
- Contract creation, legally binding consent and payment compliance are the site operator's responsibility.
- Gravity Forms and Contact Form 7 dispatch failures may be logged without being surfaced to the submitter.
- No automated compatibility/security test suite is included yet.

## Roadmap

- End-to-end tests for all three form providers
- Configurable contract ID format
- Reliable retry/idempotency handling and observability
- Internationalization, WordPress Plugin Check and public-release audit

## License

GNU General Public License v2.0 (see [LICENSE](LICENSE)).

## Author

Matthias Uhlig
