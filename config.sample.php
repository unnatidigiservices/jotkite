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
