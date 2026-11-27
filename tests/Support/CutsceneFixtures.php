<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Cutscenes\CutsceneHydration;
use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\UI\CutscenesScreen;

/**
 * Shared fixtures for the Cutscenes tests: an original cinematic, an
 * original summon, a throwaway project holding both, and an editor with the
 * Cutscenes screen open over it.
 */
/**
 * An original cinematic: two lantern boats crossing a harbour at dusk, with
 * a parallel block, a staged cast, a checkpoint and a finalizer.
 */
function harbourCinematicData(): string
{
    return <<<'PHP_SOURCE'
<?php

// Harbour Lanterns: an original test cinematic.
return [
  'id' => 'harbour-lanterns',
  'name' => 'Harbour Lanterns',
  'description' => 'Two lantern boats cross the harbour at dusk.',
  'version' => 1,
  'authoring' => ['purpose' => 'editor-test'],
  'startMap' => 'harbour',
  'presentation' => ['initial' => 'hidden'],
  'cast' => [
    ['kind' => 'staged_actor', 'id' => 'boat-east', 'sprite' => ['~^~'], 'x' => 1, 'y' => 2],
    ['kind' => 'staged_actor', 'id' => 'boat-west', 'sprite' => ['~^~'], 'x' => 1, 'y' => 4],
  ],
  'skip' => ['policy' => 'authored'],
  'checkpoints' => ['boats-crossed'],
  'finalizer' => [
    ['type' => 'camera', 'operation' => 'attach'],
    ['type' => 'remove_actor', 'actorId' => 'boat-east'],
    ['type' => 'remove_actor', 'actorId' => 'boat-west'],
    ['type' => 'clear_presentation'],
    ['type' => 'set_switch', 'name' => 'harbour_lanterns_seen', 'value' => true],
  ],
];
PHP_SOURCE;
}

function harbourCinematicScript(): string
{
    return <<<'PHP_SOURCE'
<?php

return [
  ['type' => 'transition', 'style' => 'fade', 'direction' => 'in', 'seconds' => 0.2],
  ['type' => 'camera', 'operation' => 'detach'],
  [
    'type' => 'parallel',
    'lanes' => [
      ['id' => 'east', 'commands' => [['type' => 'move_route', 'subject' => 'staged_actor', 'actorId' => 'boat-east', 'secondsPerStep' => 0.1, 'steps' => [['direction' => 'right', 'count' => 4]]]]],
      ['id' => 'west', 'commands' => [['type' => 'move_route', 'subject' => 'staged_actor', 'actorId' => 'boat-west', 'secondsPerStep' => 0.1, 'steps' => [['direction' => 'right', 'count' => 4]]]]],
      ['id' => 'words', 'commands' => [['type' => 'narration', 'title' => 'Dusk', 'text' => "The lanterns were lit one by one.\nNobody spoke.", 'seconds' => 0.4]]],
    ],
  ],
  ['type' => 'checkpoint', 'name' => 'boats-crossed'],
  ['type' => 'title_card', 'title' => 'HARBOUR LANTERNS', 'seconds' => 0.2],
];
PHP_SOURCE;
}

/**
 * An original summon: a small lantern spirit, two tracks, one cue.
 */
function lanternSummonData(): string
{
    return <<<'PHP_SOURCE'
<?php

return [
  'id' => 'lantern-wisp',
  'name' => 'Lantern Wisp',
  'description' => 'A small light that burns the dark away.',
  'moveName' => 'Wisp Flare',
  'linkedActionId' => 'Fireball',
  'playback' => ['defaultSpeed' => 1.0],
  'effectTiming' => ['mode' => 'cue', 'cueId' => 'flare'],
];
PHP_SOURCE;
}

function lanternSummonTimeline(): string
{
    return <<<'PHP_SOURCE'
<?php

$wisp = <<<'ART'
 .
( )
 '
ART;

return [
  'formatVersion' => 1,
  'fps' => 12,
  'lengthFrames' => 24,
  'tracks' => [
    // The wisp itself.
    ['type' => 'glyph', 'id' => 'wisp', 'keyframes' => [
      ['frame' => 0, 'duration' => 12, 'content' => $wisp, 'position' => [10, 5], 'color' => 'yellow'],
      ['frame' => 12, 'duration' => 12, 'content' => $wisp, 'position' => [12, 4], 'color' => 'white'],
    ]],
    ['type' => 'text', 'id' => 'name', 'keyframes' => [
      ['frame' => 2, 'duration' => 20, 'content' => 'LANTERN WISP', 'position' => [4, 1]],
    ]],
  ],
  'cues' => [
    ['id' => 'flare', 'frame' => 12, 'type' => 'applyEffect'],
  ],
];
PHP_SOURCE;
}

