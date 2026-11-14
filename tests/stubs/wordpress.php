<?php

/**
 * A small stand-in for the WordPress functions the plugin calls, so the
 * tests run without a WordPress install. State lives in WpStub.
 *
 * phpcs:disable
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

final class WpStub
{
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, mixed> */
    public static array $transients = [];
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $filters = [];
    /** @var array<string, int> */
    public static array $actionsFired = [];
    /** @var list<array{0: string, 1: string}> */
    public static array $doingItWrong = [];
    /** @var array<int, array<string, mixed>> */
    public static array $posts = [];
    /** @var array<int, array<string, mixed>> */
    public static array $meta = [];
    /** @var array<int, array<string, list<int>>> */
    public static array $postTerms = [];
    /** @var array<int, WP_Term> */
    public static array $terms = [];
    /** @var array<int, array{name: string, caps: list<string>}> */
    public static array $users = [];
    /** @var array<string, mixed> */
    public static array $feeds = [];
    /** @var array<string, array{0: int, 1: string, 2: array<mixed>}> */
    public static array $cron = [];
    public static int $nextPost = 100;
    public static int $nextTerm = 500;

    public static function reset(): void
    {
        self::$options = [];
        self::$transients = [];
        self::$filters = [];
        self::$actionsFired = [];
        self::$doingItWrong = [];
        self::$posts = [];
        self::$meta = [];
        self::$postTerms = [];
        self::$terms = [];
        self::$users = [];
        self::$feeds = [];
        self::$cron = [];
        self::$nextPost = 100;
        self::$nextTerm = 500;
    }

    public static function addTerm(int $id, string $name, string $taxonomy): void
    {
        self::$terms[$id] = new WP_Term($id, $name, $taxonomy);
    }

    /**
     * @param list<string> $caps
     */
    public static function addUser(int $id, string $name, array $caps = ['edit_posts']): void
    {
        self::$users[$id] = ['name' => $name, 'caps' => $caps];
    }
}

class WP_Error
{
    public function __construct(private string $code = '', private string $message = '')
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_code(): string
    {
        return $this->code;
    }
}

class WP_Term
{
    public int $term_id;
    public string $name;
    public string $taxonomy;

    public function __construct(int $id, string $name, string $taxonomy)
    {
        $this->term_id = $id;
        $this->name = $name;
        $this->taxonomy = $taxonomy;
    }
}

class WP_User
{
    public int $ID;
    public string $display_name;

    public function __construct(int $id, string $name)
    {
        $this->ID = $id;
        $this->display_name = $name;
    }
}

/**
 * A minimal SimplePie-like feed for fetch_feed().
 */
class SimplePie
{
    /** @param list<SimplePie_Item> $items */
    public function __construct(private array $items)
    {
    }

    /** @return list<SimplePie_Item> */
    public function get_items(int $start = 0, int $end = 0): array
    {
        return $this->items;
    }
}

class SimplePie_Item
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data)
    {
    }

    public function get_permalink(): ?string
    {
        $v = $this->data['link'] ?? null;
        return is_string($v) ? $v : null;
    }

    public function get_title(): ?string
    {
        $v = $this->data['title'] ?? null;
        return is_string($v) ? $v : null;
    }

    public function get_content(): ?string
    {
        $v = $this->data['content'] ?? null;
        return is_string($v) ? $v : null;
    }

    public function get_date(string $format = 'U'): ?string
    {
        $v = $this->data['date'] ?? null;
        return is_int($v) ? (string) $v : null;
    }

    public function get_id(): string
    {
        $v = $this->data['id'] ?? ($this->data['link'] ?? '');
        return is_string($v) ? $v : '';
    }

    public function get_author(): ?SimplePie_Author
    {
        $v = $this->data['author'] ?? null;
        return is_string($v) ? new SimplePie_Author($v) : null;
    }

    /** @return list<SimplePie_Category>|null */
    public function get_categories(): ?array
    {
        $v = $this->data['categories'] ?? null;
        if (!is_array($v)) {
            return null;
        }
        $out = [];
        foreach ($v as $label) {
            $out[] = new SimplePie_Category((string) $label);
        }
        return $out;
    }
}

