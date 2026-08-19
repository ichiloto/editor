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
