<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\BattleEntryActorResolver;
use Ichiloto\Editor\Database\BattleEntryRuleContract;
use Ichiloto\Editor\ProjectActor;

/**
 * Returns the accepted engine source root, or null to skip.
 */
function acceptedEngineRoot(): ?string
{
    $root = getenv('ICHILOTO_ENGINE_SRC');

    return is_string($root) && is_dir($root . '/src/Battle/Entry') ? $root : null;
}

/**
 * Hydrates a rule file through the accepted engine head in a subprocess.
 *
 * @return array<string, mixed> The runner's JSON result.
 */
function hydrateThroughAcceptedEngine(string $engineRoot, string $ruleFile, string $actorsDirectory = ''): array
{
    $command = sprintf(
        '%s %s %s %s %s 2>/dev/null',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(dirname(__DIR__) . '/Support/battle-entry-parity-runner.php'),
        escapeshellarg($engineRoot),
        escapeshellarg($ruleFile),
        escapeshellarg($actorsDirectory),
    );
    $output = (string) shell_exec($command);
    $decoded = json_decode($output, true);

    if (! is_array($decoded)) {
        throw new RuntimeException(sprintf('The parity runner produced no result: %s', $output));
    }

    return $decoded;
}

/**
 * Builds a throwaway directory of engine-shaped actor definition files.
 *
 * @param array<string, array{string, string|null}> $actors File stem => [name, id].
 */
function parityActorsDirectory(array $actors): string
{
    $directory = sys_get_temp_dir() . '/ichiloto-parity-actors-' . bin2hex(random_bytes(4));
    mkdir($directory, 0755, true);

    foreach ($actors as $fileStem => [$name, $id]) {
        $idLine = $id === null ? '' : sprintf("    'id' => %s,\n", var_export($id, true));
        file_put_contents(
            $directory . '/' . $fileStem . '.php',
            sprintf(
                "<?php\n\nreturn [\n  'data' => [\n%s    'name' => %s,\n  ]\n];\n",
                $idLine,
                var_export($name, true),
            ),
        );
    }

    return $directory;
}

/**
 * Builds the editor-side resolver over the same actor files.
 */
function parityResolver(string $actorsDirectory): BattleEntryActorResolver
{
    $actors = [];

    foreach (glob($actorsDirectory . '/*.php') ?: [] as $path) {
        $actors[] = ProjectActor::fromFile($path);
    }

    return BattleEntryActorResolver::fromActors($actors);
}

/**
 * The malformed-file corpus: every diagnostic branch the contract mirrors.
 *
 * @return array<string, string> Case name => rule file body.
 */