class SimplePie_Author
{
    public function __construct(private string $name)
    {
    }

    public function get_name(): string
    {
        return $this->name;
    }
}

class SimplePie_Category
{
    public function __construct(private string $label)
    {
    }

    public function get_label(): string
    {
        return $this->label;
    }

    public function get_term(): string
    {
        return $this->label;
    }
}

/**
 * Enough of wpdb for the storage tests: it records queries and answers
 * from a script of canned results.
 */
class wpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var list<string> */
    public array $queries = [];
    public bool $tableExists = false;
    public bool $canCreate = true;
    /** @var list<array<string, mixed>> Rows returned by get_results(). */
    public array $rows = [];

    public function prepare(string $query, mixed ...$args): string
    {
        $i = 0;
        return (string) preg_replace_callback('/%[sd]/', static function (array $m) use (&$i, $args): string {
            $v = $args[$i++] ?? '';
            return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
        }, $query);
    }

    public function query(string $query): int|bool
    {
        $this->queries[] = $query;
        if (str_starts_with($query, 'CREATE TABLE')) {
            if ($this->canCreate) {
                $this->tableExists = true;
                return true;
            }
            $this->last_error = 'CREATE command denied';
            return false;
        }
        if (str_starts_with($query, 'DROP TABLE')) {
            $this->tableExists = false;
            return true;
        }
        return $this->tableExists ? 1 : false;
    }

    public function get_var(string $query): mixed
    {
        $this->queries[] = $query;
        if (str_starts_with($query, 'SHOW TABLES')) {
            return $this->tableExists ? $this->prefix . 'feed_rules_recent' : null;
        }
        return 0;
    }

    public function get_row(string $query, string $output = 'OBJECT'): mixed
    {
        $this->queries[] = $query;
        return null;
    }

    public function get_results(string $query, string $output = 'OBJECT'): mixed
    {
        $this->queries[] = $query;
        return $this->rows;
    }

    /** @return array<mixed> */
    public function get_col(string $query): array
    {
        $this->queries[] = $query;
        return [];
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }
}

// Hooks.

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    WpStub::$filters[$hook][$priority][] = [$callback, $args];
    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}

function remove_filter(string $hook, callable $callback, int $priority = 10): bool
{
    foreach (WpStub::$filters[$hook][$priority] ?? [] as $i => $entry) {
        if ($entry[0] === $callback) {
            unset(WpStub::$filters[$hook][$priority][$i]);
            WpStub::$filters[$hook][$priority] = array_values(WpStub::$filters[$hook][$priority]);
            return true;
        }
    }
    return false;
}

function has_filter(string $hook, callable|false $callback = false): bool|int
{
    foreach (WpStub::$filters[$hook] ?? [] as $priority => $entries) {
        foreach ($entries as $entry) {
            if ($callback === false || $entry[0] === $callback) {
                return $callback === false ? true : $priority;
            }
        }
    }
    return false;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    $callbacks = WpStub::$filters[$hook] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $entries) {
        foreach ($entries as [$callback, $count]) {
            $value = $callback(...array_slice(array_merge([$value], $args), 0, max(1, $count)));
        }
    }
    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    WpStub::$actionsFired[$hook] = (WpStub::$actionsFired[$hook] ?? 0) + 1;
    $callbacks = WpStub::$filters[$hook] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $entries) {
        foreach ($entries as [$callback, $count]) {
            $callback(...array_slice($args, 0, $count));
        }
    }
}

function did_action(string $hook): int
{
    return WpStub::$actionsFired[$hook] ?? 0;
}

function _doing_it_wrong(string $function, string $message, string $version): void
{
    WpStub::$doingItWrong[] = [$function, $message];
}

// Options and transients.

function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WpStub::$options) ? WpStub::$options[$name] : $default;
}

function update_option(string $name, mixed $value, bool|string|null $autoload = null): bool
{
    WpStub::$options[$name] = $value;
    return true;
}

function delete_option(string $name): bool
{
    unset(WpStub::$options[$name]);
    return true;
}

function get_transient(string $name): mixed
{
    return WpStub::$transients[$name] ?? false;
}

