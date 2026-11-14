<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * One item in a test run: what was recorded and what this run gives.
 */
final class TestRow
{
    public function __construct(
        public readonly string $title,
        public readonly string $source,
        public readonly string $importerId,
        public readonly string $url,
        public readonly ?int $seenAt,
        public readonly ?string $recordedVerdict,
        public readonly Decision $decision,
        public readonly bool $mappingChanged,
        public readonly bool $sampled,
        public readonly bool $pasted,
    ) {
    }

    public function verdict(): string
    {
        return $this->decision->isKept() ? 'keep' : 'drop';
    }

    public function newlyDropped(): bool
    {
        return $this->recordedVerdict === 'keep' && !$this->decision->isKept();
    }

    public function newlyKept(): bool
    {
        return $this->recordedVerdict === 'drop' && $this->decision->isKept();
    }
}
