<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourceNode;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Database\PhpValueExporter;
use Ichiloto\Editor\Database\ProjectRecord;
use Ichiloto\Editor\Storage\FileSetOperations;
use Ichiloto\Editor\Storage\FileSetTransaction;
use Ichiloto\Editor\Storage\PreparedSave;
use Ichiloto\Editor\Storage\SaveSet;
use RuntimeException;
use Throwable;

/**
 * One source owner for a project settings file that Database categories edit as rows, one per setting by its
 * dotted path. Records are shared identities, so the ordinary record undo path edits this owner too. A write
 * replaces only the changed literal leaves over the original bytes, keeping comments and expressions; a save
 * stages the file, proves every pending value reads back, and commits all pending settings together. A subclass
 * names the file and says what the Engine reads where the file is silent and which values it accepts.
 */
abstract class ProjectSettingsFile
{
    private ?PhpArraySourceDocument $document = null;
    private ?string $issue = null;
    protected array $payload = [];
    /** @var array<string, ProjectRecord> */
    private array $records = [];
    private array $originalValues = [];
    private array $fieldIssues = [];
    private string $persistedSource = '';
    public readonly string $path;

    public function __construct(protected readonly string $projectRoot, public readonly string $relativePath)
    {
        $this->path = rtrim($projectRoot, '/') . '/' . $relativePath;
        try {
            if (! is_file($this->path)) {
                throw new RuntimeException(sprintf('%s does not exist in this project.', $relativePath));
            }
            $this->persistedSource = (string) file_get_contents($this->path);
            $file = PhpDataFile::load($this->path, $projectRoot);
            $this->payload = is_array($file->payload) ? $file->payload : [];
            $this->document = PhpArraySourceDocument::parse($this->persistedSource);
            if (! is_array($file->payload)) {
                throw new RuntimeException($file->readOnlyReason ?? sprintf('%s must return an array.', $relativePath));
            }
        } catch (Throwable $error) {
            $this->issue = $error->getMessage();
        }
    }

    /**
     * The settings the Engine reads where the file leaves them out, by path, with what their record carries beyond
     * its path and value: at least the `default` the Engine reads. Each is offered even where the file is silent.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected function listEngineSettings(): array;

    /**
     * Refuses a value the Engine would not accept at a path, before anything changes.
     *
     * @throws \InvalidArgumentException
     */
    public function assertAcceptableValue(string $path, mixed $value, bool $complete = true): void {}

    /** Whether a setting's value is a whole array edited as one setting, rather than a tree of settings. */
    protected function isWholeValue(string $path): bool
    {
        return false;
    }

    /**
     * The keys a setting's path walks, root first. A path joins them with dots; a file whose keys hold dots of their
     * own (icon roles such as map.player) says where a key begins.
     *
     * @return list<string>
     */
    protected function splitPath(string $path): array
    {
        return explode('.', $path);
    }

    /** Whether choosing nothing for a setting removes its key, rather than writing an empty value the Engine refuses. */
    protected function isRemovedWhenEmpty(string $path): bool
    {
        return false;
    }

    /**
     * Keys a whole-value edit renames rather than replaces, so the renamed entries keep their authored comments.
     *
     * @return list<array{path: list<string>, key: string}>
     */
    protected function listKeyRenames(string $path, mixed $old, mixed $new): array
    {
        return [];
    }

    public function getRecord(string $path): ProjectRecord
    {
        if (! isset($this->records[$path])) {
            $value = $this->readValue($this->payload, $path);
            $this->originalValues[$path] = $value;
            $setting = $this->listEngineSettings()[$path] ?? null;
            $this->records[$path] = new ProjectRecord($setting === null
                ? ['path' => $path, 'value' => $value]
                : ['path' => $path, 'value' => $value, ...$setting]);
        }
        return $this->records[$path];
    }