function parityRuleCorpus(): array
{
    $valid = static fn(string $id, string $extra = ''): string => sprintf(
        "['id' => '%s', %s'actors' => [['actor' => 'Rook', 'presence' => 'any']], 'effects' => [['type' => 'stat_stage', 'actor' => 'Rook', 'stat' => 'speed', 'delta' => 1]]]",
        $id,
        $extra,
    );

    return [
        'valid-minimal' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one')),
        'valid-bare-list' => sprintf("<?php\nreturn [%s];", $valid('one')),
        'valid-empty' => "<?php\nreturn ['rules' => []];",
        'valid-every-field' => "<?php\nreturn ['rules' => [['id' => 'full', 'priority' => -3, 'classification' => 'boss', 'actors' => [['actor' => 'Rook', 'presence' => 'active'], ['actor' => 'Vale', 'presence' => 'reserve']], 'conditions' => [['type' => 'switch', 'name' => 'gate', 'value' => false], ['type' => 'variable', 'name' => 'step', 'op' => '>=', 'value' => 2, 'negate' => true], ['type' => 'item', 'name' => 'Potion', 'quantity' => 2], ['type' => 'quest', 'name' => 'q', 'status' => 'active']], 'effects' => [['type' => 'stat_stage', 'actor' => 'Vale', 'stat' => 'grace', 'delta' => -4]], 'writes' => [['type' => 'switch', 'name' => 's', 'value' => true], ['type' => 'event', 'name' => 'e'], ['type' => 'variable', 'name' => 'v', 'op' => 'add', 'value' => 2]]]]];",
        'file-not-array' => "<?php\nreturn 'nonsense';",
        'rules-not-list' => "<?php\nreturn ['rules' => 'nonsense'];",
        'rule-not-array' => "<?php\nreturn ['rules' => ['nonsense']];",
        'missing-id' => "<?php\nreturn ['rules' => [['actors' => [], 'effects' => []]]];",
        'priority-not-int' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'priority' => 'high', ")),
        'classification-unsupported' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'classification' => 'epic', ")),
        'classification-not-string' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'classification' => 7, ")),
        'conditions-not-list' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'conditions' => 'nope', ")),
        'condition-not-array' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'conditions' => ['nope'], ")),
        'condition-bad-type' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'conditions' => [['type' => 'weather', 'name' => 'rain']], ")),
        'condition-empty-name' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'conditions' => [['type' => 'switch', 'name' => ' ']], ")),
        'condition-bad-negate' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'conditions' => [['type' => 'switch', 'name' => 'g', 'negate' => 'yes']], ")),
        'condition-bad-operator' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'conditions' => [['type' => 'variable', 'name' => 'v', 'op' => '~=']], ")),
        'condition-bad-quantity' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'conditions' => [['type' => 'item', 'name' => 'Potion', 'quantity' => 0]], ")),
        'actors-missing' => "<?php\nreturn ['rules' => [['id' => 'one', 'effects' => [['type' => 'stat_stage', 'actor' => 'Rook', 'stat' => 'speed', 'delta' => 1]]]]];",
        'actors-empty' => "<?php\nreturn ['rules' => [['id' => 'one', 'actors' => [], 'effects' => [['type' => 'stat_stage', 'actor' => 'Rook', 'stat' => 'speed', 'delta' => 1]]]]];",
        'actor-entry-not-array' => "<?php\nreturn ['rules' => [['id' => 'one', 'actors' => ['Rook'], 'effects' => [['type' => 'stat_stage', 'actor' => 'Rook', 'stat' => 'speed', 'delta' => 1]]]]];",
        'actor-empty-identity' => "<?php\nreturn ['rules' => [['id' => 'one', 'actors' => [['actor' => ' ', 'presence' => 'any']], 'effects' => [['type' => 'stat_stage', 'actor' => 'Rook', 'stat' => 'speed', 'delta' => 1]]]]];",
        'presence-not-string' => "<?php\nreturn ['rules' => [['id' => 'one', 'actors' => [['actor' => 'Rook', 'presence' => 4]], 'effects' => [['type' => 'stat_stage', 'actor' => 'Rook', 'stat' => 'speed', 'delta' => 1]]]]];",
        'presence-unsupported' => "<?php\nreturn ['rules' => [['id' => 'one', 'actors' => [['actor' => 'Rook', 'presence' => 'benched']], 'effects' => [['type' => 'stat_stage', 'actor' => 'Rook', 'stat' => 'speed', 'delta' => 1]]]]];",
        'effects-missing' => "<?php\nreturn ['rules' => [['id' => 'one', 'actors' => [['actor' => 'Rook', 'presence' => 'any']]]]];",
        'effects-empty' => "<?php\nreturn ['rules' => [['id' => 'one', 'actors' => [['actor' => 'Rook', 'presence' => 'any']], 'effects' => []]]];",
        'effect-not-array' => "<?php\nreturn ['rules' => [['id' => 'one', 'actors' => [['actor' => 'Rook', 'presence' => 'any']], 'effects' => ['boom']]]];",
        'effect-wrong-type' => "<?php\nreturn ['rules' => [['id' => 'one', 'actors' => [['actor' => 'Rook', 'presence' => 'any']], 'effects' => [['type' => 'heal', 'actor' => 'Rook']]]]];",
        'effect-missing-type' => "<?php\nreturn ['rules' => [['id' => 'one', 'actors' => [['actor' => 'Rook', 'presence' => 'any']], 'effects' => [['actor' => 'Rook', 'stat' => 'speed', 'delta' => 1]]]]];",
        'effect-unknown-stat' => "<?php\nreturn ['rules' => [['id' => 'one', 'actors' => [['actor' => 'Rook', 'presence' => 'any']], 'effects' => [['type' => 'stat_stage', 'actor' => 'Rook', 'stat' => 'luck', 'delta' => 1]]]]];",
        'effect-unstageable-stat' => "<?php\nreturn ['rules' => [['id' => 'one', 'actors' => [['actor' => 'Rook', 'presence' => 'any']], 'effects' => [['type' => 'stat_stage', 'actor' => 'Rook', 'stat' => 'maxHp', 'delta' => 1]]]]];",
        'effect-bad-delta' => "<?php\nreturn ['rules' => [['id' => 'one', 'actors' => [['actor' => 'Rook', 'presence' => 'any']], 'effects' => [['type' => 'stat_stage', 'actor' => 'Rook', 'stat' => 'speed', 'delta' => '1']]]]];",
        'writes-not-list' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'writes' => 'nope', ")),
        'write-not-array' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'writes' => ['nope'], ")),
        'write-bad-type' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'writes' => [['type' => 'weather', 'name' => 'rain']], ")),
        'write-empty-name' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'writes' => [['type' => 'switch', 'name' => '']], ")),
        'write-quest-transactional' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'writes' => [['type' => 'quest', 'name' => 'q']], ")),
        'write-switch-bad-value' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'writes' => [['type' => 'switch', 'name' => 's', 'value' => 'on']], ")),
        'write-variable-bad-op' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'writes' => [['type' => 'variable', 'name' => 'v', 'op' => 'mul']], ")),
        'write-variable-bad-add' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'writes' => [['type' => 'variable', 'name' => 'v', 'op' => 'add', 'value' => 'x']], ")),
        'write-variable-bad-set' => sprintf("<?php\nreturn ['rules' => [%s]];", $valid('one', "'writes' => [['type' => 'variable', 'name' => 'v', 'op' => 'set', 'value' => []]], ")),
        'duplicate-ids' => sprintf("<?php\nreturn ['rules' => [%s, %s]];", $valid('twin'), $valid('twin')),
    ];
}

