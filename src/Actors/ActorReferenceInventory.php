<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Actors;

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
            foreach (['actors', 'portraits'] as $field) {
                foreach (array_keys((array) ($payload[$field] ?? [])) as $id) {
                    $references[] = ['path' => [$field, $id], 'reference' => $id, 'kind' => 'key'];
                }
            }
            foreach (array_keys((array) ($payload['results']['portraits'] ?? [])) as $id) {
                $references[] = ['path' => ['results', 'portraits', $id], 'reference' => $id, 'kind' => 'key'];
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
        // Troops use enemy IDs; event actorId/actor target staged subjects.
        // Display names and summon wielder names are not this identity contract.
        return $references;
    }

    /** Strip object identity, not authored values, for isolated readback comparisons. */
    public static function getComparableValue(mixed $value): mixed
    {
        if (is_object($value)) {
            $fields = get_object_vars($value);
            $class = $fields['__PHP_Incomplete_Class_Name'] ?? $value::class;
            unset($fields['__PHP_Incomplete_Class_Name']);
            return ['__class' => $class, ...array_map(self::getComparableValue(...), $fields)];
        }
        return is_array($value) ? array_map(self::getComparableValue(...), $value) : $value;
    }

    /** Only runtime content locations, never saves, unrelated PHP or map glyph layers. */
    public static function getSourcePaths(string $root): array
    {
        $paths = [];
        foreach (['assets/Data/system.php', 'assets/Data/battle-entry-rules.php', 'assets/Data/troops.php',
            'assets/Data/Presentation/battle.php', 'assets/Data/Presentation/dialogue.php', 'assets/Data/Presentation/menus.php'] as $relative) {
            if (is_file($root . '/' . $relative)) { $paths[] = $root . '/' . $relative; }
        }
        foreach (['assets/Data/Skits', 'assets/Events', 'assets/Maps'] as $directory) {
            if (! is_dir($root . '/' . $directory)) { continue; }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), $directory === 'assets/Maps' ? '.data.php' : '.php')) {
                    $paths[] = $file->getPathname();
                }
            }
        }
        sort($paths, SORT_STRING);
        return $paths;
    }
}
