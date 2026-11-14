# PRD: feed-rules

Status: draft for review. Version 0.1.0. Plugin type: WordPress plugin.

Written from scratch; not derived from any third-party plugin.

One line: include and exclude keyword lists, regular expressions, category
and tag mapping, and author mapping for imported items, with a test screen
that shows what a rule would have done to the last hundred items.

## 1. Problem

A site that imports items from feeds gets everything each feed carries:
job listings, horoscopes, sponsored posts, sections it never wanted. The
operator then fixes it by hand, one post at a time. The usual remedy is a
keyword list inside each importer, and that remedy has three failures.

- Every importer has its own list, its own syntax and its own blind spots.
  Moving to a different importer means rewriting every rule.
- Word matching is written for English. A boundary test such as `\b`
  treats a vowel sign in Devanagari or Tamil as a word break, so a keyword
  in those scripts either never matches or matches inside other words.
- Nobody can see what a rule will do before it is live. In production, one
  over-broad pattern once held almost every item of a day, and every item
  sampled from that set was a good article.

feed-rules puts the rules in one place, applies them to any importer
through a small adapter interface, matches words correctly in any script,
and shows the effect of a rule on the last hundred real items before the
rule is allowed to act.

## 2. Goals and non-goals

Goals:

1. Keep or drop an imported item by keyword lists, regular expressions and
   URL prefixes, before the importer creates a post.
2. Map kept items to local categories, tags and an author.
3. Work with any importer through this plugin's own hook and adapter
   interface. Ship one reference adapter, built on WordPress core's
   `fetch_feed()`.
4. Show, on a test screen, what a saved rule, an unsaved draft rule or the
   whole rule set would have done to the last hundred items seen.
5. Let every rule run in a log-only test state before it acts.
6. Fail open: a broken rule, a broken store or a broken adapter never drops
   an item silently.

Non-goals:

- No adapter for any third-party importer is shipped or described. Others
  can write one against the interface in section 5.
- No machine-learning or language-model classification. Rules are
  deterministic.
- No language detection, freshness check or duplicate detection. Those are
  separate concerns.
- Rules do not apply retroactively to posts that already exist. The test
  screen is how an operator previews the effect.
- No editing or rewriting of item text. The plugin decides and maps; it
  does not change the title or body.
- No front-end output of any kind.
- The reference adapter is a minimal importer, not a full-featured one. It
  has no image handling, no full-text extraction and no scheduling beyond a
  fixed interval.

## 3. Users and setting

- **Who installs it:** a site operator or developer running a WordPress
  site that imports news or other items from feeds.
- **Who uses the screens:** administrators (the `manage_options`
  capability by default, filterable).
- **Where it runs:** inside WordPress, in wp-admin, in WP-Cron runs and in
  WP-CLI. It loads nothing on public front-end requests unless an importer
  calls its hook on such a request.
- **Who integrates with it:** importer authors, who either call the hook in
  section 5.3 directly or write an adapter class.

## 4. Behaviour

Terms used below:

- **Item:** one imported entry. Required: a URL. Optional: title, body
  (HTML), source name, source key, author name, source categories, source
  tags, published time, GUID, importer id.
- **Importer id:** a short slug naming the importer, for example `core-feed`
  for the reference adapter.
- **Source key:** a stable string naming one feed or source inside an
  importer, for example the normalised feed URL.
- **Rule state:** `off` (ignored), `test` (evaluated and logged, never acts)
  or `live` (acts).
- **Verdict:** `keep` or `drop`, with a list of reasons.

### 4.1 Entry and normalisation

1. An importer passes an item array and its importer id to the entry
   filter. The engine evaluates the rule set and returns the item array
   with a decision attached under one reserved key, or `false` when the
   item is dropped.
2. An item with no URL, or a URL that is not absolute `http` or `https`,
   is returned unchanged and not recorded. An invalid-item counter for the
   day goes up by one. Under `WP_DEBUG` a `_doing_it_wrong()` notice names
   the missing field. This fails open because the item problem is the
   importer's, not a rule's.
