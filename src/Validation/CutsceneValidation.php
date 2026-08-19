<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Cutscenes\CutsceneHydration;
use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Throwable;

/**
 * The cutscene checks of the project validator: paired files, stable ids,
 * the Engine's own hydration and compilation, and the references a
 * cinematic's command tree makes that the Engine can only resolve at play
 * time — cast ids, NPC ids on the map it plays on, maps, music, animations,
 * common events, declared checkpoints — plus the map triggers that launch
 * cinematics.
 *
 * Structure, nested shapes, finalizer vocabulary and authored-skip safety
 * are judged by the Engine (`CinematicDefinition::fromArrays()` and the
 * summon compiler); the validator reports what the Engine says rather than
 * keeping a second opinion.
 *
 * @package Ichiloto\Editor\Validation
 */
trait CutsceneValidation
{
    /**
     * Checks every cinematic and summon folder.
     *
     * @return Issue[]
     */
    protected function checkCutscenes(ProjectWorkspace $workspace): array
    {
        if (! CutsceneHydration::isAvailable()) {
            return [];
        }

        $library = $workspace->cutscenes;

        if (! $library instanceof CutsceneLibrary) {
            try {
                $library = CutsceneLibrary::fromProject($workspace->projectRoot);
            } catch (Throwable $throwable) {
                return [Issue::error('assets/Cutscenes', sprintf('The cutscenes could not be read: %s', $throwable->getMessage()))];
            }
        }

        $issues = [];

        foreach (CutsceneType::cases() as $type) {
            foreach ($library->issues($type) as $finding) {
                $issues[] = Issue::error(
                    sprintf('%s %s', $type->noun(), basename($finding['folder'])),
                    $finding['message'],
                    sprintf('A %s is one folder holding <id>.data.php and <id>%s, named after its folder.', $type->noun(), $type->partnerSuffix()),
                );
            }

            foreach ($library->assets($type) as $asset) {
                if ($asset->isDeleted()) {
                    continue;
                }

                $issues = [...$issues, ...$this->checkCutsceneAsset($workspace, $asset)];
            }
        }

        return $issues;
    }

    /**
     * Checks one asset: its identity, what the Engine makes of it, and the
     * references its content makes.
     *
     * @return Issue[]
     */
    protected function checkCutsceneAsset(ProjectWorkspace $workspace, CutsceneAsset $asset): array
    {
        $where = sprintf('%s %s', $asset->type->noun(), $asset->id);
        $issues = [];
        if ($asset->readOnlyReason() !== null) {
            // The library's discovery findings already say why.
            return [];
        }

        $data = $asset->data();
        $declared = trim(strval($data['id'] ?? ''));

        if ($declared !== $asset->id) {
            $issues[] = Issue::error(
                $where,
                sprintf('Its data declares id "%s" but its folder is "%s".', $declared !== '' ? $declared : '(empty)', $asset->id),
                'The Engine resolves a cutscene by folder; keep the id and the folder name equal.',
            );
        }

        if ($asset->type === CutsceneType::CINEMATIC) {
            try {
                $definition = $asset->cinematicDefinition();
            } catch (Throwable $throwable) {
                return [...$issues, Issue::error($where, $throwable->getMessage(), 'The Engine refuses this cinematic as it stands; the message names the path.')];
            }

            return [...$issues, ...$this->checkCinematicReferences($workspace, $asset, $definition->commands, $definition->finalizer)];
        }

        try {
            $asset->compiledSummon();
        } catch (Throwable $throwable) {
            return [...$issues, Issue::error($where, $throwable->getMessage(), 'The Engine refuses this summon as it stands; the message names what it needs.')];
        }

        return $issues;
    }

