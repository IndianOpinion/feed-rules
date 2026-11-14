<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

use IndianOpinion\FeedRules\Exception\InvalidItemException;

/**
 * Evaluates items against the rule set and applies mappings to posts.
 *
 * Precedence for one item, in order:
 * 1. A live keep rule that matches keeps the item. Exclude rules and the
 *    include requirement are then not applied.
 * 2. The first live exclude rule that matches drops the item.
 * 3. When live include rules are in scope, one must match or the item is
 *    dropped ("no include rule matched"). An include rule in error waives
 *    the requirement for that item.
 * 4. Test-state rules are evaluated for every item in scope and recorded as
 *    "would" results; they change nothing.
 * 5. A kept item collects categories, tags and an author from mapping rules
 *    and the mapping tables.
 */
final class Engine
{
    public const DECISION_KEY = 'feed_rules_decision';
    public const POST_META = '_feed_rules_match';

    public const OUTCOME_DROP = 'drop';
    public const OUTCOME_KEEP = 'keep';
    public const OUTCOME_MAP = 'map';
    public const OUTCOME_WOULD_DROP = 'would drop';
    public const OUTCOME_WOULD_KEEP = 'would keep';
    public const OUTCOME_WOULD_MAP = 'would map';

    public const SOURCE_TAG_CAP = 10;

    public function __construct(
        private RuleRepository $repository,
        private Settings $settings,
        private Counters $counters,
        private ?RecentStore $store = null,
        private ?Registry $registry = null,
    ) {
    }