/** A summon project whose lantern wisp has a terminal and a graphical sequence, its art a synthetic sheet. */
function stagedSummonProject(): string
{
    $root = cutsceneProject();
    file_put_contents($root . '/assets/Cutscenes/Summons/lantern-wisp/lantern-wisp.timeline.php', <<<'PHP_SOURCE'
<?php

return [
  'formatVersion' => 1,
  'presentations' => [
    'terminal' => [
      'fps' => 12,
      'lengthFrames' => 24,
      'tracks' => [
        ['type' => 'glyph', 'id' => 'wisp', 'keyframes' => [['frame' => 0, 'duration' => 24, 'content' => '*', 'position' => ['x' => 10, 'y' => 5]]]],
      ],
      'cues' => [['id' => 'flare', 'frame' => 12, 'type' => 'applyEffect']],
    ],
    'graphical' => [
      'fps' => 12,
      'lengthFrames' => 24,
      'restFrame' => 12,
      'tracks' => [
        // The wisp itself.
        ['type' => 'image', 'id' => 'wisp-art', 'asset' => 'Graphics/Summons/Wisp.png', 'sheet' => ['columns' => 2, 'rows' => 1],
          'keyframes' => [['frame' => 0, 'duration' => 24, 'sourceFrame' => 0]]],
      ],
      'cues' => [['id' => 'flare', 'frame' => 12, 'type' => 'applyEffect']],
    ],
  ],
];
PHP_SOURCE);
    mkdir($root . '/assets/Graphics/Summons', 0o777, true);
    writeTilesetTestPng($root . '/assets/Graphics/Summons/Wisp.png', 16, 8);

    return $root;
}

/**
 * A throwaway project holding the cinematic and the summon.
 */
function cutsceneProject(): string
{
    $root = makeTemporaryProject('ichiloto-cutscenes-');
    $cinematic = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns';
    $summon = $root . '/assets/Cutscenes/Summons/lantern-wisp';
    mkdir($cinematic, 0o777, true);
    mkdir($summon, 0o777, true);
    file_put_contents($cinematic . '/harbour-lanterns.data.php', harbourCinematicData());
    file_put_contents($cinematic . '/harbour-lanterns.script.php', harbourCinematicScript());
    file_put_contents($summon . '/lantern-wisp.data.php', lanternSummonData());
    file_put_contents($summon . '/lantern-wisp.timeline.php', lanternSummonTimeline());
    writeHarbourMap($root);
    writeHarbourNewGame($root);
    writeFireballSkill($root);

    return $root;
}

/**
 * What a new game needs, so one can be started in the project as in any real
 * project: system data naming one starting member, who begins on a cell of
 * a map, and that member with the full stats the Engine builds a character
 * from. Unless a test says otherwise they begin on the sample project's own
 * map, so the harbour stays a map no save can stand on yet: a map a new game
 * reaches has its cells guarded for existing saves when it is edited.
 *
 * @param string[]|string $spawnSprite The player's sprite rows, or the heading they begin facing.
 */
function writeHarbourNewGame(string $root, string $mapId = 'test-map', int $x = 9, int $y = 3, array|string $spawnSprite = 'South'): void
{
    file_put_contents($root . '/assets/Data/system.php', "<?php\n\nreturn " . var_export([
        'title' => 'Harbour',
        'currency' => ['amount' => 0],
        'startingParty' => ['Kaelion'],
        'startingPositions' => ['player' => ['destinationMap' => $mapId, 'spawnPoint' => ['x' => $x, 'y' => $y], 'spawnSprite' => $spawnSprite]],
    ], true) . ";\n");
    file_put_contents($root . '/assets/Data/Actors/Kaelion.php', "<?php\n\nreturn " . var_export(['class' => \Ichiloto\Engine\Entities\Character::class, 'data' => [
        'id' => 'Kaelion', 'name' => 'Kaelion', 'description' => 'A brave and steadfast swordsman.', 'level' => 1, 'currentExp' => 0,
        'stats' => [
            'currentHp' => 100, 'currentMp' => 20, 'currentAp' => 10, 'totalHp' => 100, 'totalMp' => 20, 'totalAp' => 10,
            'attack' => 8, 'defence' => 7, 'magicAttack' => 6, 'magicDefence' => 5, 'speed' => 4, 'grace' => 3, 'evasion' => 2,
        ],
        'images' => ['dialog' => [], 'field' => [], 'battle' => []],
    ]], true) . ";\n");
}

/**
 * The project's actors and items as the Engine holds them now, so a test can
 * tell that a preview gave back exactly what it found.
 *
 * @return array{0: object|null, 1: object|null}
 */