it('matches the accepted engine verdict and first diagnostic for the whole corpus', function (): void {
    $engineRoot = acceptedEngineRoot();

    if ($engineRoot === null) {
        expect(true)->toBeTrue();

        return;
    }

    $directory = sys_get_temp_dir() . '/ichiloto-parity-' . bin2hex(random_bytes(4));
    mkdir($directory, 0755, true);

    try {
        foreach (parityRuleCorpus() as $case => $body) {
            $ruleFile = $directory . '/' . $case . '.php';
            file_put_contents($ruleFile, $body);

            $engine = hydrateThroughAcceptedEngine($engineRoot, $ruleFile);
            $problems = BattleEntryRuleContract::problems(require $ruleFile, $ruleFile);

            if (($engine['ok'] ?? false) === true) {
                expect($problems)->toBe([], sprintf(
                    '%s: the engine accepted what the editor refused: %s',
                    $case,
                    implode(' | ', $problems),
                ));

                continue;
            }

            expect($problems)->not->toBe([], sprintf('%s: the editor accepted what the engine refused: %s', $case, strval($engine['error'] ?? '')))
                ->and($problems[0])->toBe(strval($engine['error'] ?? ''), sprintf('%s: first diagnostic differs', $case));
        }
    } finally {
        removeDirectoryRecursively($directory);
    }
})->skip(fn (): bool => acceptedEngineRoot() === null, 'ICHILOTO_ENGINE_SRC does not point at the accepted engine head.');

