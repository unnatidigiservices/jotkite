## What does this change?

<!-- A short description, and the issue it fixes if there is one: "Fixes #123" -->

## How was it tested?

<!-- e.g. "Pasted a Word doc with nested lists into the editor (Chrome), saved, checked the public page on mobile" -->

- [ ] Works when JotKite is in `/blog/` (including on a GeoRank site)
- [ ] Works when JotKite is the whole site (domain root)
- [ ] `php -l` passes on changed PHP files
- [ ] User content still goes through `pb_sanitize_html()`, forms still check CSRF, and actions still go through `pb_can()`
- [ ] Schema changes (if any) are a new numbered step in `pb_migrate()`
- [ ] CHANGELOG.md updated

## Contributor License Agreement

- [ ] I agree to the JotKite Contributor License Agreement in [CONTRIBUTING.md](https://github.com/unnatidigiservices/jotkite/blob/main/CONTRIBUTING.md).
