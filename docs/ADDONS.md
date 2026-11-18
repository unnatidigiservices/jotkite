# Building JotKite addons (themes & plugins)

Addons extend JotKite without touching its core files, so updates never overwrite your work. An addon is a folder in `addons/` with an `addon.json` manifest. Admins activate addons in **Settings → Addons**, which also shows a settings form built from the manifest.

The official **JotKite M1** theme in [`addons/m1/`](../addons/m1) is the reference implementation.

```
addons/
└── my-addon/            ← folder name = slug: a-z, 0-9, hyphens
    ├── addon.json       ← required
    ├── theme.php        ← themes: returns the page renderer
    ├── theme.css        ← themes: loaded automatically
    ├── addon.php        ← plugins: runs on every request while active
    └── …                ← any CSS, JS, images (served as normal files)
```

## addon.json

```json
{
  "name": "My Addon",
  "type": "plugin",
  "version": "1.0.0",
  "description": "One sentence shown in Settings → Addons.",
  "author": "Your Name",
  "homepage": "https://example.com",
  "requires": "0.13.0",
  "settings": [
    { "key": "color", "type": "color", "label": "Highlight colour", "default": "#1d5cff" },
    { "key": "mode",  "type": "select", "label": "Mode", "options": { "a": "Option A", "b": "Option B" }, "default": "a" }
  ]
}
```

| Field | |
|---|---|
| `type` | `"theme"` (controls the whole public page) or `"plugin"` (adds behaviour through hooks). Only one theme is active at a time; any number of plugins. |
| `requires` | The minimum JotKite version. Older versions refuse to activate it. |
| `settings` | Optional. Field types are `text`, `textarea`, `url`, `image` (with an upload button), `color`, `select` (needs `options`), `checkbox` and `number`. Keys use `a-z 0-9 _`. Each field can have `default` and `help`. |

Read a value with `pb_addon_setting('my-addon', 'color')`, or all of them with `pb_addon_settings('my-addon')`. Values are validated by type before they're saved.

## Themes

`theme.php` **returns a function** that prints the whole HTML page:

```php
<?php
if (!defined('PB_ROOT')) { http_response_code(403); exit; }

return function (array $page) { ?>
<!DOCTYPE html>
<html lang="<?= pb_e($page['lang']) ?>">
<head><?= $page['head'] ?></head>
<body class="pb-page my-theme">
  <header><a href="<?= pb_e($page['blog_url']) ?>"><?= pb_e($page['blog_title']) ?></a>
    <?= pb_nav_html('my-nav') ?></header>
  <main id="main" class="pb-main"><?= $page['content'] ?></main>
  <footer>&copy; <?= pb_e($page['year']) ?> · <?= $page['powered_by'] ?></footer>
  <?= $page['body_end'] ?>
</body>
</html>
<?php };
```

**What `$page` contains:**

