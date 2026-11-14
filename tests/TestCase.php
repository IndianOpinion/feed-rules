<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Tests;

use IndianOpinion\FeedRules\Clock;
use IndianOpinion\FeedRules\Counters;
use IndianOpinion\FeedRules\Engine;
use IndianOpinion\FeedRules\Item;
use IndianOpinion\FeedRules\Logger;
use IndianOpinion\FeedRules\Matcher;
use IndianOpinion\FeedRules\Plugin;
use IndianOpinion\FeedRules\RecentStore;
use IndianOpinion\FeedRules\Registry;
use IndianOpinion\FeedRules\Rule;
use IndianOpinion\FeedRules\RuleRepository;
use IndianOpinion\FeedRules\RuleSet;
use IndianOpinion\FeedRules\Settings;
use IndianOpinion\FeedRules\Store\MemoryStorage;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @var list<string> */
    protected array $log = [];
    protected Settings $settings;
    protected RuleRepository $repository;
    protected Counters $counters;
    protected MemoryStorage $storage;
    protected RecentStore $store;
    protected Registry $registry;
    protected Engine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        \WpStub::reset();
        Matcher::clearCache();
        Plugin::reset();
        Clock::set(1_763_100_000);
        $this->log = [];
        Logger::setSink(function (string $line): void {
            $this->log[] = $line;
        });
        $this->rebuild();
    }

    protected function tearDown(): void
    {
        Clock::set(null);
        Logger::setSink(null);
        parent::tearDown();
    }

    protected function rebuild(): void
    {
        $this->settings = new Settings();
        $this->repository = new RuleRepository();
        $this->counters = new Counters();
        $this->storage = new MemoryStorage();
        $this->store = new RecentStore($this->storage, $this->settings);
        $this->registry = new Registry();
        $this->engine = new Engine($this->repository, $this->settings, $this->counters, $this->store, $this->registry);
    }

    /**
     * @param array<string, mixed> $overrides
     * @param list<array<string, mixed>> $conditions
     */
    protected static function rule(string $action, array $conditions, array $overrides = []): Rule
    {
        return Rule::fromArray(array_merge([
            'id' => 'r' . substr(md5($action . json_encode($conditions) . json_encode($overrides)), 0, 8),
            'name' => ucfirst($action) . ' rule',
            'state' => Rule::STATE_LIVE,
            'action' => $action,
            'conditions' => $conditions,
        ], $overrides));
    }

    /**
     * @param list<string>|string $keywords
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected static function kw(array|string $keywords, string $field = 'title_body', array $extra = []): array
    {
        return array_merge(['type' => 'keywords', 'field' => $field, 'keywords' => $keywords], $extra);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected static function item(array $data = []): Item
    {
        return Item::fromArray(array_merge([
            'url' => 'https://news.example.com/story/1',
            'title' => 'A plain title',
            'body' => '<p>A plain body.</p>',
            'source_name' => 'Example News',
            'source_key' => 'https://news.example.com/feed',
        ], $data));
    }

    protected function saveRules(Rule ...$rules): RuleSet
    {
        $saved = $this->repository->save(new RuleSet(array_values($rules)), $this->repository->load()->editVersion);
        Matcher::clearCache();
        return $saved;
    }

    /**
     * Evaluate one item against one live rule without touching the stored set.
     *
     * @param array<string, mixed> $item
     */
    protected function ruleMatches(Rule $rule, array $item): bool
    {
        $decision = $this->engine->evaluate(self::item($item), 'test', new RuleSet([$rule]), true);
        foreach ($decision->reasons() as $reason) {
            if ($reason->ruleId === $rule->id) {
                return true;
            }
        }
        return false;
    }
}
