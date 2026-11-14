# Security policy

## Supported versions

The latest release on the `main` branch receives security fixes.

## Reporting a vulnerability

Email hello@indianopinion.org with a description, the affected version and steps to reproduce. Please do not open a public issue for a security problem.

We acknowledge reports within 3 working days and aim to ship a fix, or a clear plan, within 30 days. We will credit you in the release notes unless you ask us not to.

## Scope notes

- Regular expressions are accepted only from users with the plugin's capability (`manage_options` by default). A pattern that exhausts PCRE limits is counted as an error and never stops an import.
- Feed URLs are validated with `wp_http_validate_url()` and fetched through WordPress core, so `reject_unsafe_urls` applies.
- All item text shown in the admin is escaped as text.