function set_transient(string $name, mixed $value, int $ttl = 0): bool
{
    WpStub::$transients[$name] = $value;
    return true;
}

function delete_transient(string $name): bool
{
    unset(WpStub::$transients[$name]);
    return true;
}

// Text.

function remove_accents(string $text): string
{
    $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
    return (string) preg_replace('/\p{Mn}+/u', '', is_string($decomposed) ? $decomposed : $text);
}

function wp_kses_post(string $html): string
{
    return (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
}

function wp_strip_all_tags(string $text): string
{
    $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $text);
    return trim(strip_tags($text));
}

function sanitize_text_field(string $text): string
{
    return trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
}

function sanitize_key(string $key): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_textarea(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function esc_url_raw(string $url): string
{
    return $url;
}

function wp_http_validate_url(string $url): string|false
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : false;
}

function wp_unslash(mixed $value): mixed
{
    return $value;
}

// Posts, terms and users.

function get_term(int $id, string $taxonomy = ''): WP_Term|WP_Error|null
{
    $term = WpStub::$terms[$id] ?? null;
    if ($term === null || ($taxonomy !== '' && $term->taxonomy !== $taxonomy)) {
        return null;
    }
    return $term;
}

/**
 * Like WordPress: an array for a taxonomy lookup, an id, or null.
 */
function term_exists(string $name, string $taxonomy = ''): mixed
{
    foreach (WpStub::$terms as $term) {
        if (strcasecmp($term->name, $name) === 0 && ($taxonomy === '' || $term->taxonomy === $taxonomy)) {
            return ['term_id' => $term->term_id, 'term_taxonomy_id' => $term->term_id];
        }
    }
    return null;
}

/**
 * @return array{term_id: int, term_taxonomy_id: int}|WP_Error
 */
function wp_insert_term(string $name, string $taxonomy): array|WP_Error
{
    $id = WpStub::$nextTerm++;
    WpStub::addTerm($id, $name, $taxonomy);
    return ['term_id' => $id, 'term_taxonomy_id' => $id];
}

/**
 * @param list<int> $terms
 * @return list<int>|WP_Error|false
 */
function wp_set_post_terms(int $postId, array $terms, string $taxonomy, bool $append = false): array|WP_Error|false
{
    do_action('set_object_terms', $postId, $terms, $taxonomy, $append);
    $current = $append ? (WpStub::$postTerms[$postId][$taxonomy] ?? []) : [];
    WpStub::$postTerms[$postId][$taxonomy] = array_values(array_unique(array_merge($current, $terms)));
    return WpStub::$postTerms[$postId][$taxonomy];
}

/**
 * @param array<string, mixed> $args
 * @return list<int>|WP_Error
 */
function wp_get_post_terms(int $postId, string $taxonomy, array $args = []): array|WP_Error
{
    return WpStub::$postTerms[$postId][$taxonomy] ?? [];
}

/**
 * @param array<string, mixed> $args
 * @return list<WP_Term>
 */
function get_categories(array $args = []): array
{
    return array_values(array_filter(WpStub::$terms, static fn (WP_Term $t): bool => $t->taxonomy === 'category'));
}

function get_userdata(int $id): WP_User|false
{
    return isset(WpStub::$users[$id]) ? new WP_User($id, WpStub::$users[$id]['name']) : false;
}

function user_can(int $userId, string $cap): bool
{
    return in_array($cap, WpStub::$users[$userId]['caps'] ?? [], true);
}

function current_user_can(string $cap): bool
{
    return true;
}

function get_current_user_id(): int
{
    return 1;
}

/**
 * @param array<string, mixed> $postarr
 */
function wp_insert_post(array $postarr, bool $wpError = false): int|WP_Error
{
    $id = WpStub::$nextPost++;
    WpStub::$posts[$id] = $postarr;
    $cats = $postarr['post_category'] ?? [];
    if (is_array($cats) && $cats !== []) {
        WpStub::$postTerms[$id]['category'] = array_values(array_map('intval', $cats));
    }
    return $id;
}

/**
 * @param array<string, mixed> $postarr
 */
