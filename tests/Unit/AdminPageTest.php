<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Tests\Unit;

use IndianOpinion\FeedRules\Admin\AdminPage;
use IndianOpinion\FeedRules\Decision;
use IndianOpinion\FeedRules\Plugin;
use IndianOpinion\FeedRules\Reason;
use IndianOpinion\FeedRules\Tests\TestCase;

final class AdminPageTest extends TestCase
{
    private AdminPage $page;

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->tableExists = true;
        $decision = new Decision(false, [new Reason('r1', '<img src=x onerror=alert(1)>', 'exclude', 'drop', 'title', '<b>')]);
        $wpdb->rows = [[
            'importer_id' => 'imp',
            'url' => 'https://example.com/a',
            'source_key' => 'k',
            'seen_at' => 1_763_100_000,
            'sampled' => 0,
            'data' => (string) json_encode([
                'fields' => ['title' => '<script>alert(1)</script>', 'body' => 'b', 'source_name' => 'S'],
                'decision' => $decision->toArray(),
            ]),
        ]];
        $this->page = Plugin::instance()->admin();
        $this->saveRules(self::rule('exclude', [self::kw('x')], ['name' => '"><script>bad</script>']));
        Plugin::instance()->repository()->forget();
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb = null;
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        parent::tearDown();
    }

    /**
     * @param array<string, string> $get
     * @param array<string, mixed> $post
     */
    private function render(array $get, array $post = []): string
    {
        $_GET = $get;
        $_POST = $post;
        $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
        $level = ob_get_level();
        ob_start();
        try {
            $this->page->render();
        } finally {
            $html = '';
            while (ob_get_level() > $level) {
                $html = (string) ob_get_clean() . $html;
            }
        }
        return $html;
    }

    public function testEveryTabRendersEscaped(): void
    {
        foreach (['rules', 'test', 'log', 'settings'] as $tab) {
            $html = $this->render(['tab' => $tab]);
            self::assertStringContainsString('nav-tab-active', $html, $tab);
            self::assertStringNotContainsString('<script>', $html, $tab);
            self::assertStringNotContainsString('<img src=x', $html, $tab);
        }
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $this->render(['tab' => 'log']));
        $edit = $this->render(['tab' => 'rules', 'new' => '1']);
        self::assertStringContainsString('name="conditions[0][keywords]"', $edit);
        self::assertStringContainsString('formaction', $edit);
    }

    public function testTestTabRunsOnPostWithNonce(): void
    {
        $html = $this->render(['tab' => 'test'], [
            '_feed_rules_test_nonce' => 'valid',
            'test_mode' => 'set',
            'sample_title' => ['<script>x</script> pasted'],
            'sample_body' => [''],
        ]);
        self::assertStringContainsString('2 items:', $html);
        self::assertStringNotContainsString('<script>', $html);

        $this->expectException(\RuntimeException::class);
        $this->render(['tab' => 'test'], ['_feed_rules_test_nonce' => 'forged']);
    }

    /** Case 41: an empty store says so and offers to load current items. */
    public function testEmptyStoreOffersSampling(): void
    {
        global $wpdb;
        $wpdb->rows = [];
        $html = $this->render(['tab' => 'test']);
        self::assertStringContainsString('No items have been recorded yet', $html);
        self::assertStringContainsString('Load current items: WordPress core feeds', $html);

        Plugin::instance()->settings()->update(['feeds' => [['url' => 'https://feeds.example.com/a.xml']]]);
        \WpStub::$feeds['https://feeds.example.com/a.xml'] = new \SimplePie([
            new \SimplePie_Item(['link' => 'https://example.com/1', 'title' => 'One', 'content' => 'x']),
        ]);
        self::assertSame(1, Plugin::instance()->sample('core-feed'));
        $inserts = array_filter($wpdb->queries, static fn (string $q): bool => str_starts_with($q, 'INSERT INTO wp_feed_rules_recent'));
        self::assertCount(1, $inserts);
        self::assertStringContainsString(', 1, ', (string) current($inserts), 'marked as sampled');
        self::assertSame([], \WpStub::$posts);
    }
}
