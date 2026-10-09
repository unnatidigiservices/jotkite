<?php
/**
 * JotKite — admin (writing, review workflow, users, settings).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial
 */
define('PB_ROOT', dirname(__DIR__));
define('PB_BASE_PATH', rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/'));
require PB_ROOT . '/lib/postbase.php';

// Web app manifest: "Add to Home Screen" installs the admin as an app that
// opens straight on Write. Public (no session), holds nothing private.
if (isset($_GET['manifest'])) {
    $name = (string) pb_setting('blog_title');
    header('Content-Type: application/manifest+json');
    header('Cache-Control: public, max-age=3600');
    echo json_encode([
        'name' => $name . ' · JotKite',
        'short_name' => (function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) <= 12 ? $name : 'JotKite',
        'description' => 'Write anywhere, post here.',
        'id' => PB_BASE_PATH . '/admin/',
        'start_url' => PB_BASE_PATH . '/admin/?view=edit',
        'scope' => PB_BASE_PATH . '/admin/',
        'display' => 'standalone',
        'background_color' => '#ffffff',
        'theme_color' => '#1f4bff',
        'icons' => [
            ['src' => PB_BASE_PATH . '/assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'], // the rounded tile is the icon: not maskable
            ['src' => PB_BASE_PATH . '/assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ],
        'shortcuts' => [
            ['name' => 'Write', 'url' => PB_BASE_PATH . '/admin/?view=edit'],
            ['name' => 'Posts', 'url' => PB_BASE_PATH . '/admin/?view=posts'],
            ['name' => 'Media', 'url' => PB_BASE_PATH . '/admin/?view=media'],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Service worker for the home-screen app. Chrome only offers one-tap "Install"
// when the app has one. It caches nothing: every request goes to the network,
// and only a page load that fails (no signal) gets a small offline page.
if (isset($_GET['sw'])) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-cache');
    $offline = '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline · JotKite</title>'
             . '<div style="font:17px/1.5 system-ui,sans-serif;max-width:420px;margin:80px auto;padding:0 20px;text-align:center">'
             . '<h1 style="font-size:22px">You\'re offline</h1><p>JotKite needs a connection to load this page. Your saved posts are safe.</p>'
             . '<p><button onclick="location.reload()" style="font:inherit;padding:10px 18px;border-radius:10px;border:0;background:#1f4bff;color:#fff">Try again</button></p></div>';
    echo "/* JotKite admin service worker: no caching, offline page only. */\n"
       . "self.addEventListener('install', () => self.skipWaiting());\n"
       . "self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));\n"
       . "self.addEventListener('fetch', (e) => {\n"
       . "  if (e.request.method !== 'GET' || e.request.mode !== 'navigate') return;\n"
       . "  e.respondWith(fetch(e.request).catch(() => new Response(" . json_encode($offline) . ", { headers: { 'Content-Type': 'text/html; charset=utf-8' } })));\n"
       . "});\n";
    exit;
}

pb_session_start();
pb_device_login();
pb_load_plugins();
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin'); // YouTube embeds refuse to play without a referrer
header('Cache-Control: no-store');

function pb_admin_url($query = '') {
    return PB_BASE_PATH . '/admin/' . ($query !== '' ? '?' . $query : '');
}
function pb_redirect($query = '') {
    header('Location: ' . pb_admin_url($query));
    exit;
}
function pb_flash($msg, $type = 'ok') {
    $_SESSION['pb_flash'][] = [$type, $msg];
}
function pb_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
// GeoRank's admin folder name varies per install; find it by its config file.
function pb_georank_admin_url() {
    $hits = glob(PB_SITE_DIR . '/*/georank-config.json');
    if (!$hits) return null;
    return pb_site_base_path() . '/' . basename(dirname($hits[0])) . '/';
}
// Home-screen app tags, shared by the admin and sign-in pages.
function pb_app_head() {
    $b = pb_e(PB_BASE_PATH);
    return '<link rel="manifest" href="' . $b . '/admin/?manifest=1">' . "\n"
         . '<meta name="theme-color" content="#1f4bff">' . "\n"
         . '<meta name="mobile-web-app-capable" content="yes">' . "\n"
         . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
         . '<meta name="apple-mobile-web-app-title" content="JotKite">' . "\n"
         . '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
}
function pb_csrf_field() {
    return '<input type="hidden" name="_csrf" value="' . pb_e(pb_csrf_token()) . '">';
}
function pb_status_badge($status, $publishedAt = null) {
    $label = pb_status_label($status, $publishedAt);
    $cls = $label === 'Scheduled' ? 'scheduled' : $status;
    return '<span class="pb-badge pb-badge-' . pb_e($cls) . '">' . pb_e($label) . '</span>';
}
function pb_utc_to_local_input($utc) {
    if (!$utc) return '';
    return pb_format_date($utc, 'Y-m-d\TH:i');
}
// Upload-or-remove image picker (cover image, social image, favicon). Wired up
// by admin.js through the data-imgfield attributes.
function pb_image_field($name, $value, $label, $hint = '', $editable = true) {
    $value = (string) $value;
    $keep = in_array($name, ['design_favicon', 'design_social_image'], true) ? ' data-img-keep' : ''; // not converted to WebP
    $h = '<div class="pb-imgfield" data-imgfield' . $keep . '><span class="pb-small pb-field-label">' . pb_e($label) . '</span>'
       . '<div class="pb-imgfield-preview" data-img-preview>' . ($value !== '' ? '<img src="' . pb_e($value) . '" alt="">' : '') . '</div>'
       . '<input type="hidden" name="' . pb_e($name) . '" value="' . pb_e($value) . '" data-img-value>';
    if ($editable) {
        $h .= '<div class="pb-row"><button type="button" class="pb-btn pb-btn-sm" data-img-upload>' . ($value !== '' ? 'Replace' : 'Upload') . '</button>'
            . '<button type="button" class="pb-btn pb-btn-sm" data-img-library>Media library</button>'
            . '<button type="button" class="pb-btn pb-btn-sm" data-img-clear' . ($value === '' ? ' hidden' : '') . '>Remove</button></div>'
            . '<input type="file" accept="image/jpeg,image/png,image/gif,image/webp" hidden data-img-file>';
    }
    if ($hint !== '') $h .= '<p class="pb-small pb-muted pb-hint">' . pb_e($hint) . '</p>';
    return $h . '</div>';
}
// One addon setting field, from its addon.json definition.
function pb_addon_field_html(array $f, $value) {
    $name = 'addon[' . $f['key'] . ']';
    $label = (string) ($f['label'] ?? $f['key']);
    $help = !empty($f['help']) ? '<span class="pb-small pb-muted pb-hint">' . pb_e($f['help']) . '</span>' : '';
    switch ($f['type']) {
        case 'image':
            return pb_image_field($name, $value, $label, (string) ($f['help'] ?? ''));
        case 'color':
            return pb_color_field($name, $value, $label) . $help;
        case 'select':
            $h = '<label>' . pb_e($label) . '<select name="' . pb_e($name) . '">';
            foreach ((array) ($f['options'] ?? []) as $k => $v) $h .= '<option value="' . pb_e($k) . '"' . ((string) $k === (string) $value ? ' selected' : '') . '>' . pb_e($v) . '</option>';
            return $h . '</select>' . $help . '</label>';
        case 'checkbox':
            return '<label class="pb-check"><input type="checkbox" name="' . pb_e($name) . '" value="1"' . ($value === '1' ? ' checked' : '') . '> ' . pb_e($label) . '</label>' . $help;
        case 'textarea':
            return '<label>' . pb_e($label) . '<textarea name="' . pb_e($name) . '" rows="3">' . pb_e($value) . '</textarea>' . $help . '</label>';
        default:
            $type = $f['type'] === 'number' ? 'number' : ($f['type'] === 'url' ? 'text' : 'text');
            return '<label>' . pb_e($label) . '<input type="' . $type . '" name="' . pb_e($name) . '" value="' . pb_e($value) . '"' . ($f['type'] === 'url' ? ' placeholder="https://… or /page/"' : '') . '>' . $help . '</label>';
    }
}
// Hex colour with a picker; empty = inherit the site theme.
function pb_color_field($name, $value, $label) {
    $hex = pb_valid_hex($value);
    return '<div class="pb-colorfield" data-colorfield><span class="pb-small pb-field-label">' . pb_e($label) . '</span><div class="pb-row">'
         . '<input type="color" value="' . pb_e($hex ?: '#1d5cff') . '" data-color-picker aria-label="' . pb_e($label) . ' picker">'
         . '<input type="text" name="' . pb_e($name) . '" value="' . pb_e($hex) . '" placeholder="Site theme" maxlength="7" pattern="#[0-9a-fA-F]{6}" data-color-text>'
         . '<button type="button" class="pb-btn pb-btn-sm" data-color-clear>Reset</button></div></div>';
}

$user = pb_current_user();
$view = (string) ($_GET['view'] ?? 'posts');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// ============================================================================
// FIRST RUN SETUP (no users yet, and no GeoRank admin session to sign in with)
// ============================================================================
if (!$user && pb_count_users() === 0) {
    $err = '';
    if ($isPost && ($_POST['do'] ?? '') === 'setup') {
        pb_csrf_check();
        $key = (string) pb_config('setup_key');
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        if ($key !== '' && !hash_equals($key, (string) ($_POST['setup_key'] ?? ''))) $err = 'Wrong setup key.';
        elseif ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter your name and a valid email.';
        elseif (strlen($pass) < 8) $err = 'Use a password of at least 8 characters.';
        elseif (trim((string) ($_POST['username'] ?? '')) !== '' && pb_username_clean($_POST['username']) === '') $err = 'Usernames need 3–30 letters, digits, dots, dashes or underscores.';
        else {
            pb_q("INSERT INTO users (email, name, password_hash, role, created_at, username) VALUES (?, ?, ?, 'admin', ?, ?)",
                [$email, $name, password_hash($pass, PASSWORD_DEFAULT), pb_now(), pb_username_unique((string) ($_POST['username'] ?? ''), 0, $name)]);
            pb_attempt_login($email, $pass);
            pb_flash('Welcome to JotKite. Write your first post!');
            pb_redirect('view=edit');
        }
    }
    pb_auth_page('Set up your blog', $err, function () {
        $key = (string) pb_config('setup_key'); ?>
        <p class="pb-muted">Create the first admin account. You can add Editors and Contributors afterwards.</p>
        <form method="post">
          <?= pb_csrf_field() ?><input type="hidden" name="do" value="setup">
          <?php if ($key !== ''): ?><label>Setup key<input name="setup_key" required autocomplete="off"></label><?php endif; ?>
          <label>Your name <span class="pb-small pb-muted">(shown on your posts)</span><input name="name" required value="<?= pb_e($_POST['name'] ?? '') ?>"></label>
          <label>Username <span class="pb-small pb-muted">(for signing in; optional, made from your name if empty)</span><input name="username" value="<?= pb_e($_POST['username'] ?? '') ?>" autocapitalize="none" spellcheck="false" pattern="[A-Za-z0-9._-]{3,30}" autocomplete="username"></label>
          <label>Email<input type="email" name="email" required value="<?= pb_e($_POST['email'] ?? '') ?>"></label>
          <label>Password<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
          <button class="pb-btn pb-btn-primary pb-btn-block">Create admin account</button>
        </form>
        <?php
    });
    exit;
}

// ============================================================================
// LOGIN / LOGOUT
// ============================================================================
if (isset($_GET['logout'])) {
    if (hash_equals(pb_csrf_token(), (string) $_GET['logout'])) {
        pb_device_forget_current();
        unset($_SESSION['pb_uid']);
        session_regenerate_id(true);
    }
    if (pb_georank_session_role()) {
        pb_flash('You are signed in through GeoRank. Log out from the GeoRank dashboard to end that session.', 'info');
    }
    pb_redirect();
}
if (!$user) {
    $err = '';
    // Demo site: one click to try any role (lib/demo.php).
    if ($isPost && ($_POST['do'] ?? '') === 'demo_login' && pb_demo_on()) {
        pb_csrf_check();
        $role = (string) ($_POST['role'] ?? '');
        $du = isset(PB_DEMO_USERS[$role]) ? pb_row('SELECT * FROM users WHERE email = ? AND active = 1', [PB_DEMO_USERS[$role][0]]) : null;
        if (!$du && isset(PB_DEMO_USERS[$role]) && !pb_row('SELECT id FROM users WHERE email = ?', [PB_DEMO_USERS[$role][0]])) {
            // A role added after this demo's snapshot was made (e.g. Author in 0.25): create it now.
            [$dEmail, $dName] = PB_DEMO_USERS[$role];
            pb_q('INSERT INTO users (email, name, password_hash, role, created_at, username) VALUES (?, ?, ?, ?, ?, ?)',
                [$dEmail, $dName, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $role, pb_now(), pb_username_unique($dName)]);
            $du = pb_row('SELECT * FROM users WHERE email = ?', [$dEmail]);
        }
        if ($du) {
            session_regenerate_id(true);
            $_SESSION['pb_uid'] = (int) $du['id'];
            pb_flash('You are in the demo as ' . pb_role_label($du['role']) . '. Try anything: it all resets in ' . pb_demo_minutes_left() . ' min.', 'info');
            pb_redirect(preg_match('/^view=[a-z]+$/', (string) ($_POST['next'] ?? '')) ? (string) $_POST['next'] : 'view=edit');
        }
        $err = 'That demo account is missing. Wait for the next reset, or ask the site owner.';
    }
    if ($isPost && ($_POST['do'] ?? '') === 'login') {
        pb_csrf_check();
        $err = pb_attempt_login($_POST['email'] ?? '', $_POST['password'] ?? '');
        if ($err === null) {
            if (!empty($_POST['remember'])) pb_device_remember($_SESSION['pb_uid']);
            pb_redirect(preg_match('/^view=[a-z]+$/', (string) ($_POST['next'] ?? '')) ? (string) $_POST['next'] : '');
        }
    }
    $gr = pb_georank_admin_url();
    pb_auth_page(pb_demo_on() ? 'Try the JotKite demo' : 'Sign in', $err, function () use ($gr, $view) {
        $next = isset($_GET['view']) && preg_match('/^[a-z]+$/', $view) ? 'view=' . $view : '';
        if (pb_demo_on()): ?>
        <p class="pb-muted">Pick a role: no password needed. Everything you do is wiped every <?= pb_demo_minutes() ?> minutes (next reset in <?= pb_demo_minutes_left() ?> min).</p>
        <div class="pb-demo-roles">
          <?php foreach (['admin' => 'Everything, including users and settings', 'editor' => 'Writes, reviews and publishes anyone\'s posts', 'author' => 'Writes and publishes their own posts', 'contributor' => 'Writes and submits for review'] as $r => $what): ?>
          <form method="post"><?= pb_csrf_field() ?><input type="hidden" name="do" value="demo_login"><input type="hidden" name="role" value="<?= $r ?>"><input type="hidden" name="next" value="<?= pb_e($next) ?>">
            <button class="pb-btn pb-btn-block<?= $r === 'admin' ? ' pb-btn-primary' : '' ?>"><span>Enter as <?= pb_role_label($r) ?></span><small><?= pb_e($what) ?></small></button></form>
          <?php endforeach; ?>
        </div>
        <details class="pb-demo-owner-login"><summary class="pb-small pb-muted">Sign in with email</summary>
        <?php endif; ?>
        <form method="post" id="pbLogin">
          <?= pb_csrf_field() ?><input type="hidden" name="do" value="login">
          <input type="hidden" name="next" value="<?= pb_e($next) ?>">
          <label>Email or username<input type="text" name="email" id="pbLoginEmail" required autofocus value="<?= pb_e($_POST['email'] ?? '') ?>" autocomplete="username" autocapitalize="none" spellcheck="false"></label>
          <label>Password<input type="password" name="password" id="pbLoginPass" required autocomplete="current-password"></label>
          <label class="pb-check pb-small"><input type="checkbox" name="remember" value="1" checked> Keep me signed in on this device (<?= PB_DEVICE_DAYS ?> days)</label>
          <button class="pb-btn pb-btn-primary pb-btn-block">Sign in</button>
        </form>
        <script>
        // Remember the email on this device, so next time only the password is needed
        // (and a phone's password manager can fill that with a fingerprint or Face ID).
        (function () {
          var e = document.getElementById('pbLoginEmail'), p = document.getElementById('pbLoginPass');
          try {
            var saved = localStorage.getItem('pb_login_email');
            if (saved && !e.value) { e.value = saved; p.focus(); }
            document.getElementById('pbLogin').addEventListener('submit', function () { localStorage.setItem('pb_login_email', e.value.trim()); });
          } catch (err) { /* private mode: no storage, nothing to remember */ }
        })();
        </script>
        <?php if (pb_demo_on()): ?></details><?php endif; ?>
        <?php if ($gr): ?><p class="pb-muted pb-center">Site owner? <a href="<?= pb_e($gr) ?>">Sign in to GeoRank</a> and come back — you'll be signed in here automatically.</p><?php endif;
    });
    exit;
}

pb_version_check();

// Link dialog search (editor): published posts and pages only — no drafts,
// categories or media — newest first. Empty query = the 10 most recent.
if (($_GET['ajax'] ?? '') === 'links') {
    if (!pb_can($user, 'post.create')) pb_json([], 403);
    $q = trim((string) ($_GET['q'] ?? ''));
    $where = "status = 'published' AND published_at <= ?";
    $params = [pb_now()];
    if ($q !== '') {
        $where .= " AND (title LIKE ? ESCAPE '\\' OR slug LIKE ? ESCAPE '\\')";
        $like = '%' . addcslashes($q, '%_\\') . '%';
        array_push($params, $like, $like);
    }
    $out = [];
    foreach (pb_all("SELECT slug, title, type, published_at FROM posts WHERE {$where} ORDER BY type = 'page', published_at DESC LIMIT " . ($q === '' ? 10 : 20), $params) as $r) {
        $out[] = ['title' => $r['title'], 'url' => pb_url('post', $r['slug']), 'type' => $r['type'], 'date' => $r['type'] === 'post' ? pb_format_date($r['published_at']) : ''];
    }
    pb_json($out);
}
// Media library for the image picker (editor, featured image, design images): newest first, 40 per page.
if (($_GET['ajax'] ?? '') === 'media') {
    if (!pb_can($user, 'media.upload')) pb_json([], 403);
    $all = pb_media_list(trim((string) ($_GET['q'] ?? '')), pb_media_scope($user)); // Authors, Contributors: their own images
    $pg = max(1, (int) ($_GET['pg'] ?? 1));
    $items = [];
    foreach (array_slice($all, ($pg - 1) * 40, 40) as $m) {
        $size = @getimagesize(PB_UPLOAD_DIR . '/' . $m['rel']);
        $items[] = ['url' => $m['url'], 'name' => $m['name'], 'width' => $size ? (int) $size[0] : 0, 'height' => $size ? (int) $size[1] : 0];
    }
    pb_json(['items' => $items, 'more' => count($all) > $pg * 40]);
}

// ============================================================================
// POST ACTIONS (all CSRF-checked, all permission-checked in the library)
// ============================================================================
if ($isPost) {
    pb_csrf_check();
    $do = (string) ($_POST['do'] ?? '');

    // Demo site (lib/demo.php): visitors are Admins, so a few things stay locked —
    // code that would run for other visitors, files outside the blog, and the
    // demo accounts themselves. The owner unlocks them with demo_key.
    if (pb_demo_locked()) {
        $target = in_array($do, ['user_save', 'user_toggle'], true) ? pb_row('SELECT * FROM users WHERE id = ?', [(int) ($_POST['id'] ?? 0)]) : null;
        $back = ['code_save' => 'view=settings&tab=code', 'robots_sitemap' => 'view=settings', 'account_save' => 'view=account', 'user_save' => 'view=users', 'user_toggle' => 'view=users'];
        if (in_array($do, ['code_save', 'robots_sitemap'], true)
            || ($do === 'account_save' && (string) ($_POST['new_password'] ?? '') !== '')
            || ($target && pb_demo_is_demo_user($target))) {
            pb_flash('That is switched off in the demo, so every visitor gets a working site. It works normally on your own blog.', 'info');
            pb_redirect($back[$do] ?? '');
        }
    }
    if ($do === 'demo_unlock' && pb_demo_on() && pb_can($user, 'settings.manage')) {
        $key = (string) pb_config('demo_key');
        if ($key !== '' && hash_equals($key, (string) ($_POST['key'] ?? ''))) {
            $_SESSION['pb_demo_owner'] = true;
            pb_flash('Owner tools unlocked for this session.');
        } else {
            pb_flash($key === '' ? 'Set demo_key in config.php first.' : 'Wrong demo key.', 'error');
        }
        pb_redirect('view=settings&tab=demo');
    }
    if (in_array($do, ['demo_snapshot', 'demo_reset', 'demo_lock'], true) && pb_demo_on() && pb_demo_owner()) {
        if ($do === 'demo_snapshot') {
            pb_flash(pb_demo_snapshot() ? 'Saved. Every reset now returns to exactly this content.' : 'Could not save the snapshot (check that data/ is writable).', 'ok');
        } elseif ($do === 'demo_reset') {
            @file_put_contents(pb_demo_dir() . '/last-reset', '0'); // the next request restores the snapshot, before the database opens
            unset($_SESSION['pb_demo_owner']);
            pb_flash('The demo was reset to its starting point.');
            pb_redirect('view=settings&tab=demo');
        } else {
            unset($_SESSION['pb_demo_owner']);
            pb_flash('Owner tools locked.');
        }
        pb_redirect('view=settings&tab=demo');
    }
    if ($do === 'rename_dismiss' && pb_can($user, 'settings.manage')) {
        pb_settings_save(['rename_notice' => '']);
        pb_redirect(preg_match('/^[a-z]+$/', (string) ($_POST['back'] ?? '')) ? 'view=' . $_POST['back'] : '');
    }
    if ($do === 'upgrade_dismiss' && pb_can($user, 'settings.manage')) {
        pb_settings_save(['upgrade_notice' => '']);
        pb_redirect(preg_match('/^[a-z]+$/', (string) ($_POST['back'] ?? '')) ? 'view=' . $_POST['back'] : '');
    }
    if ($do === 'upload') {
        if (!pb_can($user, 'media.upload')) pb_json(['error' => 'Not allowed.'], 403);
        // Everything becomes WebP, except the favicon and default social image (keep_format):
        // browsers and link previews handle PNG/JPG most reliably there.
        $r = pb_handle_upload($_FILES['file'] ?? null, !empty($_POST['keep_format']));
        pb_json($r, isset($r['error']) ? 400 : 200);
    }
    if ($do === 'code_save' && pb_can($user, 'settings.manage')) {
        pb_settings_save([
            'code_head' => substr(str_replace("\r\n", "\n", (string) ($_POST['code_head'] ?? '')), 0, 20000),
            'code_footer' => substr(str_replace("\r\n", "\n", (string) ($_POST['code_footer'] ?? '')), 0, 20000),
        ]);
        pb_flash('Code saved. It is now on every public page.');
        pb_redirect('view=settings&tab=code');
    }
    if ($do === 'media_delete') {
        $ownOnly = !pb_can($user, 'media.delete');
        if ($ownOnly && $user['role'] !== 'author') { pb_flash('Only Authors, Editors and Admins can delete images.', 'error'); pb_redirect('view=media'); }
        $paths = is_array($_POST['paths'] ?? null) ? array_map('strval', $_POST['paths']) : [];
        if ($ownOnly) $paths = pb_media_owned($paths, $user['id']); // Authors: only images they uploaded
        [$deleted, $skipped] = pb_media_delete($paths);
        pb_flash('Deleted ' . $deleted . ' image' . ($deleted === 1 ? '' : 's') . '.' . ($skipped ? ' ' . $skipped . ' could not be deleted.' : ''), $skipped && !$deleted ? 'error' : 'ok');
        pb_redirect('view=media' . (!empty($_POST['back']) ? '&' . preg_replace('/[^a-z0-9=&_%-]/i', '', (string) $_POST['back']) : ''));
    }
    if ($do === 'import_image') { // images pasted from Docs/WordPress/web pages -> our uploads
        if (!pb_can($user, 'media.upload')) pb_json(['error' => 'Not allowed.'], 403);
        $r = pb_import_remote_image((string) ($_POST['url'] ?? ''));
        pb_json($r, isset($r['error']) ? 400 : 200);
    }

    if ($do === 'save_post') {
        $id = (int) ($_POST['id'] ?? 0);
        $then = (string) ($_POST['then'] ?? 'save');
        [$id, $err] = pb_post_save($user, $id, $_POST);
        if ($err) { pb_flash($err, 'error'); pb_redirect($id ? 'view=edit&id=' . $id : 'view=posts'); }
        if ($then === 'submit' || $then === 'publish') {
            $err = pb_post_transition($user, pb_post_by_id($id), $then, '', (string) ($_POST['publish_at'] ?? ''));
            if ($err) pb_flash($err, 'error');
            else {
                $p = pb_post_by_id($id);
                pb_flash($then === 'submit' ? 'Submitted for review. An Editor will approve it or send it back with notes.'
                    : (pb_status_label($p['status'], $p['published_at']) === 'Scheduled'
                        ? 'Scheduled for ' . pb_format_date($p['published_at'], 'j M Y, g:i a') . '.' : 'Published!'));
            }
        } else {
            pb_flash('Saved.');
        }
        pb_redirect('view=edit&id=' . $id);
    }

    if ($do === 'transition') {
        $post = pb_post_by_id((int) ($_POST['id'] ?? 0));
        $action = (string) ($_POST['action'] ?? '');
        if (!$post) { pb_flash('Post not found.', 'error'); pb_redirect(); }
        $err = pb_post_transition($user, $post, $action, (string) ($_POST['note'] ?? ''), (string) ($_POST['publish_at'] ?? ''));
        if ($err) { pb_flash($err, 'error'); pb_redirect('view=edit&id=' . (int) $post['id']); }
        $msgs = ['withdraw' => 'Moved back to drafts.', 'request_changes' => 'Sent back to the writer with your notes.',
                 'unpublish' => 'Unpublished — the post is a draft again.', 'archive' => 'Archived.', 'restore' => 'Restored as a draft.',
                 'delete' => 'Deleted permanently.', 'publish' => 'Published!', 'submit' => 'Submitted for review.'];
        pb_flash($msgs[$action] ?? 'Done.');
        pb_redirect($action === 'delete' ? 'view=posts' : 'view=edit&id=' . (int) $post['id']);
    }

    if ($do === 'category_save' && pb_can($user, 'category.manage')) {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') { pb_flash('Category name is required.', 'error'); pb_redirect('view=categories'); }
        $slug = pb_unique_slug(trim((string) ($_POST['slug'] ?? '')) ?: $name, 'categories', $id);
        $desc = trim((string) ($_POST['description'] ?? ''));
        $sort = (int) ($_POST['sort'] ?? 0);
        if ($id) pb_q('UPDATE categories SET name = ?, slug = ?, description = ?, sort = ? WHERE id = ?', [$name, $slug, $desc, $sort, $id]);
        else pb_q('INSERT INTO categories (name, slug, description, sort) VALUES (?, ?, ?, ?)', [$name, $slug, $desc, $sort]);
        pb_flash('Category saved.');
        pb_redirect('view=categories');
    }
    if ($do === 'category_delete' && pb_can($user, 'category.manage')) {
        pb_q('DELETE FROM categories WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        pb_flash('Category deleted. Its posts are now uncategorised.');
        pb_redirect('view=categories');
    }

    if ($do === 'user_save' && pb_can($user, 'user.manage')) {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = (string) ($_POST['role'] ?? 'contributor');
        $pass = (string) ($_POST['password'] ?? '');
        $target = $id ? pb_row('SELECT * FROM users WHERE id = ?', [$id]) : null;
        if (!in_array($role, PB_ROLES, true)) $role = 'contributor';
        if ($name === '') { pb_flash('Name is required.', 'error'); pb_redirect('view=users'); }
        // Username (sign-in + author page address): optional; made from the name when empty.
        $uname = trim((string) ($_POST['username'] ?? ''));
        if ($uname !== '') {
            $clean = pb_username_clean($uname);
            if ($clean === '' || $clean !== strtolower($uname)) { pb_flash('Usernames need 3–30 letters, digits, dots, dashes or underscores (no spaces).', 'error'); pb_redirect('view=users'); }
            if ((int) pb_val('SELECT COUNT(*) FROM users WHERE username = ? AND id != ?', [$clean, $id])) { pb_flash('That username is already taken.', 'error'); pb_redirect('view=users'); }
            $uname = $clean;
        }
        if ($target && (int) $target['id'] === (int) $user['id'] && $role !== 'admin') {
            pb_flash('You can\'t remove your own admin role.', 'error'); pb_redirect('view=users');
        }
        if ($target && $uname !== '') pb_q('UPDATE users SET username = ? WHERE id = ?', [$uname, $id]);
        if ($target && $target['source'] === 'georank') {
            pb_q('UPDATE users SET name = ? WHERE id = ?', [$name, $id]); // role follows the GeoRank login
        } elseif ($target) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { pb_flash('Enter a valid email.', 'error'); pb_redirect('view=users'); }
            if ((int) pb_val('SELECT COUNT(*) FROM users WHERE email = ? AND id != ?', [$email, $id])) { pb_flash('That email is already used.', 'error'); pb_redirect('view=users'); }
            pb_q('UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?', [$name, $email, $role, $id]);
            if ($pass !== '') {
                if (strlen($pass) < 8) { pb_flash('Passwords need at least 8 characters.', 'error'); pb_redirect('view=users'); }
                pb_q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
                if ($id !== (int) $user['id']) pb_device_forget_all($id); // a reset password signs their devices out
                else pb_device_forget_all($id, true);
            }
        } else {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
                pb_flash('New users need a valid email and a password of at least 8 characters.', 'error'); pb_redirect('view=users');
            }
            if ((int) pb_val('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) { pb_flash('That email is already used.', 'error'); pb_redirect('view=users'); }
            pb_q('INSERT INTO users (email, name, password_hash, role, created_at, username) VALUES (?, ?, ?, ?, ?, ?)',
                [$email, $name, password_hash($pass, PASSWORD_DEFAULT), $role, pb_now(), $uname !== '' ? $uname : pb_username_unique('', 0, $name)]);
        }
        pb_flash('User saved.' . (!$target ? ' Share the sign-in link and password with them: ' . pb_abs_url(pb_admin_url()) : ''));
        pb_redirect('view=users');
    }
    if ($do === 'user_toggle' && pb_can($user, 'user.manage')) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int) $user['id']) { pb_flash('You can\'t deactivate yourself.', 'error'); pb_redirect('view=users'); }
        pb_q('UPDATE users SET active = 1 - active WHERE id = ?', [$id]);
        pb_flash('User updated.');
        pb_redirect('view=users');
    }

    if ($do === 'settings_save' && pb_can($user, 'settings.manage')) {
        $tz = (string) ($_POST['timezone'] ?? 'UTC');
        if (!in_array($tz, timezone_identifiers_list(), true)) $tz = 'UTC';
        $siteUrl = trim((string) ($_POST['site_url'] ?? ''));
        if ($siteUrl !== '' && !preg_match('~^https?://[^/\s]+~i', $siteUrl)) $siteUrl = '';
        pb_settings_save([
            'blog_title' => trim((string) ($_POST['blog_title'] ?? '')) ?: 'Blog',
            'blog_description' => trim((string) ($_POST['blog_description'] ?? '')),
            'posts_per_page' => (string) max(1, min(48, (int) ($_POST['posts_per_page'] ?? 9))),
            'layout' => in_array($_POST['layout'] ?? '', ['auto', 'georank', 'standalone'], true) ? $_POST['layout'] : 'auto',
            'timezone' => $tz,
            'pretty_urls' => !empty($_POST['pretty_urls']) ? '1' : '0',
            'site_url' => rtrim($siteUrl, '/'),
            'language' => preg_replace('/[^a-zA-Z\-]/', '', (string) ($_POST['language'] ?? 'en')) ?: 'en',
            'show_author' => !empty($_POST['show_author']) ? '1' : '0',
            'photo_metadata' => ($_POST['photo_metadata'] ?? '') === 'strip' ? 'strip' : 'keep',
            'georank_sso' => !empty($_POST['georank_sso']) ? '1' : '0',
            'georank_editor_role' => in_array($_POST['georank_editor_role'] ?? '', PB_ROLES, true) ? $_POST['georank_editor_role'] : 'editor',
            // Homepage: '' (latest posts) or the id of a published Page.
            'front_page' => (function ($id) {
                $p = $id ? pb_post_by_id($id) : null;
                return $p && $p['type'] === 'page' && pb_post_is_public($p) ? (string) $id : '';
            })((int) ($_POST['front_page'] ?? 0)),
        ]);
        pb_flash('Settings saved.');
        pb_redirect('view=settings');
    }
    if ($do === 'design_save' && pb_can($user, 'settings.manage')) {
        $img = function ($k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            return $v !== '' && pb_safe_url($v) !== null && !preg_match('/^(mailto|tel):/i', $v) ? $v : '';
        };
        $fonts = pb_font_choices();
        $font = function ($k) use ($fonts) { $v = (string) ($_POST[$k] ?? ''); return isset($fonts[$v]) ? $v : ''; };
        $size = (int) ($_POST['design_font_size'] ?? 0);
        pb_settings_save([
            'design_social_image' => $img('design_social_image'),
            'design_favicon' => $img('design_favicon'),
            'design_font_body' => $font('design_font_body'),
            'design_font_heading' => $font('design_font_heading'),
            'design_font_size' => $size >= 14 && $size <= 22 ? (string) $size : '',
            'design_accent' => pb_valid_hex($_POST['design_accent'] ?? ''),
            'design_text' => pb_valid_hex($_POST['design_text'] ?? ''),
            'design_bg' => pb_valid_hex($_POST['design_bg'] ?? ''),
            'design_surface' => pb_valid_hex($_POST['design_surface'] ?? ''),
            'fallback_image' => in_array($_POST['fallback_image'] ?? '', ['social', 'illustrations', 'none'], true) ? $_POST['fallback_image'] : 'social',
        ]);
        pb_flash('Design saved.');
        pb_redirect('view=settings&tab=design');
    }
    if ($do === 'nav_save' && pb_can($user, 'settings.manage')) {
        $items = !empty($_POST['reset']) ? '' : json_encode(pb_nav_clean(is_array($_POST['nav'] ?? null) ? $_POST['nav'] : []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        pb_settings_save(['nav_items' => $items, 'nav_show_georank' => !empty($_POST['nav_show_georank']) ? '1' : '0']);
        pb_flash(!empty($_POST['reset']) ? 'Navigation reset to Home, Blog, Contact.' : 'Navigation saved.');
        pb_redirect('view=settings&tab=navigation');
    }
    if (in_array($do, ['addon_activate', 'addon_deactivate', 'addon_settings_save'], true) && pb_can($user, 'settings.manage')) {
        $slug = (string) ($_POST['slug'] ?? '');
        $a = $slug !== '' ? pb_addon($slug) : null;
        if ($do === 'addon_activate' && $slug === '') {
            pb_settings_save(['theme' => '']);
            pb_flash('Default theme is active.');
        } elseif (!$a) {
            pb_flash('That addon isn\'t installed.', 'error');
        } elseif ($do === 'addon_activate') {
            if (!empty($a['error'])) {
                pb_flash($a['name'] . ' can\'t be activated: ' . $a['error'], 'error');
            } elseif ($a['type'] === 'theme') {
                pb_settings_save(['theme' => $slug]);
                pb_flash($a['name'] . ' is now your theme.' . (pb_layout_mode() === 'georank' ? ' (It shows when Layout is set to JotKite theme.)' : ''));
            } else {
                $list = pb_active_plugin_slugs();
                if (!in_array($slug, $list, true)) $list[] = $slug;
                pb_settings_save(['plugins' => json_encode(array_values($list))]);
                pb_flash($a['name'] . ' activated.');
            }
        } elseif ($do === 'addon_deactivate') {
            pb_settings_save(['plugins' => json_encode(array_values(array_diff(pb_active_plugin_slugs(), [$slug])))]);
            pb_flash($a['name'] . ' deactivated.');
        } else {
            $in = is_array($_POST['addon'] ?? null) ? $_POST['addon'] : [];
            $vals = [];
            foreach ($a['settings'] as $f) $vals['addon:' . $slug . ':' . $f['key']] = pb_addon_clean_value($f, $in[$f['key']] ?? '');
            pb_settings_save($vals);
            pb_flash($a['name'] . ' settings saved.');
        }
        pb_redirect('view=settings&tab=addons' . ($do === 'addon_settings_save' ? '#addon-' . rawurlencode($slug) : ''));
    }
    if ($do === 'robots_sitemap' && pb_can($user, 'settings.manage')) {
        $msg = pb_robots_add_sitemap();
        pb_flash($msg ?: 'Could not write robots.txt (check file permissions).', $msg ? 'ok' : 'error');
        pb_redirect('view=settings');
    }

    if ($do === 'account_save') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $cut = function ($s, $n) { $s = trim(str_replace("\r\n", "\n", (string) $s)); return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n); };
        if ($name !== '') pb_q('UPDATE users SET name = ?, bio = ? WHERE id = ?', [$cut($name, 80), $cut($_POST['bio'] ?? '', 250), $user['id']]);
        // Profile (0.25): long bio, photo, username and the privacy switches.
        if (array_key_exists('bio_long', $_POST)) {
            $avatar = trim((string) ($_POST['avatar'] ?? ''));
            if ($avatar !== '' && (pb_safe_url($avatar) === null || preg_match('/^(mailto|tel):/i', $avatar))) $avatar = '';
            pb_q('UPDATE users SET bio_long = ?, avatar = ?, show_name = ?, show_box = ?, public_page = ? WHERE id = ?',
                [$cut($_POST['bio_long'], 1000), $avatar, empty($_POST['show_name']) ? 0 : 1, empty($_POST['show_box']) ? 0 : 1, empty($_POST['public_page']) ? 0 : 1, $user['id']]);
        }
        $uname = trim((string) ($_POST['username'] ?? ''));
        if ($uname !== '' && strcasecmp($uname, (string) ($user['username'] ?? '')) !== 0) {
            $clean = pb_username_clean($uname);
            if ($clean === '' || $clean !== strtolower($uname)) { pb_flash('Usernames need 3–30 letters, digits, dots, dashes or underscores (no spaces).', 'error'); pb_redirect('view=account'); }
            if ((int) pb_val('SELECT COUNT(*) FROM users WHERE username = ? AND id != ?', [$clean, $user['id']])) { pb_flash('That username is already taken.', 'error'); pb_redirect('view=account'); }
            pb_q('UPDATE users SET username = ? WHERE id = ?', [$clean, $user['id']]);
        }
        if (array_key_exists('default_category_id', $_POST)) {
            $dc = (int) $_POST['default_category_id'];
            if ($dc && !pb_val('SELECT id FROM categories WHERE id = ?', [$dc])) $dc = 0;
            pb_q('UPDATE users SET default_category_id = ? WHERE id = ?', [$dc ?: null, $user['id']]);
        }
        $new = (string) ($_POST['new_password'] ?? '');
        if ($new !== '' && $user['source'] === 'local') {
            if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $user['password_hash'])) {
                pb_flash('Your current password is wrong.', 'error'); pb_redirect('view=account');
            }
            if (strlen($new) < 8) { pb_flash('Use at least 8 characters.', 'error'); pb_redirect('view=account'); }
            pb_q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            pb_device_forget_all($user['id'], true);
        }
        pb_flash('Account updated.');
        pb_redirect('view=account');
    }
    if ($do === 'device_forget') {
        $d = pb_row('SELECT selector FROM devices WHERE id = ? AND user_id = ?', [(int) ($_POST['id'] ?? 0), $user['id']]);
        if ($d && $d['selector'] === ($_SESSION['pb_device'] ?? '')) {
            pb_device_forget_current(); // this device: forget it, but stay signed in until the session ends
        } elseif ($d) {
            pb_q('DELETE FROM devices WHERE id = ? AND user_id = ?', [(int) $_POST['id'], $user['id']]);
        }
        pb_flash('Device signed out.');
        pb_redirect('view=account');
    }

    // ---- comments (lib/engage.php) ----
    $cBack = function () {
        $tab = preg_replace('/[^a-z]/', '', (string) ($_POST['back_tab'] ?? 'pending'));
        $pg = max(1, (int) ($_POST['back_pg'] ?? 1));
        pb_redirect('view=comments&tab=' . $tab . ($pg > 1 ? '&pg=' . $pg : '') . (!empty($_POST['anchor']) ? '#c' . (int) $_POST['anchor'] : ''));
    };
    if ($do === 'comment_status' || $do === 'comment_delete') {
        $c = pb_comment_by_id((int) ($_POST['id'] ?? 0));
        $to = (string) ($_POST['status'] ?? '');
        $err = $do === 'comment_delete' ? pb_comment_delete($user, $c) : pb_comment_set_status($user, $c, $to);
        if ($err) pb_flash($err, 'error');
        else pb_flash($do === 'comment_delete' ? 'Comment deleted.' : ['approved' => 'Approved. It\'s on the post now.', 'spam' => 'Marked as spam.', 'trash' => 'Moved to trash.', 'pending' => 'Moved back to Pending.'][$to]);
        $cBack();
    }
    if ($do === 'comment_reply') {
        $c = pb_comment_by_id((int) ($_POST['id'] ?? 0));
        $post = $c ? pb_post_by_id($c['post_id']) : null;
        if (!$c || !pb_can_moderate($user, $post)) { pb_flash('You can\'t reply to this comment here.', 'error'); $cBack(); }
        if ($c['status'] !== 'approved') pb_comment_set_status($user, $c, 'approved'); // replying approves it
        $r = pb_comment_add($post, $user, ['body' => (string) ($_POST['body'] ?? ''), 'parent' => $c['id']]);
        pb_flash(isset($r['error']) ? $r['error'] : 'Reply posted' . ($c['status'] !== 'approved' ? ', and the comment approved.' : '.'), isset($r['error']) ? 'error' : 'ok');
        $cBack();
    }
    if ($do === 'comment_empty' && pb_can_moderate($user)) {
        $which = ($_POST['which'] ?? '') === 'trash' ? 'trash' : 'spam';
        $mine = in_array($user['role'], ['editor', 'admin'], true) ? '' : ' AND post_id IN (SELECT id FROM posts WHERE author_id = ' . (int) $user['id'] . ')';
        $n = pb_q("DELETE FROM comments WHERE status = ?$mine", [$which])->rowCount();
        pb_flash('Deleted ' . $n . ' comment' . ($n === 1 ? '' : 's') . '.');
        pb_redirect('view=comments&tab=' . $which);
    }
    if ($do === 'engage_save' && pb_can($user, 'settings.manage')) {
        pb_settings_save([
            'comments_enabled' => !empty($_POST['comments_enabled']) ? '1' : '0',
            'comments_moderation' => ($_POST['comments_moderation'] ?? '') === 'off' ? 'off' : 'public',
            'comments_close_days' => (string) max(0, min(3650, (int) ($_POST['comments_close_days'] ?? 0))),
            'comments_notify' => !empty($_POST['comments_notify']) ? '1' : '0',
            'likes_enabled' => !empty($_POST['likes_enabled']) ? '1' : '0',
            'share_enabled' => !empty($_POST['share_enabled']) ? '1' : '0',
        ]);
        pb_flash('Comment settings saved.');
        pb_redirect('view=settings&tab=engage');
    }
    if (($do === 'mail_save' || $do === 'mail_test') && pb_can($user, 'settings.manage')) {
        if (pb_demo_locked()) { pb_flash('Email settings are locked in the demo.', 'info'); pb_redirect('view=settings&tab=engage'); }
        { // "Send a test" saves the form first, so it tests what's on screen
            $sec = in_array($_POST['smtp_secure'] ?? '', ['ssl', 'tls', 'none'], true) ? $_POST['smtp_secure'] : 'ssl';
            $from = trim((string) ($_POST['mail_from'] ?? ''));
            $save = [
                'mail_mode' => in_array($_POST['mail_mode'] ?? '', ['off', 'smtp', 'php'], true) ? $_POST['mail_mode'] : 'off',
                'smtp_host' => preg_replace('/[^a-z0-9.\-]/i', '', (string) ($_POST['smtp_host'] ?? '')),
                'smtp_port' => (string) max(1, min(65535, (int) ($_POST['smtp_port'] ?? 465))),
                'smtp_secure' => $sec,
                'smtp_user' => trim((string) ($_POST['smtp_user'] ?? '')),
                'mail_from' => filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : '',
                'mail_from_name' => substr(trim(str_replace(["\r", "\n"], ' ', (string) ($_POST['mail_from_name'] ?? ''))), 0, 80),
                'mail_last_error' => '',
            ];
            if ((string) ($_POST['smtp_pass'] ?? '') !== '') $save['smtp_pass'] = (string) $_POST['smtp_pass']; // blank keeps the saved one
            if (!empty($_POST['smtp_pass_clear'])) $save['smtp_pass'] = '';
            pb_settings_save($save);
            if ($do === 'mail_save') pb_flash('Email settings saved. Send a test to check them.');
        }
        if ($do === 'mail_test') {
            $err = pb_mail_deliverable($user['email']) ? pb_mail($user['email'], 'Test email from ' . pb_setting('blog_title'),
                "It works! JotKite can send email from this site.\n\nComment notifications will come from " . pb_mail_from() . '.')
                : 'Your account has no real email address. Add one in My account first.';
            pb_settings_save(['mail_last_error' => $err ? gmdate('Y-m-d H:i') . ' UTC · ' . $err : '']);
            pb_flash($err ? 'Test failed: ' . $err : 'Test email sent to ' . $user['email'] . '. Check the inbox (and the spam folder).', $err ? 'error' : 'ok');
        }
        pb_redirect('view=settings&tab=engage#mail');
    }

    // Addons handle their own forms: pb_add_action('pb_admin_post', function ($do, $user) {…}).
    // CSRF is already checked; handle your own "do" values and pb_redirect().
    pb_do_action('pb_admin_post', $do, $user);
    pb_flash('That action isn\'t available to your role.', 'error');
    pb_redirect();
}

// ============================================================================
// VIEWS
// ============================================================================
$isEditor = pb_can($user, 'category.manage');
$pendingCount = $isEditor ? (int) pb_val("SELECT COUNT(*) FROM posts WHERE status = 'pending'") : 0;
$myChanges = (int) pb_val("SELECT COUNT(*) FROM posts WHERE status = 'changes_requested' AND author_id = ?", [$user['id']]);
$commentsWaiting = !pb_can_moderate($user) ? 0 : (int) pb_val("SELECT COUNT(*) FROM comments c JOIN posts p ON p.id = c.post_id WHERE c.status = 'pending'"
    . (in_array($user['role'], ['editor', 'admin'], true) ? '' : ' AND p.author_id = ' . (int) $user['id']));

ob_start();
$title = 'Posts';

if ($view === 'edit') {
    $id = (int) ($_GET['id'] ?? 0);
    $post = $id ? pb_post_by_id($id) : null;
    if ($id && (!$post || !pb_can($user, 'post.view', $post))) { pb_flash('Post not found.', 'error'); pb_redirect(); }
    $canEdit = $post ? pb_can($user, 'post.edit', $post) : true;
    $cats = pb_all('SELECT id, name FROM categories ORDER BY sort, name');
    $p = $post ?: ['id' => 0, 'title' => '', 'slug' => '', 'body' => '', 'excerpt' => '', 'cover_image' => '', 'cover_alt' => '',
                   'category_id' => $user['default_category_id'] ?? null, 'seo_title' => '', 'seo_description' => '', 'status' => 'draft', 'published_at' => null,
                   'author_id' => $user['id'], 'author_name' => $user['name'], 'first_published_at' => null,
                   'type' => $isEditor && ($_GET['type'] ?? '') === 'page' ? 'page' : 'post', 'pinned' => 0];
    $isPage = $p['type'] === 'page';
    $title = $post ? ($isPage ? 'Edit page' : 'Edit post') : ($isPage ? 'New page' : 'Write');
    $events = $post ? pb_post_events($post['id']) : [];
    $lastNote = null;
    foreach ($events as $ev) if ($ev['action'] === 'request_changes') { $lastNote = $ev; break; }
    $previewUrl = $post ? pb_url('post', $post['slug']) . (pb_setting('pretty_urls') === '1' ? '?preview=1' : '&preview=1') : '';
    ?>
<?php if ($post && $post['status'] === 'changes_requested' && $lastNote): ?>
  <div class="pb-note"><strong>Changes requested by <?= pb_e($lastNote['user_name']) ?>:</strong> <?= nl2br(pb_e($lastNote['note'])) ?></div>
<?php endif; ?>
<?php if (!$canEdit): ?>
  <div class="pb-note pb-note-info">This post is <?= pb_e(strtolower(pb_status_label($p['status'], $p['published_at']))) ?> and can't be edited by your role. Ask an Editor if something needs to change.</div>
<?php endif; ?>
<form method="post" id="pbPostForm" class="pb-edit-grid" data-new="<?= $post ? '0' : '1' ?>">
  <?= pb_csrf_field() ?>
  <input type="hidden" name="do" value="save_post">
  <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
  <input type="hidden" name="then" value="save" id="pbThen">
  <div class="pb-card pb-editor-card">
    <input class="pb-title-input" name="title" id="pbTitle" placeholder="<?= $isPage ? 'Page title (e.g. About)' : 'Your next post…' ?>" value="<?= pb_e($p['title']) ?>" <?= $canEdit ? '' : 'readonly' ?> maxlength="200" aria-label="Title">
    <?php if ($canEdit): ?>
    <div class="pb-toolbar" role="toolbar" aria-label="Formatting">
      <button type="button" data-cmd="bold" title="Bold (Ctrl+B)"><b>B</b></button>
      <button type="button" data-cmd="italic" title="Italic (Ctrl+I)"><i>I</i></button>
      <button type="button" data-cmd="h2" title="Heading">H2</button>
      <button type="button" data-cmd="h3" title="Subheading">H3</button>
      <?php /* Image early in the row: on a phone it's visible without scrolling the toolbar. */ ?>
      <button type="button" data-cmd="image" title="Insert image">🖼 Image</button>
      <span class="pb-tsep"></span>
      <button type="button" data-cmd="link" title="Link (Ctrl+K)">🔗</button>
      <button type="button" data-cmd="insertUnorderedList" title="Bullet list">• List</button>
      <button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
      <button type="button" data-cmd="blockquote" title="Quote">❝</button>
      <?php $alignIcon = function ($lines) { return '<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" fill="currentColor">' . $lines . '</svg>'; }; ?>
      <button type="button" data-cmd="justifyLeft" title="Align left" aria-label="Align left"><?= $alignIcon('<rect x="1" y="2" width="14" height="2" rx="1"/><rect x="1" y="7" width="9" height="2" rx="1"/><rect x="1" y="12" width="12" height="2" rx="1"/>') ?></button>
      <button type="button" data-cmd="justifyCenter" title="Centre" aria-label="Centre"><?= $alignIcon('<rect x="1" y="2" width="14" height="2" rx="1"/><rect x="3.5" y="7" width="9" height="2" rx="1"/><rect x="2" y="12" width="12" height="2" rx="1"/>') ?></button>
      <button type="button" data-cmd="justifyRight" title="Align right" aria-label="Align right"><?= $alignIcon('<rect x="1" y="2" width="14" height="2" rx="1"/><rect x="6" y="7" width="9" height="2" rx="1"/><rect x="3" y="12" width="12" height="2" rx="1"/>') ?></button>
      <span class="pb-tsep"></span>
      <button type="button" data-cmd="video" title="Embed YouTube or Vimeo">▶ Video</button>
      <button type="button" data-cmd="removeFormat" title="Clear formatting">⌫</button>
      <button type="button" data-cmd="source" title="Edit HTML" class="pb-tright">&lt;/&gt;</button>
    </div>
    <?php endif; ?>
    <div class="pb-editor pb-prose" id="pbEditor" <?= $canEdit ? 'contenteditable="true"' : '' ?> data-placeholder="Write something amazing…"><?= $p['body'] ?></div>
    <textarea name="body" id="pbBody" class="pb-source" hidden><?= pb_e($p['body']) ?></textarea>
    <?php if ($canEdit): ?>
    <p class="pb-editor-tip">Write anywhere, paste here: Word, Google Docs, WordPress blocks and Markdown keep their formatting, and pasted images are saved to your blog.
      <span class="pb-editor-tip-keys">Shortcuts: <code>##</code> heading · <code>-</code> list · <code>1.</code> numbered · <code>&gt;</code> quote · <code>**bold**</code> · <code>*italic*</code> · <code>`code`</code> · <code>---</code> line</span></p>
    <?php endif; ?>
    <div class="pb-imgbar" id="pbImgBar" hidden role="toolbar" aria-label="Image options">
      <span class="pb-imgbar-label">Wrap</span>
      <button type="button" data-align="" title="No wrap (on its own line)">None</button>
      <button type="button" data-align="left" title="Float left, text wraps on the right">⇤ Left</button>
      <button type="button" data-align="center" title="Centred on its own line">Centre</button>
      <button type="button" data-align="right" title="Float right, text wraps on the left">Right ⇥</button>
      <span class="pb-imgbar-sep"></span>
      <span class="pb-imgbar-label">Size</span>
      <button type="button" data-size="s" title="300px wide">S</button>
      <button type="button" data-size="m" title="500px wide">M</button>
      <button type="button" data-size="l" title="760px wide">L</button>
      <button type="button" data-size="full" title="Full width">Full</button>
      <span class="pb-imgbar-sep"></span>
      <button type="button" data-img-act="alt" title="Alt text">Alt</button>
      <button type="button" data-img-act="caption" title="Caption">Caption</button>
      <button type="button" data-img-act="above" title="Add a line of text above">↑ Text above</button>
      <button type="button" data-img-act="below" title="Add a line of text below (or press Enter)">↓ Text below</button>
      <button type="button" data-img-act="remove" title="Remove" class="pb-danger-text">✕</button>
    </div>
    <input type="file" id="pbImageFile" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
    <div class="pb-linkdlg" id="pbLinkDlg" hidden role="dialog" aria-modal="true" aria-labelledby="pbLinkTitle">
      <div class="pb-linkdlg-box">
        <h3 class="pb-h3" id="pbLinkTitle">Insert link</h3>
        <label class="pb-small">Address, or search your posts and pages
          <input type="text" id="pbLinkUrl" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="https://… or type a few words" aria-controls="pbLinkResults"></label>
        <ul class="pb-link-results" id="pbLinkResults" role="listbox" aria-label="Posts and pages"></ul>
        <label class="pb-check pb-small"><input type="checkbox" id="pbLinkNewTab"> Open in a new tab</label>
        <div class="pb-linkdlg-actions">
          <button type="button" class="pb-btn pb-btn-danger" id="pbLinkUnlink" hidden>Unlink</button>
          <button type="button" class="pb-btn" data-link-close>Cancel</button>
          <button type="button" class="pb-btn pb-btn-primary" id="pbLinkApply">Apply</button>
        </div>
      </div>
    </div>
  </div>

  <aside class="pb-side">
    <div class="pb-card">
      <div class="pb-side-head">
        <?= pb_status_badge($p['status'], $p['published_at']) ?>
        <?php if ($post): ?><a class="pb-link-sm" href="<?= pb_e($previewUrl) ?>" target="_blank" rel="noopener">Preview ↗</a><?php endif; ?>
      </div>
      <p class="pb-muted pb-small">By <?= pb_e($p['author_name']) ?><?php if ($p['published_at'] && $p['status'] === 'published'): ?> · <?= pb_e(pb_format_date($p['published_at'], 'j M Y, g:i a')) ?><?php endif; ?></p>
      <?php if ($canEdit): ?>
        <?php if (pb_can($user, 'post.publish', $p)): ?>
          <label class="pb-small">Publish date <span class="pb-muted">(future = scheduled)</span>
            <input type="datetime-local" name="<?= $p['status'] === 'published' ? 'published_at' : 'publish_at' ?>" value="<?= pb_e(pb_utc_to_local_input($p['status'] === 'published' ? $p['published_at'] : null)) ?>">
          </label>
        <?php endif; ?>
        <div class="pb-actions">
          <button class="pb-btn pb-btn-primary" data-then="save">Save<?= $p['status'] === 'published' ? ' changes' : ' draft' ?></button>
          <?php if (pb_can($user, 'post.submit', $p) && !pb_can($user, 'post.publish', $p)): ?>
            <button class="pb-btn pb-btn-primary" data-then="submit">Submit for review</button>
          <?php endif; ?>
          <?php if (pb_can($user, 'post.publish', $p) && $p['status'] !== 'published'): ?>
            <button class="pb-btn pb-btn-primary" data-then="publish"><?= $p['status'] === 'pending' ? 'Approve &amp; publish' : 'Publish' ?></button>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($post && pb_can($user, 'post.request_changes', $post)): ?>
    <div class="pb-card">
      <h3 class="pb-h3">Review</h3>
      <p class="pb-muted pb-small">Not ready? Send it back to <?= pb_e($post['author_name']) ?> with notes.</p>
      <textarea form="pbReviewForm" name="note" rows="3" placeholder="What should change?" required></textarea>
      <button form="pbReviewForm" class="pb-btn pb-btn-warn pb-btn-block">Request changes</button>
    </div>
    <?php endif; ?>

    <div class="pb-card">
      <h3 class="pb-h3"><?= $isPage ? 'Page details' : 'Post details' ?></h3>
      <?php if ($isEditor): ?>
        <label class="pb-small">Type
          <select name="type" id="pbType" <?= $canEdit ? '' : 'disabled' ?>>
            <option value="post"<?= !$isPage ? ' selected' : '' ?>>Blog post (listed on the blog, RSS, categories)</option>
            <option value="page"<?= $isPage ? ' selected' : '' ?>>Page (About, Contact… — not listed, add it to Navigation)</option>
          </select>
        </label>
        <label class="pb-check pb-small" id="pbPinWrap"<?= $isPage ? ' hidden' : '' ?>><input type="checkbox" name="pinned" value="1"<?= !empty($p['pinned']) ? ' checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>> 📌 Pin as Featured at the top of the blog home</label>
      <?php endif; ?>
      <label class="pb-small" id="pbCatWrap"<?= $isPage ? ' hidden' : '' ?>>Category
        <select name="category_id" <?= $canEdit ? '' : 'disabled' ?>>
          <option value="0">— None —</option>
          <?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $p['category_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= pb_e($c['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <?php if (!$cats && $isEditor): ?><p class="pb-small pb-muted"><a href="<?= pb_e(pb_admin_url('view=categories')) ?>">Add categories</a></p><?php endif; ?>
      <div class="pb-cover-field">
        <?= pb_image_field('cover_image', $p['cover_image'], 'Featured image', 'Shown on the post and its card. Without one, the blog uses the first image in the post, then the default social image.', $canEdit) ?>
        <input name="cover_alt" placeholder="Describe the image (alt text)" value="<?= pb_e($p['cover_alt']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
      </div>
      <label class="pb-small">Excerpt <span class="pb-muted pb-count" data-for="pbExcerpt" data-max="200"></span>
        <textarea name="excerpt" id="pbExcerpt" rows="3" maxlength="300" placeholder="Short summary for the post list (optional)" <?= $canEdit ? '' : 'readonly' ?>><?= pb_e($p['excerpt']) ?></textarea>
      </label>
      <?php if (pb_comments_enabled()): ?>
      <label class="pb-check pb-small" id="pbCommentsWrap"<?= $isPage ? ' hidden' : '' ?>><input type="hidden" name="comments_present" value="1"><input type="checkbox" name="comments_open" value="1"<?= (int) ($p['comments_open'] ?? 1) === 1 ? ' checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>> 💬 Allow comments</label>
      <?php endif; ?>
    </div>

    <details class="pb-card">
      <summary class="pb-h3">SEO &amp; URL</summary>
      <label class="pb-small">URL slug
        <input name="slug" id="pbSlug" value="<?= pb_e($p['slug']) ?>" placeholder="auto from title" <?= $canEdit ? '' : 'readonly' ?> pattern="[a-z0-9\-]*">
      </label>
      <label class="pb-small">SEO title <span class="pb-muted pb-count" data-for="pbSeoTitle" data-max="60"></span>
        <input name="seo_title" id="pbSeoTitle" value="<?= pb_e($p['seo_title']) ?>" placeholder="Defaults to the post title" <?= $canEdit ? '' : 'readonly' ?>>
      </label>
      <label class="pb-small">Meta description <span class="pb-muted pb-count" data-for="pbSeoDesc" data-max="160"></span>
        <textarea name="seo_description" id="pbSeoDesc" rows="3" placeholder="Defaults to the excerpt" <?= $canEdit ? '' : 'readonly' ?>><?= pb_e($p['seo_description']) ?></textarea>
      </label>
    </details>

    <?php // Addons add their own cards here (inside the post form; read the fields in pb_post_saved). ?>
    <?= pb_capture_action('pb_editor_panel', $p, $user, $canEdit) ?>

    <?php if ($post): ?>
    <div class="pb-card pb-more-actions">
      <?php foreach ([['withdraw', 'post.withdraw', 'Withdraw from review', ''], ['unpublish', 'post.unpublish', 'Unpublish', 'Take this post off the blog?'],
                      ['archive', 'post.archive', 'Archive', 'Archive this post? It will be hidden from the blog.'], ['restore', 'post.restore', 'Restore as draft', ''],
                      ['delete', 'post.delete', 'Delete permanently', 'Delete this post forever? This cannot be undone.']] as [$act, $perm, $label, $confirm]):
          if (!pb_can($user, $perm, $post)) continue; ?>
        <button form="pbAct_<?= $act ?>" class="pb-btn pb-btn-sm<?= $act === 'delete' || $act === 'archive' ? ' pb-btn-danger' : '' ?>"<?= $confirm ? ' data-confirm="' . pb_e($confirm) . '"' : '' ?>><?= pb_e($label) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="pb-card">
      <h3 class="pb-h3">Activity</h3>
      <ol class="pb-activity">
        <?php foreach ($events as $ev): ?>
        <li><strong><?= pb_e($ev['user_name'] ?? 'Someone') ?></strong> <?= pb_e(str_replace('_', ' ', $ev['action'])) ?>
          <span class="pb-muted">· <?= pb_e(pb_format_date($ev['created_at'], 'j M, g:i a')) ?></span>
          <?php if ($ev['note'] !== ''): ?><blockquote><?= nl2br(pb_e($ev['note'])) ?></blockquote><?php endif; ?></li>
        <?php endforeach; ?>
      </ol>
    </div>
    <?php endif; ?>
  </aside>
</form>
<?php if ($post): ?>
  <form method="post" id="pbReviewForm" hidden><?= pb_csrf_field() ?><input type="hidden" name="do" value="transition"><input type="hidden" name="id" value="<?= (int) $post['id'] ?>"><input type="hidden" name="action" value="request_changes"></form>
  <?php foreach (['withdraw', 'unpublish', 'archive', 'restore', 'delete'] as $act): ?>
  <form method="post" id="pbAct_<?= $act ?>" hidden><?= pb_csrf_field() ?><input type="hidden" name="do" value="transition"><input type="hidden" name="id" value="<?= (int) $post['id'] ?>"><input type="hidden" name="action" value="<?= $act ?>"></form>
  <?php endforeach; ?>
<?php endif; ?>
<?php

} elseif ($view === 'comments' && pb_can_moderate($user)) {
    $title = 'Comments';
    $ctabs = ['pending' => 'Pending', 'approved' => 'Approved', 'spam' => 'Spam', 'trash' => 'Trash'];
    $ctab = isset($ctabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'pending';
    $mineOnly = !in_array($user['role'], ['editor', 'admin'], true); // Authors: comments on their own posts
    $cw = $mineOnly ? ' AND p.author_id = ' . (int) $user['id'] : '';
    $counts = [];
    foreach (pb_all("SELECT c.status, COUNT(*) AS n FROM comments c JOIN posts p ON p.id = c.post_id WHERE 1 = 1$cw GROUP BY c.status") as $r) $counts[$r['status']] = (int) $r['n'];
    $per = 30;
    $cpg = max(1, (int) ($_GET['pg'] ?? 1));
    $rows = pb_all("SELECT c.*, p.title AS post_title, p.slug AS post_slug, u.avatar AS user_avatar, u.role AS user_role, pc.name AS parent_name
                    FROM comments c JOIN posts p ON p.id = c.post_id LEFT JOIN users u ON u.id = c.user_id LEFT JOIN comments pc ON pc.id = c.parent_id
                    WHERE c.status = :st$cw ORDER BY c.id " . ($ctab === 'pending' ? 'ASC' : 'DESC') . ' LIMIT :lim OFFSET :off',
                    ['st' => $ctab, 'lim' => $per + 1, 'off' => ($cpg - 1) * $per]);
    $more = count($rows) > $per;
    $rows = array_slice($rows, 0, $per);
    $hidden = function ($id, $extra = '') use ($ctab, $cpg) {
        return pb_csrf_field() . '<input type="hidden" name="id" value="' . (int) $id . '"><input type="hidden" name="back_tab" value="' . $ctab . '">'
             . '<input type="hidden" name="back_pg" value="' . $cpg . '">' . $extra;
    }; ?>
<div class="pb-tabs">
  <?php foreach ($ctabs as $k => $label): ?>
    <a href="<?= pb_e(pb_admin_url('view=comments&tab=' . $k)) ?>" class="<?= $k === $ctab ? 'active' : '' ?>"><?= $label ?><?php if (!empty($counts[$k])): ?><span><?= $counts[$k] ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</div>
<?php if (!pb_comments_enabled() && pb_can($user, 'settings.manage')): ?><div class="pb-note pb-note-info pb-small">Comments are switched off for the whole blog. <a href="<?= pb_e(pb_admin_url('view=settings&tab=engage')) ?>">Settings → Comments &amp; email</a></div><?php endif; ?>
<?php if ($rows): ?>
<?php if (in_array($ctab, ['spam', 'trash'], true)): ?>
  <form method="post" class="pb-row pb-comment-empty"><?= pb_csrf_field() ?><input type="hidden" name="do" value="comment_empty"><input type="hidden" name="which" value="<?= $ctab ?>">
    <button class="pb-btn pb-btn-sm pb-btn-danger" data-confirm="Delete every comment in <?= $ctabs[$ctab] ?> for good?"><?= $ctab === 'spam' ? 'Delete all spam' : 'Empty trash' ?></button></form>
<?php endif; ?>
<div class="pb-comment-list">
<?php foreach ($rows as $c): $guest = !$c['user_id']; ?>
  <article class="pb-card pb-cmt" id="c<?= (int) $c['id'] ?>">
    <header class="pb-cmt-head">
      <strong><?= pb_e($c['name']) ?></strong>
      <span class="pb-small pb-muted"><?= $guest ? 'Visitor' : pb_e(pb_role_label($c['user_role'] ?? '')) ?><?= $guest && (int) $c['notify'] === 1 ? ' · 📧 wants email updates' : '' ?></span>
      <span class="pb-small pb-muted pb-cmt-when"><?= pb_e(pb_format_date($c['created_at'], 'j M Y, g:i a')) ?></span>
    </header>
    <p class="pb-small pb-muted pb-cmt-on">on <a href="<?= pb_e(pb_url('post', $c['post_slug'])) ?>#comment-<?= (int) $c['id'] ?>" target="_blank" rel="noopener"><?= pb_e($c['post_title']) ?></a><?php if ($c['parent_name']): ?> · reply to <?= pb_e($c['parent_name']) ?><?php endif; ?></p>
    <div class="pb-cmt-body"><?= pb_comment_body_html($c['body'], !$guest) ?></div>
    <div class="pb-row pb-cmt-actions">
      <?php $acts = ['pending' => ['approved' => 'Approve', 'spam' => 'Spam', 'trash' => 'Trash'], 'approved' => ['pending' => 'Unapprove', 'spam' => 'Spam', 'trash' => 'Trash'],
                     'spam' => ['pending' => 'Not spam'], 'trash' => ['pending' => 'Restore']][$ctab];
      foreach ($acts as $to => $label): ?>
      <form method="post" class="pb-inline"><?= $hidden($c['id'], '<input type="hidden" name="do" value="comment_status"><input type="hidden" name="status" value="' . $to . '">') ?>
        <button class="pb-btn pb-btn-sm<?= $to === 'approved' ? ' pb-btn-primary' : '' ?>"><?= $label ?></button></form>
      <?php endforeach; ?>
      <?php if (in_array($ctab, ['spam', 'trash'], true)): ?>
      <form method="post" class="pb-inline"><?= $hidden($c['id'], '<input type="hidden" name="do" value="comment_delete">') ?>
        <button class="pb-btn pb-btn-sm pb-btn-danger" data-confirm="Delete this comment for good? Replies to it go too.">Delete forever</button></form>
      <?php endif; ?>
    </div>
    <?php if (in_array($ctab, ['pending', 'approved'], true)): ?>
    <details class="pb-cmt-reply"><summary class="pb-small">Reply<?= $ctab === 'pending' ? ' (and approve)' : '' ?></summary>
      <form method="post"><?= $hidden($c['id'], '<input type="hidden" name="do" value="comment_reply"><input type="hidden" name="anchor" value="' . (int) $c['id'] . '">') ?>
        <textarea name="body" rows="3" required placeholder="Your reply, as <?= pb_e($user['name']) ?>"></textarea>
        <button class="pb-btn pb-btn-sm pb-btn-primary">Post reply</button></form>
    </details>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
</div>
<?php if ($cpg > 1 || $more): ?>
<nav class="pb-row pb-pager-admin">
  <?php if ($cpg > 1): ?><a class="pb-btn pb-btn-sm" href="<?= pb_e(pb_admin_url('view=comments&tab=' . $ctab . '&pg=' . ($cpg - 1))) ?>">← Previous</a><?php endif; ?>
  <?php if ($more): ?><a class="pb-btn pb-btn-sm" href="<?= pb_e(pb_admin_url('view=comments&tab=' . $ctab . '&pg=' . ($cpg + 1))) ?>">Next →</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php else: ?>
  <div class="pb-empty-admin"><p><?= ['pending' => 'No comments waiting. 🎉', 'approved' => 'No approved comments yet.', 'spam' => 'No spam.', 'trash' => 'Trash is empty.'][$ctab] ?></p></div>
<?php endif; ?>
<?php

} elseif ($view === 'categories' && $isEditor) {
    $title = 'Categories';
    $rows = pb_all('SELECT c.*, (SELECT COUNT(*) FROM posts p WHERE p.category_id = c.id) AS n FROM categories c ORDER BY c.sort, c.name');
    $editId = (int) ($_GET['id'] ?? 0);
    $ec = $editId ? pb_row('SELECT * FROM categories WHERE id = ?', [$editId]) : null; ?>
<div class="pb-two-col">
  <div class="pb-card">
    <table class="pb-table">
      <thead><tr><th>Name</th><th>Slug</th><th>Posts</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $c): ?>
        <tr><td><strong><?= pb_e($c['name']) ?></strong><?php if ($c['description'] !== ''): ?><div class="pb-small pb-muted"><?= pb_e($c['description']) ?></div><?php endif; ?></td>
          <td><code><?= pb_e($c['slug']) ?></code></td><td><?= (int) $c['n'] ?></td>
          <td class="pb-right"><a class="pb-btn pb-btn-sm" href="<?= pb_e(pb_admin_url('view=categories&id=' . (int) $c['id'])) ?>">Edit</a>
            <form method="post" class="pb-inline"><?= pb_csrf_field() ?><input type="hidden" name="do" value="category_delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="pb-btn pb-btn-sm pb-btn-danger" data-confirm="Delete this category? Its posts stay, uncategorised.">Delete</button></form></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="4" class="pb-muted">No categories yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <form method="post" class="pb-card">
    <h3 class="pb-h3"><?= $ec ? 'Edit category' : 'New category' ?></h3>
    <?= pb_csrf_field() ?><input type="hidden" name="do" value="category_save"><input type="hidden" name="id" value="<?= (int) ($ec['id'] ?? 0) ?>">
    <label>Name<input name="name" required value="<?= pb_e($ec['name'] ?? '') ?>"></label>
    <label>Slug<input name="slug" value="<?= pb_e($ec['slug'] ?? '') ?>" placeholder="auto"></label>
    <label>Description<textarea name="description" rows="3"><?= pb_e($ec['description'] ?? '') ?></textarea></label>
    <label>Order<input type="number" name="sort" value="<?= (int) ($ec['sort'] ?? 0) ?>"></label>
    <button class="pb-btn pb-btn-primary">Save category</button>
    <?php if ($ec): ?><a class="pb-btn" href="<?= pb_e(pb_admin_url('view=categories')) ?>">Cancel</a><?php endif; ?>
  </form>
</div>
<?php

} elseif ($view === 'users' && pb_can($user, 'user.manage')) {
    $title = 'Users';
    $rows = pb_all('SELECT u.*, (SELECT COUNT(*) FROM posts p WHERE p.author_id = u.id) AS n FROM users u ORDER BY u.active DESC, u.role, u.name');
    $editId = (int) ($_GET['id'] ?? 0);
    $eu = $editId ? pb_row('SELECT * FROM users WHERE id = ?', [$editId]) : null; ?>
<div class="pb-two-col">
  <div class="pb-card">
    <table class="pb-table">
      <thead><tr><th>Name</th><th>Role</th><th>Posts</th><th>Last sign-in</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $u): ?>
        <tr class="<?= (int) $u['active'] ? '' : 'pb-dim' ?>"><td><strong><?= pb_e($u['name']) ?></strong>
            <div class="pb-small pb-muted"><?= $u['source'] === 'georank' ? 'Signs in through GeoRank' : pb_e($u['email']) ?><?= !empty($u['username']) ? ' · @' . pb_e($u['username']) : '' ?></div></td>
          <td><span class="pb-role pb-role-<?= pb_e($u['role']) ?>"><?= pb_e(pb_role_label($u['role'])) ?></span><?= (int) $u['active'] ? '' : ' <span class="pb-small pb-muted">(inactive)</span>' ?></td>
          <td><?= (int) $u['n'] ?></td>
          <td class="pb-small pb-muted"><?= $u['last_login_at'] ? pb_e(pb_format_date($u['last_login_at'], 'j M Y')) : '—' ?></td>
          <td class="pb-right"><a class="pb-btn pb-btn-sm" href="<?= pb_e(pb_admin_url('view=users&id=' . (int) $u['id'])) ?>">Edit</a>
          <?php if ((int) $u['id'] !== (int) $user['id']): ?>
            <form method="post" class="pb-inline"><?= pb_csrf_field() ?><input type="hidden" name="do" value="user_toggle"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button class="pb-btn pb-btn-sm"><?= (int) $u['active'] ? 'Deactivate' : 'Activate' ?></button></form>
          <?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="pb-roles-help pb-small">
      <p><span class="pb-role pb-role-contributor">Contributor</span> writes drafts and submits them for review. Can't publish.</p>
      <p><span class="pb-role pb-role-author">Author</span> writes, publishes and edits <em>their own</em> posts, no review needed. Can't touch anyone else's.</p>
      <p><span class="pb-role pb-role-editor">Editor</span> edits any post, approves, schedules, requests changes, unpublishes, manages categories.</p>
      <p><span class="pb-role pb-role-admin">Admin</span> everything, plus users, settings and permanent delete.</p>
    </div>
  </div>
  <form method="post" class="pb-card" autocomplete="off">
    <h3 class="pb-h3"><?= $eu ? 'Edit user' : 'Invite a writer' ?></h3>
    <?= pb_csrf_field() ?><input type="hidden" name="do" value="user_save"><input type="hidden" name="id" value="<?= (int) ($eu['id'] ?? 0) ?>">
    <label>Display name <span class="pb-small pb-muted">(shown on posts)</span><input name="name" required value="<?= pb_e($eu['name'] ?? '') ?>"></label>
    <label>Username <span class="pb-small pb-muted">(sign-in and author page; empty = made from the name)</span><input name="username" value="<?= pb_e($eu['username'] ?? '') ?>" autocapitalize="none" spellcheck="false" pattern="[A-Za-z0-9._-]{3,30}"></label>
    <?php if ($eu && $eu['source'] === 'georank'): ?>
      <p class="pb-small pb-muted">This account is used whenever someone signs in to GeoRank as <?= $eu['email'] === 'georank-admin@georank.local' ? 'Admin' : 'Editor' ?>. Its role follows GeoRank and the Settings page.</p>
    <?php else: ?>
    <label>Email<input type="email" name="email" required value="<?= pb_e($eu['email'] ?? '') ?>"></label>
    <label>Role<select name="role">
      <?php foreach (PB_ROLES as $r): ?><option value="<?= $r ?>"<?= ($eu['role'] ?? 'contributor') === $r ? ' selected' : '' ?>><?= pb_role_label($r) ?></option><?php endforeach; ?>
    </select></label>
    <label><?= $eu ? 'New password (leave blank to keep)' : 'Password' ?><input type="text" name="password" <?= $eu ? '' : 'required' ?> minlength="8" autocomplete="new-password"></label>
    <?php endif; ?>
    <button class="pb-btn pb-btn-primary">Save user</button>
    <?php if ($eu): ?><a class="pb-btn" href="<?= pb_e(pb_admin_url('view=users')) ?>">Cancel</a><?php endif; ?>
  </form>
</div>
<?php

} elseif ($view === 'settings' && pb_can($user, 'settings.manage')) {
    $title = 'Settings';
    $s = function ($k) { return pb_setting($k); };
    $stabs = ['general' => 'General', 'design' => 'Design', 'navigation' => 'Navigation', 'engage' => 'Comments & email', 'code' => 'Code', 'addons' => 'Addons'] + (pb_demo_on() ? ['demo' => 'Demo'] : []);
    $stab = isset($stabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'general';
    $isGr = pb_is_georank_site();
    // GeoRank options only where they mean something: on a GeoRank site, or while a
    // GeoRank login is in use. A plain install (e.g. from GitHub) never sees them.
    $showGr = $isGr || pb_georank_session_role() !== null || pb_setting('layout') === 'georank'; ?>
<div class="pb-tabs">
  <?php foreach ($stabs as $k => $label): ?>
    <a href="<?= pb_e(pb_admin_url('view=settings&tab=' . $k)) ?>" class="<?= $k === $stab ? 'active' : '' ?>"><?= pb_e($label) ?></a>
  <?php endforeach; ?>
</div>
<?php if ($stab === 'design'):
    $fonts = pb_font_choices(); ?>
<div class="pb-two-col">
  <form method="post" class="pb-card" id="pbDesignForm">
    <?= pb_csrf_field() ?><input type="hidden" name="do" value="design_save">
    <h3 class="pb-h3">Images</h3>
    <div class="pb-row pb-row-top">
      <?= pb_image_field('design_social_image', $s('design_social_image'), 'Default social share image', 'Used for Facebook/WhatsApp/X previews, and on post cards, when a post has no image of its own. 1200×630 works best.') ?>
      <?= pb_image_field('design_favicon', $s('design_favicon'), 'Blog favicon', 'Square PNG, at least 64×64. Leave empty to use the site\'s favicon.') ?>
    </div>
    <label>Posts without an image show<select name="fallback_image">
      <option value="social"<?= $s('fallback_image') === 'social' ? ' selected' : '' ?>>The default social share image (above)</option>
      <option value="illustrations"<?= $s('fallback_image') === 'illustrations' ? ' selected' : '' ?>>JotKite illustrations (10, one picked per post)</option>
      <option value="none"<?= $s('fallback_image') === 'none' ? ' selected' : '' ?>>No image</option>
    </select><span class="pb-small pb-muted">For posts with no featured image and no image in the text. The same post always gets the same picture: in the blog list, on the post and in link previews.</span></label>
    <div class="pb-illus-strip" aria-hidden="true"><?php foreach (glob(PB_ROOT . '/assets/illustrations/*.webp') ?: [] as $f): ?><img src="<?= pb_e(PB_BASE_PATH . '/assets/illustrations/' . basename($f)) ?>" alt="" loading="lazy" width="120" height="63"><?php endforeach; ?></div>

    <h3 class="pb-h3">Typography</h3>
    <div class="pb-row">
      <label>Body font<select name="design_font_body" data-preview="font-body">
        <?php foreach ($fonts as $k => [$label, $stack]): ?><option value="<?= pb_e($k) ?>" data-stack="<?= pb_e($stack) ?>"<?= $s('design_font_body') === $k ? ' selected' : '' ?>><?= pb_e($label) ?></option><?php endforeach; ?>
      </select></label>
      <label>Heading font<select name="design_font_heading" data-preview="font-heading">
        <?php foreach ($fonts as $k => [$label, $stack]): ?><option value="<?= pb_e($k) ?>" data-stack="<?= pb_e($stack) ?>"<?= $s('design_font_heading') === $k ? ' selected' : '' ?>><?= pb_e($label) ?></option><?php endforeach; ?>
      </select></label>
      <label>Text size<select name="design_font_size" data-preview="font-size">
        <option value="">Site theme</option>
        <?php foreach ([15, 16, 17, 18, 19, 20] as $px): ?><option value="<?= $px ?>"<?= (string) $s('design_font_size') === (string) $px ? ' selected' : '' ?>><?= $px ?>px</option><?php endforeach; ?>
      </select></label>
    </div>

    <h3 class="pb-h3">Colours <span class="pb-small pb-muted">(empty = follow the site theme)</span></h3>
    <div class="pb-row pb-row-top">
      <?= pb_color_field('design_accent', $s('design_accent'), 'Accent (links, buttons)') ?>
      <?= pb_color_field('design_text', $s('design_text'), 'Text') ?>
    </div>
    <div class="pb-row pb-row-top">
      <?= pb_color_field('design_bg', $s('design_bg'), 'Page background') ?>
      <?= pb_color_field('design_surface', $s('design_surface'), 'Card background') ?>
    </div>
    <button class="pb-btn pb-btn-primary">Save design</button>
  </form>
  <div class="pb-card pb-design-preview" id="pbDesignPreview">
    <h3 class="pb-h3">Preview</h3>
    <div class="pbp-page">
      <h2 class="pbp-h">How to Choose Floor Tiles</h2>
      <p class="pbp-p">Blog text looks like this, with a <a href="#" onclick="return false">link</a> in the accent colour.</p>
      <div class="pbp-card"><strong class="pbp-h">Post card</strong><p class="pbp-p pbp-small">Short summary of the post…</p></div>
      <span class="pbp-btn">Search</span>
    </div>
    <p class="pb-small pb-muted"><?= $isGr ? 'Empty values follow your GeoRank theme (Design tab), so the blog keeps matching the site.' : 'Empty values use the JotKite defaults.' ?></p>
  </div>
</div>
<?php elseif ($stab === 'demo'):
    $snap = pb_demo_dir() . '/seed.sqlite'; ?>
<div class="pb-two-col">
  <div class="pb-card">
    <h3 class="pb-h3">Demo mode is on</h3>
    <p class="pb-small">Set in <code>config.php</code>. Visitors sign in with one click as Admin, Editor or Contributor. The database and uploads go back to the saved starting point every <strong><?= pb_demo_minutes() ?> minutes</strong>.</p>
    <p class="pb-small pb-muted">Last reset: <?= pb_demo_last_reset() ? pb_e(pb_format_date(gmdate('Y-m-d H:i:s', pb_demo_last_reset()), 'j M Y, g:i a')) : '—' ?> · next in <?= pb_demo_minutes_left() ?> min
      · starting point saved <?= is_file($snap) ? pb_e(pb_format_date(gmdate('Y-m-d H:i:s', filemtime($snap)), 'j M Y, g:i a')) : '—' ?></p>
    <p class="pb-small pb-muted">Locked for visitors: Settings → Code, robots.txt, and the demo accounts' passwords, emails and roles. Public pages are marked <code>noindex</code> and show a "Try the admin" bar.</p>
  </div>
  <div class="pb-card">
    <h3 class="pb-h3">Owner tools</h3>
    <?php if (!pb_demo_owner()): ?>
      <form method="post"><?= pb_csrf_field() ?><input type="hidden" name="do" value="demo_unlock">
        <label>Demo key <span class="pb-small pb-muted">(<code>demo_key</code> in config.php)</span><input type="password" name="key" autocomplete="off" required></label>
        <button class="pb-btn pb-btn-primary">Unlock</button></form>
    <?php else: ?>
      <p class="pb-small">Unlocked for this session. Arrange the posts, pages, images and settings the way every visitor should find them, then save.</p>
      <form method="post" class="pb-inline"><?= pb_csrf_field() ?><input type="hidden" name="do" value="demo_snapshot">
        <button class="pb-btn pb-btn-primary" data-confirm="Make the current content the demo's starting point?">Save current content as the starting point</button></form>
      <form method="post" class="pb-inline"><?= pb_csrf_field() ?><input type="hidden" name="do" value="demo_reset">
        <button class="pb-btn" data-confirm="Throw away all changes since the last save?">Reset now</button></form>
      <form method="post" class="pb-inline"><?= pb_csrf_field() ?><input type="hidden" name="do" value="demo_lock">
        <button class="pb-btn">Lock</button></form>
    <?php endif; ?>
  </div>
</div>
<?php elseif ($stab === 'engage'):
    $mode = (string) $s('mail_mode');
    $passSaved = (string) $s('smtp_pass') !== '' || (string) pb_config('smtp_pass') !== '';
    $mailLocked = pb_demo_locked(); ?>
<div class="pb-two-col">
  <form method="post" class="pb-card">
    <?= pb_csrf_field() ?><input type="hidden" name="do" value="engage_save">
    <h3 class="pb-h3">Comments</h3>
    <label class="pb-check"><input type="checkbox" name="comments_enabled" value="1"<?= $s('comments_enabled') === '1' ? ' checked' : '' ?>> Allow comments on blog posts</label>
    <p class="pb-small pb-muted pb-hint">Each post can also switch them off (editor → Post details). Pages never have comments.</p>
    <label>Visitors' comments<select name="comments_moderation">
      <option value="public"<?= $s('comments_moderation') !== 'off' ? ' selected' : '' ?>>Wait for approval (recommended)</option>
      <option value="off"<?= $s('comments_moderation') === 'off' ? ' selected' : '' ?>>Appear straight away</option>
    </select><span class="pb-small pb-muted">Signed-in writers' comments always appear straight away. Editors and Admins approve visitors' comments; Authors approve those on their own posts.</span></label>
    <label>Close comments on posts older than<span class="pb-row"><input type="number" name="comments_close_days" min="0" max="3650" value="<?= (int) $s('comments_close_days') ?>" style="max-width:110px"> <span class="pb-small pb-muted">days (0 = never)</span></span></label>
    <label class="pb-check"><input type="checkbox" name="comments_notify" value="1"<?= $s('comments_notify') === '1' ? ' checked' : '' ?>> Email moderators when a comment is waiting <span class="pb-small pb-muted">(at most once an hour each)</span></label>
    <h3 class="pb-h3">Likes &amp; sharing</h3>
    <label class="pb-check"><input type="checkbox" name="likes_enabled" value="1"<?= $s('likes_enabled') === '1' ? ' checked' : '' ?>> ❤️ Like button on posts <span class="pb-small pb-muted">(no sign-in, one per browser)</span></label>
    <label class="pb-check"><input type="checkbox" name="share_enabled" value="1"<?= $s('share_enabled') === '1' ? ' checked' : '' ?>> Share button <span class="pb-small pb-muted">(phone share sheet; WhatsApp, Facebook, X, LinkedIn, Telegram, email, copy link)</span></label>
    <button class="pb-btn pb-btn-primary">Save</button>
  </form>
  <form method="post" class="pb-card" id="mail">
    <?= pb_csrf_field() ?><input type="hidden" name="do" value="mail_save">
    <h3 class="pb-h3">Sending email</h3>
    <p class="pb-small pb-muted">Used to tell visitors their comment is live or got a reply, and moderators that comments are waiting. Off: visitors aren't offered email updates.</p>
    <?php if ($mailLocked): ?><div class="pb-note pb-note-info pb-small">Read-only in the demo.</div><?php endif; ?>
    <?php if ($s('mail_last_error') !== ''): ?><div class="pb-note pb-note-error pb-small">Last problem: <?= pb_e($s('mail_last_error')) ?></div><?php endif; ?>
    <label>Send with<select name="mail_mode" id="pbMailMode">
      <option value="off"<?= $mode === 'off' ? ' selected' : '' ?>>Off</option>
      <option value="smtp"<?= $mode === 'smtp' ? ' selected' : '' ?>>SMTP: a real mailbox (recommended)</option>
      <option value="php"<?= $mode === 'php' ? ' selected' : '' ?>>PHP mail() (simple, but often lands in spam)</option>
    </select></label>
    <div class="pb-row">
      <label>SMTP server<input name="smtp_host" value="<?= pb_e($s('smtp_host')) ?>" placeholder="smtp.hostinger.com" autocapitalize="none" spellcheck="false"></label>
      <label style="max-width:110px">Port<input type="number" name="smtp_port" value="<?= pb_e($s('smtp_port')) ?>"></label>
      <label style="max-width:150px">Security<select name="smtp_secure">
        <?php foreach (['ssl' => 'SSL (465)', 'tls' => 'STARTTLS (587)', 'none' => 'None'] as $k => $l): ?><option value="<?= $k ?>"<?= $s('smtp_secure') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
      </select></label>
    </div>
    <label>Mailbox (username)<input name="smtp_user" value="<?= pb_e($s('smtp_user')) ?>" placeholder="noreply@yoursite.com" autocomplete="off" autocapitalize="none" spellcheck="false"></label>
    <label>Mailbox password<input type="password" name="smtp_pass" value="" placeholder="<?= $passSaved ? '•••••••• saved (leave blank to keep)' : '' ?>" autocomplete="new-password"></label>
    <?php if ($passSaved && (string) pb_config('smtp_pass') === ''): ?><label class="pb-check pb-small"><input type="checkbox" name="smtp_pass_clear" value="1"> Forget the saved password</label><?php endif; ?>
    <?php if ((string) pb_config('smtp_pass') !== ''): ?><p class="pb-small pb-muted pb-hint">The password comes from <code>config.php</code>.</p><?php endif; ?>
    <div class="pb-row">
      <label>From address<input name="mail_from" value="<?= pb_e($s('mail_from')) ?>" placeholder="<?= pb_e(pb_mail_from()) ?>" autocapitalize="none" spellcheck="false"></label>
      <label>From name<input name="mail_from_name" value="<?= pb_e($s('mail_from_name')) ?>" placeholder="<?= pb_e($s('blog_title')) ?>"></label>
    </div>
    <p class="pb-small pb-muted pb-hint">Hostinger: create the mailbox in hPanel → Emails, then use smtp.hostinger.com, SSL, port 465, the full address as username and its password. The From address should be that same mailbox.</p>
    <div class="pb-row">
      <button class="pb-btn pb-btn-primary" <?= $mailLocked ? 'disabled' : '' ?>>Save email settings</button>
      <button class="pb-btn" name="do" value="mail_test" <?= $mailLocked ? 'disabled' : '' ?> title="Sends to <?= pb_e($user['email']) ?>">Send a test to me</button>
    </div>
  </form>
</div>
<?php elseif ($stab === 'code'): ?>
<div class="pb-two-col">
  <form method="post" class="pb-card">
    <?= pb_csrf_field() ?><input type="hidden" name="do" value="code_save">
    <?php if (pb_demo_locked()): ?><div class="pb-note pb-note-info pb-small">This tab is read-only in the demo: code here runs for every visitor. On your own blog, Admins can edit it.</div><?php endif; ?>
    <label>Header code <span class="pb-small pb-muted">— added inside <code>&lt;head&gt;</code> on every public page</span>
      <textarea name="code_head" rows="9" class="pb-code" spellcheck="false" placeholder="<meta name=&quot;google-site-verification&quot; content=&quot;…&quot;>"><?= pb_e($s('code_head')) ?></textarea></label>
    <label>Footer code <span class="pb-small pb-muted">— added just before <code>&lt;/body&gt;</code> on every public page</span>
      <textarea name="code_footer" rows="9" class="pb-code" spellcheck="false" placeholder="<script>…analytics or chat widget…</script>"><?= pb_e($s('code_footer')) ?></textarea></label>
    <button class="pb-btn pb-btn-primary">Save code</button>
  </form>
  <div class="pb-card">
    <h3 class="pb-h3">What goes here</h3>
    <p class="pb-small"><strong>Header:</strong> site-verification tags (Google Search Console, Bing, Pinterest, Facebook), Google Analytics / Tag Manager, Microsoft Clarity, fonts or pixels that must load early.</p>
    <p class="pb-small"><strong>Footer:</strong> chat widgets, heatmaps and scripts that can load after the page.</p>
    <p class="pb-small pb-muted">Code is printed exactly as entered, so only paste code from services you trust. Only Admins can see or change this page. It never runs in the admin area, so a broken snippet can't lock you out.</p>
    <?php if ($isGr): ?><div class="pb-note pb-note-info pb-small">This is a GeoRank site: blog pages already include the code from <strong>GeoRank → Site Settings</strong> (meta-global). Add a snippet here only if it should run <em>on the blog alone</em>, or it will load twice.</div><?php endif; ?>
  </div>
</div>
<?php elseif ($stab === 'addons'):
    $addons = pb_addons();
    $activeTheme = pb_active_theme();
    $activePlugins = pb_active_plugin_slugs();
    $themes = array_filter($addons, function ($a) { return ($a['type'] ?? '') === 'theme'; });
    $plugins = array_filter($addons, function ($a) { return ($a['type'] ?? '') === 'plugin'; });
    $broken = array_filter($addons, function ($a) { return !in_array($a['type'] ?? '', ['theme', 'plugin'], true); });
    $withSettings = array_filter($addons, function ($a) use ($activeTheme, $activePlugins) {
        return $a['settings'] && empty($a['error']) && (($activeTheme && $activeTheme['slug'] === $a['slug']) || in_array($a['slug'], $activePlugins, true));
    });
    $card = function ($a, $active, $actions) { ?>
      <div class="pb-addon<?= $active ? ' is-active' : '' ?><?= !empty($a['error']) ? ' is-broken' : '' ?>">
        <div class="pb-addon-head"><strong><?= pb_e($a['name']) ?></strong>
          <?php if (!empty($a['version'])): ?><span class="pb-small pb-muted">v<?= pb_e($a['version']) ?></span><?php endif; ?>
          <?php if ($active): ?><span class="pb-badge pb-badge-published">Active</span><?php endif; ?></div>
        <?php if (!empty($a['description'])): ?><p class="pb-small"><?= pb_e($a['description']) ?></p><?php endif; ?>
        <?php if (!empty($a['author'])): ?><p class="pb-small pb-muted">By <?= !empty($a['homepage']) && pb_safe_url($a['homepage']) ? '<a href="' . pb_e($a['homepage']) . '" target="_blank" rel="noopener">' . pb_e($a['author']) . '</a>' : pb_e($a['author']) ?></p><?php endif; ?>
        <?php if (!empty($a['error'])): ?><p class="pb-small pb-danger-text">⚠ <?= pb_e($a['error']) ?></p><?php endif; ?>
        <div class="pb-row"><?= $actions ?></div>
      </div>
    <?php };
    $btn = function ($do, $slug, $label, $primary = false) {
        return '<form method="post" class="pb-inline">' . pb_csrf_field() . '<input type="hidden" name="do" value="' . pb_e($do) . '"><input type="hidden" name="slug" value="' . pb_e($slug) . '">'
             . '<button class="pb-btn pb-btn-sm' . ($primary ? ' pb-btn-primary' : '') . '">' . pb_e($label) . '</button></form>';
    }; ?>
<?php foreach ($GLOBALS['pb_addon_errors'] as $err): ?><div class="pb-flash pb-flash-error">Addon error (skipped safely): <?= pb_e($err) ?></div><?php endforeach; ?>
<?php if (pb_layout_mode() === 'georank'): ?>
  <div class="pb-note pb-note-info">This blog currently uses the <strong>GeoRank site design</strong>. Themes apply when Settings → General → Layout is set to <em>JotKite theme</em>. Plugins always apply.</div>
<?php endif; ?>
<div class="pb-card">
  <h3 class="pb-h3">Themes</h3>
  <div class="pb-addon-grid">
    <?php $card(['name' => 'Default', 'version' => PB_VERSION, 'description' => 'The built-in JotKite layout.', 'author' => 'Unnati Digi Services', 'homepage' => PB_HOMEPAGE],
        !$activeTheme, $activeTheme ? $btn('addon_activate', '', 'Activate', true) : ''); ?>
    <?php foreach ($themes as $a): $on = $activeTheme && $activeTheme['slug'] === $a['slug'];
        $card($a, $on, $on || !empty($a['error']) ? ($on && $a['settings'] ? '<a class="pb-btn pb-btn-sm" href="#addon-' . pb_e($a['slug']) . '">Settings</a>' : '') : $btn('addon_activate', $a['slug'], 'Activate', true));
    endforeach; ?>
  </div>
</div>
<div class="pb-card">
  <h3 class="pb-h3">Plugins</h3>
  <?php if (!$plugins): ?><p class="pb-small pb-muted">No plugins installed yet.</p><?php endif; ?>
  <div class="pb-addon-grid">
    <?php foreach ($plugins as $a): $on = in_array($a['slug'], $activePlugins, true);
        $card($a, $on, $on ? $btn('addon_deactivate', $a['slug'], 'Deactivate') . ($a['settings'] ? ' <a class="pb-btn pb-btn-sm" href="#addon-' . pb_e($a['slug']) . '">Settings</a>' : '')
                           : (empty($a['error']) ? $btn('addon_activate', $a['slug'], 'Activate', true) : ''));
    endforeach; ?>
  </div>
</div>
<?php foreach ($withSettings as $a): $vals = pb_addon_settings($a['slug']); ?>
<form method="post" class="pb-card pb-addon-settings" id="addon-<?= pb_e($a['slug']) ?>">
  <?= pb_csrf_field() ?><input type="hidden" name="do" value="addon_settings_save"><input type="hidden" name="slug" value="<?= pb_e($a['slug']) ?>">
  <h3 class="pb-h3"><?= pb_e($a['name']) ?> settings</h3>
  <div class="pb-addon-fields">
  <?php foreach ($a['settings'] as $f): echo pb_addon_field_html($f, $vals[$f['key']] ?? ''); endforeach; ?>
  </div>
  <button class="pb-btn pb-btn-primary">Save <?= pb_e($a['name']) ?> settings</button>
</form>
<?php endforeach; ?>
<?php if ($broken): ?>
<div class="pb-card"><h3 class="pb-h3">Folders that aren't valid addons</h3>
  <?php foreach ($broken as $a): $card($a, false, ''); endforeach; ?></div>
<?php endif; ?>
<div class="pb-card">
  <h3 class="pb-h3">Add or build an addon</h3>
  <p class="pb-small">Upload an addon folder into <code>addons/</code> (by FTP, File Manager or Git), then activate it here. For safety, addons can't be uploaded through this page — they run as code on your site, so only install addons you trust.</p>
  <p class="pb-small">Developers: themes and plugins are a folder with an <code>addon.json</code> file. See the <a href="<?= PB_REPO_URL ?>/blob/main/docs/ADDONS.md" target="_blank" rel="noopener">addon guide</a> and the M1 theme in <code>addons/m1/</code> as a reference.</p>
</div>
<?php elseif ($stab === 'navigation'):
    $navItems = pb_nav_items();
    $cats = pb_all('SELECT name, slug FROM categories ORDER BY sort, name');
    $quick = ['Site home' => pb_site_base_path() . '/', 'Blog' => PB_BASE_PATH . '/', 'Contact' => pb_site_base_path() . '/contact.html', 'About' => pb_site_base_path() . '/about.html', 'RSS feed' => pb_url('feed')];
    foreach ($cats as $c) $quick['Category: ' . $c['name']] = pb_url('category', $c['slug']);
    if (PB_BASE_PATH === '') unset($quick['Site home'], $quick['Contact'], $quick['About']); // blog is the site: use Pages instead
    foreach (pb_all("SELECT title, slug FROM posts WHERE type = 'page' AND status = 'published' ORDER BY title") as $pg) {
        $quick['Page: ' . $pg['title']] = pb_url('post', $pg['slug']);
    } ?>
<div class="pb-two-col">
  <form method="post" class="pb-card" id="pbNavForm">
    <?= pb_csrf_field() ?><input type="hidden" name="do" value="nav_save">
    <table class="pb-table pb-nav-table">
      <thead><tr><th>Label</th><th>Link</th><th title="Open in a new tab">New tab</th><th></th></tr></thead>
      <tbody id="pbNavRows">
      <?php foreach ($navItems as $it): ?>
        <tr data-nav-row>
          <td><input data-k="label" value="<?= pb_e($it['label']) ?>" maxlength="40" aria-label="Label"></td>
          <td><input data-k="url" value="<?= pb_e($it['url']) ?>" aria-label="Link"></td>
          <td class="pb-center"><input type="checkbox" data-k="new_tab" value="1"<?= !empty($it['new_tab']) ? ' checked' : '' ?> aria-label="Open in new tab"></td>
          <td class="pb-right"><button type="button" class="pb-btn pb-btn-sm" data-nav-move="-1" title="Move up">↑</button><button type="button" class="pb-btn pb-btn-sm" data-nav-move="1" title="Move down">↓</button><button type="button" class="pb-btn pb-btn-sm pb-btn-danger" data-nav-remove title="Remove">✕</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="pb-row pb-nav-add">
      <button type="button" class="pb-btn pb-btn-sm" id="pbNavAdd">+ Add link</button>
      <select id="pbNavQuick" aria-label="Quick add">
        <option value="">Quick add…</option>
        <?php foreach ($quick as $label => $url): ?><option value="<?= pb_e($url) ?>" data-label="<?= pb_e(preg_replace('/^Category: /', '', $label)) ?>"><?= pb_e($label) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php if ($showGr): ?>
    <label class="pb-check"><input type="checkbox" name="nav_show_georank" value="1"<?= $s('nav_show_georank') === '1' ? ' checked' : '' ?>> Also show this menu on the GeoRank site design, as a slim bar under the site header</label>
    <?php elseif ($s('nav_show_georank') === '1'): ?><input type="hidden" name="nav_show_georank" value="1"><?php endif; ?>
    <p class="pb-small pb-muted"><?= $isGr ? 'On this GeoRank site the main menu comes from GeoRank → Design → Menu. This list is used for the blog\'s own bar (if ticked) and the standalone layout.' : 'Shown in the blog header.' ?></p>
    <div class="pb-row">
      <button class="pb-btn pb-btn-primary">Save navigation</button>
      <button class="pb-btn" name="reset" value="1" data-confirm="Replace the menu with the defaults (Home, Blog, Contact)?">Reset to defaults</button>
    </div>
    <template id="pbNavTpl"><tr data-nav-row>
      <td><input data-k="label" maxlength="40" aria-label="Label"></td>
      <td><input data-k="url" placeholder="/page.html or https://…" aria-label="Link"></td>
      <td class="pb-center"><input type="checkbox" data-k="new_tab" value="1" aria-label="Open in new tab"></td>
      <td class="pb-right"><button type="button" class="pb-btn pb-btn-sm" data-nav-move="-1" title="Move up">↑</button><button type="button" class="pb-btn pb-btn-sm" data-nav-move="1" title="Move down">↓</button><button type="button" class="pb-btn pb-btn-sm pb-btn-danger" data-nav-remove title="Remove">✕</button></td>
    </tr></template>
  </form>
  <div class="pb-card">
    <h3 class="pb-h3">Tips</h3>
    <p class="pb-small">Links can be site pages (<code>/contact.html</code>), blog pages (<code><?= pb_e(PB_BASE_PATH) ?>/category/…/</code>) or other websites (<code>https://…</code>).</p>
    <p class="pb-small">Up to 12 links. Empty rows are ignored when you save.</p>
  </div>
</div>
<?php else:
    $sqliteVer = (string) pb_db()->query('SELECT sqlite_version()')->fetchColumn(); ?>
<div class="pb-two-col">
  <form method="post" class="pb-card">
    <?= pb_csrf_field() ?><input type="hidden" name="do" value="settings_save">
    <h3 class="pb-h3">Blog</h3>
    <label>Blog title<input name="blog_title" value="<?= pb_e($s('blog_title')) ?>"></label>
    <label>Description<textarea name="blog_description" rows="2"><?= pb_e($s('blog_description')) ?></textarea></label>
    <div class="pb-row">
      <label>Posts per page<input type="number" min="1" max="48" name="posts_per_page" value="<?= pb_e($s('posts_per_page')) ?>"></label>
      <label>Language<input name="language" value="<?= pb_e($s('language')) ?>" maxlength="10"></label>
    </div>
    <label>Timezone<input name="timezone" value="<?= pb_e($s('timezone')) ?>" list="pbTz"></label>
    <datalist id="pbTz"><?php foreach (['Asia/Kolkata', 'UTC', 'Asia/Dubai', 'Asia/Singapore', 'Europe/London', 'America/New_York', 'America/Los_Angeles', 'Australia/Sydney'] as $tz): ?><option value="<?= $tz ?>"><?php endforeach; ?></datalist>
    <label class="pb-check"><input type="checkbox" name="show_author" value="1"<?= $s('show_author') === '1' ? ' checked' : '' ?>> Show author name on posts</label>
    <?php $pubPages = pb_all("SELECT id, title FROM posts WHERE type = 'page' AND status = 'published' AND published_at <= ? ORDER BY title", [pb_now()]); ?>
    <label>Homepage shows<select name="front_page">
      <option value="">Latest posts</option>
      <?php foreach ($pubPages as $pg): ?><option value="<?= (int) $pg['id'] ?>"<?= (string) $s('front_page') === (string) $pg['id'] ? ' selected' : '' ?>>Page: <?= pb_e($pg['title']) ?></option><?php endforeach; ?>
    </select>
    <span class="pb-small pb-muted"><?= $pubPages ? 'With a page as the homepage, the post list moves to <code>' . pb_e(pb_url('posts') === pb_url('home') ? PB_BASE_PATH . '/posts/' : pb_url('posts')) . '</code> and the page\'s own address redirects to the homepage.' : 'Publish a Page (Pages → New page) to use it as the homepage.' ?></span></label>

    <h3 class="pb-h3">Photos</h3>
    <label>Photo details (location, camera, date taken)<select name="photo_metadata">
      <option value="keep"<?= $s('photo_metadata') !== 'strip' ? ' selected' : '' ?>>Keep them in the photo (recommended)</option>
      <option value="strip"<?= $s('photo_metadata') === 'strip' ? ' selected' : '' ?>>Remove them when uploading</option>
    </select>
    <span class="pb-small pb-muted">Real photos with their location and date are a genuine signal for search engines, especially for local businesses. Remove them only if photos are taken somewhere private, such as your home. Applies to new uploads.</span></label>

    <h3 class="pb-h3"><?= $showGr ? 'Layout &amp; URLs' : 'URLs' ?></h3>
    <?php if ($showGr): ?>
    <label>Layout<select name="layout">
      <option value="auto"<?= $s('layout') === 'auto' ? ' selected' : '' ?>>Automatic (use the GeoRank site design when found)</option>
      <option value="georank"<?= $s('layout') === 'georank' ? ' selected' : '' ?>>GeoRank site header, footer and theme</option>
      <option value="standalone"<?= $s('layout') === 'standalone' ? ' selected' : '' ?>>JotKite theme (choose it in Settings → Addons)</option>
    </select></label>
    <?php else: /* not a GeoRank site: the theme is chosen in Settings → Addons; keep the stored value */ ?>
    <input type="hidden" name="layout" value="<?= pb_e($s('layout')) ?>">
    <?php endif; ?>
    <label class="pb-check"><input type="checkbox" name="pretty_urls" value="1"<?= $s('pretty_urls') === '1' ? ' checked' : '' ?>> Clean URLs (<code><?= pb_e(PB_BASE_PATH) ?>/my-post/</code>) — needs Apache mod_rewrite</label>
    <label>Site URL <span class="pb-muted pb-small">(optional, e.g. https://example.com — used for canonical links, RSS and sitemap)</span><input name="site_url" value="<?= pb_e($s('site_url')) ?>" placeholder="<?= pb_e(pb_origin()) ?>"></label>

    <?php if ($showGr): ?>
    <h3 class="pb-h3">GeoRank sign-in</h3>
    <label class="pb-check"><input type="checkbox" name="georank_sso" value="1"<?= $s('georank_sso') === '1' ? ' checked' : '' ?>> Let people signed in to GeoRank use the blog without a separate login</label>
    <label>GeoRank Editors become<select name="georank_editor_role">
      <?php foreach (PB_ROLES as $r): ?><option value="<?= $r ?>"<?= $s('georank_editor_role') === $r ? ' selected' : '' ?>><?= pb_role_label($r) ?></option><?php endforeach; ?>
    </select></label>
    <?php else: ?>
    <input type="hidden" name="georank_sso" value="<?= $s('georank_sso') === '1' ? '1' : '' ?>"><input type="hidden" name="georank_editor_role" value="<?= pb_e($s('georank_editor_role')) ?>">
    <?php endif; ?>
    <button class="pb-btn pb-btn-primary">Save settings</button>
  </form>
  <div>
    <div class="pb-card">
      <h3 class="pb-h3">Links</h3>
      <p class="pb-small">Blog: <a href="<?= pb_e(pb_url()) ?>" target="_blank"><?= pb_e(pb_abs_url(pb_url())) ?></a></p>
      <p class="pb-small">RSS: <a href="<?= pb_e(pb_url('feed')) ?>" target="_blank"><?= pb_e(pb_abs_url(pb_url('feed'))) ?></a></p>
      <p class="pb-small">Sitemap: <a href="<?= pb_e(pb_url('sitemap')) ?>" target="_blank"><?= pb_e(pb_abs_url(pb_url('sitemap'))) ?></a></p>
      <form method="post"><?= pb_csrf_field() ?><input type="hidden" name="do" value="robots_sitemap">
        <button class="pb-btn pb-btn-sm">Add blog sitemap to robots.txt</button></form>
      <p class="pb-small pb-muted">Tip: <?= $isGr ? 'add a "Blog" link pointing to <code>' . pb_e(PB_BASE_PATH) . '/</code> in GeoRank → Design → Menu, and submit' : (PB_BASE_PATH !== '' ? 'link to <code>' . pb_e(PB_BASE_PATH) . '/</code> from your website\'s menu, and submit' : 'submit') ?> the sitemap in Google Search Console.</p>
    </div>
    <div class="pb-card">
      <h3 class="pb-h3">System</h3>
      <p class="pb-small">JotKite <?= pb_e(PB_VERSION) ?> · PHP <?= pb_e(PHP_VERSION) ?> · SQLite <?= pb_e($sqliteVer) ?></p>
      <?php if ($isGr): ?><p class="pb-small">GeoRank site: <strong>detected</strong>, using its header, footer and theme</p><?php endif; ?>
      <p class="pb-small">Image resizing: <?= function_exists('imagecreatetruecolor') ? 'on (GD)' : 'off — GD extension missing' ?></p>
      <p class="pb-small pb-muted">Back up <code>data/postbase.sqlite</code> and the <code>uploads/</code> folder to back up the whole blog.</p>
      <p class="pb-small">Help, guides and support: <a href="<?= PB_HOMEPAGE ?>" target="_blank" rel="noopener">jotkite.com</a> · Report a bug: <a href="<?= PB_REPO_URL ?>/issues" target="_blank" rel="noopener">GitHub Issues</a></p>
    </div>
  </div>
</div>
<?php endif;

} elseif ($view === 'media') {
    $title = 'Media';
    $mq = trim((string) ($_GET['q'] ?? ''));
    $scope = pb_media_scope($user);
    $all = pb_media_list($mq, $scope);
    $per = 60;
    $pagesN = max(1, (int) ceil(count($all) / $per));
    $pageN = min(max(1, (int) ($_GET['pg'] ?? 1)), $pagesN);
    $items = array_slice($all, ($pageN - 1) * $per, $per);
    $usage = pb_media_usage(array_column($items, 'url'));
    $canDel = pb_can($user, 'media.delete') || $user['role'] === 'author'; // Authors: their own images (the only ones they see)
    $back = http_build_query(array_filter(['q' => $mq, 'pg' => $pageN > 1 ? $pageN : null])); ?>
<div class="pb-card pb-media-bar">
  <form method="get" class="pb-media-search" role="search">
    <input type="hidden" name="view" value="media">
    <input type="search" name="q" value="<?= pb_e($mq) ?>" placeholder="Search file names…" aria-label="Search images">
    <button class="pb-btn pb-btn-sm">Search</button>
  </form>
  <button type="button" class="pb-btn pb-btn-primary pb-btn-sm" id="pbMediaUploadBtn">⬆ Upload images</button>
  <input type="file" id="pbMediaFiles" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>
  <span class="pb-small pb-muted"><?= $scope !== null ? 'Your images: ' : '' ?><?= count($all) ?> image<?= count($all) === 1 ? '' : 's' ?><?= $mq !== '' ? ' matching “' . pb_e($mq) . '”' : '' ?></span>
  <?php if ($canDel && $items): ?>
    <label class="pb-check pb-small pb-media-all"><input type="checkbox" id="pbMediaAll"> Select all</label>
    <button type="submit" form="pbMediaDelete" class="pb-btn pb-btn-sm pb-btn-danger" id="pbMediaDeleteBtn" disabled>Delete selected (<span>0</span>)</button>
  <?php endif; ?>
</div>
<?php if (!$items): ?>
  <div class="pb-card pb-empty-admin"><p><?= $mq !== '' ? 'No images match that search.' : 'No images yet. Upload some, or paste/insert images while writing a post.' ?></p></div>
<?php else: ?>
<form method="post" id="pbMediaDelete">
  <?= pb_csrf_field() ?><input type="hidden" name="do" value="media_delete"><input type="hidden" name="back" value="<?= pb_e($back) ?>">
  <div class="pb-media-grid" id="pbMedia">
  <?php foreach ($items as $i => $m): $used = $usage[$m['url']] ?? []; $photo = pb_photo_info(PB_UPLOAD_DIR . '/' . $m['rel']); ?>
    <figure class="pb-media-item" data-index="<?= $i ?>" data-url="<?= pb_e($m['url']) ?>" data-full="<?= pb_e(pb_abs_url($m['url'])) ?>"<?= $photo ? ' data-photo="' . pb_e(json_encode($photo)) . '"' : '' ?>
            data-name="<?= pb_e($m['name']) ?>" data-size="<?= pb_e(pb_human_size($m['size'])) ?>" data-date="<?= pb_e(pb_format_date(gmdate('Y-m-d H:i:s', $m['mtime']), 'j M Y, g:i a')) ?>"
            data-rel="<?= pb_e($m['rel']) ?>" data-used="<?= pb_e(json_encode($used)) ?>">
      <?php if ($canDel): ?><label class="pb-media-check" title="Select"><input type="checkbox" name="paths[]" value="<?= pb_e($m['rel']) ?>" data-used="<?= count($used) ?>"><span class="pb-sr">Select <?= pb_e($m['name']) ?></span></label><?php endif; ?>
      <button type="button" class="pb-media-thumb" aria-label="View <?= pb_e($m['name']) ?>"><img src="<?= pb_e($m['url']) ?>" alt="" loading="lazy" decoding="async"></button>
      <figcaption><span class="pb-media-name" title="<?= pb_e($m['rel']) ?>"><?= pb_e($m['name']) ?></span>
        <span class="pb-small pb-muted"><?= pb_e(pb_human_size($m['size'])) ?><?= $used ? ' · <span class="pb-media-inuse">in use</span>' : '' ?></span></figcaption>
    </figure>
  <?php endforeach; ?>
  </div>
</form>
<?php if ($pagesN > 1): ?>
  <nav class="pb-tabs pb-media-pager" aria-label="Pages">
    <?php for ($n = 1; $n <= $pagesN; $n++): ?><a href="<?= pb_e(pb_admin_url('view=media&pg=' . $n . ($mq !== '' ? '&q=' . rawurlencode($mq) : ''))) ?>" class="<?= $n === $pageN ? 'active' : '' ?>"><?= $n ?></a><?php endfor; ?>
  </nav>
<?php endif; ?>
<div class="pb-lightbox" id="pbLightbox" hidden role="dialog" aria-modal="true" aria-label="Image details">
  <div class="pb-lightbox-inner">
    <button type="button" class="pb-lightbox-close" data-lb="close" aria-label="Close">✕</button>
    <button type="button" class="pb-lightbox-nav pb-lightbox-prev" data-lb="prev" aria-label="Previous image">‹</button>
    <button type="button" class="pb-lightbox-nav pb-lightbox-next" data-lb="next" aria-label="Next image">›</button>
    <div class="pb-lightbox-img"><img alt="" id="pbLbImg"></div>
    <div class="pb-lightbox-info">
      <h3 class="pb-h3" id="pbLbName"></h3>
      <p class="pb-small pb-muted" id="pbLbMeta"></p>
      <p class="pb-small" id="pbLbPhoto" hidden></p>
      <label class="pb-small">Link (use in posts)<div class="pb-row pb-copy-row"><input id="pbLbUrl" readonly><button type="button" class="pb-btn pb-btn-sm pb-btn-primary" data-lb="copy">Copy link</button></div></label>
      <label class="pb-small">Full URL<div class="pb-row pb-copy-row"><input id="pbLbFull" readonly><button type="button" class="pb-btn pb-btn-sm" data-lb="copyfull">Copy</button></div></label>
      <div id="pbLbUsed" class="pb-small"></div>
      <?php if ($canDel): ?><button type="button" class="pb-btn pb-btn-sm pb-btn-danger" data-lb="delete">Delete image</button><?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>
<?php

} elseif (isset(pb_admin_pages()[$view]) && pb_can($user, pb_admin_pages()[$view]['perm'])) {
    // An addon's admin page (pb_add_admin_page). A failing addon shows an error, never a blank admin.
    $ap = pb_admin_pages()[$view];
    $title = $ap['label'];
    try { ($ap['render'])($user); } catch (Throwable $e) { pb_addon_error($e, 'admin page ' . $view); echo '<div class="pb-flash pb-flash-error">This addon page failed to load: ' . pb_e($e->getMessage()) . '</div>'; }

} elseif ($view === 'account') {
    $title = 'My account'; ?>
<form method="post" class="pb-card pb-narrow">
  <?= pb_csrf_field() ?><input type="hidden" name="do" value="account_save">
  <p><span class="pb-role pb-role-<?= pb_e($user['role']) ?>"><?= pb_e(pb_role_label($user['role'])) ?></span>
    <?= $user['source'] === 'georank' ? '<span class="pb-small pb-muted">Signed in through GeoRank</span>' : '<span class="pb-small pb-muted">' . pb_e($user['email']) . '</span>' ?></p>
  <label>Display name (shown on your posts)<input name="name" required maxlength="80" value="<?= pb_e($user['name']) ?>"></label>
  <label>Username <span class="pb-small pb-muted">(<?= $user['source'] === 'local' ? 'sign in with it or your email; ' : '' ?>your author page is <code><?= pb_e(pb_url('author', $user['username'] ?: 'username')) ?></code>)</span>
    <input name="username" value="<?= pb_e($user['username'] ?? '') ?>" autocapitalize="none" spellcheck="false" pattern="[A-Za-z0-9._-]{3,30}" maxlength="30"></label>

  <h3 class="pb-h3">Public profile</h3>
  <div class="pb-avatar-field"><?= pb_image_field('avatar', $user['avatar'] ?? '', 'Profile photo', 'Square works best. Shown round, next to your bio.') ?></div>
  <label>Short bio <span class="pb-muted pb-count" data-for="pbBio" data-max="250"></span>
    <textarea name="bio" id="pbBio" rows="3" maxlength="250" placeholder="One or two lines, shown under your posts."><?= pb_e($user['bio']) ?></textarea></label>
  <label>Long bio <span class="pb-muted pb-count" data-for="pbBioLong" data-max="1000"></span>
    <textarea name="bio_long" id="pbBioLong" rows="6" maxlength="1000" placeholder="Your story, for your author page. Links (https://…) become clickable."><?= pb_e($user['bio_long'] ?? '') ?></textarea></label>

  <h3 class="pb-h3">Privacy</h3>
  <label class="pb-check pb-small"><input type="checkbox" name="show_name" value="1"<?= (int) ($user['show_name'] ?? 1) === 1 ? ' checked' : '' ?>> Show my name on my posts</label>
  <label class="pb-check pb-small"><input type="checkbox" name="show_box" value="1"<?= (int) ($user['show_box'] ?? 1) === 1 ? ' checked' : '' ?>> Show my photo and short bio under my posts</label>
  <label class="pb-check pb-small"><input type="checkbox" name="public_page" value="1"<?= (int) ($user['public_page'] ?? 1) === 1 ? ' checked' : '' ?>> Public author page (long bio and my posts)<?= !empty($user['username']) && (int) ($user['public_page'] ?? 1) === 1 ? ' · <a href="' . pb_e(pb_url('author', $user['username'])) . '" target="_blank" rel="noopener">view ↗</a>' : '' ?></label>
  <p class="pb-small pb-muted">Your email is never shown publicly.</p>
  <?php $myCats = pb_all('SELECT id, name FROM categories ORDER BY sort, name'); if ($myCats): ?>
  <label>Default category for my new posts<select name="default_category_id">
    <option value="0">— None —</option>
    <?php foreach ($myCats as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) ($user['default_category_id'] ?? 0) === (int) $c['id'] ? ' selected' : '' ?>><?= pb_e($c['name']) ?></option><?php endforeach; ?>
  </select><span class="pb-small pb-muted">Preselected when you start a post; you can still change it on each post.</span></label>
  <?php endif; ?>
  <?php if ($user['source'] === 'local'): ?>
    <h3 class="pb-h3">Change password</h3>
    <label>Current password<input type="password" name="current_password" autocomplete="current-password"></label>
    <label>New password<input type="password" name="new_password" minlength="8" autocomplete="new-password"></label>
  <?php endif; ?>
  <button class="pb-btn pb-btn-primary">Save</button>
</form>
<div class="pb-card pb-narrow pb-app-card">
  <h3 class="pb-h3">📲 JotKite on your phone</h3>
  <p class="pb-small">Add the admin to your phone's home screen: it opens like an app, straight on <strong>Write</strong>, and stays signed in.</p>
  <p class="pb-small"><button type="button" class="pb-btn pb-btn-primary pb-btn-sm" data-install-btn hidden>Install on this device</button></p>
  <p class="pb-small"><strong>iPhone / iPad (Safari):</strong> open this page, tap <span class="pb-share-ico" aria-label="Share"></span> <b>Share</b>, then <b>Add to Home Screen</b>. Sign in once inside the new app.</p>
  <p class="pb-small"><strong>Android (Chrome):</strong> tap the <b>⋮</b> menu, then <b>Install app</b> or <b>Add to Home screen</b>. Samsung Internet: <b>≡</b> → <b>Add page to</b> → <b>Home screen</b>.</p>
</div>
<?php if ($user['source'] === 'local'):
    $devices = pb_all('SELECT id, selector, label, created_at, last_used_at FROM devices WHERE user_id = ? AND expires_at > ? ORDER BY last_used_at DESC', [$user['id'], time()]);
    $here = (string) ($_SESSION['pb_device'] ?? ''); ?>
<div class="pb-card pb-narrow">
  <h3 class="pb-h3">Signed-in devices</h3>
  <p class="pb-small pb-muted">Devices where you ticked “Keep me signed in”. They stay signed in for <?= PB_DEVICE_DAYS ?> days after their last visit. Lost a phone? Sign it out here. Changing your password signs out every other device.</p>
  <?php if (!$devices): ?>
    <p class="pb-small pb-muted">None yet.</p>
  <?php else: ?>
  <table class="pb-table pb-devices">
    <?php foreach ($devices as $d): ?>
    <tr><td><strong><?= pb_e($d['label'] ?: 'Device') ?></strong><?= $d['selector'] === $here ? ' <span class="pb-pin">This device</span>' : '' ?>
        <br><span class="pb-small pb-muted">Last used <?= pb_e(pb_format_date($d['last_used_at'], 'j M Y, g:i a')) ?> · added <?= pb_e(pb_format_date($d['created_at'], 'j M Y')) ?></span></td>
      <td class="pb-right"><form method="post" class="pb-inline"><?= pb_csrf_field() ?><input type="hidden" name="do" value="device_forget"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
        <button class="pb-btn pb-btn-sm"<?= $d['selector'] === $here ? ' data-confirm="Sign this device out? You will need your password next time."' : '' ?>>Sign out</button></form></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php endif;

} else {
    // ---- posts list --------------------------------------------------------
    $view = 'posts';
    $tab = (string) ($_GET['tab'] ?? ($isEditor && $pendingCount ? 'pending' : 'all'));
    $mine = !$isEditor || !empty($_GET['mine']);
    $ptype = $isEditor && ($_GET['type'] ?? '') === 'page' ? 'page' : 'post';
    $typeQs = $ptype === 'page' ? '&type=page' : '';
    $now = pb_now();
    $tabs = [
        'all' => ['All', "p.status != 'archived'"],
        'draft' => ['Drafts', "p.status = 'draft'"],
        'pending' => ['Pending review', "p.status = 'pending'"],
        'changes_requested' => ['Changes requested', "p.status = 'changes_requested'"],
        'scheduled' => ['Scheduled', "p.status = 'published' AND p.published_at > :now"],
        'published' => ['Published', "p.status = 'published' AND p.published_at <= :now"],
        'archived' => ['Archived', "p.status = 'archived'"],
    ];
    if (!isset($tabs[$tab])) $tab = 'all';
    $scope = ($mine ? ' AND p.author_id = ' . (int) $user['id'] : '') . " AND p.type = '" . $ptype . "'";
    $counts = [];
    foreach ($tabs as $k => [$label, $cond]) {
        $params = strpos($cond, ':now') !== false ? ['now' => $now] : [];
        $counts[$k] = (int) pb_val("SELECT COUNT(*) FROM posts p WHERE {$cond}{$scope}", $params);
    }
    $cond = $tabs[$tab][1];
    $params = strpos($cond, ':now') !== false ? ['now' => $now] : [];
    $rows = pb_all("SELECT p.id, p.title, p.slug, p.status, p.published_at, p.updated_at, p.submitted_at, p.pinned, u.name AS author_name, c.name AS category_name
                    FROM posts p JOIN users u ON u.id = p.author_id LEFT JOIN categories c ON c.id = p.category_id
                    WHERE {$cond}{$scope} ORDER BY " . ($tab === 'pending' ? 'p.submitted_at ASC' : 'p.updated_at DESC') . ' LIMIT 200', $params);
    $title = $ptype === 'page' ? 'Pages' : ($mine && !$isEditor ? 'My posts' : 'Posts'); ?>
<div class="pb-tabs">
  <?php foreach ($tabs as $k => [$label]): if ($k !== 'all' && $k !== $tab && !$counts[$k]) continue; ?>
    <a href="<?= pb_e(pb_admin_url('tab=' . $k . $typeQs . ($mine && $isEditor ? '&mine=1' : ''))) ?>" class="<?= $k === $tab ? 'active' : '' ?>"><?= pb_e($label) ?> <span><?= $counts[$k] ?></span></a>
  <?php endforeach; ?>
  <?php /* Right after the status tabs, where it stays visible on a phone (not off in the far corner). */ ?>
  <a class="pb-btn pb-btn-primary pb-btn-sm pb-tabs-new" href="<?= pb_e(pb_admin_url($ptype === 'page' ? 'view=edit&type=page' : 'view=edit')) ?>"><?= $ptype === 'page' ? '+ New page' : '✏️ Write new' ?></a>
  <?php if ($ptype !== 'page' && $isEditor): ?>
    <a class="pb-tabs-right" href="<?= pb_e(pb_admin_url('tab=' . $tab . ($mine ? '' : '&mine=1'))) ?>"><?= $mine ? 'Show everyone\'s posts' : 'Only my posts' ?></a>
  <?php endif; ?>
</div>
<div class="pb-card pb-flush">
<?php if ($rows): ?>
  <table class="pb-table pb-posts">
    <thead><tr><th>Title</th><th>Status</th><?php if (!$mine): ?><th>Author</th><?php endif; ?><th>Updated</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><a class="pb-post-link" href="<?= pb_e(pb_admin_url('view=edit&id=' . (int) $r['id'])) ?>"><?= pb_e($r['title']) ?></a><?= !empty($r['pinned']) ? ' <span class="pb-pin" title="Pinned as Featured on the blog home">📌 Featured</span>' : '' ?>
          <?php if ($ptype === 'page'): ?><div class="pb-small pb-muted"><?= pb_e(pb_url('post', $r['slug'])) ?></div><?php endif; ?>
          <?php if ($r['category_name']): ?><div class="pb-small pb-muted"><?= pb_e($r['category_name']) ?></div><?php endif; ?></td>
        <td><?= pb_status_badge($r['status'], $r['published_at']) ?>
          <?php if ($r['status'] === 'published' && $r['published_at'] > $now): ?><div class="pb-small pb-muted"><?= pb_e(pb_format_date($r['published_at'], 'j M, g:i a')) ?></div><?php endif; ?></td>
        <?php if (!$mine): ?><td class="pb-small"><?= pb_e($r['author_name']) ?></td><?php endif; ?>
        <td class="pb-small pb-muted"><?= pb_e(pb_format_date($r['updated_at'], 'j M Y')) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php else: ?>
  <div class="pb-empty-admin">
    <p><?= $tab === 'pending' ? 'Nothing waiting for review. 🎉' : ($ptype === 'page' ? 'No pages yet. Pages are for About, Contact, Support and similar — they don\'t appear in the blog list.' : 'No posts here yet.') ?></p>
    <a class="pb-btn pb-btn-primary" href="<?= pb_e(pb_admin_url($ptype === 'page' ? 'view=edit&type=page' : 'view=edit')) ?>"><?= $ptype === 'page' ? '+ New page' : '✍️ Write a post' ?></a>
  </div>
<?php endif; ?>
</div>
<?php
}
$content = ob_get_clean();

// ============================================================================
// ADMIN SHELL
// ============================================================================
$flashes = $_SESSION['pb_flash'] ?? [];
unset($_SESSION['pb_flash']);
$georankUrl = pb_georank_session_role() !== null ? pb_georank_admin_url() : null;
$nav = [
    ['edit', 'Write', '✏️', true],
    ['posts', $isEditor ? 'Posts' : 'My posts', '📄', true],
    ['pages', 'Pages', '📑', $isEditor],
    ['comments', 'Comments', '💬', pb_can_moderate($user) && (pb_comments_enabled() || (int) pb_val('SELECT COUNT(*) FROM comments') > 0)],
    ['media', 'Media', '🖼️', true],
    ['categories', 'Categories', '🏷️', $isEditor],
    ['users', 'Users', '👥', pb_can($user, 'user.manage')],
    ['settings', 'Settings', '⚙️', pb_can($user, 'settings.manage')],
    ['account', 'My account', '👤', true],
];
// Addon admin pages (pb_add_admin_page) go just before My account.
foreach (pb_admin_pages() as $apKey => $ap) array_splice($nav, count($nav) - 1, 0, [[$apKey, $ap['label'], $ap['icon'], pb_can($user, $ap['perm'])]]);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<?= pb_app_head() ?>
<title><?= pb_e($title) ?> · <?= pb_e(pb_setting('blog_title')) ?> · JotKite</title>
<link rel="icon" href="<?= pb_e(PB_BASE_PATH) ?>/assets/jk-favicon.svg" type="image/svg+xml">
<link rel="icon" href="<?= pb_e(PB_BASE_PATH) ?>/assets/favicon.png" type="image/png">
<link rel="apple-touch-icon" href="<?= pb_e(PB_BASE_PATH) ?>/assets/apple-touch-icon.png">
<link rel="stylesheet" href="<?= pb_e(PB_BASE_PATH) ?>/assets/admin.css?v=<?= pb_e(PB_VERSION) ?>">
</head>
<body class="pb-admin">
<div class="pb-shell">
  <aside class="pb-sidebar">
    <?php $siteName = (string) pb_setting('blog_title'); ?>
    <a class="pb-logo" href="<?= pb_e(pb_admin_url()) ?>" title="<?= pb_e($siteName) ?>"><img src="<?= pb_e(PB_BASE_PATH) ?>/assets/jk-icon.svg" alt="" width="36" height="36"><span class="pb-site-name"><?= pb_e($siteName) ?></span></a>
    <nav>
      <?php foreach ($nav as [$key, $label, $icon, $show]): if (!$show) continue;
        $onPages = ($_GET['type'] ?? '') === 'page';
        $active = $key === 'pages' ? ($view === 'posts' && $onPages)
                : ($view === $key && !($key === 'edit' && (!empty($_GET['id']) || $onPages)) && !($key === 'posts' && $onPages)); ?>
        <a href="<?= pb_e(pb_admin_url($key === 'pages' ? 'view=posts&type=page' : 'view=' . $key)) ?>" class="<?= $active ? 'active' : '' ?>"><span aria-hidden="true"><?= $icon ?></span> <?= pb_e($label) ?>
          <?php if ($key === 'posts' && ($pendingCount || $myChanges)): ?><em class="pb-count-badge" title="<?= $isEditor ? 'Waiting for your review' : 'Changes requested' ?>"><?= $isEditor ? $pendingCount : $myChanges ?></em><?php endif; ?>
          <?php if ($key === 'comments' && $commentsWaiting): ?><em class="pb-count-badge" title="Comments waiting for approval"><?= $commentsWaiting ?></em><?php endif; ?></a>
      <?php endforeach; ?>
      <a href="<?= pb_e(pb_url()) ?>" target="_blank" rel="noopener" class="pb-nav-blog">View blog ↗</a>
    </nav>
    <a class="pb-powered" href="<?= PB_HOMEPAGE ?>" target="_blank" rel="noopener">Powered by <span class="pb-wordmark">Jot<b>Kite</b></span></a>
    <?php if ($georankUrl): ?><div class="pb-sidebar-foot"><a href="<?= pb_e($georankUrl) ?>">← GeoRank dashboard</a></div><?php endif; ?>
  </aside>
  <div class="pb-main">
    <header class="pb-topbar">
      <h1><?= pb_e($title) ?></h1>
      <div class="pb-user">
        <span><?= pb_e($user['name']) ?> <span class="pb-role pb-role-<?= pb_e($user['role']) ?>"><?= pb_e(pb_role_label($user['role'])) ?></span></span>
        <?php if (!empty($_SESSION['pb_uid'])): ?><a href="<?= pb_e(pb_admin_url('logout=' . pb_csrf_token())) ?>">Log out</a><?php endif; ?>
      </div>
    </header>
    <?php if (pb_demo_on()): ?>
    <div class="pb-demo-bar" role="note">🧪 <strong>Demo</strong> · you're signed in as <?= pb_e(pb_role_label($user['role'])) ?>. Try anything: it all resets in <?= pb_demo_minutes_left() ?> min.
      <?php if (!empty($_SESSION['pb_uid'])): ?><a href="<?= pb_e(pb_admin_url('logout=' . pb_csrf_token())) ?>">Switch role</a><?php endif; ?></div>
    <?php endif; ?>
    <?php /* Install on phone (admin.js shows it on phones not yet using the app, until dismissed) */ ?>
    <div class="pb-install" id="pbInstall" hidden role="note">
      <img src="<?= pb_e(PB_BASE_PATH) ?>/assets/jk-icon.svg" alt="" width="40" height="40">
      <div><strong>📲 Put JotKite on your home screen</strong>
        <span class="pb-small" data-install-how>Open your browser's menu (⋮) and choose <b>Install app</b> or <b>Add to Home screen</b>.</span></div>
      <div class="pb-install-btns">
        <button type="button" class="pb-btn pb-btn-primary pb-btn-sm" data-install-btn hidden>Install</button>
        <button type="button" class="pb-btn pb-btn-sm" id="pbInstallClose">Not now</button>
      </div>
    </div>
    <?php if (pb_can($user, 'settings.manage') && pb_setting('rename_notice') === '1'): ?>
    <div class="pb-upgrade pb-rename" role="status">
      <div class="pb-upgrade-head">
        <img src="<?= pb_e(PB_BASE_PATH) ?>/assets/jk-icon.svg" alt="" width="40" height="40">
        <div><strong>PostBase is now JotKite</strong>
          <span class="pb-small">Same software, same posts and settings; nothing to do. The new name avoids confusion with other products called PostBase.
            New home: <a href="<?= PB_HOMEPAGE ?>" target="_blank" rel="noopener">jotkite.com</a> · code: <a href="<?= PB_REPO_URL ?>" target="_blank" rel="noopener">GitHub</a></span></div>
        <form method="post" class="pb-upgrade-dismiss"><?= pb_csrf_field() ?><input type="hidden" name="do" value="rename_dismiss"><input type="hidden" name="back" value="<?= pb_e($view) ?>">
          <button class="pb-btn pb-btn-sm">Got it</button></form>
      </div>
    </div>
    <?php endif; ?>
    <?php if (pb_can($user, 'settings.manage') && ($up = pb_upgrade_notice())): $notes = pb_changelog_between((string) $up['from'], (string) $up['to']); ?>
    <div class="pb-upgrade" role="status">
      <div class="pb-upgrade-head">
        <span class="pb-upgrade-icon" aria-hidden="true">⬆️</span>
        <div><strong>JotKite was upgraded automatically to version <?= pb_e($up['to']) ?></strong>
          <span class="pb-small pb-muted"><?= $up['from'] !== '' ? 'from ' . pb_e($up['from']) . ' · ' : '' ?><?= pb_e(pb_format_date($up['at'], 'j M Y, g:i a')) ?></span></div>
        <form method="post" class="pb-upgrade-dismiss"><?= pb_csrf_field() ?><input type="hidden" name="do" value="upgrade_dismiss"><input type="hidden" name="back" value="<?= pb_e($view) ?>">
          <button class="pb-btn pb-btn-sm">Dismiss</button></form>
      </div>
      <?php if ($notes): ?>
      <details class="pb-upgrade-notes"><summary>What's new</summary><div class="pb-changelog"><?= pb_md_lite(implode("\n\n", $notes)) ?></div>
        <p class="pb-small"><a href="<?= PB_REPO_URL ?>/blob/main/CHANGELOG.md" target="_blank" rel="noopener">Full changelog on GitHub ↗</a></p></details>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php foreach ($flashes as [$type, $msg]): ?>
      <div class="pb-flash pb-flash-<?= pb_e($type) ?>" role="status"><?= pb_e($msg) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
    <footer class="pb-admin-foot"><span class="pb-mobile-only"><?php if ($georankUrl): ?><a href="<?= pb_e($georankUrl) ?>">GeoRank dashboard</a> · <?php endif; ?></span>JotKite <?= pb_e(PB_VERSION) ?> · <a href="<?= PB_HOMEPAGE ?>" target="_blank" rel="noopener">Help &amp; support</a> · <a href="<?= PB_REPO_URL ?>" target="_blank" rel="noopener">GitHub</a></footer>
  </div>
</div>
<script>window.PB = <?= json_encode(['csrf' => pb_csrf_token(), 'endpoint' => pb_admin_url(), 'maxMb' => pb_config('max_upload_mb'), 'maxPx' => (int) pb_config('max_image_px'), 'keepMeta' => pb_setting('photo_metadata') !== 'strip','adminUrl' => pb_admin_url()]) ?>;</script>
<script src="<?= pb_e(PB_BASE_PATH) ?>/assets/paste.js?v=<?= pb_e(PB_VERSION) ?>"></script>
<script src="<?= pb_e(PB_BASE_PATH) ?>/assets/admin.js?v=<?= pb_e(PB_VERSION) ?>"></script>
</body>
</html>
<?php

// ----------------------------------------------------------------------------
// Minimal page for setup/login (defined at the bottom; PHP hoists functions).
// ----------------------------------------------------------------------------
function pb_auth_page($heading, $error, callable $body) {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<?= pb_app_head() ?>
<title><?= pb_e($heading) ?> · JotKite</title>
<link rel="icon" href="<?= pb_e(PB_BASE_PATH) ?>/assets/jk-favicon.svg" type="image/svg+xml">
<link rel="icon" href="<?= pb_e(PB_BASE_PATH) ?>/assets/favicon.png" type="image/png">
<link rel="apple-touch-icon" href="<?= pb_e(PB_BASE_PATH) ?>/assets/apple-touch-icon.png">
<link rel="stylesheet" href="<?= pb_e(PB_BASE_PATH) ?>/assets/admin.css?v=<?= pb_e(PB_VERSION) ?>">
</head>
<body class="pb-admin pb-auth">
  <div class="pb-auth-box">
    <div class="pb-logo pb-logo-lg" role="img" aria-label="JotKite"><img src="<?= pb_e(PB_BASE_PATH) ?>/assets/jk-icon.svg" alt="" width="64" height="64"><span class="pb-wordmark" aria-hidden="true">Jot<b>Kite</b></span></div>
    <p class="pb-tagline">Write anywhere, post here.</p>
    <div class="pb-card">
      <h1 class="pb-h2"><?= pb_e($heading) ?></h1>
      <?php if ($error): ?><div class="pb-flash pb-flash-error"><?= pb_e($error) ?></div><?php endif; ?>
      <?php $body(); ?>
    </div>
  </div>
</body>
</html>
<?php
}
