<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * What an importer integration provides. Register instances on the
 * `feed_rules_register_adapters` action with `$registry->add($adapter)`.
 */
interface Adapter
{
    /**
     * Slug, lower case, 1 to 40 characters (a to z, 0 to 9, hyphen, underscore).
     */
    public function id(): string;

    /**
     * Shown in the admin.
     */
    public function label(): string;

    /**
     * Hook into the importer. Called once per request on admin, cron and WP-CLI requests.
     */
    public function boot(): void;

    /**
     * Source key => label, for the scope picker.
     *
     * @return array<string, string>
     */
    public function sources(): array;

    /**
     * The user that owns posts from this source when no mapping matches, or null.
     */
    public function defaultAuthor(string $sourceKey): ?int;

    public function supportsSampling(): bool;

    /**
     * Current items from the importer's sources, without importing them.
     * Only called when supportsSampling() is true.
     *
     * @return list<Item>
     */
    public function sample(int $limit): array;
}
