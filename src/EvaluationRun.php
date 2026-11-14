<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * Evaluates rules against one item, each rule at most once, and collects errors.
 *
 * @internal
 */
final class EvaluationRun
{
    /** @var array<string, MatchResult> */
    private array $results = [];

    /** @var array<string, RuleError> */
    private array $errors = [];

    public function __construct(private FieldTexts $texts)
    {
    }

    /**
     * All conditions combined with AND. A condition that is false makes the
     * rule false; otherwise a condition in error makes the rule an error.
     * The first condition that produced text gives the reason.
     */
    public function rule(Rule $rule): MatchResult
    {
        if (isset($this->results[$rule->id])) {
            return $this->results[$rule->id];
        }
        $first = null;
        $error = null;
        foreach ($rule->conditions as $condition) {
            $result = Matcher::match($condition, $this->texts);
            if ($result->isError()) {
                $error ??= $result;
                continue;
            }
            if (!$result->isMatch()) {
                return $this->results[$rule->id] = MatchResult::none();
            }
            if ($first === null || ($first->matched === '' && $result->matched !== '')) {
                $first = $result;
            }
        }
        if ($error !== null) {
            $this->errors[$rule->id] = new RuleError($rule->id, $rule->name, $error->error, Clock::now());
            return $this->results[$rule->id] = $error;
        }
        if ($first === null) {
            return $this->results[$rule->id] = MatchResult::none();
        }
        return $this->results[$rule->id] = $first;
    }

    /**
     * @return list<RuleError>
     */
    public function errors(): array
    {
        return array_values($this->errors);
    }
}
