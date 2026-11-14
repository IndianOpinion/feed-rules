# Contributing

Thank you for helping. Small, focused pull requests are the easiest to review.

## Setup

```
git clone https://github.com/IndianOpinion/feed-rules
cd feed-rules
composer install
```

PHP 8.1 or later with `mbstring` is needed. `intl` is optional. No WordPress install is needed to run the tests: they use the stub layer in `tests/stubs/`.

## Tests and checks

```
composer test      # PHPUnit 10
composer phpstan   # PHPStan level 8
composer phpcs     # PSR-12
composer check     # all three
```

All three must pass before a pull request is merged. CI runs them on PHP 8.1, 8.2 and 8.3, so do not use syntax newer than PHP 8.1.

## Style

- PSR-12, `declare(strict_types=1);` in every PHP file.
- Typed exceptions, never silent failure, never the `@` operator, no `eval`, no shell.
- Escape every value printed in the admin. Check the capability and a nonce on every form.
- Every failure path keeps the item. A rule that cannot run must not drop work.
- Plain British English in docs and messages. No em dashes.
- A behaviour change needs a test that names the case it proves, and a line in `CHANGELOG.md` under Unreleased.

## Pull requests

- Branch from `main`, open the pull request against `main`.
- Describe what changes for an operator and how you tested it.
- Keep unrelated changes out.
- No sign-off line is required.

Adapters for specific importers belong in their own packages, written against the `Adapter` interface. This repository ships only the reference adapter for WordPress core feeds.
