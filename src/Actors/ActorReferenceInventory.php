<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Actors;

use Ichiloto\Editor\Database\PhpDataFile;

/** Catalogue actor references, not NPC/staged identities or ordinary speaker text. */
final class ActorReferenceInventory
{
    /** @return list<array{path: array, reference: mixed, kind: string, caseInsensitive?: bool}> */
    public static function getReferences(mixed $payload, string $relativePath): array
    {
        $payload = self::getComparableValue($payload);
        if (! is_array($payload)) { return []; }
        $references = [];
        if ($relativePath === 'assets/Data/system.php') {
            foreach ((array) ($payload['startingParty'] ?? []) as $index => $id) {
                $references[] = ['path' => ['startingParty', $index], 'reference' => $id, 'kind' => 'value', 'caseInsensitive' => true];
            }
        }
        if (str_starts_with($relativePath, 'assets/Data/Presentation/')) {
            // Dialogue artwork, menu portraits and battle artwork/poses are keyed by actor ID.
            foreach (['actors', 'portraits', 'actorPoses'] as $field) {
                foreach (array_keys((array) ($payload[$field] ?? [])) as $id) {
                    $references[] = ['path' => [$field, $id], 'reference' => $id, 'kind' => 'key'];
                }
            }
            foreach (array_keys((array) ($payload['results']['portraits'] ?? [])) as $id) {
                $references[] = ['path' => ['results', 'portraits', $id], 'reference' => $id, 'kind' => 'key'];
            }
            // Battle scale: its reference body and party profiles; enemy profiles are enemy identities.
            $scale = $payload['scale'] ?? null;
            if (is_array($scale)) {
                if (array_key_exists('referenceActorId', $scale)) {
                    $references[] = ['path' => ['scale', 'referenceActorId'], 'reference' => $scale['referenceActorId'], 'kind' => 'value'];
                }
                foreach (array_keys((array) ($scale['actors'] ?? [])) as $id) {
                    $references[] = ['path' => ['scale', 'actors', $id], 'reference' => $id, 'kind' => 'key'];
                }
            }
            // Battler bindings (battlers.php) key actors under `actors`, read
            // above, and name the scale reference actor; enemies are enemy identities.
            $reference = $payload['reference'] ?? null;
            if (is_array($reference) && array_key_exists('actor', $reference)) {
                $references[] = ['path' => ['reference', 'actor'], 'reference' => $reference['actor'], 'kind' => 'value'];
            }
            // Speaker labels bind to an actor or to a non-actor artwork resource.
            $resources = (array) ($payload['resources'] ?? []);
            foreach ((array) ($payload['speakers'] ?? []) as $label => $identity) {
                if (! is_string($identity) || ! array_key_exists($identity, $resources)) {
                    $references[] = ['path' => ['speakers', $label], 'reference' => $identity, 'kind' => 'value'];
                }
            }
            return $references;
        }
        if (str_starts_with($relativePath, 'assets/Data/Skits/')) {
            foreach ((array) ($payload['beats'] ?? []) as $index => $beat) {
                if (! is_array($beat)) { continue; }
                $key = array_key_exists('actor', $beat) ? 'actor' : 'speaker';
                if (array_key_exists($key, $beat)) {
                    $references[] = ['path' => ['beats', $index, $key], 'reference' => $beat[$key], 'kind' => $key === 'actor' ? 'value' : 'speaker'];
                }
            }
        }
        if (str_starts_with($relativePath, 'assets/Data/battle-entry-rules.php')) {
            $rules = isset($payload['rules']) ? $payload['rules'] : [$payload];
            foreach ((array) $rules as $index => $rule) {
                if (! is_array($rule)) { continue; }
                $prefix = isset($payload['rules']) ? ['rules', $index] : [];
                foreach (['actors', 'effects'] as $field) {
                    foreach ((array) ($rule[$field] ?? []) as $entryIndex => $entry) {
                        if (is_array($entry) && array_key_exists('actor', $entry)) {
                            $references[] = ['path' => [...$prefix, $field, $entryIndex, 'actor'], 'reference' => $entry['actor'], 'kind' => 'value', 'caseInsensitive' => true];
                        }
                    }
                }
            }
        }
        // A summon's character wielders, which the Engine matches by stable actor id.
        if (str_starts_with($relativePath, 'assets/Cutscenes/Summons/') && str_ends_with($relativePath, '.data.php')) {
            $wielders = $payload['wielders'] ?? null;
            if (is_array($wielders) && is_array($wielders['characters'] ?? null)) {
                foreach ($wielders['characters'] as $index => $id) {
                    $references[] = ['path' => ['wielders', 'characters', $index], 'reference' => $id, 'kind' => 'value', 'caseInsensitive' => true];
                }
            }
        }
        // Troops use enemy IDs; event actorId/actor target staged subjects.
        // Display names are not this identity contract.
        return $references;
    }

    /** Strip object identity, not authored values, for isolated readback comparisons. */
    public static function getComparableValue(mixed $value): mixed
    {
        return PhpDataFile::getComparableValue($value);
    }

    /** Only runtime content locations, never saves, unrelated PHP or map glyph layers. */
    public static function getSourcePaths(string $root): array
    {
        $paths = [];
        foreach (['assets/Data/system.php', 'assets/Data/battle-entry-rules.php', 'assets/Data/troops.php',
            'assets/Data/Presentation/battle.php', 'assets/Data/Presentation/battlers.php', 'assets/Data/Presentation/dialogue.php',
            'assets/Data/Presentation/menus.php'] as $relative) {
            if (is_file($root . '/' . $relative)) { $paths[] = $root . '/' . $relative; }
        }
        foreach (['assets/Data/Skits', 'assets/Events', 'assets/Maps', 'assets/Cutscenes/Summons'] as $directory) {
            if (! is_dir($root . '/' . $directory)) { continue; }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), in_array($directory, ['assets/Maps', 'assets/Cutscenes/Summons'], true) ? '.data.php' : '.php')) {
                    $paths[] = $file->getPathname();
                }
            }
        }
        sort($paths, SORT_STRING);
        return $paths;
    }
}
