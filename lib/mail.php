<?php
/**
 * JotKite — sending email (comment notifications, tests).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial
 *
 * Settings → Comments & email picks how:
 *   smtp  a real mailbox (e.g. noreply@yoursite.com at Hostinger) — reliable
 *   php   PHP's mail() — works on most hosts, but often lands in spam
 *   off   no email at all (the default; visitors aren't offered notifications)
 *
 * Mail is sent after the page has gone to the browser (pb_mail_later), so a
 * slow mail server never slows a visitor down. config.php may hold the SMTP
 * password ('smtp_pass') instead of the database, and 'mail_capture' (a file
 * path) writes mail there instead of sending it — for tests.
 */
if (!defined('PB_ROOT')) { http_response_code(403); exit; }

function pb_mail_on() {
    return in_array(pb_setting('mail_mode'), ['smtp', 'php'], true) || (string) pb_config('mail_capture') !== '';
}
function pb_mail_from() {
    $from = trim((string) pb_setting('mail_from'));
    if (filter_var($from, FILTER_VALIDATE_EMAIL)) return $from;
    $user = trim((string) pb_setting('smtp_user'));
    if (filter_var($user, FILTER_VALIDATE_EMAIL)) return $user;
    $host = preg_replace('/^www\./', '', (string) parse_url(pb_origin(), PHP_URL_HOST));
    return 'noreply@' . ($host !== '' ? $host : 'localhost');
}
// A real person's address (not the placeholder accounts GeoRank logins use).
function pb_mail_deliverable($email) {
    return (bool) filter_var((string) $email, FILTER_VALIDATE_EMAIL) && !preg_match('/@georank\.local$/i', (string) $email);
}
function pb_mail_header_word($s) {
    $s = str_replace(["\r", "\n"], ' ', (string) $s);
    if (!preg_match('/[^\x20-\x7E]/', $s)) return $s;
    // Encoded words may be 75 characters at most: split on whole UTF-8 characters, fold the line.
    preg_match_all('/./us', $s, $chars);
    $words = [];
    $cur = '';
    foreach ($chars[0] as $ch) {
        if (strlen($cur . $ch) > 45) { $words[] = $cur; $cur = ''; }
        $cur .= $ch;
    }
    if ($cur !== '') $words[] = $cur;
    return implode("\r\n ", array_map(function ($w) { return '=?UTF-8?B?' . base64_encode($w) . '?='; }, $words));
}

/**
 * Send one plain-text email now. $opts: unsubscribe (URL), reply_to.
 * Returns null on success, or an error message.
 */
