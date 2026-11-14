<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * The outcome of evaluating one item. Immutable.
 */
final class Decision
{
    /**
     * @param list<Reason> $reasons
     * @param list<Reason> $wouldReasons
     * @param list<int> $categories
     * @param list<int|string> $tags Tag ids from rules, and source tag names to copy.
     * @param list<RuleError> $errors
     * @param list<string> $matchedRuleIds Live rules that matched the item.
     * @param array<string, string> $mappingSources "category:<id>", "tag:<id>" or "author" => rule id.
     */
    public function __construct(
        private bool $kept,
        private array $reasons = [],
        private array $wouldReasons = [],
        private array $categories = [],
        private bool $categoryReplace = false,
        private array $tags = [],
        private ?int $authorId = null,
        private array $errors = [],
        private bool $bodyTruncated = false,
        private array $matchedRuleIds = [],
        private array $mappingSources = [],
        private int $time = 0,
    ) {
    }

    public function isKept(): bool
    {
        return $this->kept;
    }

    /** @return list<Reason> */
    public function reasons(): array
    {
        return $this->reasons;
    }

    /** @return list<Reason> Results of test-state rules. */
    public function wouldReasons(): array
    {
        return $this->wouldReasons;
    }

    /** @return list<int> */
    public function categories(): array
    {
        return $this->categories;
    }

    /**
     * True when a matching categorise rule asked to replace the importer's categories.
     */
    public function replacesCategories(): bool
    {
        return $this->categoryReplace;
    }

    /** @return list<int|string> */
    public function tags(): array
    {
        return $this->tags;
    }

    public function authorId(): ?int
    {
        return $this->authorId;
    }

    /** @return list<RuleError> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function bodyTruncated(): bool
    {
        return $this->bodyTruncated;
    }

    /** @return list<string> */
    public function matchedRuleIds(): array
    {
        return $this->matchedRuleIds;
    }

    /** @return array<string, string> */
    public function mappingSources(): array
    {
        return $this->mappingSources;
    }

    public function time(): int
    {
        return $this->time;
    }

    /**
     * A copy with a different keep or drop verdict, for the decision filter.
     */
    public function withKept(bool $kept, ?Reason $reason = null): self
    {
        $copy = clone $this;
        $copy->kept = $kept;
        if ($reason !== null) {
            $copy->reasons[] = $reason;
        }
        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kept' => $this->kept,
            'reasons' => array_map(static fn (Reason $r): array => $r->toArray(), $this->reasons),
            'would' => array_map(static fn (Reason $r): array => $r->toArray(), $this->wouldReasons),
            'categories' => $this->categories,
            'category_replace' => $this->categoryReplace,
            'tags' => $this->tags,
            'author' => $this->authorId,
            'errors' => array_map(static fn (RuleError $e): array => $e->toArray(), $this->errors),
            'body_truncated' => $this->bodyTruncated,
            'matched' => $this->matchedRuleIds,
            'sources' => $this->mappingSources,
            'time' => $this->time,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $reasons = static function (mixed $list): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $entry) {
                if (is_array($entry)) {
                    $out[] = Reason::fromArray($entry);
                }
            }
            return $out;
        };
        $errors = [];
        foreach (is_array($data['errors'] ?? null) ? $data['errors'] : [] as $entry) {
            if (is_array($entry)) {
                $errors[] = RuleError::fromArray($entry);
            }
        }
        $categories = [];
        foreach (is_array($data['categories'] ?? null) ? $data['categories'] : [] as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $categories[] = (int) $id;
            }
        }
        $tags = [];
        foreach (is_array($data['tags'] ?? null) ? $data['tags'] : [] as $tag) {
            if (is_int($tag) && $tag > 0) {
                $tags[] = $tag;
            } elseif (is_string($tag) && $tag !== '') {
                $tags[] = $tag;
            }
        }
        $matched = [];
        foreach (is_array($data['matched'] ?? null) ? $data['matched'] : [] as $id) {
            if (is_string($id)) {
                $matched[] = $id;
            }
        }
        $sources = [];
        foreach (is_array($data['sources'] ?? null) ? $data['sources'] : [] as $key => $ruleId) {
            if (is_string($ruleId)) {
                $sources[(string) $key] = $ruleId;
            }
        }
        $author = $data['author'] ?? null;
        return new self(
            !empty($data['kept']),
            $reasons($data['reasons'] ?? []),
            $reasons($data['would'] ?? []),
            $categories,
            !empty($data['category_replace']),
            $tags,
            is_numeric($author) && (int) $author > 0 ? (int) $author : null,
            $errors,
            !empty($data['body_truncated']),
            $matched,
            $sources,
            is_numeric($data['time'] ?? null) ? (int) $data['time'] : 0,
        );
    }
}
