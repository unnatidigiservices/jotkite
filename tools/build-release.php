<?php
/**
 * Builds the files GeoRank's Control Center -> Upgrade -> Blog installs from.
 *
 *   php tools/build-release.php [output-dir]      (default: dist/)
 *
 * Output (upload the whole "postbase" folder to
 * https://app.unnatidigiservices.in/georank/includes/postbase/):
 *
 *   postbase/manifest.json          version, changelog, and the sha256 of every file
 *   postbase/<version>/<path>.txt   each file, with ".txt" added so the host
 *                                   serves it as a download and never runs it
 *   postbase/.htaccess              belt-and-braces: nothing in here executes
 *
 * Older version folders can stay on the host; GeoRank only reads the one the
 * manifest points at.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$root = dirname(__DIR__);
$out = rtrim($argv[1] ?? $root . '/dist', '/\\') . '/postbase';
$lib = file_get_contents($root . '/lib/postbase.php');
if (!preg_match("/define\('PB_VERSION',\s*'([^']+)'\)/", $lib, $m)) { fwrite(STDERR, "PB_VERSION not found\n"); exit(1); }
$version = $m[1];

// What ships to a site: code, assets and the licence texts (the .htaccess files are created by JotKite itself).
$exclude = '#^(dist|tools|docs|\.git[^/]*|config\.php|\.gitignore|data/(?!\.htaccess$).*|uploads/(?!\.htaccess$).*)(/|$)#';
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isDir()) continue;
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    if (preg_match($exclude, $rel)) continue;
    // No dot-files (.htaccess): hosts and CDNs answer 403 for any URL with a
    // file named .ht*, so they could never be downloaded. JotKite writes its
    // .htaccess files itself on the first request (pb_ensure_protection()).
    if (preg_match('#(^|/)\.#', $rel)) continue;
    $files[$rel] = $f->getPathname();
}
ksort($files);

// PHP must lint cleanly before anything is published.
foreach ($files as $rel => $path) {
    if (substr($rel, -4) !== '.php') continue;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $lint, $code);
    if ($code !== 0) { fwrite(STDERR, "Lint failed: $rel\n" . implode("\n", $lint) . "\n"); exit(1); }
}

// Changelog text for this version (the first "## " section of CHANGELOG.md).
$changelog = '';
if (preg_match('/^## .*?(?=^## |\z)/ms', (string) @file_get_contents($root . '/CHANGELOG.md'), $cm)) $changelog = trim($cm[0]);

$verDir = $out . '/' . $version;
if (is_dir($verDir)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($verDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
}
$manifestFiles = [];
foreach ($files as $rel => $path) {
    $target = $verDir . '/' . $rel . '.txt';
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
    copy($path, $target);
    $manifestFiles[$rel] = ['sha256' => hash_file('sha256', $path), 'size' => filesize($path)];
}
$manifest = [
    'name' => 'JotKite',
    'version' => $version,
    'released' => date('Y-m-d'),
    'min_php' => '7.4',
    'path' => $version . '/',
    'suffix' => '.txt',
    'changelog' => $changelog,
    'files' => $manifestFiles,
];
file_put_contents($out . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
file_put_contents($out . '/.htaccess', "# JotKite release files: downloads only, nothing here may execute.\n"
    . "Options -Indexes -ExecCGI\n"
    . "<IfModule mod_mime.c>\n  RemoveHandler .php .phtml .html .htm\n  RemoveType .php .phtml\n  AddType text/plain .txt\n</IfModule>\n"
    . "<FilesMatch \"\\.(php\\d?|phtml|phar|html?)$\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
    . "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n</FilesMatch>\n");

echo "JotKite $version: " . count($manifestFiles) . " files -> $out\n";
foreach ($manifestFiles as $rel => $info) printf("  %-32s %7d  %s\n", $rel, $info['size'], substr($info['sha256'], 0, 12));
