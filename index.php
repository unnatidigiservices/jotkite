<?php
/**
 * JotKite — public blog front controller.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial
 *
 * Routes (pretty URLs via .htaccess, query-string fallback in brackets):
 *   /blog/                         post list          (?page=N)
 *   /blog/page/2/                  post list page 2
 *   /blog/my-post/                 single post        (?p=my-post)
 *   /blog/category/news/           category list      (?c=news)
 *   /blog/feed.xml                 RSS 2.0            (?feed=rss)
 *   /blog/sitemap.xml              XML sitemap        (?feed=sitemap)
 *   /blog/?q=tiles                 search
 */
define('PB_ROOT', __DIR__);
define('PB_BASE_PATH', rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/'));
require PB_ROOT . '/lib/postbase.php';
require PB_ROOT . '/lib/theme.php';
pb_load_plugins();

// ---- route -----------------------------------------------------------------
$route = (string) ($_GET['route'] ?? '');
if ($route === '' && !isset($_GET['p']) && !isset($_GET['c']) && !isset($_GET['feed'])) {
    // No rewrite parameter (e.g. Nginx try_files): derive the route from the request path.
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (PB_BASE_PATH !== '' && strpos($path, PB_BASE_PATH . '/') === 0) $path = substr($path, strlen(PB_BASE_PATH));
    if (!preg_match('~^/(index\.php)?$~', $path)) $route = rawurldecode($path);
}
$route = trim($route, '/');
$basePrefix = trim(PB_BASE_PATH, '/');
if ($basePrefix !== '' && strpos($route, $basePrefix . '/') === 0) $route = substr($route, strlen($basePrefix) + 1);
$view = 'home';
$slug = '';
$page = max(1, (int) ($_GET['page'] ?? 1));
if (isset($_GET['feed'])) {
    $view = $_GET['feed'] === 'sitemap' ? 'sitemap' : 'feed';
} elseif (isset($_GET['p'])) {
    $view = 'post';
    $slug = (string) $_GET['p'];
} elseif (isset($_GET['c'])) {
    $view = 'category';
    $slug = (string) $_GET['c'];
} elseif (isset($_GET['author'])) {
    $view = 'author';
    $slug = (string) $_GET['author'];
} elseif ($route !== '') {
    $parts = explode('/', $route);
    if ($route === 'feed.xml' || $route === 'feed') {
        $view = 'feed';
    } elseif ($route === 'robots.txt' && PB_BASE_PATH === '') {
        $view = 'robots'; // domain-root install with no robots.txt file of its own
    } elseif ($route === 'sitemap.xml') {
        $view = 'sitemap';
    } elseif ($parts[0] === 'page' && isset($parts[1]) && ctype_digit($parts[1]) && count($parts) === 2) {
        $page = max(1, (int) $parts[1]);
        $view = 'list';
    } elseif ($parts[0] === 'posts' && (count($parts) === 1 || (count($parts) === 3 && $parts[1] === 'page' && ctype_digit($parts[2])))) {
        $view = 'list'; // the post list when a Page is the homepage
        if (count($parts) === 3) $page = max(1, (int) $parts[2]);
    } elseif ($parts[0] === 'category' && isset($parts[1])) {
        $view = 'category';
        $slug = $parts[1];
        if (isset($parts[2], $parts[3]) && $parts[2] === 'page' && ctype_digit($parts[3])) $page = max(1, (int) $parts[3]);
        elseif (count($parts) > 2) $view = 'notfound';
    } elseif ($parts[0] === 'author' && isset($parts[1])) { // /author/username/ (+ /page/N/)
        $view = 'author';
        $slug = $parts[1];
        if (isset($parts[2], $parts[3]) && $parts[2] === 'page' && ctype_digit($parts[3])) $page = max(1, (int) $parts[3]);
        elseif (count($parts) > 2) $view = 'notfound';
    } elseif (count($parts) === 1) {
        $view = 'post';
        $slug = $parts[0];
    } else {
        $view = 'notfound';
    }
}

// Homepage: a static Page (Settings → General) or the latest posts.
$front = pb_front_page();
if ($view === 'home' && !empty($_GET['list'])) $view = 'list';
if ($view === 'home' && $front && $page === 1 && trim((string) ($_GET['q'] ?? '')) === '') {
    $view = 'post';
    $slug = $front['slug'];
    $isFront = true;
}
// One address per page: the list's old/extra URLs and the homepage Page's own slug redirect.
$redirect = null;
if ($view === 'list' && !$front && $route !== '' && strpos($route, 'posts') === 0) $redirect = pb_url('home', null, $page);
if ($view === 'list' && $front && strpos($route, 'page/') === 0) $redirect = pb_url('posts', null, $page);
if ($view === 'home' && $front && ($page > 1 || trim((string) ($_GET['q'] ?? '')) !== '')) $view = 'list';
if ($view === 'post' && empty($isFront) && $front && $slug === $front['slug']) $redirect = pb_url('home');
if ($redirect !== null) {
    header('Location: ' . $redirect, true, 301);
    exit;
}
if ($view === 'list') $view = 'home'; // same listing code below

$now = pb_now();
$publicWhere = "p.status = 'published' AND p.published_at <= :now";
$postsOnly = " AND p.type = 'post'"; // pages (About, Contact…) never appear in lists, RSS or prev/next
$listSelect = 'SELECT p.*, u.name AS author_name, u.username AS author_username, u.avatar AS author_avatar, u.bio AS author_bio,
                      u.show_name AS author_show_name, u.show_box AS author_show_box, u.public_page AS author_public_page,
                      c.name AS category_name, c.slug AS category_slug
               FROM posts p JOIN users u ON u.id = p.author_id LEFT JOIN categories c ON c.id = p.category_id';

// ---- robots.txt (only reached when no real robots.txt file exists) ---------
if ($view === 'robots') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow: /admin/\n\nSitemap: " . pb_abs_url(pb_url('sitemap')) . "\n";
    exit;
}

