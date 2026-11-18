<?php
/**
 * JotKite — serves the CSS, JS, images and fonts of addons kept outside the
 * site folder (config.php 'addons_dir'), e.g.
 *   /addon-file.php/jotkite-pro/pro.css?v=1.0.1
 * Addons in addons/ are served by the web server directly and never come here.
 * Only those file types are served: never PHP, JSON or anything else.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial
 */
define('PB_ROOT', __DIR__);

function pb_addon_file_404() {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found\n");
}

$cfg = is_file(__DIR__ . '/config.php') ? include __DIR__ . '/config.php' : [];
$base = is_array($cfg) ? (string) ($cfg['addons_dir'] ?? '') : '';
$base = $base !== '' ? realpath($base) : false;
if ($base === false || !is_dir($base)) pb_addon_file_404();

// The part after addon-file.php. Some hosts leave PATH_INFO empty: read it from the address.
$path = (string) ($_SERVER['PATH_INFO'] ?? '');
if ($path === '') {
    $uri = rawurldecode((string) strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'));
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== '' && strpos($uri, $script) === 0) $path = substr($uri, strlen($script));
}

$types = [
    'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
    'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon',
    'woff' => 'font/woff', 'woff2' => 'font/woff2',
];
// /slug/file.ext or /slug/folder/file.ext: no part may start with a dot, so no "..".
if (!preg_match('~^/([a-z0-9][a-z0-9-]{0,39})/((?:[A-Za-z0-9_-][A-Za-z0-9._-]*/){0,4}[A-Za-z0-9_-][A-Za-z0-9._-]*\.([a-z0-9]{2,5}))$~', $path, $m)
    || !isset($types[$m[3]])) pb_addon_file_404();
// An addon of the same name in addons/ wins (see pb_addons()), so this copy isn't in use.
if (is_dir(__DIR__ . '/addons/' . $m[1])) pb_addon_file_404();

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