    /**
     * The entry filter. Returns the item array with the decision attached,
     * false when the item is dropped, or the input unchanged when the
     * plugin is off, the item is invalid or anything fails.
     */
    public function filter(mixed $data, mixed $importerId): mixed
    {
        if (!is_array($data)) {
            return $data;
        }
        if (!$this->settings->enabled()) {
            return $data;
        }
        $importerId = is_string($importerId) && $importerId !== '' ? $importerId : 'unknown';
        try {
            $item = Item::fromArray(['importer' => $importerId] + $data);
        } catch (InvalidItemException $e) {
            $this->counters->invalid();
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('_doing_it_wrong')) {
                _doing_it_wrong(
                    'feed_rules_filter_item',
                    esc_html(sprintf('Item field "%s": %s', $e->field(), $e->getMessage())),
                    '0.1.0'
                );
            }
            return $data;
        }
        try {
            $decision = $this->evaluate($item, $importerId);
        } catch (\Throwable $e) {
            Logger::log(sprintf('error importer=%s evaluating %s: %s', $importerId, $item->url(), $e->getMessage()));
            return $data;
        }
        if (!$decision->isKept()) {
            return false;
        }
        $data[self::DECISION_KEY] = $decision->toArray() + ['importer' => $importerId];
        return $data;
    }

    /**
     * The apply action: map a kept item onto the post the importer created.
     */
    public function applyAction(mixed $postId, mixed $data): void
    {
        if (!is_numeric($postId) || (int) $postId < 1 || !is_array($data)) {
            return;
        }
        $raw = $data[self::DECISION_KEY] ?? null;
        if (!is_array($raw)) {
            return;
        }
        $importerId = is_string($raw['importer'] ?? null) ? $raw['importer'] : 'unknown';
        try {
            $this->applyToPost((int) $postId, Decision::fromArray($raw));
        } catch (\Throwable $e) {
            Logger::log(sprintf('error importer=%s applying to post %d: %s', $importerId, (int) $postId, $e->getMessage()));
        }
    }

    /**
     * Evaluate one item. With $dryRun nothing is written, no action fires,
     * and test-state rules are treated as live.
     */
    public function evaluate(Item $item, string $importerId, ?RuleSet $rules = null, bool $dryRun = false): Decision
    {
        return $this->run($item, $importerId, $rules, !$dryRun, $dryRun);
    }

    /**
     * Evaluate as a live import would (test-state rules stay in test), but write nothing.
     * Used to record sampled items with the verdict the live rules give.
     */
    public function preview(Item $item, string $importerId, ?RuleSet $rules = null): Decision
    {
        return $this->run($item, $importerId, $rules, false, false);
    }

    private function run(Item $item, string $importerId, ?RuleSet $rules, bool $write, bool $testAsLive): Decision
    {
        $rules ??= $this->repository->load();
        $texts = new FieldTexts($item, $this->settings->bodyWindow());
        $run = new EvaluationRun($texts);

        $active = [];
        foreach ($rules->rules as $rule) {
            if ($rule->state !== Rule::STATE_OFF && $rule->inScope($item, $importerId)) {
                $active[] = $rule;
            }
        }
        $isLive = static fn (Rule $r): bool => $r->state === Rule::STATE_LIVE
            || ($testAsLive && $r->state === Rule::STATE_TEST);
        $live = static fn (string $action): array => array_values(array_filter(
            $active,
            static fn (Rule $r): bool => $r->action === $action && $isLive($r)
        ));

        $reasons = [];
        $matched = [];
        $kept = true;

        foreach ($live(Rule::ACTION_KEEP) as $rule) {
            $result = $run->rule($rule);
            if ($result->isMatch()) {
                $reasons[] = $this->reason($rule, self::OUTCOME_KEEP, $result);
                $matched[] = $rule->id;
                break;
            }
        }
        $forcedKeep = $matched !== [];

        if (!$forcedKeep) {
            foreach ($live(Rule::ACTION_EXCLUDE) as $rule) {
                $result = $run->rule($rule);
                if ($result->isMatch()) {
                    $reasons[] = $this->reason($rule, self::OUTCOME_DROP, $result);
                    $matched[] = $rule->id;
                    $kept = false;
                    break;
                }
            }
        }

        if ($kept && !$forcedKeep) {
            $includes = $live(Rule::ACTION_INCLUDE);
            if ($includes !== []) {
                $satisfied = false;
                $errored = false;
                foreach ($includes as $rule) {
                    $result = $run->rule($rule);
                    if ($result->isMatch()) {
                        $reasons[] = $this->reason($rule, self::OUTCOME_KEEP, $result);
                        $matched[] = $rule->id;
                        $satisfied = true;
                        break;
                    }
                    $errored = $errored || $result->isError();
                }
                if (!$satisfied && !$errored) {
                    $kept = false;
                    $reasons[] = new Reason('', '', Rule::ACTION_INCLUDE, Reason::NO_INCLUDE);
                }
            }
        }

        $would = [];
        if (!$testAsLive) {
            foreach ($active as $rule) {
                if ($rule->state !== Rule::STATE_TEST) {
                    continue;
                }
                $result = $run->rule($rule);
                if ($result->isMatch()) {
                    $outcome = match ($rule->action) {
                        Rule::ACTION_EXCLUDE => self::OUTCOME_WOULD_DROP,
                        Rule::ACTION_INCLUDE, Rule::ACTION_KEEP => self::OUTCOME_WOULD_KEEP,
                        default => self::OUTCOME_WOULD_MAP,
                    };
                    $would[] = $this->reason($rule, $outcome, $result);
                }
            }
        }

        $categories = [];
        $categoryReplace = false;
        $tags = [];
        $authorId = null;
        $sources = [];
        if ($kept) {
            $cap = $this->settings->categoryCap();
            foreach ($live(Rule::ACTION_CATEGORISE) as $rule) {
                $result = $run->rule($rule);
                if (!$result->isMatch()) {
                    continue;
                }
                $reasons[] = $this->reason($rule, self::OUTCOME_MAP, $result);
                $matched[] = $rule->id;
                $categoryReplace = $categoryReplace || $rule->categoryMode === Rule::MODE_REPLACE;
                foreach ($rule->categories as $id) {
                    if (count($categories) < $cap && !in_array($id, $categories, true)) {
                        $categories[] = $id;
                        $sources['category:' . $id] = $rule->id;
                    }
                }
            }
            $map = $this->settings->intMap('category_map');
            foreach (array_merge($item->categories(), $item->tags()) as $term) {
                $id = $map[Settings::mapKey($term)] ?? null;
                if ($id !== null && count($categories) < $cap && !in_array($id, $categories, true)) {
                    $categories[] = $id;
                }
            }

            foreach ($live(Rule::ACTION_TAG) as $rule) {
                $result = $run->rule($rule);
                if (!$result->isMatch()) {
                    continue;
                }
                $reasons[] = $this->reason($rule, self::OUTCOME_MAP, $result);
                $matched[] = $rule->id;
                foreach ($rule->tags as $id) {
                    if (!in_array($id, $tags, true)) {
                        $tags[] = $id;
                        $sources['tag:' . $id] = $rule->id;
                    }
                }
            }
            if ((bool) $this->settings->get('copy_source_tags')) {
                foreach ($item->tags() as $name) {
                    $name = Text::plain($name);
                    if ($name !== '' && !in_array($name, $tags, true)) {
                        $tags[] = $name;
                    }
                }
            }

            foreach ($live(Rule::ACTION_AUTHOR) as $rule) {
                $result = $run->rule($rule);
                if (!$result->isMatch()) {
                    continue;
                }
                if ($this->canAuthor($rule->authorId)) {
                    $reasons[] = $this->reason($rule, self::OUTCOME_MAP, $result);
                    $matched[] = $rule->id;
                    $authorId = $rule->authorId;
                    $sources['author'] = $rule->id;
                    break;
                }
            }
            if ($authorId === null) {
                $authorId = $this->tableAuthor($item);
            }
            if ($authorId === null && $this->registry !== null) {
                $default = $this->registry->defaultAuthor($importerId, $item->sourceKey());
                $authorId = $default !== null && $this->canAuthor($default) ? $default : null;
            }
        }

        $decision = new Decision(
            $kept,
            $reasons,
            $would,
            $categories,
            $categoryReplace,
            $tags,
            $authorId,
            $run->errors(),
            $texts->bodyTruncated(),
            array_values(array_unique($matched)),
            $sources,
            Clock::now(),
        );

        $filtered = apply_filters('feed_rules_decision', $decision, $item, $importerId);
        if ($filtered instanceof Decision) {
            $decision = $filtered;
        }

        if ($write) {
            $this->count($decision, $run);
            if ($this->store !== null) {
                $this->store->record($item, $importerId, $decision, $texts);
            }
            do_action('feed_rules_decided', $decision, $item, $importerId);
            if (!$decision->isKept() && apply_filters('feed_rules_error_log', false)) {
                $first = $decision->reasons()[0] ?? null;
                Logger::log(sprintf(
                    'drop importer=%s rule=%s url=%s',
                    $importerId,
                    $first === null ? '' : ($first->ruleName !== '' ? $first->ruleName : $first->outcome),
                    $item->url()
                ));
            }
        }
        return $decision;
    }

    /**
     * Add the decision's categories, tags and author to a post the importer created.
     * Never removes the importer's own terms, except in replace mode or when the
     * default-category switch is on and a mapping added a category.
     */
    public function applyToPost(int $postId, Decision $decision): void
    {
        if (!$decision->isKept()) {
            return;
        }
        $sources = $decision->mappingSources();

        $categories = [];
        foreach ($decision->categories() as $id) {
            if ($this->termExists($id, 'category')) {
                $categories[] = $id;
            } else {
                $this->missing('category', $id, $sources['category:' . $id] ?? null);
            }
        }

        $tagIds = [];
        $copied = 0;
        $create = (bool) $this->settings->get('create_tags');
        foreach ($decision->tags() as $tag) {
            if (is_int($tag)) {
                if ($this->termExists($tag, 'post_tag')) {
                    $tagIds[] = $tag;
                } else {
                    $this->missing('tag', $tag, $sources['tag:' . $tag] ?? null);
                }
                continue;
            }
            if ($copied >= self::SOURCE_TAG_CAP) {
                continue;
            }
            $id = $this->tagId($tag, $create);
            if ($id !== null) {
                $tagIds[] = $id;
                $copied++;
            }
        }

        if ($categories !== []) {
            if ($decision->replacesCategories()) {
                wp_set_post_terms($postId, $categories, 'category', false);
            } else {
                wp_set_post_terms($postId, $categories, 'category', true);
                if ((bool) $this->settings->get('remove_default_category')) {
                    $this->removeDefaultCategory($postId, $categories);
                }
            }
        }
        if ($tagIds !== []) {
            wp_set_post_terms($postId, array_values(array_unique($tagIds)), 'post_tag', true);
        }

        $authorId = $decision->authorId();
        if ($authorId !== null && $this->canAuthor($authorId)) {
            wp_update_post(['ID' => $postId, 'post_author' => $authorId]);
        }

        update_post_meta($postId, self::POST_META, [
            'rules' => $decision->matchedRuleIds(),
            'time' => $decision->time() > 0 ? $decision->time() : Clock::now(),
        ]);
    }

    public function counters(): Counters
    {
        return $this->counters;
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    private function reason(Rule $rule, string $outcome, MatchResult $result): Reason
    {
        return new Reason(
            $rule->id,
            $rule->name,
            $rule->action,
            $outcome,
            $result->field,
            $result->matched,
            $result->offset,
            $result->before,
            $result->after,
        );
    }

    private function count(Decision $decision, EvaluationRun $run): void
    {
        foreach ($decision->matchedRuleIds() as $id) {
            $this->counters->hit($id);
        }
        foreach ($decision->wouldReasons() as $reason) {
            $this->counters->would($reason->ruleId);
        }
        foreach ($run->errors() as $error) {
            $this->counters->error($error->ruleId, $error->message);
        }
    }

    private function canAuthor(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        $user = get_userdata($userId);
        return $user !== false && user_can($userId, 'edit_posts');
    }

    private function tableAuthor(Item $item): ?int
    {
        $byName = $this->settings->intMap('author_names');
        $name = Settings::mapKey($item->author());
        if ($name !== '' && isset($byName[$name]) && $this->canAuthor($byName[$name])) {
            return $byName[$name];
        }
        $bySource = $this->settings->intMap('author_sources');
        $key = $item->sourceKey();
        if ($key !== '' && isset($bySource[$key]) && $this->canAuthor($bySource[$key])) {
            return $bySource[$key];
        }
        return null;
    }

    private function termExists(int $id, string $taxonomy): bool
    {
        $term = get_term($id, $taxonomy);
        return $term instanceof \WP_Term;
    }

    private function tagId(string $name, bool $create): ?int
    {
        $found = term_exists($name, 'post_tag');
        if (is_array($found) && is_numeric($found['term_id'] ?? null)) {
            return (int) $found['term_id'];
        }
        if (is_int($found) && $found > 0) {
            return $found;
        }
        if (!$create) {
            return null;
        }
        $made = wp_insert_term($name, 'post_tag');
        return is_array($made) ? (int) $made['term_id'] : null;
    }

    /**
     * @param list<int> $added
     */
    private function removeDefaultCategory(int $postId, array $added): void
    {
        $default = get_option('default_category');
        $default = is_numeric($default) ? (int) $default : 0;
        if ($default < 1 || in_array($default, $added, true)) {
            return;
        }
        $current = wp_get_post_terms($postId, 'category', ['fields' => 'ids']);
        if (!is_array($current)) {
            return;
        }
        $ids = array_map('intval', $current);
        if (!in_array($default, $ids, true)) {
            return;
        }
        wp_set_post_terms($postId, array_values(array_diff($ids, [$default])), 'category', false);
    }

    private function missing(string $what, int $id, ?string $ruleId): void
    {
        $rule = $ruleId === null ? null : $this->repository->load()->find($ruleId);
        $name = $rule === null ? '(mapping table)' : $rule->name;
        $message = sprintf('mapped %s %d no longer exists and was skipped', $what, $id);
        Logger::log(sprintf('warning rule=%s %s', $name, $message));
        if ($ruleId !== null) {
            $this->counters->warning($ruleId, $message);
        }
    }
}