function readInstalledStores(): array
{
    return [\Ichiloto\Engine\Util\Config\ConfigStore::has(\Ichiloto\Engine\Util\Stores\ActorStore::class) ? \Ichiloto\Engine\Util\Config\ConfigStore::get(\Ichiloto\Engine\Util\Stores\ActorStore::class) : null,
        \Ichiloto\Engine\Util\Config\ConfigStore::has(\Ichiloto\Engine\Util\Stores\ItemStore::class) ? \Ichiloto\Engine\Util\Config\ConfigStore::get(\Ichiloto\Engine\Util\Stores\ItemStore::class) : null];
}

/**
 * The one battle action the lantern summon links to.
 */
function writeFireballSkill(string $root): void
{
    writeSkillRecords($root, new \Ichiloto\Engine\Entities\Skills\MagicSkill(
        'Fireball',
        'A burst of flame.',
        'FIR',
        4,
        0,
        new \Ichiloto\Engine\Entities\ItemScope(),
        \Ichiloto\Engine\Entities\Enumerations\Occasion::BATTLE_SCREEN,
        new \Ichiloto\Engine\Entities\Skills\SkillInvocation('$1 casts Fireball!', 0, 0, 1, 10),
        [new \Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect('$user->stats->magicAttack * 3', null, 0.2, false)],
    ));
}

/**
 * A small, runtime-valid map for the harbour cinematic to play on: a quay
 * along the top, open water below, one NPC keeper on the quay.
 */
function writeHarbourMap(string $root): void
{
    $folder = $root . '/assets/Maps/harbour';
    mkdir($folder, 0o777, true);
    $tiles = [
        '####################',
        '#                  #',
        '#  ==============  #',
        '#                  #',
        '#                  #',
        '#                  #',
        '#                  #',
        '####################',
    ];
    $events = array_fill(0, count($tiles), str_repeat(' ', 20));
    file_put_contents($folder . '/harbour.map.php', "<?php\n\nreturn <<<'ICHILOTO_MAP'\n" . implode("\n", $tiles) . "\nICHILOTO_MAP;\n");
    file_put_contents($folder . '/harbour.event.php', "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n" . implode("\n", $events) . "\nICHILOTO_EVENT_MAP;\n");
    file_put_contents($folder . '/harbour.data.php', <<<'PHP_SOURCE'
<?php

return [
  'name' => 'Harbour',
  'region' => 'Coast',
  'description' => 'A quay at dusk.',
  'triggers' => [],
  'events' => [],
  'npcs' => [
    ['id' => 'keeper', 'name' => 'Keeper', 'sprite' => 'K', 'x' => 4, 'y' => 1],
  ],
];
PHP_SOURCE);
}

/**
 * An editor over the project, with the Cutscenes screen open.
 */
function cutscenesEditor(string $root, int $width = 140, int $height = 44): Editor
{
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => $width, 'height' => $height]);
    setEditorProperty($editor, 'isRunning', true);
    callEditorMethod($editor, 'dispatchInput', "\033OS");

    return $editor;
}

function pressKeys(Editor $editor, string ...$inputs): void
{
    foreach ($inputs as $input) {
        callEditorMethod($editor, 'dispatchInput', $input);
    }
}

function typeText(Editor $editor, string $text): void
{
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $glyph) {
        callEditorMethod($editor, 'dispatchInput', $glyph);
    }
}

/**
 * Puts the settings cursor on the row whose field id matches.
 */
function selectCutsceneField(Editor $editor, string $fieldId): void
{
    foreach (callEditorMethod($editor, 'getDatabaseSettingsFields') as $index => $field) {
        if (($field['field'] ?? null) === $fieldId) {
            setEditorProperty($editor, 'databaseSelectedSettingIndex', $index);
            setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_SETTINGS);

            return;
        }
    }

    throw new RuntimeException("No field {$fieldId} on the pane: " . implode(', ', array_map(static fn($f) => (string) ($f['field'] ?? '?'), callEditorMethod($editor, 'getDatabaseSettingsFields'))));
}

function libraryOf(Editor $editor): CutsceneLibrary
{
    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');

    return $workspace->cutscenes;
}

/**
 * Returns the settings field descriptor with an id.
 */
function cutsceneField(Editor $editor, string $fieldId): array
{
    foreach (callEditorMethod($editor, 'getDatabaseSettingsFields') as $field) {
        if (($field['field'] ?? null) === $fieldId) {
            return $field;
        }
    }

    throw new RuntimeException("No field {$fieldId} on the pane: " . implode(', ', cutsceneFieldIds($editor)));
}

/**
 * Lists the settings field ids on the pane.
 *
 * @return string[]
 */
function cutsceneFieldIds(Editor $editor): array
{
    return array_values(array_filter(array_map(static fn(array $f): string => (string) ($f['field'] ?? ''), callEditorMethod($editor, 'getDatabaseSettingsFields')), static fn(string $id): bool => $id !== ''));
}

