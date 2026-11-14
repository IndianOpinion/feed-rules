<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * Why a rule acted (or would have acted) on an item.
 */
final class Reason
{
    public const NO_INCLUDE = 'no include rule matched';

    public function __construct(
        public readonly string $ruleId,
        public readonly string $ruleName,
        public readonly string $action,
        public readonly string $outcome,
        public readonly string $field = '',
        public readonly string $matched = '',
        public readonly int $offset = -1,
        public readonly string $before = '',
        public readonly string $after = '',
    ) {
    }

    /**
     * @return array{rule_id: string, rule_name: string, action: string, outcome: string, field: string,
     *     matched: string, offset: int, before: string, after: string}
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'rule_name' => $this->ruleName,
            'action' => $this->action,
            'outcome' => $this->outcome,
            'field' => $this->field,
            'matched' => $this->matched,
            'offset' => $this->offset,
            'before' => $this->before,
            'after' => $this->after,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $s = static fn (string $k): string => isset($data[$k]) && is_scalar($data[$k]) ? (string) $data[$k] : '';
        return new self(
            $s('rule_id'),
            $s('rule_name'),
            $s('action'),
            $s('outcome'),
            $s('field'),
            $s('matched'),
            isset($data['offset']) && is_numeric($data['offset']) ? (int) $data['offset'] : -1,
            $s('before'),
            $s('after'),
        );
    }

    public function summary(): string
    {
        if ($this->ruleId === '') {
            return $this->outcome;
        }
        $text = $this->outcome . ': ' . $this->ruleName;
        if ($this->field !== '') {
            $text .= ' [' . $this->field . ']';
        }
        if ($this->matched !== '') {
            $text .= ' "' . $this->matched . '"';
        }
        return $text;
    }
}
