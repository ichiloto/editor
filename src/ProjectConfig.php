<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Editor\Database\InnPresentationFields;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Localization\EngineMessageTerms;
use Ichiloto\Engine\Rendering\FieldViewport;

/** One source owner for config.php, shared by Terms and Configuration. */
final class ProjectConfig extends ProjectSettingsFile
{
    public const string FIELD_ZOOM = 'graphics.field.zoom';
    /** The inn's rest stage timeline when an inn names none of its own (InnStay). */
    public const string INN_PRESENTATION = 'graphics.inn.presentation';

    /**
     * Settings the engine reads with a default when the file leaves them
     * out, by path, with that default.
     */
    private const array ENGINE_DEFAULTS = [self::FIELD_ZOOM => FieldViewport::DEFAULT_ZOOM, self::INN_PRESENTATION => null];

    /**
     * Settings that name another resource, by path, with the reference kind
     * ({@see \Ichiloto\Editor\Database\ReferenceCatalog}) they are chosen from.
     */
    public const array ENGINE_REFERENCES = [self::INN_PRESENTATION => 'stage_timelines'];

    /** Settings under these paths each name one audio file, chosen from the project's own folder for that kind. */
    private const array ENGINE_REFERENCE_PREFIXES = ['audio.sounds.' => 'sfx', 'audio.bgm.' => 'bgm'];

    /** The reference kind a setting is chosen from, or null when it is written as a plain value. */
    public static function getEngineReference(string $path): ?string
    {
        if (isset(self::ENGINE_REFERENCES[$path])) {
            return self::ENGINE_REFERENCES[$path];
        }
        foreach (self::ENGINE_REFERENCE_PREFIXES as $prefix => $reference) {
            if (str_starts_with($path, $prefix) && strlen($path) > strlen($prefix)) {
                return $reference;
            }
        }

        return null;
    }

    public function __construct(string $projectRoot)
    {
        parent::__construct($projectRoot, 'config.php');
    }

    /**
     * The settings the engine reads with a default, then every message the Engine says, by its path in config.php,
     * with its default, what it accepts and what it is for: each is offered even where the project leaves it out,
     * so any game can reword it.
     */
    protected function listEngineSettings(): array
    {
        $settings = array_map(static fn(mixed $default): array => ['default' => $default], self::ENGINE_DEFAULTS);
        // Every sound the Engine plays for itself is offered, so a game can choose one it has not set yet.
        foreach (SystemSound::cases() as $sound) {
            $settings['audio.sounds.' . $sound->value] = ['default' => null];
        }
        foreach (EngineMessageTerms::listTerms() as $path => $term) {
            // A message that inherits the project's own vocabulary wording reads as that wording here, as the game
            // reads it, taken from this project's file, never from another project's loaded runtime state.
            $inherited = isset($term['vocabularyDefault']) ? $this->readValue($this->payload, 'vocab.' . $term['vocabularyDefault']) : null;
            if (is_string($inherited)) {
                $term['default'] = $inherited;
            }
            $settings['messages.' . $path] = ['default' => $term['default'], 'placeholders' => $term['placeholders'],
                'description' => $term['description']];
        }

        return $settings;
    }

    /**
     * Refuses a value the engine would not accept at a path, before anything
     * changes: a field zoom outside its range, or an inn presentation that
     * is not a timeline identity.
     *
     * @throws \InvalidArgumentException
     */
    public function assertAcceptableValue(string $path, mixed $value, bool $complete = true): void
    {
        if ($path === self::FIELD_ZOOM && (! is_int($value) && ! is_float($value)
            || ! is_finite((float) $value) || $value < FieldViewport::MIN_ZOOM || $value > FieldViewport::MAX_ZOOM)) {
            throw new \InvalidArgumentException(sprintf('Field zoom must be a finite number from %g to %g.', FieldViewport::MIN_ZOOM, FieldViewport::MAX_ZOOM));
        }
        if ($path === self::INN_PRESENTATION && $value !== null) {
            InnPresentationFields::assertValid($value, complete: $complete);
        }
    }

    /** The inn's rest presentation is one setting, a whole timeline binding. */
    protected function isWholeValue(string $path): bool
    {
        return $path === self::INN_PRESENTATION;
    }

    /** One picker key replacement keeps the binding's authored comments and value span. */
    protected function listKeyRenames(string $path, mixed $old, mixed $new): array
    {
        if (! is_array($old) || ! is_array($new) || ($old['treatment'] ?? null) !== 'leader' || ($new['treatment'] ?? null) !== 'leader'
            || ! is_array($old['leaders'] ?? null) || ! is_array($new['leaders'] ?? null)) { return []; }
        $before = array_keys($old['leaders']);
        $after = array_keys($new['leaders']);
        if (count($before) !== count($after)) { return []; }
        $renames = [];
        foreach ($before as $index => $key) {
            if ($key === $after[$index]) { continue; }
            if (array_key_exists($key, $new['leaders']) || array_key_exists($after[$index], $old['leaders'])) { return []; }
            $renames[] = ['path' => ['graphics', 'inn', 'presentation', 'leaders', $key], 'key' => $after[$index]];
        }
        return $renames;
    }
}
