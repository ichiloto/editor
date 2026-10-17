<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\ProjectConfig;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Engine\Animations\Field\FieldEffectManager;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Cutscenes\Presentation\PartyStageSelection;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandRegistry;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Throwable;

/**
 * Checks every effect timeline the project uses, as the Engine plays it for
 * each consumer: battle animations' source and target effects in battle;
 * the field presentation's cue and action prompt effects, maps' field
 * effects and tileset pieces' effects on the field; inns' rest stages as a
 * stage of their own. Battle and field effects are compiled for both the
 * terminal and the graphical presentation, so a sequence that only one
 * renderer would refuse is still found; graphical stages use stage admission,
 * while a paired Terminal sequence retains its own cues and tracks.
 */
final class EffectValidator
{
    private const string FIELD_PRESENTATION = 'assets/Data/Presentation/field.php';

    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $assetRoot = $workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets';
        $issues = [];
        $uses = self::findUses($workspace, $issues);
        $library = new EffectTimelineLibrary($assetRoot);
        ksort($uses);

        foreach ($uses as $effect => $contexts) {
            foreach ($contexts as $context => $places) {
                if ($context === 'stage') {
                    try {
                        $library->loadStage($effect);
                        $asset = $workspace->cutscenes?->find(CutsceneType::EFFECT, $effect)
                            ?? throw new \RuntimeException('The effect timeline could not be read.');
                        $asset->compileEffect(EffectPresentation::TERMINAL, false, forStage: true);
                    } catch (Throwable $failure) {
                        $issues[] = Issue::error($places[0], sprintf('Effect %s cannot be played as a stage: %s', $effect, $failure->getMessage()),
                            sprintf('Fix assets/Animations/%s, or choose a stage timeline. Until then its consumer cannot show the stage.%s', $effect,
                                count($places) > 1 ? sprintf(' Also used by %d more.', count($places) - 1) : ''));
                    }
                    continue;
                }
                foreach (EffectPresentation::cases() as $presentation) {
                    try {
                        $library->load($effect, $context === 'battle', $presentation);
                    } catch (Throwable $failure) {
                        $issues[] = Issue::error($places[0], sprintf('Effect %s cannot be played in %s for the %s presentation: %s',
                            $effect, $context, $presentation->value, $failure->getMessage()),
                            sprintf('Fix assets/Animations/%s. Until then that presentation keeps its fallback.%s', $effect,
                                count($places) > 1 ? sprintf(' Also used by %d more.', count($places) - 1) : ''));
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * Checks an effect a `field_animation` command plays and waits for, as
     * the Engine plays it on the field for each presentation: it must load,
     * and it must play once, since loops belong to a map's own effects.
     *
     * @param EffectTimelineLibrary $library The project's timelines.
     * @param string $effect The effect's stable id.
     * @param string $where Where the command is.
     * @return Issue[]
     */
    public static function checkOneShotFieldEffect(EffectTimelineLibrary $library, string $effect, string $where): array
    {
        $issues = [];

        foreach (EffectPresentation::cases() as $presentation) {
            try {
                $timeline = $library->load($effect, false, $presentation);
            } catch (Throwable $failure) {
                $issues[] = Issue::error($where, sprintf('Its effect %s cannot be played on the field for the %s presentation: %s',
                    $effect, $presentation->value, $failure->getMessage()),
                    sprintf('Fix assets/Animations/%s. The runtime stops the script at this command.', $effect));
                continue;
            }

            if ($timeline->defaults['playback']['loop'] ?? false) {
                $issues[] = Issue::error($where, sprintf('Its effect %s loops in the %s presentation, and a field_animation waits for its effect to end.',
                    $effect, $presentation->value),
                    'Play a looping effect from the map\'s field effects instead, or give this timeline once playback.');
            }
        }

        return $issues;
    }

    /**
     * Returns where the project plays each effect, by context: battle
     * animations' source and target effects in battle; the field
     * presentation's cues and action prompt, maps' field effects and tileset
     * pieces' effects on the field; and the rest stage of Sleep events, `inn`
     * commands at any depth and the project's default inn presentation.
     *
     * @param Issue[] $issues Receives what could not be read.
     * @return array<string, array{battle?: list<string>, field?: list<string>, stage?: list<string>}>
     */
    public static function findUses(ProjectWorkspace $workspace, array &$issues = []): array
    {
        $assetRoot = $workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets';
        $uses = [];
        $use = static function (string $effect, bool $battle, string $where) use (&$uses): void {
            $uses[$effect][$battle ? 'battle' : 'field'][] = $where;
        };

        foreach ($workspace->getRecordDatabase('animations')?->getRecords() ?? [] as $animation) {
            foreach (['sourceEffect', 'targetEffect'] as $field) {
                $effect = $animation->get($field);
                if (is_string($effect) && $effect !== '') {
                    $use($effect, true, sprintf('assets/Data/animations.php: %s %s', strval($animation->get('name')), $field));
                }
            }
        }

        foreach (self::findStageUses($workspace, $issues) as $effect => $places) {
            $uses[$effect]['stage'] = $places;
        }

        try {
            $catalog = FieldPresentationCatalog::load($assetRoot);

            foreach ($catalog->cues as $color => $cue) {
                $use($cue['effect'], false, sprintf('%s: %s cue', self::FIELD_PRESENTATION, $color));
            }

            if ($catalog->actionPrompt !== null) {
                $use($catalog->actionPrompt, false, self::FIELD_PRESENTATION . ': action prompt');
            }
        } catch (Throwable $failure) {
            $issues[] = Issue::error(self::FIELD_PRESENTATION, 'The field presentation could not be read: ' . $failure->getMessage(),
                'Until this is fixed, cues and the action prompt show their terminal glyphs.');
        }

        foreach ($workspace->maps as $map) {
            try {
                foreach (FieldEffectManager::readDeclarations($map->data['fieldEffects'] ?? null) as $declaration) {
                    $use($declaration['effect'], false, sprintf('%s field effect %s', $map->mapId, $declaration['id']));
                }
            } catch (Throwable $failure) {
                $issues[] = Issue::error($map->mapId, 'Its fieldEffects cannot be read: ' . $failure->getMessage(),
                    'Until this is fixed, the map shows none of its field effects; gameplay is unchanged.');
            }
        }

        foreach (glob($assetRoot . DIRECTORY_SEPARATOR . Tileset::DIRECTORY . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $id = basename($file, '.php');

            try {
                $tileset = Tileset::load($assetRoot, $id);
            } catch (Throwable) {
                continue; // Map graphics validation reports an unreadable tileset.
            }

            foreach ($tileset->pieces as $piece) {
                if ($piece->effect !== null) {
                    $use($piece->effect, false, sprintf('assets/%s/%s.php piece %s', Tileset::DIRECTORY, $id, $piece->id));
                }
            }
        }

        return $uses;
    }

    /**
     * Returns where each rest stage timeline is named, as InnOffer reads it:
     * a Sleep event's data, an `inn` command at any depth of a map event,
     * event script or cinematic, and config's graphics.inn.presentation for
     * an inn naming none.
     *
     * @param Issue[] $issues
     * @param-out Issue[] $issues
     * @return array<string, list<string>> Timeline id to where it is named.
     */
    private static function findStageUses(ProjectWorkspace $workspace, array &$issues): array
    {
        $uses = [];
        $actors = new ReferenceCatalog($workspace)->valuesFor('actor_ids');
        $name = static function (mixed $presentation, string $where) use (&$uses, &$issues, $actors): void {
            if (is_string($presentation) && trim($presentation) !== '') {
                $uses[trim($presentation)][] = $where;
            } elseif (is_array($presentation) || $presentation instanceof PartyStageSelection) {
                try {
                    $selection = $presentation instanceof PartyStageSelection ? $presentation : PartyStageSelection::fromArray($presentation);
                    $bindings = $selection->toArray();
                    $subjects = array_keys($bindings['leaders']);
                    foreach ($bindings['parties'] as $binding) { $subjects = [...$subjects, ...$binding['actors']]; }
                    foreach (array_unique(array_map('strval', $subjects)) as $actor) {
                        if (! in_array($actor, $actors, true)) {
                            $issues[] = Issue::error($where, sprintf('Its rest presentation names unknown actor identity %s.', $actor),
                                'Choose a stable project actor identity; do not use a display name or guessed guest.');
                        }
                    }
                    foreach ($selection->getTimelineIds() as $timeline) {
                        $uses[$timeline][] = $where;
                    }
                } catch (Throwable $failure) {
                    $issues[] = Issue::error($where, 'Its rest presentation is invalid: ' . $failure->getMessage(),
                        'Choose explicit leader or party treatment and valid bindings; no implicit fallback is used.');
                }
            }
        };

        foreach ($workspace->maps as $map) {
            foreach ((array) ($map->data['events'] ?? []) as $marker => $definition) {
                if (is_array($definition) && str_ends_with(strval($definition['class'] ?? ''), 'SleepEventTrigger')) {
                    $name(((array) ($definition['data'] ?? []))['presentation'] ?? null, sprintf('map %s event %s', $map->mapId, strval($marker)));
                }
            }
        }

        self::visitCommands($workspace, $workspace->cutscenes?->assets(CutsceneType::CINEMATIC) ?? [], null,
            static function (array $command, string $where) use ($name): void {
                $definition = is_string($command['type'] ?? null) ? (ScriptCommandRegistry::getCatalog()->definitions[$command['type']] ?? null) : null;
                foreach ($definition?->fields ?? [] as $field) {
                    if ($field->reference !== ScriptCommandReference::STAGE_TIMELINE) { continue; }
                    $value = $command;
                    foreach (explode('.', $field->key) as $segment) { $value = is_array($value) ? ($value[$segment] ?? null) : null; }
                    $name($value, $where);
                }
            });
        $name($workspace->config?->getRecord(ProjectConfig::INN_PRESENTATION)->get('value'), 'config.php: ' . ProjectConfig::INN_PRESENTATION);

        return array_map(static fn(array $places): array => array_values(array_unique($places)), $uses);
    }

    /**
     * Returns the scripts whose `field_animation` commands play each effect:
     * map events, event scripts and cinematics, at any depth of branches,
     * lanes and choices, since the command is the same wherever it is
     * nested. What cannot be read names nothing here; validation reports it.
     *
     * @param iterable<CutsceneAsset> $cinematics The project's cinematics, as the editor holds them.
     * @return array<string, list<string>> Effect id to the scripts that play it.
     */
    public static function findScriptUses(ProjectWorkspace $workspace, iterable $cinematics): array
    {
        $uses = [];
        self::visitFieldAnimations($workspace, $cinematics, static function (array $command, string $where) use (&$uses): void {
            if (is_string($command['effect'] ?? null) && $command['effect'] !== '') {
                $uses[$command['effect']][] = $where;
            }
        });

        return array_map(static fn(array $places): array => array_values(array_unique($places)), $uses);
    }

    /**
     * Visits every `field_animation` command a script plays: map events,
     * event scripts and cinematics, at any depth of branches, lanes and
     * choices, since the command is the same wherever it is nested. What
     * cannot be read is not visited; validation reports it.
     *
     * @param iterable<CutsceneAsset> $cinematics The project's cinematics, as the editor holds them.
     * @param \Closure(array<array-key, mixed>, string): void $visit Given the command and where it is.
     */
    public static function visitFieldAnimations(ProjectWorkspace $workspace, iterable $cinematics, \Closure $visit): void
    {
        self::visitCommands($workspace, $cinematics, 'field_animation', $visit);
    }

    /**
     * Visits every command of a type a script runs: map events, event
     * scripts and cinematics, at any depth of branches, lanes and choices.
     * What cannot be read is not visited; validation reports it.
     *
     * @param iterable<CutsceneAsset> $cinematics The project's cinematics, as the editor holds them.
     * @param \Closure(array<array-key, mixed>, string): void $visit Given the command and where it is.
     */
    private static function visitCommands(ProjectWorkspace $workspace, iterable $cinematics, ?string $type, \Closure $visit): void
    {
        $collect = static function (mixed $value, string $where) use (&$collect, $visit, $type): void {
            if (! is_array($value)) {
                return;
            }

            if (($type === null && is_string($value['type'] ?? null)) || ($type !== null && ($value['type'] ?? null) === $type)) {
                $visit($value, $where);
            }

            foreach ($value as $nested) {
                $collect($nested, $where);
            }
        };

        foreach ($workspace->maps as $map) {
            foreach ((array) ($map->data['events'] ?? []) as $marker => $definition) {
                $collect($definition, sprintf('map %s event %s', $map->mapId, strval($marker)));
            }
            foreach ((array) ($map->data['npcs'] ?? []) as $index => $npc) {
                $id = is_array($npc) && is_string($npc['id'] ?? null) ? $npc['id'] : strval($index);
                $collect($npc, sprintf('map %s NPC %s', $map->mapId, $id));
            }
        }

        foreach ($workspace->getRecordDatabase('common_events')?->getRecords() ?? [] as $record) {
            $script = (array) $record->toArray();
            $scriptId = trim(strval($script['__scriptId'] ?? ''));
            $collect($script, sprintf('event script %s', $scriptId !== '' ? $scriptId : '(unnamed)'));
        }

        foreach ($cinematics as $cinematic) {
            $collect([$cinematic->data(), $cinematic->partner()], sprintf('cinematic %s', $cinematic->id));
        }
    }
}
