# Changelog

## 0.22.0 — 2026-09-29

- **"Put JotKite on your home screen."** On phones, the admin shows a small banner until it's dismissed, and never inside the installed app.
  - **Android (Chrome, Edge, Samsung):** a real one-tap **Install** button.
  - **iPhone:** Apple doesn't let web pages install themselves, so the banner shows the Safari steps with the Share icon.
  - My account always has a **JotKite on your phone** card with the steps (and the Install button where possible).
  - A minimal service worker makes the admin installable in Chrome. It caches nothing: pages always come from the network, and only a failed page load shows a small "You're offline" page.
- **Text alignment in the editor:** align left, centre and right buttons after Quote. The active alignment is highlighted.
  - Alignment is the only styling kept from a `style` attribute: centre or right, on paragraphs, headings, list items, quotes and table cells. Everything else is still stripped.

## 0.21.1 — 2026-09-29

- **`/docs/` and `/tools/` can be page addresses.** They were blocked, because the repository has folders with those names, and a page saved as "docs" was renamed "docs-1".
  - Now only the files inside those folders are blocked. The names are free for your own pages.
  - An untouched `.htaccess` that JotKite wrote itself (0.20–0.21.0) is updated automatically. An edited one is never changed; copy the new rules from the repository's `.htaccess`.
  - Nginx users: see the updated rules in the README.
- **No GeoRank wording on plain installs.** Settings → General and → Navigation only show the GeoRank options (layout, GeoRank sign-in, the slim menu bar, "GeoRank site detected") on a GeoRank site. Elsewhere they're hidden, and their saved values are kept.

## 0.21.0 — 2026-09-28 · JotKite

**PostBase is now JotKite.** Same software, new name, to avoid confusion with other products called PostBase.

