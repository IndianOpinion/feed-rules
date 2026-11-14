# Architecture

This document describes how feed-rules 0.1.0 is built from `docs/PRD.md`, and records every decision taken while building it, including the rulings on the PRD's open questions.

## Modules

All classes live under `src/` in the namespace `IndianOpinion\FeedRules`. Composer is not needed at run time: `src/autoload.php` is a small PSR-4 autoloader that the main plugin file requires.

| Module | Role |
|---|---|
| `feed-rules.php` | Plugin header and hook registration only. Services are built lazily, so a public page request loads nothing beyond this file unless an importer calls the filter. |
| `Plugin` | Builds the services once per request and wires them to WordPress (shutdown flush, activation, deactivation). |
| `Item` | Immutable imported entry. `fromArray()` validates the URL and throws `InvalidItemException` naming the field. |
| `Text` | Normalisation: script and style removal, tag stripping with block boundaries, entity decoding (three passes), UTF-8 repair, NFC, whitespace collapse, case folding, window cut, context. |
| `FieldTexts` | The normalised text of each field of one item, computed once; folded and unaccented variants on demand. Applies `feed_rules_item_text`. |
| `KeywordList` | The one place keyword lists (text or array) are parsed, trimmed, de-duplicated and checked. |
| `RegexPattern` | Delimiter and flag validation, compile check, and execution that turns PCRE failures into error values without emitting PHP warnings. |
| `UrlPrefix` | Host and path prefix normalisation and matching. |
| `Condition`, `Rule`, `RuleSet` | Value objects with `fromArray()`, `toArray()` and `validate()`. |
| `Matcher` | Compiles keyword conditions into as few patterns as fit, caches them per request, runs every condition type. |
| `EvaluationRun` | Evaluates each rule at most once per item (AND of conditions) and collects run-time errors. |
| `Engine` | The entry filter, rule precedence, mapping, counters, recording, the decision filter and action, and `applyToPost()`. |
| `Decision`, `Reason`, `RuleError` | The outcome of one evaluation. `Decision` round-trips through an array so it can ride on the item array between the filter and the action. |
| `RuleRepository` | The rule set option with an edit version for optimistic locking; fail-open load. |
| `Settings` | The settings option and the filters that override it. |
| `Counters` | Per-rule daily counters and badges, held in memory and written once per request. |
| `RecentStore`, `Store\RecentStorage`, `Store\WpdbStorage`, `Store\MemoryStorage` | The recent-items store over a storage interface. The custom table is the production storage; the memory storage serves tests and non-WordPress use. |
| `Tester`, `TestReport`, `TestRow` | Dry runs over recorded items and pasted samples; one table shape for the screen, CSV and WP-CLI. |
| `Adapter`, `Registry` | The importer adapter interface and its registry. |
| `Adapter\CoreFeedAdapter`, `Adapter\UrlKey`, `Adapter\DroppedKeys` | The reference adapter over `fetch_feed()`. |
| `ImportExport` | JSON export and all-or-nothing import. |
| `Admin\AdminPage`, `Admin\Forms` | Tools > Feed rules. `Forms` turns submitted arrays into rules and settings and is unit tested. |
| `Cli\Command` | `wp feed-rules ...`. |

## Data flow

```
importer ──apply_filters('feed_rules_filter_item', $item, $importerId)──▶ Engine::filter
    Engine::filter: switch off? return unchanged
                    Item::fromArray fails? count invalid, return unchanged
                    Engine::evaluate
                        FieldTexts (normalise once, window the body)
                        keep rules ▶ exclude rules ▶ include requirement
                        test-state rules (would results)
                        mapping (categories, tags, author)
                        feed_rules_decision filter
                        counters (memory), RecentStore::record, feed_rules_decided
    returns false (dropped) or $item + ['feed_rules_decision' => [...]]
importer inserts the post
importer ──do_action('feed_rules_apply_to_post', $postId, $item)──▶ Engine::applyAction
    applyToPost: categories (append or replace), default category switch,
                 tags (rule ids, copied source tags), author, one post meta key
shutdown ──▶ Counters::flush (one option write)
```

The test screen calls `Tester::run()`, which calls `Engine::evaluate(..., dryRun: true)` for each stored item and pasted sample. A dry run treats test-state rules as live, writes nothing and fires no `feed_rules_decided`.

## Rulings on the PRD's open questions

