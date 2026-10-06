<?php
/**
 * JotKite — engagement: comments, likes and sharing on blog posts.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial
 *
 *   Signed-in writers (any role) comment as themselves: live at once, no limits,
 *   links allowed (rel="nofollow ugc").
 *   Visitors comment with a display name: plain text only (HTML, links, emails
 *   and code are removed), spam traps and rate limits, and an Editor/Admin (or
 *   the post's Author) approves it. After posting they may leave an email to
 *   hear when it's approved and when someone replies.
 *   Replies go one level deep. Likes are per post, one per browser.
 *
 * Public endpoints: index.php?engage=state|comment|notify|like|unsub
 */
if (!defined('PB_ROOT')) { http_response_code(403); exit; }

define('PB_COMMENT_MAX', 2000);      // visitors
define('PB_COMMENT_MAX_USER', 10000); // signed-in writers

function pb_engage_secret() {
    $s = (string) pb_setting('engage_secret');
    if (strlen($s) < 32) { $s = bin2hex(random_bytes(32)); pb_settings_save(['engage_secret' => $s]); }
    return $s;
}
function pb_ip_hash() {
    return substr(hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''), pb_engage_secret()), 0, 32);
}
// Form token: when the page was shown, signed. Stops replayed/scripted posts and too-fast bots.
function pb_comment_form_token($postId) {
    $t = time();
    return $t . '.' . substr(hash_hmac('sha256', $t . '|' . (int) $postId, pb_engage_secret()), 0, 32);
}
function pb_comment_form_token_check($token, $postId, $minSeconds = 3) {
    $parts = explode('.', (string) $token, 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0])) return 'Please reload the page and try again.';
    if (!hash_equals(substr(hash_hmac('sha256', $parts[0] . '|' . (int) $postId, pb_engage_secret()), 0, 32), $parts[1])) return 'Please reload the page and try again.';
    $age = time() - (int) $parts[0];
    if ($age > 3 * 86400) return 'This page has been open a long time. Reload it and post again.';
    if ($age < $minSeconds) return 'That was quick! Wait a moment and press Post again.';
    return null;
}

function pb_comments_enabled() { return pb_setting('comments_enabled') === '1'; }
function pb_likes_enabled() { return pb_setting('likes_enabled') === '1'; }
// Comments allowed on this post right now (site switch, post switch, age limit).
function pb_comments_open($post) {
    if (!pb_comments_enabled() || ($post['type'] ?? 'post') !== 'post' || !pb_post_is_public($post)) return false;
    if (isset($post['comments_open']) && (int) $post['comments_open'] !== 1) return false;
    $days = (int) pb_setting('comments_close_days');
    return !($days > 0 && $post['published_at'] && strtotime($post['published_at'] . ' UTC') < time() - $days * 86400);
}

