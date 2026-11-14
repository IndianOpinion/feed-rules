<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Tests\Unit;

use IndianOpinion\FeedRules\Condition;
use IndianOpinion\FeedRules\KeywordList;
use IndianOpinion\FeedRules\Matcher;
use IndianOpinion\FeedRules\Rule;
use IndianOpinion\FeedRules\Tests\TestCase;

final class KeywordMatchingTest extends TestCase
{
    /** Case 7. */
    public function testCaseInsensitiveByDefaultAndSwitchable(): void
    {
        self::assertTrue($this->ruleMatches(self::rule('exclude', [self::kw('ELECTION')]), ['title' => 'The election today']));
        self::assertTrue($this->ruleMatches(self::rule('exclude', [self::kw('ÉCOLE')]), ['title' => 'Une école ici']));
        $sensitive = self::rule('exclude', [self::kw('Apple', 'title_body', ['case_sensitive' => true])]);
        self::assertFalse($this->ruleMatches($sensitive, ['title' => 'an apple a day']));
        self::assertTrue($this->ruleMatches($sensitive, ['title' => 'Apple results']));
    }

    /** Case 8. */
    public function testWholeWordsUseUnicodeClassesNotBackslashB(): void
    {
        $hindi = self::rule('exclude', [self::kw('चुनाव')]);
        self::assertTrue($this->ruleMatches($hindi, ['title' => 'आज चुनाव है']));
        self::assertFalse($this->ruleMatches($hindi, ['title' => 'आज चुनावों के नतीजे']));

        // Tamil "kadai" followed by a vowel sign must not match.
        $tamil = self::rule('exclude', [self::kw('கட')]);
        self::assertFalse($this->ruleMatches($tamil, ['title' => 'இந்த கடை திறந்தது']));
        self::assertTrue($this->ruleMatches($tamil, ['title' => 'இந்த கட திறந்தது']));

        self::assertFalse($this->ruleMatches(self::rule('exclude', [self::kw('cat')]), ['title' => 'A category page']));
        self::assertTrue($this->ruleMatches(self::rule('exclude', [self::kw('cat')]), ['title' => 'The cat sat']));
    }

    /** Case 9. */
    public function testSubstringMode(): void
    {
        $rule = self::rule('exclude', [self::kw('cat', 'title_body', ['whole_words' => false])]);
        self::assertTrue($this->ruleMatches($rule, ['title' => 'A category page']));
    }

    /** Case 10. */
    public function testPhrasesMatchAcrossAnyWhitespace(): void
    {
        $rule = self::rule('exclude', [self::kw('prime minister')]);
        self::assertTrue($this->ruleMatches($rule, ['title' => 'The Prime  Minister spoke']));
        self::assertTrue($this->ruleMatches($rule, ['title' => 'x', 'body' => "<p>the prime\nminister</p>"]));
        self::assertFalse($this->ruleMatches($rule, ['title' => 'prime ministers']));
    }

    /** Case 11. */
    public function testTrailingStarIsAPrefixAndStarElsewhereIsRejected(): void
    {
        $rule = self::rule('exclude', [self::kw('elect*')]);
        self::assertTrue($this->ruleMatches($rule, ['title' => 'electoral roll']));
        self::assertTrue($this->ruleMatches($rule, ['title' => 'Election day']));
        self::assertFalse($this->ruleMatches($rule, ['title' => 'select a seat']));

        $bad = self::rule('exclude', [self::kw("ok\nel*ct")]);
        $errors = $bad->validate();
        self::assertCount(1, $errors);
        self::assertStringContainsString('line 2', (string) $errors[0]);
        self::assertStringContainsString('el*ct', (string) $errors[0]);
    }

    /** Case 12. */
    public function testMetacharactersAreLiteral(): void
    {
        $rule = self::rule('exclude', [self::kw('c++ (beta)')]);
        self::assertSame([], $rule->validate());
        self::assertTrue($this->ruleMatches($rule, ['title' => 'Release of c++ (beta) today']));
        self::assertFalse($this->ruleMatches($rule, ['title' => 'Release of c (beta) today']));
    }