// ---- RSS -------------------------------------------------------------------
if ($view === 'feed') {
    // Summaries only (meta description, else the first 250 characters) plus the
    // permalink — readers and aggregators have to visit the site for the full post.
    $posts = pb_all("$listSelect WHERE $publicWhere$postsOnly ORDER BY p.published_at DESC LIMIT 20", ['now' => $now]);
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
       . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n<channel>\n"
       . '<title>' . pb_e(pb_setting('blog_title')) . "</title>\n"
       . '<link>' . pb_e(pb_abs_url(pb_url())) . "</link>\n"
       . '<description>' . pb_e(pb_setting('blog_description')) . "</description>\n"
       . '<language>' . pb_e(pb_setting('language')) . "</language>\n"
       . '<generator>JotKite ' . PB_HOMEPAGE . "</generator>\n"
       . '<atom:link href="' . pb_e(pb_abs_url(pb_url('feed'))) . '" rel="self" type="application/rss+xml"/>' . "\n";
    foreach ($posts as $p) {
        $link = pb_abs_url(pb_url('post', $p['slug']));
        $summary = $p['seo_description'] !== '' ? $p['seo_description'] : pb_text_excerpt($p['body'], 250);
        $descHtml = '<p>' . pb_e($summary) . '</p><p><a href="' . pb_e($link) . '">Read the full post</a></p>';
        echo "<item>\n<title>" . pb_e($p['title']) . "</title>\n<link>" . pb_e($link) . "</link>\n"
           . '<guid isPermaLink="true">' . pb_e($link) . "</guid>\n"
           . '<pubDate>' . gmdate('D, d M Y H:i:s', strtotime($p['published_at'] . ' UTC')) . " +0000</pubDate>\n"
           . ($p['category_name'] ? '<category>' . pb_e($p['category_name']) . "</category>\n" : '')
           . '<description>' . pb_e($descHtml) . "</description>\n</item>\n";
    }
    echo "</channel>\n</rss>\n";
    exit;
}

