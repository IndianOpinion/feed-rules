<?php

/**
 * Must-use plugin loader. Copy this file to wp-content/mu-plugins/ and put
 * the plugin folder at wp-content/mu-plugins/feed-rules/. The recent-items
 * table is created on first use instead of on activation.
 *
 * Plugin Name: Feed Rules (must-use loader)
 */

declare(strict_types=1);

require_once __DIR__ . '/feed-rules/feed-rules.php';
