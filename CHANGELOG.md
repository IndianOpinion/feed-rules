# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-14

### Added

- Entry filter `feed_rules_filter_item` and apply action `feed_rules_apply_to_post` for any importer.
- Keyword lists with Unicode whole-word matching, phrases, trailing-`*` prefixes, `any` and `all` modes, case and accent switches.
- Regular expression conditions with flag validation, compile checks and fail-open run-time errors.
- URL prefix conditions matched on host label and path segment boundaries.
- Actions: exclude, include, keep (beats every exclude), categorise (append or replace), tag and author.
- Rule states off, test and live; new rules start in test.
- Source category, author name and source key mapping tables; optional copying of source tags.
- Recent-items store (newest 100 per importer by default) and per-rule daily counters.
- Test screen over recent items and pasted samples, with CSV download; nothing is written by a test run.
- Reference adapter for WordPress core feeds that creates drafts or pending posts.
- Adapter interface and registry.
- WP-CLI commands: list, status, test, export, import, run-feeds, reset-errors.
- Uninstall that removes options, the table, the cron events and the plugin's post meta.

[Unreleased]: https://github.com/IndianOpinion/feed-rules/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/feed-rules/releases/tag/v0.1.0