function pb_mail($to, $subject, $text, array $opts = []) {
    $to = trim(str_replace(["\r", "\n"], '', (string) $to));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return 'Not a valid email address.';
    $from = pb_mail_from();
    $fromName = trim((string) pb_setting('mail_from_name')) ?: (string) pb_setting('blog_title');
    $domain = substr(strrchr($from, '@'), 1);
    $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
    if (!empty($opts['unsubscribe'])) $text .= "\n\n--\nStop these emails: " . $opts['unsubscribe'];

    $h = [
        'Date: ' . date('r'),
        'From: ' . pb_mail_header_word($fromName) . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . pb_mail_header_word($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'Auto-Submitted: auto-generated',
        'X-Mailer: JotKite',
    ];
    if (!empty($opts['reply_to']) && filter_var($opts['reply_to'], FILTER_VALIDATE_EMAIL)) $h[] = 'Reply-To: <' . $opts['reply_to'] . '>';
    if (!empty($opts['unsubscribe'])) {
        $h[] = 'List-Unsubscribe: <' . str_replace(["\r", "\n", '>'], '', $opts['unsubscribe']) . '>';
        $h[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
    }
    $body = rtrim(chunk_split(base64_encode($text), 76, "\r\n"));

    $capture = (string) pb_config('mail_capture');
    if ($capture !== '') {
        @file_put_contents($capture, json_encode(['to' => $to, 'subject' => $subject, 'text' => $text, 'headers' => $h]) . "\n", FILE_APPEND | LOCK_EX);
        return null;
    }
    $mode = pb_setting('mail_mode');
    if ($mode === 'php') {
        // mail() takes To and Subject separately.
        $extra = array_values(array_filter($h, function ($l) { return stripos($l, 'To:') !== 0 && stripos($l, 'Subject:') !== 0; }));
        $ok = @mail($to, pb_mail_header_word($subject), $body, implode("\r\n", $extra), '-f' . $from);
        return $ok ? null : 'PHP mail() refused the message. Use SMTP instead.';
    }
    if ($mode !== 'smtp') return 'Email is switched off (Settings → Comments & email).';
    return pb_smtp_send($from, $to, implode("\r\n", $h) . "\r\n\r\n" . $body);
}

// Minimal SMTP client: SSL (port 465) or STARTTLS (587), AUTH LOGIN.
function pb_smtp_send($from, $to, $message) {
    $host = trim((string) pb_setting('smtp_host'));
    $port = (int) pb_setting('smtp_port') ?: 465;
    $secure = (string) pb_setting('smtp_secure');
    $user = trim((string) pb_setting('smtp_user'));
    $pass = (string) (pb_config('smtp_pass') ?? '') !== '' ? (string) pb_config('smtp_pass') : (string) pb_setting('smtp_pass');
    if ($host === '') return 'No SMTP server set.';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return 'Could not connect to ' . $host . ':' . $port . ($errstr !== '' ? ' (' . $errstr . ')' : '') . '.';
    stream_set_timeout($fp, 20);
    $read = function () use ($fp) {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break; // last line of a reply: "250 OK", not "250-…"
        }
        return $out;
    };
    $step = function ($cmd, $expect, $label = null) use ($fp, $read) {
        if ($cmd !== null) fwrite($fp, $cmd . "\r\n");
        $r = $read();
        if (!in_array((int) substr($r, 0, 3), (array) $expect, true)) {
            throw new RuntimeException(($label ?? strtok((string) $cmd, ' :')) . ': ' . (trim($r) !== '' ? trim($r) : 'no answer'));
        }
        return $r;
    };
    $helo = (string) parse_url(pb_origin(), PHP_URL_HOST) ?: 'localhost';
    try {
        $step(null, 220, 'Greeting');
        $step('EHLO ' . $helo, 250);
        if ($secure === 'tls') {
            $step('STARTTLS', 220);
            $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            if (!@stream_socket_enable_crypto($fp, true, $method)) throw new RuntimeException('STARTTLS: secure connection failed');
            $step('EHLO ' . $helo, 250);
        }
        if ($user !== '') {
            $step('AUTH LOGIN', 334);
            $step(base64_encode($user), 334, 'Username');
            $step(base64_encode($pass), 235, 'Password (check the mailbox password)');
        }
        $step('MAIL FROM:<' . $from . '>', 250);
        $step('RCPT TO:<' . $to . '>', [250, 251]);
        $step('DATA', 354);
        $step(preg_replace('/^\./m', '..', $message) . "\r\n.", 250, 'Message');
        try { $step('QUIT', 221); } catch (RuntimeException $e) { /* already delivered */ }
        fclose($fp);
        return null;
    } catch (RuntimeException $e) {
        @fclose($fp);
        return $e->getMessage();
    }
}

// Queue a message; it's sent once the page has been delivered.
function pb_mail_later($to, $subject, $text, array $opts = []) {
    if (!pb_mail_on() || !pb_mail_deliverable($to)) return;
    if (empty($GLOBALS['pb_mail_queue'])) {
        $GLOBALS['pb_mail_queue'] = [];
        register_shutdown_function('pb_mail_flush');
    }
    $GLOBALS['pb_mail_queue'][] = [$to, $subject, $text, $opts];
}
function pb_mail_flush() {
    $queue = $GLOBALS['pb_mail_queue'] ?? [];
    $GLOBALS['pb_mail_queue'] = [];
    if (!$queue) return;
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();      // PHP-FPM
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request(); // Hostinger (LiteSpeed)
    ignore_user_abort(true);
    @set_time_limit(60);
    foreach ($queue as [$to, $subject, $text, $opts]) {
        $err = pb_mail($to, $subject, $text, $opts);
        if ($err !== null) {
            pb_settings_save(['mail_last_error' => gmdate('Y-m-d H:i') . ' UTC · ' . $err]);
            break; // the same problem would hit every message
        }
    }
}