3. When the global switch is off, or the enable filter returns false, every
   item is returned unchanged, nothing is recorded and no counter moves.
4. Text is normalised once per item before any rule runs:
   - `<script>` and `<style>` elements are removed with their content, then
     all other tags are stripped, and block-level tag boundaries become a
     space so that words on either side do not join;
   - HTML entities are decoded, repeatedly, up to three passes, so a
     double-encoded `&amp;amp;` becomes `&`;
   - invalid UTF-8 sequences are replaced, so a pattern with the `u` flag
     never fails on them;
   - Unicode NFC normalisation runs when the `intl` extension is present;
   - all whitespace runs, including no-break space and zero-width
     characters, collapse to one space;
   - case is folded with full Unicode case folding.
5. The title is matched in full. The body is matched up to a window of
   50,000 characters (filterable), cut on a character boundary, never
   inside a multibyte sequence. A keyword that only appears after the
   window does not match, and the decision records that the body was cut.
6. Before matching, a text filter lets an adapter remove text the importer
   itself added to the body, such as a source footer or a "read more"
   line, so that rules match the item and not the importer's boilerplate.

### 4.2 Keyword matching

7. Matching is case-insensitive by default for every script that has case.
   A rule can switch to case-sensitive.
8. Whole-word matching is the default. A word boundary is defined as: the
   character before the keyword and the character after it are not a
   letter, a combining mark or a number (Unicode classes `L`, `M`, `N`), or
   are the start or end of the text. `\b` is never used. A Hindi keyword
   matches as a whole word in Hindi text, and does not match inside a
   longer Hindi word.
9. With whole-word matching off, a keyword matches anywhere as a substring.
10. A keyword of several words is a phrase. The words match with any run of
    whitespace between them, after normalisation.
11. A trailing `*` makes a keyword a prefix: `elect*` matches `election`
    and `electoral` as whole words that start with `elect`. A `*` anywhere
    else is rejected when the rule is saved, with a message naming the
    line.
12. Every other character in a keyword is literal. Regex metacharacters in
    keywords are escaped.
13. Keyword lists are entered one per line. Lines are trimmed. Blank lines
    and lines starting with `#` are ignored. Duplicates after case folding
    are removed on save, and the save message says how many. The engine
    also accepts a list as an array of strings from code.
14. Accent-insensitive matching is optional per rule, off by default. When
    on, both the keyword and the text have accents removed with
    WordPress's `remove_accents()` before comparison, so `cafe` matches
    `café`.
15. Each condition names the field it reads: `title`, `body`,
    `title_body` (the default: title, a space, then body), `url`,
    `source_name`, `author`, `source_terms` (source categories and tags,
    joined by a separator that cannot match inside a phrase).
16. A keyword condition is `any` (at least one keyword matches, the
    default) or `all` (every keyword matches somewhere in the field).
17. A long keyword list is compiled into as few patterns as fit under the
    PCRE pattern size limit, and compiled once per request. A rule with
    more than 10,000 keywords is rejected on save.

### 4.3 Regular expressions

18. A regex condition takes one pattern in delimited form, for example
    `/\bpaid\s+partnership\b/i`. Allowed flags are `i`, `m`, `s` and `x`.
    The `u` flag is always added. Any other flag, or an undelimited
    pattern, is rejected on save with a message.
19. Every regex is compiled on save. An invalid pattern is rejected with
    the PCRE error text, and the previously saved rule is kept as it was.
20. A regex that fails at run time (backtrack limit, recursion limit, JIT
    stack) makes that condition an error for that item. An erroring
    condition never removes an item: an exclude rule in error counts as
    not matched; an include requirement is waived for that item when any
    in-scope include rule errored. The error is counted against the rule,
    the rule shows an error badge with the last error and its time, and an
    admin notice appears until the counter is reset.
21. A regex longer than 2,000 characters is rejected on save. A regex can
    be negated (`does not match`) by a per-condition switch.

### 4.4 URL prefix matching