    /**
     * Checks the references a cinematic's commands make.
     *
     * @param array<int, mixed> $commands
     * @param array<int, mixed> $finalizer
     * @return Issue[]
     */
    protected function checkCinematicReferences(ProjectWorkspace $workspace, CutsceneAsset $asset, array $commands, array $finalizer): array
    {
        $where = sprintf('cinematic %s', $asset->id);
        $data = $asset->data();
        $known = $this->cutsceneKnownReferences($workspace);
        $npcIdsByMap = $this->npcIdsByMap($workspace);
        $startMap = is_string($data['startMap'] ?? null) ? trim($data['startMap']) : '';
        $issues = [];

        if ($startMap !== '') {
            $issues = [...$issues, ...$this->checkReference($startMap, 'maps', 'start map', $where, $known)];
        }

        $castIds = [];
        $npcCast = [];

        foreach ((array) ($data['cast'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = trim(strval($entry['id'] ?? ''));
            $kind = strtolower(trim(strval($entry['kind'] ?? 'staged_actor')));

            if ($id !== '' && $kind === 'staged_actor') {
                $castIds[] = $id;
            }

            if ($kind === 'npc') {
                $npcCast[] = trim(strval($entry['npcId'] ?? $id));
            }
        }

        $checkpoints = array_values(array_filter(array_map(static fn(mixed $name): string => is_string($name) ? trim($name) : '', (array) ($data['checkpoints'] ?? [])), static fn(string $name): bool => $name !== ''));
        $npcIds = $startMap !== '' && array_key_exists($startMap, $npcIdsByMap) ? $npcIdsByMap[$startMap] : null;

        foreach ($npcCast as $npcId) {
            if ($npcId !== '' && $npcIds !== null && ! in_array($npcId, $npcIds, true)) {
                $issues[] = Issue::error($where, sprintf('Its cast names NPC "%s", which is not on %s.', $npcId, $startMap), 'Use a stable NPC id from the start map, or stage the character as a staged_actor.');
            }
        }

        $context = ['castIds' => $castIds, 'checkpoints' => $checkpoints, 'npcIds' => $npcIds, 'mapId' => $startMap !== '' ? $startMap : null, 'npcIdsByMap' => $npcIdsByMap, 'seenCheckpoints' => []];
        $issues = [...$issues, ...$this->walkCinematicCommands($commands, $where, $known, $context)];
        $issues = [...$issues, ...$this->walkCinematicCommands($finalizer, $where . ' finalizer', $known, $context)];

        return $issues;
    }

    /**
     * Walks a cinematic command list, nested blocks and lanes included.
     *
     * @param array<int, mixed> $commands
     * @param array<string, string[]> $known
     * @param array{castIds: string[], checkpoints: string[], npcIds: string[]|null, mapId: string|null, npcIdsByMap: array<string, string[]>, seenCheckpoints: string[]} $context
     * @return Issue[]
     */
    protected function walkCinematicCommands(array $commands, string $where, array $known, array &$context): array
    {
        $issues = [];

        foreach ($commands as $command) {
            if (! is_array($command)) {
                continue;
            }

            $type = strval($command['type'] ?? '');

            if (! in_array($type, CinematicCommandSchema::COMMAND_TYPES, true)) {
                continue; // The Engine already refused the tree.
            }

            $named = match ($type) {
                'give_item' => ['inventory', 'item', 'item'],
                'play_music' => ['bgm', 'music', 'track'],
                'cinematic_music' => ['bgm', 'track', 'track'],
                'play_sound' => ['sfx', 'sound', 'sound'],
                'accept_quest' => ['quests', 'id', 'quest'],
                'transfer' => ['maps', 'map', 'map'],
                'start_battle' => ['troops', 'troop', 'troop'],
                'common_event' => ['common_events', 'id', 'common event'],
                'show_animation', 'field_animation' => ['animations', 'animation', 'animation'],
                default => null,
            };

            if (is_array($named)) {
                [$category, $key, $noun] = $named;
                $issues = [...$issues, ...$this->checkReference(strval($command[$key] ?? ''), $category, $noun, $where, $known)];
            }

            if ($type === 'checkpoint') {
                $name = trim(strval($command['name'] ?? ''));

                if ($name !== '' && $context['checkpoints'] !== [] && ! in_array($name, $context['checkpoints'], true)) {
                    $issues[] = Issue::warning($where, sprintf('It records checkpoint "%s", which its checkpoints list does not declare.', $name), 'Declare it in the cinematic\'s Checkpoints, or rename the command.');
                }

                if ($name !== '') {
                    $context['seenCheckpoints'][] = $name;
                }
            }

            if ($type === 'stage_actor') {
                $id = trim(strval($command['id'] ?? $command['actorId'] ?? ''));

                if ($id !== '') {
                    $context['castIds'][] = $id;
                }
            }

            if (in_array($type, ['remove_actor', 'show_actor', 'hide_actor'], true)) {
                $issues = [...$issues, ...$this->checkStagedActorReference(trim(strval($command['actorId'] ?? $command['id'] ?? '')), $where, $context)];
            }

            if ($type === 'move_route' || $type === 'camera' || $type === 'show_animation' || $type === 'field_animation') {
                $issues = [...$issues, ...$this->checkCinematicSubject($command, $where, $context)];
            }

            $issues = [...$issues, ...$this->checkConditions((array) ($command['conditions'] ?? []), $where, $known)];

            if ($type === 'sequence') {
                $issues = [...$issues, ...$this->walkCinematicCommands((array) ($command['commands'] ?? []), $where, $known, $context)];
            }

            if ($type === 'parallel') {
                foreach ((array) ($command['lanes'] ?? []) as $lane) {
                    $laneCommands = is_array($lane) && array_is_list($lane) ? $lane : (is_array($lane) ? (array) ($lane['commands'] ?? []) : []);
                    $issues = [...$issues, ...$this->walkCinematicCommands($laneCommands, $where, $known, $context)];
                }
            }

            foreach (['then', 'else', 'cancel'] as $arm) {
                if (is_array($command[$arm] ?? null)) {
                    $issues = [...$issues, ...$this->walkCinematicCommands($command[$arm], $where, $known, $context)];
                }
            }

            foreach ((array) ($command['options'] ?? []) as $option) {
                if (is_array($option) && is_array($option['then'] ?? null)) {
                    $issues = [...$issues, ...$this->walkCinematicCommands($option['then'], $where, $known, $context)];
                }
            }

            if ($type === 'transfer') {
                $destination = trim(strval($command['map'] ?? ''));
                $context['mapId'] = $destination !== '' ? $destination : $context['mapId'];
                $context['npcIds'] = $destination !== '' && array_key_exists($destination, $context['npcIdsByMap']) ? $context['npcIdsByMap'][$destination] : null;
            }
        }

        return $issues;
    }

    /**
     * Checks a command's subject or target: a staged actor in the cast, an
     * NPC on the map the cinematic is on.
     *
     * @param array<string, mixed> $command
     * @param array{castIds: string[], checkpoints: string[], npcIds: string[]|null, mapId: string|null, npcIdsByMap: array<string, string[]>, seenCheckpoints: string[]} $context
     * @return Issue[]
     */
    protected function checkCinematicSubject(array $command, string $where, array $context): array
    {
        $subject = $command['subject'] ?? $command['target'] ?? null;
        $kind = is_array($subject) ? strtolower(trim(strval($subject['kind'] ?? ''))) : strtolower(trim(strval($subject ?? '')));
        $fields = is_array($subject) ? $subject : $command;

        if ($kind === 'staged_actor') {
            return $this->checkStagedActorReference(trim(strval($fields['actorId'] ?? $fields['id'] ?? '')), $where, $context);
        }

        if ($kind === 'npc') {
            $npcId = trim(strval($fields['npcId'] ?? $fields['id'] ?? ''));

            if ($npcId !== '' && $context['npcIds'] !== null && ! in_array($npcId, $context['npcIds'], true)) {
                return [Issue::error($where, sprintf('It addresses NPC "%s", which is not on %s.', $npcId, $context['mapId'] ?? '(unknown map)'), 'Use a stable NPC id from the map the cinematic is on at that point.')];
            }
        }

        return [];
    }

    /**
     * @param array{castIds: string[], checkpoints: string[], npcIds: string[]|null, mapId: string|null, npcIdsByMap: array<string, string[]>, seenCheckpoints: string[]} $context
     * @return Issue[]
     */
    protected function checkStagedActorReference(string $actorId, string $where, array $context): array
    {
        if ($actorId === '' || in_array($actorId, $context['castIds'], true)) {
            return [];
        }

        return [Issue::error($where, sprintf('It addresses staged actor "%s", which neither the cast nor an earlier stage_actor command defines.', $actorId), 'Add the actor to the cast, stage it first, or pick an existing id.')];
    }

    /**
     * Checks a map event that launches a cinematic.
     *
     * @param array<string, mixed> $definition
     * @param array<string, string[]> $known
     * @return Issue[]
     */
    protected function checkCinematicTrigger(array $definition, string $where, array $known): array
    {
        $issues = [];
        $allowedRoot = CinematicCommandSchema::CINEMATIC_TRIGGER_ROOT_FIELDS;

        foreach (array_diff(array_keys($definition), $allowedRoot) as $field) {
            $issues[] = Issue::error($where, sprintf('CinematicEventTrigger uses unsupported root field "%s".', $field), sprintf('Use %s.', implode(', ', $allowedRoot)));
        }

        $data = $definition['data'] ?? null;

        if (! is_array($data)) {
            return [...$issues, Issue::error($where, 'CinematicEventTrigger data is malformed.', 'Store cinematicId, mode and reusable in a data array.')];
        }

        $allowedData = CinematicCommandSchema::CINEMATIC_TRIGGER_DATA_FIELDS;

        foreach (array_diff(array_keys($data), $allowedData) as $field) {
            $issues[] = Issue::error($where, sprintf('CinematicEventTrigger uses unsupported data field "%s".', $field), sprintf('Use %s.', implode(', ', $allowedData)));
        }

        $cinematicId = trim(strval($data['cinematicId'] ?? ''));

        if ($cinematicId === '') {
            $issues[] = Issue::error($where, 'CinematicEventTrigger names no cinematicId.', 'Choose a cinematic from the picker.');
        } elseif (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $cinematicId) !== 1) {
            $issues[] = Issue::error($where, sprintf('CinematicEventTrigger cinematicId "%s" is not a stable lowercase identifier.', $cinematicId), 'Cinematic ids are lowercase letters, digits, dots, dashes and underscores.');
        } elseif (! in_array($cinematicId, $known['cinematics'] ?? [], true)) {
            $issues[] = Issue::error($where, sprintf('It launches cinematic "%s", which does not exist.', $cinematicId), 'The trigger fails closed at runtime. Choose it from the picker.');
        }

        $mode = strtolower(trim(strval($data['mode'] ?? 'action')));

        if (! in_array($mode, CinematicCommandSchema::CINEMATIC_TRIGGER_MODES, true)) {
            $issues[] = Issue::error($where, sprintf('CinematicEventTrigger uses unsupported mode "%s".', $mode !== '' ? $mode : '(empty)'), 'Choose auto or action.');
        }

        if (array_key_exists('reusable', $data) && ! is_bool($data['reusable'])) {
            $issues[] = Issue::error($where, 'CinematicEventTrigger reusable must be boolean.', 'Choose true or false.');
        }

        return [...$issues, ...$this->checkStateWrites((array) ($definition['sets'] ?? []), $where)];
    }

    /**
     * The reference categories cutscene checks resolve against.
     *
     * @return array<string, string[]>
     */
    protected function cutsceneKnownReferences(ProjectWorkspace $workspace): array
    {
        $catalog = new \Ichiloto\Editor\Database\ReferenceCatalog($workspace);
        $known = [];

        foreach (['quests', 'maps', 'troops', 'inventory', 'bgm', 'sfx', 'common_events', 'animations', 'cinematics', 'summons'] as $category) {
            try {
                $known[$category] = $catalog->valuesFor($category);
            } catch (Throwable) {
                $known[$category] = [];
            }
        }

        return $known;
    }
}