- New home: **[jotkite.com](https://jotkite.com)**. Code: **[github.com/unnatidigiservices/jotkite](https://github.com/unnatidigiservices/jotkite)**; the old GitHub address redirects there.
- **New logo:** a long pen nib shaped like a kite, white on the brand gradient tile. It's used in the admin sidebar, on the sign-in page, as the favicon (SVG with a PNG fallback) and as the home-screen app icons.
  - The "Jot**Kite**" wordmark is text, so it stays sharp at any size, with a dark-mode version.
- The new name appears everywhere people see it: page titles, the sign-in page, the upgrade notice, the admin footer, the app manifest, "Powered by" credits, the `generator` meta tag, RSS, the demo, the README and the docs.
- **Existing sites:** Admins see a one-time **"PostBase is now JotKite"** notice after updating. Posts, images and settings are untouched.
- **Unchanged on purpose:** the internal `pb_` / `PB_` names, the addon hooks and the database file `data/postbase.sqlite` keep the original codename, so every addon and existing install keeps working. See CONTRIBUTING.md.
- The M1 theme is now called *JotKite M1*, and its gradient option *JotKite gradient*.
- The admin page title now has the missing space ("Posts · Blog").

## 0.20.1 — 2026-09-27

- **Fixed: on some GeoRank sites the blog redirected to the homepage.** GeoRank's include files send direct visitors to `/` unless `GEORANK_INCLUDE` is defined. Newer sites have that guard in `meta-global.html` too, and PostBase defined the constant only after including that file, so every blog page redirected home. The admin, RSS and sitemap were not affected. The constant is now defined before the first include.

## 0.20.0 — 2026-09-27

- **The PostBase gradient.** The admin (desktop and phone) and the sign-in page now sit on the PostBase backdrop: soft periwinkle and orchid glows on lavender, with a faint dot grid. It has a matching dark-mode version.
- **M1 theme 1.1.0.** The outside background on wide screens is the same gradient by default. A new *Outside background* option offers *PostBase gradient* or *Solid colour*. Sites that had already picked their own solid colour keep it.
- **Media library in the editor.** The Image button opens a picker: choose any uploaded image (thumbnails, search, *Load more*) or **⬆ Upload new**. Featured image, social image and favicon fields get a **Media library** button too.
- **A default category for each writer.** My account → *Default category for my new posts* is preselected on every new post and can still be changed per post. Deleting the category clears the default.
- Database schema v4 (`users.default_category_id`), applied automatically.
- **Fixed: installing from GeoRank failed with "HTTP 403 for .htaccess.txt".** Hosts and CDNs, including Hostinger's, refuse to serve any file named `.ht*`, even as a `.txt` download.
  - Releases no longer contain dot-files.
  - PostBase now also recreates its main `.htaccess` (clean URLs, blocked internals) on the first request, as it already did for `data/`, `lib/` and `uploads/`.
  - An existing `.htaccess` is never overwritten.

## 0.19.0 — 2026-09-26

- **Link dialog (WordPress-style)** replaces the old address prompt. Open it with 🔗 or Ctrl+K.
  - Paste an address, or type a few words to search this blog's **published posts and pages**. No drafts, categories or media are offered. Use the arrow keys and Enter, or tap a result.
  - *Open in a new tab* adds `target="_blank" rel="noopener"`.
  - If the cursor or selection touches a link, even partly, the dialog edits that link and shows **Unlink**. Unlink keeps the text.
  - With nothing selected, the link is inserted with the post's title (or the address) as its text.
- **Toolbar:** the Image button now comes right after H3, so it's visible on a phone without scrolling.
- **Phones:** at the end of the menu or the toolbar, the › hint turns into a ‹ on the left to scroll back.
- **"View blog ↗"** is now a button right below *My account*, and on phones at the end of the menu.
- **Posts:** a **✏️ Write new** button right after the status tabs. **Pages:** *+ New page* moved there too, instead of the far right corner, where it was off-screen on phones.

## 0.18.0 — 2026-09-26

- **Self-resetting demo mode** for a public "try it" site. Set `demo`, `demo_reset_minutes` and `demo_key` in `config.php` (see the README).
  - Visitors sign in with one click as Admin, Editor or Contributor. The demo accounts have no usable password.
  - Every N minutes the database and `uploads/` are restored from a snapshot in `data/demo/`. The restore happens before the database is opened, and a lock ensures only one request does it.
  - The first visit creates the demo accounts, sample posts (featured, photo, pending review, a Page) and the first snapshot.
  - Locked for visitors: Settings → Code (it would run for everyone), writing robots.txt, and the demo accounts' passwords, emails, roles and active status.
  - Public pages are `noindex` and show a "Try the admin →" bar. The admin shows a banner with the time until the next reset.
  - Settings → Demo (owner, unlocked with `demo_key`): *Save current content as the starting point*, *Reset now*, and *Lock*.
- **Phones: a scroll hint.** The menu and the editor toolbar scroll sideways on phones. A small animated › at the right edge now shows there's more; it fades out at the end, and tapping it scrolls along.
- Phones: long pasted links and wide tables no longer widen the editor or the post list. Links wrap, and tables scroll inside themselves.
- Fixed: the "Read more →" button on the Featured post had blue text on a blue background.

## 0.17.0 — 2026-09-25

- **"Upgraded automatically" notice.** PostBase can be updated without anyone clicking anything: a hosting panel's Git auto-deploy (e.g. Hostinger), GeoRank's installer, or FTP.
  - The first admin visit on a new version records it.
  - Admins see *"PostBase was upgraded automatically to version …"* on every admin page until they press **Dismiss**. It shows the previous version and time, and a **What's new** list taken from this changelog.
  - If several updates arrive before anyone looks, the notice covers all of them.
  - Editors and Contributors don't see it.

## 0.16.1 — 2026-09-25

- The editor (750px writing column plus the 320px panel) is centred on wide screens, instead of leaving empty space on the right.

## 0.16.0 — 2026-09-25

**Photo blogging from your phone.** For local businesses, a real, geotagged phone photo on their own blog often does more than a social media post seen by a few followers.

- **Photo details are kept.**
  - Uploads keep the camera's location (GPS), date taken and phone model. Resized copies keep them too, both the in-browser resize and the server resize.
  - The orientation tag is reset to upright, because the pixels are already turned.
  - Settings → General → *Photos* can remove them instead, which is advised when photos are taken at home. With removal on, the photo is rotated first, so it never ends up sideways.
  - The Media Manager popup shows 📷 camera, 🕒 date taken and 📍 location, with a map link.
  - Everything is done in pure PHP, so PHP's exif extension isn't needed.
- **Automatic titles.** A post saved without a title is named after its publish time, e.g. "Post published on 25/09/2026 @ 10.50" (site timezone).
  - A draft named this way is renamed to the real time when it's published.
- **Keep me signed in** (on by default on the sign-in page).
  - A device stays signed in for 180 days after its last visit. The database stores only a hash of each device's secret.
  - My account → *Signed-in devices* lists devices and signs any of them out.
  - Changing a password, or an admin resetting it, signs out the other devices. Logging out forgets the device.
  - The sign-in page remembers your email on the device, so a phone's password manager can fill the password with Face ID or a fingerprint.
- **Add to Home Screen.** A web app manifest and icons install the admin as an app that opens straight on **Write**, with Posts and Media shortcuts.
  - Opening it while signed out comes back to Write after sign-in.
- Database schema v3 (the `devices` table) is applied automatically.
- The small sign-in logo on phones is fixed.

## 0.15.0 — 2026-09-25

**Write and publish from your phone.**

- **Mobile admin.**
  - The admin no longer spills off the right edge on phones. The sidebar becomes one compact row: the logo icon plus a swipeable menu.
  - "View blog" and "GeoRank dashboard" move to the footer.
  - Tapping a form field no longer zooms the page on iPhone.
- **Mobile editor.**
  - The formatting toolbar is one swipeable row that stays at the top while you write.
  - **Save / Publish sit in a bar pinned to the bottom of the screen.** The bar steps aside while the keyboard is open.
  - Wrapped images show full width, as they do on the published post.
- **Phone photos upload fast.** Images are resized in the browser (to the 1600px limit) before uploading, so a 5–12 MB camera photo becomes a few hundred KB.
  - The camera's rotation is applied, so portrait photos no longer turn sideways.
  - Location (GPS) and other EXIF data are removed.
  - The server also applies EXIF rotation when it resizes a JPEG (when PHP's `exif` extension is available).
- **Wide screens:** the writing column stops at 750px, with the 320px settings panel beside it.
- The editor shows **Submit for review** only to people who can't publish.

## 0.14.1 — 2026-09-25

- Media Manager: fixed the screen-reader label ("Select file-name") showing on top of each thumbnail. The admin CSS was missing the visually-hidden `.pb-sr` style. The select checkbox is now a compact box in the corner.
- My account uses the 👤 icon.

## 0.14.0 — 2026-09-25

**Tagline: "Write anywhere, post here."**

- **Static homepage.** Settings → General → *Homepage shows*: latest posts, or any published Page.
  - With a Page as the homepage, the post list moves to `/posts/` (`?list=1` without clean URLs).
  - The page's own address and the old `/page/N/` list URLs redirect with a 301, so search engines see one address per page.
  - The sitemap, navigation defaults, breadcrumbs, search and "All posts" links follow automatically.
  - If the chosen page is unpublished, the homepage falls back to the post list.
- **Media Manager** (sidebar → Media):
  - A thumbnail grid of every image in `uploads/`, with filename search, multi-file upload and 60 images per page.
  - Click an image for a popup with a large preview, dimensions, size and date. It has **Copy link** (for posts) and **Copy full URL**, a "Used in" list linking to each post/page/setting, previous/next (arrow keys) and Esc to close.
  - **Multi-select delete** (Editors and Admins) warns when selected images are still in use. Deletion is confined to image files inside `uploads/`, so path tricks are refused.
- **Settings → Code** (Admin only): header code (inside `<head>`) and footer code (before `</body>`) on every public page, for site verification, analytics and chat widgets. It works with every layout and theme and never runs in the admin area.
- Themes receive `posts_url` (the post list address). M1 1.0.1 searches it.

## 0.13.0 — 2026-09-24

- **Addons: themes and plugins.** Addons live in `addons/<slug>/` with an `addon.json`. Settings → Addons activates them and renders each addon's settings form (text, textarea, url, image upload, colour, select, checkbox and number fields, validated by type).
  - **Themes** return a renderer function that gets the full page (head, content, navigation, settings).
  - **Plugins** hook in with `pb_add_action` / `pb_add_filter`. The hooks are `pb_init`, `pb_head`, `pb_body_end`, `pb_page_meta`, `pb_post_content`, `pb_card_html`, `pb_nav_items`, `pb_post_saved`, `pb_post_status_changed` and `pb_post_deleted`.
  - A failing addon is logged and skipped; it never takes the blog down.
  - The developer guide is `docs/ADDONS.md`.
- **PostBase M1 theme** (first official addon): one layout for every device.
  - A 720px column and a 640px reading width.
  - A header of three permanent 100px blocks (logo or text, a button/link/text block, and a hamburger menu with search) that fits a 320–350px phone.
  - Settings for header, container, footer and outside background colours, with automatic contrast text.
- **New logo:** the "U + pen" icon and the "Post ✒ Base" wordmark as a clean SVG, with real transparent letter counters and automatic dark-mode colours.
- Layout option "Standalone" is renamed **PostBase theme**.
- Licensing: new commercial pricing, and PostBase is free on GeoRank and Unnati LiteCommerce sites. Permanent Sponsors get a dedicated thank-you post on the official blog and a permanent listing on the Sponsors page. Added a Sponsor button (`.github/FUNDING.yml`).

## 0.12.1 — 2026-09-24

- **Security hardening:** PostBase now recreates its protective `.htaccess` files (`data/`, `uploads/`, `lib/`) whenever they're missing. Dot-files are often silently skipped by FTP clients, zip tools and GitHub's web uploader, which could leave the SQLite database folder unprotected on a fresh install.

## 0.12.0 — 2026-09-24

**Write anywhere, paste without pain.**

- **Paste from anywhere.** A new paste engine (`assets/paste.js`) recognises where the content came from and keeps its structure:
  - **Word:** headings, bold and italic, links, tables, and real (nested) bulleted and numbered lists. Word's fake "·" bullets are rebuilt as lists.
  - **Google Docs:** fixes the whole document pasting as bold, keeps bold and italic from styled text, keeps headings and lists, and removes Google redirect links.
  - **WordPress (Gutenberg):** normal copy and "Copy block" / "Copy all blocks" both work. Embeds become playable videos, and images keep their left/right alignment and captions. Galleries, tables, pullquotes, buttons and columns are converted cleanly.
  - **Web pages and Notion:** YouTube and Vimeo iframes become PostBase videos, and everything else is cleaned to simple HTML.
  - **Markdown** from any editor (VS Code, Obsidian, iA Writer, Typora, ChatGPT, GitHub): headings (the top level maps to H2), bold, italic, strikethrough, highlight, links, images, code and code blocks, quotes, nested lists, task lists, tables and horizontal lines.
  - **Plain text:** paragraphs, line breaks and clickable links. A YouTube or Vimeo link on its own line becomes a video.
- **Pasted images are saved to your blog.** Images from Google Docs, WordPress and web pages, and copied or screenshot images, are copied into `uploads/`, so posts never break when the original link expires. Images that exist only on your computer (Word `file://` links) are flagged, not silently lost. Import is SSRF-safe: public http(s) only, the connection is pinned to the checked IP, redirects are checked at every hop, and size and type are validated.
- **Drag and drop** image files into the editor.
- **Markdown shortcuts while typing:** `## ` heading, `- ` list, `1. ` numbered, `> ` quote, `**bold**`, `*italic*`, `` `code` ``, `~~strike~~`, `---` + Enter for a line, and ```` ``` ```` + Enter for a code block.
- postbase.top is credited in the CSS/JS headers, the inline design CSS, a `generator` meta tag and the RSS `<generator>`.

## 0.11.1 — 2026-09-23

- Project links: the admin footer and Settings → System link to [postbase.top](https://postbase.top) for help and to [GitHub Issues](https://github.com/unnatidigiservices/postbase/issues) for bugs. "Powered by" in the standalone footer points to postbase.top.
- New SECURITY.md; README, CONTRIBUTING and COMMERCIAL-LICENSE updated with the project's origin and support channels.

## 0.11.0 — 2026-09-23

- **Pages.** Any entry can be a *Page* instead of a blog post, for About, Contact, Support and similar. Pages use the same editor, SEO fields and review workflow, get clean URLs (`/about/`), and render without date, author, category or prev/next. They're left out of the post list, RSS, categories and search, but are in the sitemap. Editors and Admins create them from the new **Pages** screen, and published pages appear in Settings → Navigation → Quick add.
- **Pinned posts.** Editors can pin a post as *Featured*. Up to 3 pinned posts show as a large card at the top of the blog home and aren't repeated in the list below. Category pages are unaffected.
- **Domain-root installs** (e.g. `postbase.top`): robots.txt is served when missing, "Add sitemap to robots.txt" writes to the right folder, the default menu is Home and RSS, and `tools/`, `docs/` and `dist/` are blocked.
- Database schema v2, upgraded automatically on the first request.

## 0.10.0 — 2026-09-23

- New Unnati PostBase logo, favicon and wordmark.
- Editor: image options bar with wrap (left, right, centre), size (300, 500, 760 px or full), alt text and caption. Wrapped images float on desktop and stack on phones with automatic height.
- Editor: YouTube and Vimeo embeds now play (they send the referrer YouTube requires), sit in a responsive frame, and can be selected and removed.
- Editor: 10px side padding, a primary-coloured Save button, and the HTML view no longer shows a stray textarea and file-upload box.
- Post cards and share previews use the featured image, else the first image in the post, else the default social image.
- Settings are split into General, Design and Navigation tabs.
  - Design: default social share image, blog favicon, body and heading fonts, text size, and accent, text, background and card colours. Each is optional and falls back to the site theme.
  - Navigation: an editable menu (default Home, Blog, Contact) with reordering, quick-add and new-tab links.
- The RSS feed now carries summaries only (meta description or the first 250 characters) with a permalink, not full posts.
- `tools/build-release.php` builds the checksummed release that GeoRank's Control Center installs from.

## 0.9.0 — 2026-09-23 (first public beta)

- Public blog: post list with pagination, single posts, categories, search, RSS 2.0, XML sitemap, clean URLs with query-string fallback.
- SEO: canonical, meta description, Open Graph and Twitter cards, `Blog` and `BlogPosting` JSON-LD, reading time, prev/next links.
- Editorial workflow: Contributor, Editor and Admin roles; submit, withdraw, approve, request changes (with notes), schedule, unpublish, archive, restore and delete; full per-post activity log.
- Editor: contenteditable toolbar (headings, lists, links, quotes, images, YouTube/Vimeo, HTML view), clean paste, cover image, excerpt, SEO fields, Ctrl+S.
- Security: server-side HTML allowlist sanitizer, CSRF on every form, `password_hash`, login throttling, locked `data/` and `lib/`, non-executable `uploads/`.
- GeoRank integration: shared-session sign-in, the site's header, footer, meta-global and theme assets, theme-token CSS, robots.txt sitemap helper.
- Storage: single-file SQLite with automatic migrations (`PRAGMA user_version`).
