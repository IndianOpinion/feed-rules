<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

use IndianOpinion\FeedRules\Exception\StoreUnavailableException;

/**
 * Runs a rule set against recorded items and pasted samples. Writes nothing.
 */
final class Tester
{
    public const PASTED_IMPORTER = 'pasted';

    public function __construct(private Engine $engine, private ?RecentStore $store)
    {
    }

    /**
     * @param list<Item> $samples Pasted sample items, run alongside the recorded ones.
     */
    public function run(
        RuleSet $rules,
        int $limit = 100,
        ?string $importerId = null,
        ?string $sourceKey = null,
        array $samples = []
    ): TestReport {
        $errors = $rules->validate();
        if ($errors !== []) {
            return new TestReport([], $errors);
        }
        $rows = [];
        foreach ($samples as $sample) {
            $importer = $sample->importerId() !== '' ? $sample->importerId() : self::PASTED_IMPORTER;
            $decision = $this->engine->evaluate($sample, $importer, $rules, true);
            $rows[] = new TestRow(
                $sample->title(),
                $sample->sourceName(),
                $importer,
                $sample->url(),
                null,
                null,
                $decision,
                false,
                false,
                true,
            );
        }
        $storeError = '';
        $recorded = [];
        if ($this->store === null) {
            $storeError = 'The recent-items store is unavailable.';
        } else {
            try {
                $recorded = $this->store->latest($limit, $importerId, $sourceKey);
            } catch (StoreUnavailableException $e) {
                $storeError = $e->getMessage();
            }
        }
        foreach ($recorded as $entry) {
            $decision = $this->engine->evaluate($entry->toItem(), $entry->importerId, $rules, true);
            $old = $entry->decision;
            $rows[] = new TestRow(
                $entry->title(),
                $entry->sourceName() !== '' ? $entry->sourceName() : $entry->sourceKey,
                $entry->importerId,
                $entry->url,
                $entry->seenAt,
                $old->isKept() ? 'keep' : 'drop',
                $decision,
                $decision->isKept() && $old->isKept() && (
                    $old->categories() !== $decision->categories()
                    || $old->tags() !== $decision->tags()
                    || $old->authorId() !== $decision->authorId()
                ),
                $entry->sampled,
                false,
            );
        }
        return new TestReport($rows, [], $storeError);
    }

    /**
     * Build a pasted sample item. A missing URL gets a placeholder on the reserved .invalid domain.
     */
    public static function pastedItem(string $title, string $body, string $url = '', int $index = 1): Item
    {
        $url = trim($url);
        if ($url === '' || !Item::isAbsoluteHttpUrl($url)) {
            $url = 'https://sample.invalid/pasted-' . $index;
        }
        return Item::fromArray([
            'url' => $url,
            'title' => $title,
            'body' => $body,
            'source_name' => 'Pasted sample',
            'importer' => self::PASTED_IMPORTER,
        ]);
    }
}