// ---- cleaning ---------------------------------------------------------------
// Plain text from a visitor: no HTML, links, email addresses or code.
// $removed counts what was taken out, so the visitor can be told.
function pb_comment_clean($text, &$removed = 0, $max = PB_COMMENT_MAX) {
    $removed = 0;
    $t = str_replace(["\r\n", "\r"], "\n", (string) $text);
    if (!preg_match('//u', $t)) $t = function_exists('mb_convert_encoding') ? mb_convert_encoding($t, 'UTF-8', 'UTF-8') : '';
    // Invisible and direction-changing characters (used to hide or disguise text), control characters.
    $t = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}\x{00AD}]/u', '', $t);
    $t = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $t);
    $strip = function ($re, $with = '') use (&$t, &$removed) {
        $t = preg_replace($re, $with, $t, -1, $n);
        $removed += $n;
    };
    // Code: fenced blocks and whole script/style elements go; inline `code` keeps its words.
    $strip('/```.*?(```|\z)/s');
    $strip('~<(script|style)\b.*?(</\1\s*>|\z)~is');
    $t = str_replace('`', '', $t);
    // HTML tags and comments ("a < b" is left alone: it isn't a tag).
    $strip('/<!--.*?(-->|\z)/s');
    $strip('~</?[a-z!][^>]*>~i');
    $t = preg_replace('/\[([^\]\n]{1,200})\]\([^)\s]*\)/', '$1', $t, -1, $n); $removed += $n; // [text](link) keeps the text
    // Links, email addresses, bare domains with a real-looking ending.
    $strip('~\b(?:https?|ftp)://\S+~i');
    $strip('~\bwww\.\S+~i');
    $strip('/[^\s@<>()]+@[^\s@<>()]+\.[a-z]{2,}/i');
    $strip('~\b[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.(?:com|net|org|in|co|io|info|biz|xyz|top|me|app|dev|site|online|store|shop|link|click|live|life|ru|cn|uk|us|de|ly|gl|gg|tk|ml|ga|cf|pw|cc|ws|tv|ai)\b(?:/\S*)?~i');
    // Tidy whitespace: trimmed lines, at most one blank line in a row.
    $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
    $t = preg_replace('/ *\n */', "\n", $t);
    $t = trim(preg_replace("/\n{3,}/", "\n\n", $t));
    return function_exists('mb_substr') ? mb_substr($t, 0, $max) : substr($t, 0, $max);
}
// A visitor's display name: 2–40 characters, no links, not a staff name.
function pb_comment_name_check($name, &$clean) {
    $removed = 0;
    $clean = trim(preg_replace('/\s+/u', ' ', str_replace('@', '', pb_comment_clean($name, $removed, 60))));
    $len = function_exists('mb_strlen') ? mb_strlen($clean) : strlen($clean);
    if ($len < 2) return 'Add your name (at least 2 letters).';
    if ($len > 40) return 'Use a shorter name (40 characters at most).';
    if ($removed) return 'Names can\'t contain links or email addresses.';
    $low = function_exists('mb_strtolower') ? mb_strtolower($clean) : strtolower($clean);
    $taken = in_array($low, ['admin', 'administrator', 'editor', 'moderator', 'author', 'owner', 'staff', 'support', 'jotkite'], true)
          || $low === (function_exists('mb_strtolower') ? mb_strtolower((string) pb_setting('blog_title')) : strtolower((string) pb_setting('blog_title')))
          || (int) pb_val('SELECT COUNT(*) FROM users WHERE active = 1 AND lower(name) = ?', [$low]) > 0;
    return $taken ? 'That name belongs to someone on the team. Please use your own.' : null;
}
// Stored comment text → HTML. Writers' links are clickable; visitors' have none left.
function pb_comment_body_html($body, $links) {
    if ($links) return pb_text_to_html($body);
    $out = '';
    foreach (preg_split("/\n{2,}/", trim((string) $body)) as $para) $out .= '<p>' . nl2br(pb_e($para), false) . '</p>';
    return $out;
}