// ---- sitemap ---------------------------------------------------------------
if ($view === 'sitemap') {
    $posts = pb_all("SELECT p.slug, p.updated_at, p.published_at FROM posts p WHERE $publicWhere ORDER BY p.published_at DESC", ['now' => $now]);
    $cats = pb_all("SELECT DISTINCT c.slug FROM categories c JOIN posts p ON p.category_id = c.id WHERE $publicWhere", ['now' => $now]);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $last = $posts ? max(array_column($posts, 'updated_at')) : pb_now();
    echo '<url><loc>' . pb_e(pb_abs_url(pb_url())) . '</loc><lastmod>' . substr($last, 0, 10) . "</lastmod></url>\n";
    if ($front) echo '<url><loc>' . pb_e(pb_abs_url(pb_url('posts'))) . '</loc><lastmod>' . substr($last, 0, 10) . "</lastmod></url>\n";
    foreach ($posts as $p) {
        if ($front && $p['slug'] === $front['slug']) continue; // already listed as the homepage
        $mod = max($p['updated_at'], $p['published_at']);
        echo '<url><loc>' . pb_e(pb_abs_url(pb_url('post', $p['slug']))) . '</loc><lastmod>' . substr($mod, 0, 10) . "</lastmod></url>\n";
    }
    foreach ($cats as $c) echo '<url><loc>' . pb_e(pb_abs_url(pb_url('category', $c['slug']))) . "</loc></url>\n";
    // Public author pages of people with at least one published post.
    foreach (pb_all("SELECT DISTINCT u.username, u.public_page FROM users u JOIN posts p ON p.author_id = u.id WHERE u.active = 1 AND $publicWhere$postsOnly", ['now' => $now]) as $u) {
        if (pb_author_url($u) !== '') echo '<url><loc>' . pb_e(pb_abs_url(pb_author_url($u))) . "</loc></url>\n";
    }
    echo "</urlset>\n";
    exit;
}