/**
 * Sets a settings field through the same recorded path the inline editor,
 * option cycling and pickers commit through.
 */
function setCutsceneField(Editor $editor, string $fieldId, string $value): void
{
    selectCutsceneField($editor, $fieldId);
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', cutsceneField($editor, $fieldId), $value);
}

/**
 * Opens the frame a row names (Enter on it).
 */
function openCutsceneFrame(Editor $editor, string $fieldId): void
{
    selectCutsceneField($editor, $fieldId);
    pressKeys($editor, "\n");
}

/**
 * Adds an entry after the row (Shift+O on it) and returns the new list of
 * field ids.
 *
 * @return string[]
 */
function addCutsceneAfter(Editor $editor, string $fieldId): array
{
    selectCutsceneField($editor, $fieldId);
    pressKeys($editor, 'O');

    return cutsceneFieldIds($editor);
}

/**
 * Two original maps for the opening fixture: a wide night plain with a
 * walled edge, and the dawn field it transfers to.
 */
function writeOpeningMaps(string $root): void
{
    $write = static function (string $id, array $tiles, array $data) use ($root): void {
        $folder = $root . '/assets/Maps/' . $id;
        mkdir($folder, 0o777, true);
        $width = strlen($tiles[0]);
        $events = array_fill(0, count($tiles), str_repeat(' ', $width));
        $leaf = basename($id);
        file_put_contents($folder . '/' . $leaf . '.map.php', "<?php\n\nreturn <<<'ICHILOTO_MAP'\n" . implode("\n", $tiles) . "\nICHILOTO_MAP;\n");
        file_put_contents($folder . '/' . $leaf . '.event.php', "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n" . implode("\n", $events) . "\nICHILOTO_EVENT_MAP;\n");
        file_put_contents($folder . '/' . $leaf . '.data.php', "<?php\n\nreturn " . var_export($data, true) . ";\n");
    };

    $night = ['#' . str_repeat('#', 58) . '#'];

    for ($row = 1; $row < 17; $row++) {
        $night[] = '#' . str_repeat(' ', 58) . '#';
    }

    $night[] = str_repeat('#', 60);
    $write('skyfield-night', $night, ['name' => 'Skyfield at Night', 'region' => 'Plain', 'description' => 'A wide plain under stars.', 'triggers' => [], 'events' => [], 'npcs' => [['id' => 'watcher', 'name' => 'Watcher', 'sprite' => 'W', 'x' => 30, 'y' => 14]]]);

    $dawn = [str_repeat('#', 40)];

    for ($row = 1; $row < 11; $row++) {
        $dawn[] = '#' . str_repeat(' ', 38) . '#';
    }

    $dawn[] = str_repeat('#', 40);
    $write('skyfield-dawn', $dawn, ['name' => 'Skyfield at Dawn', 'region' => 'Plain', 'description' => 'The same plain, lit.', 'triggers' => [], 'events' => [], 'npcs' => []]);
}

/**
 * A disposable copy of the Last Legend project, for production verification
 * that never touches the real checkout: authored assets are copied, the
 * audio (which nothing writes) is linked, and the project's own classes are
 * autoloaded the way the console does when it opens a project.
 *
 * @return string|null The copy's root, or null when no Last Legend is pinned.
 */
function disposableLastLegend(string $prefix = 'last-legend-cutscenes-'): ?string
{
    $game = gameSourceRoot();

    if ($game === null || ! is_dir($game . '/assets/Cutscenes/Summons')) {
        return null;
    }

    $root = reserveTemporaryPath($prefix);
    copyLastLegendFixture($game, $root);

    static $autoloaderRegistered = false;

    if (! $autoloaderRegistered) {
        $autoloaderRegistered = true;
        spl_autoload_register(static function (string $class) use ($game): void {
            $prefix = 'Ichiloto\\FinalQuest\\';

            if (! str_starts_with($class, $prefix)) {
                return;
            }

            // The classes are identical in every copy; the pinned checkout's
            // are read, never written.
            $path = $game . '/assets/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($path)) {
                require $path;
            }
        });
    }

    return $root;
}

/**
 * The sha256 of every authored file under a project, excluding the linked
 * audio and any paths whose relative name starts with an excluded prefix.
 *
 * @param string[] $excludePrefixes
 * @return array<string, string>
 */
function authoredHashTree(string $root, array $excludePrefixes = []): array
{
    $hashes = [];

    foreach (sourceHashTree($root . '/assets') as $relative => $hash) {
        if (str_starts_with($relative, 'Audio/')) {
            continue;
        }

        foreach ($excludePrefixes as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                continue 2;
            }
        }

        $hashes[$relative] = $hash;
    }

    return $hashes;
}

