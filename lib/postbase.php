<?php
/**
 * JotKite — core library (config, database, auth, roles, posts).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial
 * Copyright (c) 2026 Unnati Digi Services. See LICENSE and COMMERCIAL-LICENSE.md.
 *
 * Every entry point (index.php, admin/index.php) defines PB_ROOT and
 * PB_BASE_PATH before requiring this file.
 */
if (!defined('PB_ROOT')) { http_response_code(403); exit; }

define('PB_VERSION', '0.22.0');
define('PB_HOMEPAGE', 'https://jotkite.com');                             // project info, docs and support
define('PB_REPO_URL', 'https://github.com/unnatidigiservices/jotkite');    // source code and issues
define('PB_SCHEMA_VERSION', 4);
define('PB_DATA_DIR', PB_ROOT . '/data');
define('PB_UPLOAD_DIR', PB_ROOT . '/uploads');
// The site's web root: PB_ROOT itself when JotKite runs at a domain root
// (e.g. jotkite.com), its parent for /blog/, two levels up for /news/blog/.
define('PB_SITE_DIR', PB_BASE_PATH === '' ? PB_ROOT : dirname(PB_ROOT, substr_count(trim(PB_BASE_PATH, '/'), '/') + 1));
define('PB_SESSION_LIFETIME', 12 * 60 * 60); // matches GeoRank so a shared session is never cut short
define('PB_ROLES', ['contributor', 'editor', 'admin']);
define('PB_STATUSES', ['draft', 'pending', 'changes_requested', 'published', 'archived']);
// URL names used by JotKite itself. "docs" and "tools" (repository folders) are
// deliberately not here: their files are blocked, their names are free for pages.
define('PB_RESERVED_SLUGS', ['admin', 'assets', 'data', 'lib', 'uploads', 'page', 'category', 'feed', 'feed.xml', 'sitemap.xml', 'robots.txt', 'search', 'index.php', 'posts', 'addons']);

// ----------------------------------------------------------------------------
// CONFIG — optional config.php (see config.sample.php) overrides these.
// Everything a site owner changes day to day lives in the settings table.
// ----------------------------------------------------------------------------
$PB_CONFIG = [
    'db_path'   => PB_DATA_DIR . '/postbase.sqlite',
    'setup_key' => '',          // if set, first-run setup asks for it
    'max_upload_mb' => 5,
    'max_image_px'  => 1600,
    // Public "try it" site that resets itself (lib/demo.php). Never on a real blog.
    'demo'               => false,
    'demo_reset_minutes' => 60,
    'demo_key'           => '',   // typed in Settings → Demo to unlock owner tools
];
if (is_file(PB_ROOT . '/config.php')) {
    $pbUserConfig = include PB_ROOT . '/config.php';
    if (is_array($pbUserConfig)) $PB_CONFIG = array_merge($PB_CONFIG, $pbUserConfig);
}
function pb_config($key) {
    global $PB_CONFIG;
    return $PB_CONFIG[$key] ?? null;
}

