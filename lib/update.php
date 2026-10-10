<?php
/**
 * JotKite — one-click updates from the admin (Settings → Updates).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial
 *
 * Updates come from the same release files GeoRank installs from
 * (tools/build-release.php): manifest.json lists every file with its SHA-256,
 * and manifest.sig is an RSA-SHA256 signature of manifest.json made with the
 * JotKite release key. An update only installs when:
 *   1. the signature matches the public key below — a hacked release server
 *      can't push code, because it can't sign;
 *   2. every downloaded file matches its SHA-256 from the signed manifest.
 *
 * Files are downloaded into data/update/stage/ first; only when all of them
 * arrived intact are they swapped in. The replaced files go to
 * data/update/backup/ for one-click Roll back. data/, uploads/ and config.php
 * are never touched. Database changes run on the next request, as always.
 *
 * config.php: 'update_url' (release folder, '' = no updates) and
 * 'update_pubkey' (PEM, for your own signed releases).
 */
if (!defined('PB_ROOT')) { http_response_code(403); exit; }

define('PB_UPDATE_URL', 'https://app.unnatidigiservices.in/georank/includes/postbase/');
define('PB_RELEASE_PUBKEY', "-----BEGIN PUBLIC KEY-----
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAo5R/sxuSQtbLRAhOEovd
pt2fZDakr9JR2NqccUPYycQAcfnNFUOtKe+wvEwUtLmpWt9gceO+KB3ty48kiTUP
90YSHvnhsyk0LFD+1F/Q98gO9JH/DNlxXOt/i0DSwmsOoPb50PmePu/Hsl2PaxzK
zDam71SEV+VZVdXpKY/h7zd1krl0kRHxbtCueqzuOuxv93MDX0uChVn5rAGadf9A
Z3DbI6pi6i2RR2WIKdaoC7JzSZT0sb/gqFqt1V5HMNStwN9iBQx/xY27k7L08v7D
z4bHQRV6eX96w6hajvEQF0kfk0FE7klMsGyBROTdrtXD0a6EXEspdpPFl/0q7yuA
wLN94ozwbZIs9559OG0C/s/LwmshXvhPXLQOZuPmJOw4AodIcda6R4PtwCfj5a0/
LPH3JUFvz0jKOtT59vCtmafsTSK51pfwdk24HG5zzUDen2hkW8QMTcg6yjoZb+sp
RAaf9v3l0fRoguVV5iTlUOcnAhh65ECFUMLI8kpHO8f/AgMBAAE=
-----END PUBLIC KEY-----");
define('PB_UPDATE_DIR', PB_DATA_DIR . '/update');

function pb_update_url() {
    $u = pb_config('update_url');
    $u = $u === null ? PB_UPDATE_URL : trim((string) $u);
    return $u === '' ? '' : rtrim($u, '/') . '/';
}
function pb_update_pubkey() {
    $k = (string) (pb_config('update_pubkey') ?? '');
    return $k !== '' ? $k : PB_RELEASE_PUBKEY;
}

// Why this site can't update itself from the admin (empty = it can).
function pb_update_blockers() {
    $b = [];
    if (pb_update_url() === '') $b[] = 'Updates are switched off in config.php (update_url).';
    if (pb_is_georank_site()) $b[] = 'This blog is part of a GeoRank site: it updates through GeoRank (Control Center → Upgrade → Blog).';
    if (is_dir(PB_ROOT . '/.git')) $b[] = 'This site is a Git checkout (it has a .git folder), so it should update by pulling or deploying from Git. Updating here would make the next Git deploy fail. If you no longer deploy with Git, delete the .git folder.';
    if (pb_demo_on()) $b[] = 'Updates are off on a demo site.';
    if (!function_exists('openssl_verify')) $b[] = 'PHP\'s OpenSSL extension is missing, so release signatures can\'t be checked.';
    if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) $b[] = 'PHP can\'t download files on this server (no cURL, allow_url_fopen off).';
    return $b;
}

