<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes;

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;
use Ichiloto\Editor\Database\PhpValueExporter;
use Ichiloto\Editor\History\TracksPersistedState;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
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
     * @var array<string, string> Which file each top-level payload key came
     * from ('data' or 'partner'), for keys the files were read holding.
     */
    private array $keyOwners = [];

    /**
     * @var bool Whether a cinematic script was authored as `['commands' => ...]`
     * rather than a bare command list.
     */
    private bool $scriptIsMap = false;

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

        $asset->readOnlyReason = $reasons === [] ? null : implode('; ', $reasons);
        $asset->adoptArrays($data, $partner);
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
        [$data, $partner] = $asset->split($payload);
        $asset->keyOwners = [];
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
        if ($this->type !== CutsceneType::CINEMATIC) {
            return [];
        }

        $commands = array_is_list($this->partner) ? $this->partner : ($this->partner[self::COMMANDS_KEY] ?? []);

        return is_array($commands) ? array_values($commands) : [];
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
        $payload = $this->data;

        if ($this->type === CutsceneType::CINEMATIC) {
            $payload[self::COMMANDS_KEY] = $this->commands();

            if (! array_is_list($this->partner)) {
                foreach ($this->partner as $key => $value) {
                    if ($key !== self::COMMANDS_KEY && ! array_key_exists($key, $payload)) {
                        $payload[$key] = $value;
                    }
                }
            }
        } else {
            foreach ($this->partner as $key => $value) {
                if (! array_key_exists($key, $payload)) {
                    $payload[$key] = $value;
                }
            }
        }

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
        [$data, $partner] = $this->split($payload);

        if ($data === $this->data && $partner === $this->partner) {
            return;
        }

        $this->data = $data;
        $this->partner = $partner;
        $this->touchState();
    }

    /**
     * Splits a payload into the two files' arrays.
     *
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>, 1: array<int|string, mixed>}
     */
    private function split(array $payload): array
    {
        unset($payload[self::ORIGIN_KEY]);
        $data = [];
        $partner = [];

        if ($this->type === CutsceneType::CINEMATIC) {
            $commands = $payload[self::COMMANDS_KEY] ?? [];
            unset($payload[self::COMMANDS_KEY]);
            $commands = is_array($commands) ? array_values($commands) : [];

            foreach ($payload as $key => $value) {
                if (($this->keyOwners[$key] ?? 'data') === 'partner') {
                    $partner[$key] = $value;
                } else {
                    $data[$key] = $value;
                }
            }

            if ($this->scriptIsMap || $partner !== []) {
                $partner = [self::COMMANDS_KEY => $commands, ...$partner];
            } else {
                $partner = $commands;
            }

            return [$data, $partner];
        }

        foreach ($payload as $key => $value) {
            $owner = $this->keyOwners[$key] ?? (in_array($key, CinematicCommandSchema::SUMMON_TIMELINE_FIELDS, true) ? 'partner' : 'data');

            if ($owner === 'partner') {
                $partner[$key] = $value;
            } else {
                $data[$key] = $value;
            }
        }

        return [$data, $partner];
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
        $this->keyOwners = [];
        $this->scriptIsMap = $this->type === CutsceneType::CINEMATIC && ! array_is_list($partner) && $partner !== [];

        foreach (array_keys($data) as $key) {
            $this->keyOwners[$key] = 'data';
        }

        if ($this->type === CutsceneType::SUMMON || $this->scriptIsMap) {
            foreach (array_keys($partner) as $key) {
                if (! isset($this->keyOwners[$key])) {
                    $this->keyOwners[$key] = 'partner';
                }
            }
        }
    }

    /**
     * Writes both files as one logical transaction.
     *
     * The data source and the partner source are each rewritten only where
     * their arrays changed. Both proposed sources are evaluated in the
     * project, hydrated through the engine, and only then written next to
     * the originals and swapped into place -- the data file first, and if the
     * partner cannot follow, the data file is put back. A deleted asset's
     * folder is removed instead. Nothing advances until the write succeeds.
     *
     * @param callable(string ...$paths): void|null $backup Called with the paths about to be overwritten, before they are.
     * @return bool True when anything was written.
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

        // 2. Evaluate both proposed sources where the game would.
        $folderExisted = is_dir($this->folder);

        if (! $folderExisted && ! mkdir($this->folder, 0o777, true) && ! is_dir($this->folder)) {
            throw new RuntimeException(sprintf('Unable to create %s.', $this->folder));
        }

        $dataTemp = $this->dataPath() . '.tmp-' . getmypid();
        $partnerTemp = $this->partnerPath() . '.tmp-' . getmypid();

        try {
            if (file_put_contents($dataTemp, $dataSource) === false || file_put_contents($partnerTemp, $partnerSource) === false) {
                throw new RuntimeException(sprintf('Unable to write a temporary file next to %s.', $this->folder));
            }

            $evaluatedData = $this->evaluate($dataTemp);
            $evaluatedPartner = $this->evaluate($partnerTemp);

            if (! is_array($evaluatedData) || $evaluatedData !== $this->data) {
                throw new RuntimeException(sprintf('The rewritten %s would not read back as the edited data; nothing was written.', basename($this->dataPath())));
            }

            if (! is_array($evaluatedPartner) || $evaluatedPartner !== $this->partner) {
                throw new RuntimeException(sprintf('The rewritten %s would not read back as the edited %s; nothing was written.', basename($this->partnerPath()), $this->type->partnerNoun()));
            }

            // 3. Hydrate and compile through the engine.
            $this->hydrate($evaluatedData, $evaluatedPartner);

            // 4. Back up what is about to be overwritten.
            if ($backup !== null) {
                $overwritten = array_values(array_filter(
                    [$writeData ? $this->dataPath() : null, $writePartner ? $this->partnerPath() : null],
                    static fn(?string $path): bool => $path !== null && is_file($path),
                ));

                if ($overwritten !== []) {
                    $backup(...$overwritten);
                }
            }

            // 5. Swap both into place.
            $previousData = is_file($this->dataPath()) ? (string) file_get_contents($this->dataPath()) : null;

            if ($writeData && ! rename($dataTemp, $this->dataPath())) {
                throw new RuntimeException(sprintf('Unable to replace %s.', $this->dataPath()));
            }

            if ($writePartner && ! rename($partnerTemp, $this->partnerPath())) {
                if ($writeData && $previousData !== null) {
                    file_put_contents($this->dataPath(), $previousData);
                }

                throw new RuntimeException(sprintf('Unable to replace %s.', $this->partnerPath()));
            }
        } catch (Throwable $throwable) {
            @unlink($dataTemp);
            @unlink($partnerTemp);

            if (! $folderExisted) {
                @rmdir($this->folder);
            }

            throw $throwable;
        } finally {
            @unlink($dataTemp);
            @unlink($partnerTemp);
        }

        $this->adoptWritten($dataSource, $partnerSource);
        $this->isNew = false;

        return true;
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
     * Removes the asset's folder from disk.
     *
     * @param callable(string ...$paths): void|null $backup
     */
    private function delete(?callable $backup): bool
    {
        if (! is_dir($this->folder)) {
            // Never written: nothing on disk to take away.
            $this->captureBaseline();

            return false;
        }

        $paths = array_values(array_filter($this->paths(), 'is_file'));

        if ($backup !== null && $paths !== []) {
            $backup(...$paths);
        }

        foreach ($paths as $path) {
            if (! unlink($path)) {
                throw new RuntimeException(sprintf('Unable to remove %s.', $path));
            }
        }

        // Only the two files are the asset's; anything else in the folder is
        // left for its author, and the folder goes only when empty.
        $remaining = array_diff(scandir($this->folder) ?: [], ['.', '..']);

        if ($remaining === []) {
            @rmdir($this->folder);
        }

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
        $this->keyOwners = [];

        foreach (array_keys($this->data) as $key) {
            $this->keyOwners[$key] = 'data';
        }

        if ($this->type === CutsceneType::SUMMON || $this->scriptIsMap) {
            foreach (array_keys($this->partner) as $key) {
                if (! isset($this->keyOwners[$key])) {
                    $this->keyOwners[$key] = 'partner';
                }
            }
        }

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
