# JotKite on a GeoRank site

## Install (per client site, about 2 minutes)

1. In GeoRank (v1.90+), go to **Control Center → Upgrade → Blog — Unnati PostBase** and click **Install Blog**. GeoRank downloads every file from `app.unnatidigiservices.in/georank/includes/postbase/`, checks each file's sha256, and only then copies them into `public_html/blog/`. Later, **Check for Blog Updates** installs new versions the same way; posts, images and `config.php` are never touched.
   *No outbound HTTPS on the host?* Upload the JotKite folder to `public_html/blog/` by FTP instead.
2. Reload the dashboard. A **📝 Blog** link appears in the top bar. Click it and you're in JotKite as Admin. There's no second password.
3. In JotKite **Settings**, set the blog title and description. Leave Layout on *Automatic*.
4. In GeoRank **Design → Menu**, add an *External* item named "Blog" pointing to `/blog/`, then Save.
5. In GeoRank **Control Center → robots.txt**, regenerate once. GeoRank v1.89+ adds `Sitemap: …/blog/sitemap.xml` automatically. On older GeoRank builds, use JotKite **Settings → Add blog sitemap to robots.txt** instead.
6. Submit `https://site/blog/sitemap.xml` in Google Search Console.

## Publishing a release to Unnati's server

1. Bump `PB_VERSION` in `lib/postbase.php` and add a `## x.y.z` section at the top of `CHANGELOG.md`.
2. Run `php tools/build-release.php`. It lints every PHP file and writes `dist/postbase/`.
3. Upload the **contents** of `dist/postbase/` to `https://app.unnatidigiservices.in/georank/includes/postbase/`: `manifest.json`, `.htaccess` and the new `<version>/` folder. Older version folders can stay.
4. Check that `…/postbase/manifest.json` opens in a browser and shows the new version. Every GeoRank site's **Check for Blog Updates** will now offer it.

Every file is stored as `<name>.txt`, so the host serves it as a download and never runs it, even though `includes/` executes PHP (`fonts.php`).

## How the integration works

| Piece | What happens |
|---|---|
| Sign-in | GeoRank and JotKite share the PHP session cookie. JotKite reads `$_SESSION['georank_auth']` and `$_SESSION['georank_role']` (read-only) and maps GeoRank Admin to JotKite Admin, and GeoRank Editor to the role chosen in Settings (default: Editor). Each maps to one shared JotKite account whose display name can be changed in *My account*. Turn it off in Settings → GeoRank sign-in. |
| Guest writers | Create them in JotKite → Users as *Contributor*. They sign in at `/blog/admin/` with email and password, and never see GeoRank. |
| Look & feel | Blog pages include `meta-global.html`, `header.html` (with `GEORANK_INCLUDE` defined) and `footer.html`, copy the homepage's stylesheet, preload and script tags (so `?v=` cache-busting, fonts, WhatsApp button, back-to-top and App Shell all carry over), and style themselves with the theme tokens `--accent`, `--surface`, `--border`, `--radius`, `--max-w`, `--muted` and `--text`. |
| After a Design change | Nothing to do. The blog reads the live header, footer and stylesheet on every request. |
| Backups | GeoRank's page backups don't cover the blog. Back up `blog/data/postbase.sqlite` and `blog/uploads/`. |

## Rules

- Keep the folder name `blog` unless you have a reason not to. Any name works, but the docs and GeoRank's top-bar link assume `/blog/`.
- Don't create a GeoRank category folder called `blog`.
- A starter page `blog.html` (from GeoRank's scaffold) can stay as it is, or be turned into a redirect to `/blog/` from GeoRank → Redirects.
