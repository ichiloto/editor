<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\ProjectWorkspace;
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
    writeFireballSkill($root);

    return $root;
}

/**
 * The one battle action the lantern summon links to.
 */
function writeFireballSkill(string $root): void
{
    file_put_contents($root . '/assets/Data/skills.php', <<<'PHP_SOURCE'
<?php

use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;

return [
  new MagicSkill(
    'Fireball',
    'A burst of flame.',
    'FIR',
    4,
    0,
    new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ONE, ItemScopeStatus::ALIVE),
    Occasion::BATTLE_SCREEN,
    new SkillInvocation('$1 casts Fireball!', 0, 0, 1, 10),
    [
      new HPDamageSkillEffect('$user->stats->magicAttack * 3', NULL, 0.2, false),
    ],
  ),
];
PHP_SOURCE);
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

    $root = rememberTemporaryProject(sys_get_temp_dir() . '/' . uniqid($prefix, true));
    mkdir($root . '/assets', 0o777, true);

    foreach (scandir($game . '/assets') ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $source = $game . '/assets/' . $entry;

        if ($entry === 'Audio') {
            symlink($source, $root . '/assets/' . $entry);
        } elseif (is_dir($source)) {
            mkdir($root . '/assets/' . $entry, 0o777, true);
            copyDirectoryRecursively($source, $root . '/assets/' . $entry);
        } else {
            copy($source, $root . '/assets/' . $entry);
        }
    }

    foreach (['config.php', 'ichiloto.json', 'input.php', 'composer.json'] as $entry) {
        if (is_file($game . '/' . $entry)) {
            copy($game . '/' . $entry, $root . '/' . $entry);
        }
    }

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
