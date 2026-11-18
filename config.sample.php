<?php
/**
 * Optional. Copy to config.php to override defaults. Everything else is set
 * from Admin → Settings and stored in the database.
 */
return [
    // Where the SQLite database lives. Moving it outside the web root is
    // the safest choice when your host allows it, e.g. '/home/user/postbase.sqlite'.
    'db_path' => __DIR__ . '/data/postbase.sqlite',

    // When set, the first-run setup screen asks for this value before it
    // creates the first admin account. Recommended on standalone installs.
    'setup_key' => '',

    // Upload limits for images. Larger photos are resized to max_image_px wide (needs GD).
    'max_upload_mb' => 5,
    'max_image_px'  => 1600,

    // One-click updates (Settings → Updates). '' switches them off. Only releases
    // signed with the JotKite release key install; 'update_pubkey' (PEM) is for
    // your own signed releases from your own server.
    // 'update_url' => 'https://app.unnatidigiservices.in/georank/includes/postbase/',
    // 'update_pubkey' => '',

    // Optional: a second addons folder outside the site folder, for addons that
    // aren't in your Git repository (premium addons). A Git deploy replaces the
    // site folder and would remove them from addons/; here they stay. Use a full
    // path, e.g. '/home/u123456789/jotkite-addons' (one folder per addon inside).
    // 'addons_dir' => '',

    // Optional: the SMTP mailbox password (Settings → Comments & email) kept here
    // instead of the database. When set, the Settings field is ignored.
    // 'smtp_pass' => '',

    // Public demo site ("try it" install, e.g. demo.jotkite.com). NEVER on a real blog:
    // visitors sign in with one click as Admin/Editor/Contributor, and every
    // demo_reset_minutes the database and uploads/ go back to a saved starting
    // point. Type demo_key in Settings → Demo to save that starting point.
    'demo'               => false,
    'demo_reset_minutes' => 60,
    'demo_key'           => '',
];
