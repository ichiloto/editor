<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Engine\Localization\Vocabulary;

use Ichiloto\Editor\Database\InnPresentationFields;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\AudioBus;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Localization\EngineMessageTerms;
use Ichiloto\Engine\Field\PlayerDash;
use Ichiloto\Engine\Rendering\FieldViewport;

/** One source owner for config.php, shared by Terms and Configuration. */
final class ProjectConfig extends ProjectSettingsFile
{
    public const string FIELD_ZOOM = 'graphics.field.zoom';
    /** The inn's rest stage timeline when an inn names none of its own (InnStay). */
    public const string INN_PRESENTATION = 'graphics.inn.presentation';
    /** The event script a save point runs, else the Engine's save point notice (SaveFeatures). */
    public const string SAVE_POINT_SCRIPT = 'save.point_script';
    /** Always Dash's first value; the player changes it in Options and Config (PlayerDash). */
    public const string ALWAYS_DASH = PlayerDash::CONFIG_ALWAYS_DASH;

    /**
     * Settings the engine reads with a default when the file leaves them
     * out, by path, with that default.
     */
    private const array ENGINE_DEFAULTS = [self::FIELD_ZOOM => FieldViewport::DEFAULT_ZOOM, self::INN_PRESENTATION => null,
        self::SAVE_POINT_SCRIPT => null, self::ALWAYS_DASH => false];

    /**
     * Settings that name another resource, by path, with the reference kind
     * ({@see \Ichiloto\Editor\Database\ReferenceCatalog}) they are chosen from.
     */
    public const array ENGINE_REFERENCES = [self::INN_PRESENTATION => 'stage_timelines', self::SAVE_POINT_SCRIPT => 'common_events'];

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
     * A shared display term as this project words it in its own file (`vocab.<path>`), else the Engine's
     * wording ({@see Vocabulary::DEFAULTS}). A blank term keeps the Engine's, as a label cannot be blank
     * ({@see Vocabulary::getLabel()}).
     */
    public function getTermLabel(string $path): string
    {
        $term = $this->getRecord('vocab.' . $path)->get('value');
        $term = is_string($term) ? trim($term) : '';

        return $term !== '' ? $term : (Vocabulary::DEFAULTS[$path] ?? '');
    }

    /**
     * The settings the engine reads with a default, then every message the Engine says, by its path in config.php,
     * with its default, what it accepts and what it is for: each is offered even where the project leaves it out,
     * so any game can reword it.
     */
    protected function listEngineSettings(): array
    {
        $settings = array_map(static fn(mixed $default): array => ['default' => $default], self::ENGINE_DEFAULTS);
        // The volumes the game starts at: the master volume, and beneath it each bus's (Music, Sound Effects, Voice).
        // The player changes them in Options and Config; their choices stay in player settings.
        $settings[AudioManager::CONFIG_MASTER_VOLUME] = ['default' => AudioManager::DEFAULT_MASTER_VOLUME];
        foreach (AudioBus::cases() as $bus) {
            $settings[$bus->getVolumeConfigPath()] = ['default' => AudioBus::DEFAULT_VOLUME];
        }
        // Every sound the Engine plays for itself is offered, so a game can choose one it has not set yet,
        // with its own volume beside it, so a dense sound can sit under the music.
        foreach (SystemSound::cases() as $sound) {
            $settings[$sound->getConfigPath()] = ['default' => null];
            $settings[$sound->getVolumeConfigPath()] = ['default' => SystemSound::DEFAULT_VOLUME];
        }
        // The Engine's own wording for terms many screens share, such as the level in rows ("Lv"), is offered
        // even where the project leaves it out, so a game rewords it once.
        foreach (Vocabulary::DEFAULTS as $path => $default) {
            $settings['vocab.' . $path] = ['default' => $default];
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
     * changes: a field zoom outside its range, a master, bus or system sound
     * volume outside its range, an inn presentation that is not a timeline
     * identity, or an Always Dash that is not true or false.
     *
     * @throws \InvalidArgumentException
     */
    public function assertAcceptableValue(string $path, mixed $value, bool $complete = true): void
    {
        if ($path === self::FIELD_ZOOM && (! is_int($value) && ! is_float($value)
            || ! is_finite((float) $value) || $value < FieldViewport::MIN_ZOOM || $value > FieldViewport::MAX_ZOOM)) {
            throw new \InvalidArgumentException(sprintf('Field zoom must be a finite number from %g to %g.', FieldViewport::MIN_ZOOM, FieldViewport::MAX_ZOOM));
        }
        if (self::isVolume($path) && (! is_int($value) || $value < AudioManager::MIN_VOLUME || $value > AudioManager::MAX_VOLUME)) {
            throw new \InvalidArgumentException(sprintf('A volume must be a whole number from %d to %d.',
                AudioManager::MIN_VOLUME, AudioManager::MAX_VOLUME));
        }
        if ($path === self::INN_PRESENTATION && $value !== null) {
            InnPresentationFields::assertValid($value, complete: $complete);
        }
        if ($path === self::ALWAYS_DASH && ! is_bool($value)) {
            throw new \InvalidArgumentException('Always Dash must be true or false.');
        }
    }

    /** Whether a path holds a volume the Engine reads: the master volume, a bus's or a system sound's own. */
    private static function isVolume(string $path): bool
    {
        return $path === AudioManager::CONFIG_MASTER_VOLUME
            || array_any(AudioBus::cases(), static fn(AudioBus $bus): bool => $path === $bus->getVolumeConfigPath())
            || array_any(SystemSound::cases(), static fn(SystemSound $sound): bool => $path === $sound->getVolumeConfigPath());
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
