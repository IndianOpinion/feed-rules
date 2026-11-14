<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Tests\Unit;

use IndianOpinion\FeedRules\Exception\InvalidRuleException;
use IndianOpinion\FeedRules\RegexPattern;
use IndianOpinion\FeedRules\Rule;
use IndianOpinion\FeedRules\RuleSet;
use IndianOpinion\FeedRules\Tests\TestCase;

final class RegexTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function re(string $pattern, string $field = 'title_body', bool $negate = false): array
    {
        return ['type' => 'regex', 'field' => $field, 'pattern' => $pattern, 'negate' => $negate];
    }

    /** Case 18. */
    public function testFlagsAndDelimiters(): void
    {
        self::assertNotNull(RegexPattern::problem('/x/e'));
        self::assertNotNull(RegexPattern::problem('x'));
        self::assertNotNull(RegexPattern::problem('/x/g'));
        self::assertNull(RegexPattern::problem('/x/imsx'));
        self::assertNull(RegexPattern::problem('#paid\s+partnership#i'));
        self::assertNull(RegexPattern::problem('{a}'));
        self::assertSame('/x/imsxu', RegexPattern::compiled('/x/imsx'));

        $rule = self::rule('exclude', [self::re('/PAID\s+partnership/i')]);
        self::assertSame([], $rule->validate());
        self::assertTrue($this->ruleMatches($rule, ['title' => 'A paid  partnership with é']));
    }

    /** Case 19. */
    public function testInvalidPatternIsRejectedWithPcreMessageAndSavedRuleKept(): void
    {
        $good = self::rule('exclude', [self::re('/ok/')], ['id' => 'rfixed']);
        $this->saveRules($good);
        $bad = self::rule('exclude', [self::re('/(unbalanced/')], ['id' => 'rfixed']);
        $errors = $bad->validate();
        self::assertCount(1, $errors);
        self::assertStringContainsString('missing closing parenthesis', (string) $errors[0]);

        try {
            $this->repository->save($this->repository->load()->withRule($bad), $this->repository->load()->editVersion);
            self::fail('expected InvalidRuleException');
        } catch (InvalidRuleException $e) {
            self::assertNotEmpty($e->errors());
        }
        $this->repository->forget();
        $stored = $this->repository->load()->find('rfixed');
        self::assertNotNull($stored);
        self::assertSame('/ok/', $stored->conditions[0]->pattern);
    }

    /** Case 20: exclude in error keeps the item, counts the error and shows the badge. */
    public function testRuntimeErrorFailsOpenForExclude(): void
    {
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000');
        try {
            $rule = self::rule('exclude', [self::re('/(\w+\s?)*$/', 'title')], ['name' => 'Catastrophic']);
            $this->saveRules($rule);
            $result = $this->engine->filter(['url' => 'https://example.com/a', 'title' => str_repeat('ab ', 5000) . '!'], 'test');
            self::assertIsArray($result);
            $this->counters->flush();
            self::assertSame(1, $this->counters->totals($rule->id)['errors']);
            self::assertSame([$rule->id], $this->counters->rulesWithErrors());
            self::assertArrayHasKey('last_error', $this->counters->ruleInfo($rule->id));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
    }

    /** Case 20: an include rule in error waives the include requirement. */
    public function testRuntimeErrorWaivesIncludeRequirement(): void
    {
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000');
        try {
            $rule = self::rule('include', [self::re('/(\w+\s?)*$/', 'title')]);
            $decision = $this->engine->evaluate(
                self::item(['title' => str_repeat('ab ', 5000) . '!']),
                'test',
                new RuleSet([$rule])
            );
            self::assertTrue($decision->isKept());
            self::assertCount(1, $decision->errors());
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
    }

    /** Case 21. */
    public function testLengthLimitAndNegation(): void
    {
        $long = '/' . str_repeat('a', 1998) . '/';
        self::assertSame(2000, strlen($long));
        self::assertNull(RegexPattern::problem($long));
        self::assertNotNull(RegexPattern::problem('/' . str_repeat('a', 1999) . '/'));

        $negated = self::rule('exclude', [self::re('/approved/', 'title', true)]);
        self::assertFalse($this->ruleMatches($negated, ['title' => 'approved story']));
        self::assertTrue($this->ruleMatches($negated, ['title' => 'random story']));
    }

    public function testPatternErrorsNeverEmitWarnings(): void
    {
        $error = null;
        self::assertNull(RegexPattern::run('/(/u', 'x', $error));
        self::assertIsString($error);
        self::assertSame(Rule::STATE_LIVE, self::rule('exclude', [self::re('/x/')])->state);
    }
}