22. A URL prefix condition holds lines of the form `host` or
    `host/path/prefix`. On save and at match time:
    - a scheme and a leading `www.` are removed from the rule;
    - the item host is lower-cased and a leading `www.`, `m.`, `amp.` or
      `mobile.` is removed;
    - a rule host matches that host or any subdomain of it, never a host
      that only ends with the same letters (`example.com` matches
      `news.example.com`, not `badexample.com`);
    - a path prefix matches on a segment boundary: `/jobs` matches `/jobs`
      and `/jobs/123`, not `/jobsearch`;
    - path comparison is case-insensitive; query and fragment are ignored.

### 4.5 Rules, scope and order

23. A rule has: an id (generated, stable), a name, a state (`off`, `test`
    or `live`), a scope, one to ten conditions combined with AND, and one
    action. Actions: `exclude`, `include`, `categorise`, `tag`, `author`.
    A new rule is saved in the `test` state unless the operator picks
    another.
24. Scope is one of: all sources; a list of source keys; a list of
    importer ids. A rule out of scope for an item is not evaluated for it.
25. Exclude: rules run in list order. The first live exclude rule that
    matches drops the item, and its name, the field and the matched text
    become the reason. Later live exclude rules are not evaluated.
26. Include: when at least one live include rule is in scope for the item,
    the item must match at least one of them, or it is dropped with the
    reason "no include rule matched". When no live include rule is in
    scope, every item passes this step.
27. Exclude wins over include: an item that matches both is dropped.
28. A rule in `test` state is evaluated for every item in scope whatever
    the live outcome. Its result is recorded as "would drop", "would keep"
    or "would map", and it changes nothing.
29. A rule in `off` state is never evaluated and never shown in a decision.
30. Category mapping: every live `categorise` rule that matches a kept item
    adds its categories. The result is the union, in rule order, capped at
    ten categories per item.
31. Source category mapping: a table maps a source category or tag name
    (after normalisation) to one local category. Each matching row adds
    its category, inside the same cap.
32. Tag mapping: every live `tag` rule that matches adds its tags. A switch,
    off by default, copies source tags onto the post as tags. Copied tags
    are limited to 10 per item, and only existing tags are used unless a
    second switch allows creating new ones.
33. Author mapping: a table maps a source author name (normalised) or a
    source key to a local user. `author` rules can do the same with
    conditions. The first match in order wins. The user must exist and
    have the `edit_posts` capability, or the mapping is skipped. With no
    match, the adapter's default author is used, else the importer's own
    choice stands. The plugin never creates users.
34. Mapping is applied after the importer has created the post, through
    the apply action in section 5.3. Categories and tags are added to what
    the importer set, never replacing it, except that a switch (off by
    default) removes the site's default category when a mapping added
    another category.
35. A mapped category, tag or user that no longer exists when the mapping
    is applied is skipped. The skip is logged with the rule name, and the
    rule shows a warning on the rules screen.
36. A kept post gets one meta entry recording the ids of the rules that
    matched it and the time of the decision, so an editor can see why a
    post landed where it did.
37. Changing a rule never touches existing posts.

### 4.6 Recent items and the test screen

38. Every evaluated item is recorded in a recent-items store: the
    normalised fields the rules read, the body cut to the matching window,
    the importer id, the source key, the time seen, the verdict and the
    reasons. The store keeps the last 100 items (filterable, 20 to 1,000).
    Older rows are pruned in the same request that inserts a row.
39. The same URL from the same importer seen again updates its existing
    row (time, verdict, reasons) instead of adding a second row.
40. The test screen runs one of: a saved rule, the rule currently in the
    edit form (not yet saved), or the whole rule set with or without that
    draft. It runs against the last 100 items in the store, newest first.
    For each item it shows: title, source, time seen, the verdict that was
    recorded, the verdict this run gives, the categories, tags and author
    this run maps, and for each matching rule the field and the matched
    text with up to 60 characters of context each side. A summary row
    counts: kept, dropped, newly dropped, newly kept, and mapping changed.
    Test-state rules are treated as live during a test run, so the screen
    answers "what if this rule were live".
41. When the store is empty, the screen says so and offers, for adapters
    that support it, a "load current items" button. The reference adapter
    supports it: it reads the configured feeds through the feed cache and
    records their items as if they had been seen, marked as sampled.
