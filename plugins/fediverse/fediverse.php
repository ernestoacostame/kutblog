<?php
// Fediverse plugin for KutBlog
// This file initializes the ActivityPub system when loaded.

if (!in_array('sqlite', PDO::getAvailableDrivers())) {
    // SQLite PDO driver not enabled in PHP environment
    return;
}

require_once __DIR__ . '/activitypub.php';

// DB is opened lazily by ap_db(); schema DDL is gated by PRAGMA user_version.
