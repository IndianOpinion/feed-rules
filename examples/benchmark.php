<?php

/**
 * Measures one item against 50 live rules holding 5,000 keywords in total,
 * outside WordPress, using the test stubs.
 *
 * Run from the repository root after `composer install`:
 *     php examples/benchmark.php
 */

declare(strict_types=1);

require __DIR__ . '/../tests/stubs/wordpress.php';
require __DIR__ . '/../vendor/autoload.php';

use IndianOpinion\FeedRules\Counters;
use IndianOpinion\FeedRules\Engine;
use IndianOpinion\FeedRules\Item;
use IndianOpinion\FeedRules\Rule;
use IndianOpinion\FeedRules\RuleRepository;
use IndianOpinion\FeedRules\RuleSet;
use IndianOpinion\FeedRules\Settings;

$rules = [];
for ($r = 0; $r < 50; $r++) {
    $keywords = [];
    for ($k = 0; $k < 100; $k++) {
        $keywords[] = 'term' . $r . 'x' . $k;
    }
    $rules[] = Rule::fromArray([
        'id' => 'r' . $r,
        'state' => 'live',
        'action' => $r % 2 === 0 ? 'exclude' : 'categorise',
        'categories' => [1],
        'conditions' => [['type' => 'keywords', 'keywords' => $keywords]],
    ]);
}
$set = new RuleSet($rules);
$engine = new Engine(new RuleRepository(), new Settings(), new Counters());
$item = Item::fromArray([
    'url' => 'https://example.com/story',
    'title' => 'An ordinary headline about the budget and the weather',
    'body' => str_repeat('<p>Plain words in an ordinary paragraph of news copy. </p>', 40),
]);

$engine->evaluate($item, 'bench', $set, true);
$runs = 200;
$start = hrtime(true);
for ($i = 0; $i < $runs; $i++) {
    $engine->evaluate($item, 'bench', $set, true);
}
$ms = (hrtime(true) - $start) / 1e6 / $runs;
printf("One item, 50 rules, 5,000 keywords: %.2f ms per item (PHP %s)\n", $ms, PHP_VERSION);
