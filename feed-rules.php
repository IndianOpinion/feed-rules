<?php

/**
 * Plugin Name:       Feed Rules
 * Plugin URI:        https://github.com/IndianOpinion/feed-rules
 * Description:       Include and exclude keyword lists, regular expressions, category, tag and author mapping for imported items, with a test screen over the last hundred items.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       feed-rules
 *
 * @package IndianOpinion\FeedRules
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('FEED_RULES_FILE')) {
    return;
}
define('FEED_RULES_FILE', __FILE__);

require_once __DIR__ . '/src/autoload.php';

// Entry filter and apply action: the only hooks an importer needs.
add_filter(
    'feed_rules_filter_item',
    static function ($item, $importerId = '') {
        return \IndianOpinion\FeedRules\Plugin::instance()->engine()->filter($item, $importerId);
    },
    10,
    2
);
add_action(
    'feed_rules_apply_to_post',
    static function ($postId, $item = []): void {
        \IndianOpinion\FeedRules\Plugin::instance()->engine()->applyAction($postId, $item);
    },
    10,
    2
);

// Reference adapter cron run.
add_action(
    'feed_rules_core_feed_run',
    static function ($interval = null): void {
        $plugin = \IndianOpinion\FeedRules\Plugin::instance();
        $plugin->registry();
        $plugin->coreFeed()->run(is_string($interval) ? $interval : null);
        $plugin->counters()->flush();
    }
);

// Boot adapters where importers run: admin, cron and WP-CLI. Nothing on public pages.
add_action(
    'init',
    static function (): void {
        if (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            \IndianOpinion\FeedRules\Plugin::instance()->registry();
        }
    },
    20
);

if (is_admin()) {
    add_action(
        'plugins_loaded',
        static function (): void {
            \IndianOpinion\FeedRules\Plugin::instance()->admin()->register();
        }
    );
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('feed-rules', \IndianOpinion\FeedRules\Cli\Command::class);
}

register_activation_hook(
    __FILE__,
    static function (): void {
        \IndianOpinion\FeedRules\Plugin::instance()->activate();
    }
);
register_deactivation_hook(__FILE__, [\IndianOpinion\FeedRules\Plugin::class, 'deactivate']);
