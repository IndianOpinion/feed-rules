<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Exception;

/**
 * Thrown when an importer hands over an item that cannot be evaluated.
 */
final class InvalidItemException extends FeedRulesException
{
    private string $field;

    public function __construct(string $field, string $message)
    {
        parent::__construct($message);
        $this->field = $field;
    }

    public function field(): string
    {
        return $this->field;
    }
}