/** GET a URL (HTTPS; plain HTTP only for localhost testing). Returns [body, error]. */
function pb_update_http($url, $maxBytes = 8388608) {
    $p = parse_url($url);
    $scheme = strtolower($p['scheme'] ?? '');
    $local = in_array(strtolower($p['host'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true);
    if ($scheme !== 'https' && !($scheme === 'http' && $local)) return [null, 'Updates must come over HTTPS.'];
    if (function_exists('curl_init')) {
        $body = '';
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'JotKite/' . PB_VERSION . ' (+' . PB_HOMEPAGE . ')',
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, $maxBytes) {
                if (strlen($body) + strlen($chunk) > $maxBytes) return 0; // too big: abort
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $err = curl_error($c);
        curl_close($c);
        if (!$ok) return [null, 'Download failed: ' . ($err !== '' ? $err : 'no answer') . '.'];
        if ($code !== 200) return [null, 'The release server answered HTTP ' . $code . ' for ' . basename((string) ($p['path'] ?? '')) . '.'];
        return [$body, null];
    }
    $ctx = stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 0, 'ignore_errors' => true, 'user_agent' => 'JotKite/' . PB_VERSION],
                                  'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $body = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
    if ($body === false || $status !== 200) return [null, 'Download failed' . ($status ? ' (HTTP ' . $status . ')' : '') . '.'];
    if (strlen($body) > $maxBytes) return [null, 'A release file is larger than expected.'];
    return [$body, null];
}

// A file the updater may write: code and docs only, never data, uploads or config.
function pb_update_path_ok($rel) {
    $rel = (string) $rel;
    if (!preg_match('#^[A-Za-z0-9_][A-Za-z0-9._/-]*$#', $rel) || strpos($rel, '..') !== false || strpos($rel, '//') !== false) return false;
    if (preg_match('#(^|/)\.#', $rel)) return false; // no dot-files
    if (preg_match('#^(data|uploads|dist|tools|docs)(/|$)|^config\.php$#', $rel)) return false;
    return (bool) preg_match('#^(index\.php|config\.sample\.php|[A-Z][A-Z-]*(\.md)?|(admin|assets|lib|addons)/.+)$#', $rel);
}

/** The signed release manifest. Returns [manifest, error]. */
function pb_update_manifest() {
    $base = pb_update_url();
    if ($base === '') return [null, 'Updates are switched off.'];
    [$raw, $err] = pb_update_http($base . 'manifest.json', 2097152);
    if ($err) return [null, $err];
    [$sig, $err] = pb_update_http($base . 'manifest.sig', 8192);
    if ($err) return [null, 'This release isn\'t signed (' . $err . '). For your safety it can\'t be installed.'];
    $bin = base64_decode(trim((string) $sig), true);
    $key = @openssl_pkey_get_public(pb_update_pubkey());
    if (!$bin || !$key || openssl_verify($raw, $bin, $key, OPENSSL_ALGO_SHA256) !== 1) {
        return [null, 'The release signature doesn\'t match the JotKite release key. Nothing was installed. If this keeps happening, tell us: ' . PB_HOMEPAGE];
    }
    $m = json_decode($raw, true);
    if (!is_array($m) || ($m['name'] ?? '') !== 'JotKite' || !preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', (string) ($m['version'] ?? ''))
        || !preg_match('#^[0-9A-Za-z.\-]+/$#', (string) ($m['path'] ?? '')) || ($m['suffix'] ?? null) !== '.txt' || !is_array($m['files'] ?? null) || !$m['files']) {
        return [null, 'The release manifest is incomplete.'];
    }
    foreach ($m['files'] as $rel => $f) {
        if (!pb_update_path_ok($rel) || !preg_match('/^[a-f0-9]{64}$/', (string) ($f['sha256'] ?? '')) || !is_int($f['size'] ?? null)) {
            return [null, 'The release lists a file JotKite won\'t install: ' . substr((string) $rel, 0, 80)];
        }
    }
    return [$m, null];
}

/** Cached update check: ['at', 'version', 'released', 'changelog', 'min_php', 'error']. $force = ask the server now. */
function pb_update_check($force = false) {
    $c = json_decode((string) pb_setting('update_check'), true);
    if (!$force && is_array($c) && (int) ($c['at'] ?? 0) > time() - 86400) return $c;
    [$m, $err] = pb_update_manifest();
    $c = ['at' => time(), 'version' => $m['version'] ?? '', 'released' => $m['released'] ?? '', 'changelog' => $m['changelog'] ?? '',
          'min_php' => $m['min_php'] ?? '', 'error' => $err ?? ''];
    pb_settings_save(['update_check' => json_encode($c)]);
    return $c;
}
function pb_update_available() {
    $c = json_decode((string) pb_setting('update_check'), true);
    return is_array($c) && !empty($c['version']) && version_compare($c['version'], PB_VERSION, '>') ? $c : null;
}

function pb_update_rmdir($dir) {
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}
function pb_update_put($from, $to) { // move a file into place, replacing what's there
    if (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0755, true)) return false;
    if (@rename($from, $to)) return true;
    if (!@copy($from, $to)) return false; // e.g. rename refused across file systems
    @unlink($from);
    return true;
}
function pb_update_opcache(array $rels) {
    if (!function_exists('opcache_invalidate')) return;
    foreach ($rels as $rel) if (substr($rel, -4) === '.php') @opcache_invalidate(PB_ROOT . '/' . $rel, true);
}