42. A test run writes nothing: no counters, no log rows, no post meta, no
    changes to the store. It is safe to run any number of times.
43. A draft rule with an invalid keyword line or regex is not run. The
    screen shows the same validation message a save would show.
44. Items whose body was cut to the window are marked in the results so the
    operator knows a later keyword could not have matched.
45. The test screen can be filtered by importer id or source key, and the
    result table can be downloaded as CSV with the same columns.

### 4.7 Reference adapter: WordPress core feeds

46. The operator lists feeds: URL, label, default author, default category,
    post status (`draft` by default; `pending` or `publish` allowed),
    interval (hourly by default), and maximum items per run (20 by
    default). A WP-Cron event per interval reads each feed with
    `fetch_feed()`, turns each entry into an item, passes it to the entry
    filter, inserts a post for each kept item, then applies the mapping.
47. Each item gets a URL key: scheme and host lower-cased, a leading
    `www.` removed, the fragment removed, and query parameters starting
    with `utm_` removed. A post already carrying that key is skipped
    before rules run. A dropped item's key is remembered for 30 days in a
    bounded set (newest 5,000 keys), so the same dropped item is not
    evaluated, recorded and counted again on every run.
48. When `fetch_feed()` returns an error for one feed, the error text and
    time are stored for that feed and shown on the settings screen. The
    other feeds in the run carry on. The failed feed is tried again on the
    next run.
49. An entry with no link is skipped and counted. An entry with no title
    takes the first ten words of its text as the title. An entry with
    neither title nor text is skipped and counted.
50. No more than the configured maximum of items per feed is inserted in
    one run, newest first by published time, then by feed order.
51. The adapter shortens the feed cache lifetime to one minute under the
    run interval, for its own calls only, so a 12-hour default cache does
    not hide new items. It restores the filter after each call.
52. A run takes a lock (a transient with a ten-minute expiry). A run that
    finds the lock held exits at once and logs that it did so.
53. Body HTML is passed through `wp_kses_post()` and the title through
    `sanitize_text_field()` before insert. The source URL is stored as
    post meta.

### 4.8 Failure modes

54. The stored rule set is unreadable or has an unknown schema version:
    the engine treats it as empty (every item kept, no mapping), shows an
    admin notice, and writes one error log line per hour at most. Fails
    open, because a lost rule set must not stop imports.
55. An adapter throws while building an item or applying a decision: the
    exception is caught, the item passes through unchanged, and the error
    is logged with the adapter id. Fails open.
56. The recent-items table is missing (for example on a new site in a
    network): the plugin creates it once in that request. If that fails,
    it stops recording for the request and keeps filtering. Fails open
    for imports; the test screen says the store is unavailable.
57. Two administrators save the rule set at the same time: each save
    carries the version it was loaded at, and a save against an older
    version is rejected with "rules changed since you opened this page",
    keeping the newer rules.
58. Rules are exported and imported as JSON through WP-CLI. An import is
    validated in full first and applied all at once, or not at all.

## 5. Public interface

Names below are this plugin's own. PHP namespace
`IndianOpinion\FeedRules`. Hook and option prefix `feed_rules_`.

### 5.1 Classes

- `Item` (final, immutable). `Item::fromArray(array $data): Item` throws
  `InvalidItemException` naming the field. `toArray(): array`. Getters for
  every field in section 4.
- `Decision` (final, immutable). `isKept(): bool`, `reasons(): list<Reason>`,
  `wouldReasons(): list<Reason>` (test-state results), `categories():
  list<int>`, `tags(): list<int|string>`, `authorId(): ?int`,
  `errors(): list<RuleError>`, `bodyTruncated(): bool`, `toArray()`.
- `Reason`: rule id, rule name, action, field, matched text, offset.
- `Rule`, `Condition`, `RuleSet`: value objects with `fromArray()` and
  `toArray()`. `RuleSet::validate(): list<ValidationError>`.