    /**
     * The settings under these roots: every literal leaf the file holds, then every setting the Engine knows there
     * that the file leaves out.
     *
     * @param list<string> $roots
     * @return list<ProjectRecord>
     */
    public function getSettingRecords(array $roots): array
    {
        $records = [];
        $visit = function (array $tree, array $segments, bool $ambiguous = false) use (&$visit, &$records): void {
            foreach ($tree as $key => $value) {
                $keys = [...$segments, (string) $key];
                $path = implode('.', $keys);
                // A key the path cannot be split back into is ambiguous with a nested path.
                $hasDottedKey = $ambiguous || $this->splitPath($path) !== $keys;
                if ($this->isWholeValue($path) && ! $hasDottedKey) { $records[] = $this->getRecord($path); }
                elseif (is_array($value)) { $visit($value, $keys, $hasDottedKey); }
                elseif (! is_object($value) || $value instanceof \UnitEnum) {
                    if ($hasDottedKey) {
                        $this->fieldIssues[$path] = $path . ' contains a dotted literal key, ambiguous with a nested path; it is preserved read-only.';
                        $records[] = new ProjectRecord(['path' => $path, 'value' => $value]);
                    } else { $records[] = $this->getRecord($path); }
                }
            }
        };
        foreach ($roots as $root) {
            if (is_array($this->payload[$root] ?? null)) { $visit($this->payload[$root], [$root]); }
        }
        foreach (array_keys($this->listEngineSettings()) as $path) {
            $root = $this->splitPath($path)[0];
            if (in_array($root, $roots, true) && ! in_array($path, array_map(static fn(ProjectRecord $record): mixed => $record->get('path'), $records), true) && $this->issue === null) {
                $records[] = $this->getRecord($path);
            }
        }
        return $records;
    }

    public function getReadOnlyReason(): ?string
    {
        return $this->issue;
    }

    public function getFieldIssue(string $path): ?string
    {
        if (isset($this->fieldIssues[$path])) { return $this->fieldIssues[$path]; }
        try {
            $this->createFieldEdit($this->document, $path, '1', $this->isWholeValue($path));
            return null;
        } catch (Throwable $error) {
            return $error->getMessage();
        }
    }

    /** Whether the file authors a setting at all, as opposed to leaving it to its default. */
    public function holds(string $path): bool
    {
        return $this->document?->nodeAt($this->splitPath($path)) !== null;
    }

    public function isDirty(): bool
    {
        foreach ($this->records as $record) {
            if ($record->isDirty()) { return true; }
        }
        return false;
    }

    /** Rebuild only the changed leaf spans over the original bytes, including after save/undo. */
    public function getSource(): string
    {
        return $this->composeSource();
    }

    /** Proves a proposed structured value is source-editable before changing its shared record. */
    public function assertSourceAcceptsValue(string $path, mixed $value): void
    {
        $this->composeSource([$path => $value]);
    }

    /** The value a setting holds now, pending edits included, or what the file holds where none was read. */
    protected function getCurrentValue(string $path): mixed
    {
        return isset($this->records[$path]) ? $this->records[$path]->get('value') : $this->readValue($this->payload, $path);
    }

    private function composeSource(array $overrides = []): string
    {
        $document = $this->document;
        foreach ($this->records as $path => $record) {
            $value = array_key_exists($path, $overrides) ? $overrides[$path] : $record->get('value');
            if ($value === $this->originalValues[$path]) { continue; }
            if ($this->isWholeValue($path) && is_array($this->originalValues[$path])) {
                $this->createFieldEdit($document, $path, '1', true);
                $old = $this->payload;
                $new = $old;
                $next = &$new;
                foreach ($this->splitPath($path) as $segment) { $next = &$next[$segment]; }
                $next = $value;
                unset($next);
                $document = ArraySourceWriter::rewrite($document, $old, $new, $this->listKeyRenames($path, $this->originalValues[$path], $value));
                continue;
            }
            if ($value === null && $this->isRemovedWhenEmpty($path)) {
                // An authored key chosen away is removed, keeping its neighbours and their comments.
                $this->createFieldEdit($document, $path, '1');
                $document = $document->withEdits([$document->removeEntryEdit($this->splitPath($path))]);
                continue;
            }
            $edit = $this->createFieldEdit($document, $path, PhpValueExporter::export($value));
            $document = $document->withEdits([$edit]);
        }
        return $document?->source ?? $this->persistedSource;
    }