/**
 * Evaluates a PHP array literal written in a test expectation.
 *
 * @return array<string, mixed>
 */
function require_pair_data(string $literal): array
{
    /** @var array<string, mixed> $value */
    $value = eval('return ' . $literal . ';');

    return $value;
}

/**
 * An original flat field effect: one glyph track and a sound cue, with a
 * comment the editor must keep.
 */
function emberSparkTimeline(): string
{
    return <<<'PHP_SOURCE'
<?php

// Ember Spark: a small flicker over its target.
return [
  'fps' => 10,
  'lengthFrames' => 6,
  'tracks' => [[
    'id' => 'spark',
    'type' => 'glyph',
    'keyframes' => [
      ['frame' => 0, 'duration' => 3, 'content' => '*', 'position' => ['x' => 0, 'y' => 0], 'color' => 'yellow'],
      ['frame' => 3, 'duration' => 3, 'content' => '+', 'position' => ['x' => 1, 'y' => 0], 'color' => 'red'],
    ],
  ]],
  'cues' => [['id' => 'crackle', 'frame' => 0, 'type' => 'playSound', 'payload' => ['sound' => 'crackle']]],
];
PHP_SOURCE;
}

/**
 * An original battle effect with separate terminal and graphical sequences:
 * a glyph stroke in the terminal, a two-frame image stroke on screen.
 */
function duskSlashTimeline(): string
{
    return <<<'PHP_SOURCE'
<?php

return [
  'presentations' => [
    'terminal' => [
      'fps' => 12,
      'lengthFrames' => 4,
      'tracks' => [[
        'id' => 'stroke',
        'type' => 'glyph',
        'facing' => 'west',
        'keyframes' => [['frame' => 0, 'duration' => 4, 'content' => '/', 'position' => ['x' => 0, 'y' => 0]]],
      ]],
    ],
    'graphical' => [
      'fps' => 12,
      'lengthFrames' => 2,
      'restFrame' => 1,
      'tracks' => [[
        'id' => 'stroke-art',
        'type' => 'image',
        'asset' => 'Graphics/Effects/dusk-slash.png',
        'sheet' => ['columns' => 2, 'rows' => 1],
        'facing' => 'west',
        'keyframes' => [['frame' => 0, 'sourceFrame' => 0], ['frame' => 1, 'sourceFrame' => 1]],
      ]],
    ],
  ],
];
PHP_SOURCE;
}

/** A project holding the two original effects under assets/Animations. */
function effectProject(): string
{
    $root = cutsceneProject();
    foreach (['ember-spark' => emberSparkTimeline(), 'dusk-slash' => duskSlashTimeline()] as $id => $source) {
        @mkdir($root . '/assets/Animations/' . $id, 0o777, true);
        file_put_contents($root . '/assets/Animations/' . $id . '/' . $id . '.timeline.php', $source);
    }
    @mkdir($root . '/assets/Graphics/Effects', 0o777, true);
    writeTilesetTestPng($root . '/assets/Graphics/Effects/dusk-slash.png', 32, 16);

    return $root;
}

/** The path of an effect's timeline under a project's assets/Animations. */
function effectTimelinePath(string $root, string $id): string
{
    return $root . '/assets/Animations/' . $id . '/' . $id . '.timeline.php';
}

/** The terminal editor's Cutscenes screen on one effect of the project, at the record's own rows. */
function openEffectEditor(string $root, string $id): Editor
{
    $editor = cutscenesEditor($root, 160, 50);
    callEditorMethod($editor, 'switchCutsceneType', CutsceneType::EFFECT);
    callEditorMethod($editor, 'selectCutsceneById', $id);
    setEditorProperty($editor, 'databaseCommandFramePath', []);

    return $editor;
}

/**
 * The graphical editor's session on one effect of the project, with its track rows: the session, the
 * effect's index, a row by its field, and an edit of a row by its field.
 *
 * @return array{0: EditorSession, 1: int, 2: Closure(string): array<string, mixed>, 3: Closure(string, string): array<string, mixed>}
 */
