<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * The result of a test run: rows, summary counts, or why it did not run.
 */
final class TestReport
{
    public const COLUMNS = [
        'Title', 'Source', 'Importer', 'URL', 'Seen', 'Recorded', 'This run', 'Categories', 'Tags', 'Author',
        'Matches', 'Body cut',
    ];

    /**
     * @param list<TestRow> $rows
     * @param list<ValidationError> $validationErrors
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly array $validationErrors = [],
        public readonly string $storeError = '',
    ) {
    }

    public function isValid(): bool
    {
        return $this->validationErrors === [];
    }

    /**
     * @return array{items: int, kept: int, dropped: int, newly_dropped: int, newly_kept: int, mapping_changed: int}
     */
    public function summary(): array
    {
        $out = ['items' => 0, 'kept' => 0, 'dropped' => 0, 'newly_dropped' => 0, 'newly_kept' => 0, 'mapping_changed' => 0];
        foreach ($this->rows as $row) {
            $out['items']++;
            $out[$row->decision->isKept() ? 'kept' : 'dropped']++;
            $out['newly_dropped'] += $row->newlyDropped() ? 1 : 0;
            $out['newly_kept'] += $row->newlyKept() ? 1 : 0;
            $out['mapping_changed'] += $row->mappingChanged ? 1 : 0;
        }
        return $out;
    }

    /**
     * The table as plain strings, the same for the screen, the CSV file and WP-CLI.
     *
     * @return list<array<string, string>>
     */
    public function table(): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            $d = $row->decision;
            $matches = [];
            foreach ($d->reasons() as $reason) {
                $text = $reason->summary();
                if ($reason->matched !== '' && ($reason->before !== '' || $reason->after !== '')) {
                    $text .= ' in "...' . $reason->before . '[' . $reason->matched . ']' . $reason->after . '..."';
                }
                $matches[] = $text;
            }
            foreach ($d->errors() as $error) {
                $matches[] = 'error: ' . $error->ruleName . ': ' . $error->message;
            }
            $recorded = $row->pasted ? 'pasted' : (string) $row->recordedVerdict;
            if ($row->sampled) {
                $recorded .= ' (sampled)';
            }
            $out[] = array_combine(self::COLUMNS, [
                $row->title,
                $row->source,
                $row->importerId,
                $row->url,
                $row->seenAt === null ? '' : gmdate('Y-m-d H:i', $row->seenAt),
                $recorded,
                $row->verdict(),
                implode(', ', array_map([self::class, 'termName'], $d->categories(), array_fill(0, count($d->categories()), 'category'))),
                implode(', ', array_map(
                    static fn (int|string $t): string => is_int($t) ? self::termName($t, 'post_tag') : $t,
                    $d->tags()
                )),
                $d->authorId() === null ? '' : self::userName($d->authorId()),
                implode(' | ', $matches),
                $d->bodyTruncated() ? 'yes' : '',
            ]);
        }
        return $out;
    }

    /**
     * CSV text with a header row, the same rows and columns as table().
     */
    public function csv(): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        fputcsv($handle, self::COLUMNS, ',', '"', '\\');
        foreach ($this->table() as $row) {
            fputcsv($handle, array_map([self::class, 'csvCell'], array_values($row)), ',', '"', '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /**
     * Stop spreadsheet formula injection.
     */
    private static function csvCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'" . $value : $value;
    }

    private static function termName(int $id, string $taxonomy): string
    {
        if (function_exists('get_term')) {
            $term = get_term($id, $taxonomy);
            if ($term instanceof \WP_Term) {
                return $term->name;
            }
        }
        return '#' . $id;
    }

    private static function userName(int $id): string
    {
        if (function_exists('get_userdata')) {
            $user = get_userdata($id);
            if ($user instanceof \WP_User) {
                return $user->display_name;
            }
        }
        return '#' . $id;
    }
}
