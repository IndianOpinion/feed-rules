<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

use IndianOpinion\FeedRules\Exception\InvalidRuleException;

/**
 * The ordered list of rules, with its schema version and edit version.
 */
final class RuleSet
{
    public const SCHEMA = 1;

    /**
     * @param list<Rule> $rules
     */
    public function __construct(
        public readonly array $rules = [],
        public readonly int $editVersion = 0,
    ) {
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidRuleException when the data is not a rule set of a known schema.
     */
    public static function fromArray(array $data): self
    {
        $schema = $data['schema'] ?? null;
        if ($schema !== self::SCHEMA) {
            throw new InvalidRuleException(sprintf('Unknown rule set schema "%s".', is_scalar($schema) ? (string) $schema : gettype($schema)));
        }
        if (!isset($data['rules']) || !is_array($data['rules'])) {
            throw new InvalidRuleException('The rule set has no rule list.');
        }
        $rules = [];
        foreach ($data['rules'] as $rule) {
            if (!is_array($rule)) {
                throw new InvalidRuleException('A rule in the set is not an array.');
            }
            $rules[] = Rule::fromArray($rule);
        }
        $version = $data['edit_version'] ?? 0;
        return new self($rules, is_int($version) ? $version : 0);
    }

    /**
     * @return array{schema: int, edit_version: int, rules: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'edit_version' => $this->editVersion,
            'rules' => array_map(static fn (Rule $r): array => $r->toArray(), $this->rules),
        ];
    }

    /**
     * @return list<ValidationError>
     */
    public function validate(): array
    {
        $errors = [];
        $ids = [];
        foreach ($this->rules as $rule) {
            if (isset($ids[$rule->id])) {
                $errors[] = new ValidationError($rule->id, 'id', 'two rules share this id');
            }
            $ids[$rule->id] = true;
            array_push($errors, ...$rule->validate());
        }
        return $errors;
    }

    public function find(string $id): ?Rule
    {
        foreach ($this->rules as $rule) {
            if ($rule->id === $id) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * The set with $rule replacing the rule of the same id, or appended.
     */
    public function withRule(Rule $rule): self
    {
        $rules = $this->rules;
        foreach ($rules as $i => $existing) {
            if ($existing->id === $rule->id) {
                $rules[$i] = $rule;
                return new self($rules, $this->editVersion);
            }
        }
        $rules[] = $rule;
        return new self($rules, $this->editVersion);
    }

    public function without(string $id): self
    {
        return new self(
            array_values(array_filter($this->rules, static fn (Rule $r): bool => $r->id !== $id)),
            $this->editVersion
        );
    }

    /**
     * Move a rule one place up (-1) or down (+1).
     */
    public function move(string $id, int $direction): self
    {
        $rules = $this->rules;
        foreach ($rules as $i => $rule) {
            if ($rule->id !== $id) {
                continue;
            }
            $j = $i + ($direction < 0 ? -1 : 1);
            if (!isset($rules[$j])) {
                return $this;
            }
            [$rules[$i], $rules[$j]] = [$rules[$j], $rules[$i]];
            return new self(array_values($rules), $this->editVersion);
        }
        return $this;
    }

    public function withEditVersion(int $version): self
    {
        return new self($this->rules, $version);
    }

    /**
     * @return array<string, int> Rule count by state.
     */
    public function countByState(): array
    {
        $counts = array_fill_keys(Rule::STATES, 0);
        foreach ($this->rules as $rule) {
            $counts[$rule->state] = ($counts[$rule->state] ?? 0) + 1;
        }
        return $counts;
    }
}