- `Engine::evaluate(Item $item, string $importerId, ?RuleSet $rules = null,
  bool $dryRun = false): Decision`. With `$dryRun` true nothing is written
  and test-state rules are treated as live.
- `Engine::applyToPost(int $postId, Decision $decision): void`.
- `RecentStore`: `record(Item, string $importerId, Decision): void`,
  `latest(int $limit = 100, ?string $importerId = null, ?string
  $sourceKey = null): list<RecordedItem>`, `prune(): int`.
- `Tester::run(RuleSet $rules, int $limit = 100, ?string $importerId =
  null, ?string $sourceKey = null): TestReport`.
- Exceptions: `InvalidItemException`, `InvalidRuleException`,
  `StoreUnavailableException`, `RulesConflictException`. All extend
  `FeedRulesException`.

### 5.2 Adapter interface

```
interface Adapter
  id(): string                     slug, lower case, 1 to 40 chars
  label(): string                  shown in the admin
  boot(): void                     hook into the importer
  sources(): array<string,string>  source key => label, for the scope picker
  defaultAuthor(string $sourceKey): ?int
  supportsSampling(): bool
  sample(int $limit): list<Item>   only called when supportsSampling()
```

Adapters register on the `feed_rules_register_adapters` action, which
passes the registry: `$registry->add(Adapter $adapter)`. A duplicate id is
rejected with `_doing_it_wrong()`. The reference adapter has id
`core-feed`.

### 5.3 Hooks

| Hook | Type | Arguments | Purpose |
|---|---|---|---|
| `feed_rules_filter_item` | filter | `array $item, string $importerId` | Entry point. Returns the item with key `feed_rules_decision` added, or `false` when dropped. |
| `feed_rules_apply_to_post` | action | `int $postId, array $item` | Importer calls after insert, passing the array the filter returned. |
| `feed_rules_register_adapters` | action | `Registry $registry` | Register adapters. |
| `feed_rules_item_text` | filter | `string $text, string $field, Item $item` | Strip importer boilerplate before matching. |
| `feed_rules_decision` | filter | `Decision $d, Item $item, string $importerId` | Last chance to change a decision. |
| `feed_rules_decided` | action | `Decision $d, Item $item, string $importerId` | After every live decision. Not fired in test runs. |
| `feed_rules_enabled` | filter | `bool` | Global switch from code. Default: the stored switch. |
| `feed_rules_body_window` | filter | `int` | Default 50000. |
| `feed_rules_recent_limit` | filter | `int` | Default 100, clamped 20 to 1000. |
| `feed_rules_capability` | filter | `string` | Default `manage_options`. |
| `feed_rules_error_log` | filter | `bool` | Write one line per drop to the PHP error log. Default false. |
| `feed_rules_core_feed_post_status` | filter | `string, string $sourceKey` | Default from the feed's setting. |
| `feed_rules_core_feed_post_data` | filter | `array $postarr, Item $item` | Change the post before insert. |

### 5.4 Admin screens

Under Tools, "Feed rules", with four tabs: Rules, Test, Log, Settings.
Rules lists rules with state, scope, action, hit counts for today and the
last seven days, last hit time and error or warning badges; rules can be
reordered and duplicated. Test is section 4.6. Log shows the recent-items
store with verdicts and reasons. Settings holds the global switch, the
mapping tables, the tag switches, the default-category switch and the
reference adapter's feed list.

### 5.5 WP-CLI

`wp feed-rules list`, `wp feed-rules status`, `wp feed-rules test
<rule-id|all> [--limit=<n>] [--importer=<id>] [--source=<key>]
[--format=table|csv|json]`, `wp feed-rules export [<file>]`, `wp
feed-rules import <file> [--dry-run]`, `wp feed-rules run-feeds` (one run
of the reference adapter), `wp feed-rules reset-errors [<rule-id>]`.

## 6. Data