// ---- reading ----------------------------------------------------------------
function pb_comment_by_id($id) {
    return pb_row('SELECT c.*, u.avatar AS user_avatar, u.role AS user_role, u.email AS user_email
                   FROM comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.id = ?', [(int) $id]);
}
function pb_comment_count($postId) {
    return (int) pb_val("SELECT COUNT(*) FROM comments WHERE post_id = ? AND status = 'approved'", [(int) $postId]);
}
function pb_like_count($postId) {
    return (int) pb_val('SELECT COUNT(*) FROM post_likes WHERE post_id = ?', [(int) $postId]);
}
// Who may approve, hide or delete comments on $post (no $post: on any of their posts).
function pb_can_moderate($user, $post = null) {
    if (!$user) return false;
    if (in_array($user['role'], ['editor', 'admin'], true)) return true;
    if ($user['role'] !== 'author') return false;
    return $post === null || (int) ($post['author_id'] ?? 0) === (int) $user['id'];
}

// ---- writing ----------------------------------------------------------------
/**
 * Add a comment. $user: the signed-in writer, or null for a visitor.
 * $in: body, name, parent, website (honeypot), t (form token).
 * Returns ['comment' => row] or ['error' => message].
 */
function pb_comment_add($post, $user, array $in) {
    if (!$post || !pb_comments_open($post)) return ['error' => 'Comments are closed on this post.'];
    $removed = 0;
    if ($user) {
        $body = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['body'] ?? '')));
        $body = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $body);
        $body = function_exists('mb_substr') ? mb_substr($body, 0, PB_COMMENT_MAX_USER) : substr($body, 0, PB_COMMENT_MAX_USER);
        $name = (string) $user['name'];
    } else {
        if (trim((string) ($in['website'] ?? '')) !== '') return ['error' => 'Your comment could not be posted.']; // honeypot: only bots fill it
        $err = pb_comment_form_token_check($in['t'] ?? '', $post['id']);
        if ($err) return ['error' => $err];
        $err = pb_comment_name_check($in['name'] ?? '', $name);
        if ($err) return ['error' => $err];
        $body = pb_comment_clean($in['body'] ?? '', $removed);
    }
    if ($body === '') return ['error' => $removed ? 'Links, email addresses and code aren\'t allowed, and nothing else was left. Write your comment in words.' : 'Write a comment first.'];

    $ip = pb_ip_hash();
    if (!$user) {
        $since = gmdate('Y-m-d H:i:s', time() - 600);
        if ((int) pb_val('SELECT COUNT(*) FROM comments WHERE ip_hash = ? AND created_at > ? AND user_id IS NULL', [$ip, $since]) >= 3) return ['error' => 'You\'ve posted several comments just now. Please wait a few minutes.'];
        if ((int) pb_val("SELECT COUNT(*) FROM comments WHERE ip_hash = ? AND status = 'pending'", [$ip]) >= 10) return ['error' => 'You have many comments waiting for approval. Please wait until they\'re checked.'];
        if (pb_val('SELECT id FROM comments WHERE post_id = ? AND body = ? AND created_at > ?', [$post['id'], $body, gmdate('Y-m-d H:i:s', time() - 86400)])) return ['error' => 'You already posted that comment.'];
    }
    $err = pb_apply_filters('pb_comment_check', '', ['body' => $body, 'name' => $name, 'user' => $user], $post);
    if (is_string($err) && $err !== '') return ['error' => $err];

    // One level of replies: a reply to a reply joins the same thread.
    $parentId = (int) ($in['parent'] ?? 0);
    if ($parentId) {
        $parent = pb_row("SELECT id, parent_id FROM comments WHERE id = ? AND post_id = ? AND status = 'approved'", [$parentId, $post['id']]);
        $parentId = $parent ? (int) ($parent['parent_id'] ?: $parent['id']) : 0;
    }
    $live = $user || pb_setting('comments_moderation') !== 'public';
    pb_q('INSERT INTO comments (post_id, parent_id, user_id, name, body, status, token, ip_hash, created_at, approved_at)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [(int) $post['id'], $parentId ?: null, $user ? (int) $user['id'] : null, $name, $body, $live ? 'approved' : 'pending',
         bin2hex(random_bytes(16)), $ip, pb_now(), $live ? pb_now() : null]);
    $c = pb_comment_by_id((int) pb_db()->lastInsertId());
    $c['removed'] = $removed;
    pb_do_action('pb_comment_saved', $c, $post);
    if ($live) pb_comment_approved($c, $post, $user);
    else pb_comment_tell_moderators($post);
    // Visitors' email addresses are kept for 6 months at most.
    pb_q("UPDATE comments SET email = '', notify = 0 WHERE email != '' AND created_at < ?", [gmdate('Y-m-d H:i:s', time() - 183 * 86400)]);
    return ['comment' => $c];
}

// Approve / spam / trash / restore. Returns null or an error.
function pb_comment_set_status($user, $c, $status) {
    if (!in_array($status, ['approved', 'pending', 'spam', 'trash'], true)) return 'Unknown action.';
    $post = $c ? pb_post_by_id($c['post_id']) : null;
    if (!$c || !$post || !pb_can_moderate($user, $post)) return 'You can\'t moderate this comment.';
    if ($c['status'] === $status) return null;
    pb_q('UPDATE comments SET status = ?, approved_at = CASE WHEN ? = \'approved\' THEN COALESCE(approved_at, ?) ELSE approved_at END WHERE id = ?',
        [$status, $status, pb_now(), (int) $c['id']]);
    $c2 = pb_comment_by_id($c['id']);
    if ($status === 'approved' && $c['approved_at'] === null) pb_comment_approved($c2, $post, $user);
    return null;
}
function pb_comment_delete($user, $c) {
    $post = $c ? pb_post_by_id($c['post_id']) : null;
    if (!$c || !$post || !pb_can_moderate($user, $post)) return 'You can\'t moderate this comment.';
    pb_q('DELETE FROM comments WHERE id = ?', [(int) $c['id']]); // replies go with it
    return null;
}

