<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Tests\Unit;

use IndianOpinion\FeedRules\Admin\Forms;
use IndianOpinion\FeedRules\Clock;
use IndianOpinion\FeedRules\Rule;
use IndianOpinion\FeedRules\RuleSet;
use IndianOpinion\FeedRules\Tester;
use IndianOpinion\FeedRules\TestReport;
use IndianOpinion\FeedRules\Tests\TestCase;

final class TesterTest extends TestCase
{
    private Tester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tester = new Tester($this->engine, $this->store);
        $this->saveRules(self::rule('exclude', [self::kw('sport')], ['id' => 'rsport', 'name' => 'No sport']));
        $titles = ['sport results', 'budget news', 'weather today', 'sport and budget'];
        foreach ($titles as $i => $title) {
            Clock::set(1_763_100_000 + $i);
            $this->engine->filter([
                'url' => 'https://example.com/' . $i,
                'title' => $title,
                'source_key' => $i < 2 ? 'feed-a' : 'feed-b',
                'source_name' => $i < 2 ? 'Feed A' : 'Feed B',
            ], 'imp');
        }
        $this->counters->flush();
    }

    /** Case 40. */
    public function testDraftRuleRunReportsOutcomesAndContext(): void
    {
        $draft = self::rule('exclude', [self::kw('budget')], ['state' => 'test', 'name' => 'Draft']);
        $set = $this->repository->load()->without('rsport')->withRule($draft);
        $report = $this->tester->run($set);
        self::assertTrue($report->isValid());
        self::assertSame(
            ['items' => 4, 'kept' => 2, 'dropped' => 2, 'newly_dropped' => 1, 'newly_kept' => 1, 'mapping_changed' => 0],
            $report->summary()
        );
        $row = $report->rows[2];
        self::assertSame('budget news', $row->title);
        self::assertSame('drop', $row->verdict());
        $reason = $row->decision->reasons()[0];
        self::assertSame('Draft', $reason->ruleName);
        self::assertSame('budget', $reason->matched);
        self::assertSame('', $reason->before);
        self::assertSame(' news', $reason->after);
    }

    public function testContextIsCappedAtSixtyCharacters(): void
    {
        $this->engine->filter([
            'url' => 'https://example.com/long',
            'title' => str_repeat('x', 100) . ' needle ' . str_repeat('y', 100),
        ], 'imp');
        $report = $this->tester->run(new RuleSet([self::rule('exclude', [self::kw('needle')])]), 1);
        $reason = $report->rows[0]->decision->reasons()[0];
        self::assertSame(60, mb_strlen($reason->before));
        self::assertSame(60, mb_strlen($reason->after));
    }

    /** Case 42. */
    public function testRunWritesNothing(): void
    {
        $before = [\WpStub::$options, \WpStub::$meta, $this->store->latest(), did_action('feed_rules_decided')];
        $this->tester->run($this->repository->load()->withRule(self::rule('categorise', [self::kw('budget')], ['categories' => [3]])));
        $this->counters->flush();
        self::assertEquals($before, [\WpStub::$options, \WpStub::$meta, $this->store->latest(), did_action('feed_rules_decided')]);
    }

    /** Case 43. */
    public function testInvalidDraftIsNotRun(): void
    {
        $report = $this->tester->run(new RuleSet([self::rule('exclude', [['type' => 'regex', 'pattern' => '/(/']])]));
        self::assertFalse($report->isValid());
        self::assertSame([], $report->rows);
        self::assertStringContainsString('regex', (string) $report->validationErrors[0]);
    }

    /** Case 44. */
    public function testCutBodyIsMarked(): void
    {
        $this->engine->filter(['url' => 'https://example.com/big', 'title' => 'big', 'body' => str_repeat('word ', 1000)], 'imp');
        $report = $this->tester->run($this->repository->load(), 1);
        self::assertTrue($report->rows[0]->decision->bodyTruncated());
        self::assertSame('yes', $report->table()[0]['Body cut']);
    }

    /** Case 45. */
    public function testSourceFilterAndCsv(): void
    {
        $report = $this->tester->run($this->repository->load(), 100, null, 'feed-b');
        self::assertCount(2, $report->rows);
        $table = $report->table();
        $lines = array_values(array_filter(explode("\n", $report->csv())));
        self::assertCount(3, $lines);
        self::assertSame(TestReport::COLUMNS, str_getcsv($lines[0], ',', '"', '\\'));
        self::assertSame(array_values($table[0]), str_getcsv($lines[1], ',', '"', '\\'));
        self::assertCount(4, $this->tester->run($this->repository->load(), 100, 'imp')->rows);
        self::assertCount(0, $this->tester->run($this->repository->load(), 100, 'nope')->rows);
    }

    public function testCsvNeutralisesFormulas(): void
    {
        $this->engine->filter(['url' => 'https://example.com/f', 'title' => '=HYPERLINK("x")'], 'imp');
        self::assertStringContainsString("'=HYPERLINK", $this->tester->run($this->repository->load(), 1)->csv());
    }

    /** Ruling 3: pasted samples run alongside recorded items. */
    public function testPastedSamples(): void
    {
        $samples = Forms::samples([
            'sample_title' => ['Sport special', ''],
            'sample_body' => ['body', ''],
            'sample_url' => ['', ''],
        ]);
        self::assertCount(1, $samples);
        $report = $this->tester->run($this->repository->load(), 100, null, null, $samples);
        self::assertCount(5, $report->rows);
        self::assertTrue($report->rows[0]->pasted);
        self::assertSame('drop', $report->rows[0]->verdict());
        self::assertSame('pasted', $report->table()[0]['Recorded']);
        self::assertSame(5, $report->summary()['items']);
        self::assertSame(0, $report->summary()['newly_dropped']);
    }

    public function testFormsBuildTestSets(): void
    {
        $saved = $this->repository->load();
        $post = [
            'rule' => ['name' => 'Draft', 'action' => 'exclude', 'state' => 'test'],
            'conditions' => [['type' => 'keywords', 'field' => 'title', 'keywords' => "weather\n"], ['type' => 'keywords', 'keywords' => '']],
        ];
        $draft = Forms::testSet($post + ['test_mode' => 'draft'], $saved);
        self::assertCount(1, $draft->rules);
        self::assertCount(1, $draft->rules[0]->conditions);
        self::assertCount(2, Forms::testSet($post + ['test_mode' => 'set_draft'], $saved)->rules);
        self::assertSame('rsport', Forms::testSet(['test_mode' => 'rule:rsport'], $saved)->rules[0]->id);
        self::assertSame($saved, Forms::testSet([], $saved));
        self::assertSame(Rule::STATE_TEST, $draft->rules[0]->state);
    }
}
