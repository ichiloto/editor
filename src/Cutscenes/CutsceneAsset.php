<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes;

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;
use Ichiloto\Editor\Storage\FileSetTransaction;
use Ichiloto\Editor\Storage\FileSetTransactionFailure;
use Ichiloto\Editor\Database\InnPresentationFields;
use Ichiloto\Editor\Database\PhpValueExporter;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\History\TracksPersistedState;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectCadence;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use RuntimeException;
use Throwable;

/**
 * One cutscene: a stable id, one folder, and the two files that are one
 * logical asset.
 *
 * A cinematic is `<id>.data.php` and `<id>.script.php`; a summon is
 * `<id>.data.php` and `<id>.timeline.php`. The editor holds both as the
 * arrays they evaluate to and as the bytes they were authored in, edits the
 * arrays, and on save writes back only the bytes each change needs -- data
 * edits never touch the partner file, partner edits never touch the data
 * file, and a clean save writes nothing. Both files are proposed, evaluated,
 * hydrated through the engine and only then replaced, together; if any step
 * fails, neither existing file changes.
 *
 * Identity is the folder, captured once. A display-name edit is a field
 * edit; it never renames the folder or the files.
 *
 * @package Ichiloto\Editor\Cutscenes
 */
final class CutsceneAsset
{
    use TracksPersistedState;

    /**
     * The hidden payload key that tells a written record which asset it was
     * read from. Never shown, never written to a file.
     */
    public const string ORIGIN_KEY = '__cutscene';

    /**
     * The payload key a cinematic's script commands are edited under.
     */
    public const string COMMANDS_KEY = 'commands';

    /**
     * The hidden payload key naming the sequence a record with separate
     * terminal and graphical sequences shows, so its schema offers the rows
     * that sequence reads (a summon's stage is graphical only). Never shown,
     * never written to a file.
     */
    public const string SEQUENCE_KEY = '__sequence';

    /** The canvas a new summon stage starts with, in stage units. */
    private const array STAGE_CANVAS = ['width' => 1280, 'height' => 720];

    /**
     * @var array<string, mixed> The data file's array as the editor now holds it.
     */
    private array $data;

    /**
     * @var array<int|string, mixed> The partner file's array as the editor now holds it.
     */
    private array $partner;

    /**
     * @var array<string, mixed> The data file's array as last read or written.
     */
    private array $loadedData;

    /**
     * @var array<int|string, mixed> The partner file's array as last read or written.
     */
    private array $loadedPartner;

    /**
     * How this pair's two files merge into one record and split back into
     * two, and whether it can make that round trip without loss.
     */
    private CutscenePairShape $shape;

    private ?PhpArraySourceDocument $dataDocument = null;
    private ?PhpArraySourceDocument $partnerDocument = null;

    /** @var array<string, string> Authored bytes at load/save, even for read-only sources. */
    private array $baselineSources = [];

    /** @var array<string, array{source: string, values: array<array-key, mixed>}> Undo templates, never persisted conflict/validation baselines. */
    private array $sourceTemplates = [];

    /**
     * The sequence an effect or summon with separate terminal and graphical
     * sequences is being edited in. The editor shows and edits one at a
     * time; the file keeps both, each exactly as written unless it is the
     * one edited.
     */
    private EffectPresentation $presentationView = EffectPresentation::TERMINAL;
    /**
     * @var array<string, mixed> An effect sequence's own FPS while the battle
     * paces it, by sequence ('flat', 'terminal' or 'graphical'), so choosing
     * fixed cadence again gives it back.
     */
    private array $pacedFps = [];
    private ?string $readOnlyReason = null;
    private bool $isNew = false;
    private bool $isDeleted = false;

    /**
     * @param CutsceneType $type The cutscene form.
     * @param string $id The stable id, which is the folder name.
     * @param string $folder The absolute folder.
     * @param string|null $projectRoot The project the asset belongs to.
     */
    private function __construct(
        public readonly CutsceneType $type,
        public readonly string $id,
        public readonly string $folder,
        private readonly ?string $projectRoot,
    ) {
    }

