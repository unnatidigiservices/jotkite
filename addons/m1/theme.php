<?php
/**
 * PostBase M1 ("Mobile One") theme · https://postbase.top
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-PostBase-Commercial
 *
 * One layout for every device:
 *   - a 720px centred column (header, container and footer),
 *   - a 640px centred reading width for content,
 *   - a header of three permanent 100px blocks — logo · action · menu — which
 *     fits a 320–350px phone and simply spreads out up to 720px elsewhere.
 * Colours for header, container, footer and outside are set in
 * Settings → Addons; text colours are picked automatically for contrast.
 */
if (!defined('PB_ROOT')) { http_response_code(403); exit; }

return function (array $page) {
    $s = $page['settings'];
    $color = function ($key, $fallback) use ($s) { return pb_valid_hex($s[$key] ?? '') ?: $fallback; };
    $headerBg = $color('header_bg', '#3a00c2');
    $containerBg = $color('container_bg', '#ffffff');
    $footerBg = $color('footer_bg', '#160845');
    $pageBg = $color('page_bg', '#eef1f7');
    // Outside the column: the PostBase gradient by default. A site that picked its own
    // solid colour before this option existed (M1 1.0.x) keeps that colour.
    $style = pb_setting('addon:m1:page_style');
    if ($style === null) $style = strtolower($pageBg) !== '#eef1f7' ? 'color' : 'gradient';
    $bodyClass = 'pb-page pb-m1' . ($style === 'color' ? '' : ' m1-gradient');
    $headerFg = pb_text_on($headerBg);
    // Links/buttons in the content use the header colour when it is dark enough to read on white.
    $accent = $headerFg === '#ffffff' ? $headerBg : '#3a00c2';
    $vars = '--m1-header-bg:' . $headerBg . ';--m1-header-fg:' . $headerFg
          . ';--m1-container-bg:' . $containerBg . ';--m1-container-fg:' . pb_text_on($containerBg, '#1b2033', '#f3f4f8')
          . ';--m1-footer-bg:' . $footerBg . ';--m1-footer-fg:' . pb_text_on($footerBg)
          . ';--m1-page-bg:' . $pageBg . ';--m1-accent:' . $accent;

    // Block 1 — logo image, or text.
    $logo = trim((string) ($s['logo'] ?? ''));
    $logoText = trim((string) ($s['logo_text'] ?? '')) !== '' ? $s['logo_text'] : $page['blog_title'];
    $logoHtml = $logo !== ''
        ? '<img src="' . pb_e($logo) . '" alt="' . pb_e($page['blog_title']) . '" width="100" height="100">'
        : '<span class="m1-logo-text">' . pb_e($logoText) . '</span>';

    // Block 2 — button, link, text or empty.
    $type = (string) ($s['action_type'] ?? 'button');
    $label = trim((string) ($s['action_label'] ?? ''));
    $url = trim((string) ($s['action_url'] ?? '')) !== '' ? $s['action_url'] : $page['feed_url'];
    $action = '';
    if ($label !== '' && $type === 'button') $action = '<a class="m1-btn" href="' . pb_e($url) . '">' . pb_e($label) . '</a>';
    elseif ($label !== '' && $type === 'link') $action = '<a class="m1-link" href="' . pb_e($url) . '">' . pb_e($label) . '</a>';
    elseif ($label !== '' && $type === 'text') $action = '<span class="m1-text">' . pb_e($label) . '</span>';

    $footerText = trim((string) ($s['footer_text'] ?? '')) !== '' ? pb_e($s['footer_text']) : '&copy; ' . pb_e($page['year']) . ' ' . pb_e($page['blog_title']);
    ?><!DOCTYPE html>
<html lang="<?= pb_e($page['lang']) ?>">
<head>
<?= $page['head'] ?>
</head>
<body class="<?= $bodyClass ?>" style="<?= pb_e($vars) ?>">
<a class="m1-skip" href="#main">Skip to content</a>
<div class="m1-shell">
  <header class="m1-header">
    <div class="m1-header-inner">
      <a class="m1-block m1-logo" href="<?= pb_e($page['blog_url']) ?>" aria-label="<?= pb_e($page['blog_title']) ?> — home"><?= $logoHtml ?></a>
      <div class="m1-block m1-action"><?= $action ?></div>
      <button class="m1-block m1-burger" type="button" aria-expanded="false" aria-controls="m1-menu">
        <span class="m1-burger-icon" aria-hidden="true"><i></i><i></i><i></i></span><span class="m1-sr">Menu</span>
      </button>
    </div>
    <div id="m1-menu" class="m1-menu" hidden>
      <?= pb_nav_html('m1-menu-links') ?>
      <form class="m1-search" role="search" method="get" action="<?= pb_e($page['posts_url'] ?? $page['blog_url']) ?>">
        <?php if (strpos((string) ($page['posts_url'] ?? ''), '?list=1') !== false): ?><input type="hidden" name="list" value="1"><?php endif; ?>
        <label class="m1-sr" for="m1-q">Search posts</label>
        <input id="m1-q" type="search" name="q" placeholder="Search posts…">
        <button type="submit">Search</button>
      </form>
    </div>
  </header>
  <main id="main" class="pb-main m1-main">
<?= $page['content'] ?>
  </main>
  <footer class="m1-footer">
    <div class="m1-footer-inner">
      <?= pb_nav_html('m1-footer-nav') ?>
      <p><?= $footerText ?></p>
      <p class="m1-powered"><?= $page['powered_by'] ?></p>
    </div>
  </footer>
</div>
<script src="<?= pb_e($page['theme_url'] . '/theme.js?v=' . ($page['theme_version'] ?? '1')) ?>" defer></script>
<?= $page['body_end'] ?>
</body>
</html>
<?php
};
