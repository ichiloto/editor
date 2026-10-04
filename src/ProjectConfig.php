<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourceNode;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Database\PhpValueExporter;
use Ichiloto\Editor\Database\ProjectRecord;
use Ichiloto\Editor\Storage\FileSetOperations;
use Ichiloto\Editor\Storage\FileSetTransaction;
use Ichiloto\Editor\Storage\FilesystemFileSetOperations;
use Ichiloto\Engine\Rendering\FieldViewport;
use RuntimeException;
use Throwable;

/** One source owner for config.php, shared by Terms and Configuration. */
final class ProjectConfig
{
    public const string FIELD_ZOOM = 'graphics.field.zoom';

    /**
     * Settings the engine reads with a default when the file leaves them
     * out, by path, with that default.
     */
    private const array ENGINE_DEFAULTS = [self::FIELD_ZOOM => FieldViewport::DEFAULT_ZOOM];
    private ?PhpArraySourceDocument $document = null;
    private ?string $issue = null;
    private array $payload = [];
    /** @var array<string, ProjectRecord> */
    private array $records = [];
    private array $originalValues = [];
    private array $fieldIssues = [];
    private string $persistedSource = '';
    public readonly string $path;

    public function __construct(private readonly string $projectRoot)
    {
        $this->path = rtrim($projectRoot, '/') . '/config.php';
        try {
            if (! is_file($this->path)) {
                throw new RuntimeException('config.php does not exist in this project.');
            }
            $this->persistedSource = (string) file_get_contents($this->path);
            $file = PhpDataFile::load($this->path, $projectRoot);
            $this->payload = is_array($file->payload) ? $file->payload : [];
            $this->document = PhpArraySourceDocument::parse($this->persistedSource);
            if (! is_array($file->payload)) {
                throw new RuntimeException($file->readOnlyReason ?? 'config.php must return an array.');
            }
        } catch (Throwable $error) {
            $this->issue = $error->getMessage();
        }
    }

    /** Records are shared identities: the ordinary record undo path edits this owner too. */
    public function getRecord(string $path): ProjectRecord
    {
        if (! isset($this->records[$path])) {
            $value = self::getValue($this->payload, $path);
            $this->originalValues[$path] = $value;
            // A setting the engine knows carries the default it reads when
            // the file holds none, which is what the setting reads as here.
            $this->records[$path] = new ProjectRecord(array_key_exists($path, self::ENGINE_DEFAULTS)
                ? ['path' => $path, 'value' => $value, 'default' => self::ENGINE_DEFAULTS[$path]]
                : ['path' => $path, 'value' => $value]);
        }
        return $this->records[$path];
    }

    /** @return list<ProjectRecord> */
    public function getTermRecords(array $roots): array
    {
        $records = [];
        $visit = function (array $tree, string $prefix, bool $ambiguous = false) use (&$visit, &$records): void {
            foreach ($tree as $key => $value) {
                $path = $prefix . '.' . $key;
                $hasDottedKey = $ambiguous || str_contains((string) $key, '.');
                if (is_array($value)) { $visit($value, $path, $hasDottedKey); }
                elseif (! is_object($value) || $value instanceof \UnitEnum) {
                    if ($hasDottedKey) {
                        $this->fieldIssues[$path] = $path . ' contains a dotted literal key, ambiguous with a nested path; it is preserved read-only.';
                        $records[] = new ProjectRecord(['path' => $path, 'value' => $value]);
                    } else { $records[] = $this->getRecord($path); }
                }
            }
        };
        foreach ($roots as $root) {
            if (is_array($this->payload[$root] ?? null)) { $visit($this->payload[$root], $root); }
        }
        // A setting the engine knows is offered even where the file leaves it
        // out, so it can be set without being typed in by hand.
        foreach (array_keys(self::ENGINE_DEFAULTS) as $path) {
            $root = explode('.', $path)[0];
            if (in_array($root, $roots, true) && self::getValue($this->payload, $path) === null && $this->issue === null) {
                $records[] = $this->getRecord($path);
            }
        }
        return $records;
    }

    public function getReadOnlyReason(): ?string
    {
        return $this->issue;
    }

