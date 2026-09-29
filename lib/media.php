<?php
/**
 * JotKite — media library (the images in uploads/) · https://jotkite.com
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial
 */
if (!defined('PB_ROOT')) { http_response_code(403); exit; }

define('PB_MEDIA_EXTS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

/** Every image in uploads/, newest first: [rel, url, name, size, mtime]. Optional filename filter. */
function pb_media_list($q = '') {
    $out = [];
    if (!is_dir(PB_UPLOAD_DIR)) return $out;
    $q = strtolower(trim((string) $q));
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PB_UPLOAD_DIR, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || !in_array(strtolower($f->getExtension()), PB_MEDIA_EXTS, true)) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(PB_UPLOAD_DIR) + 1));
        if ($q !== '' && strpos(strtolower($rel), $q) === false) continue;
        $out[] = ['rel' => $rel, 'url' => PB_BASE_PATH . '/uploads/' . $rel, 'name' => $f->getFilename(),
                  'size' => $f->getSize(), 'mtime' => $f->getMTime()];
    }
    usort($out, function ($a, $b) { return $b['mtime'] - $a['mtime'] ?: strcmp($a['rel'], $b['rel']); });
    return $out;
}

/** Where each image URL is used: posts/pages (body or featured image) and settings (logo, share image…). */
function pb_media_usage(array $urls) {
    $use = array_fill_keys($urls, []);
    if (!$urls) return $use;
    $posts = pb_all('SELECT id, title, type, body, cover_image FROM posts');
    $settings = pb_all("SELECT key, value FROM settings WHERE value LIKE '%/uploads/%'");
    foreach ($urls as $u) {
        foreach ($posts as $p) {
            if (strpos($p['body'], $u) !== false || $p['cover_image'] === $u) {
                $use[$u][] = ['kind' => $p['type'], 'id' => (int) $p['id'], 'title' => $p['title']];
            }
        }
        foreach ($settings as $s) {
            if (strpos($s['value'], $u) !== false) $use[$u][] = ['kind' => 'setting', 'id' => 0, 'title' => $s['key']];
        }
    }
    return $use;
}

/** Resolve a relative uploads path to a real image file inside uploads/, or null. */
function pb_media_path($rel) {
    $rel = str_replace('\\', '/', (string) $rel);
    if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/') return null;
    if (!in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), PB_MEDIA_EXTS, true)) return null;
    $base = realpath(PB_UPLOAD_DIR);
    $full = realpath(PB_UPLOAD_DIR . '/' . $rel);
    if (!$base || !$full || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) return null;
    return $full;
}

/** Delete images by relative path. Returns [deleted count, skipped count]. */
function pb_media_delete(array $rels) {
    $deleted = 0; $skipped = 0;
    foreach (array_unique($rels) as $rel) {
        $full = pb_media_path($rel);
        if ($full && @unlink($full)) $deleted++; else $skipped++;
    }
    return [$deleted, $skipped];
}

// ----------------------------------------------------------------------------
// PHOTO METADATA (EXIF) — pure PHP, so it works without the exif extension.
// Phone photos carry where (GPS), when and on what camera they were taken.
// JotKite keeps that by default: for local businesses a real, geotagged photo
// is a signal of a real place. Settings → General can remove it instead.
// ----------------------------------------------------------------------------