    /**
     * Reads an asset from its folder.
     *
     * The pair must be complete: a folder holding one file without the
     * other is an orphan the library reports rather than an asset.
     *
     * @param CutsceneType $type The form.
     * @param string $folder The absolute folder.
     * @param string|null $projectRoot The project root.
     * @return self The asset; read-only, with a reason, when a file cannot be read safely.
     */
    public static function load(CutsceneType $type, string $folder, ?string $projectRoot = null): self
    {
        $asset = new self($type, basename($folder), rtrim($folder, '/'), $projectRoot);
        $dataPath = $asset->dataPath();
        $partnerPath = $asset->partnerPath();

        $hasData = $type->hasDataFile();

        if (($hasData && ! is_file($dataPath)) || ! is_file($partnerPath)) {
            throw new RuntimeException(sprintf('%s "%s" is missing %s.', ucfirst($type->noun()), $asset->id, ! is_file($partnerPath) ? basename($partnerPath) : basename($dataPath)));
        }

        $dataSource = $hasData ? (string) file_get_contents($dataPath) : '';
        $partnerSource = (string) file_get_contents($partnerPath);
        $asset->baselineSources = $hasData
            ? [$dataPath => $dataSource, $partnerPath => $partnerSource]
            : [$partnerPath => $partnerSource];
        $reasons = [];

        try {
            // An effect has no data file; all of it is its timeline.
            $data = $hasData ? $asset->evaluate($dataPath) : [];
        } catch (Throwable $throwable) {
            $data = [];
            $reasons[] = sprintf('%s could not be evaluated (%s)', basename($dataPath), $throwable->getMessage());
        }

        try {
            $partner = $asset->evaluate($partnerPath);
        } catch (Throwable $throwable) {
            $partner = [];
            $reasons[] = sprintf('%s could not be evaluated (%s)', basename($partnerPath), $throwable->getMessage());
        }

        if (! is_array($data)) {
            $reasons[] = sprintf('%s does not return an array', basename($dataPath));
            $data = [];
        }

        if (! is_array($partner)) {
            $reasons[] = sprintf('%s does not return an array', basename($partnerPath));
            $partner = [];
        }

        try {
            $asset->dataDocument = $hasData ? PhpArraySourceDocument::parse($dataSource) : null;
        } catch (SourceUnreadable $unreadable) {
            $reasons[] = sprintf('%s cannot be rewritten in place: %s', basename($dataPath), rtrim($unreadable->getMessage(), '.'));
        }

        try {
            $asset->partnerDocument = PhpArraySourceDocument::parse($partnerSource);
        } catch (SourceUnreadable $unreadable) {
            $reasons[] = sprintf('%s cannot be rewritten in place: %s', basename($partnerPath), rtrim($unreadable->getMessage(), '.'));
        }

        $declared = trim(strval($data['id'] ?? ''));

        if ($declared !== '' && $declared !== $asset->id) {
            $reasons[] = sprintf('the folder is "%s" but the data file declares id "%s"', $asset->id, $declared);
        }

        $asset->adoptArrays($data, $partner);

        // The pair is offered as writable only when the editor can prove it
        // reads both files into one record and writes that record back as
        // the same two files. A shape it would normalise is shown and left
        // alone: an edit to any asset must never reshape this one.
        $irreversible = $asset->shape->refusalFor($data, $partner);

        if ($irreversible !== null) {
            $reasons[] = $irreversible;
        }

        $asset->readOnlyReason = $reasons === [] ? null : implode('; ', $reasons);
        $asset->captureBaseline();

        return $asset;
    }

    /**
     * Starts an asset that has no folder yet, from a payload the workspace
     * chose for it. It is dirty until saved, and saving creates the folder.
     *
     * @param CutsceneType $type The form.
     * @param string $id The stable id.
     * @param string $root The absolute folder holding this type's assets.
     * @param array<string, mixed> $payload The initial payload, partner keys included.
     * @param string|null $projectRoot The project root.
     */
    public static function create(CutsceneType $type, string $id, string $root, array $payload, ?string $projectRoot = null): self
    {
        $asset = new self($type, $id, rtrim($root, '/') . '/' . $id, $projectRoot);
        $asset->isNew = true;
        $asset->shape = CutscenePairShape::forNewAsset($type);

        if (! $type->hasDataFile()) {
            unset($payload['id']);
        }
        [$data, $partner] = $asset->shape->split($payload);
        $asset->shape = CutscenePairShape::of($type, $data, $partner);
        $asset->data = $data;
        $asset->partner = $partner;
        $asset->loadedData = [];
        $asset->loadedPartner = [];
        // A never-saved asset fingerprints against nothing, so it is dirty.
        $asset->persistedFingerprint = null;
        $asset->touchState();

        return $asset;
    }

    /**
     * Returns the absolute path of the data file.
     */
    public function dataPath(): string
    {
        return $this->folder . '/' . $this->id . '.data.php';
    }

    /**
     * Returns the absolute path of the partner file: the script or the timeline.
     */
    public function partnerPath(): string
    {
        return $this->folder . '/' . $this->id . $this->type->partnerSuffix();
    }

    /**
     * Returns the files a save of this asset would write.
     *
     * @return string[]
     */
    public function paths(): array
    {
        return $this->type->hasDataFile() ? [$this->dataPath(), $this->partnerPath()] : [$this->partnerPath()];
    }

