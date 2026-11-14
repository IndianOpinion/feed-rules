<?php

/**
 * Removes everything Feed Rules created, on every site of a network.
 * Posts are never deleted. The source URL meta on imported posts is kept
 * because it is content provenance.
 *
 * @package IndianOpinion\FeedRules
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

$feedRulesCleanup = static function (): void {
    global $wpdb;
    foreach (
        [
            \IndianOpinion\FeedRules\RuleRepository::OPTION,
            \IndianOpinion\FeedRules\Settings::OPTION,
            \IndianOpinion\FeedRules\Counters::OPTION,
            \IndianOpinion\FeedRules\Adapter\DroppedKeys::OPTION,
        ] as $option
    ) {
        delete_option($option);
    }
    delete_transient(\IndianOpinion\FeedRules\Adapter\CoreFeedAdapter::LOCK);
    delete_transient(\IndianOpinion\FeedRules\RuleRepository::CORRUPT_LOG_TRANSIENT);
    delete_post_meta_by_key(\IndianOpinion\FeedRules\Engine::POST_META);
    delete_post_meta_by_key(\IndianOpinion\FeedRules\Adapter\CoreFeedAdapter::META_URL_KEY);
    wp_clear_scheduled_hook(\IndianOpinion\FeedRules\Adapter\CoreFeedAdapter::CRON_HOOK);
    if ($wpdb instanceof wpdb) {
        (new \IndianOpinion\FeedRules\Store\WpdbStorage($wpdb))->drop();
    }
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $feedRulesSite) {
        switch_to_blog((int) $feedRulesSite);
        $feedRulesCleanup();
        restore_current_blog();
    }
} else {
    $feedRulesCleanup();
}
