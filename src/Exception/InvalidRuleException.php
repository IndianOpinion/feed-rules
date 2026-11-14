<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Exception;

use IndianOpinion\FeedRules\ValidationError;

/**
 * Thrown when a rule or rule set cannot be read or fails validation.
 */
final class InvalidRuleException extends FeedRulesException
{
    /** @var list<ValidationError> */
    private array $errors;

    /**
     * @param list<ValidationError> $errors
     */
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    /**
     * @return list<ValidationError>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
