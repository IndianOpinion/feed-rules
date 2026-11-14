<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

use IndianOpinion\FeedRules\Adapter\CoreFeedAdapter;
use IndianOpinion\FeedRules\Admin\AdminPage;
use IndianOpinion\FeedRules\Store\WpdbStorage;

/**
 * Builds the services once per request and wires them to WordPress.
 */
final class Plugin
{
    public const VERSION = '0.1.0';

    private static ?self $instance = null;

    private Settings $settings;
    private RuleRepository $repository;
    private Counters $counters;
    private ?RecentStore $store = null;
    private ?WpdbStorage $storage = null;
    private ?Registry $registry = null;
    private ?Engine $engine = null;
    private ?CoreFeedAdapter $coreFeed = null;

    private function __construct()
    {
        $this->settings = new Settings();
        $this->repository = new RuleRepository();
        $this->counters = new Counters();
        add_action('shutdown', [$this->counters, 'flush']);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Forget every service (tests, and switch_to_blog() in uninstall).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function repository(): RuleRepository
    {
        return $this->repository;
    }

    public function counters(): Counters
    {
        return $this->counters;
    }

    public function storage(): ?WpdbStorage
    {
        global $wpdb;
        if ($this->storage === null && $wpdb instanceof \wpdb) {
            $this->storage = new WpdbStorage($wpdb);
        }
        return $this->storage;
    }

    public function store(): ?RecentStore
    {
        if ($this->store === null) {
            $storage = $this->storage();
            if ($storage !== null) {
                $this->store = new RecentStore($storage, $this->settings);
            }
        }
        return $this->store;
    }

    public function coreFeed(): CoreFeedAdapter
    {
        return $this->coreFeed ??= new CoreFeedAdapter($this->settings);
    }

    /**
     * The adapter registry, filled on first use through `feed_rules_register_adapters`.
     */
    public function registry(): Registry
    {
        if ($this->registry === null) {
            $this->registry = new Registry();
            $this->registry->add($this->coreFeed());
            do_action('feed_rules_register_adapters', $this->registry);
            $this->registry->boot();
        }
        return $this->registry;
    }

    public function engine(): Engine
    {
        return $this->engine ??= new Engine(
            $this->repository,
            $this->settings,
            $this->counters,
            $this->store(),
            $this->registry()
        );
    }

    public function tester(): Tester
    {
        return new Tester($this->engine(), $this->store());
    }

    public function importExport(): ImportExport
    {
        return new ImportExport($this->repository);
    }

    /**
     * Fetch current items from an adapter and record them as sampled.
     *
     * @return int Items recorded.
     */
    public function sample(string $importerId, int $limit = 100): int
    {
        $adapter = $this->registry()->get($importerId);
        $store = $this->store();
        if ($adapter === null || !$adapter->supportsSampling() || $store === null) {
            return 0;
        }
        $count = 0;
        try {
            foreach ($adapter->sample($limit) as $item) {
                $store->record($item, $importerId, $this->engine()->preview($item, $importerId), null, true);
                $count++;
            }
        } catch (\Throwable $e) {
            Logger::log(sprintf('error adapter=%s sample: %s', $importerId, $e->getMessage()));
        }
        return $count;
    }

    public function admin(): AdminPage
    {
        return new AdminPage($this);
    }

    public function activate(): void
    {
        try {
            $this->storage()?->ensure();
        } catch (\Throwable $e) {
            Logger::log('error activation: ' . $e->getMessage());
        }
        $this->coreFeed()->schedule();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(CoreFeedAdapter::CRON_HOOK);
    }

    public static function privacyText(): string
    {
        return 'Feed rules keeps the last items each importer brought in, including the feed author name of '
            . 'each item, so administrators can test rules against them. Only the most recent items are kept '
            . '(100 per importer by default). Nothing is sent off the site.';
    }
}