// ---- single post -----------------------------------------------------------
if ($view === 'post') {
    $post = pb_post_by_slug($slug);
    $preview = false;
    if ($post && !pb_post_is_public($post)) {
        // Unpublished: only people who may see it in the admin get a preview.
        if (isset($_COOKIE[session_name()])) pb_session_start();
        $user = pb_current_user();
        if ($user && pb_can($user, 'post.view', $post)) $preview = true;
        else $post = null;
    }
    if ($post) {
        $url = pb_abs_url(pb_url('post', $post['slug']));
        $desc = $post['seo_description'] !== '' ? $post['seo_description']
              : ($post['excerpt'] !== '' ? $post['excerpt'] : pb_text_excerpt($post['body']));
        $img = $post['cover_image'];      // shown above the article
        // A post with no image at all shows its illustration on top, the same one as in the list and link previews.
        if ($img === '' && $post['type'] === 'post' && pb_setting('fallback_image') === 'illustrations' && stripos($post['body'], '<img') === false) {
            $img = pb_illustration_for($post['slug']);
        }
        $shareImg = pb_post_image($post); // og:image / JSON-LD fallback chain
        $pageTitle = ($post['seo_title'] !== '' ? $post['seo_title'] : $post['title']) . ' | ' . pb_setting('blog_title');

        if ($post['type'] === 'page') {
            // A page: just the title and content — no date, author, category or post navigation.
            ob_start(); ?>
<div class="pb-wrap pb-article-wrap">
<?php if ($preview): ?>
  <div class="pb-preview-bar">Preview &middot; <?= pb_e(pb_status_label($post['status'], $post['published_at'])) ?> page &middot; not visible to the public.
    <a href="<?= pb_e(pb_url('admin', 'view=edit&id=' . (int) $post['id'])) ?>">Back to editor</a></div>
<?php endif; ?>
  <article class="pb-article pb-static">
    <header class="pb-article-head"><h1><?= pb_e($post['title']) ?></h1></header>
<?php if ($img !== ''): ?>
    <figure class="pb-cover"><img src="<?= pb_e($img) ?>" alt="<?= pb_e($post['cover_alt'] !== '' ? $post['cover_alt'] : $post['title']) ?>"<?= pb_img_dims($img) ?> fetchpriority="high"></figure>
<?php endif; ?>
    <div class="pb-content">
<?= pb_apply_filters('pb_post_content', pb_add_img_dims($post['body']), $post) /* sanitized on save; plugins may add to it */ ?>
    </div>
  </article>
</div>
<?php
            $content = ob_get_clean();
            if ($preview) header('Cache-Control: no-store');
            $jsonld = ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $post['title'], 'description' => $desc, 'url' => $url];
            pb_render_page(['title' => $pageTitle, 'description' => $desc, 'canonical' => $url, 'image' => $shareImg,
                'jsonld' => $preview ? null : $jsonld, 'noindex' => $preview], $content);
            exit;
        }

        $jsonld = [
            '@context' => 'https://schema.org', '@type' => 'BlogPosting',
            'headline' => $post['title'], 'description' => $desc, 'mainEntityOfPage' => $url,
            'datePublished' => $post['published_at'] ? str_replace(' ', 'T', $post['published_at']) . 'Z' : null,
            'dateModified' => str_replace(' ', 'T', $post['updated_at']) . 'Z',
        ];
        $au = pb_post_author($post); // name / page / box, as the author's privacy switches allow
        $jsonld['author'] = $au['name'] !== ''
            ? array_filter(['@type' => 'Person', 'name' => $au['name'], 'url' => $au['url'] !== '' ? pb_abs_url($au['url']) : null])
            : ['@type' => 'Organization', 'name' => pb_setting('blog_title')];
        if ($shareImg !== '') $jsonld['image'] = pb_abs_url($shareImg);

        $prev = $next = null;
        if (!$preview) {
            $prev = pb_row("SELECT p.slug, p.title FROM posts p WHERE $publicWhere$postsOnly AND p.published_at < :pub ORDER BY p.published_at DESC LIMIT 1",
                ['now' => $now, 'pub' => $post['published_at']]);
            $next = pb_row("SELECT p.slug, p.title FROM posts p WHERE $publicWhere$postsOnly AND p.published_at > :pub ORDER BY p.published_at ASC LIMIT 1",
                ['now' => $now, 'pub' => $post['published_at']]);
        }

        ob_start(); ?>
<div class="pb-wrap pb-article-wrap">
<?php if ($preview): ?>
  <div class="pb-preview-bar">Preview &middot; <?= pb_e(pb_status_label($post['status'], $post['published_at'])) ?> &middot; not visible to the public.
    <a href="<?= pb_e(pb_url('admin', 'view=edit&id=' . (int) $post['id'])) ?>">Back to editor</a></div>
<?php endif; ?>
  <nav class="pb-crumbs" aria-label="Breadcrumb"><a href="<?= pb_e(pb_url('posts')) ?>"><?= pb_e(pb_setting('blog_title')) ?></a>
<?php if ($post['category_name']): ?> <span aria-hidden="true">/</span> <a href="<?= pb_e(pb_url('category', $post['category_slug'])) ?>"><?= pb_e($post['category_name']) ?></a><?php endif; ?>
  </nav>
  <article class="pb-article">
    <header class="pb-article-head">
      <h1><?= pb_e($post['title']) ?></h1>
      <p class="pb-meta">
<?php if ($au['name'] !== ''): ?>By <?= $au['url'] !== '' ? '<a href="' . pb_e($au['url']) . '" rel="author">' . pb_e($au['name']) . '</a>' : pb_e($au['name']) ?> &middot; <?php endif; ?>
<?php if ($post['published_at']): ?><time datetime="<?= pb_e(str_replace(' ', 'T', $post['published_at']) . 'Z') ?>"><?= pb_e(pb_format_date($post['published_at'])) ?></time> &middot; <?php endif; ?>
        <?= pb_reading_minutes($post['body']) ?> min read</p>
    </header>
<?php if ($img !== ''): ?>
    <figure class="pb-cover"><img src="<?= pb_e($img) ?>" alt="<?= pb_e($post['cover_alt'] !== '' ? $post['cover_alt'] : $post['title']) ?>"<?= pb_img_dims($img) ?> fetchpriority="high"></figure>
<?php endif; ?>
    <div class="pb-content">
<?= pb_apply_filters('pb_post_content', pb_add_img_dims($post['body']), $post) /* sanitized on save; plugins may add to it */ ?>
    </div>
<?= $au['box'] ? pb_author_box_html($au) : '' ?>
  </article>
<?php if ($prev || $next): ?>
  <nav class="pb-prevnext" aria-label="More posts">
    <?php if ($next): ?><a class="pb-next" href="<?= pb_e(pb_url('post', $next['slug'])) ?>"><small>Newer</small><?= pb_e($next['title']) ?></a><?php else: ?><span></span><?php endif; ?>
    <?php if ($prev): ?><a class="pb-prev" href="<?= pb_e(pb_url('post', $prev['slug'])) ?>"><small>Older</small><?= pb_e($prev['title']) ?></a><?php endif; ?>
  </nav>
<?php endif; ?>
  <p class="pb-back"><a href="<?= pb_e(pb_url('posts')) ?>">&larr; All posts</a></p>
</div>
<?php
        $content = ob_get_clean();
        if ($preview) header('Cache-Control: no-store');
        pb_render_page([
            'title' => $pageTitle,
            'description' => $desc, 'canonical' => $url, 'image' => $shareImg, 'type' => 'article',
            'jsonld' => $preview ? null : $jsonld, 'noindex' => $preview,
        ], $content);
        exit;
    }
    $view = 'notfound';
}

