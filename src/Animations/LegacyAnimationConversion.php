<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Animations;

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourceNode;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;
use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Database\PhpValueExporter;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Editor\Validation\EffectValidator;
use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\Timelines\EffectCadence;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Animations\Timelines\LegacyAnimationMigration;
use Ichiloto\Engine\Entities\Skills\SkillRecord;
use InvalidArgumentException;
use RuntimeException;

/**
 * Converts a legacy cell-frame animation record into an effect timeline, as
 * one reversible source set: the new `Animations/<id>/<id>.timeline.php`,
 * made by the Engine's {@see LegacyAnimationMigration}, and, when a battle
 * should play it, the record's caster or target effect naming it.
 *
 * Timing belongs to the consumer, so nothing here supplies a rate: the
 * author chooses battle-phase cadence (a battle paces it) or a fixed rate,
 * the ticks each original frame lasts, and the rest frame shown with
 * reduced motion. The record's legacy frames and cues stay as written; the
 * compatibility reader still plays them wherever nothing names the
 * timeline. Field scripts that play the record keep doing so until their
 * own command is pointed at the timeline; a field consumer's timing may
 * differ from a battle's, and one record may need several timelines.
 *
 * Planning writes nothing. It refuses, before any write, a record with
 * nothing to convert, a timeline name already taken, a binding the record
 * already has, a timing the Engine cannot compile for its consumer, and an
 * animations file whose source the editor cannot edit in place.
 */
final class LegacyAnimationConversion
{
    /** Where the record says a battle plays a timeline. */
    public const array BINDINGS = ['sourceEffect', 'targetEffect'];

    private const string ANIMATIONS = 'assets/Data/animations.php';

    /**
     * What converting the record would touch: its legacy content, its
     * current bindings, and every consumer that plays it now.
     *
     * @return array{id: int, name: string, frames: int, maxFrames: int, position: string, cues: int, flashes: int,
     *   sourceEffect: ?string, targetEffect: ?string, battle: list<string>, field: list<string>}
     * @throws InvalidArgumentException When there is no such record, or it has nothing to convert.
     */
    public static function describe(ProjectWorkspace $workspace, int $id): array
    {
        $entry = self::requireEntry(self::readEntries($workspace->projectRoot), $id)[1];
        $animation = Animation::fromArray($entry);
        $cues = array_filter(array_map(static fn(int $frame): mixed => $animation->getCue($frame), range(1, $animation->maxFrames)));

        return [
            'id' => $id,
            'name' => $animation->name,
            // The frames authored; the rest of the frame count are blank holds.
            'frames' => count(array_filter((array) ($entry['frames'] ?? []), is_array(...))),
            'maxFrames' => $animation->maxFrames,
            'position' => $animation->position->value,
            'cues' => count($cues),
            'flashes' => count(array_filter($cues, static fn($cue): bool => $cue->flashColor !== null && $cue->flashDurationFrames > 0)),
            'sourceEffect' => is_string($entry['sourceEffect'] ?? null) ? $entry['sourceEffect'] : null,
            'targetEffect' => is_string($entry['targetEffect'] ?? null) ? $entry['targetEffect'] : null,
            'battle' => self::findBattleConsumers($workspace, $id, $animation),
            'field' => self::findFieldConsumers($workspace, $id, $animation->name),
        ];
    }

    /**
     * Plans the conversion without writing.
     *
     * @param EffectCadence $cadence Battle-phase cadence (paced by a battle) or a fixed rate.
     * @param int|null $fps The fixed rate; required for fixed cadence, refused for battle-phase.
     * @param int $ticksPerFrame How many timeline frames each original frame lasts.
     * @param int $restFrame The original frame (zero-based, flash tails included) shown with reduced motion.
     * @param bool $includeFlash Whether the record's flash cues become flash tracks.
     * @param string|null $binding `sourceEffect` or `targetEffect` when a battle should play it, or null.
     * @throws InvalidArgumentException With the reason, when the conversion cannot be made as asked.
     */
    public static function plan(ProjectWorkspace $workspace, int $id, string $timelineId, EffectCadence $cadence, ?int $fps,
        int $ticksPerFrame, int $restFrame, bool $includeFlash, ?string $binding): SourceSetPlan
    {
        $root = $workspace->projectRoot;
        $entries = self::readEntries($root);
        [$index, $entry] = self::requireEntry($entries, $id);
        if ($binding !== null && ! in_array($binding, self::BINDINGS, true)) {
            throw new InvalidArgumentException(sprintf('A battle plays a timeline as the record\'s %s, not %s.', implode(' or ', self::BINDINGS), $binding));
        }
        if ($binding !== null && is_string($entry[$binding] ?? null) && $entry[$binding] !== '') {
            throw new InvalidArgumentException(sprintf('%s already plays %s as its %s; convert it for another consumer or clear that first.',
                strval($entry['name'] ?? $id), $entry[$binding], $binding));
        }
        if ($binding === null && $cadence === EffectCadence::BATTLE_PHASE) {
            throw new InvalidArgumentException('Only a battle paces a timeline by its phases; bind it to the record for battle, or give it a fixed rate.');
        }
        EffectTimelineLibrary::assertId($timelineId);
        $folder = $root . '/assets/' . EffectTimelineLibrary::DIRECTORY . '/' . $timelineId;
        $timelinePath = $folder . '/' . $timelineId . '.timeline.php';
        if (file_exists($folder)) {
            throw new InvalidArgumentException(sprintf('A timeline named %s already exists; choose a name of its own.', $timelineId));
        }

        $data = LegacyAnimationMigration::getTimelineData($timelineId, Animation::fromArray($entry), $cadence, $ticksPerFrame, $restFrame, $fps, $includeFlash);
        if ($binding === null) {
            // A field consumer compiles it outside battle, where battle-only keys are refused.
            new EffectTimelineLibrary('')->compile($timelineId, $data);
        }
        $timelineSource = "<?php\n\nreturn " . PhpValueExporter::export($data) . ";\n";

        $path = $root . '/' . self::ANIMATIONS;
        $source = (string) file_get_contents($path);
        $proposed = $source;
        $after = $entries;
        if ($binding !== null) {
            $proposed = self::bindInSource($source, $index, $binding, $timelineId);
            $after[$index][$binding] = $timelineId;
        }

        $readBack = static fn(string $destination, string $staged): mixed => PhpDataFile::getComparableValue(PhpDataFile::evaluateIsolated($staged, $root));

        // The record changes only when a battle is to play the timeline.
        $record = $binding === null ? [] : [$path => $source];

        return new SourceSetPlan(
            $root,
            [$path => $source],
            [...$record, $timelinePath => null],
            [...($binding === null ? [] : [$path => $proposed]), $timelinePath => $timelineSource],
            [...($binding === null ? [] : [$path => PhpDataFile::getComparableValue($entries)]), $timelinePath => null],
            [...($binding === null ? [] : [$path => PhpDataFile::getComparableValue($after)]), $timelinePath => PhpDataFile::getComparableValue($data)],
            static fn(): array => is_file($path) ? [$path] : [],
            $readBack,
            'animation conversion',
            'The animations file',
        );
    }

