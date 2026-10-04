<?php
/**
 * JotKite — addons (themes & plugins) · https://jotkite.com
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial
 *
 * An addon is a folder in addons/<slug>/ with an addon.json manifest:
 *   type "theme"  -> theme.php returns a function(array $page) that prints the
 *                    whole public page; theme.css is loaded automatically.
 *   type "plugin" -> addon.php is included on every request while active and
 *                    hooks in with pb_add_action() / pb_add_filter().
 * Settings declared in the manifest get a form in Settings -> Addons and are
 * read with pb_addon_setting(). A failing addon is logged and skipped — it
 * can never take the blog down. See docs/ADDONS.md.
 */
if (!defined('PB_ROOT')) { http_response_code(403); exit; }

define('PB_ADDONS_DIR', PB_ROOT . '/addons');
define('PB_ADDON_FIELD_TYPES', ['text', 'textarea', 'url', 'image', 'color', 'select', 'checkbox', 'number']);

$GLOBALS['pb_hooks'] = ['action' => [], 'filter' => []];
$GLOBALS['pb_addon_errors'] = [];

// ------------------------------------------------------------------- hooks
function pb_add_action($hook, callable $fn, $priority = 10) {
    $GLOBALS['pb_hooks']['action'][$hook][(int) $priority][] = $fn;
}
function pb_add_filter($hook, callable $fn, $priority = 10) {
    $GLOBALS['pb_hooks']['filter'][$hook][(int) $priority][] = $fn;
}
function pb_do_action($hook, ...$args) {
    $list = $GLOBALS['pb_hooks']['action'][$hook] ?? [];
    ksort($list);
    foreach ($list as $fns) foreach ($fns as $fn) {
        try { $fn(...$args); } catch (Throwable $e) { pb_addon_error($e, 'action ' . $hook); }
    }
}
function pb_apply_filters($hook, $value, ...$args) {
    $list = $GLOBALS['pb_hooks']['filter'][$hook] ?? [];
    ksort($list);
    foreach ($list as $fns) foreach ($fns as $fn) {
        try { $value = $fn($value, ...$args); } catch (Throwable $e) { pb_addon_error($e, 'filter ' . $hook); }
    }
    return $value;
}
// Output of an action as a string (for places that build HTML, like <head>).
function pb_capture_action($hook, ...$args) {
    ob_start();
    pb_do_action($hook, ...$args);
    return (string) ob_get_clean();
}
function pb_addon_error($e, $where) {
    $msg = $where . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    $GLOBALS['pb_addon_errors'][] = $msg;
    error_log('[JotKite addon] ' . $msg);
}

function pb_has_action($hook) {
    return !empty($GLOBALS['pb_hooks']['action'][$hook]);
}

// ------------------------------------------------- admin pages, routes, data
// Addons can add a page to the admin menu, answer public addresses, and keep
// their own database tables. See docs/ADDONS.md.
$GLOBALS['pb_admin_pages'] = [];
$GLOBALS['pb_routes'] = [];

/**
 * An admin page: a menu item that opens /admin/?view=$key.
 * $render(array $user) prints the page; $perm is a pb_can() permission
 * (e.g. 'post.create' for every writer, 'settings.manage' for Admins).
 */
function pb_add_admin_page($key, $label, $icon, callable $render, $perm = 'post.create') {
    if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', (string) $key)
        || in_array($key, ['edit', 'posts', 'pages', 'media', 'categories', 'users', 'settings', 'account'], true)) return false; // built-in pages
    $GLOBALS['pb_admin_pages'][$key] = ['label' => (string) $label, 'icon' => (string) $icon, 'render' => $render, 'perm' => (string) $perm];
    return true;
}
function pb_admin_pages() {
    return $GLOBALS['pb_admin_pages'];
}

/**
 * A public address: /$prefix/… (or ?route=$prefix/…) is answered by
 * $handler(array $parts, int $page), which prints the page — usually with
 * pb_render_page() — and returns true; return false to let JotKite show
 * "not found". Posts can't take a slug that equals a route prefix.
 */
function pb_add_route($prefix, callable $handler) {
    $prefix = strtolower((string) $prefix);
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', $prefix) || in_array($prefix, PB_RESERVED_SLUGS, true)) return false;
    $GLOBALS['pb_routes'][$prefix] = $handler;
    return true;
}
function pb_routes() {
    return $GLOBALS['pb_routes'];
}

/**
 * An addon's own tables, created and upgraded once per version:
 *   pb_addon_migrate('places', 2, function (PDO $db, $from) { if ($from < 1) {…} if ($from < 2) {…} });
 * Runs in a transaction; the version is remembered in the settings table.
 */
function pb_addon_migrate($slug, $version, callable $fn) {
    $key = 'addon:' . $slug . ':schema';
    $from = (int) pb_setting($key);
    if ($from >= (int) $version) return true;
    $db = pb_db();
    $db->beginTransaction();
    try {
        $fn($db, $from);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        pb_addon_error($e, 'migrate ' . $slug);
        return false;
    }
    pb_settings_save([$key => (string) (int) $version]);
    return true;
}

