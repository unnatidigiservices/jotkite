<?php
/**
 * JotKite — self-resetting demo mode · https://jotkite.com
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial
 *
 * Turned on in config.php only (never from the admin), for a public "try it"
 * site:
 *
 *   return ['demo' => true, 'demo_reset_minutes' => 60, 'demo_key' => 'a-long-secret'];
 *
 * - Visitors sign in with one click as Admin, Editor or Contributor.
 * - Every demo_reset_minutes, the database and uploads/ are restored from a
 *   snapshot in data/demo/. The first snapshot is made automatically, with
 *   sample content.
 * - Anything that could hurt other visitors or the server is locked: custom
 *   header/footer code, robots.txt, and the demo accounts' passwords/emails.
 * - The site owner types demo_key in Settings → Demo to unlock owner tools:
 *   edit the Code tab, save the current content as the new starting point,
 *   or reset now.
 */
if (!defined('PB_ROOT')) { http_response_code(403); exit; }

define('PB_DEMO_USERS', [
    'admin'       => ['demo-admin@postbase.demo', 'Demo Admin'],
    'editor'      => ['demo-editor@postbase.demo', 'Demo Editor'],
    'author'      => ['demo-author@postbase.demo', 'Demo Author'],
    'contributor' => ['demo-writer@postbase.demo', 'Demo Contributor'],
]);

function pb_demo_on() {
    return (bool) pb_config('demo');
}
function pb_demo_dir() {
    return dirname(pb_config('db_path')) . '/demo';
}
function pb_demo_minutes() {
    return max(5, (int) (pb_config('demo_reset_minutes') ?: 60));
}
function pb_demo_last_reset() {
    return (int) @file_get_contents(pb_demo_dir() . '/last-reset');
}
function pb_demo_minutes_left() {
    $last = pb_demo_last_reset();
    return $last ? max(1, (int) ceil(($last + pb_demo_minutes() * 60 - time()) / 60)) : pb_demo_minutes();
}
// Owner tools are unlocked for this session by typing demo_key.
function pb_demo_owner() {
    return !empty($_SESSION['pb_demo_owner']);
}
// True when a demo visitor may not do this (owner tools unlock it).
function pb_demo_locked() {
    return pb_demo_on() && !pb_demo_owner();
}
function pb_demo_is_demo_user($user) {
    foreach (PB_DEMO_USERS as [$email]) if ($user && strcasecmp($user['email'], $email) === 0) return true;
    return false;
}

