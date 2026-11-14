<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Adapter;

/**
 * A comparable key for an item URL: scheme and host lower-cased, a leading
 * www. removed, the fragment removed and utm_ parameters dropped.
 */
final class UrlKey
{
    public static function make(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['host'])) {
            return trim($url);
        }
        $scheme = strtolower($parts['scheme'] ?? 'http');
        $host = strtolower($parts['host']);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        $key = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $key .= ':' . $parts['port'];
        }
        $key .= $parts['path'] ?? '/';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $kept = [];
            foreach (explode('&', $parts['query']) as $pair) {
                $name = strtolower(urldecode(explode('=', $pair, 2)[0]));
                if ($pair !== '' && !str_starts_with($name, 'utm_')) {
                    $kept[] = $pair;
                }
            }
            if ($kept !== []) {
                $key .= '?' . implode('&', $kept);
            }
        }
        return $key;
    }
}
