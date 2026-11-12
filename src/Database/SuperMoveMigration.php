<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\History\SourceSetRequired;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Engine\Battle\CrisisAbilityCatalog;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Moves a project from the earlier Super Move shape to the current one in one
 * reversible change: `crisis-abilities.php` becomes `super-moves.php` byte for
 * byte, and every actor's `crisisAbility` key becomes `superMove` where it
 * stands (an actor already naming the same Super Move under both keys keeps
 * only `superMove`). Ids, names, labels, activation conditions and variants
 * stay exactly as they are; saves are untouched.
 *
 * Nothing is written when the project holds both catalogues, when the game
 * cannot read the catalogue or an actor's reference, or when an actor's
 * source cannot take the renamed key as authored. The plan is shared by the
 * Terminal editor and the editor session, which both ask before writing and
 * record it as one undo step.
 *
 * @package Ichiloto\Editor\Database
 */
final class SuperMoveMigration
{
    /** The undo step's name, and what the author is asked to confirm. */
    public const string LABEL = 'Migrate to Super Moves';
    /** What the plan does, for refusals. */
    private const string SUBJECT = 'Super Move migration';

    private function __construct()
    {
    }

    /**
     * Plans the migration and says what writing it would change, or null when
     * the project already uses the current shape throughout.
     *
     * @throws RuntimeException When the project cannot be moved as it is; nothing is changed.
     */
    public static function describe(string $projectRoot): ?SourceSetRequired
    {
        $plan = self::plan($projectRoot);
        if ($plan === null) {
            return null;
        }
        $root = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $paths = array_map(static fn(string $path): string => str_starts_with($path, $root) ? substr($path, strlen($root)) : $path,
            $plan->getChangedPaths());

        return new SourceSetRequired($plan, self::LABEL, 'this Super Move migration',
            sprintf('Move %s to %s and rename crisisAbility to superMove on every actor that has it?',
                SuperMoveFields::LEGACY_CATALOGUE, SuperMoveFields::CATALOGUE), $paths);
    }

    /**
     * Plans the migration without writing anything.
     *
     * @return SourceSetPlan|null The files to write, or null when there is nothing to move.
     * @throws RuntimeException When the project cannot be moved as it is; nothing is changed.
     */
    public static function plan(string $projectRoot): ?SourceSetPlan
    {
        return ProjectDirectoryContext::run($projectRoot, self::buildPlan(...));
    }