function wp_update_post(array $postarr, bool $wpError = false): int|WP_Error
{
    $id = (int) ($postarr['ID'] ?? 0);
    WpStub::$posts[$id] = array_merge(WpStub::$posts[$id] ?? [], $postarr);
    return $id;
}

function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
{
    return WpStub::$meta[$postId][$key] ?? ($single ? '' : []);
}

function update_post_meta(int $postId, string $key, mixed $value): bool
{
    WpStub::$meta[$postId][$key] = $value;
    return true;
}

function delete_post_meta_by_key(string $key): bool
{
    foreach (WpStub::$meta as $postId => $meta) {
        unset(WpStub::$meta[$postId][$key]);
    }
    return true;
}

/**
 * @param array<string, mixed> $args
 * @return list<int>
 */
function get_posts(array $args = []): array
{
    $out = [];
    foreach (WpStub::$meta as $postId => $meta) {
        if (isset($args['meta_key']) && ($meta[$args['meta_key']] ?? null) === ($args['meta_value'] ?? null)) {
            $out[] = $postId;
        }
    }
    return $out;
}

// Feeds and cron.

function fetch_feed(string $url): SimplePie|WP_Error
{
    $feed = WpStub::$feeds[$url] ?? null;
    if ($feed instanceof SimplePie || $feed instanceof WP_Error) {
        return $feed;
    }
    return new WP_Error('http', 'No stub feed for ' . $url);
}

/**
 * @param array<mixed> $args
 */
function wp_next_scheduled(string $hook, array $args = []): int|false
{
    $key = $hook . json_encode($args);
    return isset(WpStub::$cron[$key]) ? WpStub::$cron[$key][0] : false;
}

/**
 * @param array<mixed> $args
 */
function wp_schedule_event(int $time, string $recurrence, string $hook, array $args = []): bool
{
    WpStub::$cron[$hook . json_encode($args)] = [$time, $recurrence, $args];
    return true;
}

/**
 * @param array<mixed> $args
 */
function wp_unschedule_event(int $time, string $hook, array $args = []): bool
{
    unset(WpStub::$cron[$hook . json_encode($args)]);
    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    $n = 0;
    foreach (array_keys(WpStub::$cron) as $key) {
        if (str_starts_with($key, $hook)) {
            unset(WpStub::$cron[$key]);
            $n++;
        }
    }
    return $n;
}

// Admin helpers used by the admin page.

function wp_doing_cron(): bool
{
    return false;
}

function is_admin(): bool
{
    return false;
}

function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . $path;
}

/**
 * @param array<string, mixed> $args
 */
function add_query_arg(array $args, string $url): string
{
    return $url . '?' . http_build_query($args);
}

function wp_verify_nonce(string $nonce, string $action = ''): int|false
{
    return $nonce === 'valid' ? 1 : false;
}

function check_admin_referer(string $action = '', string $name = '_wpnonce'): int|false
{
    return 1;
}

function wp_nonce_field(string $action = '', string $name = '_wpnonce'): string
{
    echo '<input type="hidden" name="' . $name . '" value="valid">';
    return '';
}

function wp_die(string $message = '', int|string $title = '', mixed $args = []): never
{
    throw new \RuntimeException($message);
}

function wp_safe_redirect(string $location): bool
{
    return true;
}

function nocache_headers(): void
{
}

function add_management_page(string $pageTitle, string $menuTitle, string $capability, string $slug, callable $callback): string
{
    return $slug;
}

function wp_add_privacy_policy_content(string $name, string $content): void
{
}

/**
 * @param array<string, mixed> $args
 */
function wp_dropdown_users(array $args = []): string
{
    return '<select></select>';
}

function is_multisite(): bool
{
    return false;
}

function register_activation_hook(string $file, callable $callback): void
{
}

function register_deactivation_hook(string $file, callable $callback): void
{
}

/**
 * @param array<string, mixed> $args
 * @return list<int>
 */
function get_sites(array $args = []): array
{
    return [1];
}

function switch_to_blog(int $id): bool
{
    return true;
}

function restore_current_blog(): bool
{
    return true;
}

function __return_true(): bool
{
    return true;
}

function __return_false(): bool
{
    return false;
}
