# Security policy

## Supported versions

Security fixes go into the latest release. GeoRank sites get them through **Control Center → Upgrade → Blog → Check for Blog Updates**. Other installs should update by replacing the code files, as described in the README under *Updating*.

## Reporting a vulnerability

Please **don't** open a public GitHub issue for security problems.

- Preferred: GitHub's private [**Report a vulnerability**](https://github.com/unnatidigiservices/jotkite/security/advisories/new) form.
- Or: contact us through **[jotkite.com](https://jotkite.com)** and mark the message "Security".

Please include the JotKite version (shown in the admin footer), what an attacker could do, and steps to reproduce. We aim to acknowledge reports within 3 working days and to ship a fix before any public disclosure, and we'll credit you in the changelog unless you'd rather stay anonymous.

## Scope

In scope: this repository's code, including authentication and roles, the HTML sanitizer, uploads, CSRF protection, and the GeoRank sign-on bridge.

Out of scope: vulnerabilities in your hosting setup, PHP itself, or third-party sites embedded in posts (YouTube, Vimeo, Google Maps).