function openEffectTrackSession(string $root, string $id): array
{
    $session = EditorSession::open($root);
    $index = findEffectIndex($session, $id);
    $row = static fn(string $field): array => array_find($session->readDatabaseRecord('cutscenes/effect', $index, ['tracks'])['rows'],
        static fn(array $entry): bool => ($entry['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException("No row {$field}.");
    $apply = static fn(string $field, string $value): array => $session->applyDatabaseRecord('cutscenes/effect', $index, $row($field)['key'], $value);

    return [$session, $index, $row, $apply];
}

/** The harbour cinematic of {@see cutsceneProject()}, as the Engine reads it. */
function harbourDefinition(string $root): \Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition
{
    $folder = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns';
    $data = ProjectDirectoryContext::run($root, static fn(): mixed => require $folder . '/harbour-lanterns.data.php');
    $script = ProjectDirectoryContext::run($root, static fn(): mixed => require $folder . '/harbour-lanterns.script.php');

    return CutsceneHydration::cinematic($data, $script, $root);
}

/** The editor window's READY: what its graphical view can draw, as the game's renderer says it. */
function previewWindowReady(): string
{
    return json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => [
        RendererSessionConfig::SPRITE_SOURCE_RECT, RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_OVERLAY,
        RendererSessionConfig::CANVAS_CLIP_OPACITY, RendererSessionConfig::FRAME_VIEWPORT, RendererSessionConfig::FIELD_MOTION,
        RendererSessionConfig::SPRITE_LIFT, RendererSessionConfig::CANVAS_IMAGE_TONE, RendererSessionConfig::CANVAS_IMAGE_FLIP,
    ]]);
}

/**
 * Exchanges with the editor window until the frame the Engine has begun is
 * whole, as a window receives it across its ticks. The Engine's retained
 * upload yields on its own time budget, so one exchange may carry only the
 * first packets of a frame, or none of them yet; each answer says whether a
 * delivery is pending, and each later exchange carries no events and gives
 * the upload its next turn. An exchange with nothing to send is returned as
 * it is.
 *
 * Every view the Editor draws through the Engine's retained presentation is
 * driven through here: a session's answer as it is, for a cinematic, an
 * effect's field or a map's canvas, in process or over the session host's
 * wire, and a preview itself through {@see exchangePreviewSceneUntilPresented()}.
 *
 * @param Closure(list<string>): array<string, mixed> $exchange One exchange: the window's events in, the answer out, with its `messages` and `deliveryPending`.
 * @param list<string> $events The window's answers for the first exchange.
 * @return array<string, mixed> The last answer, its `messages` every message sent, in order.
 * @throws RuntimeException When the frame is still not whole after the bound of exchanges.
 */
function exchangeSceneUntilPresented(Closure $exchange, array $events, int $maxExchanges = 64): array
{
    $answer = $exchange($events);
    $messages = $answer['messages'];
    for ($exchanges = 1; $answer['deliveryPending'] || isPreviewFrameUnfinished($messages); $exchanges++) {
        if ($exchanges >= $maxExchanges) {
            throw new RuntimeException(sprintf('The preview frame was not whole within %d exchanges.', $maxExchanges));
        }
        $answer = $exchange([]);
        array_push($messages, ...$answer['messages']);
    }

    return [...$answer, 'messages' => $messages];
}

/**
 * {@see exchangeSceneUntilPresented()} for a preview session or field
 * itself, which exchanges a scene and answers hasPendingSceneDelivery().
 *
 * @param object $preview A preview session or field.
 * @param list<string> $events The window's answers for the first exchange.
 * @return list<array{type: string, payload: array<string, mixed>}> Every message sent, in order.
 * @throws RuntimeException When the frame is still not whole after the bound of exchanges.
 */
function exchangePreviewSceneUntilPresented(object $preview, array $events, ?string $sessionId, int $maxExchanges = 64): array
{
    return exchangeSceneUntilPresented(static fn(array $events): array => [
        'messages' => $preview->exchangeScene($events, $sessionId),
        'deliveryPending' => $preview->hasPendingSceneDelivery(),
    ], $events, $maxExchanges)['messages'];
}

/**
 * Whether the last frame packet sent belongs to a frame that has not been presented yet.
 *
 * @param list<array{type: string, payload: array<string, mixed>}> $messages
 */
function isPreviewFrameUnfinished(array $messages): bool
{
    $last = array_find(array_reverse($messages), static fn(array $message): bool => $message['type'] === 'frame');

    return $last !== null && ($last['payload']['present'] ?? false) !== true;
}

/** @param list<array{type: string, payload: array<string, mixed>}> $messages */
function previewFrameGenerations(array $messages): array
{
    return array_values(array_filter(array_map(static fn(array $message): ?int => $message['type'] === 'frame'
        ? ($message['payload']['generation'] ?? null) : null, $messages), static fn(?int $generation): bool => $generation !== null));
}

/** A row of the harbour cinematic's record, or of its command list when the field names a command. */
function getCinematicRow(EditorSession $session, string $field): array
{
    // Script commands are rows of the command list's frame; cast members are rows of the record.
    $frame = str_starts_with($field, 'command') ? [CutsceneAsset::COMMANDS_KEY] : [];

    return array_find($session->readDatabaseRecord('cutscenes/cinematic', 0, $frame)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? '') === $field)
        ?? throw new RuntimeException('Missing cinematic row ' . $field);
}