// ---- author page: photo, name, long bio and their posts ---------------------
if ($view === 'author') {
    $a = pb_row('SELECT * FROM users WHERE username = ? AND active = 1', [$slug]);
    if (!$a || pb_author_url($a) === '') $view = 'notfound'; // no such author, or their page is private
}
if ($view === 'author') {
    $per = max(1, min(48, (int) pb_setting('posts_per_page')));
    $aw = "$publicWhere$postsOnly AND p.author_id = :aid";
    $total = (int) pb_val("SELECT COUNT(*) FROM posts p WHERE $aw", ['now' => $now, 'aid' => (int) $a['id']]);
    $pages = max(1, (int) ceil($total / $per));
    $page = min($page, $pages);
    $posts = pb_all("$listSelect WHERE $aw ORDER BY p.published_at DESC LIMIT :lim OFFSET :off",
        ['now' => $now, 'aid' => (int) $a['id'], 'lim' => $per, 'off' => ($page - 1) * $per]);
    $aurl = pb_abs_url(pb_url('author', $a['username'], $page));
    ob_start(); ?>
<div class="pb-wrap">
  <header class="pb-author-head">
    <?= pb_avatar_html($a['avatar'], $a['name'], 112) ?>
    <div>
      <h1><?= pb_e($a['name']) ?></h1>
<?php if ($a['bio'] !== ''): ?>      <p class="pb-lead"><?= pb_e($a['bio']) ?></p><?php endif; ?>
    </div>
  </header>
<?php if ($a['bio_long'] !== '' && $page === 1): ?>
  <div class="pb-content pb-author-long"><?= pb_text_to_html($a['bio_long']) ?></div>
<?php endif; ?>
  <h2 class="pb-author-posts"><?= $total ? 'Posts by ' . pb_e($a['name']) : 'No posts yet' ?></h2>
<?php if ($posts): ?>
  <div class="pb-grid">
<?php foreach ($posts as $p) echo pb_card_html($p) . "\n"; ?>
  </div>
<?= pb_pagination_html($page, $pages, 'author', $a['username']) ?>
<?php endif; ?>
</div>
<?php
    $content = ob_get_clean();
    $person = array_filter(['@type' => 'Person', 'name' => $a['name'], 'description' => $a['bio'] !== '' ? $a['bio'] : null,
        'image' => $a['avatar'] !== '' ? pb_abs_url($a['avatar']) : null, 'url' => pb_abs_url(pb_url('author', $a['username']))]);
    pb_render_page([
        'title' => $a['name'] . ($page > 1 ? ' — Page ' . $page : '') . ' | ' . pb_setting('blog_title'),
        'description' => $a['bio'] !== '' ? $a['bio'] : 'Posts by ' . $a['name'] . ' on ' . pb_setting('blog_title') . '.',
        'canonical' => $aurl, 'image' => $a['avatar'], 'type' => 'profile',
        'jsonld' => ['@context' => 'https://schema.org', '@type' => 'ProfilePage', 'mainEntity' => $person],
    ], $content);
    exit;
}

// ---- lists (home, category, search) ----------------------------------------
$category = null;
if ($view === 'category') {
    $category = pb_row('SELECT * FROM categories WHERE slug = ?', [$slug]);
    if (!$category) $view = 'notfound';
}

