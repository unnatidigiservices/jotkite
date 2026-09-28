<p align="center">
  <a href="https://jotkite.com"><img src="assets/jk-icon.svg" width="96" alt="JotKite logo"></a>
</p>
<h1 align="center">JotKite</h1>
<p align="center"><sub>by <a href="https://unnatidigiservices.in">Unnati Digi Services</a> · formerly <em>PostBase</em></sub></p>

<p align="center"><strong>Write anywhere, post here.</strong><br>
A fast, mobile-friendly PHP blogging platform with a real editorial workflow: Contributor → Editor → Admin.</p>

<p align="center">
  <a href="https://jotkite.com"><img alt="Website" src="https://img.shields.io/badge/website-jotkite.com-1f4bff"></a>
  <img alt="PHP 7.4+" src="https://img.shields.io/badge/PHP-7.4%2B-777bb4">
  <img alt="SQLite" src="https://img.shields.io/badge/database-SQLite-003b57">
  <a href="#license"><img alt="License" src="https://img.shields.io/badge/license-AGPL--3.0%20%7C%20Commercial-5c1aef"></a>
  <img alt="No build step" src="https://img.shields.io/badge/build%20step-none-0f9d58">
</p>

<p align="center">
  <a href="https://jotkite.com"><b>Website &amp; docs</b></a> ·
  <a href="#install">Install</a> ·
  <a href="https://github.com/unnatidigiservices/jotkite/issues">Report a bug</a> ·
  <a href="#project--support">Support</a>
</p>

---

Drop the folder into `/blog/` on any PHP host, or onto a domain of its own, open `/admin/` and start writing. There's no Composer, no npm, no MySQL and no framework, just a few PHP files and one SQLite database. Pages are small, load fast and are built for phones first.

## Why JotKite exists

