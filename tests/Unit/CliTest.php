<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Tests\Unit;

use IndianOpinion\FeedRules\Cli\Command;
use IndianOpinion\FeedRules\Plugin;
use IndianOpinion\FeedRules\RuleSet;
use IndianOpinion\FeedRules\Tests\TestCase;

final class CliTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        \WP_CLI::$lines = [];
        global $wpdb;
        $wpdb = new \wpdb();
        $this->dir = sys_get_temp_dir() . '/feed-rules-cli-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        global $wpdb;
        $wpdb = null;
        parent::tearDown();
    }

    public function testExportImportDryRunAndListAndStatus(): void
    {
        $plugin = Plugin::instance();
        $cli = new Command($plugin);
        $file = $this->dir . '/rules.json';
        file_put_contents($file, (string) json_encode((new RuleSet([self::rule('exclude', [self::kw('x')], ['id' => 'rcli', 'name' => 'CLI rule'])]))->toArray()));

        $cli->import([$file], ['dry-run' => true]);
        self::assertSame('Success: Dry run: would import 1 rule(s).', end(\WP_CLI::$lines));
        self::assertSame([], $plugin->repository()->load()->rules);

        $cli->import([$file], []);
        $plugin->repository()->forget();
        self::assertNotNull($plugin->repository()->load()->find('rcli'));

        $cli->list_([], ['format' => 'json']);
        self::assertStringContainsString('"name": "CLI rule"', (string) end(\WP_CLI::$lines));

        $out = $this->dir . '/out.json';
        $cli->export([$out], []);
        self::assertStringContainsString('rcli', (string) file_get_contents($out));

        $cli->status([], []);
        self::assertContains('Global switch: on', \WP_CLI::$lines);
        self::assertContains('Rules: 1 live, 0 test, 0 off', \WP_CLI::$lines);

        $cli->test(['missing'], []);
        self::assertStringStartsWith('Error:', (string) end(\WP_CLI::$lines));
        $cli->test(['all'], ['format' => 'csv']);
        self::assertStringStartsWith('Title,Source', (string) end(\WP_CLI::$lines));

        $cli->reset_errors([], []);
        self::assertSame('Success: Error badges cleared.', end(\WP_CLI::$lines));

        $cli->run_feeds([], []);
        self::assertStringStartsWith('Success: Inserted 0', (string) end(\WP_CLI::$lines));
    }
}
