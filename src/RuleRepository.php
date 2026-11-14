<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

use IndianOpinion\FeedRules\Exception\InvalidRuleException;
use IndianOpinion\FeedRules\Exception\RulesConflictException;

/**
 * Reads and writes the rule set option, with optimistic locking on the edit version.
 */
final class RuleRepository
{
    public const OPTION = 'feed_rules_rules';
    public const CORRUPT_LOG_TRANSIENT = 'feed_rules_corrupt_logged';

    private ?RuleSet $cache = null;
    private bool $corrupt = false;

    /**
     * The stored rule set. An unreadable set is treated as empty (fail open),
     * flagged for the admin notice, and logged at most once an hour.
     */
    public function load(): RuleSet
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $raw = get_option(self::OPTION, null);
        $this->corrupt = false;
        if ($raw === null || $raw === false || $raw === '') {
            return $this->cache = new RuleSet();
        }
        try {
            if (!is_array($raw)) {
                throw new InvalidRuleException('The stored rule set is not an array.');
            }
            $this->cache = RuleSet::fromArray($raw);
        } catch (InvalidRuleException $e) {
            $this->corrupt = true;
            if (get_transient(self::CORRUPT_LOG_TRANSIENT) === false) {
                set_transient(self::CORRUPT_LOG_TRANSIENT, 1, 3600);
                Logger::log('error rule set unreadable, every item is kept: ' . $e->getMessage());
            }
            $this->cache = new RuleSet();
        }
        return $this->cache;
    }

    public function isCorrupt(): bool
    {
        $this->load();
        return $this->corrupt;
    }

    /**
     * Validate and save a rule set that was loaded at $loadedVersion.
     *
     * @throws InvalidRuleException when any rule fails validation; nothing is written.
     * @throws RulesConflictException when the stored set changed since it was loaded.
     */
    public function save(RuleSet $rules, int $loadedVersion): RuleSet
    {
        $errors = $rules->validate();
        if ($errors !== []) {
            throw new InvalidRuleException('The rules were not saved: ' . (string) $errors[0], $errors);
        }
        $this->cache = null;
        $current = $this->load();
        if (!$this->corrupt && $current->editVersion !== $loadedVersion) {
            throw new RulesConflictException('The rules changed since you opened this page. Your change was not saved.');
        }
        $saved = $rules->withEditVersion(max($current->editVersion, $loadedVersion) + 1);
        update_option(self::OPTION, $saved->toArray(), false);
        $this->cache = $saved;
        $this->corrupt = false;
        return $saved;
    }

    public function forget(): void
    {
        $this->cache = null;
    }
}