| What | Where | Retention | Uninstall |
|---|---|---|---|
| Rule set with schema version and edit version | one option, not autoloaded | until changed | deleted |
| Settings and mapping tables | one option, not autoloaded | until changed | deleted |
| Per-rule daily counters (hits, would-hits, errors) | one option, not autoloaded | 14 days, pruned on write | deleted |
| Recent items | one custom table, unique on importer id plus URL hash | last 100 by default | dropped |
| Reference adapter feeds and per-feed last error | inside the settings option | until changed | deleted |
| Dropped-item key set | one option, not autoloaded | 30 days, 5,000 keys | deleted |
| Rule ids and decision time on kept posts | one post meta key | life of the post | meta deleted, posts kept |
| Source URL on posts from the reference adapter | one post meta key | life of the post | kept (it is content provenance) |
| Run lock | one transient | ten minutes | deleted |

Deactivation clears the scheduled cron events. Uninstall removes
everything above marked deleted or dropped, on every site of a network.
Posts are never deleted.

## 7. Configuration and sensible defaults

| Setting | Default |
|---|---|
| Global switch | on |
| New rule state | `test` |
| Field | `title_body` |
| Case-sensitive | off |
| Whole words | on |
| Accent-insensitive | off |
| Keyword mode | `any` |
| Body window | 50,000 characters |
| Recent items kept | 100 |
| Category cap per item | 10 |
| Copy source tags | off |
| Create new tags | off |
| Remove default category when mapped | off |
| Reference adapter post status | `draft` |
| Reference adapter interval | hourly |
| Reference adapter items per run | 20 |
| Error log line per drop | off |

A fresh install has no rules, so it changes nothing until a rule is set to
`live`.

## 8. Security and privacy

- Every admin screen and form checks the capability from the capability
  filter and a nonce. The test screen runs on POST with a nonce.
- Regular expressions come only from users with that capability. They run
  with errors caught and counted; no pattern can stop an import.
- Nothing is evaluated as code. No `eval`, no shell, no `@`.
- All output is escaped. Item titles, bodies, matched text and context in
  the test and log screens are shown as text, never as HTML.
- Feed URLs are validated with `wp_http_validate_url()` before they are
  saved and fetched through core's HTTP layer, so `reject_unsafe_urls`
  applies.
- Imported HTML is filtered with `wp_kses_post()` before insert.
- Personal data: feed author names are stored in the recent-items store for
  the last 100 items only. The plugin adds a short paragraph to the
  privacy policy guide saying so. Nothing is sent off the site.

## 9. Performance budget

- One item against 50 rules holding 5,000 keywords in total: under 5 ms on
  PHP 8.3 on ordinary hardware, after the first compile in the request.
- One test run of 100 items against the whole rule set: under one second.
- The rule set is read once per request and compiled once per request.
- Recording an item: one upsert, plus one bounded delete when the store is
  over its limit.
- Counters: one option write per cron run or request, not one per item.
- Public front-end requests: no queries and no files loaded beyond the
  main plugin file, unless an importer calls the hook.

## 10. Compatibility

- PHP 8.1, 8.2 and 8.3. Requires `mbstring` and PCRE with Unicode
  support; uses `intl` when present.
- WordPress 6.4 and later. Single site and multisite (rules are per site).
- Works with any page cache and any object cache. Nothing front-end is
  cached or varied.
- Works as a must-use plugin through a one-line loader. In that case the
  table is created on first use rather than on activation.
- Importers integrate by calling the filter and the action in section 5.3,
  or through an adapter class.

## 11. Observability

- Log tab: the recent-items store, newest first, with verdict, reasons,
  would-reasons, errors and a "body cut" mark.
- Rules tab: hits today and over seven days, would-hits for test-state
  rules, error and warning badges with the last message and time.
- `wp feed-rules status`: global switch, rule counts by state, store size,
  counters for the last seven days, rules with errors, reference adapter
  feeds with last run and last error.
- Optional PHP error log line per dropped item:
  `feed-rules drop importer=<id> rule=<name> url=<url>`.
- Admin notices: unreadable rule set, rules with run-time errors, missing
  store.

## 12. Acceptance tests

Each test names the case it proves. Tests run under PHPUnit 10 against a
stub layer, without a WordPress install.

1. (1) A valid item through the filter returns an array with the decision
   key; a dropped item returns `false`.
