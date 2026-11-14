<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * One reason a rule or rule set cannot be saved.
 */
final class ValidationError
{
    public function __construct(
        public readonly string $ruleId,
        public readonly string $path,
        public readonly string $message,
    ) {
    }

    public function __toString(): string
    {
        $where = $this->ruleId === '' ? $this->path : $this->ruleId . ' ' . $this->path;
        return trim($where) === '' ? $this->message : $where . ': ' . $this->message;
    }
}