    /**
     * Returns why the asset cannot be written, or null when it can.
     */
    public function readOnlyReason(): ?string
    {
        return $this->readOnlyReason;
    }

    public function isEditable(): bool
    {
        return $this->readOnlyReason === null;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    public function isDeleted(): bool
    {
        return $this->isDeleted;
    }

    /**
     * Marks the asset for deletion on the next save, or takes that back.
     */
    public function markDeleted(bool $deleted): void
    {
        $this->isDeleted = $deleted;
        $this->touchState();
    }

    /**
     * Returns the display name, or the id when none is authored.
     */
    public function name(): string
    {
        // An effect is named by its id; its timeline holds no name.
        $name = trim(strval($this->data['name'] ?? ''));

        return $name !== '' ? $name : $this->id;
    }

    /**
     * Returns the data file's array as the editor holds it.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * Returns the partner file's array as the editor holds it: a cinematic's
     * script (list, or map with `commands`), a summon's timeline.
     *
     * @return array<int|string, mixed>
     */
    public function partner(): array
    {
        return $this->partner;
    }

    /**
     * Returns a cinematic's commands, whatever shape the script file has.
     *
     * @return array<int, mixed>
     */
    public function commands(): array
    {
        return $this->shape->commandsOf($this->partner);
    }

    /**
     * Returns the one array the editor edits: the data file's keys, plus a
     * cinematic's `commands` or a summon's timeline keys, plus the origin
     * marker that ties a written record back to this asset.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = match (true) {
            ! $this->hasPresentations() => $this->shape->merge($this->data, $this->partner),
            // An effect is its timeline alone: the sequence is the record.
            $this->type === CutsceneType::EFFECT => $this->getPresentationSequence($this->partner),
            // A summon is its definition, the timeline's own keys and the sequence.
            default => [
                ...$this->shape->merge($this->data, array_diff_key($this->partner, ['presentations' => true])),
                ...$this->getPresentationSequence($this->partner),
            ],
        };

        if (! $this->type->hasDataFile()) {
            // An effect's id is its folder, as the Engine finds it; it is
            // shown and renamed here like any id, and never written.
            $payload = ['id' => $this->id, ...$payload];
        }

        if ($this->hasPresentations()) {
            $payload[self::SEQUENCE_KEY] = $this->presentationView->value;
        }

        $payload[self::ORIGIN_KEY] = $this->id;

        return $payload;
    }

    /**
     * Whether this is an effect or a summon with separate terminal and
     * graphical sequences, edited one at a time.
     */
    public function hasPresentations(): bool
    {
        return $this->type !== CutsceneType::CINEMATIC && is_array($this->partner['presentations'] ?? null);
    }

    /** The sequence being edited, for an effect or summon with separate sequences; null otherwise. */
    public function getPresentationView(): ?EffectPresentation
    {
        return $this->hasPresentations() ? $this->presentationView : null;
    }

    /**
     * Chooses which of the sequences the editor shows and edits. Nothing is
     * written; the other sequence stays as it is.
     */
    public function selectPresentation(EffectPresentation $presentation): void
    {
        $this->presentationView = $presentation;
    }

    /**
     * Gives a flat effect or summon separate terminal and graphical
     * sequences, each starting as a copy of the flat one, so each
     * presentation can then be authored on its own. A summon keeps its
     * format version and editor metadata beside them, where the Engine reads
     * them. A flat timeline stays flat unless this is chosen.
     *
     * @return bool False when it already has them, is a cinematic, or cannot be written.
     */
    public function splitIntoPresentations(): bool
    {
        if ($this->type === CutsceneType::CINEMATIC || $this->hasPresentations() || $this->readOnlyReason !== null) {
            return false;
        }
        $this->assertSourcesUnchanged();

        $outer = $this->type === CutsceneType::SUMMON
            ? array_intersect_key($this->partner, array_flip(SummonCutsceneDefinition::PAIRED_TIMELINE_FIELDS))
            : [];
        $sequence = array_diff_key($this->partner, $outer);
        $presentations = [EffectPresentation::TERMINAL->value => $sequence, EffectPresentation::GRAPHICAL->value => $sequence];
        $partner = [];

        // The sequences take the place the first sequence key had.
        foreach ($this->partner as $key => $value) {
            if (array_key_exists($key, $outer)) {
                $partner[$key] = $value;
            } elseif (! array_key_exists('presentations', $partner)) {
                $partner['presentations'] = $presentations;
            }
        }

        $this->partner = $partner + ['presentations' => $presentations];
        $this->touchState();

        return true;
    }

    /**
     * Whether the graphical effect or summon owns a cinematic stage.
     */
    public function hasStage(): bool
    {
        return in_array($this->type, [CutsceneType::SUMMON, CutsceneType::EFFECT], true)
            && ($this->type === CutsceneType::EFFECT || $this->hasPresentations())
            && is_array(($this->hasPresentations()
                ? $this->getSequence(EffectPresentation::GRAPHICAL) : $this->partner)['stage'] ?? null);
    }

    /**
     * Gives a graphical effect or summon a cinematic stage, or takes it
     * away. A new stage is the smallest the Engine reads: a 16:9 canvas
     * shown from the first frame until the last, its camera on the canvas's
     * centre at its own scale. Its subjects, art, covers and the sequence's
     * rest frame are authored after. The terminal sequence never has one.
     *
     * @return bool False when nothing changed: ineligible, read-only, too
     * short to restore before its end, or already so. Existing tracks/cues
     * are preserved; incomplete authoring must compile before it can save.
     */
    public function setStage(bool $present): bool
    {
        $graphical = EffectPresentation::GRAPHICAL->value;

        if (! in_array($this->type, [CutsceneType::SUMMON, CutsceneType::EFFECT], true)
            || ($this->type === CutsceneType::SUMMON && ! $this->hasPresentations())
            || $this->readOnlyReason !== null || $present === $this->hasStage()) {
            return false;
        }
        $this->assertSourcesUnchanged();

        $sequence = $this->hasPresentations() ? $this->getSequence(EffectPresentation::GRAPHICAL) : $this->partner;

        if (! $present) {
            unset($sequence['stage']);
        } else {
            $length = (int) ($sequence['lengthFrames'] ?? 0);

            if ($length < 2) {
                return false;
            }

            $stage = [
                'canvas' => self::STAGE_CANVAS,
                'startFrame' => 0,
                'restoreFrame' => $length - 1,
                'camera' => [['id' => 'initial', 'frame' => 0,
                    'focus' => ['x' => intdiv(self::STAGE_CANVAS['width'], 2), 'y' => intdiv(self::STAGE_CANVAS['height'], 2)], 'zoom' => 1]],
            ];
            // Where the Engine writes it: after the sequence's clock, before its tracks.
            $placed = [];

            foreach ($sequence as $key => $value) {
                if (in_array($key, ['tracks', 'cues'], true) && ! array_key_exists('stage', $placed)) {
                    $placed['stage'] = $stage;
                }

                $placed[$key] = $value;
            }

            $sequence = $placed + ['stage' => $stage];
        }

        $partner = $this->partner;
        if ($this->hasPresentations()) {
            $partner['presentations'][$graphical] = $sequence;
        } else {
            $partner = $sequence;
        }
        $this->partner = $partner;
        $this->touchState();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function getSequence(EffectPresentation $presentation): array
    {
        $sequence = $this->partner['presentations'][$presentation->value] ?? [];

        return is_array($sequence) ? $sequence : [];
    }

    /**
     * Starts a new asset with this one's files under another id: both
     * sequences of one that has two, whichever is shown.
     */
    public function copyAs(string $id, string $root): self
    {
        $this->assertSourcesUnchanged();
        $copy = new self($this->type, $id, rtrim($root, '/') . '/' . $id, $this->projectRoot);
        $copy->isNew = true;
        $copy->data = $this->type->hasDataFile() && array_key_exists('id', $this->data)
            ? [...$this->data, 'id' => $id]
            : ($this->type->hasDataFile() ? ['id' => $id, ...$this->data] : $this->data);
        $copy->partner = $this->partner;
        $copy->presentationView = $this->presentationView;
        $copy->pacedFps = $this->pacedFps;
        $copy->shape = CutscenePairShape::of($this->type, $copy->data, $copy->partner);
        $copy->loadedData = [];
        $copy->loadedPartner = [];
        $copy->persistedFingerprint = null;
        $copy->touchState();

        return $copy;
    }

    /**
     * Returns everything an edit can change, for undo and redo: both files'
     * arrays, the sequence being edited and whether the asset is deleted.
     * The payload alone is not enough: it is one sequence of an effect with
     * two, and a split leaves it unchanged.
     *
     * @return array{data: array<string, mixed>, partner: array<int|string, mixed>, view: EffectPresentation, deleted: bool, pacedFps: array<string, mixed>, sourceTemplates: array<string, array{source: string, values: array<array-key, mixed>}>}
     */
    public function captureEditState(): array
    {
        $templates = [];
        if ($this->readOnlyReason === null) {
            if ($this->type->hasDataFile()) {
                $templates['data'] = ['source' => $this->proposedSource($this->dataDocument, $this->loadedData, $this->data, 'data'), 'values' => $this->data];
            }
            $templates[$this->type->partnerNoun()] = ['source' => $this->proposedSource($this->partnerDocument, $this->loadedPartner,
                $this->partner, $this->type->partnerNoun()), 'values' => $this->partner];
        }

        return ['data' => $this->data, 'partner' => $this->partner, 'view' => $this->presentationView, 'deleted' => $this->isDeleted,
            'pacedFps' => $this->pacedFps, 'sourceTemplates' => $templates];
    }

    /**
     * Puts the asset back to a state `captureEditState` returned.
     *
     * @param array{data: array<string, mixed>, partner: array<int|string, mixed>, view: EffectPresentation, deleted: bool, pacedFps?: array<string, mixed>, sourceTemplates?: array<string, array{source: string, values: array<array-key, mixed>}>} $state
     */
    public function restoreEditState(array $state): void
    {
        // Retain the actual last-saved baseline for conflict checks and
        // changed-reference validation, while recovering authored syntax.
        $this->sourceTemplates = $state['sourceTemplates'] ?? [];
        $this->presentationView = $state['view'];
        $this->pacedFps = $state['pacedFps'] ?? [];
        $this->markDeleted($state['deleted']);

        if ($this->readOnlyReason !== null || ($state['data'] === $this->data && $state['partner'] === $this->partner)) {
            return;
        }

        $this->data = $state['data'];
        $this->partner = $state['partner'];
        $this->shape = CutscenePairShape::of($this->type, $this->data, $this->partner);
        $this->touchState();
    }

    /**
     * A sequence the battle paces has no FPS of its own: the battle phase it
     * plays in sets its timing, and the Engine refuses one. Choosing
     * battle_phase cadence takes the sequence's FPS out and keeps it;
     * choosing fixed again puts it back, first, as sequences write it.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function settleEffectCadence(array $payload): array
    {
        $sequence = $this->hasPresentations() ? $this->presentationView->value : 'flat';

        if (($payload['cadence'] ?? null) === EffectCadence::BATTLE_PHASE->value) {
            if (array_key_exists('fps', $payload)) {
                $this->pacedFps[$sequence] = $payload['fps'];
                unset($payload['fps']);
            }
        } elseif (! array_key_exists('fps', $payload) && array_key_exists($sequence, $this->pacedFps)) {
            $payload = ['fps' => $this->pacedFps[$sequence], ...$payload];
            unset($this->pacedFps[$sequence]);
        }

        return $payload;
    }

    /**
     * @param array<int|string, mixed> $timeline
     * @return array<string, mixed>
     */
    private function getPresentationSequence(array $timeline): array
    {
        $sequence = $timeline['presentations'][$this->presentationView->value] ?? [];

        return is_array($sequence) ? $sequence : [];
    }

    /**
     * Takes an edited payload back into the data and partner arrays.
     *
     * Every key goes to the file it was read from; a key neither file held
     * goes where its name belongs -- a timeline field to the timeline, the
     * commands to the script, anything else to the data file.
     *
     * @param array<string, mixed> $payload The payload, origin marker included or not.
     */
    public function apply(array $payload): void
    {
        if ($this->readOnlyReason !== null) {
            // A pair the editor cannot reverse exactly is never rewritten,
            // and the record write-back passes every asset through here --
            // including the ones an author was not editing.
            return;
        }
        $this->assertSourcesUnchanged();

        if (! $this->type->hasDataFile()) {
            unset($payload['id']);
        }

        if ($this->type === CutsceneType::EFFECT) {
            $payload = $this->settleEffectCadence($payload);
        }

        unset($payload[self::SEQUENCE_KEY]);

        if ($this->hasPresentations()) {
            // The edited sequence goes back into its place; the other
            // sequence, and the keys of this one, keep their order.
            unset($payload[self::ORIGIN_KEY]);
            $old = $this->getPresentationSequence($this->partner);
            $edited = $this->type === CutsceneType::EFFECT
                ? $payload
                : array_filter($payload, static fn(string $key): bool => array_key_exists($key, $old)
                    || (in_array($key, CinematicCommandSchema::SUMMON_TIMELINE_FIELDS, true) && ! in_array($key, SummonCutsceneDefinition::PAIRED_TIMELINE_FIELDS, true)),
                    ARRAY_FILTER_USE_KEY);
            $presentations = $this->partner['presentations'];
            $presentations[$this->presentationView->value] = array_intersect_key(array_replace(array_flip(array_keys($old)), $edited), $edited);

            if ($this->type === CutsceneType::EFFECT) {
                $partner = [...$this->partner, 'presentations' => $presentations];
                $data = $this->data;
            } else {
                // A summon's definition and the timeline's own keys split as a flat pair does.
                [$data, $partner] = $this->shape->split([...array_diff_key($payload, $edited), 'presentations' => $presentations]);
            }
        } else {
            [$data, $partner] = $this->shape->split($payload);
        }

        if ($data === $this->data && $partner === $this->partner) {
            return;
        }

        $this->data = $data;
        $this->partner = $partner;
        $this->touchState();
    }

    /**
     * Records the arrays the files hold and which file each key came from.
     *
     * @param array<string, mixed> $data
     * @param array<int|string, mixed> $partner
     */
    private function adoptArrays(array $data, array $partner): void
    {
        $this->loadedData = $data;
        $this->loadedPartner = $partner;
        $this->data = $data;
        $this->partner = $partner;
        $this->shape = CutscenePairShape::of($this->type, $data, $partner);
    }

    /** Checks the owner's loaded source set, allowing recovery but never external replacement. */
    public function assertSourcesUnchanged(): void
    {
        $this->createSourceTransaction()->assertSourcesUnchanged();
    }

    private function createSourceTransaction(): FileSetTransaction
    {
        $transaction = new FileSetTransaction($this->folder);
        foreach ($this->paths() as $path) {
            // Null requires an unwritten/deleted member to remain absent.
            $transaction->expectSource($path, $this->baselineSources[$path] ?? null, allowMissing: true);
        }
        return $transaction;
    }

    /** Checks both source edits without writing, so unsupported expressions never enter history. */
    public function assertSourceAccepts(): void
    {
        $this->assertSourcesUnchanged();
        if ($this->type->hasDataFile()) {
            $this->proposedSource($this->dataDocument, $this->loadedData, $this->data, 'data');
        }
        $this->proposedSource($this->partnerDocument, $this->loadedPartner, $this->partner, $this->type->partnerNoun());
    }

    /** Changed rest bindings must be complete; untouched graphical references survive Terminal edits. */
    public function assertInnPresentationsValid(?ReferenceCatalog $references = null): void
    {
        InnPresentationFields::assertChangedCommandsValid($this->loadedData, $this->data, $references);
        InnPresentationFields::assertChangedCommandsValid($this->loadedPartner, $this->partner, $references);
    }

    /** Only changed/new route paths must be complete before either source can be installed. */
    public function assertMovementRoutesValid(): void
    {
        \Ichiloto\Editor\Database\MovementRouteFields::assertChangedCommandsValid($this->loadedData, $this->data);
        \Ichiloto\Editor\Database\MovementRouteFields::assertChangedCommandsValid($this->loadedPartner, $this->partner);
    }

    /**
     * Writes both files as one logical transaction.
     *
     * The data source and the partner source are each rewritten only where
     * their arrays changed. Both proposed sources are staged beside the
     * originals, evaluated in the project and hydrated through the engine,
     * and only then installed by `FileSetTransaction` -- which takes the
     * backup once and, if either file cannot be installed, puts back every
     * file it had already touched. A deleted asset's pair is removed through
     * the same boundary. Nothing here advances until the whole operation
     * succeeds, so the pair on disk is always the complete old one or the
     * complete new one.
     *
     * @param callable(string ...$paths): void|null $backup Called with the paths about to be overwritten, before they are.
     * @param ReferenceCatalog|null $references The workspace's live authoring references.
     * @return bool True when anything was written.
     * @throws FileSetTransactionFailure When the pair could not be installed.
     */
    public function save(?callable $backup = null, ?ReferenceCatalog $references = null): bool
    {
        $transaction = $this->createSourceTransaction();
        $transaction->assertSourcesUnchanged();
        if ($this->isDeleted) {
            return $this->delete($backup);
        }

        $missing = array_any($this->paths(), static fn(string $path): bool => ! is_file($path));
        if (! $this->isDirty() && ! $this->isNew && ! $missing) {
            return false;
        }

        if ($this->readOnlyReason !== null) {
            throw new RuntimeException(sprintf('%s "%s" is read-only: %s.', ucfirst($this->type->noun()), $this->id, $this->readOnlyReason));
        }

        $this->assertInnPresentationsValid($references);
        $this->assertMovementRoutesValid();

        // 1. Both proposed sources, each touched only where it changed.
        $hasData = $this->type->hasDataFile();
        $dataSource = $hasData ? $this->proposedSource($this->dataDocument, $this->loadedData, $this->data, 'data') : '';
        $partnerSource = $this->proposedSource($this->partnerDocument, $this->loadedPartner, $this->partner, $this->type->partnerNoun());
        $writeData = $hasData && (! is_file($this->dataPath()) || $this->isNew || $this->dataDocument === null || $dataSource !== $this->dataDocument->source);
        $writePartner = ! is_file($this->partnerPath()) || $this->isNew || $this->partnerDocument === null || $partnerSource !== $this->partnerDocument->source;

        if (! $writeData && ! $writePartner) {
            // Dirty by fingerprint but identical in source: a same-value
            // round trip. Clean without writing.
            $this->adoptWritten($this->dataDocument?->source ?? $dataSource, $this->partnerDocument?->source ?? $partnerSource);

            return false;
        }

        if ($writeData) {
            $transaction->write($this->dataPath(), $dataSource);
        }

        if ($writePartner) {
            $transaction->write($this->partnerPath(), $partnerSource);
        }

        // 2. Stage both, then read back the exact bytes that will be
        //    installed -- evaluated where the game would evaluate them.
        $staged = $transaction->stage();

        try {
            $evaluatedData = $hasData
                ? $this->evaluateSide($staged[$this->dataPath()] ?? $this->dataPath(), $this->data, basename($this->dataPath()), 'data')
                : [];
            $evaluatedPartner = $this->evaluateSide($staged[$this->partnerPath()] ?? $this->partnerPath(), $this->partner, basename($this->partnerPath()), $this->type->partnerNoun());

            // 3. Hydrate and compile through the engine.
            $this->hydrate($evaluatedData, $evaluatedPartner);
        } catch (Throwable $throwable) {
            // Nothing has been installed: drop the staged copies and leave
            // both files exactly as they were.
            $transaction->rollBack();

            throw $throwable;
        }

        // 4. Back up once, then install the pair or restore it.
        $transaction->commit($backup);

        $this->adoptWritten($dataSource, $partnerSource);
        $this->isNew = false;

        return true;
    }

    /**
     * Evaluates one side of the pair as the game would read it after this
     * save, and proves it is the array the editor holds.
     *
     * @param string $path The staged copy when the file is being written, the file itself when it is not.
     * @param array<array-key, mixed> $expected What the editor holds for that side.
     * @return array<array-key, mixed> The evaluated array.
     */
    private function evaluateSide(string $path, array $expected, string $name, string $noun): array
    {
        $evaluated = $this->evaluate($path);

        if (! is_array($evaluated) || $evaluated !== $expected) {
            throw new RuntimeException(sprintf('%s would not read back as the edited %s; nothing was written.', $name, $noun));
        }

        return $evaluated;
    }

    /**
     * Hydrates the arrays through the engine, as save and validation both do.
     *
     * @param array<string, mixed> $data
     * @param array<int|string, mixed> $partner
     */
    public function hydrate(?array $data = null, ?array $partner = null): void
    {
        $data ??= $this->data;
        $partner ??= $this->partner;

        if ($this->type === CutsceneType::CINEMATIC) {
            CutsceneHydration::cinematic($data, $partner, $this->projectRoot);

            return;
        }

        if ($this->type === CutsceneType::EFFECT) {
            CutsceneHydration::checkEffect($this->id, $partner, $this->projectRoot);

            return;
        }

        // Both renderers play a summon; each must accept it as it stands.
        foreach (EffectPresentation::cases() as $presentation) {
            CutsceneHydration::compileSummon($data, $partner, $this->projectRoot, $presentation);
        }
    }

    /**
     * Compiles the effect as it stands in memory, unsaved edits included, as
     * battle, the field or an owned stage plays it in one presentation.
     *
     * @throws RuntimeException When the Engine refuses it, or for another type.
     */
    public function compileEffect(EffectPresentation $presentation, bool $forBattle, bool $forStage = false): CompiledEffectTimeline
    {
        if ($this->type !== CutsceneType::EFFECT) {
            throw new RuntimeException(sprintf('%s is a %s, not an effect.', $this->id, $this->type->noun()));
        }

        return CutsceneHydration::compileEffect($this->id, $this->partner, $presentation, $forBattle, $this->projectRoot, $forStage);
    }

    /** Preserves the existing battle/field API while new consumers use the action-named compiler. */
    public function compiledEffect(EffectPresentation $presentation, bool $forBattle): CompiledEffectTimeline
    {
        return $this->compileEffect($presentation, $forBattle);
    }

    /** Whether this effect declares its own graphical space, independent of its selected sequence. */
    public function isOwnedStageEffect(): bool
    {
        return $this->type === CutsceneType::EFFECT && CutsceneHydration::isOwnedStage($this->partner);
    }

    /**
     * Hydrates the cinematic as it stands in memory, unsaved edits included.
     *
     * @throws RuntimeException When the Engine refuses it, or for a summon.
     */
    public function cinematicDefinition(): CinematicDefinition
    {
        if ($this->type !== CutsceneType::CINEMATIC) {
            throw new RuntimeException(sprintf('%s is a summon, not a cinematic.', $this->id));
        }

        return CutsceneHydration::cinematic($this->data, $this->partner, $this->projectRoot);
    }

    /**
     * Hydrates the summon as it stands in memory, unsaved edits included.
     *
     * @throws RuntimeException When the Engine refuses it, or for a cinematic.
     */
    public function summonDefinition(): SummonCutsceneDefinition
    {
        if ($this->type !== CutsceneType::SUMMON) {
            throw new RuntimeException(sprintf('%s is a cinematic, not a summon.', $this->id));
        }

        return CutsceneHydration::summon($this->data, $this->partner, $this->projectRoot);
    }

    /**
     * Compiles the summon as it stands in memory, unsaved edits included, as
     * one renderer plays it: the terminal by default, as the editors preview it.
     *
     * @throws RuntimeException When the Engine refuses it, or for a cinematic.
     */
    public function compiledSummon(EffectPresentation $presentation = EffectPresentation::TERMINAL): SummonCompiledCutscene
    {
        if ($this->type !== CutsceneType::SUMMON) {
            throw new RuntimeException(sprintf('%s is a cinematic, not a summon.', $this->id));
        }

        return CutsceneHydration::compileSummon($this->data, $this->partner, $this->projectRoot, $presentation);
    }

    /**
     * Removes the asset's pair from disk, as one transaction.
     *
     * Both files go or neither does: a deletion that cannot remove the
     * second file puts the first back, so a refused deletion leaves the
     * complete original pair.
     *
     * @param callable(string ...$paths): void|null $backup
     * @throws FileSetTransactionFailure When the pair could not be removed.
     */
    private function delete(?callable $backup): bool
    {
        if (! is_dir($this->folder)) {
            // Never written: nothing on disk to take away.
            $this->baselineSources = [];
            $this->captureBaseline();

            return false;
        }

        $transaction = $this->createSourceTransaction();

        foreach ($this->paths() as $path) {
            $transaction->remove($path);
        }

        // Only the two files are the asset's; anything else in the folder is
        // left for its author, and the folder goes only when empty.
        $transaction->commit($backup);
        $this->baselineSources = [];
        $this->captureBaseline();

        return true;
    }

    /**
     * Returns the source a file should now hold.
     *
     * @param array<array-key, mixed> $loaded
     * @param array<array-key, mixed> $current
     */
    private function proposedSource(?PhpArraySourceDocument $document, array $loaded, array $current, string $noun): string
    {
        if (isset($this->sourceTemplates[$noun])) {
            $template = $this->sourceTemplates[$noun];
            $document = PhpArraySourceDocument::parse($template['source']);
            $loaded = $template['values'];
        }
        if ($document === null) {
            // No file yet: a new asset's file is written whole, in the
            // editor's own layout.
            return "<?php\n\nreturn " . PhpValueExporter::export($current) . ";\n";
        }

        try {
            return ArraySourceWriter::rewrite($document, $loaded, $current)->source;
        } catch (SourcePreservationRefusal $refusal) {
            throw new RuntimeException(sprintf('The %s of "%s" cannot be rewritten safely: %s', $noun, $this->id, $refusal->getMessage()), 0, $refusal);
        }
    }

    /**
     * Adopts written sources as the new baseline.
     */
    private function adoptWritten(string $dataSource, string $partnerSource): void
    {
        $this->baselineSources = $this->type->hasDataFile()
            ? [$this->dataPath() => $dataSource, $this->partnerPath() => $partnerSource]
            : [$this->partnerPath() => $partnerSource];
        $this->dataDocument = $this->type->hasDataFile() ? PhpArraySourceDocument::parse($dataSource) : null;
        $this->partnerDocument = PhpArraySourceDocument::parse($partnerSource);
        $this->loadedData = $this->data;
        $this->loadedPartner = $this->partner;
        $this->shape = CutscenePairShape::of($this->type, $this->data, $this->partner);
        $this->captureBaseline();
    }

    /**
     * Evaluates a PHP file the way the game does: inside the project.
     */
    private function evaluate(string $path): mixed
    {
        $operation = static fn(): mixed => require $path;

        if ($this->projectRoot !== null && is_dir($this->projectRoot)) {
            return ProjectDirectoryContext::run($this->projectRoot, static fn(): mixed => $operation());
        }

        return $operation();
    }

    /**
     * @inheritDoc
     */
    protected function buildPersistedPayload(): string
    {
        return ($this->isDeleted ? "deleted\0" : '')
            . PhpValueExporter::export($this->data)
            . "\0"
            . PhpValueExporter::export($this->partner);
    }
}
