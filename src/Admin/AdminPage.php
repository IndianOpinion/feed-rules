<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Admin;

use IndianOpinion\FeedRules\Adapter\CoreFeedAdapter;
use IndianOpinion\FeedRules\Condition;
use IndianOpinion\FeedRules\Exception\InvalidRuleException;
use IndianOpinion\FeedRules\Exception\RulesConflictException;
use IndianOpinion\FeedRules\Exception\StoreUnavailableException;
use IndianOpinion\FeedRules\Plugin;
use IndianOpinion\FeedRules\Rule;
use IndianOpinion\FeedRules\RuleSet;
use IndianOpinion\FeedRules\Settings;
use IndianOpinion\FeedRules\TestReport;

/**
 * Tools > Feed rules: Rules, Test, Log and Settings tabs.
 *
 * Every form checks the capability and a nonce. Every value is escaped on output.
 */
final class AdminPage
{
    public const SLUG = 'feed-rules';
    public const TABS = ['rules' => 'Rules', 'test' => 'Test', 'log' => 'Log', 'settings' => 'Settings'];

    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_notices', [$this, 'notices']);
        add_action('admin_init', [$this, 'privacy']);
        add_action('admin_post_feed_rules_save_rule', [$this, 'handleSaveRule']);
        add_action('admin_post_feed_rules_rule_op', [$this, 'handleRuleOp']);
        add_action('admin_post_feed_rules_save_settings', [$this, 'handleSaveSettings']);
        add_action('admin_post_feed_rules_sample', [$this, 'handleSample']);
        add_action('admin_post_feed_rules_reset_errors', [$this, 'handleResetErrors']);
        add_action('admin_post_feed_rules_test_csv', [$this, 'handleTestCsv']);
    }

    public function menu(): void
    {
        add_management_page('Feed rules', 'Feed rules', Settings::capability(), self::SLUG, [$this, 'render']);
    }

    public function privacy(): void
    {
        if (function_exists('wp_add_privacy_policy_content')) {
            wp_add_privacy_policy_content('Feed Rules', '<p>' . esc_html(Plugin::privacyText()) . '</p>');
        }
    }

    public function notices(): void
    {
        if (!current_user_can(Settings::capability())) {
            return;
        }
        $flash = $this->takeFlash();
        if ($flash !== null) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($flash['type']),
                esc_html($flash['message'])
            );
        }
        if ($this->plugin->repository()->isCorrupt()) {
            echo '<div class="notice notice-error"><p>'
                . esc_html('Feed rules: the stored rule set cannot be read. Every item is being kept and no mapping is applied. Import a rule set or save the rules again.')
                . '</p></div>';
        }
        $errors = $this->plugin->counters()->rulesWithErrors();
        if ($errors !== []) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html(sprintf('Feed rules: %d rule(s) failed at run time. Items were kept. See the Rules tab.', count($errors)))
                . '</p></div>';
        }
    }

    public function render(): void
    {
        $this->guard();
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'rules';
        $tab = isset(self::TABS[$tab]) ? $tab : 'rules';
        echo '<div class="wrap"><h1>' . esc_html('Feed rules') . '</h1><nav class="nav-tab-wrapper">';
        foreach (self::TABS as $key => $label) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url($this->url(['tab' => $key])),
                $key === $tab ? ' nav-tab-active' : '',
                esc_html($label)
            );
        }
        echo '</nav>';
        match ($tab) {
            'test' => $this->renderTest(),
            'log' => $this->renderLog(),
            'settings' => $this->renderSettings(),
            default => $this->renderRules(),
        };
        echo '</div>';
    }

    public function handleSaveRule(): void
    {
        $this->guard('feed_rules_save_rule');
        $post = $this->post();
        $repo = $this->plugin->repository();
        $rule = Forms::rule($post, [$this, 'tagId']);
        $version = is_numeric($post['edit_version'] ?? null) ? (int) $post['edit_version'] : -1;
        try {
            $repo->save($repo->load()->withRule($rule), $version);
            $dupes = Forms::duplicates($rule);
            $this->flash('success', sprintf('Rule "%s" saved.', $rule->name) . ($dupes > 0 ? sprintf(' %d duplicate keyword(s) removed.', $dupes) : ''));
            $this->redirect(['tab' => 'rules']);
        } catch (InvalidRuleException $e) {
            $this->flash('error', implode(' ', array_map('strval', $e->errors())));
            $this->stashDraft($post);
            $this->redirect(['tab' => 'rules', 'edit' => $rule->id, 'draft' => 1]);
        } catch (RulesConflictException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect(['tab' => 'rules']);
        }
    }

    public function handleRuleOp(): void
    {
        $this->guard('feed_rules_rule_op');
        $post = $this->post();
        $repo = $this->plugin->repository();
        $set = $repo->load();
        $id = Forms::str($post, 'rule_id');
        $op = Forms::str($post, 'op');
        $rule = $set->find($id);
        $version = is_numeric($post['edit_version'] ?? null) ? (int) $post['edit_version'] : -1;
        if ($rule === null) {
            $this->flash('error', 'That rule no longer exists.');
            $this->redirect(['tab' => 'rules']);
            return;
        }
        $new = match ($op) {
            'up' => $set->move($id, -1),
            'down' => $set->move($id, 1),
            'duplicate' => $set->withRule($rule->duplicate()),
            'delete' => $set->without($id),
            'off', 'test', 'live' => $set->withRule($rule->withState($op)),
            default => $set,
        };
        try {
            $repo->save($new, $version);
            $this->flash('success', 'Rules updated.');
        } catch (InvalidRuleException | RulesConflictException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect(['tab' => 'rules']);
    }

    public function handleSaveSettings(): void
    {
        $this->guard('feed_rules_save_settings');
        $this->plugin->settings()->update(Forms::settings($this->post()));
        $this->plugin->coreFeed()->schedule();
        $this->flash('success', 'Settings saved.');
        $this->redirect(['tab' => 'settings']);
    }

    public function handleSample(): void
    {
        $this->guard('feed_rules_sample');
        $importer = Forms::str($this->post(), 'importer');
        $count = $this->plugin->sample($importer !== '' ? $importer : CoreFeedAdapter::ID);
        $this->plugin->counters()->flush();
        $this->flash('success', sprintf('%d current item(s) loaded and marked as sampled.', $count));
        $this->redirect(['tab' => 'test']);
    }

    public function handleResetErrors(): void
    {
        $this->guard('feed_rules_reset_errors');
        $id = Forms::str($this->post(), 'rule_id');
        $this->plugin->counters()->resetErrors($id === '' ? null : $id);
        $this->plugin->counters()->flush();
        $this->flash('success', 'Error badges cleared.');
        $this->redirect(['tab' => 'rules']);
    }

    public function handleTestCsv(): void
    {
        $this->guard('feed_rules_test');
        $report = $this->runTest($this->post());
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="feed-rules-test.csv"');
        echo $report->csv(); // phpcs:ignore -- CSV body, not HTML.
        exit;
    }

    /**
     * Resolve a tag name to a term id, creating it when the operator names a new tag in a rule.
     */
    public function tagId(string $name): ?int
    {
        $found = term_exists($name, 'post_tag');
        if (is_array($found) && is_numeric($found['term_id'] ?? null)) {
            return (int) $found['term_id'];
        }
        if (is_int($found) && $found > 0) {
            return $found;
        }
        $made = wp_insert_term($name, 'post_tag');
        return is_array($made) ? (int) $made['term_id'] : null;
    }

    /**
     * Resolve a tag name without creating anything (test runs write nothing).
     */
    public function existingTagId(string $name): ?int
    {
        $found = term_exists($name, 'post_tag');
        if (is_array($found) && is_numeric($found['term_id'] ?? null)) {
            return (int) $found['term_id'];
        }
        return is_int($found) && $found > 0 ? $found : null;
    }

    /**
     * @param array<mixed> $post
     */
    public function runTest(array $post): TestReport
    {
        $saved = $this->plugin->repository()->load();
        $set = Forms::testSet($post, $saved, [$this, 'existingTagId']);
        $limit = is_numeric($post['limit'] ?? null) ? max(1, min(1000, (int) $post['limit'])) : 100;
        $importer = Forms::str($post, 'importer');
        $source = Forms::str($post, 'source');
        return $this->plugin->tester()->run(
            $set,
            $limit,
            $importer === '' ? null : $importer,
            $source === '' ? null : $source,
            Forms::samples($post)
        );
    }

    private function renderRules(): void
    {
        $set = $this->plugin->repository()->load();
        $edit = isset($_GET['edit']) ? sanitize_key(wp_unslash((string) $_GET['edit'])) : '';
        if ($edit !== '' || isset($_GET['new'])) {
            $rule = $set->find($edit);
            $draft = isset($_GET['draft']) ? $this->takeDraft() : null;
            if ($draft !== null) {
                $rule = Forms::rule($draft);
            }
            $this->renderRuleForm($rule ?? Rule::fromArray(['conditions' => [[]]]), $set);
            return;
        }
        printf('<p><a class="button button-primary" href="%s">%s</a></p>', esc_url($this->url(['tab' => 'rules', 'new' => 1])), esc_html('Add rule'));
        if ($set->rules === []) {
            echo '<p>' . esc_html('No rules yet. A fresh install changes nothing until a rule is set to live.') . '</p>';
            return;
        }
        $counters = $this->plugin->counters();
        echo '<table class="widefat striped"><thead><tr>';
        foreach (['Order', 'Name', 'State', 'Action', 'Scope', 'Today', '7 days', 'Last hit', 'Badges', ''] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($set->rules as $i => $rule) {
            $today = $counters->totals($rule->id, 1);
            $week = $counters->totals($rule->id, 7);
            $info = $counters->ruleInfo($rule->id);
            $badges = [];
            if (isset($info['last_error'])) {
                $badges[] = 'Error: ' . $info['last_error'] . ' (' . gmdate('Y-m-d H:i', (int) ($info['last_error_time'] ?? 0)) . ' UTC)';
            }
            if (isset($info['warning'])) {
                $badges[] = 'Warning: ' . $info['warning'] . ' (' . gmdate('Y-m-d H:i', (int) ($info['warning_time'] ?? 0)) . ' UTC)';
            }
            $hits = static fn (array $t): string => $t['hits'] . ' hits, ' . $t['would'] . ' would, ' . $t['errors'] . ' errors';
            echo '<tr><td>' . esc_html((string) ($i + 1)) . '</td>';
            printf('<td><a href="%s">%s</a></td>', esc_url($this->url(['tab' => 'rules', 'edit' => $rule->id])), esc_html($rule->name));
            echo '<td>' . esc_html($rule->state) . '</td><td>' . esc_html($rule->action) . '</td>';
            echo '<td>' . esc_html($rule->scope === Rule::SCOPE_ALL ? 'all sources' : $rule->scope . ': ' . implode(', ', $rule->scopeValues)) . '</td>';
            echo '<td>' . esc_html($hits($today)) . '</td><td>' . esc_html($hits($week)) . '</td>';
            echo '<td>' . esc_html(isset($info['last_hit']) ? gmdate('Y-m-d H:i', (int) $info['last_hit']) . ' UTC' : '') . '</td>';
            echo '<td>' . esc_html(implode(' ', $badges)) . '</td><td>';
            foreach (['up' => 'Up', 'down' => 'Down', 'duplicate' => 'Duplicate', 'off' => 'Off', 'test' => 'Test', 'live' => 'Live', 'delete' => 'Delete'] as $op => $label) {
                if ($op === $rule->state) {
                    continue;
                }
                $this->opButton($rule->id, $op, $label, $set->editVersion);
            }
            if ($badges !== []) {
                $this->postButton('feed_rules_reset_errors', ['rule_id' => $rule->id], 'Clear badges');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderRuleForm(Rule $rule, RuleSet $set): void
    {
        $post = esc_url(admin_url('admin-post.php'));
        $test = esc_url($this->url(['tab' => 'test']));
        echo '<form method="post" action="' . $post . '">';
        wp_nonce_field('feed_rules_save_rule');
        wp_nonce_field('feed_rules_test', '_feed_rules_test_nonce');
        echo '<input type="hidden" name="action" value="feed_rules_save_rule">';
        printf('<input type="hidden" name="edit_version" value="%d">', $set->editVersion);
        printf('<input type="hidden" name="rule[id]" value="%s">', esc_attr($rule->id));
        echo '<table class="form-table"><tbody>';
        $this->row('Name', sprintf('<input class="regular-text" name="rule[name]" value="%s">', esc_attr($rule->name)));
        $this->row('State', $this->select('rule[state]', array_combine(Rule::STATES, Rule::STATES), $rule->state)
            . ' <span class="description">' . esc_html('New rules start in test: logged, never acting.') . '</span>');
        $this->row('Action', $this->select('rule[action]', array_combine(Rule::ACTIONS, Rule::ACTIONS), $rule->action)
            . ' <span class="description">' . esc_html('keep beats every exclude and the include requirement.') . '</span>');
        $sources = $this->plugin->registry()->sources();
        $this->row(
            'Scope',
            $this->select('rule[scope]', ['all' => 'all sources', 'sources' => 'source keys', 'importers' => 'importer ids'], $rule->scope)
            . sprintf('<br><textarea name="rule[scope_values]" rows="3" cols="60">%s</textarea>', esc_textarea(implode("\n", $rule->scopeValues)))
            . '<p class="description">' . esc_html('One per line. Known sources: ' . implode(', ', array_keys($sources)) . '. Importers: ' . implode(', ', array_keys($this->plugin->registry()->all()))) . '</p>'
        );
        $cats = '';
        foreach (get_categories(['hide_empty' => false]) as $cat) {
            $cats .= sprintf(
                '<label><input type="checkbox" name="rule[categories][]" value="%d"%s> %s</label><br>',
                (int) $cat->term_id,
                in_array((int) $cat->term_id, $rule->categories, true) ? ' checked' : '',
                esc_html($cat->name)
            );
        }
        $this->row('Categories', $cats . $this->select('rule[category_mode]', ['append' => 'append to the importer\'s categories', 'replace' => 'replace the importer\'s categories'], $rule->categoryMode));
        $tagNames = [];
        foreach ($rule->tags as $tagId) {
            $term = get_term($tagId, 'post_tag');
            $tagNames[] = $term instanceof \WP_Term ? $term->name : (string) $tagId;
        }
        $this->row('Tags', sprintf('<input class="regular-text" name="rule[tags]" value="%s"> <span class="description">%s</span>', esc_attr(implode(', ', $tagNames)), esc_html('Comma separated.')));
        $users = wp_dropdown_users([
            'name' => 'rule[author]',
            'selected' => $rule->authorId,
            'show_option_none' => '(none)',
            'option_none_value' => '0',
            'capability' => ['edit_posts'],
            'echo' => false,
        ]);
        $this->row('Author', $users);
        echo '</tbody></table><h2>' . esc_html('Conditions (all must match)') . '</h2>';
        $conditions = $rule->conditions;
        if (count($conditions) < Rule::MAX_CONDITIONS) {
            $conditions[] = null;
        }
        foreach ($conditions as $i => $c) {
            $this->renderCondition($i, $c);
        }
        echo '<p><button class="button button-primary" type="submit">' . esc_html('Save rule') . '</button> ';
        printf('<button class="button" type="submit" formaction="%s" name="test_mode" value="draft">%s</button> ', $test, esc_html('Test this rule alone'));
        printf('<button class="button" type="submit" formaction="%s" name="test_mode" value="set_draft">%s</button></p>', $test, esc_html('Test the whole set with this rule'));
        echo '</form>';
    }

    private function renderCondition(int $i, ?Condition $condition): void
    {
        $c = $condition ?? Condition::fromArray([]);
        $n = 'conditions[' . $i . ']';
        echo '<fieldset style="border:1px solid #ccd0d4;padding:8px;margin:8px 0"><legend>' . esc_html('Condition ' . ($i + 1)) . '</legend>';
        echo $this->select($n . '[type]', ['keywords' => 'keywords', 'regex' => 'regular expression', 'url_prefix' => 'URL prefix'], $c->type); // phpcs:ignore -- escaped in select().
        echo ' ' . $this->select($n . '[field]', array_combine(Condition::FIELDS, Condition::FIELDS), $c->field); // phpcs:ignore
        echo ' ' . $this->select($n . '[mode]', ['any' => 'any keyword', 'all' => 'all keywords'], $c->mode); // phpcs:ignore
        foreach (['whole_words' => ['Whole words', $c->wholeWords], 'case_sensitive' => ['Case-sensitive', $c->caseSensitive], 'accent_insensitive' => ['Ignore accents', $c->accentInsensitive], 'negate' => ['Does not match', $c->negate]] as $key => [$label, $on]) {
            printf(' <label><input type="checkbox" name="%s[%s]" value="1"%s> %s</label>', esc_attr($n), esc_attr($key), $on ? ' checked' : '', esc_html($label));
        }
        printf('<br><label>%s<br><textarea name="%s[keywords]" rows="4" cols="60">%s</textarea></label>', esc_html('Keywords, one per line (# starts a comment, a trailing * matches a prefix)'), esc_attr($n), esc_textarea(implode("\n", $c->keywords)));
        printf('<br><label>%s <input class="regular-text code" name="%s[pattern]" value="%s"></label>', esc_html('Pattern, for example /paid\s+partnership/i'), esc_attr($n), esc_attr($c->pattern));
        printf('<br><label>%s<br><textarea name="%s[prefixes]" rows="3" cols="60">%s</textarea></label>', esc_html('Hosts or host/path prefixes, one per line'), esc_attr($n), esc_textarea(implode("\n", $c->prefixes)));
        echo '</fieldset>';
    }

    private function renderTest(): void
    {
        $report = null;
        $post = [];
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $nonce = isset($_POST['_feed_rules_test_nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['_feed_rules_test_nonce'])) : '';
            if (!wp_verify_nonce($nonce, 'feed_rules_test')) {
                wp_die(esc_html('The test form expired. Reload the page and try again.'), 403);
            }
            $post = $this->post();
            $report = $this->runTest($post);
        }
        $store = $this->plugin->store();
        $available = $store !== null && $store->isAvailable();
        if (!$available) {
            echo '<div class="notice notice-error inline"><p>' . esc_html('The recent-items store is unavailable: ' . ($store?->lastError() ?? 'no database')) . '</p></div>';
        } elseif ($store->count() === 0) {
            echo '<p>' . esc_html('No items have been recorded yet. Items appear here as importers run, or load current items now.') . '</p>';
            foreach ($this->plugin->registry()->all() as $id => $adapter) {
                if ($adapter->supportsSampling()) {
                    $this->postButton('feed_rules_sample', ['importer' => $id], 'Load current items: ' . $adapter->label());
                }
            }
        }
        $set = $this->plugin->repository()->load();
        $modes = ['set' => 'The whole saved rule set'];
        foreach ($set->rules as $rule) {
            $modes['rule:' . $rule->id] = 'Saved rule: ' . $rule->name . ' (' . $rule->state . ')';
        }
        $mode = Forms::str($post, 'test_mode');
        echo '<form method="post" action="' . esc_url($this->url(['tab' => 'test'])) . '">';
        wp_nonce_field('feed_rules_test', '_feed_rules_test_nonce');
        wp_nonce_field('feed_rules_test');
        echo '<input type="hidden" name="action" value="feed_rules_test_csv">';
        echo '<table class="form-table"><tbody>';
        $this->row('Run', $this->select('test_mode', $modes, isset($modes[$mode]) ? $mode : 'set'));
        $this->row('Importer', sprintf('<input name="importer" value="%s">', esc_attr(Forms::str($post, 'importer'))));
        $this->row('Source key', sprintf('<input class="regular-text" name="source" value="%s">', esc_attr(Forms::str($post, 'source'))));
        $this->row('Items', sprintf('<input type="number" min="1" max="1000" name="limit" value="%d">', is_numeric($post['limit'] ?? null) ? (int) $post['limit'] : 100));
        $titles = is_array($post['sample_title'] ?? null) ? $post['sample_title'] : [];
        $bodies = is_array($post['sample_body'] ?? null) ? $post['sample_body'] : [];
        $samples = '';
        for ($i = 0; $i < 2; $i++) {
            $samples .= sprintf(
                '<p><input class="regular-text" placeholder="%s" name="sample_title[%d]" value="%s"><br><textarea rows="3" cols="60" placeholder="%s" name="sample_body[%d]">%s</textarea><input type="hidden" name="sample_url[%d]" value=""></p>',
                esc_attr('Title'),
                $i,
                esc_attr(is_string($titles[$i] ?? null) ? $titles[$i] : ''),
                esc_attr('Body'),
                $i,
                esc_textarea(is_string($bodies[$i] ?? null) ? $bodies[$i] : ''),
                $i
            );
        }
        $this->row('Pasted samples', $samples);
        echo '</tbody></table><p><button class="button button-primary" type="submit">' . esc_html('Run test') . '</button> ';
        printf('<button class="button" type="submit" formaction="%s">%s</button></p></form>', esc_url(admin_url('admin-post.php')), esc_html('Download CSV'));
        if ($report !== null) {
            $this->renderReport($report);
        }
    }

    private function renderReport(TestReport $report): void
    {
        if (!$report->isValid()) {
            echo '<div class="notice notice-error inline"><p>' . esc_html('Not run: ' . implode(' ', array_map('strval', $report->validationErrors))) . '</p></div>';
            return;
        }
        if ($report->storeError !== '') {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($report->storeError) . '</p></div>';
        }
        $s = $report->summary();
        echo '<p><strong>' . esc_html(sprintf(
            '%d items: %d kept, %d dropped, %d newly dropped, %d newly kept, %d with changed mapping.',
            $s['items'],
            $s['kept'],
            $s['dropped'],
            $s['newly_dropped'],
            $s['newly_kept'],
            $s['mapping_changed']
        )) . '</strong></p>';
        $this->table(TestReport::COLUMNS, $report->table());
    }

    private function renderLog(): void
    {
        $store = $this->plugin->store();
        if ($store === null) {
            echo '<p>' . esc_html('The recent-items store is unavailable.') . '</p>';
            return;
        }
        try {
            $rows = $store->latest($this->plugin->settings()->recentLimit() * 5);
        } catch (StoreUnavailableException $e) {
            echo '<p>' . esc_html('The recent-items store is unavailable: ' . $e->getMessage()) . '</p>';
            return;
        }
        $out = [];
        foreach ($rows as $row) {
            $d = $row->decision;
            $out[] = [
                gmdate('Y-m-d H:i', $row->seenAt),
                $row->importerId,
                $row->sourceName() !== '' ? $row->sourceName() : $row->sourceKey,
                $row->title(),
                ($d->isKept() ? 'keep' : 'drop') . ($row->sampled ? ' (sampled)' : ''),
                implode(' | ', array_map(static fn ($r): string => $r->summary(), $d->reasons())),
                implode(' | ', array_map(static fn ($r): string => $r->summary(), $d->wouldReasons())),
                implode(' | ', array_map(static fn ($e): string => $e->ruleName . ': ' . $e->message, $d->errors())),
                $d->bodyTruncated() ? 'body cut' : '',
            ];
        }
        if ($out === []) {
            echo '<p>' . esc_html('Nothing recorded yet.') . '</p>';
            return;
        }
        $this->table(['Seen (UTC)', 'Importer', 'Source', 'Title', 'Verdict', 'Reasons', 'Would', 'Errors', ''], $out);
    }

    private function renderSettings(): void
    {
        $settings = $this->plugin->settings();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('feed_rules_save_settings');
        echo '<input type="hidden" name="action" value="feed_rules_save_settings"><table class="form-table"><tbody>';
        $check = fn (string $key, string $label): string => sprintf('<label><input type="checkbox" name="%s" value="1"%s> %s</label>', esc_attr($key), $settings->get($key) ? ' checked' : '', esc_html($label));
        $number = fn (string $key): string => sprintf('<input type="number" name="%s" value="%d">', esc_attr($key), is_numeric($settings->get($key)) ? (int) $settings->get($key) : 0);
        $this->row('Global switch', $check('enabled', 'Apply rules to imported items'));
        $this->row('Recent items per importer', $number('recent_limit') . ' <span class="description">20 to 1,000</span>');
        $this->row('Body window (characters)', $number('body_window'));
        $this->row('Category cap per item', $number('category_cap'));
        $this->row('Tags', $check('copy_source_tags', 'Copy source tags onto posts (up to 10)') . '<br>' . $check('create_tags', 'Create source tags that do not exist yet'));
        $this->row('Default category', $check('remove_default_category', 'Remove the default category when a mapping added another'));
        $this->row('Source category map', sprintf('<textarea name="category_map" rows="4" cols="60" placeholder="%s">%s</textarea>', esc_attr('Cricket = 12'), esc_textarea(Forms::mapText($settings->intMap('category_map')))));
        $this->row('Author by source author name', sprintf('<textarea name="author_names" rows="3" cols="60" placeholder="%s">%s</textarea>', esc_attr('Jane Doe = 3'), esc_textarea(Forms::mapText($settings->intMap('author_names')))));
        $this->row('Author by source key', sprintf('<textarea name="author_sources" rows="3" cols="60" placeholder="%s">%s</textarea>', esc_attr('https://example.com/feed = 3'), esc_textarea(Forms::mapText($settings->intMap('author_sources')))));
        echo '</tbody></table><h2>' . esc_html('Reference adapter: WordPress core feeds') . '</h2>';
        $adapter = $this->plugin->coreFeed();
        $feeds = $adapter->feeds();
        $feeds[] = null;
        $status = $settings->get('feed_status');
        $status = is_array($status) ? $status : [];
        echo '<table class="widefat"><thead><tr>';
        foreach (['Feed URL', 'Label', 'Default author id', 'Default category id', 'Status', 'Interval', 'Max per run', 'Last run'] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($feeds as $i => $feed) {
            $n = 'feeds[' . $i . ']';
            $st = $feed === null ? [] : (is_array($status[$feed['key']] ?? null) ? $status[$feed['key']] : []);
            $last = isset($st['last_run']) ? gmdate('Y-m-d H:i', (int) $st['last_run']) . ' UTC' : '';
            if (!empty($st['error'])) {
                $last .= ' Error: ' . (string) $st['error'];
            }
            echo '<tr>';
            printf('<td><input class="regular-text" name="%s[url]" value="%s"></td>', esc_attr($n), esc_attr($feed['url'] ?? ''));
            printf('<td><input name="%s[label]" value="%s"></td>', esc_attr($n), esc_attr($feed['label'] ?? ''));
            printf('<td><input type="number" name="%s[author]" value="%d"></td>', esc_attr($n), $feed['author'] ?? 0);
            printf('<td><input type="number" name="%s[category]" value="%d"></td>', esc_attr($n), $feed['category'] ?? 0);
            echo '<td>' . $this->select($n . '[status]', array_combine(CoreFeedAdapter::STATUSES, CoreFeedAdapter::STATUSES), $feed['status'] ?? 'draft') . '</td>'; // phpcs:ignore
            $intervals = array_keys(CoreFeedAdapter::INTERVALS);
            echo '<td>' . $this->select($n . '[interval]', array_combine($intervals, $intervals), $feed['interval'] ?? 'hourly') . '</td>'; // phpcs:ignore
            printf('<td><input type="number" min="1" max="200" name="%s[max]" value="%d"></td>', esc_attr($n), $feed['max'] ?? 20);
            echo '<td>' . esc_html($last) . '</td></tr>';
        }
        echo '</tbody></table><p class="description">' . esc_html('Clear a URL to remove a feed. Posts are created as drafts or pending, never published.') . '</p>';
        echo '<p><button class="button button-primary" type="submit">' . esc_html('Save settings') . '</button></p></form>';
    }

    /**
     * @param list<string> $headers
     * @param list<array<array-key, string>> $rows
     */
    private function table(array $headers, array $rows): void
    {
        echo '<table class="widefat striped"><thead><tr>';
        foreach ($headers as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . esc_html($cell) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function row(string $label, string $html): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore -- $html is built from escaped parts.
    }

    /**
     * @param array<string, string> $options
     */
    private function select(string $name, array $options, string $selected): string
    {
        $html = '<select name="' . esc_attr($name) . '">';
        foreach ($options as $value => $label) {
            $html .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr((string) $value),
                (string) $value === $selected ? ' selected' : '',
                esc_html($label)
            );
        }
        return $html . '</select>';
    }

    private function opButton(string $ruleId, string $op, string $label, int $version): void
    {
        $this->postButton('feed_rules_rule_op', ['rule_id' => $ruleId, 'op' => $op, 'edit_version' => (string) $version], $label);
    }

    /**
     * @param array<string, string> $fields
     */
    private function postButton(string $action, array $fields, string $label): void
    {
        echo '<form method="post" style="display:inline" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field($action);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        foreach ($fields as $k => $v) {
            echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
        echo '<button class="button button-small" type="submit">' . esc_html($label) . '</button></form> ';
    }

    private function guard(?string $nonceAction = null): void
    {
        if (!current_user_can(Settings::capability())) {
            wp_die(esc_html('You are not allowed to manage feed rules.'), 403);
        }
        if ($nonceAction !== null) {
            check_admin_referer($nonceAction);
        }
    }

    /**
     * @return array<mixed>
     */
    private function post(): array
    {
        $post = wp_unslash($_POST); // phpcs:ignore -- nonce checked in guard() or renderTest().
        return is_array($post) ? $post : [];
    }

    /**
     * @param array<string, string|int> $args
     */
    private function url(array $args): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('tools.php'));
    }

    /**
     * @param array<string, string|int> $args
     */
    private function redirect(array $args): void
    {
        wp_safe_redirect($this->url($args));
        exit;
    }

    private function flash(string $type, string $message): void
    {
        set_transient('feed_rules_flash_' . get_current_user_id(), ['type' => $type, 'message' => $message], 120);
    }

    /**
     * @return array{type: string, message: string}|null
     */
    private function takeFlash(): ?array
    {
        $key = 'feed_rules_flash_' . get_current_user_id();
        $flash = get_transient($key);
        if (!is_array($flash) || !is_string($flash['type'] ?? null) || !is_string($flash['message'] ?? null)) {
            return null;
        }
        delete_transient($key);
        return ['type' => $flash['type'], 'message' => $flash['message']];
    }

    /**
     * @param array<mixed> $post
     */
    private function stashDraft(array $post): void
    {
        set_transient('feed_rules_draft_' . get_current_user_id(), $post, 600);
    }

    /**
     * @return array<mixed>|null
     */
    private function takeDraft(): ?array
    {
        $key = 'feed_rules_draft_' . get_current_user_id();
        $draft = get_transient($key);
        delete_transient($key);
        return is_array($draft) ? $draft : null;
    }
}
