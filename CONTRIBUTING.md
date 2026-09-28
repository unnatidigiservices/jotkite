# Contributing to JotKite

Thanks for helping! Bug reports, docs fixes, translations and pull requests are all welcome.

JotKite is maintained by [Unnati Digi Services](https://unnatidigiservices.in). It started as the blog for [GeoRank](https://georank.co.in) client sites, so changes must keep working both inside a GeoRank site (`/blog/`) and standalone.

## Ground rules

- **Keep it drop-in.** No Composer or npm dependencies, no build step. It must run by uploading files to shared hosting with PHP 7.4+.
- **Security first.** Anything that renders user content must go through `pb_sanitize_html()`, every state-changing request must check the CSRF token, and every action must be permission-checked through `pb_can()`.
- **Match the style.** Plain procedural PHP with `pb_` prefixed functions, short comments explaining *why*, and escaping with `pb_e()` on output.
- **Why `pb_`?** JotKite was called *PostBase* until version 0.21.0. The `pb_` / `PB_` prefixes, the addon hooks and the `data/postbase.sqlite` database name keep that original codename on purpose: renaming them would break every addon and existing install. Please keep using them.
- **Schema changes** go in `pb_migrate()` as a new numbered step. Bump `PB_SCHEMA_VERSION` and never edit an old step.

## Contributor License Agreement (required)

JotKite is dual-licensed (AGPL-3.0 and commercial). To keep offering both, we need the right to distribute your contribution under both licenses. By opening a pull request you agree that:

1. You wrote the contribution, or have the right to submit it.
2. You grant Unnati Digi Services a perpetual, worldwide, irrevocable, royalty-free license to use, modify, sublicense and distribute your contribution under the AGPL-3.0-or-later **and** under commercial terms.
3. You keep the copyright to your contribution, and it will always also be available under the AGPL.

Add this line to your pull request description:

```
I agree to the JotKite Contributor License Agreement in CONTRIBUTING.md.
```

## Where to talk

- **Bugs and feature ideas:** [GitHub Issues](https://github.com/unnatidigiservices/jotkite/issues). Include your JotKite version (admin footer), PHP version and steps to reproduce.
- **Questions and how-tos:** [jotkite.com](https://jotkite.com).
- **Security issues:** never in a public issue. See [SECURITY.md](SECURITY.md).