2. (2) Items with no URL, a relative URL and an `ftp:` URL come back
   unchanged; the invalid counter rises by three; nothing is recorded.
3. (3) With the switch off, an item matching a live exclude rule is
   returned unchanged and the store is untouched.
4. (4) A body with `<script>x</script>`, `foo<br>bar`, `&amp;amp;`, an
   invalid UTF-8 byte, a no-break space and a zero-width space normalises
   to the expected text, and a `u` pattern runs without error on it.
5. (5) A keyword placed after character 50,000 does not match and the
   decision reports the body as cut; a multibyte character at the cut is
   not split.
6. (6) A text filter that removes a footer stops a footer-only keyword
   from matching.
7. (7) `ELECTION` matches `election`; `ÉCOLE` matches `école`; with
   case-sensitive on, `Apple` does not match `apple`.
8. (8) A Hindi keyword matches as a whole word in Hindi text and does not
   match inside a longer Hindi word; a Tamil keyword followed by a vowel
   sign does not match; `cat` does not match `category`.
9. (9) With whole words off, `cat` matches `category`.
10. (10) The phrase `prime minister` matches `Prime  Minister` and
    across a line break.
11. (11) `elect*` matches `electoral` and not `select`; `el*ct` is rejected
    on save naming the line.
12. (12) The keyword `c++ (beta)` matches the literal text and causes no
    pattern error.
13. (13) A textarea with blanks, comments and case duplicates saves the
    expected list and reports the duplicate count; an array from code
    gives the same result.
14. (14) With accents off, `cafe` does not match `café`; with accents on,
    it does.
15. (15) The same keyword is tested against each field and matches only in
    the field the condition names.
16. (16) `any` matches with one keyword present; `all` needs every keyword.
17. (17) A rule with 9,000 keywords compiles into patterns under the size
    limit and matches its last keyword; 10,001 keywords are rejected.
18. (18) `/x/e`, `x` without delimiters and `/x/g` are rejected; `/x/imsx`
    is accepted and runs with `u`.
19. (19) An unbalanced pattern is rejected with the PCRE message and the
    saved rule is unchanged.
20. (20) A catastrophic pattern against a long subject errors: the item is
    kept, the error counter rises, the rule carries the error badge; the
    same for an include rule, where the requirement is waived.
21. (21) A 2,001-character regex is rejected; a negated regex keeps items
    it does not match and drops items it does, as an exclude.
22. (22) `https://www.example.com/jobs` matches `m.example.com/jobs/1`
    and `news.example.com/JOBS`, not `badexample.com/jobs` and not
    `example.com/jobsearch`; the query string is ignored.
23. (23) A rule with eleven conditions is rejected; a new rule saves in
    `test` state; all conditions must match for the rule to match.
24. (24) A rule scoped to source A does not evaluate for source B; a rule
    scoped to importer X does not evaluate for importer Y.
25. (25) Two matching live exclude rules: the first named is the reason and
    the second is not evaluated.
26. (26) With one live include rule in scope, a non-matching item is
    dropped with "no include rule matched"; with none in scope it is kept.
27. (27) An item matching a live include and a live exclude is dropped.
28. (28) A test-state exclude rule that matches leaves the item kept and
    records "would drop".
29. (29) An off rule that would match appears nowhere in the decision.
30. (30) Three matching category rules give the union in rule order; a
    twelfth category is not added.
31. (31) A source category `Cricket ` maps to the local category set for
    `cricket`.
32. (32) Tag rules add their tags; copy-tags off copies nothing; on, it
    copies only existing tags up to ten; with create on, a new tag is
    created.
33. (33) The first author rule wins; a user without `edit_posts` is
    skipped and the default author is used; no user is ever created.
34. (34) Applying a mapping keeps the importer's categories; with the
    switch on, the default category is removed only when another category
    was added.
35. (35) A mapped category deleted before apply is skipped and the rule
    carries a warning.
36. (36) A kept post carries meta with the matched rule ids and the time.
37. (37) Saving a changed rule leaves existing posts and their terms as
    they were.
38. (38) After 105 items the store holds the latest 100 with verdicts and
    reasons; the limit filter clamps 5 to 20 and 5,000 to 1,000.