/** JPEG segments before the image data: [[marker, offset, total length], …]. */
function pb_jpeg_segments($bytes) {
    $out = [];
    $n = strlen($bytes);
    if ($n < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") return $out;
    $i = 2;
    while ($i + 4 <= $n && $bytes[$i] === "\xFF") {
        $m = ord($bytes[$i + 1]);
        if ($m === 0xFF) { $i++; continue; }                     // fill byte
        if ($m === 0xDA || $m === 0xD9) break;                    // start of scan / end of image
        if ($m === 0x01 || ($m >= 0xD0 && $m <= 0xD7)) { $i += 2; continue; }
        $len = unpack('n', substr($bytes, $i + 2, 2))[1];
        if ($len < 2 || $i + 2 + $len > $n) break;
        $out[] = [$m, $i, $len + 2];
        $i += 2 + $len;
    }
    return $out;
}

/** The whole EXIF APP1 segment (marker included), or ''. */
function pb_jpeg_exif_segment($bytes) {
    foreach (pb_jpeg_segments($bytes) as [$m, $off, $len]) {
        if ($m === 0xE1 && substr($bytes, $off + 4, 6) === "Exif\0\0") return substr($bytes, $off, $len);
    }
    return '';
}

/** Removes EXIF/XMP (APP1) and IPTC (APP13) without re-encoding the image. */
function pb_jpeg_strip_meta($bytes) {
    $cut = 0;
    $out = '';
    foreach (pb_jpeg_segments($bytes) as [$m, $off, $len]) {
        if ($m === 0xE1 || $m === 0xED) { $out .= substr($bytes, $cut, $off - $cut); $cut = $off + $len; }
    }
    return $cut ? $out . substr($bytes, $cut) : $bytes;
}

/** Puts an EXIF segment right after the JPEG start marker. */
function pb_jpeg_add_exif($jpeg, $segment) {
    return $segment === '' || substr($jpeg, 0, 2) !== "\xFF\xD8" ? $jpeg : "\xFF\xD8" . $segment . substr($jpeg, 2);
}

/**
 * Reads an EXIF segment: orientation, camera, date taken and GPS position.
 * 'orientation_at' is the byte offset of the orientation value inside the
 * segment, so a copy can be marked "upright" after the pixels were rotated.
 */
function pb_exif_parse($segment) {
    $t = substr($segment, 10); // after FF E1, length and "Exif\0\0": the TIFF block
    $n = strlen($t);
    if ($n < 8) return null;
    $le = substr($t, 0, 2) === 'II';
    if (!$le && substr($t, 0, 2) !== 'MM') return null;
    $u16 = function ($p) use ($t, $n, $le) { return $p + 2 <= $n ? unpack($le ? 'v' : 'n', substr($t, $p, 2))[1] : 0; };
    $u32 = function ($p) use ($t, $n, $le) { return $p + 4 <= $n ? unpack($le ? 'V' : 'N', substr($t, $p, 4))[1] : 0; };
    $sizes = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 7 => 1, 9 => 4, 10 => 8];
    $ifd = function ($off) use ($u16, $u32, $n, $sizes) {
        $tags = [];
        if ($off < 8 || $off + 2 > $n) return $tags;
        $count = min($u16($off), 256);
        for ($k = 0; $k < $count; $k++) {
            $e = $off + 2 + $k * 12;
            if ($e + 12 > $n) break;
            $type = $u16($e + 2);
            $cnt = $u32($e + 4);
            $size = ($sizes[$type] ?? 1) * $cnt;
            $pos = $size <= 4 ? $e + 8 : $u32($e + 8);
            if ($pos + $size > $n) continue;
            $tags[$u16($e)] = ['type' => $type, 'count' => $cnt, 'pos' => $pos];
        }
        return $tags;
    };
    $str = function ($tag) use ($t) { return $tag && $tag['type'] === 2 ? trim(substr($t, $tag['pos'], $tag['count']), "\0 ") : ''; };
    $rat = function ($p) use ($u32) { $d = $u32($p + 4); return $d ? $u32($p) / $d : 0.0; };
    $coord = function ($tag, $ref, $neg) use ($rat, $t) {
        if (!$tag || $tag['type'] !== 5 || $tag['count'] < 3) return null;
        $v = $rat($tag['pos']) + $rat($tag['pos'] + 8) / 60 + $rat($tag['pos'] + 16) / 3600;
        return round(strtoupper(substr($t, $ref['pos'] ?? 0, 1)) === $neg ? -$v : $v, 6);
    };

    $ifd0 = $ifd($u32(4));
    $info = ['orientation' => 1, 'orientation_at' => null, 'make' => '', 'model' => '', 'taken' => '', 'lat' => null, 'lng' => null];
    if (isset($ifd0[0x0112]) && $ifd0[0x0112]['type'] === 3) {
        $info['orientation'] = $u16($ifd0[0x0112]['pos']);
        $info['orientation_at'] = 10 + $ifd0[0x0112]['pos'];
    }
    $info['make'] = $str($ifd0[0x010F] ?? null);
    $info['model'] = $str($ifd0[0x0110] ?? null);
    $info['taken'] = $str($ifd0[0x0132] ?? null);
    if (isset($ifd0[0x8769])) {
        $sub = $ifd($u32($ifd0[0x8769]['pos']));
        $info['taken'] = $str($sub[0x9003] ?? null) ?: $info['taken']; // DateTimeOriginal
    }
    if (isset($ifd0[0x8825])) {
        $gps = $ifd($u32($ifd0[0x8825]['pos']));
        $info['lat'] = $coord($gps[2] ?? null, $gps[1] ?? null, 'S');
        $info['lng'] = $coord($gps[4] ?? null, $gps[3] ?? null, 'W');
        if (!$info['lat'] && !$info['lng']) $info['lat'] = $info['lng'] = null; // 0,0 = no fix
    }
    return $info;
}

/** The segment with its orientation set to 1 (upright), for pixels already rotated. */
function pb_exif_upright($segment) {
    $info = pb_exif_parse($segment);
    if (!$info || !$info['orientation_at'] || $info['orientation'] === 1) return $segment;
    $le = substr($segment, 10, 2) === 'II';
    return substr_replace($segment, pack($le ? 'v' : 'n', 1), $info['orientation_at'], 2);
}

// ---- WebP (all uploads are stored as WebP since 0.24) ----
// A WebP file is a RIFF container of chunks. Photo details live in an "EXIF"
// chunk (the same TIFF block a JPEG carries), which needs the extended "VP8X"
// header with its EXIF flag set.