// A comment went live (first time only): tell the people waiting to hear.
function pb_comment_approved($c, $post, $byUser = null) {
    pb_do_action('pb_comment_approved', $c, $post);
    if (!pb_mail_on()) return;
    $link = pb_abs_url(pb_url('post', $post['slug'])) . '#comment-' . (int) $c['id'];
    $sent = [];
    // The visitor who wrote it, if they asked.
    if (pb_comment_mail_live($c, $post)) $sent[strtolower($c['email'])] = true;
    // The person replied to.
    if ($c['parent_id']) {
        $p = pb_comment_by_id($c['parent_id']);
        $to = $p ? ($p['user_id'] ? (string) $p['user_email'] : ((int) $p['notify'] === 1 ? (string) $p['email'] : '')) : '';
        $self = $p && (($p['user_id'] && (int) $p['user_id'] === (int) ($c['user_id'] ?? 0)) || ($to !== '' && strcasecmp($to, (string) $c['email']) === 0));
        if ($to !== '' && !$self && !isset($sent[strtolower($to)])) {
            pb_mail_later($to, $c['name'] . ' replied to your comment',
                "Hi {$p['name']},\n\n{$c['name']} replied to your comment on \"{$post['title']}\":\n\n" . pb_mail_quote($c['body']) . "\n\nReply or read the conversation: $link",
                $p['user_id'] ? [] : ['unsubscribe' => pb_unsub_url($p)]);
        }
    }
}
// "Your comment is live", to a visitor who left their email. True when queued.
function pb_comment_mail_live($c, $post) {
    if ((int) $c['notify'] !== 1 || $c['email'] === '' || $c['user_id']) return false;
    $link = pb_abs_url(pb_url('post', $post['slug'])) . '#comment-' . (int) $c['id'];
    pb_mail_later($c['email'], 'Your comment is live on ' . pb_setting('blog_title'),
        "Hi {$c['name']},\n\nYour comment on \"{$post['title']}\" has been approved:\n\n" . pb_mail_quote($c['body']) . "\n\nSee it here: $link",
        ['unsubscribe' => pb_unsub_url($c)]);
    return true;
}
function pb_mail_quote($body) {
    $b = function_exists('mb_substr') ? mb_substr((string) $body, 0, 600) : substr((string) $body, 0, 600);
    return '> ' . str_replace("\n", "\n> ", $b) . (strlen((string) $body) > strlen($b) ? ' …' : '');
}
function pb_unsub_url($c) {
    return pb_abs_url(PB_BASE_PATH . '/index.php?engage=unsub&id=' . (int) $c['id'] . '&k=' . $c['token']);
}
// "Comments waiting": the post's Author (if they moderate) or else Editors and
// Admins. At most one email an hour per person.
function pb_comment_tell_moderators($post) {
    if (!pb_mail_on() || pb_setting('comments_notify') !== '1') return;
    $author = pb_user_by_id($post['author_id']);
    $people = $author && $author['role'] === 'author' ? [$author]
        : pb_all("SELECT * FROM users WHERE active = 1 AND role IN ('editor', 'admin')");
    if ($author && $author['role'] === 'author') $people = array_merge($people, pb_all("SELECT * FROM users WHERE active = 1 AND role = 'admin'"));
    $waiting = (int) pb_val("SELECT COUNT(*) FROM comments WHERE status = 'pending'");
    $url = pb_abs_url(PB_BASE_PATH . '/admin/?view=comments');
    $done = [];
    foreach ($people as $u) {
        if (!pb_mail_deliverable($u['email']) || isset($done[$u['id']])) continue;
        $done[$u['id']] = true;
        $key = 'mail_pending_sent:' . (int) $u['id'];
        if ((int) pb_setting($key) > time() - 3600) continue;
        pb_settings_save([$key => (string) time()]);
        pb_mail_later($u['email'], 'New comment waiting on ' . pb_setting('blog_title'),
            "Hi {$u['name']},\n\nA new comment on \"{$post['title']}\" is waiting for approval" . ($waiting > 1 ? " ($waiting comments are waiting in all)" : '') . ".\n\nApprove or remove: $url");
    }
}

