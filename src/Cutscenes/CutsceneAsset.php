<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes;

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;
use Ichiloto\Editor\Storage\FileSetTransaction;
use Ichiloto\Editor\Storage\FileSetTransactionFailure;
use Ichiloto\Editor\Database\PhpValueExporter;
use Ichiloto\Editor\History\TracksPersistedState;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;
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

        if (! is_file($dataPath) || ! is_file($partnerPath)) {
            throw new RuntimeException(sprintf('%s "%s" is missing %s.', ucfirst($type->noun()), $asset->id, is_file($dataPath) ? basename($partnerPath) : basename($dataPath)));
        }

        $dataSource = (string) file_get_contents($dataPath);
        $partnerSource = (string) file_get_contents($partnerPath);
        $reasons = [];

        try {
            $data = $asset->evaluate($dataPath);
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
            $asset->dataDocument = PhpArraySourceDocument::parse($dataSource);
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
        return [$this->dataPath(), $this->partnerPath()];
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
        $payload = $this->shape->merge($this->data, $this->partner);
        $payload[self::ORIGIN_KEY] = $this->id;

        return $payload;
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

        [$data, $partner] = $this->shape->split($payload);

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
     * @return bool True when anything was written.
     * @throws FileSetTransactionFailure When the pair could not be installed.
     */
    public function save(?callable $backup = null): bool
    {
        if ($this->isDeleted) {
            return $this->delete($backup);
        }

        if (! $this->isDirty() && ! $this->isNew) {
            return false;
        }

        if ($this->readOnlyReason !== null) {
            throw new RuntimeException(sprintf('%s "%s" is read-only: %s.', ucfirst($this->type->noun()), $this->id, $this->readOnlyReason));
        }

        // 1. Both proposed sources, each touched only where it changed.
        $dataSource = $this->proposedSource($this->dataDocument, $this->loadedData, $this->data, 'data');
        $partnerSource = $this->proposedSource($this->partnerDocument, $this->loadedPartner, $this->partner, $this->type->partnerNoun());
        $writeData = $this->isNew || $this->dataDocument === null || $dataSource !== $this->dataDocument->source;
        $writePartner = $this->isNew || $this->partnerDocument === null || $partnerSource !== $this->partnerDocument->source;

        if (! $writeData && ! $writePartner) {
            // Dirty by fingerprint but identical in source: a same-value
            // round trip. Clean without writing.
            $this->adoptWritten($this->dataDocument?->source ?? $dataSource, $this->partnerDocument?->source ?? $partnerSource);

            return false;
        }

        $transaction = new FileSetTransaction($this->folder);

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
            $evaluatedData = $this->evaluateSide($staged[$this->dataPath()] ?? $this->dataPath(), $this->data, basename($this->dataPath()), 'data');
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

        CutsceneHydration::compileSummon($data, $partner, $this->projectRoot);
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
     * Compiles the summon as it stands in memory, unsaved edits included.
     *
     * @throws RuntimeException When the Engine refuses it, or for a cinematic.
     */
    public function compiledSummon(): SummonCompiledCutscene
    {
        if ($this->type !== CutsceneType::SUMMON) {
            throw new RuntimeException(sprintf('%s is a cinematic, not a summon.', $this->id));
        }

        return CutsceneHydration::compileSummon($this->data, $this->partner, $this->projectRoot);
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
            $this->captureBaseline();

            return false;
        }

        $transaction = new FileSetTransaction($this->folder);

        foreach ($this->paths() as $path) {
            $transaction->remove($path);
        }

        // Only the two files are the asset's; anything else in the folder is
        // left for its author, and the folder goes only when empty.
        $transaction->commit($backup);
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
        $this->dataDocument = PhpArraySourceDocument::parse($dataSource);
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
