<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

use IndianOpinion\FeedRules\Exception\InvalidRuleException;

/**
 * Rule set export and all-or-nothing import as JSON.
 */
final class ImportExport
{
    public function __construct(private RuleRepository $repository)
    {
    }

    public function export(): string
    {
        return (string) json_encode(
            $this->repository->load()->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Validate the whole file first; replace the rule set only when every rule is valid.
     *
     * @throws InvalidRuleException when the file or any rule is invalid. Nothing is changed.
     */
    public function import(string $json, bool $dryRun = false): RuleSet
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new InvalidRuleException('The file is not valid JSON: ' . json_last_error_msg());
        }
        $set = RuleSet::fromArray($data);
        $errors = $set->validate();
        if ($errors !== []) {
            throw new InvalidRuleException(
                sprintf('%d problem(s) in the file, nothing was imported. First: %s', count($errors), (string) $errors[0]),
                $errors
            );
        }
        if ($dryRun) {
            return $set;
        }
        $this->repository->forget();
        return $this->repository->save($set, $this->repository->load()->editVersion);
    }
}