if ($view === 'notfound') {
    http_response_code(404);
    pb_render_page(['title' => 'Not found | ' . pb_setting('blog_title'), 'noindex' => true],
        '<div class="pb-wrap pb-empty"><h1>Post not found</h1><p>It may have moved or been unpublished.</p>'
        . '<p><a class="pb-btn" href="' . pb_e(pb_url('posts')) . '">Browse all posts</a></p></div>');
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$per = max(1, min(48, (int) pb_setting('posts_per_page')));
$where = $publicWhere . $postsOnly;
$params = ['now' => $now];
// Blog home (not a category or search): pinned posts go in the Featured block
// on page 1 and are left out of the chronological list so they never repeat.
$pinned = [];
if (!$category && $q === '') {
    if ($page === 1) $pinned = pb_all("$listSelect WHERE $publicWhere$postsOnly AND p.pinned = 1 ORDER BY p.published_at DESC LIMIT 3", ['now' => $now]);
    $where .= ' AND p.pinned = 0';
}
if ($category) {
    $where .= ' AND p.category_id = :cat';
    $params['cat'] = (int) $category['id'];
}
if ($q !== '') {
    $where .= " AND (p.title LIKE :q ESCAPE '\\' OR p.body LIKE :q ESCAPE '\\' OR p.excerpt LIKE :q ESCAPE '\\')";
    $params['q'] = '%' . addcslashes($q, '%_\\') . '%';
}
$total = (int) pb_val("SELECT COUNT(*) FROM posts p WHERE $where", $params);
$pages = max(1, (int) ceil($total / $per));
if ($page > $pages && $total > 0) { $page = $pages; }
$posts = pb_all("$listSelect WHERE $where ORDER BY p.published_at DESC LIMIT :lim OFFSET :off",
    $params + ['lim' => $per, 'off' => ($page - 1) * $per]);
$cats = pb_all("SELECT c.name, c.slug, COUNT(p.id) AS n FROM categories c JOIN posts p ON p.category_id = c.id
                WHERE $publicWhere$postsOnly GROUP BY c.id ORDER BY c.sort, c.name", ['now' => $now]);

$heading = $category ? $category['name'] : pb_setting('blog_title');
$intro = $category ? $category['description'] : pb_setting('blog_description');

ob_start(); ?>
<div class="pb-wrap">
  <header class="pb-list-head">
    <h1><?= pb_e($heading) ?></h1>
<?php if ($intro !== ''): ?>    <p class="pb-lead"><?= pb_e($intro) ?></p><?php endif; ?>
    <form class="pb-search" role="search" method="get" action="<?= pb_e(pb_url('posts')) ?>"><?php if (pb_front_page() && pb_setting('pretty_urls') !== '1'): ?><input type="hidden" name="list" value="1"><?php endif; ?>
      <label class="pb-sr" for="pb-q">Search posts</label>
      <input id="pb-q" type="search" name="q" value="<?= pb_e($q) ?>" placeholder="Search posts…">
      <button type="submit">Search</button>
    </form>
<?php if ($cats): ?>
    <nav class="pb-cats" aria-label="Categories">
      <a href="<?= pb_e(pb_url('posts')) ?>"<?= !$category ? ' aria-current="page"' : '' ?>>All</a>
<?php foreach ($cats as $c): ?>
      <a href="<?= pb_e(pb_url('category', $c['slug'])) ?>"<?= $category && $category['slug'] === $c['slug'] ? ' aria-current="page"' : '' ?>><?= pb_e($c['name']) ?></a>
<?php endforeach; ?>
    </nav>
<?php endif; ?>
  </header>
<?php if ($q !== ''): ?>
  <p class="pb-meta"><?= $total ?> result<?= $total === 1 ? '' : 's' ?> for &ldquo;<?= pb_e($q) ?>&rdquo; &middot; <a href="<?= pb_e(pb_url('posts')) ?>">Clear</a></p>
<?php endif; ?>
<?php foreach ($pinned as $p) echo pb_featured_html($p) . "\n"; ?>
<?php if ($posts): ?>
  <div class="pb-grid">
<?php foreach ($posts as $p) echo pb_card_html($p) . "\n"; ?>
  </div>
<?php if ($q === '') echo pb_pagination_html($page, $pages, $category ? 'category' : 'posts', $category ? $category['slug'] : null); ?>
<?php elseif ($pinned): ?>
<?php else: ?>
  <div class="pb-empty"><p><?= $q !== '' ? 'No posts match your search.' : 'No posts yet. Check back soon.' ?></p></div>
<?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$canonical = $category ? pb_abs_url(pb_url('category', $category['slug'], $page)) : pb_abs_url(pb_url('posts', null, $page));
pb_render_page([
    'title' => $heading . ($page > 1 ? ' — Page ' . $page : '') . ($category ? ' | ' . pb_setting('blog_title') : ''),
    'description' => $intro,
    'canonical' => $q === '' ? $canonical : '',
    'noindex' => $q !== '',
    'jsonld' => ['@context' => 'https://schema.org', '@type' => 'Blog', 'name' => pb_setting('blog_title'), 'url' => pb_abs_url(pb_url('posts'))],
], $content);
