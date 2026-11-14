<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * Host and path prefix matching on segment and label boundaries.
 */
final class UrlPrefix
{
    private const ITEM_HOST_PREFIXES = ['www.', 'm.', 'amp.', 'mobile.'];

    /**
     * Normalise a rule line: drop the scheme and a leading www., lower-case the host,
     * drop a trailing slash, query and fragment.
     */
    public static function normaliseRule(string $line): string
    {
        $line = trim($line);
        $line = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $line);
        $line = (string) preg_replace('/[?#].*$/s', '', $line);
        $slash = strpos($line, '/');
        $host = $slash === false ? $line : substr($line, 0, $slash);
        $path = $slash === false ? '' : substr($line, $slash);
        $host = strtolower(rtrim($host, '.'));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        $path = rtrim(strtolower($path), '/');
        return $host . $path;
    }

    public static function isValidRule(string $rule): bool
    {
        [$host] = self::splitRule($rule);
        return $host !== '' && preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/', $host) === 1;
    }

    /**
     * @return array{0: string, 1: string} Host and path prefix (path may be empty).
     */
    public static function splitRule(string $rule): array
    {
        $slash = strpos($rule, '/');
        if ($slash === false) {
            return [$rule, ''];
        }
        return [substr($rule, 0, $slash), substr($rule, $slash)];
    }

    /**
     * Does an item URL fall under a normalised rule?
     */
    public static function matches(string $rule, string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return false;
        }
        $itemHost = strtolower(rtrim($parts['host'], '.'));
        foreach (self::ITEM_HOST_PREFIXES as $prefix) {
            if (str_starts_with($itemHost, $prefix)) {
                $itemHost = substr($itemHost, strlen($prefix));
                break;
            }
        }
        [$ruleHost, $rulePath] = self::splitRule($rule);
        if ($itemHost !== $ruleHost && !str_ends_with($itemHost, '.' . $ruleHost)) {
            return false;
        }
        if ($rulePath === '') {
            return true;
        }
        $itemPath = strtolower($parts['path'] ?? '');
        return $itemPath === $rulePath || str_starts_with($itemPath, $rulePath . '/');
    }
}
