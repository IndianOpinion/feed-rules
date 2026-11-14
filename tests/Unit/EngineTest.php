<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Tests\Unit;

use IndianOpinion\FeedRules\Engine;
use IndianOpinion\FeedRules\Reason;
use IndianOpinion\FeedRules\Rule;
use IndianOpinion\FeedRules\RuleSet;
use IndianOpinion\FeedRules\Tests\TestCase;

final class EngineTest extends TestCase
{
    /** Case 1. */
    public function testFilterAttachesDecisionOrReturnsFalse(): void
    {
        $this->saveRules(self::rule('exclude', [self::kw('horoscope')]));
        $kept = $this->engine->filter(['url' => 'https://example.com/1', 'title' => 'Budget news', 'extra' => 7], 'imp');
        self::assertIsArray($kept);
        self::assertArrayHasKey(Engine::DECISION_KEY, $kept);
        self::assertSame(7, $kept['extra']);
        self::assertTrue($kept[Engine::DECISION_KEY]['kept']);
        self::assertFalse($this->engine->filter(['url' => 'https://example.com/2', 'title' => 'Your horoscope'], 'imp'));
    }

    /** Case 2. */
    public function testInvalidItemsPassThroughAndAreCounted(): void
    {
        define('WP_DEBUG', true);
        $this->saveRules(self::rule('exclude', [self::kw('x')]));
        foreach ([['title' => 'x'], ['url' => '/relative', 'title' => 'x'], ['url' => 'ftp://example.com/x', 'title' => 'x']] as $item) {
            self::assertSame($item, $this->engine->filter($item, 'imp'));
        }
        $this->counters->flush();
        self::assertSame(3, $this->counters->invalidToday());
        self::assertSame(0, $this->storage->count());
        self::assertCount(3, \WpStub::$doingItWrong);
        self::assertStringContainsString('url', \WpStub::$doingItWrong[0][1]);
    }

    /** Case 3. */
    public function testGlobalSwitchOff(): void
    {
        $this->saveRules(self::rule('exclude', [self::kw('x')]));
        $this->settings->update(['enabled' => false]);
        $item = ['url' => 'https://example.com/1', 'title' => 'x'];
        self::assertSame($item, $this->engine->filter($item, 'imp'));
        self::assertSame(0, $this->storage->count());
        self::assertFalse($this->counters->hasPending());

        $this->settings->update(['enabled' => true]);
        add_filter('feed_rules_enabled', '__return_false');
        self::assertSame($item, $this->engine->filter($item, 'imp'));
    }

    /** Case 23. */
    public function testRuleShapeAndAndOfConditions(): void
    {
        $conditions = array_fill(0, 11, self::kw('x'));
        self::assertNotEmpty(Rule::fromArray(['action' => 'exclude', 'conditions' => $conditions])->validate());
        $new = Rule::fromArray(['action' => 'exclude', 'conditions' => [self::kw('x')]]);
        self::assertSame(Rule::STATE_TEST, $new->state);
        self::assertSame([], $new->validate());

        $both = self::rule('exclude', [self::kw('red'), self::kw('car')]);
        self::assertFalse($this->ruleMatches($both, ['title' => 'a red bus']));
        self::assertTrue($this->ruleMatches($both, ['title' => 'a red car']));
    }

    /** Case 24. */
    public function testScopeBySourceAndImporter(): void
    {
        $bySource = self::rule('exclude', [self::kw('x')], ['scope' => 'sources', 'scope_values' => ['A']]);
        $byImporter = self::rule('exclude', [self::kw('x')], ['scope' => 'importers', 'scope_values' => ['X']]);
        $item = static fn (string $source): \IndianOpinion\FeedRules\Item => self::item(['title' => 'x', 'source_key' => $source]);
        self::assertFalse($this->engine->evaluate($item('A'), 'any', new RuleSet([$bySource]))->isKept());
        self::assertTrue($this->engine->evaluate($item('B'), 'any', new RuleSet([$bySource]))->isKept());
        self::assertFalse($this->engine->evaluate($item('A'), 'X', new RuleSet([$byImporter]))->isKept());
        self::assertTrue($this->engine->evaluate($item('A'), 'Y', new RuleSet([$byImporter]))->isKept());
        self::assertNotEmpty(Rule::fromArray(['scope' => 'sources', 'action' => 'exclude', 'conditions' => [self::kw('x')]])->validate());
    }

    /** Case 25. */
    public function testFirstMatchingExcludeIsTheReasonAndLaterOnesAreNotEvaluated(): void
    {
        $first = self::rule('exclude', [self::kw('alpha')], ['name' => 'First', 'id' => 'rfirst']);
        $second = self::rule('exclude', [self::kw('alpha')], ['name' => 'Second', 'id' => 'rsecond']);
        $this->saveRules($first, $second);
        $decision = $this->engine->evaluate(self::item(['title' => 'alpha']), 'imp');
        self::assertFalse($decision->isKept());
        self::assertCount(1, $decision->reasons());
        self::assertSame('First', $decision->reasons()[0]->ruleName);
        self::assertSame('alpha', $decision->reasons()[0]->matched);
        self::assertSame('title_body', $decision->reasons()[0]->field);
        $this->counters->flush();
        self::assertSame(1, $this->counters->totals('rfirst')['hits']);
        self::assertSame(0, $this->counters->totals('rsecond')['hits']);
    }