[Unnati Digi Services](https://unnatidigiservices.in) built JotKite for its **[GeoRank](https://georank.co.in)** clients: local businesses whose sites needed a blog that loads fast on mobile, looks like the rest of the site, and never lets an unreviewed post go live. On a GeoRank site it installs in one click, uses the site's own header, footer, menu and theme, and signs GeoRank users in automatically.

JotKite is also available as a **free add-on for [Unnati LiteCommerce](https://litecommerce.co.in) sites**, styled to match your brand.

Nothing in it depends on GeoRank, though. It runs just as well beside any PHP website or as a standalone blog, so it's open source for everyone. Project information, guides and support live at **[jotkite.com](https://jotkite.com)**, which runs on JotKite itself.

## Features

- ✍️ **Write anywhere, paste without pain.**
  - Paste from **Word, Google Docs, WordPress (Gutenberg), Notion, web pages or any Markdown editor**, and headings, lists, bold and italic, links, tables, images and videos come through clean.
  - Pasted images are saved to your blog, so they never break later.
  - Type Markdown as you go: `## `, `- `, `1. `, `> `, `**bold**`, `*italic*`, `` `code` ``, `---`.
  - Images auto-resize and can wrap left or right with a size, caption and alt text.
  - YouTube and Vimeo embeds, plus an HTML view.
- 🛂 **Publishing control with three roles:**

  | | Contributor | Editor | Admin |
  |---|:-:|:-:|:-:|
  | Write & edit own drafts | ✅ | ✅ | ✅ |
  | Submit for review | ✅ | ✅ | ✅ |
  | Edit anyone's posts | | ✅ | ✅ |
  | Approve, publish, schedule | | ✅ | ✅ |
  | Request changes (with notes) | | ✅ | ✅ |
  | Pages, pinned posts, categories | | ✅ | ✅ |
  | Users, settings, permanent delete | | | ✅ |

- 🧾 **Review queue and audit trail.** Pending posts are counted in the sidebar, and every submit, approve, change request and unpublish is logged with who, when and why.
- 📄 **Pages and a featured post.**
  - Pages (About, Contact, Support…) use the same editor with clean URLs, and stay out of the post list.
  - Pin a post to feature it at the top of the blog home.
- 🏠 **Static homepage.** Make any Page your homepage; the post list moves to `/posts/` automatically, with SEO-safe redirects.
- 🖼️ **Media Manager.** A thumbnail library of every image, with a popup preview, one-click copy link, "used in" tracking and multi-select delete.
- 🧰 **Custom header and footer code.** Add site-verification tags, analytics or chat widgets to every page from Settings → Code.
- ⏰ **Scheduled posts.** Publish with a future date and the post goes live on its own.
- 📱 **Fast and mobile-first.**
  - No JavaScript framework on public pages.
  - Lazy-loaded, resized images.
  - Responsive layouts that stack cleanly on phones.
- 🔎 **SEO built in.**
  - Tags: clean URLs, canonical tags, meta descriptions, Open Graph and Twitter cards.
  - Structured data: `BlogPosting` and `WebPage` JSON-LD.
  - Discovery: an XML sitemap, a summary RSS feed, reading time and search.
- 🧩 **Themes and plugins.**
  - Settings → Addons activates themes and plugins and gives each a settings form.
  - Build your own with a folder and an `addon.json`; see [docs/ADDONS.md](docs/ADDONS.md).
  - The first official addon is **JotKite M1** ("Mobile One"): one layout for every device, with a 720px column, a 640px reading width, and a thumb-friendly header of three 100px blocks (logo, action, menu). You pick the header, container, footer and outside colours.
- 🎨 **Your design.** It follows the site's theme automatically. Settings → Design adds fonts, colours, a favicon and a default share image, and Settings → Navigation edits the menu.
- 🔐 **Secure by default.**
  - Every post body passes a server-side HTML allowlist sanitizer.
  - CSRF tokens, `password_hash` and login throttling.
  - A non-executable uploads folder and a locked data folder.
- 📦 **Zero setup.** A single SQLite file. Back up the whole blog by copying `data/` and `uploads/`.

## Requirements

- PHP 7.4 or newer (8.x recommended) with `pdo_sqlite`, which is enabled by default on almost every host
- Optional: `gd` (image resizing), `mbstring`, `intl`/`iconv` (nicer slugs)
- Apache with `mod_rewrite` for clean URLs. Without it, JotKite falls back to `?p=slug` URLs. See [Nginx](#nginx) below.

## Install

### Next to an existing site (`/blog/`)

1. Download or clone this repository into a folder named `blog` in your web root:
   ```
   public_html/
   ├── index.html        ← your site
   └── blog/             ← JotKite
   ```
2. Make sure `blog/data/` and `blog/uploads/` are writable by PHP (usually they already are).
3. Visit `https://your-site.com/blog/admin/` and create the first admin account. (Optional: set `setup_key` in `config.php` first.)
4. In **Settings**, click **Add blog sitemap to robots.txt**. Then add a "Blog" link to your site menu.

### As a whole site on its own domain

Put the files directly in `public_html/`. The blog becomes the home page, posts live at `/post-slug/`, the admin is at `/admin/`, and `robots.txt` and `sitemap.xml` are served for you. Create About, Contact and similar pages under **Pages**, and add them in **Settings → Navigation**.

> On a public domain, set `setup_key` in `config.php` **before** uploading, so nobody else can claim the first-run setup screen.

### On a GeoRank site

Use **Control Center → Upgrade → Blog → Install Blog** in GeoRank (v1.90+). Every file is checksum-verified, updates never touch posts or images, and GeoRank users are signed in automatically. See [docs/GEORANK.md](docs/GEORANK.md).

### Updating

Replace every file except `config.php`, `data/` and `uploads/`. Database upgrades run automatically on the next page load.

### Public demo site

To run a "try it" site that anyone can play with, install JotKite on its own (sub)domain and add this to `config.php`:

```php
return ['demo' => true, 'demo_reset_minutes' => 60, 'demo_key' => 'a-long-secret'];
```

- **First visit:** JotKite creates demo accounts and sample posts.
- **Sign-in:** visitors enter with one click as Admin, Editor or Contributor.
- **Reset:** every 60 minutes the database and images go back to the starting point.
- **Locked for visitors:** custom header/footer code, robots.txt, and the demo accounts' passwords, emails and roles. Public pages are `noindex`.
- **Your own starting point:** arrange the content you want, then enter `demo_key` in **Settings → Demo** and click *Save current content as the starting point*.

Never turn this on for a real blog.

## Nginx

```nginx
# JotKite in /blog/ (for a whole-domain install, drop the /blog prefix)
location /blog/ {
    try_files $uri $uri/ /blog/index.php?$args;
}
location ~ ^/blog/(data|lib|tools|docs)/ { deny all; }
location ~ ^/blog/uploads/.*\.(php|phtml|html?)$ { deny all; }
```

## Why SQLite?

We compared plain text files, JSON and SQLite for a multi-author workflow. See [docs/DATABASE.md](docs/DATABASE.md).

## Roadmap

- Tags, revisions with one-click restore, email notifications for review requests
- Media library browser, image alt-text reminders
- Import from WordPress (WXR) and export to Markdown/JSON
- Comments (opt-in, moderated)
- MySQL driver for large installs

Have an idea? [Open an issue](https://github.com/unnatidigiservices/jotkite/issues). Pull requests are welcome; please read [CONTRIBUTING.md](CONTRIBUTING.md) first.

## Project & support

| You want to… | Go to |
|---|---|
| Read guides, see a live demo, get help | **[jotkite.com](https://jotkite.com)** |
| Report a bug or request a feature | [GitHub Issues](https://github.com/unnatidigiservices/jotkite/issues) |
| Report a security vulnerability (privately) | See [SECURITY.md](SECURITY.md) |
| Buy a commercial license or priority support | [jotkite.com](https://jotkite.com) · [COMMERCIAL-LICENSE.md](COMMERCIAL-LICENSE.md) |
| Build a theme or plugin | [docs/ADDONS.md](docs/ADDONS.md) |
| Get a fast local-business website with the blog built in | [GeoRank](https://georank.co.in) |
| Add a blog to your online store | [Unnati LiteCommerce](https://litecommerce.co.in) |

JotKite is developed and maintained by **[Unnati Digi Services](https://unnatidigiservices.in)**.

## License

JotKite is **dual-licensed**:

- **[GNU AGPL-3.0-or-later](LICENSE):** free for everyone, including commercial websites, as long as you comply with the AGPL. That means sharing the source of any modified version you run for other people over a network.
- **[Commercial license](COMMERCIAL-LICENSE.md):** for sites, SaaS platforms and hosts that want to modify, white-label or bundle JotKite **without** AGPL obligations:

  | License | Price |
  |---|---|
  | Single site | $17 / year |
  | Single site, permanent (with custom design and CMS integration) | $97 one-time |
  | White-label SaaS provider | $197 / year |
  | Hosting company | $297 / year |
  | Permanent Sponsor (a thank-you post about your product + a permanent listing on the Sponsors page) | $497 one-time |

  Free on [GeoRank](https://georank.co.in) and [Unnati LiteCommerce](https://litecommerce.co.in) sites.

See [NOTICE](NOTICE) for the copyright and licensing statement.

© 2026 Unnati Digi Services. "JotKite", "GeoRank" and their logos are names of Unnati Digi Services. The AGPL covers the code, not the right to present a modified version under those names.
