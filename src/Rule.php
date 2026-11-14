<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * One rule: a state, a scope, one to ten conditions combined with AND, and one action.
 */
final class Rule
{
    public const STATE_OFF = 'off';
    public const STATE_TEST = 'test';
    public const STATE_LIVE = 'live';
    public const STATES = [self::STATE_OFF, self::STATE_TEST, self::STATE_LIVE];

    public const ACTION_EXCLUDE = 'exclude';
    public const ACTION_INCLUDE = 'include';
    public const ACTION_KEEP = 'keep';
    public const ACTION_CATEGORISE = 'categorise';
    public const ACTION_TAG = 'tag';
    public const ACTION_AUTHOR = 'author';
    public const ACTIONS = [
        self::ACTION_EXCLUDE,
        self::ACTION_INCLUDE,
        self::ACTION_KEEP,
        self::ACTION_CATEGORISE,
        self::ACTION_TAG,
        self::ACTION_AUTHOR,
    ];

    public const SCOPE_ALL = 'all';
    public const SCOPE_SOURCES = 'sources';
    public const SCOPE_IMPORTERS = 'importers';
    public const SCOPES = [self::SCOPE_ALL, self::SCOPE_SOURCES, self::SCOPE_IMPORTERS];

    public const MODE_APPEND = 'append';
    public const MODE_REPLACE = 'replace';

    public const MAX_CONDITIONS = 10;

    /**
     * @param list<string> $scopeValues
     * @param list<Condition> $conditions
     * @param list<int> $categories
     * @param list<int> $tags
     */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $state,
        public readonly string $scope,
        public readonly array $scopeValues,
        public readonly array $conditions,
        public readonly string $action,
        public readonly array $categories,
        public readonly string $categoryMode,
        public readonly array $tags,
        public readonly int $authorId,
    ) {
    }

    /**
     * Build a rule from stored data or a form. A missing id is generated;
     * a missing state is `test`. Call validate() before saving.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = is_string($data['id'] ?? null) && $data['id'] !== '' ? self::sanitizeId($data['id']) : self::newId();
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        $conditions = [];
        foreach (is_array($data['conditions'] ?? null) ? $data['conditions'] : [] as $condition) {
            if (is_array($condition)) {
                $conditions[] = Condition::fromArray($condition);
            }
        }
        $scopeValues = $data['scope_values'] ?? [];
        $scopeValues = KeywordList::parse(is_string($scopeValues) || is_array($scopeValues) ? $scopeValues : [])['keywords'];

        return new self(
            $id,
            $name === '' ? 'Rule ' . $id : $name,
            is_string($data['state'] ?? null) ? $data['state'] : self::STATE_TEST,
            is_string($data['scope'] ?? null) ? $data['scope'] : self::SCOPE_ALL,
            $scopeValues,
            $conditions,
            is_string($data['action'] ?? null) ? $data['action'] : self::ACTION_EXCLUDE,
            self::intList($data['categories'] ?? []),
            ($data['category_mode'] ?? self::MODE_APPEND) === self::MODE_REPLACE ? self::MODE_REPLACE : self::MODE_APPEND,
            self::intList($data['tags'] ?? []),
            is_numeric($data['author'] ?? null) ? max(0, (int) $data['author']) : 0,
        );
    }

    public static function newId(): string
    {
        return 'r' . bin2hex(random_bytes(5));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'state' => $this->state,
            'scope' => $this->scope,
            'scope_values' => $this->scopeValues,
            'conditions' => array_map(static fn (Condition $c): array => $c->toArray(), $this->conditions),
            'action' => $this->action,
            'categories' => $this->categories,
            'category_mode' => $this->categoryMode,
            'tags' => $this->tags,
            'author' => $this->authorId,
        ];
    }

    /**
     * A copy with a new id, a "(copy)" name and the test state.
     */
    public function duplicate(): self
    {
        $data = $this->toArray();
        $data['id'] = self::newId();
        $data['name'] = $this->name . ' (copy)';
        $data['state'] = self::STATE_TEST;
        return self::fromArray($data);
    }

    public function withState(string $state): self
    {
        $data = $this->toArray();
        $data['state'] = $state;
        return self::fromArray($data);
    }

    /**
     * @return list<ValidationError>
     */
    public function validate(): array
    {
        $errors = [];
        if (!in_array($this->state, self::STATES, true)) {
            $errors[] = new ValidationError($this->id, 'state', sprintf('unknown state "%s"', $this->state));
        }
        if (!in_array($this->action, self::ACTIONS, true)) {
            $errors[] = new ValidationError($this->id, 'action', sprintf('unknown action "%s"', $this->action));
        }
        if (!in_array($this->scope, self::SCOPES, true)) {
            $errors[] = new ValidationError($this->id, 'scope', sprintf('unknown scope "%s"', $this->scope));
        } elseif ($this->scope !== self::SCOPE_ALL && $this->scopeValues === []) {
            $errors[] = new ValidationError($this->id, 'scope', 'a scoped rule needs at least one source key or importer id');
        }
        $count = count($this->conditions);
        if ($count < 1 || $count > self::MAX_CONDITIONS) {
            $errors[] = new ValidationError(
                $this->id,
                'conditions',
                sprintf('a rule needs 1 to %d conditions, this one has %d', self::MAX_CONDITIONS, $count)
            );
        }
        foreach ($this->conditions as $i => $condition) {
            array_push($errors, ...$condition->validate($this->id, $i));
        }
        if ($this->action === self::ACTION_CATEGORISE && $this->categories === []) {
            $errors[] = new ValidationError($this->id, 'categories', 'a categorise rule needs at least one category');
        }
        if ($this->action === self::ACTION_TAG && $this->tags === []) {
            $errors[] = new ValidationError($this->id, 'tags', 'a tag rule needs at least one tag');
        }
        if ($this->action === self::ACTION_AUTHOR && $this->authorId < 1) {
            $errors[] = new ValidationError($this->id, 'author', 'an author rule needs a user');
        }
        return $errors;
    }

    public function inScope(Item $item, string $importerId): bool
    {
        if ($this->scope === self::SCOPE_SOURCES) {
            return in_array($item->sourceKey(), $this->scopeValues, true);
        }
        if ($this->scope === self::SCOPE_IMPORTERS) {
            return in_array($importerId, $this->scopeValues, true);
        }
        return true;
    }

    public function isMapping(): bool
    {
        return in_array($this->action, [self::ACTION_CATEGORISE, self::ACTION_TAG, self::ACTION_AUTHOR], true);
    }

    private static function sanitizeId(string $id): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9_-]/', '', $id);
        return $clean === '' ? self::newId() : substr($clean, 0, 40);
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_numeric($entry) && (int) $entry > 0 && !in_array((int) $entry, $out, true)) {
                $out[] = (int) $entry;
            }
        }
        return $out;
    }
}
