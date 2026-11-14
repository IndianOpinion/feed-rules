<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Tests\Unit;

use IndianOpinion\FeedRules\Adapter\CoreFeedAdapter;
use IndianOpinion\FeedRules\Admin\Forms;
use IndianOpinion\FeedRules\Counters;
use IndianOpinion\FeedRules\Engine;
use IndianOpinion\FeedRules\RuleRepository;
use IndianOpinion\FeedRules\Settings;
use IndianOpinion\FeedRules\Tests\TestCase;

final class FormsAndUninstallTest extends TestCase
{
    public function testSettingsFormIsValidated(): void
    {
        $settings = Forms::settings([
            'enabled' => '1',
            'recent_limit' => '5',
            'category_map' => "Cricket = 12\nbad line\nFootball = x\n Tennis  =  3 ",
            'author_sources' => 'https://example.com/feed = 4',
            'feeds' => [
                ['url' => 'https://example.com/feed', 'status' => 'publish', 'max' => '500'],
                ['url' => 'javascript:alert(1)'],
                ['url' => ''],
            ],
        ]);
        self::assertTrue($settings['enabled']);
        self::assertSame(20, $settings['recent_limit']);
        self::assertSame(['cricket' => 12, 'tennis' => 3], $settings['category_map']);
        self::assertSame(['https://example.com/feed' => 4], $settings['author_sources']);
        self::assertIsArray($settings['feeds']);
        self::assertCount(1, $settings['feeds']);
        self::assertSame('draft', $settings['feeds'][0]['status']);
        self::assertSame(200, $settings['feeds'][0]['max']);
        self::assertFalse($settings['copy_source_tags']);
    }

    public function testRuleFormResolvesTagsAndReportsDuplicates(): void
    {
        $rule = Forms::rule([
            'rule' => ['name' => '<b>Name</b>', 'action' => 'tag', 'tags' => 'alpha, 7, beta'],
            'conditions' => [['type' => 'keywords', 'keywords' => "a\nA\nb", 'whole_words' => '1']],
        ], static fn (string $name): ?int => $name === 'alpha' ? 11 : null);
        self::assertSame('Name', $rule->name);
        self::assertSame([11, 7], $rule->tags);
        self::assertSame(1, Forms::duplicates($rule));
        self::assertSame('test', $rule->state);
    }

    public function testUninstallRemovesWhatThePluginCreated(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->tableExists = true;
        \WpStub::$options[RuleRepository::OPTION] = ['schema' => 1, 'rules' => []];
        \WpStub::$options[Settings::OPTION] = [];
        \WpStub::$options[Counters::OPTION] = [];
        \WpStub::$options['unrelated'] = 1;
        \WpStub::$meta[5] = [Engine::POST_META => [], CoreFeedAdapter::META_SOURCE_URL => 'https://example.com', CoreFeedAdapter::META_URL_KEY => 'k'];
        \WpStub::$posts[5] = ['post_title' => 'kept'];
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'feed-rules/feed-rules.php');
        }
        require dirname(__DIR__, 2) . '/uninstall.php';
        self::assertSame(['unrelated' => 1], \WpStub::$options);
        self::assertSame([CoreFeedAdapter::META_SOURCE_URL => 'https://example.com'], \WpStub::$meta[5]);
        self::assertArrayHasKey(5, \WpStub::$posts);
        self::assertFalse($wpdb->tableExists);
        $wpdb = null;
    }

    public function testMainFileRegistersOnlyHooks(): void
    {
        require_once dirname(__DIR__, 2) . '/feed-rules.php';
        self::assertTrue(defined('FEED_RULES_FILE'));
        self::assertNotFalse(has_filter('feed_rules_filter_item'));
        self::assertNotFalse(has_filter('feed_rules_apply_to_post'));
        self::assertNotFalse(has_filter(CoreFeedAdapter::CRON_HOOK));
        $item = apply_filters('feed_rules_filter_item', ['url' => 'https://example.com/a', 'title' => 'x'], 'imp');
        self::assertIsArray($item);
        self::assertArrayHasKey(Engine::DECISION_KEY, $item);
    }

    public function testExampleRuleSetIsValid(): void
    {
        $json = (string) file_get_contents(dirname(__DIR__, 2) . '/examples/rules.json');
        $set = (new \IndianOpinion\FeedRules\ImportExport($this->repository))->import($json, true);
        self::assertCount(4, $set->rules);
        self::assertTrue($this->ruleMatches($set->rules[1]->withState('live'), ['title' => 'आज का राशिफल']));
    }
}
