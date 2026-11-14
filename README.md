# feed-rules

Include and exclude rules, category, tag and author mapping for items any importer brings into WordPress, with a test screen that shows what a rule would have done to the last hundred real items. It puts every rule in one place, matches whole words correctly in any script (Hindi and Tamil included), and lets a rule run in a log-only test state before it is allowed to act.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

Measured result: in production, one over-broad exclude pattern once held almost every item of a day, and every item sampled from that set was a good article. The test state and the test screen exist so that cannot happen silently again. On PHP 8.3 one item against 50 rules holding 5,000 keywords takes about 0.7 ms (`php examples/benchmark.php`).

## Install

Requirements: WordPress 6.4 or later, PHP 8.1 or later with `mbstring` and PCRE. `intl` is used for NFC normalisation when present.

Without Composer:

1. Download the release zip, or `git clone https://github.com/IndianOpinion/feed-rules` into `wp-content/plugins/feed-rules`.
2. Activate "Feed Rules" under Plugins.

With Composer (the package is a `wordpress-plugin`, so use your installer paths):

```
composer require indianopinion/feed-rules
```

As a must-use plugin: put the folder at `wp-content/mu-plugins/feed-rules/` and copy `examples/mu-plugin-loader.php` to `wp-content/mu-plugins/feed-rules-loader.php`. The recent-items table is then created on first use instead of on activation.

## Quick start

1. Tools > Feed rules > Settings. Add a feed under "Reference adapter" if you want the built-in importer, or connect your own importer (below).
2. Rules > Add rule. Pick an action (`exclude`, `include`, `keep`, `categorise`, `tag`, `author`), add one to ten conditions, save. New rules start in the `test` state: they are evaluated and logged, and change nothing.
3. Test tab. Run the rule against the last hundred items, or paste a title and body. Check "newly dropped" before you go further.
4. When the test looks right, set the rule to `live`.

Connecting an importer takes two lines around its post insert:

```php
$item = apply_filters('feed_rules_filter_item', [
    'url' => $link, 'title' => $title, 'body' => $html, 'source_name' => 'Example Wire',
], 'my-importer');
if ($item === false) {
    return; // Dropped by a rule.
}
$postId = wp_insert_post([...]);
do_action('feed_rules_apply_to_post', $postId, $item);
```

See `examples/importer-integration.php` and `examples/custom-adapter.php`. An example rule set for `wp feed-rules import` is in `examples/rules.json`.

## Configuration

Settings live under Tools > Feed rules > Settings. Every default can also be changed in code.

| Setting | Default | Filter |
|---|---|---|
| Global switch | on | `feed_rules_enabled` |
| New rule state | `test` | |
| Field | `title_body` | |
| Case-sensitive | off | |
| Whole words | on | |
| Accent-insensitive | off | |
| Keyword mode | `any` | |
| Body window | 2,000 characters | `feed_rules_body_window` |
| Recent items kept, per importer | 100 (20 to 1,000) | `feed_rules_recent_limit` |
| Category cap per item | 10 | |
| Category mode per rule | append | |
| Copy source tags | off | |
| Create new tags | off | |
| Remove default category when mapped | off | |
| Reference adapter post status | `draft` (`pending` allowed) | `feed_rules_core_feed_post_status` |
| Reference adapter interval | hourly | |
| Reference adapter items per run | 20 | |
| Error log line per drop | off | `feed_rules_error_log` |
| Capability for the screens | `manage_options` | `feed_rules_capability` |

Hooks:

| Hook | Type | Purpose |
|---|---|---|
| `feed_rules_filter_item` | filter | Entry point: `(array $item, string $importerId)`. Returns the item with `feed_rules_decision` added, or `false`. |
| `feed_rules_apply_to_post` | action | `(int $postId, array $item)` after insert. Adds mapped terms and author. |
| `feed_rules_register_adapters` | action | `(Registry $registry)`: `$registry->add($adapter)`. |
| `feed_rules_item_text` | filter | `(string $text, string $field, Item $item)`: strip importer boilerplate before matching. |
| `feed_rules_decision` | filter | `(Decision $d, Item $item, string $importerId)`: last chance to change a decision. |
| `feed_rules_decided` | action | After every live decision. Never fired by test runs. |
| `feed_rules_core_feed_post_data` | filter | `(array $postarr, Item $item)` before the reference adapter inserts. |

WP-CLI: `wp feed-rules list`, `status`, `test <rule-id|all> [--limit] [--importer] [--source] [--format=table|csv|json]`, `export [<file>]`, `import <file> [--dry-run]`, `run-feeds`, `reset-errors [<rule-id>]`.

## How it works

1. The importer passes an item to `feed_rules_filter_item`. The item needs a URL; title, body, source name, source key, author, categories, tags, published time and GUID are optional.
2. Text is normalised once: scripts and styles removed, tags stripped with block boundaries kept as spaces, entities decoded up to three times, bad UTF-8 repaired, NFC applied, whitespace (including no-break and zero-width spaces) collapsed. The body is cut to the window.
3. Rules run in list order, with this precedence:
   - a live `keep` rule that matches keeps the item, whatever the exclude and include rules say (a keep rule in error also keeps it: every failure keeps the item);
   - otherwise the first live `exclude` rule that matches drops it;
   - otherwise, if any live `include` rule is in scope, one must match or the item is dropped with "no include rule matched";
   - `test` rules are evaluated for every item and recorded as "would drop", "would keep" or "would map";
   - a kept item collects categories, tags and an author from mapping rules and the mapping tables.
4. Whole words are found with Unicode letter, mark and number classes, never `\b`, so a Hindi keyword does not match inside a longer Hindi word and a Tamil keyword followed by a vowel sign does not match.
5. Each decision is written to a recent-items table (newest 100 per importer), to per-rule daily counters (one option, 14 days), and, for kept posts, to one post meta key `_feed_rules_match` holding the ids of the live rules that matched and the time.
6. The test screen re-runs any rule set over the stored items and pasted samples with nothing written.

The reference adapter (`core-feed`) is a minimal importer on top of `fetch_feed()`. It runs on WP-Cron, skips URLs it has already imported or dropped, inserts drafts or pending posts only, and calls the same filter and action as any other importer.

## Limitations

- Rules never touch posts that already exist. Use the test screen to see the effect on recent items.
- Only the first 2,000 characters of the body are matched by default. Raise the window if your keywords live deep in long articles.
- No language detection, duplicate detection or freshness checks. No machine learning.
- The reference adapter has no image handling, no full-text extraction and no scheduling beyond hourly, twice daily and daily.
- Matched text and context on the test screen are shown case-folded.

## FAQ

**Does a broken rule stop my imports?**
No. A regex that hits the backtrack limit is counted as an error and the item is kept. An unreadable rule set means every item is kept and an admin notice appears.

**Why is my new rule not doing anything?**
New rules start in `test`. Look at the Log tab for "would drop", then set the rule to `live`.

**Can I trust one source completely?**
Yes. Add a `keep` rule scoped to that source, or matching its URL prefix. A live keep rule beats every exclude rule.

**Do test runs change anything?**
No. Test runs write no counters, no log rows and no post meta, and never fire `feed_rules_decided`.

**Does it publish posts?**
No. The reference adapter creates drafts or pending posts. An editor publishes them.

## Licence

MIT. Copyright (c) 2026 Indian Opinion. See `LICENSE`.
