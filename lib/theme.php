<?php
/**
 * JotKite — public page layout.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial
 *
 * Two layouts:
 *   georank    — wraps blog pages in the site's own meta-global.html,
 *                header.html and footer.html, and reuses the stylesheet and
 *                scripts the homepage loads. Theme, fonts, menu, WhatsApp
 *                button etc. all follow the GeoRank Design tab automatically.
 *   standalone — a clean self-contained layout for any other PHP site.
 */
if (!defined('PB_ROOT')) { http_response_code(403); exit; }

// Stylesheet/preload tags from the homepage <head>, and script tags from its
// <body>, so blog pages load exactly the same theme assets (cache-busting
// ?v= numbers included) without JotKite knowing anything about them.
function pb_georank_page_assets() {
    $idx = PB_SITE_DIR . '/index.html';
    $out = ['head' => '', 'foot' => ''];
    if (!is_file($idx)) return $out;
    $raw = (string) file_get_contents($idx);
    $siteBase = pb_site_base_path();
    $fix = function ($tag) use ($siteBase) {
        // Relative asset paths on the homepage are relative to the site root, not /blog/.
        return preg_replace_callback('~\b(href|src)=(["\'])(?!https?:|//|/|data:|#)([^"\']+)\2~i', function ($m) use ($siteBase) {
            return $m[1] . '=' . $m[2] . $siteBase . '/' . $m[3] . $m[2];
        }, $tag);
    };
    if (preg_match('~<head[^>]*>(.*?)</head>~is', $raw, $h)) {
        preg_match_all('~<link\b[^>]*\brel=(["\'])(stylesheet|preload|preconnect|modulepreload)\1[^>]*>~i', $h[1], $links);
        foreach ($links[0] as $tag) $out['head'] .= $fix($tag) . "\n";
    }
    if (preg_match('~<body[^>]*>(.*)</body>~is', $raw, $b)) {
        preg_match_all('~<script\b[^>]*\bsrc=[^>]*>\s*</script>~i', $b[1], $scripts);
        foreach ($scripts[0] as $tag) $out['foot'] .= $fix($tag) . "\n";
    }
    return $out;
}

// Inline CSS for Settings → Design. Only overrides what the admin actually
// set; everything left empty keeps inheriting the site theme.
function pb_design_css() {
    $vars = [];
    $map = ['design_accent' => '--pb-accent', 'design_text' => '--pb-text', 'design_bg' => '--pb-bg', 'design_surface' => '--pb-surface'];
    foreach ($map as $key => $var) {
        $hex = pb_valid_hex(pb_setting($key));
        if ($hex !== '') $vars[] = $var . ':' . $hex;
    }
    $css = $vars ? '.pb-page{' . implode(';', $vars) . '}' : '';
    if (pb_valid_hex(pb_setting('design_text')) !== '') {
        $css .= '.pb-main,.pb-main h1,.pb-main h2,.pb-main h3,.pb-main h4{color:var(--pb-text)}';
    }
    if (pb_valid_hex(pb_setting('design_bg')) !== '') $css .= '.pb-main{background:var(--pb-bg)}';
    $fonts = pb_font_choices();
    $body = $fonts[pb_setting('design_font_body')][1] ?? '';
    $head = $fonts[pb_setting('design_font_heading')][1] ?? '';
    if ($body !== '') $css .= '.pb-main{font-family:' . $body . '}';
    if ($head !== '') $css .= '.pb-main h1,.pb-main h2,.pb-main h3,.pb-main h4,.pb-brand{font-family:' . $head . '}';
    $size = (int) pb_setting('design_font_size');
    if ($size >= 14 && $size <= 22) $css .= '.pb-main{font-size:' . $size . 'px}';
    return $css;
}

function pb_nav_html($class) {
    $items = pb_nav_items();
    if (!$items) return '';
    $here = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $h = '<nav class="' . pb_e($class) . '" aria-label="Blog navigation">';
    foreach ($items as $it) {
        $url = (string) $it['url'];
        $current = $url === $here || ($url === PB_BASE_PATH . '/' && strpos($here, PB_BASE_PATH . '/') === 0);
        $h .= '<a href="' . pb_e($url) . '"' . ($current ? ' aria-current="page"' : '')
            . (!empty($it['new_tab']) ? ' target="_blank" rel="noopener"' : '') . '>' . pb_e($it['label']) . '</a>';
    }
    return $h . '</nav>';
}

