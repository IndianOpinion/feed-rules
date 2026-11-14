<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Cli;

use IndianOpinion\FeedRules\Exception\FeedRulesException;
use IndianOpinion\FeedRules\Plugin;
use IndianOpinion\FeedRules\RuleSet;
use IndianOpinion\FeedRules\TestReport;
use WP_CLI;

/**
 * Manage feed rules from the command line.
 */
final class Command
{
    private Plugin $plugin;

    public function __construct(?Plugin $plugin = null)
    {
        $this->plugin = $plugin ?? Plugin::instance();
    }

    /**
     * List rules in order.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, csv or json. Default: table.
     *
     * @subcommand list
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function list_(array $args, array $assoc): void
    {
        $rows = [];
        $counters = $this->plugin->counters();
        foreach ($this->plugin->repository()->load()->rules as $i => $rule) {
            $week = $counters->totals($rule->id, 7);
            $rows[] = [
                'order' => $i + 1,
                'id' => $rule->id,
                'name' => $rule->name,
                'state' => $rule->state,
                'action' => $rule->action,
                'scope' => $rule->scope,
                'hits_7d' => $week['hits'],
                'would_7d' => $week['would'],
                'errors_7d' => $week['errors'],
            ];
        }
        $this->output($rows, $assoc['format'] ?? 'table', ['order', 'id', 'name', 'state', 'action', 'scope', 'hits_7d', 'would_7d', 'errors_7d']);
    }

    /**
     * Show the global switch, rule counts, store size, counters and feed health.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $settings = $this->plugin->settings();
        $repo = $this->plugin->repository();
        $set = $repo->load();
        $counts = $set->countByState();
        $store = $this->plugin->store();
        WP_CLI::line('Global switch: ' . ($settings->enabled() ? 'on' : 'off'));
        WP_CLI::line('Rule set: ' . ($repo->isCorrupt() ? 'UNREADABLE (every item kept)' : 'ok') . ', edit version ' . $set->editVersion);
        WP_CLI::line(sprintf('Rules: %d live, %d test, %d off', $counts['live'], $counts['test'], $counts['off']));
        WP_CLI::line(sprintf(
            'Recent items: %s (limit %d per importer)',
            $store !== null && $store->isAvailable() ? (string) $store->count() : 'store unavailable',
            $settings->recentLimit()
        ));
        WP_CLI::line('Invalid items today: ' . $this->plugin->counters()->invalidToday());
        foreach ($set->rules as $rule) {
            $week = $this->plugin->counters()->totals($rule->id, 7);
            $info = $this->plugin->counters()->ruleInfo($rule->id);
            WP_CLI::line(sprintf(
                '  %s [%s %s] 7 days: %d hits, %d would, %d errors%s',
                $rule->name,
                $rule->state,
                $rule->action,
                $week['hits'],
                $week['would'],
                $week['errors'],
                isset($info['last_error']) ? ' | last error: ' . $info['last_error'] : ''
            ));
        }
        $status = $settings->get('feed_status');
        $status = is_array($status) ? $status : [];
        foreach ($this->plugin->coreFeed()->feeds() as $feed) {
            $st = is_array($status[$feed['key']] ?? null) ? $status[$feed['key']] : [];
            WP_CLI::line(sprintf(
                'Feed %s: last run %s%s',
                $feed['url'],
                isset($st['last_run']) ? gmdate('Y-m-d H:i', (int) $st['last_run']) . ' UTC' : 'never',
                !empty($st['error']) ? ', last error: ' . (string) $st['error'] : ''
            ));
        }
    }

    /**
     * Run a rule, or the whole set, against the recent items. Writes nothing.
     *
     * ## OPTIONS
     *
     * <rule-id|all>
     * : A rule id, or "all" for the whole saved set.
     *
     * [--limit=<n>]
     * : Items to test. Default: 100.
     *
     * [--importer=<id>]
     * : Only items from this importer.
     *
     * [--source=<key>]
     * : Only items from this source key.
     *
     * [--format=<format>]
     * : table, csv or json. Default: table.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function test(array $args, array $assoc): void
    {
        $target = $args[0] ?? 'all';
        $saved = $this->plugin->repository()->load();
        if ($target === 'all') {
            $set = $saved;
        } else {
            $rule = $saved->find($target);
            if ($rule === null) {
                WP_CLI::error(sprintf('No rule with id "%s".', $target));
                return;
            }
            $set = new RuleSet([$rule]);
        }
        $report = $this->plugin->tester()->run(
            $set,
            isset($assoc['limit']) ? max(1, (int) $assoc['limit']) : 100,
            $assoc['importer'] ?? null,
            $assoc['source'] ?? null
        );
        if (!$report->isValid()) {
            WP_CLI::error(implode(' ', array_map('strval', $report->validationErrors)));
            return;
        }
        if ($report->storeError !== '') {
            WP_CLI::warning($report->storeError);
        }
        $format = $assoc['format'] ?? 'table';
        if ($format === 'csv') {
            WP_CLI::line(rtrim($report->csv(), "\n"));
            return;
        }
        $this->output($report->table(), $format, TestReport::COLUMNS);
        $s = $report->summary();
        if ($format === 'table') {
            WP_CLI::line(sprintf(
                '%d items: %d kept, %d dropped, %d newly dropped, %d newly kept, %d mapping changed.',
                $s['items'],
                $s['kept'],
                $s['dropped'],
                $s['newly_dropped'],
                $s['newly_kept'],
                $s['mapping_changed']
            ));
        }
    }

    /**
     * Export the rule set as JSON, to a file or to standard output.
     *
     * ## OPTIONS
     *
     * [<file>]
     * : Where to write. Default: standard output.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function export(array $args, array $assoc): void
    {
        $json = $this->plugin->importExport()->export();
        if (!isset($args[0])) {
            WP_CLI::line($json);
            return;
        }
        if (file_put_contents($args[0], $json . "\n") === false) {
            WP_CLI::error('Could not write ' . $args[0]);
            return;
        }
        WP_CLI::success('Exported to ' . $args[0]);
    }

    /**
     * Replace the rule set from a JSON file. All or nothing.
     *
     * ## OPTIONS
     *
     * <file>
     * : The JSON file.
     *
     * [--dry-run]
     * : Validate and report without changing anything.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function import(array $args, array $assoc): void
    {
        $file = $args[0] ?? '';
        $json = is_readable($file) ? file_get_contents($file) : false;
        if (!is_string($json)) {
            WP_CLI::error('Cannot read ' . $file);
            return;
        }
        $dry = !empty($assoc['dry-run']);
        try {
            $set = $this->plugin->importExport()->import($json, $dry);
        } catch (FeedRulesException $e) {
            WP_CLI::error($e->getMessage());
            return;
        }
        WP_CLI::success(sprintf('%s %d rule(s).', $dry ? 'Dry run: would import' : 'Imported', count($set->rules)));
    }

    /**
     * Run the reference adapter once over every configured feed.
     *
     * @subcommand run-feeds
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function run_feeds(array $args, array $assoc): void
    {
        $report = $this->plugin->coreFeed()->run();
        $this->plugin->counters()->flush();
        if ($report['locked']) {
            WP_CLI::warning('Another run holds the lock. Nothing done.');
            return;
        }
        WP_CLI::success(sprintf(
            'Inserted %d, dropped %d, skipped %d existing, %d already dropped, %d without link, %d empty; %d feed(s) failed.',
            $report['inserted'],
            $report['dropped'],
            $report['skipped_existing'],
            $report['skipped_seen'],
            $report['skipped_no_link'],
            $report['skipped_empty'],
            $report['failed_feeds']
        ));
    }

    /**
     * Clear error badges and error counts for one rule, or for all rules.
     *
     * ## OPTIONS
     *
     * [<rule-id>]
     * : The rule. Default: every rule.
     *
     * @subcommand reset-errors
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function reset_errors(array $args, array $assoc): void
    {
        $this->plugin->counters()->resetErrors($args[0] ?? null);
        $this->plugin->counters()->flush();
        WP_CLI::success('Error badges cleared.');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $fields
     */
    private function output(array $rows, string $format, array $fields): void
    {
        if ($format === 'json') {
            WP_CLI::line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        \WP_CLI\Utils\format_items($format === 'csv' ? 'csv' : 'table', $rows, $fields);
    }
}