/**
 * Install the latest release. Returns [ok, message].
 * Steps: signed manifest → download every changed file into stage/ and check
 * its SHA-256 → back up the files being replaced → swap → done. Any failure
 * before the swap changes nothing; a failure during it restores the backup.
 */
function pb_update_run($user) {
    if (!pb_can($user, 'settings.manage')) return [false, 'Only Admins can update JotKite.'];
    if ($b = pb_update_blockers()) return [false, $b[0]];
    if (!is_dir(PB_UPDATE_DIR) && !@mkdir(PB_UPDATE_DIR, 0755, true)) return [false, 'Could not create data/update/.'];
    $lock = @fopen(PB_UPDATE_DIR . '/lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return [false, 'An update is already running. Wait a minute and reload.'];
    @set_time_limit(300);
    ignore_user_abort(true);
    try {
        [$m, $err] = pb_update_manifest();
        if ($err) return [false, $err];
        if (!version_compare($m['version'], PB_VERSION, '>')) return [false, 'You already have the latest version (' . PB_VERSION . ').'];
        if (!empty($m['min_php']) && version_compare(PHP_VERSION, (string) $m['min_php'], '<')) return [false, 'JotKite ' . $m['version'] . ' needs PHP ' . $m['min_php'] . ' or newer; this server has ' . PHP_VERSION . '. Change the PHP version in your hosting panel first.'];

        // What actually changed, and can PHP write it?
        $todo = [];
        foreach ($m['files'] as $rel => $f) {
            $target = PB_ROOT . '/' . $rel;
            if (is_file($target) && hash_file('sha256', $target) === $f['sha256']) continue;
            $dir = dirname($target);
            while (!is_dir($dir) && $dir !== dirname($dir)) $dir = dirname($dir);
            if (is_file($target) ? !is_writable($target) : !is_writable($dir)) {
                return [false, 'PHP isn\'t allowed to change ' . $rel . ' on this server, so JotKite can\'t update itself here. Update by uploading the files instead (see ' . PB_HOMEPAGE . '/docs-updating/).'];
            }
            $todo[$rel] = $f;
        }
        if (!$todo) { pb_settings_save(['update_check' => '']); return [false, 'All files are already up to date.']; }

        // 1. Download into stage/ and verify every file.
        $stage = PB_UPDATE_DIR . '/stage';
        pb_update_rmdir($stage);
        $base = pb_update_url() . $m['path'];
        foreach ($todo as $rel => $f) {
            [$bytes, $err] = pb_update_http($base . str_replace('%2F', '/', rawurlencode($rel)) . $m['suffix'], max(1024, $f['size'] + 1024));
            if ($err) { pb_update_rmdir($stage); return [false, $err . ' Nothing was changed.']; }
            if (strlen($bytes) !== $f['size'] || !hash_equals($f['sha256'], hash('sha256', $bytes))) {
                pb_update_rmdir($stage);
                return [false, 'A downloaded file (' . $rel . ') doesn\'t match the signed release, so the update was stopped. Nothing was changed. Try again later.'];
            }
            $to = $stage . '/' . $rel;
            if ((!is_dir(dirname($to)) && !@mkdir(dirname($to), 0755, true)) || @file_put_contents($to, $bytes) !== strlen($bytes)) {
                pb_update_rmdir($stage);
                return [false, 'Could not write to data/update/. Nothing was changed.'];
            }
        }

        // 2. Back up what will be replaced (one backup: the version before this one).
        $backup = PB_UPDATE_DIR . '/backup';
        pb_update_rmdir($backup);
        @mkdir($backup, 0755, true);
        $info = ['version' => PB_VERSION, 'to' => $m['version'], 'at' => pb_now(), 'changed' => [], 'added' => []];
        foreach ($todo as $rel => $f) {
            $target = PB_ROOT . '/' . $rel;
            if (is_file($target)) {
                if (!is_dir(dirname($backup . '/' . $rel))) @mkdir(dirname($backup . '/' . $rel), 0755, true);
                if (!@copy($target, $backup . '/' . $rel)) { pb_update_rmdir($stage); return [false, 'Could not back up ' . $rel . '. Nothing was changed.']; }
                $info['changed'][] = $rel;
            } else {
                $info['added'][] = $rel;
            }
        }
        file_put_contents($backup . '/backup.json', json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // 3. Swap in: assets first, the code that runs every request (lib/, index files) last.
        $order = array_keys($todo);
        usort($order, function ($a, $b) {
            $w = function ($r) { return strpos($r, 'lib/') === 0 ? 2 : (preg_match('#(^|/)index\.php$#', $r) ? 3 : (substr($r, -4) === '.php' ? 1 : 0)); };
            return $w($a) - $w($b) ?: strcmp($a, $b);
        });
        $done = [];
        foreach ($order as $rel) {
            if (!pb_update_put($stage . '/' . $rel, PB_ROOT . '/' . $rel)) {
                pb_update_restore($info, $done);
                pb_update_rmdir($stage);
                return [false, 'Could not replace ' . $rel . '. The previous version was put back; nothing else changed.'];
            }
            $done[] = $rel;
        }
        pb_update_opcache($done);
        if (function_exists('opcache_reset')) @opcache_reset();
        pb_update_rmdir($stage);
        pb_settings_save(['update_check' => '', 'update_last' => json_encode(['from' => PB_VERSION, 'to' => $m['version'], 'at' => pb_now(), 'by' => $user['name'], 'files' => count($done)])]);
        return [true, 'Updated to JotKite ' . $m['version'] . ' (' . count($done) . ' file' . (count($done) === 1 ? '' : 's') . ').'];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// Put back the backed-up files (all of them, or only $only), and remove files the update added.
function pb_update_restore(array $info, $only = null) {
    $backup = PB_UPDATE_DIR . '/backup';
    $ok = true;
    foreach ($info['changed'] as $rel) {
        if ($only !== null && !in_array($rel, $only, true)) continue;
        if (!pb_update_path_ok($rel) || !@copy($backup . '/' . $rel, PB_ROOT . '/' . $rel)) $ok = false;
    }
    foreach ($info['added'] as $rel) {
        if ($only !== null && !in_array($rel, $only, true)) continue;
        if (pb_update_path_ok($rel)) @unlink(PB_ROOT . '/' . $rel);
    }
    pb_update_opcache(array_merge($info['changed'], $info['added']));
    if (function_exists('opcache_reset')) @opcache_reset();
    return $ok;
}
function pb_update_backup_info() {
    $j = json_decode((string) @file_get_contents(PB_UPDATE_DIR . '/backup/backup.json'), true);
    return is_array($j) && isset($j['version'], $j['changed'], $j['added']) ? $j : null;
}
/** Go back to the version before the last update. Returns [ok, message]. */
function pb_update_rollback($user) {
    if (!pb_can($user, 'settings.manage')) return [false, 'Only Admins can roll back.'];
    $info = pb_update_backup_info();
    if (!$info) return [false, 'There\'s no earlier version to go back to.'];
    if (!pb_update_restore($info)) return [false, 'Some files could not be put back. Check the folder permissions, or upload JotKite ' . $info['version'] . ' by hand.'];
    pb_update_rmdir(PB_UPDATE_DIR . '/backup');
    pb_settings_save(['update_check' => '', 'update_last' => json_encode(['from' => $info['to'], 'to' => $info['version'], 'at' => pb_now(), 'by' => $user['name'], 'rollback' => true])]);
    return [true, 'Back on JotKite ' . $info['version'] . '. Your posts and settings are unchanged.'];
}

// The daily check, after the page has gone to the browser (Admins' admin visits only).
function pb_update_check_later() {
    if (pb_update_blockers()) return; // GeoRank, Git and demo sites update another way
    $c = json_decode((string) pb_setting('update_check'), true);
    if (is_array($c) && (int) ($c['at'] ?? 0) > time() - 86400) return;
    register_shutdown_function(function () {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
        try { pb_update_check(true); } catch (Throwable $e) { /* try again tomorrow */ }
    });
}
