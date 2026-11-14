<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * The normalised text of every field of one item, computed once, with
 * folded and unaccented variants made on demand.
 */
final class FieldTexts
{
    /** @var array<string, string> */
    private array $base;

    /** @var array<string, string> */
    private array $variants = [];

    private bool $truncated;

    public function __construct(private Item $item, int $window)
    {
        $filter = static function (string $text, string $field) use ($item): string {
            if ($item->isNormalised() || !function_exists('apply_filters')) {
                return $text;
            }
            $filtered = apply_filters('feed_rules_item_text', $text, $field, $item);
            return is_string($filtered) ? $filtered : $text;
        };
        $normalise = static function (string $text) use ($item): string {
            return $item->isNormalised() ? Text::plain($text) : Text::normalise($text);
        };

        $title = $normalise($filter($item->title(), 'title'));
        [$body, $cut] = Text::window($normalise($filter($item->body(), 'body')), $window);
        $this->truncated = $cut || $item->wasTruncated();
        $terms = [];
        foreach (array_merge($item->categories(), $item->tags()) as $term) {
            $term = Text::plain($term);
            if ($term !== '') {
                $terms[] = $term;
            }
        }
        $this->base = [
            'title' => $title,
            'body' => $body,
            'title_body' => trim($title . ' ' . $body),
            'url' => Text::scrub($filter($item->url(), 'url')),
            'source_name' => $normalise($filter($item->sourceName(), 'source_name')),
            'author' => $normalise($filter($item->author(), 'author')),
            'source_terms' => implode(Text::TERM_SEPARATOR, $terms),
        ];
    }

    public function item(): Item
    {
        return $this->item;
    }

    public function bodyTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * The text of a field, optionally case-folded and with accents removed.
     */
    public function get(string $field, bool $fold = false, bool $unaccent = false): string
    {
        $base = $this->base[$field] ?? '';
        if (!$fold && !$unaccent) {
            return $base;
        }
        $key = $field . '|' . (int) $fold . (int) $unaccent;
        if (!isset($this->variants[$key])) {
            $text = $fold ? Text::fold($base) : $base;
            $this->variants[$key] = $unaccent ? Text::unaccent($text) : $text;
        }
        return $this->variants[$key];
    }

    /**
     * Normalised fields for the recent-items store.
     *
     * @return array{title: string, body: string, source_name: string, author: string}
     */
    public function stored(): array
    {
        return [
            'title' => $this->base['title'],
            'body' => $this->base['body'],
            'source_name' => $this->base['source_name'],
            'author' => $this->base['author'],
        ];
    }
}
