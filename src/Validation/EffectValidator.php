<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Animations\Field\FieldEffectManager;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Throwable;

/**
 * Checks every effect timeline the project uses, as the Engine plays it for
 * each consumer: battle animations' source and target effects in battle;
 * the field presentation's cue and action prompt effects, maps' field
 * effects and tileset pieces' effects on the field. Each is compiled for
 * both the terminal and the graphical presentation, so a sequence that only
 * one renderer would refuse is still found.
 */
final class EffectValidator
{
    private const string FIELD_PRESENTATION = 'assets/Data/Presentation/field.php';

    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $assetRoot = $workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets';
        $issues = [];
        /** @var array<string, array{battle: list<string>, field: list<string>}> $uses */
        $uses = [];
        $use = static function (string $effect, bool $battle, string $where) use (&$uses): void {
            $uses[$effect][$battle ? 'battle' : 'field'][] = $where;
        };

        foreach ($workspace->animationDatabase->getAnimations() as $animation) {
            // Read from the authored entry, which keeps the bindings whatever the Engine's Animation carries.
            $entry = $workspace->animationDatabase->getAuthoredEntry($animation) ?? [];

            foreach (['sourceEffect' => $entry['sourceEffect'] ?? null, 'targetEffect' => $entry['targetEffect'] ?? null] as $field => $effect) {
                if (is_string($effect) && $effect !== '') {
                    $use($effect, true, sprintf('assets/Data/animations.php: %s %s', $animation->name, $field));
                }
            }
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

        $library = new EffectTimelineLibrary($assetRoot);
        ksort($uses);

        foreach ($uses as $effect => $contexts) {
            foreach ($contexts as $context => $places) {
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
}
