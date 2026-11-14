<?php

/**
 * A minimal adapter for your own importer. An adapter tells feed-rules
 * which sources exist (for rule scopes), who the default author is, and
 * optionally how to fetch current items for the test screen.
 *
 * Load this from a plugin or a must-use plugin.
 */

declare(strict_types=1);

namespace MySite;

use IndianOpinion\FeedRules\Adapter;
use IndianOpinion\FeedRules\Item;
use IndianOpinion\FeedRules\Registry;

final class MyImporterAdapter implements Adapter
{
    public function id(): string
    {
        return 'my-importer';
    }

    public function label(): string
    {
        return 'My importer';
    }

    public function boot(): void
    {
        // Hook into your importer here if it does not call the feed-rules filter itself.
    }

    public function sources(): array
    {
        return ['https://wire.example.com/feed' => 'Example Wire'];
    }

    public function defaultAuthor(string $sourceKey): ?int
    {
        return $sourceKey === 'https://wire.example.com/feed' ? 2 : null;
    }

    public function supportsSampling(): bool
    {
        return true;
    }

    public function sample(int $limit): array
    {
        return [
            Item::fromArray([
                'url' => 'https://wire.example.com/story/1',
                'title' => 'A sample headline',
                'body' => '<p>A sample body.</p>',
                'source_name' => 'Example Wire',
                'source_key' => 'https://wire.example.com/feed',
            ]),
        ];
    }
}

add_action('feed_rules_register_adapters', static function (Registry $registry): void {
    $registry->add(new MyImporterAdapter());
});