    /** Saves all pending settings together, never a second whole-value rewrite. */
    public function save(?FileSetOperations $files = null): void
    {
        $prepared = $this->prepareSave();
        if ($prepared !== null) {
            SaveSet::commitOne($this->projectRoot, $prepared, files: $files);
        }
    }

    /**
     * Works out the settings save without writing it: the file with every
     * pending setting composed into it, and the proof that the staged file
     * holds each changed setting. Null when nothing is pending.
     *
     * @throws SourcePreservationRefusal When a setting cannot be written into the file's own source.
     */
    public function prepareSave(): ?PreparedSave
    {
        if (! $this->isDirty()) { return null; }
        foreach ($this->records as $path => $record) {
            if ($record->isDirty() && $record->get('value') !== $this->originalValues[$path]) { $this->assertAcceptableValue($path, $record->get('value')); }
        }
        $source = $this->getSource();

        return new PreparedSave(
            $this->relativePath,
            fn(FileSetTransaction $transaction) => $transaction->write($this->path, $source),
            function (array $staged): void {
                if ((string) file_get_contents($this->path) !== $this->persistedSource) {
                    throw new SourcePreservationRefusal($this->relativePath . ' changed on disk. Reload before saving; pending edits were not written.');
                }
                $evaluated = PhpDataFile::evaluateIsolated($staged[$this->path], $this->projectRoot);
                foreach ($this->records as $path => $record) {
                    if ($record->get('value') === $this->originalValues[$path]) { continue; }
                    if ($this->readValue(is_array($evaluated) ? $evaluated : [], $path) !== $record->get('value')) {
                        throw new SourcePreservationRefusal('The staged ' . $this->relativePath . ' does not preserve ' . $path . '.');
                    }
                }
            },
            function () use ($source): void {
                $this->persistedSource = $source;
                foreach ($this->records as $record) { $record->markClean(); }
            },
        );
    }

    private function createFieldEdit(?PhpArraySourceDocument $document, string $path, string $literal, bool $allowArray = false): array
    {
        if ($this->issue !== null || $document === null) { throw new SourcePreservationRefusal($this->issue ?? $this->relativePath . ' cannot be preserved.'); }
        $segments = $this->splitPath($path);
        $walked = [];
        $node = $document->root();
        foreach ($segments as $index => $key) {
            if ($node->kind !== SourceNode::ARRAY || $node->hasOpaqueKey || count(array_unique(array_column($node->entries, 'key'), SORT_REGULAR)) !== count($node->entries)) {
                throw new SourcePreservationRefusal($path . ' needs literal, uniquely keyed parent arrays; opaque settings are preserved read-only.');
            }
            $entry = $node->entryFor($key);
            if ($entry === null) {
                foreach (array_reverse(array_slice($segments, $index + 1)) as $child) {
                    $literal = '[' . var_export($child, true) . ' => ' . $literal . ']';
                }
                return $document->insertEntryEdit($walked, count($node->entries), $key, $literal);
            }
            $walked[] = $key;
            $node = $entry->value;
        }
        // An enum case is written as one: replacing it with another case of
        // its enum keeps the file reading the same kind of value.
        if ($node->kind !== SourceNode::SCALAR && ! ($allowArray && $node->kind === SourceNode::ARRAY) && ! $this->readValue($this->payload, $path) instanceof \UnitEnum) {
            throw new SourcePreservationRefusal($path . ' is an authored expression, not an editable literal.');
        }
        return $document->replaceValueEdit($walked, $literal);
    }

    protected function readValue(array $payload, string $path): mixed
    {
        $value = $payload;
        foreach ($this->splitPath($path) as $key) { $value = is_array($value) ? ($value[$key] ?? null) : null; }
        return $value;
    }

    /**
     * Returns the files a save would overwrite.
     *
     * @return string[]
     */
    public function getBackupPaths(): array
    {
        return [$this->path];
    }
}
