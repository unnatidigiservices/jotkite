<?php
/**
 * JotKite — serves the CSS, JS, images and fonts of addons kept outside the
 * site folder (config.php 'addons_dir'), e.g.
 *   /_addon/jotkite-pro/pro.css?v=1.0.1
 * index.php sends those addresses here before loading anything else.
 * Addons in addons/ are served by the web server directly and never come here.
 * Only those file types are served: never PHP, JSON or anything else.
 *
 * (Lives in lib/ rather than at the site root, because the updater only ever
 * installs index.php at the root.)
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial
 */
if (!defined('PB_ROOT')) { http_response_code(403); exit; }

function pb_addon_file_404() {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found\n");
}

/** Serve one file. $path is the part after /_addon, e.g. /jotkite-pro/pro.css. */
function pb_addon_file_serve($path) {
    $cfg = is_file(PB_ROOT . '/config.php') ? include PB_ROOT . '/config.php' : [];
    $base = is_array($cfg) ? (string) ($cfg['addons_dir'] ?? '') : '';
    $base = $base !== '' ? realpath($base) : false;
    if ($base === false || !is_dir($base)) pb_addon_file_404();

    $types = [
        'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2',
    ];
    // /slug/file.ext or /slug/folder/file.ext: no part may start with a dot, so no "..".
    if (!preg_match('~^/([a-z0-9][a-z0-9-]{0,39})/((?:[A-Za-z0-9_-][A-Za-z0-9._-]*/){0,4}[A-Za-z0-9_-][A-Za-z0-9._-]*\.([a-z0-9]{2,5}))$~', (string) $path, $m)
        || !isset($types[$m[3]])) pb_addon_file_404();
    // An addon of the same name in addons/ wins (see pb_addons()), so this copy isn't in use.
    if (is_dir(PB_ROOT . '/addons/' . $m[1])) pb_addon_file_404();

    $file = realpath($base . '/' . $m[1] . '/' . $m[2]);
    if ($file === false || !is_file($file) || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0) pb_addon_file_404();

    $mtime = (int) filemtime($file);
    $etag = '"' . dechex($mtime) . '-' . dechex((int) filesize($file)) . '"';
    header('Content-Type: ' . $types[$m[3]]);
    header('X-Content-Type-Options: nosniff');
    // CSS and JS are linked with ?v=<addon version>, like the files in addons/ (see .htaccess).
    header('Cache-Control: public, max-age=' . (in_array($m[3], ['css', 'js'], true) ? '31536000, immutable' : '604800'));
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('ETag: ' . $etag);
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . filesize($file));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($file);
    exit;
}