1. **Always-keep override.** Ruled: a `keep` rule beats any exclude ("skip") rule for the same item. Built as a sixth action, `keep`. Precedence for one item is: a live `keep` rule that matches keeps the item, and then neither exclude rules nor the include requirement are applied; otherwise the first matching live `exclude` rule drops the item; otherwise the include requirement applies. Mapping rules still run on a kept item. A `keep` rule in test state records "would keep" and changes nothing. Reason: scope alone cannot express "trust this source even when an exclude rule matches", and the precedence has to be one rule that operators can remember.
2. **Body window.** Ruled: match against the first 2,000 characters of the stripped body by default, with the size a filter. The default is 2,000 (PRD said 50,000), the `feed_rules_body_window` filter and the Settings field change it. The store keeps the body cut to the same window, so a test run sees exactly what the live run saw, and the "body cut" mark is carried through the store. Reason: most deciding words are in the opening of a story; a small window keeps both matching and the store cheap.
3. **Pasted sample items.** Ruled: yes. The Test tab accepts pasted title and body pairs, run alongside the recent items. `Tester::run()` takes a list of sample `Item`s; pasted rows are marked "pasted", use the importer id `pasted` and a placeholder URL on the reserved `.invalid` domain when none is given, and do not count towards "newly dropped" or "newly kept". Reason: a new site, or an adapter that cannot sample, still needs a way to try a rule.
4. **Test-state hits and post meta.** Ruled: never write test hits to post meta. Rule hit counters live in one option; a real import writes a single post meta key holding the matched rule ids. Built as: counters (hits, would-hits, errors per rule per day, plus badges) in the option `feed_rules_counters`; one post meta key, `_feed_rules_match`, holding `rules` (the ids of the live rules that matched) and `time`. Test-state rules never appear in it. Reason: post meta is per post and permanent; test results are about rules, not posts.
5. **Recent-items limit.** Ruled: per importer adapter, with one global option for the cap. The store keeps the newest N rows per importer id; N comes from the `recent_limit` setting (default 100), the `feed_rules_recent_limit` filter can override it, and it is clamped to 20 to 1,000. Pruning runs per importer in the same request that inserts a row. Reason: a busy importer must not push a quiet one out of the test screen.
6. **Category mode.** Ruled: append by default; replace is opt-in per rule. Each rule has `category_mode` `append` (default) or `replace`. When any matching live categorise rule asks for replace, the post's categories are set to the mapped set; otherwise mapped categories are added to the importer's. The remove-default-category switch only applies in append mode, because replace already removes it. Reason: an importer's own categories are usually right; replacing them should be a deliberate choice for one rule.

## Builder decisions

7. **No publishing.** The reference adapter creates `draft` or `pending` posts only. The PRD allowed `publish`; the project rule is that a human stays in the loop, so `publish` is not offered and the `feed_rules_core_feed_post_status` and `feed_rules_core_feed_post_data` filters are clamped to `draft` or `pending`.
8. **Invisible characters.** No-break space, zero-width space, word joiner and BOM become spaces, as the PRD says. Zero-width joiner, zero-width non-joiner and soft hyphen are removed instead, because they occur inside Indic and Persian words and turning them into spaces would split those words. Keywords pass through the same normalisation, so the comparison stays symmetric.
9. **Case folding and context.** Matching folds both sides with full Unicode case folding (`mb_convert_case(..., MB_CASE_FOLD)`), so `ß` matches `ss`. Matched text and its 60-character context are taken from the text that was matched, so on the test screen they appear folded.
10. **Negation on every condition.** The PRD asks for a negate switch on regex conditions. The switch is available on every condition type, since it costs nothing and "URL does not start with" is a common need.
11. **Regex fields.** A regex runs against the normalised but not folded text: case is controlled by the pattern's `i` flag, not by the case-sensitive switch. The accent switch does apply.
12. **Errors.** A rule whose conditions include one in error, and none that is false, is in error for that item. An error is never a match: an exclude, keep or mapping rule in error does nothing, and an include rule in error waives the include requirement for that item. Errors bump the rule's error counter and set an error badge that stays until `reset-errors` or the Clear badges button.
13. **Combined patterns.** Keyword alternatives are packed into patterns of at most 24,000 bytes of source. A group that still fails to compile is split in half until it compiles. `all` mode compiles one pattern per keyword. Patterns are cached per request by a hash of the condition.
14. **Counter writes.** Counters accumulate in memory and are written once, on `shutdown`, and also at the end of each reference adapter run and CLI command. Days older than 14 are pruned on write. The invalid-item counter is kept as a pseudo rule `_invalid`.
15. **Mapping order.** Author: the first matching live `author` rule whose user exists and can `edit_posts`, then the source author name table, then the source key table, then the adapter's default author. Category table rows apply to source categories and source tags. Rule tags are stored as term ids: names typed in the rule form are resolved, and created if new, when the rule is saved. Test runs only look tags up, never create them.
16. **Missing terms.** A mapped category or tag that no longer exists is skipped at apply time, logged with the rule name, and shown as a warning badge on the rule. `reset-errors` clears warnings too.
17. **Dropped keys.** The reference adapter remembers dropped URL keys as SHA-1 hashes with their time (30 days, newest 5,000), written once per run. It also stores each imported post's URL key in `_feed_rules_url_key` so it can skip existing posts with one meta query; that key is removed on uninstall. The source URL meta (`_feed_rules_source_url`) is kept as provenance.
18. **Recovering from an unreadable rule set.** Saving over an unreadable set is allowed whatever edit version the form carried, so an operator can always recover by importing or saving.
19. **Store schema.** One table, `{prefix}feed_rules_recent`: `importer_id`, `url_hash` (SHA-1 of the URL), `url`, `source_key`, `seen_at`, `sampled`, `data` (JSON: normalised fields and the decision). Unique on importer id plus URL hash; indexed on importer id plus time. The table is checked once per request on first write and created when missing; a failure turns recording off for the request and is logged once.
20. **Sampling.** "Load current items" evaluates sampled items with the live rule set (test rules stay in test) without counting or firing actions, then records them with `sampled` set.
21. **CSV.** The test CSV has the same columns as the screen. Cells starting with `=`, `+`, `-` or `@` are prefixed with an apostrophe so spreadsheets do not run them.
22. **Static analysis.** PHPStan runs at level 8 over `src/`, the main file and `uninstall.php`, with the WordPress and WP-CLI stubs from `tests/stubs/` as bootstrap files.

## Testing

PHPUnit 10 runs against a small stub layer (`tests/stubs/wordpress.php`, `tests/stubs/wp-cli.php`). The stubs keep options, transients, hooks, posts, meta, terms, users, cron and feeds in memory, and a fake `wpdb` records queries. Every acceptance test in section 12 of the PRD maps to at least one test; the test docblocks name the case number.