    private static function buildPlan(string $root): ?SourceSetPlan
    {
        $catalogue = $root . '/' . SuperMoveFields::CATALOGUE;
        $legacy = $root . '/' . SuperMoveFields::LEGACY_CATALOGUE;
        if (file_exists($catalogue) && file_exists($legacy)) {
            throw new RuntimeException(sprintf('Both %s and %s declare Super Moves, so nothing was moved. Keep one of them, then migrate again.',
                SuperMoveFields::CATALOGUE, SuperMoveFields::LEGACY_CATALOGUE));
        }

        $actorPaths = glob($root . '/assets/Data/Actors/*.php') ?: [];
        $legacyPaths = is_file($legacy) ? [$legacy] : [];
        $payloads = array_map(PhpDataFile::getComparableValue(...), PhpDataFile::evaluateIsolatedFiles([...$actorPaths, ...$legacyPaths], $root));
        $watched = $originals = $proposals = $before = $after = [];

        if ($legacyPaths !== []) {
            $entries = $payloads[count($actorPaths)];
            try {
                new CrisisAbilityCatalog(is_array($entries) ? $entries : throw new InvalidArgumentException('it does not return a list'));
            } catch (InvalidArgumentException $refused) {
                throw new RuntimeException(sprintf('The game cannot read %s (%s), so nothing was moved. Correct it, then migrate again.',
                    SuperMoveFields::LEGACY_CATALOGUE, $refused->getMessage()), previous: $refused);
            }
            $source = self::readSource($legacy);
            // The catalogue moves as authored: the same bytes under the name the Engine now reads.
            $watched[$legacy] = $originals[$legacy] = $source;
            $proposals[$legacy] = null;
            $before[$legacy] = $entries;
            $after[$legacy] = null;
            $originals[$catalogue] = null;
            $proposals[$catalogue] = $source;
            $before[$catalogue] = null;
            $after[$catalogue] = $entries;
        }

        foreach ($actorPaths as $index => $path) {
            $source = self::readSource($path);
            $watched[$path] = $source;
            $payload = $payloads[$index];
            $data = is_array($payload) ? ($payload['data'] ?? null) : null;
            if (! is_array($data) || ! array_key_exists(SuperMoveFields::LEGACY_ACTOR_KEY, $data)) {
                continue;
            }
            [$moved, $proposal] = self::moveActorReference($path, $source, $payload);
            $originals[$path] = $source;
            $proposals[$path] = $proposal;
            $before[$path] = $payload;
            $after[$path] = $moved;
        }

        if ($proposals === []) {
            return null;
        }

        return new SourceSetPlan(
            $root,
            $watched,
            $originals,
            $proposals,
            $before,
            $after,
            // The catalogue created is watched on its own: it must not exist until written.
            static fn(): array => [...(glob($root . '/assets/Data/Actors/*.php') ?: []), ...(is_file($legacy) ? [$legacy] : [])],
            static fn(string $path, string $staged): mixed => PhpDataFile::getComparableValue(PhpDataFile::evaluateIsolated($staged, $root)),
            self::SUBJECT,
            'The actor files and Super Move catalogue',
        );
    }

    /**
     * Returns an actor payload naming its Super Move under `superMove` alone,
     * and its source rewritten to match, keeping everything else as authored.
     *
     * @param array<string, mixed> $payload The actor file as the game reads it.
     * @return array{0: array<string, mixed>, 1: string}
     * @throws RuntimeException When the game cannot read the reference or the source cannot take the change.
     */
    private static function moveActorReference(string $path, string $source, array $payload): array
    {
        $data = $payload['data'];
        try {
            $reference = CrisisAbilityCatalog::getActorReference($data);
        } catch (InvalidArgumentException $refused) {
            throw new RuntimeException(sprintf('%s: %s Nothing was moved. Choose its Super Move on the actor page, then migrate again.',
                $path, $refused->getMessage()), previous: $refused);
        }

        $moved = $payload;
        $renames = [];
        if (array_key_exists(SuperMoveFields::ACTOR_KEY, $data)) {
            // Both keys already agree: the current one stays where it is and the earlier one goes.
            unset($moved['data'][SuperMoveFields::LEGACY_ACTOR_KEY]);
        } else {
            $renamed = [];
            foreach ($data as $key => $value) {
                $renamed[$key === SuperMoveFields::LEGACY_ACTOR_KEY ? SuperMoveFields::ACTOR_KEY : $key] = $value;
            }
            $moved['data'] = $renamed;
            $renames[] = ['path' => ['data', SuperMoveFields::LEGACY_ACTOR_KEY], 'key' => SuperMoveFields::ACTOR_KEY];
        }
        if (CrisisAbilityCatalog::getActorReference($moved['data']) !== $reference) {
            throw new RuntimeException(sprintf('%s would not keep its Super Move %s, so nothing was moved.', $path, $reference ?? 'none'));
        }

        try {
            $proposal = ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($source), $payload, $moved, $renames)->source;
        } catch (Throwable $refused) {
            throw new RuntimeException(sprintf('%s: %s Nothing was moved; write its data as a plain array, then migrate again.',
                $path, rtrim($refused->getMessage(), '.') . '.'), previous: $refused);
        }

        return [$moved, $proposal];
    }

    private static function readSource(string $path): string
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException(sprintf('%s could not be read, so nothing was moved.', $path));
        }

        return $source;
    }
}
