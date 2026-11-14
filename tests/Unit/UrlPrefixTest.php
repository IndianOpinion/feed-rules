<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Tests\Unit;

use IndianOpinion\FeedRules\Tests\TestCase;
use IndianOpinion\FeedRules\UrlPrefix;

final class UrlPrefixTest extends TestCase
{
    /** Case 22. */
    public function testHostAndPathPrefixes(): void
    {
        $rule = UrlPrefix::normaliseRule('https://www.example.com/jobs');
        self::assertSame('example.com/jobs', $rule);
        self::assertTrue(UrlPrefix::matches($rule, 'https://m.example.com/jobs/1'));
        self::assertTrue(UrlPrefix::matches($rule, 'https://news.example.com/JOBS'));
        self::assertTrue(UrlPrefix::matches($rule, 'https://example.com/jobs?ref=1#top'));
        self::assertFalse(UrlPrefix::matches($rule, 'https://badexample.com/jobs'));
        self::assertFalse(UrlPrefix::matches($rule, 'https://example.com/jobsearch'));
        self::assertTrue(UrlPrefix::matches('example.com', 'https://amp.example.com/anything'));
        self::assertTrue(UrlPrefix::matches('example.com', 'http://mobile.example.com/'));
    }

    public function testUrlPrefixConditionInARule(): void
    {
        $rule = self::rule('exclude', [['type' => 'url_prefix', 'prefixes' => "https://www.example.com/jobs\n"]]);
        self::assertSame([], $rule->validate());
        self::assertTrue($this->ruleMatches($rule, ['url' => 'https://m.example.com/jobs/1?utm_source=x']));
        self::assertFalse($this->ruleMatches($rule, ['url' => 'https://example.com/jobsearch']));
        self::assertNotEmpty(self::rule('exclude', [['type' => 'url_prefix', 'prefixes' => 'not a host!']])->validate());
    }
}
