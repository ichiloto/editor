<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Actors\ActorReferenceInventory;
use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\ProjectWorkspace;
use Throwable;

/** Check the same catalogue-reference locations the confirmed migration repairs. */
final class ActorReferenceValidator
{
    public const string UNRESOLVED_ACTOR_REFERENCE = 'actor.reference.unresolved';

    public function validate(ProjectWorkspace $workspace): array
    {
        $ids = [];
        foreach ($workspace->actorDatabase->getActors() as $actor) {
            if ($actor->hasDefinitionId()) { $ids[] = $actor->getDefinitionId(); }
        }
        $payloads = ['assets/Data/system.php' => ['startingParty' => $workspace->systemDatabase->getField('startingParty') ?? []]];
        foreach (['skits', 'troops', 'common_events', 'battle_entry_rules'] as $category) {
            $database = $workspace->getRecordDatabase($category);
            if ($database === null) { continue; }
            foreach ($database->getRecords() as $index => $record) {
                $path = $database->schema->relativePath . ':' . $index;
                if ($category === 'skits') {
                    $path = $record->sourcePath ?? $database->path . '/' . $record->recordId . '.php';
                    $root = rtrim($workspace->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                    if (str_starts_with($path, $root)) { $path = substr($path, strlen($root)); }
                }
                $payloads[$path] = $record->toArray();
            }
        }
        foreach ($workspace->maps as $map) {
            $payloads[$map->dataPath] = $map->getEditableData();
        }
        $issues = [];
        foreach (['battle', 'dialogue', 'menus'] as $name) {
            $relative = 'assets/Data/Presentation/' . $name . '.php';
            $path = $workspace->projectRoot . '/' . $relative;
            if (! is_file($path)) { continue; }
            try { $payloads[$relative] = PhpDataFile::evaluateIsolated($path, $workspace->projectRoot); }
            catch (Throwable $failure) {
                $issues[] = Issue::error($relative, 'Actor presentation references could not be checked: ' . $failure->getMessage());
            }
        }
        foreach ($payloads as $where => $payload) {
            foreach (ActorReferenceInventory::getReferences($payload, $where) as $reference) {
                // Legacy speaker is display text, not an explicit actor reference.
                if ($reference['kind'] === 'speaker') { continue; }
                $value = $reference['reference'];
                $known = $ids;
                if (is_string($value) && ($reference['caseInsensitive'] ?? false)) {
                    $value = strtolower(trim($value));
                    $known = array_map(static fn(string $id): string => strtolower(trim($id)), $ids);
                }
                if (! is_string($value) || ! in_array($value, $known, true)) {
                    $issues[] = Issue::error($where . ':' . implode('.', $reference['path']),
                        'Unresolved actor reference ' . var_export($reference['reference'], true) . '; use an explicit stable actor id.',
                        'Review and confirm the project actor identity migration, or select an existing actor.',
                        self::UNRESOLVED_ACTOR_REFERENCE);
                }
            }
        }
        return $issues;
    }
}
