<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * A rule that could not be evaluated for an item (for example a regex hitting the backtrack limit).
 */
final class RuleError
{
    public function __construct(
        public readonly string $ruleId,
        public readonly string $ruleName,
        public readonly string $message,
        public readonly int $time,
    ) {
    }

    /**
     * @return array{rule_id: string, rule_name: string, message: string, time: int}
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'rule_name' => $this->ruleName,
            'message' => $this->message,
            'time' => $this->time,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['rule_id']) && is_string($data['rule_id']) ? $data['rule_id'] : '',
            isset($data['rule_name']) && is_string($data['rule_name']) ? $data['rule_name'] : '',
            isset($data['message']) && is_string($data['message']) ? $data['message'] : '',
            isset($data['time']) && is_numeric($data['time']) ? (int) $data['time'] : 0,
        );
    }
}