// --------------------------------------------------------------- discovery
/** All addons found in addons/, keyed by slug. Invalid manifests are listed with an 'error'. */
function pb_addons() {
    static $all = null;
    if ($all !== null) return $all;
    $all = [];
    foreach (glob(PB_ADDONS_DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $slug = basename($dir);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $slug)) continue;
        $manifest = is_file($dir . '/addon.json') ? json_decode((string) file_get_contents($dir . '/addon.json'), true) : null;
        $a = is_array($manifest) ? $manifest : [];
        $a['slug'] = $slug;
        $a['dir'] = $dir;
        $a['url'] = PB_BASE_PATH . '/addons/' . $slug;
        $a['name'] = (string) ($a['name'] ?? $slug);
        $a['version'] = (string) ($a['version'] ?? '0.0.0');
        $a['settings'] = array_values(array_filter((array) ($a['settings'] ?? []), function ($f) {
            return is_array($f) && isset($f['key']) && preg_match('/^[a-z0-9_]{1,40}$/', (string) $f['key'])
                && in_array($f['type'] ?? 'text', PB_ADDON_FIELD_TYPES, true);
        }));
        if (!is_array($manifest)) $a['error'] = 'addon.json is missing or not valid JSON.';
        elseif (!in_array($a['type'] ?? '', ['theme', 'plugin'], true)) $a['error'] = 'addon.json must set "type" to "theme" or "plugin".';
        elseif ($a['type'] === 'theme' && !is_file($dir . '/theme.php')) $a['error'] = 'A theme needs a theme.php file.';
        elseif ($a['type'] === 'plugin' && !is_file($dir . '/addon.php')) $a['error'] = 'A plugin needs an addon.php file.';
        elseif (!empty($a['requires']) && version_compare(PB_VERSION, (string) $a['requires'], '<')) $a['error'] = 'Needs JotKite ' . $a['requires'] . ' or newer.';
        $all[$slug] = $a;
    }
    ksort($all);
    return $all;
}
function pb_addon($slug) {
    $all = pb_addons();
    return $all[$slug] ?? null;
}
function pb_active_theme() {
    $a = pb_addon((string) pb_setting('theme'));
    return $a && ($a['type'] ?? '') === 'theme' && empty($a['error']) ? $a : null;
}
function pb_active_plugin_slugs() {
    $list = json_decode((string) pb_setting('plugins'), true);
    return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
}
/** Include every active plugin once. Called by each entry point after the library loads. */
function pb_load_plugins() {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    foreach (pb_active_plugin_slugs() as $slug) {
        $a = pb_addon($slug);
        if (!$a || ($a['type'] ?? '') !== 'plugin' || !empty($a['error'])) continue;
        try {
            (static function ($__file) { require $__file; })($a['dir'] . '/addon.php');
        } catch (Throwable $e) {
            pb_addon_error($e, 'plugin ' . $slug);
        }
    }
    pb_do_action('pb_init');
}

// ---------------------------------------------------------------- settings
function pb_addon_field($slug, $key) {
    $a = pb_addon($slug);
    foreach ($a['settings'] ?? [] as $f) if ($f['key'] === $key) return $f;
    return null;
}
/** A setting value for an addon, falling back to the default in its addon.json. */
function pb_addon_setting($slug, $key) {
    $v = pb_setting('addon:' . $slug . ':' . $key);
    if ($v !== null) return $v;
    $f = pb_addon_field($slug, $key);
    return $f ? (string) ($f['default'] ?? '') : '';
}
function pb_addon_settings($slug) {
    $out = [];
    foreach (pb_addon($slug)['settings'] ?? [] as $f) $out[$f['key']] = pb_addon_setting($slug, $f['key']);
    return $out;
}
/** Validate one submitted value against its field definition. */
function pb_addon_clean_value(array $f, $raw) {
    $raw = is_string($raw) ? trim($raw) : '';
    switch ($f['type'] ?? 'text') {
        case 'color':    return pb_valid_hex($raw);
        case 'checkbox': return $raw !== '' ? '1' : '0';
        case 'number':   return is_numeric($raw) ? (string) (0 + $raw) : (string) ($f['default'] ?? '');
        case 'select':   return array_key_exists($raw, (array) ($f['options'] ?? [])) ? $raw : (string) ($f['default'] ?? '');
        case 'url':
        case 'image':    return $raw !== '' && pb_safe_url($raw) !== null && !preg_match('/^(mailto|tel):/i', $raw) ? substr($raw, 0, 500) : '';
        case 'textarea': return substr(strip_tags($raw), 0, 2000);
        default:         return substr(strip_tags($raw), 0, 300);
    }
}

// ----------------------------------------------------------------- themes
/** Render a public page with a theme. Returns false (caller falls back to the default layout) on any failure. */
function pb_render_with_theme(array $theme, array $page) {
    $level = ob_get_level();
    try {
        $renderer = (static function ($__file) { return require $__file; })($theme['dir'] . '/theme.php');
        if (!is_callable($renderer)) throw new RuntimeException('theme.php must return a function');
        ob_start();
        $renderer($page);
        $html = ob_get_clean();
    } catch (Throwable $e) {
        while (ob_get_level() > $level) ob_end_clean(); // discard the half-rendered page
        pb_addon_error($e, 'theme ' . $theme['slug']);
        return false;
    }
    echo $html;
    return true;
}
/** Pick black or white text for a background colour (for themes). */
function pb_text_on($hex, $dark = '#111827', $light = '#ffffff') {
    $hex = pb_valid_hex($hex);
    if ($hex === '') return $dark;
    [$r, $g, $b] = array_map(function ($c) { $c = hexdec($c) / 255; return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4); },
        str_split(substr($hex, 1), 2));
    $lum = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    return $lum > 0.45 ? $dark : $light;
}