function setCinematicRow(EditorSession $session, string $field, string $value): array
{
    return $session->applyDatabaseRecord('cutscenes/cinematic', 0, getCinematicRow($session, $field)['key'], $value);
}

/** The harbour cinematic, its title card replaced by a command, on a map with one world object. */
function createHarbourCommandProject(array $command): string
{
    $root = cutsceneProject();
    file_put_contents($root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php', str_replace(
        "['type' => 'title_card', 'title' => 'HARBOUR LANTERNS', 'seconds' => 0.2],", var_export($command, true) . ',', harbourCinematicScript()));
    $mapPath = $root . '/assets/Maps/harbour/harbour.data.php';
    $map = require $mapPath;
    $map['worldObjects'] = [['id' => 'beacon', 'anchor' => ['x' => 6, 'y' => 3], 'pivot' => ['x' => 0.5, 'y' => 1.0], 'sprites2d' => null]];
    file_put_contents($mapPath, "<?php\nreturn " . var_export($map, true) . ";\n");

    return $root;
}

/** @return array{string, string} The harbour cinematic's data file and script file. */
function getHarbourPaths(string $root): array
{
    $directory = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/';

    return [$directory . 'harbour-lanterns.data.php', $directory . 'harbour-lanterns.script.php'];
}

/**
 * A script for the harbour cinematic that frames two phases, built the way authors build long scenes: a helper
 * closure that returns a command, a list built in a loop and spread into a block, and comments between them.
 */
function framedHarbourScript(): string
{
    return <<<'PHP_SOURCE'
<?php

// Framed by hand.
$line = static fn(string $text): array => ['type' => 'narration', 'text' => $text, 'seconds' => 0.2];
$drift = [];
foreach (['boat-east', 'boat-west'] as $boat) {
  $drift[] = ['type' => 'move_route', 'subject' => 'staged_actor', 'actorId' => $boat, 'secondsPerStep' => 0.1, 'steps' => [['direction' => 'right', 'count' => 2]]];
}

return [
  ['type' => 'camera', 'operation' => 'detach'],
  ['type' => 'framing', 'subjects' => ['keeper', ['kind' => 'staged_actor', 'id' => 'boat-east']], 'commands' => [
    // The keeper watches.
    $line('The keeper watched the first boat.'),
    ...$drift,
    ['type' => 'framing', 'subjects' => ['lead'], 'commands' => [
      ['type' => 'wait', 'seconds' => 0.2],
    ]],
  ]],
  ['type' => 'checkpoint', 'name' => 'boats-crossed'],
];
PHP_SOURCE;
}

/**
 * The harbour cinematic binding two framing subjects, `lead` (the player) and `keeper` (the quay's NPC), with
 * {@see framedHarbourScript()} as its script unless it is given another.
 *
 * @return array{string, string, string} The project root, the data path and the script path.
 */
function framedHarbourProject(?string $script = null): array
{
    $root = cutsceneProject();
    [$dataPath, $scriptPath] = getHarbourPaths($root);
    file_put_contents($dataPath, str_replace("'presentation' => ['initial' => 'hidden'],",
        "'presentation' => ['initial' => 'hidden', 'framing' => ['subjects' => [\n"
        . "    // Who a phase keeps in view.\n"
        . "    'lead' => ['kind' => 'player'],\n"
        . "    'keeper' => ['kind' => 'npc', 'id' => 'keeper'],\n"
        . "  ]]],", harbourCinematicData()));
    file_put_contents($scriptPath, $script ?? framedHarbourScript());

    return [$root, $dataPath, $scriptPath];
}

/**
 * A reusable event script that stages its own actors and frames a phase on one of them inside a branch arm, built
 * with a helper closure and a looped list, as {@see framedHarbourScript()} is.
 */
function framedRescueScript(): string
{
    return <<<'PHP_SOURCE'
<?php

$stage = static fn(string $id, int $x): array => ['type' => 'stage_actor', 'id' => $id, 'sprite' => ['#'], 'x' => $x, 'y' => 3];
$walk = [];
for ($step = 0; $step < 2; $step++) {
  $walk[] = ['type' => 'move_route', 'subject' => 'player', 'secondsPerStep' => 0.1, 'steps' => [['direction' => 'left']]];
}

return [
  [
    'type' => 'branch',
    'conditions' => [['type' => 'event', 'name' => 'rescued', 'negate' => true]],
    'then' => [
      $stage('crate', 3),
      ['type' => 'framing', 'subjects' => [['kind' => 'staged_actor', 'id' => 'crate']], 'commands' => [
        // Said over the crate.
        ['type' => 'narration', 'text' => 'The crate shifts.', 'seconds' => 1],
        $stage('helper', 5),
        ...$walk,
      ]],
      ['type' => 'remove_actor', 'actorId' => 'crate'],
    ],
    'else' => [],
  ],
];
PHP_SOURCE;
}