39. (39) The same URL from the same importer twice leaves one row with the
    later time; from two importers, two rows.
40. (40) A draft rule run on the test screen reports the per-item outcome,
    the matched text with context, and correct summary counts including
    newly dropped and newly kept; a test-state rule is treated as live.
41. (41) An empty store shows the message; the reference adapter's sample
    fills the store with items marked as sampled.
42. (42) A test run leaves the counters, the store and post meta
    byte-for-byte unchanged and fires no `feed_rules_decided`.
43. (43) A draft with an invalid regex shows the validation message and no
    results.
44. (44) An item with a cut body carries the mark in the results.
45. (45) The source filter limits the rows; the CSV has the same rows and
    columns as the table.
46. (46) A stubbed `fetch_feed()` with three entries, one excluded, inserts
    two draft posts with mapping applied.
47. (47) An existing post's URL key (with `utm_source` and a fragment
    added) is skipped; a dropped item seen on the next run is not
    evaluated again; the key set stays at 5,000.
48. (48) One feed returning `WP_Error` stores its error; the next feed in
    the run still imports.
49. (49) A linkless entry is skipped and counted; a titleless entry gets
    ten words; an empty entry is skipped.
50. (50) A feed with 30 new entries and a limit of 20 inserts the 20 newest.
51. (51) The cache lifetime filter is set during the adapter's call and
    removed after it.
52. (52) A run with the lock held exits without fetching and logs it.
53. (53) A body with `<script>` is inserted without it; the title has tags
    removed; the source URL meta is set.
54. (54) A corrupt rule option keeps every item, shows the notice, and logs
    once within an hour.
55. (55) An adapter that throws in apply leaves the item and post as they
    were and logs the adapter id.
56. (56) With the table missing, the first record creates it; with
    creation failing, filtering still works and the test screen reports
    the store unavailable.
57. (57) A save with an old edit version is rejected and the newer rules
    stay.
58. (58) An import file with one invalid rule changes nothing; `--dry-run`
    reports and changes nothing; a valid file replaces the set.

## 13. Open questions

The builder answers each in `docs/ARCHITECTURE.md`.

1. Should an `always keep` action exist that overrides exclude rules for
   trusted sources, or is scope enough?
2. Is a 50,000-character body window right for the store, or should the
   store keep less (for example 20,000) and the engine match more?
3. Should the test screen also accept pasted sample items (title, body,
   URL) when the store is empty and the adapter cannot sample?
4. Should test-state rule hits on kept posts also be written to post meta,
   or only to the store?
5. Should the recent-items limit apply per importer or across all
   importers? The default here is across all.
6. Should category mapping offer a per-rule "replace the importer's
   categories" mode in 0.1.0, or wait for demand?

## 14. Lessons from production

Written as rules for the builder.

1. Ship a new rule in a log-only state and look at what it would have done
   to real items before it acts. An over-broad pattern once held almost
   every item of a day, and all of them were good.
2. Never use `\b` for a word boundary. Define the boundary by Unicode
   letter, mark and number classes, or Indic scripts break.
3. Normalise hosts before comparing them (`www.`, `m.`, `amp.`,
   `mobile.`), match subdomains by a dot-prefixed suffix, and match path
   prefixes on segment boundaries. A bare string prefix matches the wrong
   pages.
4. Match the item's own text, not what the importer added to it. Give
   adapters a hook to strip their footers before rules run.
5. Decode entities more than once. Feeds double-encode titles.
6. Record every decision with the rule, the field and the matched text. A
   refusal with no recorded reason cannot be audited or fixed.
7. Keep logs bounded: a fixed number of recent rows and per-day counters.
   Never let a log option grow without limit.
8. Accept a list as an array or as text with one entry per line, and
   normalise it once, in one place.
9. A guard that cannot run must not drop work. Every failure path in this
   plugin keeps the item and says so.
10. Remember what was dropped, or a polling importer will evaluate and
    count the same dropped item on every run.
11. Nothing in this plugin deletes a post. Dropping means not creating.
