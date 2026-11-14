<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Admin;

use IndianOpinion\FeedRules\Adapter\CoreFeedAdapter;
use IndianOpinion\FeedRules\Rule;
use IndianOpinion\FeedRules\RuleSet;
use IndianOpinion\FeedRules\Settings;
use IndianOpinion\FeedRules\Tester;
use IndianOpinion\FeedRules\Item;

/**
 * Turns submitted admin forms into rules, rule sets and settings.
 * Pure functions over arrays (already unslashed), so they can be tested.
 */
final class Forms
{
    /**
     * @param array<mixed> $post
     * @param (callable(string): ?int)|null $tagResolver Turns a tag name into a term id.
     */
    public static function rule(array $post, ?callable $tagResolver = null): Rule
    {
        $raw = is_array($post['rule'] ?? null) ? $post['rule'] : [];
        $conditions = [];
        foreach (is_array($post['conditions'] ?? null) ? $post['conditions'] : [] as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $type = self::str($condition, 'type');
            $body = match ($type) {
                'regex' => self::str($condition, 'pattern'),
                'url_prefix' => self::str($condition, 'prefixes'),
                default => self::str($condition, 'keywords'),
            };
            if (trim($body) === '') {
                continue;
            }
            $conditions[] = [
                'type' => $type,
                'field' => self::str($condition, 'field'),
                'keywords' => self::str($condition, 'keywords'),
                'pattern' => self::str($condition, 'pattern'),
                'prefixes' => self::str($condition, 'prefixes'),
                'mode' => self::str($condition, 'mode'),
                'whole_words' => !empty($condition['whole_words']),
                'case_sensitive' => !empty($condition['case_sensitive']),
                'accent_insensitive' => !empty($condition['accent_insensitive']),
                'negate' => !empty($condition['negate']),
            ];
        }
        $tags = [];
        foreach (preg_split('/,/', self::str($raw, 'tags')) ?: [] as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            if (ctype_digit($name)) {
                $tags[] = (int) $name;
            } elseif ($tagResolver !== null) {
                $id = $tagResolver($name);
                if ($id !== null) {
                    $tags[] = $id;
                }
            }
        }
        return Rule::fromArray([
            'id' => self::str($raw, 'id'),
            'name' => sanitize_text_field(self::str($raw, 'name')),
            'state' => self::str($raw, 'state') !== '' ? self::str($raw, 'state') : Rule::STATE_TEST,
            'scope' => self::str($raw, 'scope'),
            'scope_values' => self::str($raw, 'scope_values'),
            'action' => self::str($raw, 'action'),
            'categories' => is_array($raw['categories'] ?? null) ? $raw['categories'] : [],
            'category_mode' => self::str($raw, 'category_mode'),
            'tags' => $tags,
            'author' => self::str($raw, 'author'),
            'conditions' => $conditions,
        ]);
    }

    /**
     * The number of duplicate keywords removed from a rule's conditions.
     */
    public static function duplicates(Rule $rule): int
    {
        $count = 0;
        foreach ($rule->conditions as $condition) {
            $count += $condition->duplicatesRemoved;
        }
        return $count;
    }

    /**
     * The rule set a test run should use.
     *
     * Modes: "set" (saved rules), "rule:<id>" (one saved rule), "draft"
     * (the rule in the edit form alone), "set_draft" (saved rules with the draft).
     *
     * @param array<mixed> $post
     */
    public static function testSet(array $post, RuleSet $saved, ?callable $tagResolver = null): RuleSet
    {
        $mode = self::str($post, 'test_mode');
        if ($mode === 'draft') {
            return new RuleSet([self::rule($post, $tagResolver)]);
        }
        if ($mode === 'set_draft') {
            return $saved->withRule(self::rule($post, $tagResolver));
        }
        if (str_starts_with($mode, 'rule:')) {
            $rule = $saved->find(substr($mode, 5));
            return new RuleSet($rule === null ? [] : [$rule]);
        }
        return $saved;
    }

    /**
     * Pasted sample items from the test form.
     *
     * @param array<mixed> $post
     * @return list<Item>
     */
    public static function samples(array $post): array
    {
        $titles = is_array($post['sample_title'] ?? null) ? $post['sample_title'] : [];
        $bodies = is_array($post['sample_body'] ?? null) ? $post['sample_body'] : [];
        $urls = is_array($post['sample_url'] ?? null) ? $post['sample_url'] : [];
        $out = [];
        foreach ($titles as $i => $title) {
            $title = is_string($title) ? $title : '';
            $body = is_string($bodies[$i] ?? null) ? $bodies[$i] : '';
            if (trim($title) === '' && trim($body) === '') {
                continue;
            }
            $url = is_string($urls[$i] ?? null) ? $urls[$i] : '';
            $out[] = Tester::pastedItem($title, $body, $url, count($out) + 1);
        }
        return $out;
    }

    /**
     * @param array<mixed> $post
     * @return array<string, mixed>
     */
    public static function settings(array $post): array
    {
        $feeds = [];
        foreach (is_array($post['feeds'] ?? null) ? $post['feeds'] : [] as $row) {
            if (is_array($row)) {
                $feed = CoreFeedAdapter::normaliseFeed($row);
                if ($feed !== null) {
                    unset($feed['key']);
                    $feeds[] = $feed;
                }
            }
        }
        $int = static fn (string $k, int $d): int => is_numeric($post[$k] ?? null) ? (int) $post[$k] : $d;
        return [
            'enabled' => !empty($post['enabled']),
            'recent_limit' => min(Settings::RECENT_MAX, max(Settings::RECENT_MIN, $int('recent_limit', 100))),
            'body_window' => max(100, min(1000000, $int('body_window', 2000))),
            'category_cap' => max(1, min(50, $int('category_cap', 10))),
            'copy_source_tags' => !empty($post['copy_source_tags']),
            'create_tags' => !empty($post['create_tags']),
            'remove_default_category' => !empty($post['remove_default_category']),
            'category_map' => self::map(self::str($post, 'category_map'), true),
            'author_names' => self::map(self::str($post, 'author_names'), true),
            'author_sources' => self::map(self::str($post, 'author_sources'), false),
            'feeds' => $feeds,
        ];
    }

    /**
     * Parse "name = id" lines.
     *
     * @return array<string, int>
     */
    public static function map(string $text, bool $normaliseNames): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $pos = strrpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($line, 0, $pos));
            $id = trim(substr($line, $pos + 1));
            if ($name === '' || !ctype_digit($id) || (int) $id < 1) {
                continue;
            }
            $out[$normaliseNames ? Settings::mapKey($name) : $name] = (int) $id;
        }
        return $out;
    }

    /**
     * @param array<string, int> $map
     */
    public static function mapText(array $map): string
    {
        $lines = [];
        foreach ($map as $name => $id) {
            $lines[] = $name . ' = ' . $id;
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<mixed> $data
     */
    public static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }
}