/**
 * A project whose harbour cinematic, which cannot be skipped, runs the `rescue` reusable event script
 * ({@see framedRescueScript()}, unless it is given another) right after it detaches the camera.
 *
 * @return array{string, string} The project root and the event script's path.
 */
function framedRescueProject(?string $script = null): array
{
    $root = cutsceneProject();
    $path = $root . '/assets/Events/rescue.php';
    file_put_contents($path, $script ?? framedRescueScript());
    [$dataPath, $scriptPath] = getHarbourPaths($root);
    // Authored skipping cannot prove a reusable script safe, so the Engine runs a cinematic that calls one to the end.
    file_put_contents($dataPath, str_replace("'skip' => ['policy' => 'authored'],", "'skip' => ['policy' => 'forbidden'],", harbourCinematicData()));
    file_put_contents($scriptPath, str_replace("['type' => 'camera', 'operation' => 'detach'],",
        "['type' => 'camera', 'operation' => 'detach'],\n  ['type' => 'common_event', 'id' => 'rescue'],", harbourCinematicScript()));

    return [$root, $path];
}

/** A record row by its field id, in a frame of the record. */
function getRecordRow(EditorSession $session, string $category, int $index, string $field, array $frame = []): array
{
    return array_find($session->readDatabaseRecord($category, $index, $frame)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException(sprintf('Missing %s row %s.', $category, $field));
}

function setRecordRow(EditorSession $session, string $category, int $index, string $field, string $value, array $frame = []): array
{
    return $session->applyDatabaseRecord($category, $index, getRecordRow($session, $category, $index, $field, $frame)['key'], $value);
}

/**
 * A synthetic battle effect with a captured ground span, written as an author writes one: a lane of light whose
 * rear (the pivot) is held at the caster's ground and whose tip at the farthest selected target's.
 */
function groundLanceTimeline(): string
{
    return <<<'PHP_SOURCE'
<?php

// Ground Lance: a lane of light from the caster's feet to the farthest target.
return [
  'presentations' => [
    'terminal' => [
      'fps' => 12,
      'lengthFrames' => 4,
      'tracks' => [[
        'id' => 'lane',
        'type' => 'glyph',
        'keyframes' => [['frame' => 0, 'duration' => 4, 'content' => '=', 'position' => ['x' => 0, 'y' => 0]]],
      ]],
    ],
    'graphical' => [
      'fps' => 24,
      'lengthFrames' => 12,
      'targetCaptureFrame' => 2, // the targets and the caster's ground are held from here
      'restFrame' => 3,
      'tracks' => [
        // The lane: its rear on the caster's ground, its tip on the farthest target's.
        ['id' => 'lane-art', 'type' => 'image', 'asset' => 'Graphics/Effects/ground-lance.png',
         'anchor' => 'target-group', 'attachment' => 'ground', 'anchorLifetime' => 'target-capture', 'fit' => 'contain',
         'pivot' => ['x' => 0.8828125, 'y' => 0.53125],   'groundSpan' => ['tip' => ['x' => 0.1328125, 'y' => 0.46875]], /* measured on the sheet */
         'depth' => 'behind',
         'keyframes' => [['frame' => 3, 'duration' => 8]]],
      ],
    ],
  ],
];
PHP_SOURCE;
}

/** The colour of a texel of Ground Lance's 64 by 16 image: its column in red and its row in green, every texel opaque. */
function getGroundLanceTexel(int $x, int $y): array
{
    return [20 + 3 * $x, 30 + 13 * $y, 90];
}

/**
 * The effect battle preview project, its unarmed attack playing Ground Lance on its targets. The image's rear
 * (the pivot) is the centre of its texel (56, 8) and its tip the centre of its texel (8, 7).
 */
function groundLanceProject(): string
{
    $root = effectBattlePreviewProject();
    mkdir($root . '/assets/Animations/ground-lance', 0o777, true);
    file_put_contents(effectTimelinePath($root, 'ground-lance'), groundLanceTimeline());
    $image = imagecreatetruecolor(64, 16);
    for ($y = 0; $y < 16; $y++) {
        for ($x = 0; $x < 64; $x++) {
            imagesetpixel($image, $x, $y, (int) imagecolorallocate($image, ...getGroundLanceTexel($x, $y)));
        }
    }
    imagepng($image, $root . '/assets/Graphics/Effects/ground-lance.png');
    file_put_contents($root . '/assets/Data/animations.php', "<?php\n\nreturn " . var_export([
        ['id' => 1, 'name' => 'Ground Lance', 'roles' => ['attack', 'attack-unarmed'], 'targetEffect' => 'ground-lance'],
    ], true) . ";\n");

    return $root;
}