| Key | Contents |
|---|---|
| `head` | Everything for `<head>`: charset, viewport, title, SEO and social tags, canonical, JSON-LD, `blog.css`, your `theme.css`, and plugin head tags. **Always print it.** |
| `content` | The page body (post list, post, page, 404…). Uses the standard `pb-` classes from `assets/blog.css`, so style or override those. |
| `body_end` | Plugin scripts. **Print it just before `</body>`.** |
| `nav` | Menu items from Settings → Navigation (`label`, `url`, `new_tab`, and from 0.33 `children`: sub-items with the same keys). `pb_nav_html($class, $mode)` renders them with the current page marked. `$mode` (0.33): `'dropdown'` (default; a ▾ button opens each main link's sub-items, styled by blog.css and opened by a tap via assets/nav.js), `'stacked'` (sub-items listed under their main link with class `pb-nav-child`, for menus that are already a column), or `'top'` (main links only, for footers). |
| `blog_title`, `blog_url` (the homepage), `posts_url` (the post list: same as `blog_url`, or `/posts/` when a Page is the homepage), `site_url`, `feed_url`, `favicon`, `year`, `lang` | Site basics. Point search forms at `posts_url`. |
| `theme_url`, `theme_version` | URL of your addon folder and your addon.json version, for your own assets (`theme.js?v=...`). |
| `settings` | Your theme's settings, defaults included. |
| `powered_by` | The "Powered by JotKite" link (HTML). |

Rules:
- Keep `class="pb-page"` on `<body>` and `class="pb-main"` on the main element, so Settings → Design (fonts and colours) keeps working.
- Escape anything you print with `pb_e()`, except `head`, `content`, `body_end` and `powered_by`, which are already safe HTML.
- `pb_text_on($hex)` returns black or white text for a background colour.
- If your theme throws an error, JotKite logs it and shows the built-in layout, so a broken theme never breaks the blog.
- Themes are used when Settings → General → Layout is *JotKite theme* (or *Automatic* on non-GeoRank sites). On GeoRank sites the site's own design is the default.

## Plugins

`addon.php` runs on every request while the plugin is active. Hook in with actions (do something) and filters (change a value):

```php
<?php
if (!defined('PB_ROOT')) { http_response_code(403); exit; }

// Add a reading-progress bar to every public page.
pb_add_action('pb_head', function () {
    echo '<style>#rp{position:fixed;top:0;left:0;height:3px;background:var(--pb-accent);z-index:99}</style>';
});
pb_add_action('pb_body_end', function () {
    echo '<div id="rp"></div><script>addEventListener("scroll",function(){var h=document.documentElement;'
       . 'document.getElementById("rp").style.width=(h.scrollTop/(h.scrollHeight-h.clientHeight)*100)+"%"})</script>';
});

// Append a note to every post.
pb_add_filter('pb_post_content', function ($html, $post) {
    return $html . '<p><em>Thanks for reading!</em></p>';
});
```

| Hook | Kind | Arguments |
|---|---|---|
| `pb_init` | action | — (after all plugins load) |
| `pb_head` | action | `$meta`: echo tags into `<head>` |
| `pb_body_end` | action | `$meta`: echo before `</body>` |
| `pb_page_meta` | filter | `$meta` (title, description, canonical, image, jsonld, noindex…) |
| `pb_post_content` | filter | `$html, $post`: the post/page body as shown |
| `pb_card_html` | filter | `$html, $post`: a post card in lists |
| `pb_nav_items` | filter | `$items`: the menu |
| `pb_post_saved` | action | `$postId, $user` |
| `pb_post_status_changed` | action | `$post, $action, $user, $note` (submit, approved, published, scheduled, request_changes, unpublish, archive, restore, withdraw) |
| `pb_post_deleted` | action | `$post, $user` |
| `pb_editor_panel` | action | `$post, $user, $canEdit`: echo a card into the editor's side column (0.27) |
| `pb_admin_post` | action | `$do, $user`: handle your own admin form posts; CSRF is already checked (0.27) |
| `pb_sitemap` | action | echo extra `<url><loc>…</loc></url>` lines into sitemap.xml (0.27) |
| `pb_editor_toolbar` | action | `$post, $user`: echo extra buttons into the editor toolbar. Use your own `data-*` attribute, not `data-cmd` (0.31) |
| `pb_admin_footer` | action | `$view, $user`: echo your admin `<link>`/`<script>` tags; printed after admin.js, so `window.PBEditor` is ready on the editor (0.31) |
| `pb_link_search` | filter | `$items, $q`: the editor link popup's results; add `['title' => …, 'url' => …, 'label' => …]` items (0.31) |
| `pb_comment_form` | action | `$post`: echo extra fields into the comment form, e.g. a star rating (0.28) |
| `pb_comment_check` | filter | `'', ['body', 'name', 'user'], $post`: return an error message to refuse a comment (0.28) |
| `pb_comment_saved` | action | `$comment, $post`: a comment was stored (pending or live); read your form fields from `$_POST` (0.28) |
| `pb_comment_approved` | action | `$comment, $post`: a comment went live for the first time (0.28) |
| `pb_comment_html` | filter | `$html, $comment, $post`: one comment as shown (the `<li>` is closed by the caller) (0.28) |
| `pb_comment_visitor` | filter | `null, $post`: vouch for a visitor your addon signed in itself. Return `['name' => …, 'live' => true]` and the comment uses that name and skips the approval queue. The text is still cleaned as plain text, and spam traps and rate limits still apply (0.32) |
| `pb_page_header` | filter | `$html, $post`: a Page's title header (`<header class="pb-article-head"><h1>…`). Return `''` when your first block carries the page's heading (0.32) |
| `pb_strip_photo_metadata` | filter | `$strip` (bool, from Settings → General): return `true` to always remove photo details (EXIF: location, camera, date) on upload. Settings then shows the option as locked (0.32) |

**Page context in `pb_page_meta` (0.32).** On posts and Pages, `$meta['post']` is the post row. `$meta['front']` is `true` on the homepage (a homepage Page, or page 1 of the latest posts). Use them to add structured data to one kind of page, for example an organisation's details on the homepage.

**Child pages (0.33).** A Page can have a parent Page (`posts.parent_id`; `posts.page_order` sorts siblings). `pb_url('post', $slug)` already returns the nested address (`/about-us/history/`), so link with it rather than building addresses yourself. Helpers: `pb_page_ancestors($slugOrId)` (parents, top first), `pb_page_path($slug)` (`about-us/history`), `pb_page_children($id)` (published sub-pages in order), `pb_page_breadcrumbs($post)` (`['html', 'jsonld']`, or `null` for a top-level page). A child page's `jsonld` in `pb_page_meta` is an `@graph` holding the WebPage and its BreadcrumbList; don't add a second BreadcrumbList.

The optional third argument to `pb_add_action` / `pb_add_filter` is a priority (lower runs first; default 10). Exceptions inside a hook are caught and logged, and the rest of the page still renders.

## Bigger plugins (0.27+)

Everything a feature-sized plugin needs, for example a places/reviews directory:

```php
<?php
if (!defined('PB_ROOT')) { http_response_code(403); exit; }

// 1. Your own tables, created once and upgraded by version number.
pb_add_action('pb_init', function () {
    pb_addon_migrate('places', 1, function (PDO $db, $from) {
        if ($from < 1) $db->exec('CREATE TABLE places_place (id INTEGER PRIMARY KEY, slug TEXT UNIQUE, name TEXT NOT NULL, lat REAL, lng REAL)');
    });
});

// 2. Extra data on a post: a card in the editor, saved with the post.
pb_add_action('pb_editor_panel', function ($post, $user, $canEdit) {
    $r = $post['id'] ? (int) pb_post_meta($post['id'], 'places:rating', 0) : 0;
    echo '<div class="pb-card"><h3 class="pb-h3">Rating</h3><label class="pb-small">Stars'
       . '<input type="number" name="places_rating" min="0" max="5" value="' . $r . '"' . ($canEdit ? '' : ' readonly') . '></label></div>';
});
pb_add_action('pb_post_saved', function ($postId, $user) {
    if (isset($_POST['places_rating'])) pb_post_meta_set($postId, 'places:rating', max(0, min(5, (int) $_POST['places_rating'])));
});

// 3. An admin page in the menu (for every writer here; use 'settings.manage' for Admins only).
pb_add_admin_page('places', 'Places', '📍', function ($user) {
    echo '<div class="pb-card">Your places…</div>';
}, 'post.create');

// 4. A public address: /places/ and /places/<slug>/ (+ /page/N/).
pb_add_route('places', function (array $parts, $page) {
    if (isset($parts[1]) && !pb_row('SELECT id FROM places_place WHERE slug = ?', [$parts[1]])) return false; // → 404
    pb_render_page(['title' => 'Places | ' . pb_setting('blog_title'), 'canonical' => pb_abs_url('/places/')], '<div class="pb-wrap">…</div>');
    return true;
});
pb_add_action('pb_sitemap', function () { echo '<url><loc>' . pb_e(pb_abs_url('/places/')) . "</loc></url>\n"; });
```

| Function | What it does |
|---|---|
| `pb_post_meta($postId, $key, $default)` | Read a value (JSON-decoded: strings, numbers, arrays…) |
| `pb_post_meta_set($postId, $key, $value)` | Write a value (`null` deletes). Max 256 KB per value |
| `pb_post_meta_all($postId)` / `pb_post_meta_many($ids, $key)` | All values of a post / one key for many posts (for lists) |
| `pb_addon_migrate($slug, $version, $fn)` | Create/upgrade your tables; runs in a transaction, once per version |
| `pb_add_admin_page($key, $label, $icon, $render, $perm)` | Menu item + page at `/admin/?view=$key`; built-in names are reserved |
| `pb_add_route($prefix, $handler)` | Public pages under `/$prefix/`; posts can no longer take that slug |
| `pb_add_block_sanitizer($fn)` | Keep a block of your own through the HTML sanitizer (0.31). `$fn(DOMDocument $doc, DOMElement $div)` is called for every `<div>` with a class. Return `true` when it's yours: you then own that element, so rebuild its attributes from values you validated yourself and call `pb_sanitize_children($doc, $div)` for any ordinary content inside. Return `false` otherwise. Unknown divs are still unwrapped. |

**Editor API (0.31).** On the editor page, `window.PBEditor` offers `el` (the editor element), `insertHtml(html)` (at the cursor), `refresh()` (re-check blocks and the empty lines around them), `lineBeside(block, 'above'|'below')`, `markDirty()`, and (0.32) `upload(file)` (a Promise of `{url, width, height}`, shrunk and converted like any upload) and `pickImage(onPick)` (the media library; `onPick({url, width, height})`). Mark your block's element with `data-pb-block` so the editor keeps a typeable line above and below it. Add `contenteditable="false"` if its inside shouldn't be typed in. Both attributes are removed before saving.

**GeoRank-only addons (0.31).** `"georank_only": true` in addon.json makes a plugin switch on by itself on an Unnati GeoRank site, and it is never loaded anywhere else (Settings → Addons shows "Available on Unnati GeoRank sites only").

Meta keys: up to 64 of `a-z 0-9 _ . : -`; prefix them with your slug (`places:rating`). Meta is deleted together with its post. Built-in galleries (`<figure class="pb-gallery">` with images) are available to plugins too: write that HTML into a post and the blog styles it and loads the photo viewer.

## Addons outside the site folder (0.34+)

A Git deploy replaces the site folder, so an addon that isn't in your repository disappears from `addons/` on the next deploy. Keep such addons in a folder outside the site and name it in `config.php`:

```php
'addons_dir' => '/home/u123456789/jotkite-addons',
```

with one folder per addon inside (`jotkite-addons/my-addon/addon.json`). They are listed and activated in Settings → Addons as usual. Always build file links from `$a['url']` (for example `pb_addon('my-addon')['url'] . '/style.css'`), never a hard-coded `/addons/…`: for these addons it points to `/_addon/my-addon`, which `index.php` answers (code in `lib/addon-file.php`), serving CSS, JS, images and fonts only. It works with the usual rewrite rules (Apache `.htaccess`, Nginx `try_files … /index.php`), so nothing extra is needed on the server. When the same name exists in both places, `addons/` wins.

## Security checklist

- Start every PHP file with `if (!defined('PB_ROOT')) { http_response_code(403); exit; }`.
- Escape output with `pb_e()`, and never print user input raw.
- Use `pb_q()` with parameters for database access, never string-built SQL.
- Admin-only features: check `pb_can(pb_current_user(), 'settings.manage')`, and use `pb_csrf_field()` / `pb_csrf_check()` on forms.
- Addons are installed by uploading the folder (FTP, File Manager, Git). JotKite deliberately has no "upload addon zip" button, because a zip upload would let anyone with an admin password run arbitrary code.

## Sharing your addon

Publish it on GitHub with the topic `jotkite-addon` and open an issue in [unnatidigiservices/jotkite](https://github.com/unnatidigiservices/jotkite/issues) to have it listed on [jotkite.com](https://jotkite.com). Addons you distribute must be AGPL-compatible, unless you hold a JotKite commercial license.