// Restores the snapshot when it is time. Runs before the database is opened.
function pb_demo_maybe_reset($dbPath, $force = false) {
    $dir = pb_demo_dir();
    if (!is_file($dir . '/seed.sqlite')) return false;
    if (!$force && time() - pb_demo_last_reset() < pb_demo_minutes() * 60) return false;
    $lock = @fopen($dir . '/reset.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return false; // another request is resetting
    try {
        if (!$force && time() - pb_demo_last_reset() < pb_demo_minutes() * 60) return false;
        // Database: replace the file (the WAL/SHM side files belong to the old one).
        @copy($dir . '/seed.sqlite', $dbPath . '.restore');
        @unlink($dbPath . '-wal');
        @unlink($dbPath . '-shm');
        if (!@rename($dbPath . '.restore', $dbPath)) { @unlink($dbPath); @rename($dbPath . '.restore', $dbPath); }
        // Uploads: exactly the snapshot's files again (the .htaccess stays).
        pb_demo_clear_dir(PB_UPLOAD_DIR, true);
        pb_demo_copy_dir($dir . '/uploads', PB_UPLOAD_DIR);
        @file_put_contents($dir . '/last-reset', (string) time());
        return true;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// Saves the current database and uploads as the demo's starting point.
function pb_demo_snapshot() {
    $dir = pb_demo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $tmp = $dir . '/seed.new.sqlite';
    @unlink($tmp);
    try {
        pb_db()->exec('VACUUM INTO ' . pb_db()->quote($tmp)); // SQLite 3.27+: a clean, consistent copy
    } catch (Exception $e) {
        try { pb_db()->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (Exception $e2) { /* not in WAL mode */ }
        if (!@copy(pb_config('db_path'), $tmp)) return false;
    }
    if (!@rename($tmp, $dir . '/seed.sqlite')) { @unlink($dir . '/seed.sqlite'); @rename($tmp, $dir . '/seed.sqlite'); }
    pb_demo_clear_dir($dir . '/uploads', false);
    pb_demo_copy_dir(PB_UPLOAD_DIR, $dir . '/uploads');
    @file_put_contents($dir . '/last-reset', (string) time());
    return true;
}

function pb_demo_clear_dir($dir, $keepHtaccess) {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        if ($keepHtaccess && $f->getPath() === $dir && $f->getFilename() === '.htaccess') continue;
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
}
function pb_demo_copy_dir($from, $to) {
    if (!is_dir($from)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        if ($f->getFilename() === '.htaccess') continue; // the live folder's own protection stays in charge
        $dest = $to . '/' . substr($f->getPathname(), strlen($from) + 1);
        if ($f->isDir()) { if (!is_dir($dest)) @mkdir($dest, 0755, true); }
        else { if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0755, true); @copy($f->getPathname(), $dest); }
    }
}

// First run of a demo site: the three demo accounts, sample content, and the first snapshot.
function pb_demo_bootstrap() {
    static $done = false;
    if ($done || is_file(pb_demo_dir() . '/seed.sqlite')) return;
    $done = true;
    $ids = [];
    foreach (PB_DEMO_USERS as $role => [$email, $name]) {
        $u = pb_row('SELECT id FROM users WHERE email = ?', [$email]);
        if (!$u) {
            // Nobody knows this password: demo accounts are entered with the one-click buttons.
            pb_q('INSERT INTO users (email, name, password_hash, role, bio, created_at, username) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$email, $name, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $role, '', pb_now(), pb_username_unique($name)]);
            $u = ['id' => (int) pb_db()->lastInsertId()];
        }
        $ids[$role] = (int) $u['id'];
    }
    if (!(int) pb_val('SELECT COUNT(*) FROM posts')) pb_demo_sample_content($ids);
    pb_settings_save(['installed_version' => PB_VERSION]);
    pb_demo_snapshot();
}

function pb_demo_sample_content(array $ids) {
    if (!(string) pb_val("SELECT value FROM settings WHERE key = 'blog_title'")) {
        pb_settings_save(['blog_title' => 'JotKite Demo', 'blog_description' => 'Write anywhere, post here. Try the admin: everything resets every ' . pb_demo_minutes() . ' minutes.']);
    }
    pb_q("INSERT INTO categories (slug, name, description, sort) VALUES ('news', 'News', 'What is new in the shop', 1)");
    $cat = (int) pb_db()->lastInsertId();
    // A real image for the photo post: the JotKite logo, copied into uploads/.
    $img = '';
    $sub = gmdate('Y') . '/' . gmdate('m');
    if (is_dir(PB_UPLOAD_DIR . '/' . $sub) || @mkdir(PB_UPLOAD_DIR . '/' . $sub, 0755, true)) {
        if (@copy(PB_ROOT . '/assets/logo.png', PB_UPLOAD_DIR . '/' . $sub . '/demo-photo.png')) $img = PB_BASE_PATH . '/uploads/' . $sub . '/demo-photo.png';
    }
    $now = pb_now();
    $ago = function ($h) { return gmdate('Y-m-d H:i:s', time() - $h * 3600); };
    $posts = [
        ['welcome-to-the-demo', 'Welcome to the JotKite demo', 'published', $ids['admin'], $ago(2), 1, 'post',
         '<p>This is a live JotKite blog. <strong>Sign in to the admin</strong> with one click, as an Admin, Editor or Contributor, and try anything: write, paste from Word or Google Docs, upload photos from your phone, publish, or break things.</p>'
         . '<h2>Everything resets</h2><p>Every ' . pb_demo_minutes() . ' minutes the demo goes back to this starting point, so nothing you do here is permanent.</p>'
         . '<ul><li>Paste a Google Doc and watch the formatting survive.</li><li>Type <code>## </code> for a heading, or <code>- </code> for a list.</li><li>Open the admin on your phone and add it to your home screen.</li></ul>'],
        ['new-stock-arrived', 'New stock arrived', 'published', $ids['editor'], $ago(26), 0, 'post',
         ($img !== '' ? '<figure class="pb-figure pb-align-center pb-w-m"><img src="' . pb_e($img) . '" alt="New stock on the shelf" width="512" height="512"><figcaption>A photo post: one picture, one line.</figcaption></figure>' : '')
         . '<p>Fresh arrivals this week. Drop by the shop, or call us to reserve yours.</p>'],
        ['festive-opening-hours', 'Festive opening hours', 'pending', $ids['contributor'], $ago(3), 0, 'post',
         '<p>We are open from 9 am to 10 pm every day during the festival week.</p><p><em>Written by the Contributor and waiting for an Editor to approve it.</em></p>'],
        ['about', 'About', 'published', $ids['admin'], $ago(48), 0, 'page',
         '<p>This is a <strong>Page</strong>: it has its own address (<code>/about/</code>), sits in the menu, and is left out of the post list and RSS.</p>'],
    ];
    foreach ($posts as [$slug, $title, $status, $author, $when, $pinned, $type, $body]) {
        $published = $status === 'published' ? $when : null;
        pb_q('INSERT INTO posts (slug, title, excerpt, body, cover_image, category_id, author_id, status, created_at, updated_at, submitted_at, published_at, first_published_at, type, pinned)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$slug, $title, '', $body, $slug === 'new-stock-arrived' ? $img : '', $type === 'post' ? $cat : null, $author, $status, $when, $when,
             $status === 'pending' ? $when : null, $published, $published, $type, $pinned]);
        pb_log_event((int) pb_db()->lastInsertId(), $author, $status === 'pending' ? 'submitted' : 'published');
    }
}

// Public pages of a demo: keep search engines out, and invite visitors into the admin.
function pb_demo_public_head() {
    return pb_demo_on() ? '<meta name="robots" content="noindex, nofollow">' . "\n" : '';
}
function pb_demo_public_bar() {
    if (!pb_demo_on()) return '';
    return '<div style="position:fixed;left:50%;bottom:12px;transform:translateX(-50%);z-index:9999;max-width:calc(100vw - 24px);background:#0f1b3d;color:#fff;'
         . 'font:14px/1.4 system-ui,sans-serif;padding:9px 16px;border-radius:99px;box-shadow:0 6px 24px rgba(0,0,0,.25);text-align:center">'
         . 'JotKite demo · resets every ' . pb_demo_minutes() . ' min · <a href="' . pb_e(PB_BASE_PATH . '/admin/') . '" style="color:#9ec1ff;font-weight:600">Try the admin →</a></div>' . "\n";
}