    /**
     * Refuses a value the engine would not accept at a path, before anything
     * changes: a field zoom outside its range.
     *
     * @throws \InvalidArgumentException
     */
    public function assertAcceptableValue(string $path, mixed $value): void
    {
        if ($path === self::FIELD_ZOOM && (! is_int($value) && ! is_float($value)
            || ! is_finite((float) $value) || $value < FieldViewport::MIN_ZOOM || $value > FieldViewport::MAX_ZOOM)) {
            throw new \InvalidArgumentException(sprintf('Field zoom must be a finite number from %g to %g.', FieldViewport::MIN_ZOOM, FieldViewport::MAX_ZOOM));
        }
    }

    public function getFieldIssue(string $path): ?string
    {
        if (isset($this->fieldIssues[$path])) { return $this->fieldIssues[$path]; }
        try {
            $this->createFieldEdit($this->document, $path, '1');
            return null;
        } catch (Throwable $error) {
            return $error->getMessage();
        }
    }

    /** Whether the file authors a setting at all, as opposed to leaving it to its default. */
    public function holds(string $path): bool
    {
        return $this->document?->nodeAt(explode('.', $path)) !== null;
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
        $document = $this->document;
        foreach ($this->records as $path => $record) {
            if ($record->get('value') === $this->originalValues[$path]) { continue; }
            $edit = $this->createFieldEdit($document, $path, PhpValueExporter::export($record->get('value')));
            $document = $document->withEdits([$edit]);
        }
        return $document?->source ?? $this->persistedSource;
    }

    /** Saves all pending configuration leaves together, never a second whole-value rewrite. */
    public function save(?FileSetOperations $files = null): void
    {
        if (! $this->isDirty()) { return; }
        $source = $this->getSource();
        $transaction = new FileSetTransaction($this->projectRoot, $files ?? new FilesystemFileSetOperations());
        $transaction->write($this->path, $source);
        try {
            $staged = $transaction->stage();
            if ((string) file_get_contents($this->path) !== $this->persistedSource) {
                throw new SourcePreservationRefusal('config.php changed on disk. Reload before saving; pending edits were not written.');
            }
            $evaluated = PhpDataFile::evaluateIsolated($staged[$this->path], $this->projectRoot);
            foreach ($this->records as $path => $record) {
                if ($record->get('value') === $this->originalValues[$path]) { continue; }
                if (self::getValue(is_array($evaluated) ? $evaluated : [], $path) !== $record->get('value')) {
                    throw new SourcePreservationRefusal('The staged config.php does not preserve ' . $path . '.');
                }
            }
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
        $this->persistedSource = $source;
        foreach ($this->records as $record) { $record->markClean(); }
    }

    private function createFieldEdit(?PhpArraySourceDocument $document, string $path, string $literal): array
    {
        if ($this->issue !== null || $document === null) { throw new SourcePreservationRefusal($this->issue ?? 'config.php cannot be preserved.'); }
        $segments = explode('.', $path);
        $walked = [];
        $node = $document->root();
        foreach ($segments as $index => $key) {
            if ($node->kind !== SourceNode::ARRAY || $node->hasOpaqueKey || count(array_unique(array_column($node->entries, 'key'), SORT_REGULAR)) !== count($node->entries)) {
                throw new SourcePreservationRefusal($path . ' needs literal, uniquely keyed parent arrays; opaque configuration is preserved read-only.');
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
        if ($node->kind !== SourceNode::SCALAR && ! self::getValue($this->payload, $path) instanceof \UnitEnum) {
            throw new SourcePreservationRefusal($path . ' is an authored expression, not an editable literal.');
        }
        return $document->replaceValueEdit($walked, $literal);
    }

    private static function getValue(array $payload, string $path): mixed
    {
        $value = $payload;
        foreach (explode('.', $path) as $key) { $value = is_array($value) ? ($value[$key] ?? null) : null; }
        return $value;
    }

    /**
     * Returns the files a save of this database would overwrite.
     *
     * @return string[]
     */
    public function getBackupPaths(): array
    {
        return [$this->path];
    }
}