    /** @return list<mixed> The animations file's records, as the runtime reads them. */
    private static function readEntries(string $root): array
    {
        $file = PhpDataFile::load($root . '/' . self::ANIMATIONS, $root);
        if (! is_array($file->payload) || ! array_is_list($file->payload)) {
            throw new InvalidArgumentException(sprintf('%s is not a list of animation records the editor can read.', self::ANIMATIONS));
        }

        return $file->payload;
    }

    /**
     * @param list<mixed> $entries
     * @return array{0: int, 1: array<string, mixed>} The record's position and data.
     */
    private static function requireEntry(array $entries, int $id): array
    {
        foreach ($entries as $index => $entry) {
            if (is_array($entry) && ($entry['id'] ?? null) === $id) {
                if (array_filter((array) ($entry['frames'] ?? [])) === [] && array_filter((array) ($entry['cues'] ?? [])) === []) {
                    throw new InvalidArgumentException(sprintf('%s has no legacy frames or cues to convert.', strval($entry['name'] ?? $id)));
                }

                return [$index, $entry];
            }
        }

        throw new InvalidArgumentException(sprintf('There is no animation %d.', $id));
    }

    /** The animations file with the record naming the timeline, edited in place. */
    private static function bindInSource(string $source, int $index, string $binding, string $timelineId): string
    {
        try {
            $document = PhpArraySourceDocument::parse($source);
        } catch (SourceUnreadable $unreadable) {
            throw new InvalidArgumentException(sprintf('%s cannot be edited in place: %s', self::ANIMATIONS, $unreadable->getMessage()), previous: $unreadable);
        }
        $record = $document->nodeAt([$index]);
        if ($record === null || $record->kind !== SourceNode::ARRAY || $record->hasOpaqueKey) {
            throw new InvalidArgumentException(sprintf('Animation record %d is not a literal array the editor can edit in place.', $index + 1));
        }

        try {
            return $document->withEdits([$document->insertEntryEdit([$index], count($record->entries), $binding, var_export($timelineId, true))])->source;
        } catch (RuntimeException $failure) {
            throw new InvalidArgumentException(sprintf('%s cannot take the binding in place: %s', self::ANIMATIONS, $failure->getMessage()), previous: $failure);
        }
    }

    /** @return list<string> The skills and items that play the record in battle, and the roles it holds. */
    private static function findBattleConsumers(ProjectWorkspace $workspace, int $id, Animation $animation): array
    {
        $consumers = [];
        foreach ($workspace->getRecordDatabase('skills')?->getRecords() ?? [] as $record) {
            try {
                $skill = SkillRecord::readSkill((array) $record->toArray());
            } catch (InvalidArgumentException) {
                continue;
            }
            if ($skill->animationId === $id) {
                $consumers[] = sprintf('skill %s', $skill->name);
            }
        }
        foreach ($workspace->getRecordDatabase('items')?->getRecords() ?? [] as $item) {
            if ($item->get('animationId') === $id) {
                $consumers[] = sprintf('item %s', strval($item->get('name')));
            }
        }
        foreach ($animation->roles as $role) {
            $consumers[] = sprintf('every action with role %s', $role);
        }

        return $consumers;
    }

    /** @return list<string> The scripts whose field animations play the record, by id or by name. */
    private static function findFieldConsumers(ProjectWorkspace $workspace, int $id, string $name): array
    {
        $uses = [];
        EffectValidator::visitFieldAnimations($workspace, $workspace->cutscenes?->assets(CutsceneType::CINEMATIC) ?? [],
            static function (array $command, string $where) use (&$uses, $id, $name): void {
                // A legacy reference names the record by id or by name.
                if (in_array($command['animation'] ?? $command['id'] ?? null, [$id, $name], true)) {
                    $uses[] = $where;
                }
            });

        return array_values(array_unique($uses));
    }
}
