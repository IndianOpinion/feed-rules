<?php

/**
 * How an importer uses feed-rules: one filter before creating a post,
 * one action after. Drop this pattern into your own importer.
 *
 * The filter returns false when the item is dropped, or the item with a
 * decision attached. When feed-rules is not active the filter is a no-op
 * and the item comes back unchanged.
 */

declare(strict_types=1);

/**
 * @param array<string, mixed> $entry One entry from your source.
 */
function my_importer_handle_entry(array $entry): ?int
{
    $item = [
        'url' => $entry['link'],
        'title' => $entry['title'],
        'body' => $entry['html'],
        'source_name' => 'Example Wire',
        'source_key' => 'https://wire.example.com/feed',
        'author' => $entry['byline'] ?? '',
        'categories' => $entry['sections'] ?? [],
        'tags' => $entry['keywords'] ?? [],
        'published' => $entry['timestamp'] ?? null,
    ];

    $item = apply_filters('feed_rules_filter_item', $item, 'my-importer');
    if ($item === false) {
        return null; // Dropped by a rule. Nothing is created.
    }

    $postId = wp_insert_post([
        'post_title' => sanitize_text_field($item['title']),
        'post_content' => wp_kses_post($item['body']),
        'post_status' => 'draft',
    ]);
    if (is_wp_error($postId) || $postId === 0) {
        return null;
    }

    // Adds mapped categories, tags and author, and records which rules matched.
    do_action('feed_rules_apply_to_post', $postId, $item);
    return $postId;
}
