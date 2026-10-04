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
| `nav` | Menu items from Settings → Navigation (`label`, `url`, `new_tab`). `pb_nav_html($class)` renders them with the current page marked. |
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

Meta keys: up to 64 of `a-z 0-9 _ . : -`; prefix them with your slug (`places:rating`). Meta is deleted together with its post. Built-in galleries (`<figure class="pb-gallery">` with images) are available to plugins too: write that HTML into a post and the blog styles it and loads the photo viewer.

## Security checklist

- Start every PHP file with `if (!defined('PB_ROOT')) { http_response_code(403); exit; }`.
- Escape output with `pb_e()`, and never print user input raw.
- Use `pb_q()` with parameters for database access, never string-built SQL.
- Admin-only features: check `pb_can(pb_current_user(), 'settings.manage')`, and use `pb_csrf_field()` / `pb_csrf_check()` on forms.
- Addons are installed by uploading the folder (FTP, File Manager, Git). JotKite deliberately has no "upload addon zip" button, because a zip upload would let anyone with an admin password run arbitrary code.

## Sharing your addon

Publish it on GitHub with the topic `jotkite-addon` and open an issue in [unnatidigiservices/jotkite](https://github.com/unnatidigiservices/jotkite/issues) to have it listed on [jotkite.com](https://jotkite.com). Addons you distribute must be AGPL-compatible, unless you hold a JotKite commercial license.