it('resolves actor identities exactly as the accepted engine store does', function (): void {
    $engineRoot = acceptedEngineRoot();

    if ($engineRoot === null) {
        expect(true)->toBeTrue();

        return;
    }

    $directory = sys_get_temp_dir() . '/ichiloto-parity-' . bin2hex(random_bytes(4));
    mkdir($directory, 0755, true);

    try {
        $ruleFile = $directory . '/rules.php';

        // Resolution: id, display name, and file stem all reach the same
        // durable identity; an unknown reference is refused with the same
        // wording; a healthy store accepts all three reference kinds.
        file_put_contents($ruleFile, "<?php\nreturn ['rules' => [['id' => 'one', 'actors' => [['actor' => 'actor.rook', 'presence' => 'any'], ['actor' => 'Rook', 'presence' => 'active'], ['actor' => 'RookFile', 'presence' => 'reserve']], 'effects' => [['type' => 'stat_stage', 'actor' => 'ROOK', 'stat' => 'speed', 'delta' => 1]]]]];");
        $healthy = parityActorsDirectory(['RookFile' => ['Rook', 'actor.rook'], 'Vale' => ['Vale', null]]);
        $engine = hydrateThroughAcceptedEngine($engineRoot, $ruleFile, $healthy);
        $resolver = parityResolver($healthy);

        expect($engine['ok'] ?? false)->toBeTrue()
            ->and($resolver->problems())->toBe([])
            ->and(BattleEntryRuleContract::problems(require $ruleFile, $ruleFile, $resolver))->toBe([])
            ->and($resolver->canonicalId('ROOK'))->toBe('actor.rook')
            ->and($resolver->canonicalId('RookFile'))->toBe('actor.rook');

        // Unknown actor: same verdict, same wording.
        file_put_contents($ruleFile, "<?php\nreturn ['rules' => [['id' => 'one', 'actors' => [['actor' => 'Nobody', 'presence' => 'any']], 'effects' => [['type' => 'stat_stage', 'actor' => 'Rook', 'stat' => 'speed', 'delta' => 1]]]]];");
        $engine = hydrateThroughAcceptedEngine($engineRoot, $ruleFile, $healthy);
        $problems = BattleEntryRuleContract::problems(require $ruleFile, $ruleFile, $resolver);

        expect($engine['ok'] ?? true)->toBeFalse()
            ->and($problems[0] ?? null)->toBe(strval($engine['error'] ?? ''));

        // A contested reference refuses the store itself, with the same
        // wording the resolver reports.
        $contested = parityActorsDirectory(['Kael' => ['Kael', null], 'Impostor' => ['Kael', 'actor.impostor']]);
        $engine = hydrateThroughAcceptedEngine($engineRoot, $ruleFile, $contested);
        $resolver = parityResolver($contested);

        expect($engine['phase'] ?? null)->toBe('store')
            ->and($resolver->problems())->toContain(strval($engine['error'] ?? ''));

        // Two definitions sharing an identity refuse the store the same way.
        $duplicated = parityActorsDirectory(['EchoOne' => ['Echo', 'actor.echo'], 'EchoTwo' => ['Echo Again', 'actor.echo']]);
        $engine = hydrateThroughAcceptedEngine($engineRoot, $ruleFile, $duplicated);
        $resolver = parityResolver($duplicated);

        expect($engine['phase'] ?? null)->toBe('store')
            ->and($resolver->problems())->toContain(strval($engine['error'] ?? ''));
    } finally {
        removeDirectoryRecursively($directory);
    }
})->skip(fn (): bool => acceptedEngineRoot() === null, 'ICHILOTO_ENGINE_SRC does not point at the accepted engine head.');

it('authors a disposable project the accepted engine hydrates in the same order', function (): void {
    $engineRoot = acceptedEngineRoot();

    if ($engineRoot === null) {
        expect(true)->toBeTrue();

        return;
    }

    $root = makeTemporaryProject();

    try {
        $database = loadRecordDatabase($root, 'battle_entry_rules');

        foreach ([
            ['third-by-priority', '20', 'speed'],
            ['first-declared-of-ties', '', 'grace'],
            ['second-declared-of-ties', '', 'evasion'],
        ] as [$id, $priority, $stat]) {
            $index = $database->addRecord();
            $database->setField($index, 'id', $id);

            if ($priority !== '') {
                $database->setField($index, 'priority', $priority);
            }

            $database->setField($index, 'actors', 'Kaelion:any');
            $database->setField($index, 'effect0Actor', 'Kaelion');
            $database->setField($index, 'effect0Stat', $stat);
        }

        $database->save();

        $engine = hydrateThroughAcceptedEngine($engineRoot, battleEntryRulesPath($root));

        // The engine runs what the editor authored, in the exact order the
        // editor's cue presents.
        expect($engine['ok'] ?? false)->toBeTrue()
            ->and($engine['order'] ?? null)->toBe([
                'first-declared-of-ties',
                'second-declared-of-ties',
                'third-by-priority',
            ]);

        // A reorder is a real edit: moving the tied pair, saving, and
        // hydrating again swaps their execution order at the runtime too.
        $database = loadRecordDatabase($root, 'battle_entry_rules');
        $tied = array_search('first-declared-of-ties', $database->getEntryLabels(), true);

        expect($database->moveRecord((int) $tied, (int) $tied + 1))->toBeTrue();

        $database->save();
        $engine = hydrateThroughAcceptedEngine($engineRoot, battleEntryRulesPath($root));

        expect($engine['ok'] ?? false)->toBeTrue()
            ->and($engine['order'] ?? null)->toBe([
                'second-declared-of-ties',
                'first-declared-of-ties',
                'third-by-priority',
            ]);
    } finally {
        removeDirectoryRecursively($root);
    }
})->skip(fn (): bool => acceptedEngineRoot() === null, 'ICHILOTO_ENGINE_SRC does not point at the accepted engine head.');