/**
 * Print a full public page.
 * $meta: title, description, canonical, image, type ('website'|'article'),
 *        jsonld (array|null), noindex (bool), head (extra raw HTML).
 */
function pb_render_page(array $meta, $content) {
    $meta = pb_apply_filters('pb_page_meta', $meta);
    $title = $meta['title'] ?? pb_setting('blog_title');
    $desc = $meta['description'] ?? '';
    $canonical = $meta['canonical'] ?? '';
    $image = ($meta['image'] ?? '') !== '' ? $meta['image'] : (string) pb_setting('design_social_image');
    $lang = pb_setting('language') ?: 'en';
    $blogCss = PB_BASE_PATH . '/assets/blog.css?v=' . PB_VERSION;

    $head = '<meta charset="UTF-8">' . "\n"
          . '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n"
          . '<title>' . pb_e($title) . '</title>' . "\n";
    if ($desc !== '') $head .= '<meta name="description" content="' . pb_e($desc) . '">' . "\n";
    if (!empty($meta['noindex'])) $head .= '<meta name="robots" content="noindex, follow">' . "\n";
    $tail = '';
    if ($canonical !== '') $tail .= '<link rel="canonical" href="' . pb_e($canonical) . '">' . "\n";
    $tail .= '<link rel="alternate" type="application/rss+xml" title="' . pb_e(pb_setting('blog_title')) . '" href="' . pb_e(pb_abs_url(pb_url('feed'))) . '">' . "\n";
    $tail .= '<meta property="og:type" content="' . pb_e($meta['type'] ?? 'website') . '">' . "\n";
    $tail .= '<meta property="og:title" content="' . pb_e($title) . '">' . "\n";
    if ($desc !== '') $tail .= '<meta property="og:description" content="' . pb_e($desc) . '">' . "\n";
    if ($canonical !== '') $tail .= '<meta property="og:url" content="' . pb_e($canonical) . '">' . "\n";
    if ($image !== '') {
        $tail .= '<meta property="og:image" content="' . pb_e(pb_abs_url($image)) . '">' . "\n";
        $tail .= '<meta name="twitter:image" content="' . pb_e(pb_abs_url($image)) . '">' . "\n";
        $tail .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
    }
    if (!empty($meta['jsonld'])) {
        $tail .= '<script type="application/ld+json">'
               . json_encode($meta['jsonld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
               . '</script>' . "\n";
    }
    $favicon = (string) pb_setting('design_favicon');
    if ($favicon !== '') $tail .= '<link rel="icon" href="' . pb_e($favicon) . '">' . "\n"; // after meta-global, so it wins
    $designCss = pb_design_css();
    if ($designCss !== '') $tail .= '<style>/* JotKite design settings · https://jotkite.com */' . $designCss . '</style>' . "\n";
    $tail .= '<meta name="generator" content="JotKite (' . PB_HOMEPAGE . ')">' . "\n";
    $tail .= $meta['head'] ?? '';
    $tail .= pb_capture_action('pb_head', $meta);       // plugins: extra <head> tags
    $bodyEnd = pb_capture_action('pb_body_end', $meta); // plugins: scripts before </body>
    // Settings → Code (Admin only, printed as entered): verification tags, analytics, chat widgets…
    $codeHead = trim((string) pb_setting('code_head'));
    $codeFooter = trim((string) pb_setting('code_footer'));
    if ($codeHead !== '') $tail .= "<!-- JotKite: custom head code -->\n" . $codeHead . "\n";
    if ($codeFooter !== '') $bodyEnd .= "<!-- JotKite: custom footer code -->\n" . $codeFooter . "\n";
    $tail .= pb_demo_public_head();    // demo sites (lib/demo.php): noindex…
    $bodyEnd .= pb_demo_public_bar();  // …and a "Try the admin" bar

    header('Content-Type: text/html; charset=utf-8');
    if (pb_layout_mode() === 'georank') {
        // GeoRank's include files redirect to the homepage when GEORANK_INCLUDE isn't
        // defined (so nobody can open them directly). header.html and footer.html
        // always had that guard; newer sites put it in meta-global.html too, so it
        // must be defined before the very first include.
        if (!defined('GEORANK_INCLUDE')) define('GEORANK_INCLUDE', true);
        $assets = pb_georank_page_assets();
        echo "<!DOCTYPE html>\n<html lang=\"" . pb_e($lang) . "\">\n<head>\n" . $head;
        if (is_file(PB_SITE_DIR . '/meta-global.html')) include PB_SITE_DIR . '/meta-global.html';
        echo "\n" . $assets['head'];
        echo pb_css_tag(PB_ROOT . '/assets/blog.css', $blogCss) . $tail;
        echo "</head>\n<body class=\"pb-page pb-georank\">\n";
        include PB_SITE_DIR . '/header.html';
        $subnav = pb_setting('nav_show_georank') === '1' ? '<div class="pb-subnav-bar"><div class="pb-wrap">' . pb_nav_html('pb-subnav') . "</div></div>\n" : '';
        echo "\n<main id=\"main\" class=\"pb-main\">\n" . $subnav . $content . "\n</main>\n";
        include PB_SITE_DIR . '/footer.html';
        echo "\n" . $assets['foot'] . $bodyEnd . "</body>\n</html>\n";
        return;
    }

    // Active theme addon (Settings → Addons). Any failure falls back to the built-in layout below.
    $theme = pb_active_theme();
    if ($theme) {
        $themeHead = $head . pb_css_tag(PB_ROOT . '/assets/blog.css', $blogCss);
        foreach (['theme.css'] as $f) {
            if (is_file($theme['dir'] . '/' . $f)) {
                $themeHead .= pb_css_tag($theme['dir'] . '/' . $f, $theme['url'] . '/' . $f . '?v=' . $theme['version']);
            }
        }
        $page = [
            'lang' => $lang,
            'head' => $themeHead . $tail,           // everything that belongs inside <head>
            'body_end' => $bodyEnd,                  // print just before </body>
            'content' => $content,                   // the list/post/page HTML
            'nav' => pb_nav_items(),                 // [['label','url','new_tab'], ...]
            'blog_title' => (string) pb_setting('blog_title'),
            'blog_url' => pb_url(),                  // the homepage
            'posts_url' => pb_url('posts'),          // the post list (differs when a Page is the homepage)
            'site_url' => pb_site_base_path() . '/',
            'feed_url' => pb_url('feed'),
            'favicon' => $favicon,
            'theme_url' => $theme['url'],
            'theme_version' => $theme['version'],   // for cache-busting your own assets
            'settings' => pb_addon_settings($theme['slug']),
            'year' => date('Y'),
            'powered_by' => 'Powered by <a href="' . PB_HOMEPAGE . '" rel="noopener">JotKite</a>',
        ];
        if (pb_render_with_theme($theme, $page)) return;
    }

    echo "<!DOCTYPE html>\n<html lang=\"" . pb_e($lang) . "\">\n<head>\n" . $head
       . pb_css_tag(PB_ROOT . '/assets/blog.css', $blogCss) . $tail
       . "</head>\n<body class=\"pb-page pb-standalone\">\n"
       . '<header class="pb-topbar"><div class="pb-wrap pb-topbar-inner">'
       . '<a class="pb-brand" href="' . pb_e(pb_url()) . '">'
       . ($favicon !== '' ? '<img src="' . pb_e($favicon) . '" alt="" width="28" height="28">' : '')
       . pb_e(pb_setting('blog_title')) . '</a>'
       . pb_nav_html('pb-topnav') . '</div></header>' . "\n"
       . '<main id="main" class="pb-main">' . "\n" . $content . "\n</main>\n"
       . '<footer class="pb-footer"><div class="pb-wrap">&copy; ' . date('Y') . ' ' . pb_e(pb_setting('blog_title'))
       . ' &middot; Powered by <a href="' . PB_HOMEPAGE . '" rel="noopener">JotKite</a></div></footer>' . "\n"
       . $bodyEnd . "</body>\n</html>\n";
}

// A small stylesheet goes straight into the page: no extra request blocking the
// first paint (PageSpeed "render-blocking requests"). Big ones stay linked.
function pb_css_tag($file, $url) {
    if (is_file($file) && filesize($file) <= 24576) {
        $css = (string) file_get_contents($file);
        return '<style>' . str_replace('</style', '<\/style', $css) . '</style>' . "\n";
    }
    return '<link rel="stylesheet" href="' . pb_e($url) . '">' . "\n";
}
// ' width="…" height="…"' for an image in this blog's uploads/, so the browser
// reserves its space before it loads and the page doesn't jump (PageSpeed "CLS").
function pb_img_dims($url) {
    static $cache = [];
    $url = (string) $url;
    if (isset($cache[$url])) return $cache[$url];
    $prefix = PB_BASE_PATH . '/uploads/';
    $out = '';
    if (strpos($url, $prefix) === 0 && strpos($url, '..') === false) {
        $size = @getimagesize(PB_UPLOAD_DIR . '/' . substr(strtok($url, '?#'), strlen($prefix)));
        if ($size && $size[0] > 0) $out = ' width="' . (int) $size[0] . '" height="' . (int) $size[1] . '"';
    }
    return $cache[$url] = $out;
}

// Post HTML: give every uploaded image that lacks them its width and height
// (older posts, images added as HTML), for the same no-jump reason.
function pb_add_img_dims($html) {
    return preg_replace_callback('~<img\b[^>]*>~i', function ($m) {
        $tag = $m[0];
        if (preg_match('~\swidth="\d+"~i', $tag) || !preg_match('~\ssrc="([^"]+)"~i', $tag, $s)) return $tag;
        $dims = pb_img_dims(html_entity_decode($s[1], ENT_QUOTES, 'UTF-8'));
        return $dims === '' ? $tag : preg_replace('~^<img\b~i', '<img' . $dims, $tag);
    }, (string) $html);
}

// Round author photo, or the first letter of the name on a coloured disc.
function pb_avatar_html($src, $name, $size) {
    $size = (int) $size;
    if ((string) $src !== '') {
        return '<img class="pb-avatar" src="' . pb_e($src) . '" alt="' . pb_e($name) . '" width="' . $size . '" height="' . $size . '" loading="lazy" decoding="async">';
    }
    $initial = function_exists('mb_substr') ? mb_strtoupper(mb_substr(trim((string) $name), 0, 1)) : strtoupper(substr(trim((string) $name), 0, 1));
    return '<span class="pb-avatar pb-avatar-initial" style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . round($size * .42) . 'px" aria-hidden="true">' . pb_e($initial ?: '?') . '</span>';
}
// "Written by" box under a post: small photo, short bio, link to the author page.
function pb_author_box_html(array $au) {
    $name = pb_e($au['name']);
    $link = $au['url'] !== '' ? pb_e($au['url']) : '';
    $pic = pb_avatar_html($au['avatar'], $au['name'], 56);
    return '<aside class="pb-author-box" aria-label="About the author">'
        . ($link !== '' ? '<a href="' . $link . '" tabindex="-1" aria-hidden="true">' . $pic . '</a>' : $pic)
        . '<div><p class="pb-author-name">Written by ' . ($link !== '' ? '<a href="' . $link . '" rel="author">' . $name . '</a>' : $name) . '</p>'
        . ($au['bio'] !== '' ? '<p class="pb-author-bio">' . pb_e($au['bio']) . '</p>' : '')
        . ($link !== '' ? '<a class="pb-author-more" href="' . $link . '">More about ' . $name . ' &rarr;</a>' : '')
        . '</div></aside>';
}
// Plain text (long bio) to HTML: paragraphs, line breaks, and clickable web links.
function pb_text_to_html($text) {
    $out = '';
    foreach (preg_split("/\n{2,}/", trim(str_replace("\r\n", "\n", (string) $text))) as $para) {
        $h = preg_replace_callback('~https?://[^\s<>"\']+~i', function ($m) {
            $url = rtrim(html_entity_decode($m[0], ENT_QUOTES, 'UTF-8'), '.,;:!?)');
            $tail = substr(html_entity_decode($m[0], ENT_QUOTES, 'UTF-8'), strlen($url));
            return '<a href="' . pb_e($url) . '" rel="nofollow ugc noopener" target="_blank">' . pb_e($url) . '</a>' . pb_e($tail);
        }, pb_e($para));
        $out .= '<p>' . nl2br($h, false) . '</p>';
    }
    return $out;
}

// One post card for listing pages.
function pb_card_html($p) {
    $url = pb_url('post', $p['slug']);
    $excerpt = $p['excerpt'] !== '' ? $p['excerpt'] : pb_text_excerpt($p['body'], 150);
    $img = pb_post_image($p); // cover → first image in the post → default social image
    $h = '<article class="pb-card">';
    if ($img !== '') {
        $h .= '<a class="pb-card-img" href="' . pb_e($url) . '" tabindex="-1" aria-hidden="true">'
            . '<img src="' . pb_e($img) . '" alt=""' . pb_img_dims($img) . ' loading="lazy" decoding="async"></a>';
    }
    $h .= '<div class="pb-card-body">';
    if (!empty($p['category_name'])) {
        $h .= '<a class="pb-chip" href="' . pb_e(pb_url('category', $p['category_slug'])) . '">' . pb_e($p['category_name']) . '</a>';
    }
    $h .= '<h2 class="pb-card-title"><a href="' . pb_e($url) . '">' . pb_e($p['title']) . '</a></h2>'
        . '<p class="pb-card-excerpt">' . pb_e($excerpt) . '</p>'
        . '<p class="pb-meta"><time datetime="' . pb_e(str_replace(' ', 'T', $p['published_at']) . 'Z') . '">'
        . pb_e(pb_format_date($p['published_at'])) . '</time> &middot; ' . pb_reading_minutes($p['body']) . ' min read</p>'
        . '</div></article>';
    return pb_apply_filters('pb_card_html', $h, $p);
}

// Pinned post: a wide card at the top of the blog home.
function pb_featured_html($p) {
    $url = pb_url('post', $p['slug']);
    $img = pb_post_image($p);
    $excerpt = $p['excerpt'] !== '' ? $p['excerpt'] : pb_text_excerpt($p['body'], 220);
    $h = '<article class="pb-featured' . ($img === '' ? ' pb-featured-noimg' : '') . '">';
    if ($img !== '') {
        $h .= '<a class="pb-featured-img" href="' . pb_e($url) . '" tabindex="-1" aria-hidden="true"><img src="' . pb_e($img) . '" alt=""' . pb_img_dims($img) . ' fetchpriority="high"></a>';
    }
    $h .= '<div class="pb-featured-body"><span class="pb-featured-label">📌 Featured</span>'
        . '<h2 class="pb-featured-title"><a href="' . pb_e($url) . '">' . pb_e($p['title']) . '</a></h2>'
        . '<p class="pb-card-excerpt">' . pb_e($excerpt) . '</p>'
        . '<p class="pb-meta"><time datetime="' . pb_e(str_replace(' ', 'T', $p['published_at']) . 'Z') . '">'
        . pb_e(pb_format_date($p['published_at'])) . '</time> &middot; ' . pb_reading_minutes($p['body']) . ' min read</p>'
        . '<a class="pb-btn pb-featured-cta" href="' . pb_e($url) . '">Read more &rarr;</a></div></article>';
    return $h;
}

function pb_pagination_html($page, $pages, $type = 'home', $arg = null) {
    if ($pages <= 1) return '';
    $h = '<nav class="pb-pager" aria-label="Pagination">';
    if ($page > 1) $h .= '<a href="' . pb_e(pb_url($type, $arg, $page - 1)) . '" rel="prev">&larr; Newer</a>';
    $h .= '<span>Page ' . (int) $page . ' of ' . (int) $pages . '</span>';
    if ($page < $pages) $h .= '<a href="' . pb_e(pb_url($type, $arg, $page + 1)) . '" rel="next">Older &rarr;</a>';
    return $h . '</nav>';
}