/** Adds an EXIF block (given as a JPEG APP1 segment) to WebP bytes. $w/$h: image size. */
function pb_webp_add_exif($webp, $segment, $w, $h) {
    $tiff = substr((string) $segment, 10); // after FF E1, length and "Exif\0\0"
    if ($tiff === '' || substr($webp, 0, 4) !== 'RIFF' || substr($webp, 8, 4) !== 'WEBP') return $webp;
    $body = substr($webp, 12);
    $first = substr($body, 0, 4);
    if ($first === 'VP8X') {
        $body[8] = chr(ord($body[8]) | 0x08); // EXIF flag
    } else {
        $alpha = 0;
        if ($first === 'VP8L' && strlen($body) >= 13) { // lossless: the alpha bit sits in the bitstream header
            $alpha = ((unpack('V', substr($body, 9, 4))[1] >> 28) & 1) ? 0x10 : 0;
        }
        $vp8x = 'VP8X' . pack('V', 10) . chr(0x08 | $alpha) . "\0\0\0"
              . substr(pack('V', max(1, (int) $w) - 1), 0, 3) . substr(pack('V', max(1, (int) $h) - 1), 0, 3);
        $body = $vp8x . $body;
    }
    $body .= 'EXIF' . pack('V', strlen($tiff)) . $tiff . (strlen($tiff) % 2 ? "\0" : '');
    return 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;
}

/** The EXIF block of WebP bytes, returned as a JPEG-style APP1 segment (for pb_exif_parse), or ''. */
function pb_webp_exif_segment($webp) {
    if (substr($webp, 0, 4) !== 'RIFF' || substr($webp, 8, 4) !== 'WEBP') return '';
    $n = strlen($webp);
    for ($i = 12; $i + 8 <= $n;) {
        $id = substr($webp, $i, 4);
        $len = unpack('V', substr($webp, $i + 4, 4))[1];
        if ($id === 'EXIF') {
            $tiff = substr($webp, $i + 8, $len);
            if (strpos($tiff, "Exif\0\0") === 0) $tiff = substr($tiff, 6); // some tools include the JPEG prefix
            return "\xFF\xE1" . pack('n', strlen($tiff) + 8) . "Exif\0\0" . $tiff;
        }
        $i += 8 + $len + ($len % 2);
    }
    return '';
}

/** True for a GIF with more than one frame (converting it would freeze the animation). */
function pb_gif_is_animated($path) {
    return preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', (string) @file_get_contents($path)) > 1;
}

/** Can this server write WebP? (GD built with WebP support.) */
function pb_webp_available() {
    static $ok = null;
    if ($ok === null) $ok = function_exists('imagewebp') && function_exists('imagecreatetruecolor') && (!function_exists('gd_info') || !empty(gd_info()['WebP Support']));
    return $ok;
}

/** Photo details of an image file for the Media Manager ('' fields when absent). */
function pb_photo_info($path) {
    if (preg_match('/\.webp$/i', $path)) {
        $seg = pb_webp_exif_segment((string) @file_get_contents($path)); // the EXIF chunk sits after the image data
    } elseif (preg_match('/\.jpe?g$/i', $path)) {
        $fh = @fopen($path, 'rb');
        if (!$fh) return null;
        $head = fread($fh, 131072); // EXIF always sits in the first 64 KB
        fclose($fh);
        $seg = pb_jpeg_exif_segment((string) $head);
    } else {
        return null;
    }
    $info = $seg !== '' ? pb_exif_parse($seg) : null;
    if (!$info) return null;
    $model = $info['make'] !== '' && stripos($info['model'], $info['make']) === 0 ? trim(substr($info['model'], strlen($info['make']))) : $info['model'];
    $camera = trim($info['make'] . ' ' . $model); // "Apple" + "Apple iPhone 14" → "Apple iPhone 14"
    $taken = '';
    if (preg_match('/^(\d{4}):(\d\d):(\d\d) (\d\d):(\d\d)/', $info['taken'], $d)) {
        $taken = date('j M Y, g:i a', mktime((int) $d[4], (int) $d[5], 0, (int) $d[2], (int) $d[3], (int) $d[1]));
    }
    if ($camera === '' && $taken === '' && $info['lat'] === null) return null;
    return ['camera' => $camera, 'taken' => $taken, 'lat' => $info['lat'], 'lng' => $info['lng']];
}

// Rotates/flips a GD image as the EXIF orientation says. Returns the new image.
function pb_gd_orient($img, $orientation) {
    $rotate = [3 => 180, 6 => -90, 8 => 90, 5 => -90, 7 => 90][$orientation] ?? 0; // GD turns counter-clockwise
    if ($rotate && ($r = imagerotate($img, $rotate, 0))) { imagedestroy($img); $img = $r; }
    if (in_array($orientation, [2, 5, 7], true)) imageflip($img, IMG_FLIP_HORIZONTAL);
    if ($orientation === 4) imageflip($img, IMG_FLIP_VERTICAL);
    return $img;
}

function pb_human_size($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}