    /** Case 13. */
    public function testListParsingFromTextAndArray(): void
    {
        $text = "  Alpha \n\n# a comment\nbeta\nALPHA\n\tgamma\nBeta\n";
        $parsed = KeywordList::parse($text);
        self::assertSame(['Alpha', 'beta', 'gamma'], $parsed['keywords']);
        self::assertSame(2, $parsed['duplicates']);

        $fromArray = KeywordList::parse(['  Alpha ', '', '# a comment', 'beta', 'ALPHA', 'gamma', 'Beta']);
        self::assertSame($parsed['keywords'], $fromArray['keywords']);
        self::assertSame(2, $fromArray['duplicates']);

        $condition = Condition::fromArray(self::kw($text));
        self::assertSame(2, $condition->duplicatesRemoved);
    }

    /** Case 14. */
    public function testAccentInsensitiveIsOptional(): void
    {
        self::assertFalse($this->ruleMatches(self::rule('exclude', [self::kw('cafe')]), ['title' => 'Le café']));
        $on = self::rule('exclude', [self::kw('cafe', 'title_body', ['accent_insensitive' => true])]);
        self::assertTrue($this->ruleMatches($on, ['title' => 'Le café']));
    }

    /** Case 15. */
    public function testFieldsAreIndependent(): void
    {
        $item = [
            'url' => 'https://example.com/zeta-url',
            'title' => 'alpha',
            'body' => 'beta',
            'source_name' => 'gamma',
            'author' => 'delta',
            'categories' => ['epsilon'],
            'tags' => ['eta'],
        ];
        $where = [
            'title' => 'alpha',
            'body' => 'beta',
            'source_name' => 'gamma',
            'author' => 'delta',
            'source_terms' => 'epsilon',
            'url' => 'zeta',
        ];
        foreach ($where as $field => $word) {
            foreach (Condition::FIELDS as $other) {
                if ($other === 'title_body') {
                    continue;
                }
                $hit = $this->ruleMatches(self::rule('exclude', [self::kw($word, $other)]), $item);
                self::assertSame($other === $field, $hit, "$word in $other");
            }
        }
        self::assertTrue($this->ruleMatches(self::rule('exclude', [self::kw('alpha', 'title_body')]), $item));
        self::assertTrue($this->ruleMatches(self::rule('exclude', [self::kw('beta', 'title_body')]), $item));
    }

    public function testSourceTermsSeparatorStopsCrossTermPhrases(): void
    {
        $rule = self::rule('exclude', [self::kw('north south', 'source_terms')]);
        self::assertFalse($this->ruleMatches($rule, ['categories' => ['North'], 'tags' => ['South']]));
        self::assertTrue($this->ruleMatches($rule, ['categories' => ['North South']]));
    }

    /** Case 16. */
    public function testAnyAndAllModes(): void
    {
        $any = self::rule('exclude', [self::kw(['red', 'blue'])]);
        $all = self::rule('exclude', [self::kw(['red', 'blue'], 'title_body', ['mode' => 'all'])]);
        self::assertTrue($this->ruleMatches($any, ['title' => 'a red car']));
        self::assertFalse($this->ruleMatches($all, ['title' => 'a red car']));
        self::assertTrue($this->ruleMatches($all, ['title' => 'a red car', 'body' => 'and a blue one']));
    }

    /** Case 17. */
    public function testLongListsCompileIntoChunksAndLimitIsEnforced(): void
    {
        $keywords = [];
        for ($i = 0; $i < 9000; $i++) {
            $keywords[] = 'keyword' . $i . ' phrase';
        }
        $rule = self::rule('exclude', [self::kw($keywords)]);
        self::assertSame([], $rule->validate());
        $patterns = Matcher::keywordPatterns($rule->conditions[0]);
        self::assertGreaterThan(1, count($patterns));
        foreach ($patterns as $pattern) {
            self::assertLessThanOrEqual(Matcher::CHUNK_BYTES + 20, strlen($pattern));
        }
        self::assertTrue($this->ruleMatches($rule, ['title' => 'about keyword8999 phrase here']));
        self::assertFalse($this->ruleMatches($rule, ['title' => 'about keyword9000 phrase here']));

        $keywords[] = 'x';
        for ($i = 0; $i < 1000; $i++) {
            $keywords[] = 'extra' . $i;
        }
        $tooMany = self::rule('exclude', [self::kw($keywords)]);
        self::assertCount(10001, $tooMany->conditions[0]->keywords);
        $errors = $tooMany->validate();
        self::assertNotEmpty($errors);
        self::assertStringContainsString('10000', (string) $errors[0]);
    }

    public function testRulesAreValidated(): void
    {
        self::assertSame(Rule::STATE_TEST, Rule::fromArray(['conditions' => [self::kw('x')]])->state);
    }
}
