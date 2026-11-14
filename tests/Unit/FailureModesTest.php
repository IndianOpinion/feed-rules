<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Tests\Unit;

use IndianOpinion\FeedRules\Adapter;
use IndianOpinion\FeedRules\Engine;
use IndianOpinion\FeedRules\Exception\FeedRulesException;
use IndianOpinion\FeedRules\Exception\InvalidRuleException;
use IndianOpinion\FeedRules\Exception\RulesConflictException;
use IndianOpinion\FeedRules\ImportExport;
use IndianOpinion\FeedRules\Registry;
use IndianOpinion\FeedRules\RuleRepository;
use IndianOpinion\FeedRules\RuleSet;
use IndianOpinion\FeedRules\Tests\TestCase;

final class FailureModesTest extends TestCase
{
    /** Case 54. */
    public function testCorruptRuleSetKeepsEverythingAndLogsOncePerHour(): void
    {
        \WpStub::$options[RuleRepository::OPTION] = ['schema' => 99, 'rules' => []];
        self::assertIsArray($this->engine->filter(['url' => 'https://example.com/a', 'title' => 'x'], 'imp'));
        self::assertTrue($this->repository->isCorrupt());
        $this->rebuild();
        $this->engine->filter(['url' => 'https://example.com/b', 'title' => 'x'], 'imp');
        self::assertCount(1, $this->log);
        self::assertStringContainsString('rule set unreadable', $this->log[0]);

        \WpStub::$options[RuleRepository::OPTION] = 'garbage';
        $this->rebuild();
        self::assertSame([], $this->repository->load()->rules);
        self::assertTrue($this->repository->isCorrupt());
    }

    /** Case 55. */
    public function testThrowingCodeDuringApplyIsCaughtAndLogged(): void
    {
        \WpStub::addTerm(5, 'Five', 'category');
        $this->saveRules(self::rule('categorise', [self::kw('x')], ['categories' => [5]]));
        $item = $this->engine->filter(['url' => 'https://example.com/a', 'title' => 'x'], 'my-importer');
        self::assertIsArray($item);
        add_action('set_object_terms', static function (): void {
            throw new \RuntimeException('adapter hook failed');
        });
        $this->engine->applyAction(70, $item);
        self::assertArrayNotHasKey(70, \WpStub::$meta);
        self::assertArrayNotHasKey(70, \WpStub::$postTerms);
        self::assertStringContainsString('importer=my-importer', implode("\n", $this->log));
    }

    public function testThrowingDecisionFilterFailsOpen(): void
    {
        $this->saveRules(self::rule('exclude', [self::kw('x')]));
        add_filter('feed_rules_decision', static function (): void {
            throw new \RuntimeException('boom');
        });
        $input = ['url' => 'https://example.com/a', 'title' => 'x'];
        self::assertSame($input, $this->engine->filter($input, 'imp'));
        self::assertStringContainsString('importer=imp', $this->log[0]);
    }

    public function testRegistryRejectsDuplicatesAndContainsThrowingAdapters(): void
    {
        $make = static fn (string $id, bool $throws = false): Adapter => new class ($id, $throws) implements Adapter {
            public function __construct(private string $id, private bool $throws)
            {
            }
            public function id(): string
            {
                return $this->id;
            }
            public function label(): string
            {
                return 'x';
            }
            public function boot(): void
            {
                if ($this->throws) {
                    throw new \RuntimeException('no boot');
                }
            }
            public function sources(): array
            {
                return ['k' => 'Key'];
            }
            public function defaultAuthor(string $sourceKey): ?int
            {
                if ($this->throws) {
                    throw new \RuntimeException('no author');
                }
                return null;
            }
            public function supportsSampling(): bool
            {
                return false;
            }
            public function sample(int $limit): array
            {
                return [];
            }
        };
        $registry = new Registry();
        self::assertTrue($registry->add($make('one')));
        self::assertFalse($registry->add($make('one')));
        self::assertFalse($registry->add($make('Bad Id')));
        self::assertCount(2, \WpStub::$doingItWrong);
        self::assertTrue($registry->add($make('two', true)));
        $registry->boot();
        self::assertNull($registry->defaultAuthor('two', 'k'));
        self::assertSame(['k' => 'Key (two)'], $registry->sources());
        self::assertCount(2, $this->log);
    }

    /** Case 57. */
    public function testConcurrentSaveIsRejected(): void
    {
        $this->saveRules(self::rule('exclude', [self::kw('a')], ['id' => 'ra']));
        $loaded = $this->repository->load();
        $otherTab = new RuleRepository();
        $otherTab->save($otherTab->load()->withRule(self::rule('exclude', [self::kw('b')], ['id' => 'rb'])), $loaded->editVersion);
        $this->repository->forget();
        try {
            $this->repository->save($loaded->withRule(self::rule('exclude', [self::kw('c')], ['id' => 'rc'])), $loaded->editVersion);
            self::fail('expected a conflict');
        } catch (RulesConflictException $e) {
            self::assertStringContainsString('rules changed since you opened this page', $e->getMessage());
        }
        $this->repository->forget();
        self::assertNotNull($this->repository->load()->find('rb'));
        self::assertNull($this->repository->load()->find('rc'));
    }

    /** Case 58. */
    public function testImportIsAllOrNothing(): void
    {
        $this->saveRules(self::rule('exclude', [self::kw('keep me')], ['id' => 'rold']));
        $io = new ImportExport($this->repository);
        $valid = new RuleSet([self::rule('exclude', [self::kw('new')], ['id' => 'rnew'])]);
        $invalid = new RuleSet([
            self::rule('exclude', [self::kw('fine')], ['id' => 'rfine']),
            self::rule('exclude', [['type' => 'regex', 'pattern' => '/(/']], ['id' => 'rbad']),
        ]);
        try {
            $io->import((string) json_encode($invalid->toArray()));
            self::fail('expected InvalidRuleException');
        } catch (InvalidRuleException $e) {
            self::assertStringContainsString('nothing was imported', $e->getMessage());
        }
        self::assertNotNull($this->repository->load()->find('rold'));

        $dry = $io->import((string) json_encode($valid->toArray()), true);
        self::assertCount(1, $dry->rules);
        self::assertNotNull($this->repository->load()->find('rold'));

        $io->import((string) json_encode($valid->toArray()));
        $this->repository->forget();
        self::assertNull($this->repository->load()->find('rold'));
        self::assertNotNull($this->repository->load()->find('rnew'));

        $exported = json_decode($io->export(), true);
        self::assertIsArray($exported);
        self::assertSame('rnew', $exported['rules'][0]['id']);

        $this->expectException(FeedRulesException::class);
        $io->import('{not json');
    }

    public function testApplyIgnoresItemsWithoutDecision(): void
    {
        $this->engine->applyAction(80, ['url' => 'https://example.com']);
        $this->engine->applyAction('nope', [Engine::DECISION_KEY => ['kept' => true]]);
        self::assertSame([], \WpStub::$meta);
    }
}