    /** Case 26. */
    public function testIncludeRequirement(): void
    {
        $include = self::rule('include', [self::kw('cricket')]);
        $decision = $this->engine->evaluate(self::item(['title' => 'football']), 'imp', new RuleSet([$include]));
        self::assertFalse($decision->isKept());
        self::assertSame(Reason::NO_INCLUDE, $decision->reasons()[0]->outcome);

        $scoped = self::rule('include', [self::kw('cricket')], ['scope' => 'sources', 'scope_values' => ['other']]);
        self::assertTrue($this->engine->evaluate(self::item(['title' => 'football']), 'imp', new RuleSet([$scoped]))->isKept());
        self::assertTrue($this->engine->evaluate(self::item(['title' => 'cricket']), 'imp', new RuleSet([$include]))->isKept());
    }

    /** Case 27. */
    public function testExcludeWinsOverInclude(): void
    {
        $set = new RuleSet([self::rule('include', [self::kw('cricket')]), self::rule('exclude', [self::kw('betting')])]);
        self::assertFalse($this->engine->evaluate(self::item(['title' => 'cricket betting tips']), 'imp', $set)->isKept());
    }

    /** Ruling 1: a keep rule beats every exclude rule and the include requirement. */
    public function testKeepBeatsExcludeAndInclude(): void
    {
        $keep = self::rule('keep', [['type' => 'url_prefix', 'prefixes' => 'trusted.example.org']], ['name' => 'Trusted']);
        $set = new RuleSet([
            self::rule('exclude', [self::kw('betting')]),
            self::rule('include', [self::kw('cricket')]),
            $keep,
        ]);
        $decision = $this->engine->evaluate(
            self::item(['url' => 'https://trusted.example.org/a', 'title' => 'betting odds']),
            'imp',
            $set
        );
        self::assertTrue($decision->isKept());
        self::assertSame('Trusted', $decision->reasons()[0]->ruleName);
        self::assertSame(Engine::OUTCOME_KEEP, $decision->reasons()[0]->outcome);
        self::assertFalse($this->engine->evaluate(self::item(['title' => 'betting odds']), 'imp', $set)->isKept());

        $testKeep = $keep->withState(Rule::STATE_TEST);
        $set = new RuleSet([self::rule('exclude', [self::kw('betting')]), $testKeep]);
        $decision = $this->engine->evaluate(self::item(['url' => 'https://trusted.example.org/a', 'title' => 'betting']), 'imp', $set);
        self::assertFalse($decision->isKept());
        self::assertSame(Engine::OUTCOME_WOULD_KEEP, $decision->wouldReasons()[0]->outcome);
    }

    /** Case 28. */
    public function testTestStateRulesOnlyRecord(): void
    {
        $rule = self::rule('exclude', [self::kw('alpha')], ['state' => 'test']);
        $decision = $this->engine->evaluate(self::item(['title' => 'alpha']), 'imp', new RuleSet([$rule]));
        self::assertTrue($decision->isKept());
        self::assertSame([], $decision->reasons());
        self::assertCount(1, $decision->wouldReasons());
        self::assertSame(Engine::OUTCOME_WOULD_DROP, $decision->wouldReasons()[0]->outcome);
        $this->counters->flush();
        self::assertSame(1, $this->counters->totals($rule->id)['would']);
        self::assertSame(0, $this->counters->totals($rule->id)['hits']);
    }

    /** Case 29. */
    public function testOffRulesAreInvisible(): void
    {
        $rule = self::rule('exclude', [self::kw('alpha')], ['state' => 'off']);
        $decision = $this->engine->evaluate(self::item(['title' => 'alpha']), 'imp', new RuleSet([$rule]));
        self::assertTrue($decision->isKept());
        self::assertStringNotContainsString($rule->id, (string) json_encode($decision->toArray()));
    }

    public function testDecidedActionAndDropLogLine(): void
    {
        $this->saveRules(self::rule('exclude', [self::kw('alpha')], ['name' => 'No alpha']));
        add_filter('feed_rules_error_log', '__return_true');
        $this->engine->filter(['url' => 'https://example.com/a', 'title' => 'alpha'], 'imp');
        self::assertSame(1, did_action('feed_rules_decided'));
        self::assertContains('feed-rules drop importer=imp rule=No alpha url=https://example.com/a', $this->log);
    }

    public function testDecisionFilterCanChangeVerdict(): void
    {
        $this->saveRules(self::rule('exclude', [self::kw('alpha')]));
        add_filter('feed_rules_decision', static fn ($d) => $d->withKept(true), 10, 3);
        self::assertIsArray($this->engine->filter(['url' => 'https://example.com/a', 'title' => 'alpha'], 'imp'));
    }

    public function testDecisionRoundTrip(): void
    {
        $this->saveRules(self::rule('categorise', [self::kw('alpha')], ['categories' => [3]]));
        $decision = $this->engine->evaluate(self::item(['title' => 'alpha']), 'imp');
        $copy = \IndianOpinion\FeedRules\Decision::fromArray(json_decode((string) json_encode($decision->toArray()), true));
        self::assertEquals($decision->toArray(), $copy->toArray());
    }
}