// ----------------------------------------------------------------------------
// SMALL HELPERS
// ----------------------------------------------------------------------------
function pb_e($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function pb_now() {
    return gmdate('Y-m-d H:i:s');
}
function pb_fatal($msg) {
    http_response_code(500);
    echo '<!DOCTYPE html><meta charset="utf-8"><title>JotKite error</title>'
       . '<div style="font:16px/1.5 system-ui,sans-serif;max-width:640px;margin:60px auto;padding:0 20px">'
       . '<h1 style="font-size:22px">JotKite can\'t start</h1><p>' . pb_e($msg) . '</p></div>';
    exit;
}
function pb_is_https() {
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}
// Scheme + host of the site. The "site_url" setting wins when set, so
// canonicals, the sitemap and the RSS feed never depend on a Host header.
function pb_origin() {
    $fixed = rtrim((string) pb_setting('site_url'), '/');
    if ($fixed !== '') return preg_replace('~^(https?://[^/]+).*$~i', '$1', $fixed);
    $host = preg_replace('/[^a-z0-9.\-:\[\]]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    return (pb_is_https() ? 'https' : 'http') . '://' . $host;
}
// Path of the site root the blog sits under ('' when the blog is /blog/).
function pb_site_base_path() {
    $p = rtrim(str_replace('\\', '/', dirname(PB_BASE_PATH === '' ? '/' : PB_BASE_PATH)), '/');
    return $p === '.' ? '' : $p;
}
function pb_slugify($text, $max = 80) {
    $text = (string) $text;
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($t !== false && trim($t) !== '') $text = $t;
    }
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim(substr(trim($text, '-'), 0, $max), '-');
    return $text;
}
function pb_text_excerpt($html, $len = 160) {
    $t = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/h[1-6]|\/li)[^>]*>/i', ' ', (string) $html)), ENT_QUOTES, 'UTF-8');
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    if (function_exists('mb_strlen') ? mb_strlen($t) <= $len : strlen($t) <= $len) return $t;
    $cut = function_exists('mb_substr') ? mb_substr($t, 0, $len) : substr($t, 0, $len);
    $sp = strrpos($cut, ' ');
    if ($sp !== false && $sp > $len * 0.6) $cut = substr($cut, 0, $sp);
    return rtrim($cut, " ,.;:-") . '…';
}
function pb_reading_minutes($html) {
    $words = str_word_count(strip_tags((string) $html));
    return max(1, (int) round($words / 200));
}
function pb_format_date($utc, $format = 'j M Y') {
    if (!$utc) return '';
    try {
        $d = new DateTime($utc, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone(pb_setting('timezone') ?: 'UTC'));
        return $d->format($format);
    } catch (Exception $e) {
        return '';
    }
}
// Local "Y-m-d\TH:i" (from <input type=datetime-local>) -> UTC storage string.
function pb_local_to_utc($local) {
    $local = trim((string) $local);
    if ($local === '') return null;
    try {
        $d = new DateTime($local, new DateTimeZone(pb_setting('timezone') ?: 'UTC'));
        $d->setTimezone(new DateTimeZone('UTC'));
        return $d->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

// ----------------------------------------------------------------------------
// SESSION — shares GeoRank's PHP session (same cookie, same lifetime) so a
// GeoRank admin/editor lands in JotKite already signed in.
// ----------------------------------------------------------------------------
function pb_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.gc_maxlifetime', (string) PB_SESSION_LIFETIME);
    $p = session_get_cookie_params();
    $p['lifetime'] = PB_SESSION_LIFETIME;
    $p['httponly'] = true;
    if (empty($p['samesite'])) $p['samesite'] = 'Lax';
    if (pb_is_https()) $p['secure'] = true;
    session_set_cookie_params($p);
    session_start();
}
function pb_csrf_token() {
    if (empty($_SESSION['pb_csrf'])) $_SESSION['pb_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['pb_csrf'];
}
function pb_csrf_check() {
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || empty($_SESSION['pb_csrf']) || !hash_equals($_SESSION['pb_csrf'], $sent)) {
        http_response_code(400);
        exit('Security token expired. Go back, reload the page and try again.');
    }
}

// ----------------------------------------------------------------------------
// DATABASE — SQLite via PDO. See docs/DATABASE.md for why.
// ----------------------------------------------------------------------------
// Protective .htaccess files are dot-files, and dot-files are often silently
// dropped by FTP clients, zip tools and GitHub's web uploader. JotKite never
// relies on them having survived: it recreates any that are missing — the data
// folder (SQLite database), uploads (never executable) and lib.
// JotKite's own .htaccess, byte for byte the same as the one in the repository
// (a test checks they match).
function pb_root_htaccess() {
    return "# JotKite\n"
         . "Options -Indexes\n"
         . "DirectoryIndex index.php\n"
         . "\n"
         . "<IfModule mod_rewrite.c>\n"
         . "  RewriteEngine On\n"
         . "  # If clean URLs give a 404 or 500 on your host, uncomment and set this to\n"
         . "  # the folder JotKite lives in (with slashes), e.g. /blog/\n"
         . "  # RewriteBase /blog/\n"
         . "\n"
         . "  # Never serve internals directly.\n"
         . "  RewriteRule ^(data|lib|dist)(/|$) - [F,L]\n"
         . "  # The repository's docs/ and tools/ files are never served either, but the\n"
         . "  # names stay free for your own pages, e.g. /docs/ or /tools/.\n"
         . "  RewriteCond %{REQUEST_FILENAME} -f\n"
         . "  RewriteRule ^(docs|tools)/ - [F,L]\n"
         . "  RewriteRule ^(docs|tools)/?$ index.php?route=$1 [QSA,L]\n"
         . "  # Addon code and manifests are never served directly (their CSS/JS/images are).\n"
         . "  RewriteRule ^addons/.+\\.(php|json|md)$ - [F,L]\n"
         . "  RewriteRule ^(config(\\.sample)?\\.php|[^/]+\\.md|\\.git.*)$ - [F,L]\n"
         . "\n"
         . "  # Everything that isn't a real file or folder goes to the front controller.\n"
         . "  RewriteCond %{REQUEST_FILENAME} !-f\n"
         . "  RewriteCond %{REQUEST_FILENAME} !-d\n"
         . "  RewriteRule ^(.*)$ index.php?route=$1 [QSA,L]\n"
         . "</IfModule>\n";
}
// Earlier generated versions of the main .htaccess (0.20.0 as PostBase, 0.21.0 as
// JotKite). A file still byte-identical to one of them was written by JotKite and
// never edited, so it is safe to bring up to date. Anything else is left alone.
function pb_root_htaccess_is_old($raw) {
    $cur = pb_root_htaccess();
    $oldRules = "  # Never serve internals or docs directly.\n  RewriteRule ^(data|lib|tools|docs|dist)(/|$) - [F,L]\n";
    $newRules = substr($cur, $a = strpos($cur, "  # Never serve internals directly.\n"), strpos($cur, "  # Addon code") - $a);
    $v021 = str_replace($newRules, $oldRules, $cur);
    $v020 = str_replace("# JotKite\n", "# Unnati PostBase\n", str_replace('the folder JotKite lives in', 'the folder PostBase lives in', $v021));
    return $raw === $v021 || $raw === $v020;
}
function pb_ensure_protection() {
    static $done = false;
    if ($done) return;
    $done = true;
    $main = PB_ROOT . '/.htaccess';
    if (is_file($main) && pb_root_htaccess_is_old((string) @file_get_contents($main))) @file_put_contents($main, pb_root_htaccess());
    $deny = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    $files = [
        // The main one (clean URLs, blocked internals). Releases don't ship it at all:
        // hosts and CDNs refuse to serve any file named .ht*, even as a download.
        PB_ROOT . '/.htaccess' => pb_root_htaccess(),
        dirname(pb_config('db_path')) . '/.htaccess' => "# The SQLite database lives here. Never serve it.\n" . $deny,
        PB_ROOT . '/lib/.htaccess' => $deny,
        PB_UPLOAD_DIR . '/.htaccess' => "# Uploaded images only. Nothing in here may ever run as code.\n"
            . "Options -Indexes -ExecCGI\n"
            . "<FilesMatch \"\\.(php\\d?|phtml|phar|pl|py|cgi|sh|s?html?|htaccess|svg)$\">\n"
            . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
            . "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n</FilesMatch>\n"
            . "<IfModule mod_mime.c>\n  RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .html .htm\n"
            . "  RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .html .htm\n</IfModule>\n"
            . "<IfModule mod_headers.c>\n  Header set X-Content-Type-Options \"nosniff\"\n</IfModule>\n",
    ];
    foreach ($files as $path => $content) {
        $dir = dirname($path);
        if (is_file($path)) continue;
        // Only protect folders inside the JotKite install (a custom db_path outside the web root needs none).
        if (strpos(str_replace('\\', '/', $dir), str_replace('\\', '/', PB_ROOT)) !== 0) continue;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($path, $content);
    }
}

function pb_db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        pb_fatal('The PHP "pdo_sqlite" extension is not enabled on this server. Enable it in your hosting control panel (PHP extensions), then reload.');
    }
    $path = pb_config('db_path');
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) pb_fatal('Could not create the data folder: ' . $dir);
    if (!is_writable($dir)) pb_fatal('The data folder is not writable: ' . $dir);
    pb_ensure_protection();
    $demo = pb_demo_on();
    if ($demo) pb_demo_maybe_reset($path); // lib/demo.php: restore the snapshot when it's time
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        // WAL is faster, but a demo swaps the whole file on reset: a single file is safer there.
        try { $pdo->exec('PRAGMA journal_mode = ' . ($demo ? 'DELETE' : 'WAL')); } catch (Exception $e) { /* some network filesystems refuse WAL; rollback journal is fine */ }
        pb_migrate($pdo);
        if ($demo) pb_demo_bootstrap();
    } catch (PDOException $e) {
        pb_fatal('Database error: ' . $e->getMessage());
    }
    return $pdo;
}
function pb_migrate(PDO $pdo) {
    $v = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($v >= PB_SCHEMA_VERSION) return;
    $pdo->beginTransaction();
    if ($v < 1) {
        $pdo->exec("
            CREATE TABLE users (
                id            INTEGER PRIMARY KEY,
                email         TEXT NOT NULL UNIQUE COLLATE NOCASE,
                name          TEXT NOT NULL,
                password_hash TEXT,
                role          TEXT NOT NULL CHECK (role IN ('contributor','editor','admin')),
                active        INTEGER NOT NULL DEFAULT 1,
                source        TEXT NOT NULL DEFAULT 'local',
                bio           TEXT NOT NULL DEFAULT '',
                created_at    TEXT NOT NULL,
                last_login_at TEXT
            );
            CREATE TABLE categories (
                id          INTEGER PRIMARY KEY,
                slug        TEXT NOT NULL UNIQUE,
                name        TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                sort        INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE posts (
                id                 INTEGER PRIMARY KEY,
                slug               TEXT NOT NULL UNIQUE,
                title              TEXT NOT NULL,
                excerpt            TEXT NOT NULL DEFAULT '',
                body               TEXT NOT NULL DEFAULT '',
                cover_image        TEXT NOT NULL DEFAULT '',
                cover_alt          TEXT NOT NULL DEFAULT '',
                category_id        INTEGER REFERENCES categories(id) ON DELETE SET NULL,
                author_id          INTEGER NOT NULL REFERENCES users(id),
                status             TEXT NOT NULL DEFAULT 'draft'
                                   CHECK (status IN ('draft','pending','changes_requested','published','archived')),
                seo_title          TEXT NOT NULL DEFAULT '',
                seo_description    TEXT NOT NULL DEFAULT '',
                created_at         TEXT NOT NULL,
                updated_at         TEXT NOT NULL,
                submitted_at       TEXT,
                published_at       TEXT,
                first_published_at TEXT,
                reviewed_by        INTEGER REFERENCES users(id)
            );
            CREATE INDEX idx_posts_public ON posts(status, published_at);
            CREATE INDEX idx_posts_author ON posts(author_id, status);
            CREATE TABLE post_events (
                id         INTEGER PRIMARY KEY,
                post_id    INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                user_id    INTEGER REFERENCES users(id),
                action     TEXT NOT NULL,
                note       TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL
            );
            CREATE INDEX idx_events_post ON post_events(post_id, id);
            CREATE TABLE settings (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );
            CREATE TABLE login_attempts (
                ip           TEXT NOT NULL,
                attempted_at INTEGER NOT NULL
            );
        ");
    }
    if ($v < 2) {
        // 0.11: pages (About, Contact… — same editor and workflow, no blog
        // listing) and pinned posts (featured at the top of the blog home).
        $pdo->exec("ALTER TABLE posts ADD COLUMN type TEXT NOT NULL DEFAULT 'post' CHECK (type IN ('post','page'))");
        $pdo->exec('ALTER TABLE posts ADD COLUMN pinned INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('CREATE INDEX idx_posts_type ON posts(type, status, published_at)');
    }
    if ($v < 3) {
        // 0.16: "Keep me signed in" devices. Only a SHA-256 of each device's
        // secret is stored; the secret itself lives in that device's cookie.
        $pdo->exec("
            CREATE TABLE devices (
                id           INTEGER PRIMARY KEY,
                user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                selector     TEXT NOT NULL UNIQUE,
                token_hash   TEXT NOT NULL,
                label        TEXT NOT NULL DEFAULT '',
                created_at   TEXT NOT NULL,
                last_used_at TEXT NOT NULL,
                expires_at   INTEGER NOT NULL
            );
            CREATE INDEX idx_devices_user ON devices(user_id);
        ");
    }
    if ($v < 4) {
        // 0.20: each writer's default category, preselected on their new posts.
        $pdo->exec('ALTER TABLE users ADD COLUMN default_category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL');
    }
    // Future schema changes go here as: if ($v < 5) { ... }
    $pdo->exec('PRAGMA user_version = ' . (int) PB_SCHEMA_VERSION);
    $pdo->commit();
}
function pb_q($sql, array $params = []) {
    $st = pb_db()->prepare($sql);
    foreach ($params as $k => $v) {
        $name = is_int($k) ? $k + 1 : (strpos($k, ':') === 0 ? $k : ':' . $k);
        $type = is_int($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue($name, $v, $type);
    }
    $st->execute();
    return $st;
}
function pb_row($sql, array $params = []) {
    $r = pb_q($sql, $params)->fetch();
    return $r === false ? null : $r;
}
function pb_all($sql, array $params = []) {
    return pb_q($sql, $params)->fetchAll();
}
function pb_val($sql, array $params = []) {
    return pb_q($sql, $params)->fetchColumn();
}

// ----------------------------------------------------------------------------
// SETTINGS
// ----------------------------------------------------------------------------
function pb_settings_defaults() {
    return [
        'blog_title'          => 'Blog',
        'blog_description'    => 'News, tips and updates from our team.',
        'posts_per_page'      => '9',
        'layout'              => 'auto',          // auto | georank | standalone
        'timezone'            => 'Asia/Kolkata',
        'pretty_urls'         => '1',
        'site_url'            => '',
        'language'            => 'en',
        'show_author'         => '1',
        'photo_metadata'      => 'keep',          // keep | strip — EXIF location, camera, date (lib/media.php)
        'georank_sso'         => '1',
        'georank_editor_role' => 'editor',        // what a GeoRank "Editor" login becomes here
        // Settings → Design. Empty = inherit (GeoRank site theme, or JotKite defaults).
        'design_social_image' => '',
        'design_favicon'      => '',
        'design_font_body'    => '',
        'design_font_heading' => '',
        'design_font_size'    => '',
        'design_accent'       => '',
        'design_text'         => '',
        'design_bg'           => '',
        'design_surface'      => '',
        // Settings → Navigation. JSON list of {label, url, new_tab}; '' = defaults.
        'nav_items'           => '',
        'nav_show_georank'    => '0',
        // Settings → Addons. Active theme slug ('' = built-in default) and active plugins (JSON list).
        'theme'               => '',
        'plugins'             => '[]',
        // Settings → General: a published Page as the homepage ('' = latest posts).
        'front_page'          => '',
        // Settings → Code: raw HTML/JS added to every public page (Admin only).
        'code_head'           => '',
        'code_footer'         => '',
        // Version bookkeeping: the version that last ran, and an upgrade notice
        // ({from, to, at} JSON) that Admins see until they dismiss it.
        'installed_version'   => '',
        'upgrade_notice'      => '',
        'rename_notice'       => '',              // '1' = show "PostBase is now JotKite" once
    ];
}

// ----------------------------------------------------------------------------
// UPGRADE NOTICE — new files can arrive without anyone clicking anything
// (a hosting panel's Git auto-deploy, GeoRank's installer, FTP). The first
// admin request on a new version records it, and Admins see "upgraded to
// version …" until they dismiss it.
// ----------------------------------------------------------------------------
function pb_version_check() {
    $known = (string) pb_setting('installed_version');
    if ($known === PB_VERSION) return;
    $save = ['installed_version' => PB_VERSION];
    // A demo restores an older snapshot every hour: the notice would keep coming back.
    if (pb_demo_on()) { pb_settings_save($save); return; }
    // An existing site (not a fresh install) that predates this bookkeeping: the old version is unknown.
    $existing = $known !== '' || (int) pb_val('SELECT COUNT(*) FROM users WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 3600)]) > 0;
    if ($existing) {
        $prev = json_decode((string) pb_setting('upgrade_notice'), true);
        // Several updates before anyone looked: keep the oldest "from".
        $from = is_array($prev) && isset($prev['from']) ? (string) $prev['from'] : $known;
        $save['upgrade_notice'] = json_encode(['from' => $from, 'to' => PB_VERSION, 'at' => pb_now()]);
        // 0.21.0: the product was renamed. Sites coming from an older version
        // (or an unknown one) tell their Admins once.
        if ($known === '' || version_compare($known, '0.21.0', '<')) $save['rename_notice'] = '1';
    }
    pb_settings_save($save);
}
function pb_upgrade_notice() {
    $n = json_decode((string) pb_setting('upgrade_notice'), true);
    return is_array($n) && !empty($n['to']) ? $n : null;
}
// CHANGELOG.md sections for versions after $from up to $to (newest first, at most 12).
function pb_changelog_between($from, $to) {
    $md = (string) @file_get_contents(PB_ROOT . '/CHANGELOG.md');
    $out = [];
    foreach (preg_split('/^(?=## )/m', $md) as $sec) {
        if (!preg_match('/^## ([0-9][0-9A-Za-z.\-]*)/', $sec, $m)) continue;
        $v = $m[1];
        if (version_compare($v, $to, '>')) continue;
        if ($from !== '' ? !version_compare($v, $from, '>') : $v !== $to) continue;
        $out[] = trim($sec);
        if (count($out) >= 12) break; // a site can skip many versions (e.g. a Git deploy after weeks)
    }
    return $out;
}
// Just enough Markdown for the changelog: headings, nested bullets, bold, code, links.
function pb_md_lite($md) {
    $inline = function ($s) {
        $s = pb_e($s);
        $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<![\w*])\*(?!\s)(.+?)\*(?!\w)/', '<em>$1</em>', $s);
        $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
        return preg_replace('/\[([^\]]+)\]\((https:\/\/[^)\s"]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $s);
    };
    $html = '';
    $depth = 0;
    foreach (preg_split('/\R/', (string) $md) as $line) {
        if (preg_match('/^(\s*)- (.*)$/', $line, $m)) {
            $want = intdiv(strlen($m[1]), 2) + 1;
            while ($depth < $want) { $html .= '<ul>'; $depth++; }
            while ($depth > $want) { $html .= '</ul>'; $depth--; }
            $html .= '<li>' . $inline($m[2]) . '</li>';
            continue;
        }
        while ($depth > 0) { $html .= '</ul>'; $depth--; }
        if (preg_match('/^## (.*)$/', $line, $m)) $html .= '<h4>' . $inline($m[1]) . '</h4>';
        elseif (trim($line) !== '') $html .= '<p>' . $inline($line) . '</p>';
    }
    while ($depth > 0) { $html .= '</ul>'; $depth--; }
    return $html;
}

// Font choices for Settings → Design. Web-safe stacks only, so the blog never
// loads a third-party font (same set GeoRank offers).
function pb_font_choices() {
    return [
        ''          => ['Site theme (inherit)', ''],
        'system'    => ['System UI', 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif'],
        'arial'     => ['Arial', 'Arial, Helvetica, sans-serif'],
        'helvetica' => ['Helvetica', 'Helvetica, Arial, sans-serif'],
        'verdana'   => ['Verdana', 'Verdana, Geneva, sans-serif'],
        'tahoma'    => ['Tahoma', 'Tahoma, Verdana, sans-serif'],
        'trebuchet' => ['Trebuchet MS', '"Trebuchet MS", Helvetica, sans-serif'],
        'georgia'   => ['Georgia (serif)', 'Georgia, "Times New Roman", serif'],
        'palatino'  => ['Palatino (serif)', '"Palatino Linotype", Palatino, "Book Antiqua", serif'],
    ];
}
function pb_valid_hex($v) {
    $v = trim((string) $v);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : '';
}

// Navigation links shown in the standalone header (and, optionally, as a slim
// bar on GeoRank sites). Defaults: site home, blog, contact.
function pb_nav_defaults() {
    $site = pb_site_base_path();
    if (PB_BASE_PATH === '') { // blog is the whole site: no separate home or contact page
        $items = [['label' => 'Home', 'url' => '/', 'new_tab' => false]];
        if (pb_front_page()) $items[] = ['label' => 'Blog', 'url' => pb_url('posts'), 'new_tab' => false];
        $items[] = ['label' => 'RSS', 'url' => pb_url('feed'), 'new_tab' => false];
        return $items;
    }
    return [
        ['label' => 'Home', 'url' => $site . '/', 'new_tab' => false],
        ['label' => 'Blog', 'url' => pb_url('posts'), 'new_tab' => false],
        ['label' => 'Contact', 'url' => $site . '/contact.html', 'new_tab' => false],
    ];
}
function pb_nav_items() {
    $raw = (string) pb_setting('nav_items');
    $items = $raw !== '' ? json_decode($raw, true) : null;
    return pb_apply_filters('pb_nav_items', is_array($items) ? $items : pb_nav_defaults());
}
// Cleans a submitted nav list: drops blank rows and unsafe URLs, caps at 12 links.
function pb_nav_clean(array $rows) {
    $out = [];
    foreach ($rows as $r) {
        $label = trim((string) ($r['label'] ?? ''));
        $url = trim((string) ($r['url'] ?? ''));
        if ($label === '' || $url === '' || pb_safe_url($url) === null) continue;
        $out[] = ['label' => substr($label, 0, 40), 'url' => substr($url, 0, 300), 'new_tab' => !empty($r['new_tab'])];
        if (count($out) >= 12) break;
    }
    return $out;
}

// Image for cards, og:image and JSON-LD: the post's cover, else the first
// image in its body, else the blog's default social image (Settings → Design).
function pb_post_image($post) {
    if (!empty($post['cover_image'])) return $post['cover_image'];
    if (preg_match('/<img\b[^>]*\bsrc="([^"]+)"/i', (string) ($post['body'] ?? ''), $m)) {
        $src = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        if (pb_safe_url($src) !== null) return $src;
    }
    return (string) pb_setting('design_social_image');
}
function pb_setting($key) {
    static $cache = null;
    if ($key === null) { $cache = null; return null; } // cache reset
    if ($cache === null) {
        $cache = pb_settings_defaults();
        foreach (pb_all('SELECT key, value FROM settings') as $r) $cache[$r['key']] = $r['value'];
    }
    return $cache[$key] ?? null;
}
function pb_settings_save(array $values) {
    $st = pb_db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    foreach ($values as $k => $v) $st->execute([$k, (string) $v]);
    pb_setting(null);
}

// ----------------------------------------------------------------------------
// USERS, LOGIN, GEORANK SINGLE SIGN-ON
// ----------------------------------------------------------------------------
function pb_user_by_id($id) {
    return pb_row('SELECT * FROM users WHERE id = ? AND active = 1', [(int) $id]);
}
function pb_count_users() {
    return (int) pb_val('SELECT COUNT(*) FROM users');
}
function pb_georank_session_role() {
    if (pb_setting('georank_sso') !== '1') return null;
    if (empty($_SESSION['georank_auth'])) return null;
    $r = $_SESSION['georank_role'] ?? '';
    return in_array($r, ['admin', 'editor'], true) ? $r : null;
}
// One shared JotKite account per GeoRank role (GeoRank logins are
// role-based, not per person). Created on first use; the display name can be
// changed from My Account.
function pb_georank_user($georankRole) {
    $email = 'georank-' . $georankRole . '@georank.local';
    $u = pb_row('SELECT * FROM users WHERE email = ?', [$email]);
    $role = $georankRole === 'admin' ? 'admin' : pb_setting('georank_editor_role');
    if (!in_array($role, PB_ROLES, true)) $role = 'editor';
    if (!$u) {
        pb_q('INSERT INTO users (email, name, role, source, created_at) VALUES (?, ?, ?, ?, ?)',
            [$email, $georankRole === 'admin' ? 'Site Admin' : 'Site Editor', $role, 'georank', pb_now()]);
        $u = pb_row('SELECT * FROM users WHERE email = ?', [$email]);
    } elseif ($u['role'] !== $role) {
        pb_q('UPDATE users SET role = ? WHERE id = ?', [$role, $u['id']]);
        $u['role'] = $role;
    }
    return (int) $u['active'] === 1 ? $u : null;
}
function pb_current_user() {
    static $resolved = false, $user = null;
    if ($resolved) return $user;
    $resolved = true;
    if (session_status() !== PHP_SESSION_ACTIVE) return null;
    if (!empty($_SESSION['pb_uid'])) {
        $user = pb_user_by_id($_SESSION['pb_uid']);
        if ($user) return $user;
        unset($_SESSION['pb_uid']);
    }
    $gr = pb_georank_session_role();
    if ($gr) $user = pb_georank_user($gr);
    return $user;
}
function pb_login_throttled($ip) {
    pb_q('DELETE FROM login_attempts WHERE attempted_at < ?', [time() - 900]);
    return (int) pb_val('SELECT COUNT(*) FROM login_attempts WHERE ip = ?', [$ip]) >= 8;
}
function pb_attempt_login($email, $password) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
    if (pb_login_throttled($ip)) return 'Too many attempts. Wait 15 minutes and try again.';
    $u = pb_row("SELECT * FROM users WHERE email = ? AND source = 'local'", [trim((string) $email)]);
    if (!$u || !$u['password_hash'] || !password_verify((string) $password, $u['password_hash'])) {
        pb_q('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)', [$ip, time()]);
        return 'Wrong email or password.';
    }
    if ((int) $u['active'] !== 1) return 'This account has been deactivated.';
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        pb_q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['pb_uid'] = (int) $u['id'];
    pb_q('UPDATE users SET last_login_at = ? WHERE id = ?', [pb_now(), $u['id']]);
    pb_q('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
    return null;
}
// ----------------------------------------------------------------------------
// "KEEP ME SIGNED IN" — typing a long email and password on a phone is the
// hardest part of mobile publishing. After one sign-in, a device (e.g. the
// JotKite app on the home screen) stays signed in for PB_DEVICE_DAYS days
// of inactivity. Cookie = selector:secret; the database keeps only a hash of
// the secret, so a leaked database can't sign anyone in. Each device can be
// signed out from My account; a password change signs out all other devices.
// ----------------------------------------------------------------------------
define('PB_DEVICE_COOKIE', 'pb_device');
define('PB_DEVICE_DAYS', 180);

function pb_device_cookie($value, $expires) {
    setcookie(PB_DEVICE_COOKIE, $value, ['expires' => $expires, 'path' => PB_BASE_PATH . '/admin/',
        'secure' => pb_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}
function pb_device_label() {
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $os = preg_match('/iPhone/', $ua) ? 'iPhone' : (preg_match('/iPad/', $ua) ? 'iPad' : (preg_match('/Android/', $ua) ? 'Android'
        : (preg_match('/Windows/', $ua) ? 'Windows' : (preg_match('/Macintosh/', $ua) ? 'Mac' : (preg_match('/CrOS/', $ua) ? 'Chromebook' : (preg_match('/Linux/', $ua) ? 'Linux' : 'Device'))))));
    $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/SamsungBrowser/', $ua) ? 'Samsung Internet' : (preg_match('/Firefox|FxiOS/', $ua) ? 'Firefox'
        : (preg_match('/Chrome|CriOS/', $ua) ? 'Chrome' : (preg_match('/Safari/', $ua) ? 'Safari' : ''))));
    return trim($os . ($br !== '' ? ' · ' . $br : ''));
}
function pb_device_remember($userId) {
    $selector = bin2hex(random_bytes(9));
    $secret = bin2hex(random_bytes(32));
    $expires = time() + PB_DEVICE_DAYS * 86400;
    pb_q('INSERT INTO devices (user_id, selector, token_hash, label, created_at, last_used_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [(int) $userId, $selector, hash('sha256', $secret), pb_device_label(), pb_now(), pb_now(), $expires]);
    pb_device_cookie($selector . ':' . $secret, $expires);
    $_SESSION['pb_device'] = $selector;
}
// Signs the visitor in from their device cookie when the session has ended.
function pb_device_login() {
    $raw = (string) ($_COOKIE[PB_DEVICE_COOKIE] ?? '');
    if ($raw === '' || !empty($_SESSION['pb_uid'])) return;
    $parts = explode(':', $raw, 2);
    $row = count($parts) === 2 && ctype_xdigit($parts[0]) ? pb_row('SELECT * FROM devices WHERE selector = ?', [$parts[0]]) : null;
    $valid = $row && hash_equals($row['token_hash'], hash('sha256', $parts[1])) && (int) $row['expires_at'] > time();
    $u = $valid ? pb_user_by_id($row['user_id']) : null;
    if (!$u || $u['source'] !== 'local') {
        if ($row && !$valid) pb_q('DELETE FROM devices WHERE id = ?', [$row['id']]); // wrong secret or expired: retire it
        pb_device_cookie('', time() - 3600);
        return;
    }
    session_regenerate_id(true);
    $_SESSION['pb_uid'] = (int) $u['id'];
    $_SESSION['pb_device'] = $row['selector'];
    $expires = time() + PB_DEVICE_DAYS * 86400; // sliding: every visit extends it
    pb_q('UPDATE devices SET last_used_at = ?, expires_at = ?, label = ? WHERE id = ?', [pb_now(), $expires, pb_device_label(), $row['id']]);
    pb_q('UPDATE users SET last_login_at = ? WHERE id = ?', [pb_now(), $u['id']]);
    pb_device_cookie($raw, $expires);
}
function pb_device_forget_current() {
    if (!empty($_SESSION['pb_device'])) pb_q('DELETE FROM devices WHERE selector = ?', [(string) $_SESSION['pb_device']]);
    unset($_SESSION['pb_device']);
    if (isset($_COOKIE[PB_DEVICE_COOKIE])) pb_device_cookie('', time() - 3600);
}
// All of a user's devices, optionally except the current one.
function pb_device_forget_all($userId, $keepCurrent = false) {
    $keep = $keepCurrent ? (string) ($_SESSION['pb_device'] ?? '') : '';
    pb_q('DELETE FROM devices WHERE user_id = ? AND selector != ?', [(int) $userId, $keep]);
}

function pb_role_label($role) {
    return ['contributor' => 'Contributor', 'editor' => 'Editor', 'admin' => 'Admin'][$role] ?? $role;
}

// ----------------------------------------------------------------------------
// PERMISSIONS — the whole publishing-control matrix lives here.
//
//   Contributor : writes own drafts, submits them for review, edits them
//                 until approved. Never publishes.
//   Editor      : everything a contributor can, plus edits any post,
//                 approves/publishes/schedules, requests changes,
//                 unpublishes, archives, manages categories.
//   Admin       : everything an editor can, plus users, settings and
//                 permanent deletion.
// ----------------------------------------------------------------------------
function pb_can($user, $perm, $post = null) {
    if (!$user) return false;
    $role = $user['role'];
    $isEditor = $role === 'editor' || $role === 'admin';
    $own = $post && isset($post['author_id']) && (int) $post['author_id'] === (int) $user['id'];
    $status = $post['status'] ?? '';
    switch ($perm) {
        case 'post.create':
        case 'media.upload':
            return true;
        case 'post.view':
            return $isEditor || $own;
        case 'post.edit':
            return $isEditor || ($own && in_array($status, ['draft', 'pending', 'changes_requested'], true));
        case 'post.submit':
            return $own && in_array($status, ['draft', 'changes_requested'], true);
        case 'post.withdraw':
            return $own && $status === 'pending';
        case 'post.publish':
            return $isEditor && $status !== 'archived';
        case 'post.request_changes':
            return $isEditor && $status === 'pending';
        case 'post.unpublish':
            return $isEditor && $status === 'published';
        case 'post.archive':
            return $isEditor && $status !== 'archived';
        case 'post.restore':
            return $isEditor && $status === 'archived';
        case 'post.delete':
            return $role === 'admin' || ($own && $status === 'draft' && empty($post['first_published_at']));
        case 'category.manage':
        case 'media.delete':
            return $isEditor;
        case 'user.manage':
        case 'settings.manage':
            return $role === 'admin';
    }
    return false;
}

// ----------------------------------------------------------------------------
// POSTS
// ----------------------------------------------------------------------------
function pb_status_label($status, $publishedAt = null) {
    if ($status === 'published' && $publishedAt && $publishedAt > pb_now()) return 'Scheduled';
    return [
        'draft' => 'Draft', 'pending' => 'Pending review', 'changes_requested' => 'Changes requested',
        'published' => 'Published', 'archived' => 'Archived',
    ][$status] ?? $status;
}
function pb_post_by_id($id) {
    return pb_row('SELECT p.*, u.name AS author_name, c.name AS category_name, c.slug AS category_slug
                   FROM posts p JOIN users u ON u.id = p.author_id LEFT JOIN categories c ON c.id = p.category_id
                   WHERE p.id = ?', [(int) $id]);
}
function pb_post_by_slug($slug) {
    return pb_row('SELECT p.*, u.name AS author_name, c.name AS category_name, c.slug AS category_slug
                   FROM posts p JOIN users u ON u.id = p.author_id LEFT JOIN categories c ON c.id = p.category_id
                   WHERE p.slug = ?', [(string) $slug]);
}
function pb_post_is_public($post) {
    return $post && $post['status'] === 'published' && $post['published_at'] && $post['published_at'] <= pb_now();
}
function pb_unique_slug($base, $table = 'posts', $exceptId = 0) {
    $base = pb_slugify($base) ?: ($table === 'posts' ? 'post' : 'category');
    if (in_array($base, PB_RESERVED_SLUGS, true)) $base .= '-1';
    $slug = $base;
    $n = 2;
    while ((int) pb_val("SELECT COUNT(*) FROM {$table} WHERE slug = ? AND id != ?", [$slug, (int) $exceptId]) > 0) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}
function pb_log_event($postId, $userId, $action, $note = '') {
    pb_q('INSERT INTO post_events (post_id, user_id, action, note, created_at) VALUES (?, ?, ?, ?, ?)',
        [(int) $postId, $userId ? (int) $userId : null, $action, (string) $note, pb_now()]);
}
function pb_post_events($postId) {
    return pb_all('SELECT e.*, u.name AS user_name FROM post_events e LEFT JOIN users u ON u.id = e.user_id
                   WHERE e.post_id = ? ORDER BY e.id DESC LIMIT 50', [(int) $postId]);
}

// Create or update a post's content. Status changes go through
// pb_post_transition() so every one of them is permission-checked and logged.
// Returns [postId, error|null].
function pb_post_save($user, $postId, array $in) {
    $post = $postId ? pb_post_by_id($postId) : null;
    if ($postId && !$post) return [0, 'Post not found.'];
    if ($post && !pb_can($user, 'post.edit', $post)) return [$postId, 'You can\'t edit this post in its current state.'];
    if (!$post && !pb_can($user, 'post.create')) return [0, 'You can\'t create posts.'];

    $title = trim((string) ($in['title'] ?? ''));
    // Photo posts often have no title: name them after their publish time,
    // e.g. "Post published on 25/09/2026 @ 10.50" (site timezone).
    if ($title === '') {
        $when = ($post && $post['status'] === 'published' ? $post['published_at'] : null)
            ?: pb_local_to_utc($in['publish_at'] ?? '') ?: pb_now();
        $title = ($post['type'] ?? ($in['type'] ?? 'post')) === 'page' ? 'Untitled page' : 'Post published on ' . pb_format_date($when, 'd/m/Y @ H.i');
    }
    $title = function_exists('mb_substr') ? mb_substr($title, 0, 200) : substr($title, 0, 200);
    $body = pb_sanitize_html($in['body'] ?? '');
    $excerpt = trim(strip_tags((string) ($in['excerpt'] ?? '')));
    $slugIn = trim((string) ($in['slug'] ?? ''));
    $slug = pb_unique_slug($slugIn !== '' ? $slugIn : $title, 'posts', $post ? (int) $post['id'] : 0);
    $catId = (int) ($in['category_id'] ?? 0);
    if ($catId && !pb_val('SELECT id FROM categories WHERE id = ?', [$catId])) $catId = 0;
    $cover = trim((string) ($in['cover_image'] ?? ''));
    if ($cover !== '' && pb_safe_url($cover) === null) $cover = '';

    $fields = [
        'slug' => $slug, 'title' => $title, 'excerpt' => $excerpt, 'body' => $body,
        'cover_image' => $cover, 'cover_alt' => trim((string) ($in['cover_alt'] ?? '')),
        'category_id' => $catId ?: null,
        'seo_title' => trim((string) ($in['seo_title'] ?? '')),
        'seo_description' => trim((string) ($in['seo_description'] ?? '')),
        'updated_at' => pb_now(),
    ];
    // Pages and pinning are site structure: Editors/Admins only. A contributor's
    // save never changes them (new posts from contributors are always 'post').
    if (pb_can($user, 'category.manage')) {
        $type = ($in['type'] ?? ($post['type'] ?? 'post')) === 'page' ? 'page' : 'post';
        $fields['type'] = $type;
        $fields['pinned'] = $type === 'post' && !empty($in['pinned']) ? 1 : 0;
        if ($type === 'page') $fields['category_id'] = null;
    } elseif (!$post) {
        $fields['type'] = 'post';
    }
    // Only editors decide when a post goes live.
    if (pb_can($user, 'post.publish', $post ?: ['status' => 'draft']) && array_key_exists('published_at', $in) && $post && $post['status'] === 'published') {
        $when = pb_local_to_utc($in['published_at']);
        if ($when) $fields['published_at'] = $when;
    }

    if ($post) {
        $sets = [];
        foreach ($fields as $k => $v) $sets[] = "{$k} = :{$k}";
        $fields['id'] = (int) $post['id'];
        pb_q('UPDATE posts SET ' . implode(', ', $sets) . ' WHERE id = :id', $fields);
        if ((int) $post['author_id'] !== (int) $user['id']) pb_log_event($post['id'], $user['id'], 'edited');
        pb_do_action('pb_post_saved', (int) $post['id'], $user);
        return [(int) $post['id'], null];
    }
    $fields['author_id'] = (int) $user['id'];
    $fields['status'] = 'draft';
    $fields['created_at'] = pb_now();
    $cols = array_keys($fields);
    pb_q('INSERT INTO posts (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')', $fields);
    $id = (int) pb_db()->lastInsertId();
    pb_log_event($id, $user['id'], $fields['type'] === 'page' ? 'created page' : 'created');
    pb_do_action('pb_post_saved', $id, $user);
    return [$id, null];
}

// Workflow transitions. Returns null on success or an error string.
function pb_post_transition($user, $post, $action, $note = '', $publishAtLocal = '') {
    $note = trim((string) $note);
    $now = pb_now();
    switch ($action) {
        case 'submit':
            if (!pb_can($user, 'post.submit', $post)) return 'This post can\'t be submitted right now.';
            pb_q("UPDATE posts SET status = 'pending', submitted_at = ? WHERE id = ?", [$now, $post['id']]);
            break;
        case 'withdraw':
            if (!pb_can($user, 'post.withdraw', $post)) return 'This post isn\'t waiting for review.';
            pb_q("UPDATE posts SET status = 'draft' WHERE id = ?", [$post['id']]);
            break;
        case 'publish':
            if (!pb_can($user, 'post.publish', $post)) return 'Only an Editor or Admin can publish.';
            $when = pb_local_to_utc($publishAtLocal) ?: ($post['status'] === 'published' && $post['published_at'] ? $post['published_at'] : $now);
            pb_q("UPDATE posts SET status = 'published', published_at = ?, first_published_at = COALESCE(first_published_at, ?),
                  reviewed_by = ? WHERE id = ?", [$when, $when, $user['id'], $post['id']]);
            // An automatic "Post published on …" title follows the real publish time.
            if (preg_match('~^Post published on \d\d/\d\d/\d{4} @ \d\d\.\d\d$~', $post['title'])) {
                pb_q('UPDATE posts SET title = ? WHERE id = ?', ['Post published on ' . pb_format_date($when, 'd/m/Y @ H.i'), $post['id']]);
            }
            $action = $when > $now ? 'scheduled' : ($post['status'] === 'pending' ? 'approved' : 'published');
            break;
        case 'request_changes':
            if (!pb_can($user, 'post.request_changes', $post)) return 'Only a post waiting for review can be sent back.';
            if ($note === '') return 'Add a note so the writer knows what to change.';
            pb_q("UPDATE posts SET status = 'changes_requested', reviewed_by = ? WHERE id = ?", [$user['id'], $post['id']]);
            break;
        case 'unpublish':
            if (!pb_can($user, 'post.unpublish', $post)) return 'This post isn\'t published.';
            pb_q("UPDATE posts SET status = 'draft' WHERE id = ?", [$post['id']]);
            break;
        case 'archive':
            if (!pb_can($user, 'post.archive', $post)) return 'You can\'t archive this post.';
            pb_q("UPDATE posts SET status = 'archived' WHERE id = ?", [$post['id']]);
            break;
        case 'restore':
            if (!pb_can($user, 'post.restore', $post)) return 'This post isn\'t archived.';
            pb_q("UPDATE posts SET status = 'draft' WHERE id = ?", [$post['id']]);
            break;
        case 'delete':
            if (!pb_can($user, 'post.delete', $post)) return 'You can\'t delete this post.';
            pb_q('DELETE FROM posts WHERE id = ?', [$post['id']]);
            pb_do_action('pb_post_deleted', $post, $user);
            return null;
        default:
            return 'Unknown action.';
    }
    pb_log_event($post['id'], $user['id'], $action, $note);
    pb_do_action('pb_post_status_changed', pb_post_by_id($post['id']), $action, $user, $note);
    return null;
}

// ----------------------------------------------------------------------------
// HTML SANITIZER — every body is cleaned on save, so a contributor can never
// store script, event handlers, styles or unknown embeds.
// ----------------------------------------------------------------------------
function pb_safe_url($url) {
    $url = trim((string) $url);
    if ($url === '') return null;
    $probe = preg_replace('/[\x00-\x20\x7f]+/', '', $url);
    if (preg_match('~^([a-z][a-z0-9+.\-]*):~i', $probe, $m)
        && !in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true)) {
        return null;
    }
    return $url;
}
function pb_embed_allowed($src) {
    return (bool) preg_match('~^https://(www\.)?(youtube\.com|youtube-nocookie\.com)/embed/[\w\-]+~i', $src)
        || (bool) preg_match('~^https://player\.vimeo\.com/video/\d+~i', $src)
        || (bool) preg_match('~^https://www\.google\.com/maps/embed\?~i', $src);
}
function pb_sanitize_html($html) {
    $html = trim((string) $html);
    if ($html === '') return '';
    if (!class_exists('DOMDocument')) {
        return '<p>' . nl2br(pb_e(strip_tags($html))) . '</p>';
    }
    $useMb = function_exists('mb_encode_numericentity');
    $map = [0x80, 0x10FFFF, 0, 0x1FFFFF];
    if ($useMb) $html = mb_encode_numericentity($html, $map, 'UTF-8');

    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="pb-root">'
        . $html . '</div></body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = (new DOMXPath($doc))->query('//div[@id="pb-root"]')->item(0);
    if (!$root) return '';
    pb_sanitize_children($doc, $root);

    $out = '';
    foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
    if ($useMb) $out = mb_decode_numericentity($out, $map, 'UTF-8');
    $out = preg_replace('~<p>(\s|&nbsp;|<br>)*</p>~i', '', $out); // empty paragraphs from the editor
    return trim($out);
}
function pb_sanitize_children(DOMDocument $doc, DOMNode $node) {
    static $allowed = [
        'p' => [], 'br' => [], 'h2' => ['id'], 'h3' => ['id'], 'h4' => ['id'],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'sub' => [], 'sup' => [], 'mark' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'pre' => [], 'code' => [], 'hr' => [],
        'img' => ['src', 'alt', 'width', 'height', 'title', 'loading'],
        'figure' => ['class'], 'figcaption' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
        'iframe' => ['src', 'width', 'height', 'title', 'allowfullscreen', 'loading'],
    ];
    static $drop = ['script', 'style', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'option',
        'svg', 'math', 'template', 'noscript', 'meta', 'link', 'base', 'head', 'title', 'applet', 'frame', 'frameset',
        'audio', 'video', 'canvas', 'dialog'];
    static $rename = ['h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4'];
    static $blocks = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'table', 'blockquote', 'pre', 'figure', 'section', 'article'];

    $children = [];
    foreach ($node->childNodes as $c) $children[] = $c;
    foreach ($children as $child) {
        if ($child instanceof DOMText) continue; // includes CDATA
        if (!($child instanceof DOMElement)) { $node->removeChild($child); continue; } // comments, PIs
        $tag = strtolower($child->nodeName);
        if (in_array($tag, $drop, true)) { $node->removeChild($child); continue; }

        if ($tag === 'div' || $tag === 'section' || $tag === 'article') {
            $hasBlock = false;
            foreach ($child->childNodes as $gc) {
                if ($gc instanceof DOMElement && in_array(strtolower($gc->nodeName), $blocks, true)) { $hasBlock = true; break; }
            }
            if ($hasBlock) { // unwrap: keep the inner blocks, lose the wrapper
                pb_sanitize_children($doc, $child);
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            $tag = 'p';
            $child = pb_rename_element($doc, $child, 'p');
        } elseif (isset($rename[$tag])) {
            $child = pb_rename_element($doc, $child, $rename[$tag]);
            $tag = $rename[$tag];
        }
        if (!isset($allowed[$tag])) { // span, font, etc.: unwrap
            pb_sanitize_children($doc, $child);
            while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
            $node->removeChild($child);
            continue;
        }

        // Text alignment (editor's align buttons, or pasted centred text) is the one bit of
        // styling kept: centre or right on text blocks, nothing else from a style attribute.
        $align = '';
        if (in_array($tag, ['p', 'h2', 'h3', 'h4', 'li', 'blockquote', 'td', 'th'], true)) {
            if (preg_match('/(?:^|;)\s*text-align\s*:\s*(center|right)\s*(?:;|$)/i', $child->getAttribute('style'), $am)) $align = strtolower($am[1]);
            elseif (preg_match('/^(center|right)$/i', $child->getAttribute('align'))) $align = strtolower($child->getAttribute('align'));
        }
        $names = [];
        foreach ($child->attributes as $a) $names[] = $a->nodeName;
        foreach ($names as $name) {
            if (!in_array(strtolower($name), $allowed[$tag], true)) $child->removeAttribute($name);
        }
        if ($align !== '') $child->setAttribute('style', 'text-align: ' . $align);
        if ($tag === 'a') {
            if ($child->hasAttribute('href') && pb_safe_url($child->getAttribute('href')) === null) $child->removeAttribute('href');
            if ($child->getAttribute('target') === '_blank') {
                $child->setAttribute('rel', 'noopener noreferrer');
            } else {
                $child->removeAttribute('target');
                if ($child->hasAttribute('rel') && !preg_match('/^[a-z ]+$/i', $child->getAttribute('rel'))) $child->removeAttribute('rel');
            }
        } elseif ($tag === 'img') {
            $src = $child->getAttribute('src');
            if (pb_safe_url($src) === null || stripos($src, 'mailto:') === 0 || stripos($src, 'tel:') === 0) {
                $node->removeChild($child);
                continue;
            }
            $child->setAttribute('loading', 'lazy');
            foreach (['width', 'height'] as $dim) {
                if ($child->hasAttribute($dim) && !ctype_digit($child->getAttribute($dim))) $child->removeAttribute($dim);
            }
        } elseif ($tag === 'figure') {
            // Only JotKite's own layout classes survive (image wrap/size, video embed).
            $keep = array_filter(preg_split('/\s+/', $child->getAttribute('class')), function ($c) {
                return (bool) preg_match('/^pb-(figure|embed|align-(left|right|center)|w-(s|m|l|full))$/', $c);
            });
            if ($keep) $child->setAttribute('class', implode(' ', array_unique($keep)));
            else $child->removeAttribute('class');
        } elseif ($tag === 'iframe') {
            if (!pb_embed_allowed($child->getAttribute('src'))) { $node->removeChild($child); continue; }
            $child->setAttribute('loading', 'lazy');
            $child->setAttribute('allowfullscreen', '');
            // YouTube refuses to play (Error 153) when the embed sends no referrer.
            $child->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
            $child->setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
            if (!$child->hasAttribute('title')) $child->setAttribute('title', 'Embedded video');
        } elseif (in_array($tag, ['h2', 'h3', 'h4'], true) && $child->hasAttribute('id')) {
            $child->setAttribute('id', pb_slugify($child->getAttribute('id'), 60));
        }
        pb_sanitize_children($doc, $child);
    }
}
function pb_rename_element(DOMDocument $doc, DOMElement $el, $newTag) {
    $new = $doc->createElement($newTag);
    foreach ($el->attributes as $a) $new->setAttribute($a->nodeName, $a->nodeValue);
    while ($el->firstChild) $new->appendChild($el->firstChild);
    $el->parentNode->replaceChild($new, $el);
    return $new;
}

// ----------------------------------------------------------------------------
// UPLOADS — images only, re-validated from the file bytes, never trusted by
// extension. uploads/.htaccess also stops any script from running there.
// ----------------------------------------------------------------------------
function pb_handle_upload($file) {
    if (!is_array($file) || !isset($file['error'])) return ['error' => 'No file received.'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'That file is larger than the server allows.' : 'Upload failed (code ' . (int) $file['error'] . ').'];
    }
    $maxBytes = (int) (pb_config('max_upload_mb') * 1024 * 1024);
    if ($file['size'] > $maxBytes) return ['error' => 'Images must be ' . pb_config('max_upload_mb') . ' MB or smaller.'];
    return pb_store_image($file['tmp_name'], (string) $file['name'], true);
}

// Validates an image file on disk (by its bytes, never its name), resizes it if
// needed and moves it into uploads/YYYY/MM/. Shared by uploads and imports.
function pb_store_image($tmpPath, $origName, $isUpload) {
    $info = @getimagesize($tmpPath);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
    if (defined('IMAGETYPE_WEBP')) $types[IMAGETYPE_WEBP] = 'webp';
    if (!$info || !isset($types[$info[2]])) return ['error' => 'Only JPG, PNG, GIF and WebP images can be uploaded.'];
    $ext = $types[$info[2]];

    $sub = gmdate('Y') . '/' . gmdate('m');
    pb_ensure_protection();
    $dir = PB_UPLOAD_DIR . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return ['error' => 'Could not create the uploads folder.'];
    $base = pb_slugify(pathinfo($origName, PATHINFO_FILENAME), 40) ?: 'image';
    $name = $base . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    $dest = $dir . '/' . $name;

    [$w, $h] = [$info[0], $info[1]];
    $max = (int) pb_config('max_image_px');
    // Photo metadata (EXIF: location, camera, date) is kept unless Settings →
    // General says to remove it. See lib/media.php.
    $strip = pb_setting('photo_metadata') === 'strip';
    $jpeg = $ext === 'jpg' ? (string) file_get_contents($tmpPath) : '';
    $exif = $jpeg !== '' ? pb_jpeg_exif_segment($jpeg) : '';
    $orient = $exif !== '' ? (int) (pb_exif_parse($exif)['orientation'] ?? 1) : 1;
    $orient = $orient >= 2 && $orient <= 8 ? $orient : 1;
    $sideways = $orient >= 5;
    // Re-encode when too wide, or when metadata must go but the camera's
    // rotation lives only in that metadata (the pixels need turning first).
    $resized = false;
    $mustTurn = $strip && $orient !== 1;
    if (($sideways ? $h : $w) > $max || $mustTurn) {
        if ($ext !== 'gif' && function_exists('imagecreatetruecolor')) {
            $loaders = ['jpg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'];
            $savers  = ['jpg' => 'imagejpeg', 'png' => 'imagepng', 'webp' => 'imagewebp'];
            $src = function_exists($loaders[$ext]) && function_exists($savers[$ext]) ? @$loaders[$ext]($tmpPath) : false;
            if ($src) {
                if ($orient !== 1) {
                    $src = pb_gd_orient($src, $orient);
                    [$w, $h] = [imagesx($src), imagesy($src)];
                }
                $nw = min($w, $max);
                $nh = (int) round($h * $nw / $w);
                $dst = imagecreatetruecolor($nw, $nh);
                if ($ext !== 'jpg') { imagealphablending($dst, false); imagesavealpha($dst, true); }
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                $ok = $ext === 'jpg' ? imagejpeg($dst, $dest, 85) : ($ext === 'png' ? imagepng($dst, $dest, 7) : imagewebp($dst, $dest, 82));
                imagedestroy($src);
                imagedestroy($dst);
                if ($ok) {
                    $resized = true;
                    [$w, $h] = [$nw, $nh];
                    // GD drops EXIF: put it back, marked upright since the pixels now are.
                    if (!$strip && $exif !== '') @file_put_contents($dest, pb_jpeg_add_exif((string) file_get_contents($dest), pb_exif_upright($exif)));
                }
            }
        }
    }
    if (!$resized) {
        if ($strip && $exif !== '') {
            $moved = @file_put_contents($dest, pb_jpeg_strip_meta($jpeg)) !== false;
            if ($moved) @unlink($tmpPath);
        } else {
            $moved = $isUpload ? move_uploaded_file($tmpPath, $dest) : @rename($tmpPath, $dest);
        }
        if (!$moved) return ['error' => 'Could not save the image.'];
        if (!$strip && $sideways) [$w, $h] = [$h, $w]; // shown upright by the browser
    } elseif (!$isUpload) {
        @unlink($tmpPath);
    }
    @chmod($dest, 0644);
    return ['url' => PB_BASE_PATH . '/uploads/' . $sub . '/' . $name, 'width' => $w, 'height' => $h];
}

// Copies an image the writer pasted (from Google Docs, WordPress, a web page…)
// into this blog's uploads, so the post never depends on — or breaks with —
// someone else's server. Guarded against SSRF: http(s) only, public IPs only
// (checked at every redirect hop, with the connection pinned to the checked
// IP), size-capped while downloading, and the bytes must be a real image.
function pb_import_remote_image($url) {
    if (!function_exists('curl_init')) return ['error' => 'This server can\'t fetch images (cURL is off). Upload the image instead.'];
    $maxBytes = (int) (pb_config('max_upload_mb') * 1024 * 1024);
    $url = trim((string) $url);
    if (strpos($url, '//') === 0) $url = 'https:' . $url;
    for ($hop = 0; $hop < 4; $hop++) {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') return ['error' => 'Only http(s) image links can be imported.'];
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
        if (!$ips) return ['error' => 'Couldn\'t find the server for ' . $host . '.'];
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return ['error' => 'That image address points to a private network and was blocked.'];
            }
        }
        $body = '';
        $tooBig = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => 'JotKite/' . PB_VERSION . ' (+' . PB_HOMEPAGE . ')',
            CURLOPT_HTTPHEADER => ['Accept: image/webp,image/png,image/jpeg,image/gif;q=0.9,*/*;q=0.1'], // no AVIF: GD can't store it
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body, &$tooBig, $maxBytes) {
                $body .= $chunk;
                if (strlen($body) > $maxBytes) { $tooBig = true; return 0; }
                return strlen($chunk);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err = curl_error($ch);
        curl_close($ch);
        if ($tooBig) return ['error' => 'That image is larger than ' . pb_config('max_upload_mb') . ' MB.'];
        if ($code >= 300 && $code < 400 && $location !== '') { $url = $location; continue; }
        if ($code !== 200 || $body === '') return ['error' => 'Couldn\'t download the image' . ($err ? ' (' . $err . ')' : ' (HTTP ' . $code . ')') . '.'];
        $tmp = tempnam(sys_get_temp_dir(), 'pbimg');
        file_put_contents($tmp, $body);
        $name = basename((string) ($parts['path'] ?? 'image')) ?: 'image';
        $r = pb_store_image($tmp, $name, false);
        if (is_file($tmp)) @unlink($tmp);
        return $r;
    }
    return ['error' => 'Too many redirects.'];
}

// ----------------------------------------------------------------------------
// URLS — pretty (/blog/my-post/) when mod_rewrite is available, query-string
// fallback (/blog/?p=my-post) otherwise. Toggle in Settings.
// ----------------------------------------------------------------------------
// The published Page chosen as the homepage (Settings → General), or null when
// the homepage is the latest-posts list. An unpublished/deleted page falls back.
function pb_front_page() {
    static $cache = false;
    if ($cache !== false) return $cache;
    $id = (int) pb_setting('front_page');
    $p = $id ? pb_post_by_id($id) : null;
    $cache = $p && $p['type'] === 'page' && pb_post_is_public($p) ? $p : null;
    return $cache;
}
function pb_url($type = 'home', $arg = null, $page = 1) {
    $pretty = pb_setting('pretty_urls') === '1';
    $b = PB_BASE_PATH . '/';
    switch ($type) {
        case 'post':
            $front = pb_front_page();
            if ($front && $front['slug'] === $arg) return $b; // the homepage page lives at the root
            return $pretty ? $b . rawurlencode($arg) . '/' : $b . '?p=' . rawurlencode($arg);
        case 'posts': // the post list: the homepage, or /posts/ when a Page is the homepage
            if (!pb_front_page()) return pb_url('home', null, $page);
            if ($pretty) return $b . 'posts/' . ($page > 1 ? 'page/' . (int) $page . '/' : '');
            return $b . '?list=1' . ($page > 1 ? '&page=' . (int) $page : '');
        case 'category':
            $u = $pretty ? $b . 'category/' . rawurlencode($arg) . '/' : $b . '?c=' . rawurlencode($arg);
            if ($page > 1) $u .= $pretty ? 'page/' . (int) $page . '/' : '&page=' . (int) $page;
            return $u;
        case 'feed':
            return $pretty ? $b . 'feed.xml' : $b . '?feed=rss';
        case 'sitemap':
            return $pretty ? $b . 'sitemap.xml' : $b . '?feed=sitemap';
        case 'admin':
            return $b . 'admin/' . ($arg ? '?' . $arg : '');
        default:
            if ($page > 1) return $pretty ? $b . 'page/' . (int) $page . '/' : $b . '?page=' . (int) $page;
            return $b;
    }
}
function pb_abs_url($path) {
    if (preg_match('~^https?://~i', $path)) return $path;
    return pb_origin() . ($path !== '' && $path[0] === '/' ? '' : '/') . $path;
}

// ----------------------------------------------------------------------------
// GEORANK INTEGRATION
// ----------------------------------------------------------------------------
function pb_is_georank_site() {
    return is_file(PB_SITE_DIR . '/header.html') && is_file(PB_SITE_DIR . '/footer.html');
}
function pb_layout_mode() {
    $m = pb_setting('layout');
    if ($m === 'georank' && pb_is_georank_site()) return 'georank';
    if ($m === 'standalone') return 'standalone';
    return pb_is_georank_site() ? 'georank' : 'standalone';
}
require __DIR__ . '/addons.php';
require __DIR__ . '/media.php';
require __DIR__ . '/demo.php';

// Adds "Sitemap: <blog sitemap>" to the site's robots.txt, outside GeoRank's
// managed marker block so a GeoRank robots regeneration never removes it.
function pb_robots_add_sitemap() {
    $path = PB_SITE_DIR . '/robots.txt';
    $line = 'Sitemap: ' . pb_abs_url(pb_url('sitemap'));
    $raw = is_file($path) ? (string) file_get_contents($path) : "User-agent: *\nAllow: /\n";
    if (strpos($raw, $line) !== false) return 'robots.txt already lists the blog sitemap.';
    $raw = rtrim($raw) . "\n\n# JotKite blog\n" . $line . "\n";
    return @file_put_contents($path, $raw) === false ? null : 'Added the blog sitemap to robots.txt.';
}