// ---- likes ------------------------------------------------------------------
// Toggle a like. $visitor: the browser's random id. Several people can share an
// internet connection, so one address may like a post up to 20 times.
function pb_like_toggle($post, $visitor) {
    $v = hash('sha256', $visitor . '|' . pb_engage_secret());
    if (pb_val('SELECT 1 FROM post_likes WHERE post_id = ? AND visitor = ?', [$post['id'], $v])) {
        pb_q('DELETE FROM post_likes WHERE post_id = ? AND visitor = ?', [$post['id'], $v]);
        return false;
    }
    $ip = pb_ip_hash();
    if ((int) pb_val('SELECT COUNT(*) FROM post_likes WHERE post_id = ? AND ip_hash = ?', [$post['id'], $ip]) >= 20) return null;
    pb_q('INSERT INTO post_likes (post_id, visitor, ip_hash, created_at) VALUES (?, ?, ?, ?)', [$post['id'], $v, $ip, pb_now()]);
    return true;
}
function pb_liked($postId, $visitor) {
    return (bool) pb_val('SELECT 1 FROM post_likes WHERE post_id = ? AND visitor = ?', [(int) $postId, hash('sha256', $visitor . '|' . pb_engage_secret())]);
}

// ---- HTML -------------------------------------------------------------------
function pb_comment_html($c, $post, $canReply) {
    $staff = $c['user_id'] && in_array($c['user_role'] ?? '', ['author', 'editor', 'admin'], true);
    $isAuthor = $c['user_id'] && (int) $c['user_id'] === (int) $post['author_id'];
    $badge = $isAuthor ? 'Author' : ($staff ? 'Team' : '');
    $when = str_replace(' ', 'T', $c['created_at']) . 'Z';
    $h = '<li class="pb-comment' . ($c['status'] === 'pending' ? ' is-pending' : '') . '" id="comment-' . (int) $c['id'] . '" data-id="' . (int) $c['id'] . '">'
       . '<div class="pb-comment-head">' . pb_avatar_html($c['user_avatar'] ?? '', $c['name'], 36)
       . '<span class="pb-comment-name">' . pb_e($c['name']) . '</span>'
       . ($badge !== '' ? '<span class="pb-comment-badge">' . $badge . '</span>' : '')
       . '<a class="pb-comment-time" href="#comment-' . (int) $c['id'] . '"><time datetime="' . pb_e($when) . '">' . pb_e(pb_format_date($c['created_at'], 'j M Y')) . '</time></a></div>'
       . '<div class="pb-comment-body">' . pb_comment_body_html($c['body'], (bool) $c['user_id']) . '</div>'
       . ($c['status'] === 'pending' ? '<p class="pb-comment-wait">Waiting for approval · only you can see this</p>' : '')
       . ($canReply && $c['status'] === 'approved' ? '<button type="button" class="pb-comment-reply" data-reply="' . (int) $c['id'] . '" data-name="' . pb_e($c['name']) . '">Reply</button>' : '');
    return pb_apply_filters('pb_comment_html', $h, $c, $post); // the caller closes </li> after any replies
}
// The Share button's icon: a little kite with its tail (JotKite's mark), in the text colour.
function pb_kite_icon() {
    return '<svg class="pb-kite" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">'
         . '<path d="M15.5 1.8 21.6 7.4 15.5 15.2 9.4 7.4Z" fill="currentColor" fill-opacity=".14" stroke-width="1.8"/>'
         . '<path d="M9.4 7.4h12.2M15.5 1.8v13.4" stroke-width="1.1"/>'
         . '<path d="M15.5 15.2c-.4 2.4-2.6 2.5-4.2 3.5s-2.6 3.3-6.8 3.4" stroke-width="1.5"/>'
         . '<path d="m10.4 17.6.6 2.2M6.6 20.6l1.1 1.9" stroke-width="1.5"/></svg>';
}
// The Like button's icon: a smiling kite. Outlined until liked, then it fills with
// the JotKite gradient and takes off a little (blog.css).
function pb_smile_kite_icon() {
    return '<svg class="pb-like-kite" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">'
         . '<defs><linearGradient id="pbKiteGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#1f4bff"/><stop offset="1" stop-color="#8b2cf6"/></linearGradient></defs>'
         . '<path class="k-tail" d="M12 18.4c.4 1.5-1.3 2-1 3.6" fill="none" stroke-width="1.4" stroke-linecap="round"/>'
         . '<path class="k-bow" d="m10.1 20.1 1.7.5" fill="none" stroke-width="1.4" stroke-linecap="round"/>'
         . '<path class="k-body" d="M12 1.6 20.2 8.8 12 18.4 3.8 8.8Z" stroke-width="1.7" stroke-linejoin="round"/>'
         . '<circle class="k-eye" cx="9.5" cy="8.4" r="1.05"/><circle class="k-eye" cx="14.5" cy="8.4" r="1.05"/>'
         . '<path class="k-smile" d="M9.2 11.3q2.8 2.6 5.6 0" fill="none" stroke-width="1.5" stroke-linecap="round"/></svg>';
}
function pb_share_links($post) {
    $url = pb_abs_url(pb_url('post', $post['slug']));
    $t = rawurlencode($post['title']);
    $u = rawurlencode($url);
    return [
        'WhatsApp' => 'https://wa.me/?text=' . $t . '%20' . $u,
        'Facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $u,
        'X' => 'https://twitter.com/intent/tweet?url=' . $u . '&text=' . $t,
        'LinkedIn' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $u,
        'Telegram' => 'https://t.me/share/url?url=' . $u . '&text=' . $t,
        'Email' => 'mailto:?subject=' . $t . '&body=' . $u,
    ];
}
// The block under a post: like + share, the comments and the comment form.
function pb_engage_html($post) {
    if (($post['type'] ?? 'post') !== 'post' || !pb_post_is_public($post)) return '';
    $likes = pb_likes_enabled();
    $share = pb_setting('share_enabled') === '1';
    $open = pb_comments_open($post);
    $rows = pb_comments_enabled() ? pb_all("SELECT c.*, u.avatar AS user_avatar, u.role AS user_role FROM comments c LEFT JOIN users u ON u.id = c.user_id
                                           WHERE c.post_id = ? AND c.status = 'approved' ORDER BY c.id", [(int) $post['id']]) : [];
    if (!$likes && !$share && !$open && !$rows) return '';
    $api = PB_BASE_PATH . '/index.php?engage=';
    $url = pb_abs_url(pb_url('post', $post['slug']));
    $h = '<section class="pb-engage" id="comments" data-post="' . (int) $post['id'] . '" data-api="' . pb_e($api) . '" data-url="' . pb_e($url) . '" data-title="' . pb_e($post['title']) . '">';
    if ($likes || $share) {
        $h .= '<div class="pb-engage-bar">';
        if ($likes) {
            $n = pb_like_count($post['id']);
            $h .= '<button type="button" class="pb-like" data-like aria-pressed="false">' . pb_smile_kite_icon()
                . '<span data-like-label>Like</span><span class="pb-like-n" data-like-n' . ($n ? '' : ' hidden') . '>' . $n . '</span></button>';
        }
        if ($share) {
            $h .= '<div class="pb-share"><button type="button" class="pb-share-btn" data-share aria-expanded="false" aria-controls="pbShareMenu">' . pb_kite_icon() . 'Share</button>'
                . '<div class="pb-share-menu" id="pbShareMenu" data-share-menu hidden>';
            foreach (pb_share_links($post) as $label => $href) {
                $h .= '<a href="' . pb_e($href) . '"' . ($label === 'Email' ? '' : ' target="_blank" rel="noopener nofollow"') . '>' . $label . '</a>';
            }
            $h .= '<button type="button" data-copy>Copy link</button></div></div>';
        }
        $h .= '</div>';
    }
    if (pb_comments_enabled() && ($open || $rows)) {
        $n = count($rows);
        $h .= '<h2 class="pb-comments-title" data-count="' . $n . '">' . ($n ? $n . ' comment' . ($n === 1 ? '' : 's') : 'Comments') . '</h2>';
        $by = [];
        foreach ($rows as $r) $by[(int) ($r['parent_id'] ?? 0)][] = $r;
        $h .= '<ol class="pb-comments" data-comments>';
        foreach ($by[0] ?? [] as $c) {
            $h .= pb_comment_html($c, $post, $open);
            $h .= '<ol class="pb-replies" data-replies="' . (int) $c['id'] . '">';
            foreach ($by[(int) $c['id']] ?? [] as $r) $h .= pb_comment_html($r, $post, false) . '</li>';
            $h .= '</ol></li>';
        }
        $h .= '</ol>';
        if (!$n && $open) $h .= '<p class="pb-comments-empty pb-muted" data-empty>No comments yet. Start the conversation.</p>';
        if ($open) {
            $moderated = pb_setting('comments_moderation') === 'public';
            $note = ($_GET['comment'] ?? '') === 'pending' ? '<p class="pb-comment-msg is-ok" role="status">Thanks! Your comment will appear once it\'s approved.</p>' : '';
            $h .= '<form class="pb-comment-form" id="pbCommentForm" method="post" action="' . pb_e($api . 'comment') . '" data-comment-form novalidate>'
                . '<h3 class="pb-comment-form-title" data-form-title>Leave a comment</h3>'
                . '<p class="pb-replying" data-replying hidden><span data-replying-to></span> <button type="button" data-cancel-reply>Cancel</button></p>'
                . '<input type="hidden" name="post" value="' . (int) $post['id'] . '"><input type="hidden" name="parent" value="0">'
                . '<input type="hidden" name="t" value="' . pb_e(pb_comment_form_token($post['id'])) . '">'
                . '<p class="pb-comment-as" data-as hidden></p>'
                . '<label class="pb-comment-field" data-name-field>Your name<input name="name" maxlength="40" autocomplete="name" required></label>'
                . '<label class="pb-comment-field">Comment<textarea name="body" rows="4" maxlength="' . PB_COMMENT_MAX . '" required data-body></textarea></label>'
                . '<div class="pb-hp" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>'
                . pb_capture_action('pb_comment_form', $post)
                . '<p class="pb-comment-rules" data-rules>Plain text only: links, email addresses and code are removed.' . ($moderated ? ' Comments appear after a quick check.' : '') . '</p>'
                . '<button type="submit" class="pb-btn pb-comment-submit">Post comment</button>'
                . '<div data-form-msg>' . $note . '</div></form>';
        } else {
            $h .= '<p class="pb-comments-closed pb-muted">Comments are closed.</p>';
        }
    }
    return $h . '</section>';
}

// ---- public endpoints ---------------------------------------------------------
function pb_engage_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
// The signed-in writer, when there is one and (for changes) the request carries their token.
function pb_engage_user($needCsrf) {
    if (!isset($_COOKIE[session_name()])) return null;
    pb_session_start();
    $u = pb_current_user();
    if (!$u || !$needCsrf) return $u;
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? ''));
    return $sent !== '' && !empty($_SESSION['pb_csrf']) && hash_equals($_SESSION['pb_csrf'], $sent) ? $u : null;
}
function pb_engage_post($id) {
    $p = pb_post_by_id((int) $id);
    return $p && $p['type'] === 'post' && pb_post_is_public($p) ? $p : null;
}
function pb_engage_route($action) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $wantsJson = stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

    if ($action === 'state') {
        $post = pb_engage_post($_GET['post'] ?? 0);
        if (!$post) pb_engage_json(['error' => 'Not found.'], 404);
        $u = pb_engage_user(false);
        $v = (string) ($_GET['v'] ?? '');
        pb_engage_json([
            'user' => $u ? ['name' => $u['name'], 'moderate' => pb_can_moderate($u, $post)] : null,
            'csrf' => $u ? pb_csrf_token() : null,
            'liked' => preg_match('/^[a-f0-9]{16,64}$/', $v) ? pb_liked($post['id'], $v) : false,
            'likes' => pb_like_count($post['id']),
            'mail' => pb_mail_on(),
            'open' => pb_comments_open($post),
        ]);
    }
    if ($action === 'unsub') {
        $c = pb_row('SELECT * FROM comments WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
        $ok = $c && hash_equals((string) $c['token'], (string) ($_GET['k'] ?? ''));
        // A GET only shows the button (mail scanners open links); the POST — or a mail app's one-click — unsubscribes.
        if ($ok && $isPost) {
            if ($c['email'] !== '') pb_q("UPDATE comments SET notify = 0, email = '' WHERE email = ? COLLATE NOCASE", [$c['email']]);
            pb_q("UPDATE comments SET notify = 0, email = '' WHERE id = ?", [(int) $c['id']]);
        }
        $msg = !$ok ? '<h1>Link not recognised</h1><p>This unsubscribe link is incomplete or too old. Nothing was changed.</p>'
             : ($isPost ? '<h1>You\'re unsubscribed</h1><p>We won\'t email you about comments again, and your email address has been deleted.</p>'
             : '<h1>Stop comment emails?</h1><p>You\'ll no longer hear when comments are approved or answered, and we\'ll delete your email address.</p>'
               . '<form method="post"><button class="pb-btn" type="submit">Unsubscribe</button></form>');
        header('Cache-Control: no-store');
        pb_render_page(['title' => 'Email notifications | ' . pb_setting('blog_title'), 'noindex' => true], '<div class="pb-wrap pb-empty">' . $msg . '</div>');
        exit;
    }
    if (!$isPost) pb_engage_json(['error' => 'Use POST.'], 405);

    if ($action === 'like') {
        $post = pb_engage_post($_POST['post'] ?? 0);
        $v = (string) ($_POST['v'] ?? '');
        if (!$post || !pb_likes_enabled() || !preg_match('/^[a-f0-9]{16,64}$/', $v)) pb_engage_json(['error' => 'Not available.'], 400);
        $liked = pb_like_toggle($post, $v);
        pb_engage_json(['liked' => $liked === null ? false : $liked, 'likes' => pb_like_count($post['id']), 'limited' => $liked === null]);
    }
    if ($action === 'comment') {
        $post = pb_engage_post($_POST['post'] ?? 0);
        $user = pb_engage_user(true);
        $r = $post ? pb_comment_add($post, $user, $_POST) : ['error' => 'Comments are closed on this post.'];
        if (!$wantsJson) { // no JavaScript: back to the post
            $to = $post ? pb_url('post', $post['slug']) : pb_url();
            header('Location: ' . $to . (isset($r['comment']) && $r['comment']['status'] === 'pending' ? (strpos($to, '?') === false ? '?' : '&') . 'comment=pending' : '') . '#comments', true, 303);
            exit;
        }
        if (isset($r['error'])) pb_engage_json(['error' => $r['error']], 422);
        $c = $r['comment'];
        pb_engage_json([
            'id' => (int) $c['id'], 'status' => $c['status'], 'parent' => (int) ($c['parent_id'] ?? 0),
            'html' => pb_comment_html($c, $post, !$c['parent_id'] && $c['status'] === 'approved') . ($c['parent_id'] ? '' : '<ol class="pb-replies" data-replies="' . (int) $c['id'] . '"></ol>') . '</li>',
            'key' => $c['status'] === 'pending' ? $c['token'] : null, // lets the visitor add an email to this comment
            'removed' => (int) $c['removed'],
        ]);
    }
    if ($action === 'notify') {
        $c = pb_row('SELECT * FROM comments WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        $email = trim((string) ($_POST['email'] ?? ''));
        if (!$c || $c['user_id'] || !hash_equals((string) $c['token'], (string) ($_POST['key'] ?? ''))) pb_engage_json(['error' => 'Please reload the page and try again.'], 400);
        if (!pb_mail_on()) pb_engage_json(['error' => 'Email notifications are switched off on this site.'], 400);
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) pb_engage_json(['error' => 'That email address doesn\'t look right.'], 422);
        if ($c['email'] !== '') pb_engage_json(['error' => 'This comment already has an email address.'], 409);
        pb_q('UPDATE comments SET email = ?, notify = 1 WHERE id = ?', [$email, (int) $c['id']]);
        if ($c['status'] === 'approved') pb_comment_mail_live(pb_comment_by_id($c['id']), pb_post_by_id($c['post_id'])); // approved before they typed it
        pb_engage_json(['ok' => true]);
    }
    pb_engage_json(['error' => 'Unknown action.'], 404);
}
