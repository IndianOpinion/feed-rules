<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Tests\Unit;

use IndianOpinion\FeedRules\FieldTexts;
use IndianOpinion\FeedRules\Text;
use IndianOpinion\FeedRules\Tests\TestCase;

final class TextTest extends TestCase
{
    /** Case 4. */
    public function testNormalisationStripsDecodesRepairsAndCollapses(): void
    {
        $html = "<script>x</script>foo<br>bar &amp;amp; caf\xC3\xA9 bad\xFFbyte no\u{00A0}break zero\u{200B}width<style>.a{}</style>";
        $text = Text::normalise($html);
        self::assertSame('foo bar & café bad?byte no break zero width', $text);
        self::assertTrue(mb_check_encoding($text, 'UTF-8'));
        self::assertSame(1, preg_match('/bad.byte/u', $text));
    }

    public function testBlockBoundariesDoNotJoinWords(): void
    {
        self::assertSame('one two three', Text::normalise('<p>one</p><div>two</div><li>three</li>'));
    }

    public function testCaseFoldingIsFullUnicode(): void
    {
        self::assertSame('école', Text::fold('ÉCOLE'));
        self::assertSame('strasse', Text::fold('STRAßE'));
    }

    /** Case 5. */
    public function testBodyWindowCutsOnCharacterBoundary(): void
    {
        $body = str_repeat('a', 1999) . 'é' . ' needle';
        $texts = new FieldTexts(self::item(['body' => $body]), 2000);
        self::assertTrue($texts->bodyTruncated());
        $cut = $texts->get('body');
        self::assertSame(2000, mb_strlen($cut));
        self::assertTrue(mb_check_encoding($cut, 'UTF-8'));
        self::assertStringEndsWith('é', $cut);
    }

    /** Case 5: a keyword after the window does not match and the decision says the body was cut. */
    public function testKeywordAfterWindowDoesNotMatch(): void
    {
        $rule = self::rule('exclude', [self::kw('needle', 'body')]);
        $decision = $this->engine->evaluate(
            self::item(['body' => str_repeat('word ', 500) . 'needle']),
            'test',
            new \IndianOpinion\FeedRules\RuleSet([$rule]),
            true
        );
        self::assertTrue($decision->isKept());
        self::assertTrue($decision->bodyTruncated());

        $this->settings->update(['body_window' => 50000]);
        $decision = $this->engine->evaluate(
            self::item(['body' => str_repeat('word ', 500) . 'needle']),
            'test',
            new \IndianOpinion\FeedRules\RuleSet([$rule]),
            true
        );
        self::assertFalse($decision->isKept());
    }

    public function testBodyWindowFilterWins(): void
    {
        add_filter('feed_rules_body_window', static fn (): int => 10);
        self::assertSame(10, $this->settings->bodyWindow());
    }

    /** Case 6. */
    public function testItemTextFilterRemovesImporterBoilerplate(): void
    {
        $rule = self::rule('exclude', [self::kw('subscribe now', 'body')]);
        $item = ['body' => '<p>Real story.</p><p class="footer">Subscribe now for more</p>'];
        self::assertTrue($this->ruleMatches($rule, $item));
        add_filter('feed_rules_item_text', static function (string $text, string $field): string {
            return $field === 'body' ? (string) preg_replace('#<p class="footer">.*?</p>#s', '', $text) : $text;
        }, 10, 3);
        self::assertFalse($this->ruleMatches($rule, $item));
    }
}
