<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * Holds the registered importer adapters.
 */
final class Registry
{
    /** @var array<string, Adapter> */
    private array $adapters = [];

    /**
     * Register an adapter. A bad or duplicate id is rejected with _doing_it_wrong().
     */
    public function add(Adapter $adapter): bool
    {
        $id = $adapter->id();
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $id) !== 1) {
            _doing_it_wrong(__METHOD__, esc_html(sprintf('Adapter id "%s" must be 1 to 40 lower-case characters.', $id)), '0.1.0');
            return false;
        }
        if (isset($this->adapters[$id])) {
            _doing_it_wrong(__METHOD__, esc_html(sprintf('An adapter with id "%s" is already registered.', $id)), '0.1.0');
            return false;
        }
        $this->adapters[$id] = $adapter;
        return true;
    }

    public function get(string $id): ?Adapter
    {
        return $this->adapters[$id] ?? null;
    }

    /**
     * @return array<string, Adapter>
     */
    public function all(): array
    {
        return $this->adapters;
    }

    /**
     * Boot every adapter. One that throws is logged and skipped.
     */
    public function boot(): void
    {
        foreach ($this->adapters as $id => $adapter) {
            try {
                $adapter->boot();
            } catch (\Throwable $e) {
                Logger::log(sprintf('error adapter=%s boot: %s', $id, $e->getMessage()));
            }
        }
    }

    public function defaultAuthor(string $importerId, string $sourceKey): ?int
    {
        $adapter = $this->get($importerId);
        if ($adapter === null) {
            return null;
        }
        try {
            return $adapter->defaultAuthor($sourceKey);
        } catch (\Throwable $e) {
            Logger::log(sprintf('error adapter=%s defaultAuthor: %s', $importerId, $e->getMessage()));
            return null;
        }
    }

    /**
     * @return array<string, string> Source key => "label (importer)".
     */
    public function sources(): array
    {
        $out = [];
        foreach ($this->adapters as $id => $adapter) {
            try {
                foreach ($adapter->sources() as $key => $label) {
                    $out[(string) $key] = $label . ' (' . $id . ')';
                }
            } catch (\Throwable $e) {
                Logger::log(sprintf('error adapter=%s sources: %s', $id, $e->getMessage()));
            }
        }
        return $out;
    }
}
