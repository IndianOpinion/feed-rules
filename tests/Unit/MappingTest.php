<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Tests\Unit;

use IndianOpinion\FeedRules\Adapter;
use IndianOpinion\FeedRules\Engine;
use IndianOpinion\FeedRules\Item;
use IndianOpinion\FeedRules\RuleSet;
use IndianOpinion\FeedRules\Tests\TestCase;

final class MappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        for ($i = 1; $i <= 12; $i++) {
            \WpStub::addTerm($i, 'Cat ' . $i, 'category');
        }
        \WpStub::addTerm(1, 'Uncategorised', 'category');
        \WpStub::$options['default_category'] = 1;
    }

    /** Case 30. */
    public function testCategoryUnionInRuleOrderWithCap(): void
    {
        $set = new RuleSet([
            self::rule('categorise', [self::kw('x')], ['categories' => [2, 3, 4, 5]]),
            self::rule('categorise', [self::kw('x')], ['categories' => [5, 6, 7, 8], 'name' => 'b']),
            self::rule('categorise', [self::kw('x')], ['categories' => [9, 10, 11, 12], 'name' => 'c']),
        ]);
        $decision = $this->engine->evaluate(self::item(['title' => 'x']), 'imp', $set);
        self::assertSame([2, 3, 4, 5, 6, 7, 8, 9, 10, 11], $decision->categories());
    }

    /** Case 31. */
    public function testSourceCategoryTable(): void
    {
        $this->settings->update(['category_map' => ['cricket' => 7]]);
        $decision = $this->engine->evaluate(self::item(['categories' => ['Cricket ']]), 'imp', new RuleSet());
        self::assertSame([7], $decision->categories());
    }

    /** Case 32. */
    public function testTagRulesAndSourceTagCopying(): void
    {
        \WpStub::addTerm(50, 'politics', 'post_tag');
        for ($i = 0; $i < 12; $i++) {
            \WpStub::addTerm(60 + $i, 'existing' . $i, 'post_tag');
        }
        $set = new RuleSet([self::rule('tag', [self::kw('x')], ['tags' => [50]])]);
        $sourceTags = array_merge(['brand-new'], array_map(static fn (int $i): string => 'existing' . $i, range(0, 11)));
        $item = self::item(['title' => 'x', 'tags' => $sourceTags]);

        $decision = $this->engine->evaluate($item, 'imp', $set);
        self::assertSame([50], $decision->tags());

        $this->settings->update(['copy_source_tags' => true]);
        $decision = $this->engine->evaluate($item, 'imp', $set);
        $this->engine->applyToPost(10, $decision);
        $tags = \WpStub::$postTerms[10]['post_tag'];
        self::assertContains(50, $tags);
        self::assertCount(11, $tags, 'the rule tag plus ten copied tags');
        self::assertNull(term_exists('brand-new', 'post_tag'));

        $this->settings->update(['create_tags' => true]);
        $this->engine->applyToPost(11, $this->engine->evaluate($item, 'imp', $set));
        self::assertNotNull(term_exists('brand-new', 'post_tag'));
    }

    /** Case 33. */
    public function testAuthorMapping(): void
    {
        \WpStub::addUser(2, 'Editor');
        \WpStub::addUser(3, 'Second');
        \WpStub::addUser(4, 'Subscriber', ['read']);
        \WpStub::addUser(9, 'Default');
        $first = self::rule('author', [self::kw('x')], ['author' => 2, 'name' => 'first']);
        $second = self::rule('author', [self::kw('x')], ['author' => 3, 'name' => 'second']);
        self::assertSame(2, $this->engine->evaluate(self::item(['title' => 'x']), 'imp', new RuleSet([$first, $second]))->authorId());

        $adapter = new class implements Adapter {
            public function id(): string
            {
                return 'imp';
            }
            public function label(): string
            {
                return 'Test';
            }
            public function boot(): void
            {
            }
            public function sources(): array
            {
                return [];
            }
            public function defaultAuthor(string $sourceKey): ?int
            {
                return 9;
            }
            public function supportsSampling(): bool
            {
                return false;
            }
            public function sample(int $limit): array
            {
                return [];
            }
        };
        $this->registry->add($adapter);
        $noCap = self::rule('author', [self::kw('x')], ['author' => 4]);
        $usersBefore = \WpStub::$users;
        self::assertSame(9, $this->engine->evaluate(self::item(['title' => 'x']), 'imp', new RuleSet([$noCap]))->authorId());
        self::assertSame($usersBefore, \WpStub::$users);

        $this->settings->update(['author_names' => ['jane doe' => 3]]);
        self::assertSame(3, $this->engine->evaluate(self::item(['author' => ' Jane  DOE ']), 'imp', new RuleSet())->authorId());
    }

    /** Case 34 and ruling 6: append by default, replace opt-in, default category switch. */
    public function testApplyKeepsImporterCategories(): void
    {
        \WpStub::$postTerms[20]['category'] = [1, 3];
        $set = new RuleSet([self::rule('categorise', [self::kw('x')], ['categories' => [5]])]);
        $decision = $this->engine->evaluate(self::item(['title' => 'x']), 'imp', $set);
        $this->engine->applyToPost(20, $decision);
        self::assertSame([1, 3, 5], \WpStub::$postTerms[20]['category']);

        $this->settings->update(['remove_default_category' => true]);
        \WpStub::$postTerms[21]['category'] = [1, 3];
        $this->engine->applyToPost(21, $decision);
        self::assertSame([3, 5], \WpStub::$postTerms[21]['category']);

        \WpStub::$postTerms[22]['category'] = [1];
        $this->engine->applyToPost(22, $this->engine->evaluate(self::item(['title' => 'nothing']), 'imp', $set));
        self::assertSame([1], \WpStub::$postTerms[22]['category'], 'no mapping added, default stays');

        $replace = new RuleSet([self::rule('categorise', [self::kw('x')], ['categories' => [6], 'category_mode' => 'replace'])]);
        \WpStub::$postTerms[23]['category'] = [1, 3];
        $this->engine->applyToPost(23, $this->engine->evaluate(self::item(['title' => 'x']), 'imp', $replace));
        self::assertSame([6], \WpStub::$postTerms[23]['category']);
    }

    /** Case 35. */
    public function testDeletedCategoryIsSkippedWithWarning(): void
    {
        $rule = self::rule('categorise', [self::kw('x')], ['categories' => [5, 99], 'name' => 'Gone']);
        $this->saveRules($rule);
        $decision = $this->engine->evaluate(self::item(['title' => 'x']), 'imp');
        $this->engine->applyToPost(30, $decision);
        self::assertSame([5], \WpStub::$postTerms[30]['category']);
        $this->counters->flush();
        self::assertStringContainsString('category 99', (string) $this->counters->ruleInfo($rule->id)['warning']);
        self::assertNotEmpty(array_filter($this->log, static fn (string $l): bool => str_contains($l, 'rule=Gone')));
    }

    /** Case 36 and ruling 4: one meta key with the live rule ids; never test-state hits. */
    public function testPostMetaHoldsMatchedLiveRuleIds(): void
    {
        $live = self::rule('categorise', [self::kw('x')], ['categories' => [5], 'id' => 'rlive']);
        $test = self::rule('categorise', [self::kw('x')], ['categories' => [6], 'id' => 'rtest', 'state' => 'test']);
        $this->saveRules($live, $test);
        $result = $this->engine->filter(['url' => 'https://example.com/a', 'title' => 'x'], 'imp');
        self::assertIsArray($result);
        $this->engine->applyAction(40, $result);
        $meta = \WpStub::$meta[40][Engine::POST_META];
        self::assertSame(['rlive'], $meta['rules']);
        self::assertSame(1_763_100_000, $meta['time']);
        self::assertCount(1, \WpStub::$meta[40]);
    }

    /** Case 37. */
    public function testChangingARuleLeavesPostsAlone(): void
    {
        $rule = self::rule('categorise', [self::kw('x')], ['categories' => [5], 'id' => 'rc']);
        $this->saveRules($rule);
        $result = $this->engine->filter(['url' => 'https://example.com/a', 'title' => 'x'], 'imp');
        $this->engine->applyAction(41, $result);
        $before = [\WpStub::$postTerms, \WpStub::$meta, \WpStub::$posts];
        $this->repository->save(
            $this->repository->load()->withRule(self::rule('categorise', [self::kw('y')], ['categories' => [6], 'id' => 'rc'])),
            $this->repository->load()->editVersion
        );
        self::assertSame($before, [\WpStub::$postTerms, \WpStub::$meta, \WpStub::$posts]);
    }

    public function testDroppedDecisionAppliesNothing(): void
    {
        $this->engine->applyToPost(50, new \IndianOpinion\FeedRules\Decision(false));
        self::assertArrayNotHasKey(50, \WpStub::$meta);
        self::assertInstanceOf(Item::class, self::item());
    }
}
