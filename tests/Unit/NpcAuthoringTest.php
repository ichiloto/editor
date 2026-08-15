<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Database\WorldWriteCodec;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\Field\NpcCollection;
use Ichiloto\Editor\Field\NpcReferences;
use Ichiloto\Editor\Field\ProjectNpc;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Editor\Validation\Severity;

/**
 * Writes a map's data file from an array, the way the fixtures are written.
 *
 * @param string $path The data file path.
 * @param array<string, mixed> $data The map data.
 * @return void
 */
function writeNpcMapData(string $path, array $data): void
{
    file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");
}

/**
 * Returns a throwaway project whose fixture map carries the given NPCs and
 * events, and the map's data path.
 *
 * @param array<int, mixed> $npcs The npcs block.
 * @param array<string, mixed>|null $events Event definitions to merge, if any.
 * @return array{0: string, 1: string} The project root and the map data path.
 */
function npcProject(array $npcs, ?array $events = null): array
{
    $root = makeTemporaryProject('ichiloto-npc-');
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $data['npcs'] = $npcs;

    if ($events !== null) {
        $data['events'] = array_replace($data['events'] ?? [], $events);
    }

    writeNpcMapData($path, $data);

    return [$root, $path];
}

/**
 * Builds an unbooted editor over a project, on the canvas, in NPC mode.
 */
function npcEditor(string $root): Editor
{
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);
    setEditorProperty($editor, 'focusedPane', 'canvas');
    callEditorMethod($editor, 'setEditingMode', 'npc');

    return $editor;
}

/**
 * Returns the editor's selected map.
 */
function npcMap(Editor $editor): ProjectMap
{
    $map = callEditorMethod($editor, 'getSelectedMap');
    expect($map)->toBeInstanceOf(ProjectMap::class);

    return $map;
}

/**
 * Creates an NPC at a tile through the canvas flow: Enter, a typed name,
 * Enter.
 */
function createNpcThroughCanvas(Editor $editor, int $x, int $y, string $name): void
{
    setEditorProperty($editor, 'cursorX', $x);
    setEditorProperty($editor, 'cursorY', $y);
    setEditorProperty($editor, 'focusedPane', 'canvas');
    callEditorMethod($editor, 'dispatchInput', "\r");

    foreach (mb_str_split($name) as $character) {
        callEditorMethod($editor, 'dispatchInput', $character);
    }

    callEditorMethod($editor, 'dispatchInput', "\r");
}

/**
 * Returns the Inspector descriptor of an NPC field by its id.
 *
 * @return array<string, mixed>
 */
function npcField(Editor $editor, string $fieldId): array
{
    foreach (callEditorMethod($editor, 'getInspectorFields') as $field) {
        if (is_array($field) && ($field['field'] ?? null) === $fieldId) {
            return $field;
        }
    }

    throw new RuntimeException(sprintf('No Inspector field "%s".', $fieldId));
}

/**
 * Applies a value to an NPC field through the recorded write path.
 */
function setNpcField(Editor $editor, string $fieldId, string $value): void
{
    callEditorMethod($editor, 'applyNpcFieldValueRecorded', npcField($editor, $fieldId), $value);
}

/**
 * Puts the Inspector cursor on a field.
 */
function restNpcCursorOn(Editor $editor, string $fieldId): void
{
    foreach (callEditorMethod($editor, 'getInspectorFields') as $index => $field) {
        if (is_array($field) && ($field['field'] ?? null) === $fieldId) {
            setEditorProperty($editor, 'databaseSelectedSettingIndex', $index);

            return;
        }
    }

    throw new RuntimeException(sprintf('No Inspector field "%s".', $fieldId));
}

/**
 * Loads a map's NPC entries fresh from disk.
 *
 * @return array<int, mixed>
 */
function npcsOnDisk(string $path): array
{
    return (require $path)['npcs'] ?? [];
}

/**
 * Hashes every file under a directory, keyed by relative path.
 *
 * @return array<string, string> The SHA-256 matrix.
 */
function npcHashTree(string $directory): array
{
    $hashes = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $hashes[substr($file->getPathname(), strlen($directory) + 1)] = hash_file('sha256', $file->getPathname());
        }
    }

    ksort($hashes);

    return $hashes;
}

/**
 * Returns the messages of validation issues, one line each.
 *
 * @return string[]
 */
function npcIssueLines(string $root, ?Severity $severity = null): array
{
    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));

    return array_values(array_map(
        static fn($issue): string => $issue->where . ': ' . $issue->message,
        array_filter($issues, static fn($issue): bool => $severity === null || $issue->severity === $severity),
    ));
}

// -- Model -----------------------------------------------------------------

it('keeps an NPC entry verbatim and reads every runtime field from it', function () {
    $entry = [
        'id' => 'gate-guard',
        'name' => 'Gate Guard',
        'sprite' => '<fg=#ffaf00>@</>',
        'x' => 19,
        'y' => 8,
        'movement' => 'wander',
        'wanderArea' => ['x' => 17, 'y' => 6, 'width' => 5, 'height' => 4],
        'sprites' => ['north' => '^', 'SOUTH' => 'v'],
        'conditions' => [['type' => 'switch', 'name' => 'gate_open']],
        'dialogue' => [['text' => 'Halt.']],
        'script' => [],
        'sets' => [['type' => 'switch', 'name' => 'met_guard']],
        'mood' => 'stern',
    ];
    $npc = new ProjectNpc($entry);

    expect($npc->toArray())->toBe($entry)
        ->and($npc->getId())->toBe('gate-guard')
        ->and($npc->getName())->toBe('Gate Guard')
        ->and($npc->getSprite())->toBe('<fg=#ffaf00>@</>')
        ->and($npc->getVisibleSprite())->toBe('@')
        ->and($npc->getSpriteWidth())->toBe(1)
        ->and($npc->getX())->toBe(19)
        ->and($npc->getY())->toBe(8)
        ->and($npc->wanders())->toBeTrue()
        ->and($npc->getWanderArea())->toBe(['x' => 17, 'y' => 6, 'width' => 5, 'height' => 4])
        // Read by heading in one case; the payload keeps the author's.
        ->and($npc->getDirectionalSprites())->toBe(['north' => '^', 'south' => 'v'])
        ->and($npc->toArray()['sprites'])->toBe(['north' => '^', 'SOUTH' => 'v'])
        ->and($npc->getConditions())->toHaveCount(1)
        ->and($npc->getDialogue())->toBe([['text' => 'Halt.']])
        ->and($npc->getSets())->toHaveCount(1)
        ->and($npc->getUnknownFields())->toBe(['mood'])
        ->and($npc->scriptShadowsDialogue())->toBeFalse();
});

it('creates a fresh NPC with its id first, fixed, and a page that follows its name', function () {
    $npc = ProjectNpc::createAt('gate-guard', 'Gate Guard', 3, 2);

    expect(array_keys($npc->toArray()))->toBe(['id', 'name', 'sprite', 'x', 'y', 'movement', 'dialogue'])
        ->and($npc->getMovement())->toBe('fixed')
        // No pinned speaker: the runtime titles the page with the NPC's own
        // name, so a rename carries through.
        ->and($npc->getDialogue())->toBe([['text' => 'Hello.']]);
});

it('measures sprites the way the terminal draws them, with or without the engine', function () {
    expect(ProjectNpc::strippedGlyph('<fg=#ff87af>@</>'))->toBe('@')
        ->and(ProjectNpc::glyphWidth('<fg=#ff87af>@</>'))->toBe(1)
        ->and(ProjectNpc::glyphWidth('🐈'))->toBe(2)
        ->and(ProjectNpc::strippedGlyph("\e[33m@\e[0m"))->toBe('@')
        ->and(ProjectNpc::visibleGlyph('<fg=red>'))->toBe('@')
        ->and(ProjectNpc::strippedGlyph('<'))->toBe('<');
});

it('refuses to change an id through with(), and folds dialogue both ways', function () {
    $npc = ProjectNpc::createAt('a', 'A', 0, 0);

    // Nothing renames an id: with() hands the same NPC back.
    expect($npc->with('id', 'b')->getId())->toBe('a')
        ->and($npc->with('name', 'B')->getId())->toBe('a');

    // Plain pages read as one unconditioned variant, and write back as pages.
    $variants = $npc->getDialogueAsVariants();

    expect($variants)->toBe([['lines' => [['text' => 'Hello.']]]])
        ->and(ProjectNpc::dialogueFromVariants($variants))->toBe([['text' => 'Hello.']]);

    // A conditioned variant stays a variant list.
    $conditioned = [['conditions' => [['type' => 'switch', 'name' => 'x']], 'lines' => [['text' => 'Hi']]]];

    expect(ProjectNpc::dialogueFromVariants($conditioned))->toBe($conditioned)
        ->and(new ProjectNpc(['name' => 'n', 'x' => 0, 'y' => 0, 'dialogue' => $conditioned])->hasDialogueVariants())->toBeTrue();
});

it('gives the collection CRUD that keeps positions and raw entries', function () {
    $collection = NpcCollection::fromMapData([
        ['id' => 'a', 'name' => 'A', 'x' => 1, 'y' => 1],
        'not an npc',
        ['name' => 'Legacy', 'x' => 2, 'y' => 2],
    ]);

    // Indices are list positions, so a raw entry keeps its slot and the
    // NPCs around it keep theirs.
    expect($collection->count())->toBe(2)
        ->and($collection->ids())->toBe(['a'])
        ->and($collection->indexAt(2, 2))->toBe(2)
        ->and($collection->indexOfId('a'))->toBe(0)
        ->and($collection->get(1))->toBeNull()
        ->and($collection->toMapData()[1])->toBe('not an npc');

    $added = $collection->withAdded(ProjectNpc::createAt('c', 'C', 3, 3));
    $inserted = $added->withInsertedAt(0, ProjectNpc::createAt('z', 'Z', 4, 4));

    expect($added->count())->toBe(3)
        ->and(array_keys($added->all()))->toBe([0, 2, 3])
        ->and($inserted->get(0)?->getId())->toBe('z')
        ->and($inserted->withRemoved(0)->toMapData())->toBe($added->toMapData())
        ->and($inserted->withReplaced(1, ProjectNpc::createAt('a', 'Renamed', 1, 1))->get(1)?->getName())->toBe('Renamed');

    // Ids derive from names, unique on the map, numbered on collision.
    expect($collection->uniqueIdFor('A'))->toBe('a-2')
        ->and($collection->uniqueIdFor('Gate Guard'))->toBe('gate-guard')
        ->and($collection->uniqueIdFor('!!!'))->toBe('npc');
});

it('rejects a second NPC under an id the map already has', function () {
    $collection = NpcCollection::fromMapData([['id' => 'a', 'name' => 'A', 'x' => 1, 'y' => 1]]);

    expect(fn() => $collection->withAdded(ProjectNpc::createAt('a', 'Again', 2, 2)))->toThrow(RuntimeException::class);
});

// -- Canvas: create, select, list, move, duplicate, delete -----------------

it('enters NPC mode on F3, names a new NPC, and derives its id from that name', function () {
    [$root, $path] = npcProject([]);
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);
    setEditorProperty($editor, 'focusedPane', 'canvas');

    // Both encodings the decoder emits for F3.
    callEditorMethod($editor, 'dispatchInput', "\033OR");
    expect(getEditorProperty($editor, 'editingMode'))->toBe('npc');
    callEditorMethod($editor, 'dispatchInput', "\033[13~");
    expect(getEditorProperty($editor, 'editingMode'))->toBe('map');
    callEditorMethod($editor, 'dispatchInput', "\033OR");

    createNpcThroughCanvas($editor, 4, 2, 'Gate Guard');

    $map = npcMap($editor);
    $npc = $map->getNpcs()->get(0);

    expect($npc)->not->toBeNull()
        ->and($npc->getId())->toBe('gate-guard')
        ->and($npc->getName())->toBe('Gate Guard')
        ->and($npc->getX())->toBe(4)
        ->and($npc->getY())->toBe(2)
        ->and(getEditorProperty($editor, 'selectedNpcIndex'))->toBe(0)
        // The author lands in the Inspector to keep authoring.
        ->and(getEditorProperty($editor, 'focusedPane'))->toBe('inspector')
        // Nothing was painted: tile and event layers are untouched.
        ->and($map->getTileSymbol(4, 2))->toBe(' ')
        ->and(trim($map->getEventSymbol(4, 2)))->toBe('');

    // Enter with an empty name falls back to a placeholder rather than
    // refusing; Esc creates nothing.
    createNpcThroughCanvas($editor, 5, 2, '');
    expect($map->getNpcs()->get(1)?->getId())->toBe('new-npc');
    setEditorProperty($editor, 'focusedPane', 'canvas');
    setEditorProperty($editor, 'cursorX', 6);
    callEditorMethod($editor, 'dispatchInput', "\r");
    callEditorMethod($editor, 'dispatchInput', "\033");
    expect($map->getNpcs()->count())->toBe(2);
});

it('selects an NPC under the cursor, from the list, and by stepping', function () {
    [$root] = npcProject([
        ['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1],
        ['id' => 'b', 'name' => 'Bob', 'sprite' => 'B', 'x' => 6, 'y' => 3],
    ]);
    $editor = npcEditor($root);

    setEditorProperty($editor, 'cursorX', 6);
    setEditorProperty($editor, 'cursorY', 3);
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect(getEditorProperty($editor, 'selectedNpcIndex'))->toBe(1);

    // ] and [ step through the collection and carry the cursor along.
    setEditorProperty($editor, 'focusedPane', 'canvas');
    callEditorMethod($editor, 'dispatchInput', ']');
    expect(getEditorProperty($editor, 'selectedNpcIndex'))->toBe(0)
        ->and(getEditorProperty($editor, 'cursorX'))->toBe(2)
        ->and(getEditorProperty($editor, 'cursorY'))->toBe(1);
    callEditorMethod($editor, 'dispatchInput', '[');
    expect(getEditorProperty($editor, 'selectedNpcIndex'))->toBe(1);

    // L opens the map-local list; typing narrows it; Enter selects.
    callEditorMethod($editor, 'dispatchInput', 'l');
    $lines = callEditorMethod($editor, 'getInspectorLines');
    expect(implode("\n", $lines))->toContain('NPCs on this map')
        ->and(implode("\n", $lines))->toContain('Ann  (a)')
        ->and(implode("\n", $lines))->toContain('Bob  (b)');
    callEditorMethod($editor, 'dispatchInput', 'A');
    callEditorMethod($editor, 'dispatchInput', 'n');
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect(getEditorProperty($editor, 'selectedNpcIndex'))->toBe(0)
        ->and(getEditorProperty($editor, 'cursorX'))->toBe(2);
});

it('draws NPCs as an overlay: wide glyphs own two columns, styled ones one, the selected one bracketed', function () {
    [$root] = npcProject([
        ['id' => 'cat', 'name' => 'Cat', 'sprite' => '🐈', 'x' => 2, 'y' => 2],
        ['id' => 'mum', 'name' => 'Mum', 'sprite' => '<fg=#ff87af>@</>', 'x' => 6, 'y' => 2],
        ['id' => 'guard', 'name' => 'Guard', 'sprite' => 'G', 'x' => 9, 'y' => 3],
    ]);
    $map = ProjectWorkspace::fromProject($root)->getMapByIndex(0);

    $plain = $map->renderPreview(12, 5, showNpcOverlay: false);
    $overlay = $map->renderPreview(12, 5, showNpcOverlay: true, selectedNpcIndex: 2);

    // Not persisted art: without the overlay flag the rows are the tiles.
    expect($plain[2])->toBe('#          #')
        // The cat's second column is the empty overhang cell, so the
        // terminal draws the emoji in the room it needs; Mum is one plain
        // @; the selected guard is bracketed.
        ->and($overlay[2])->toBe('# 🐈  @    #')
        ->and($overlay[3])->toBe('#       [G]#')
        ->and(mb_strwidth($overlay[2]))->toBe(12);

    // A directional sprite previewed for the selected NPC.
    $previewed = $map->renderPreview(12, 5, showNpcOverlay: true, selectedNpcIndex: 2, selectedNpcSprite: '<fg=cyan>^</>');
    // Still the selected NPC, so still bracketed.
    expect($previewed[3])->toBe('#       [^]#');
});

it('moves an NPC on the canvas, and undoes and redoes the move', function () {
    [$root] = npcProject([['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1]]);
    $editor = npcEditor($root);
    $map = npcMap($editor);

    callEditorMethod($editor, 'selectNpc', 0);
    setEditorProperty($editor, 'focusedPane', 'canvas');
    callEditorMethod($editor, 'dispatchInput', 'm');
    setEditorProperty($editor, 'cursorX', 8);
    setEditorProperty($editor, 'cursorY', 3);
    callEditorMethod($editor, 'dispatchInput', "\r");

    expect($map->getNpcs()->get(0)?->getX())->toBe(8)
        ->and($map->getNpcs()->get(0)?->getY())->toBe(3)
        ->and($map->getNpcs()->get(0)?->getId())->toBe('a')
        ->and($map->isDirty())->toBeTrue();

    callEditorMethod($editor, 'performUndo');
    expect($map->getNpcs()->get(0)?->getX())->toBe(2)
        ->and($map->isDirty())->toBeFalse();

    callEditorMethod($editor, 'performRedo');
    expect($map->getNpcs()->get(0)?->getX())->toBe(8)
        ->and($map->isDirty())->toBeTrue();
});

it('duplicates under a fresh id, deletes, and undoes both with positions intact', function () {
    [$root] = npcProject([
        ['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1],
        ['id' => 'b', 'name' => 'Bob', 'sprite' => 'B', 'x' => 6, 'y' => 3],
    ]);
    $editor = npcEditor($root);
    $map = npcMap($editor);

    callEditorMethod($editor, 'selectNpc', 0);
    setEditorProperty($editor, 'focusedPane', 'canvas');
    callEditorMethod($editor, 'dispatchInput', 'd');

    expect($map->getNpcs()->count())->toBe(3)
        ->and($map->getNpcs()->get(2)?->getId())->toBe('ann')
        ->and($map->getNpcs()->get(2)?->getName())->toBe('Ann')
        ->and($map->getNpcs()->get(2)?->getX())->toBe(3);

    callEditorMethod($editor, 'performUndo');
    expect($map->getNpcs()->ids())->toBe(['a', 'b']);
    callEditorMethod($editor, 'performRedo');
    expect($map->getNpcs()->ids())->toBe(['a', 'b', 'ann']);

    // Delete the middle one; undo puts it back at index 1, not the end.
    callEditorMethod($editor, 'selectNpc', 1);
    callEditorMethod($editor, 'dispatchInput', "\033[3~");
    expect($map->getNpcs()->ids())->toBe(['a', 'ann']);
    callEditorMethod($editor, 'performUndo');
    expect($map->getNpcs()->ids())->toBe(['a', 'b', 'ann'])
        ->and(getEditorProperty($editor, 'selectedNpcIndex'))->toBe(1);
});

it('refuses to delete an NPC that a move_route names, and says where', function () {
    [$root] = npcProject(
        [
            ['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1],
            ['id' => 'b', 'name' => 'Bob', 'sprite' => 'B', 'x' => 6, 'y' => 3, 'script' => [
                ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'a', 'steps' => [['direction' => 'north']]],
            ]],
        ],
    );
    $editor = npcEditor($root);
    $map = npcMap($editor);

    callEditorMethod($editor, 'selectNpc', 0);
    setEditorProperty($editor, 'focusedPane', 'canvas');
    callEditorMethod($editor, 'dispatchInput', "\033[3~");

    expect($map->getNpcs()->count())->toBe(2)
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('named by NPC Bob script');

    // Bob names Ann; nothing names Bob.
    expect(new NpcReferences(getEditorProperty($editor, 'workspace'))->describe($map, 'b'))->toBe([]);
    callEditorMethod($editor, 'selectNpc', 1);
    callEditorMethod($editor, 'dispatchInput', "\033[3~");
    expect($map->getNpcs()->ids())->toBe(['a']);
});

// -- Inspector: every field through the shared pane ----------------------

it('edits name, sprite, coordinates and movement without touching the id, hiding wander bounds while fixed', function () {
    [$root, $path] = npcProject([['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1]]);
    $editor = npcEditor($root);
    $map = npcMap($editor);
    callEditorMethod($editor, 'selectNpc', 0);

    $ids = array_map(static fn(array $f): string => (string) ($f['field'] ?? ''), callEditorMethod($editor, 'getInspectorFields'));
    expect($ids)->toContain('name')->toContain('sprite')->toContain('x')->toContain('movement')
        ->and($ids)->not->toContain('wanderArea.width');

    setNpcField($editor, 'name', 'Annabel');
    setNpcField($editor, 'sprite', '<fg=green>a</>');
    setNpcField($editor, 'x', '5');
    setNpcField($editor, 'movement', 'wander');

    $npc = $map->getNpcs()->get(0);
    expect($npc?->getId())->toBe('a')
        ->and($npc?->getName())->toBe('Annabel')
        ->and($npc?->getSprite())->toBe('<fg=green>a</>')
        ->and($npc?->getX())->toBe(5)
        ->and($npc?->wanders())->toBeTrue()
        // The pane reads absent keys as what the runtime does with them.
        ->and(npcField($editor, 'wanderArea.width'))->toBeArray();

    setNpcField($editor, 'wanderArea.x', '4');
    setNpcField($editor, 'wanderArea.y', '1');
    setNpcField($editor, 'wanderArea.width', '3');
    setNpcField($editor, 'wanderArea.height', '2');
    expect($map->getNpcs()->get(0)?->getWanderArea())->toBe(['x' => 4, 'y' => 1, 'width' => 3, 'height' => 2]);

    // Four undos: the bounds go one at a time; the fifth restores movement.
    foreach (range(1, 5) as $step) {
        callEditorMethod($editor, 'performUndo');
    }
    expect($map->getNpcs()->get(0)?->wanders())->toBeFalse()
        ->and($map->getNpcs()->get(0)?->toArray())->not->toHaveKey('wanderArea');

    // Clearing a bound removes the key and prunes an emptied parent.
    setNpcField($editor, 'movement', 'wander');
    setNpcField($editor, 'wanderArea.width', '3');
    setNpcField($editor, 'wanderArea.width', '');
    expect($map->getNpcs()->get(0)?->toArray())->not->toHaveKey('wanderArea');

    // A same-value edit records nothing.
    $before = getEditorProperty($editor, 'history')->count();
    setNpcField($editor, 'name', 'Annabel');
    expect(getEditorProperty($editor, 'history')->count())->toBe($before);
});

it('reads Movement and Sprite as their runtime defaults when unset', function () {
    [$root] = npcProject([['name' => 'Legacy', 'x' => 2, 'y' => 1]]);
    $editor = npcEditor($root);
    callEditorMethod($editor, 'selectNpc', 0);

    expect(npcField($editor, 'movement')['value'])->toBe('fixed')
        ->and(npcField($editor, 'sprite')['value'])->toBe('@');
});

it('authors directional sprites, previews the one under the cursor, and removes them cleanly', function () {
    [$root] = npcProject([['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1]]);
    $editor = npcEditor($root);
    $map = npcMap($editor);
    callEditorMethod($editor, 'selectNpc', 0);

    setNpcField($editor, 'sprites.north', '^');
    setNpcField($editor, 'sprites.west', '<fg=cyan><</>');
    expect($map->getNpcs()->get(0)?->getDirectionalSprites())->toBe(['north' => '^', 'west' => '<fg=cyan><</>']);

    setEditorProperty($editor, 'focusedPane', 'inspector');
    restNpcCursorOn($editor, 'sprites.west');
    expect(callEditorMethod($editor, 'previewedNpcSprite'))->toBe('<fg=cyan><</>');
    restNpcCursorOn($editor, 'name');
    expect(callEditorMethod($editor, 'previewedNpcSprite'))->toBeNull();

    setNpcField($editor, 'sprites.north', '');
    setNpcField($editor, 'sprites.west', '');
    expect($map->getNpcs()->get(0)?->toArray())->not->toHaveKey('sprites');
});

it('authors visibility conditions and completion writes through the shared codecs', function () {
    [$root] = npcProject([['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1]]);
    $editor = npcEditor($root);
    $map = npcMap($editor);
    callEditorMethod($editor, 'selectNpc', 0);

    setNpcField($editor, 'conditions', 'switch:gate_open; !event:left_town');
    setNpcField($editor, 'sets', 'switch:met_ann; variable:visits:add:1; quest:breakfast-duty:grant');

    $npc = $map->getNpcs()->get(0);
    expect($npc?->getConditions())->toBe([
        ['type' => 'switch', 'name' => 'gate_open'],
        ['type' => 'event', 'name' => 'left_town', 'negate' => true],
    ])
        ->and($npc?->getSets())->toBe(WorldWriteCodec::decodeAll('switch:met_ann; variable:visits:add:1; quest:breakfast-duty:grant'))
        ->and($npc?->getSets())->toHaveCount(3);

    setNpcField($editor, 'conditions', '');
    setNpcField($editor, 'sets', '');
    expect($map->getNpcs()->get(0)?->toArray())->not->toHaveKey('conditions')
        ->and($map->getNpcs()->get(0)?->toArray())->not->toHaveKey('sets');
});

it('authors dialogue pages, conditional variants with their lines, and an inline script', function () {
    [$root, $path] = npcProject([['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1, 'dialogue' => [['text' => 'Hi.']]]]);
    $editor = npcEditor($root);
    $map = npcMap($editor);
    callEditorMethod($editor, 'selectNpc', 0);

    // The one plain page shows as variant 1, line 1; the speaker row reads
    // by meaning.
    expect(npcField($editor, 'variant0Line0Text')['value'])->toBe('Hi.')
        ->and(npcField($editor, 'variant0Line0Name')['value'])->toBe("(the NPC's name)");

    setNpcField($editor, 'variant0Line0Name', '(No speaker)');
    expect($map->getNpcs()->get(0)?->getDialogue())->toBe([['text' => 'Hi.', 'name' => '']]);
    setNpcField($editor, 'variant0Line0Name', "(the NPC's name)");
    expect($map->getNpcs()->get(0)?->getDialogue())->toBe([['text' => 'Hi.']]);

    // A second line on the same variant stays plain pages on disk.
    restNpcCursorOn($editor, 'variant0Line0Text');
    callEditorMethod($editor, 'addDatabaseNpcSubItem');
    setNpcField($editor, 'variant0Line1Text', 'Nice day.');
    expect($map->getNpcs()->get(0)?->getDialogue())->toBe([['text' => 'Hi.'], ['text' => 'Nice day.']]);

    // A second variant with a condition turns the whole thing into variants.
    restNpcCursorOn($editor, 'variant0Conditions');
    callEditorMethod($editor, 'addDatabaseNpcSubItem');
    setNpcField($editor, 'variant1Conditions', 'switch:gate_open');
    setNpcField($editor, 'variant1Line0Text', 'The gate is open.');
    $dialogue = $map->getNpcs()->get(0)?->getDialogue();
    expect($dialogue)->toHaveCount(2)
        ->and($dialogue[0])->toBe(['lines' => [['text' => 'Hi.'], ['text' => 'Nice day.']]])
        ->and($dialogue[1]['conditions'])->toBe([['type' => 'switch', 'name' => 'gate_open']])
        ->and($dialogue[1]['lines'])->toBe([['text' => 'The gate is open.']]);

    // The variant's own script is a frame like a branch arm.
    setEditorProperty($editor, 'databaseCommandFramePath', [1, 'script']);
    setEditorProperty($editor, 'databaseSelectedSettingIndex', 0);
    callEditorMethod($editor, 'addDatabaseNpcSubItem');
    expect($map->getNpcs()->get(0)?->getDialogue()[1]['script'][0]['type'] ?? null)->toBe('text');
    setEditorProperty($editor, 'databaseCommandFramePath', []);

    // The top-level script is its own frame; adding a command there.
    expect(npcField($editor, 'commandListScript'))->toHaveKey('frame');
    setEditorProperty($editor, 'databaseCommandFramePath', ['script']);
    setEditorProperty($editor, 'databaseSelectedSettingIndex', 0);
    callEditorMethod($editor, 'addDatabaseNpcSubItem');
    setEditorProperty($editor, 'databaseCommandFramePath', []);
    $npc = $map->getNpcs()->get(0);
    expect($npc?->getScript())->toHaveCount(1)
        ->and($npc?->scriptShadowsDialogue())->toBeTrue();
    $labels = array_map(static fn(array $f): string => (string) ($f['label'] ?? ''), callEditorMethod($editor, 'getInspectorFields'));
    expect($labels)->toContain('  ! Script replaces dialogue');

    // Removing the variant and every add is undoable; then it all saves.
    callEditorMethod($editor, 'saveSelectedMap');
    $saved = npcsOnDisk($path)[0];
    expect($saved['script'])->toHaveCount(1)
        ->and($saved['dialogue'][1]['conditions'][0]['name'])->toBe('gate_open');
});

it('assigns a stable id to a legacy NPC once, from its name, and never again', function () {
    [$root] = npcProject([['name' => 'Old Man', 'sprite' => 'o', 'x' => 2, 'y' => 1]]);
    $editor = npcEditor($root);
    $map = npcMap($editor);
    callEditorMethod($editor, 'selectNpc', 0);

    $labels = array_map(static fn(array $f): string => (string) ($f['label'] ?? ''), callEditorMethod($editor, 'getInspectorFields'));
    expect($labels)->toContain('  ! No stable id')
        ->and(npcField($editor, 'id')['value'])->toBe('');

    setEditorProperty($editor, 'focusedPane', 'inspector');
    restNpcCursorOn($editor, '__npc_assign_id');
    callEditorMethod($editor, 'dispatchInput', "\r");

    expect($map->getNpcs()->get(0)?->getId())->toBe('old-man')
        ->and($map->isDirty())->toBeTrue();
    callEditorMethod($editor, 'performUndo');
    expect($map->getNpcs()->get(0)?->getId())->toBeNull();
    callEditorMethod($editor, 'performRedo');
    expect($map->getNpcs()->get(0)?->getId())->toBe('old-man');

    // The row is gone now; nothing offers to change the id again.
    $labels = array_map(static fn(array $f): string => (string) ($f['label'] ?? ''), callEditorMethod($editor, 'getInspectorFields'));
    expect($labels)->not->toContain('  ! No stable id');
    callEditorMethod($editor, 'assignIdToSelectedNpc');
    expect($map->getNpcs()->get(0)?->getId())->toBe('old-man');
});

it('offers newly created NPC ids to the move_route picker at once, and drops deleted ones', function () {
    [$root] = npcProject([['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1]]);
    $editor = npcEditor($root);
    $map = npcMap($editor);
    $workspace = getEditorProperty($editor, 'workspace');

    expect(new ReferenceCatalog($workspace, $map)->valuesFor('map_npcs'))->toBe(['a']);

    createNpcThroughCanvas($editor, 5, 2, 'Bob');
    expect(new ReferenceCatalog($workspace, $map)->valuesFor('map_npcs'))->toBe(['a', 'bob']);

    callEditorMethod($editor, 'selectNpc', 0);
    setEditorProperty($editor, 'focusedPane', 'canvas');
    callEditorMethod($editor, 'dispatchInput', "\033[3~");
    expect(new ReferenceCatalog($workspace, $map)->valuesFor('map_npcs'))->toBe(['bob']);

    // Another map's NPCs are never offered: ids are map-local.
    expect(new ReferenceCatalog($workspace, null)->valuesFor('map_npcs'))->toBe([]);
});

// -- Persistence -----------------------------------------------------------

it('saves nothing for a clean inspection, and only the map data file for an NPC edit', function () {
    [$root, $path] = npcProject([['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1, 'mood' => ['stern' => true]]]);
    $before = npcHashTree($root);
    $editor = npcEditor($root);
    $map = npcMap($editor);

    // Inspect every field, select, switch modes, save: nothing changes.
    callEditorMethod($editor, 'selectNpc', 0);
    callEditorMethod($editor, 'getInspectorFields');
    callEditorMethod($editor, 'getInspectorLines');
    callEditorMethod($editor, 'setEditingMode', 'map');
    callEditorMethod($editor, 'setEditingMode', 'npc');
    callEditorMethod($editor, 'selectNpc', 0);
    expect($map->isDirty())->toBeFalse();
    callEditorMethod($editor, 'saveSelectedMap');
    callEditorMethod($editor, 'saveAllAssets');
    expect(npcHashTree($root))->toBe($before);

    // One NPC edit: the data file changes, and nothing else does.
    setNpcField($editor, 'name', 'Annabel');
    callEditorMethod($editor, 'saveSelectedMap');
    $after = npcHashTree($root);
    $changed = array_keys(array_diff_assoc($after, $before));
    expect($changed)->toBe(['assets/Maps/test-map/test-map.data.php'])
        ->and(hash_file('sha256', $root . '/assets/Maps/test-map/test-map.map.php'))->toBe($before['assets/Maps/test-map/test-map.map.php'])
        ->and(hash_file('sha256', $root . '/assets/Maps/test-map/test-map.event.php'))->toBe($before['assets/Maps/test-map/test-map.event.php']);

    // Unknown fields, nested ones included, came through the round trip.
    $reloaded = ProjectWorkspace::fromProject($root)->getMapByIndex(0);
    expect($reloaded->getNpcs()->get(0)?->getName())->toBe('Annabel')
        ->and($reloaded->getNpcs()->get(0)?->toArray()['mood'])->toBe(['stern' => true])
        ->and($reloaded->getNpcs()->get(0)?->getUnknownFields())->toBe(['mood']);
});

it('places the saved checkpoint in the middle of history and follows it with undo and redo', function () {
    [$root] = npcProject([['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1]]);
    $editor = npcEditor($root);
    $map = npcMap($editor);
    callEditorMethod($editor, 'selectNpc', 0);

    setNpcField($editor, 'name', 'One');
    setNpcField($editor, 'name', 'Two');
    callEditorMethod($editor, 'saveSelectedMap');
    expect($map->isDirty())->toBeFalse();
    setNpcField($editor, 'name', 'Three');
    expect($map->isDirty())->toBeTrue();

    callEditorMethod($editor, 'performUndo');
    expect($map->getNpcs()->get(0)?->getName())->toBe('Two')
        ->and($map->isDirty())->toBeFalse();
    callEditorMethod($editor, 'performUndo');
    expect($map->getNpcs()->get(0)?->getName())->toBe('One')
        ->and($map->isDirty())->toBeTrue();
    callEditorMethod($editor, 'performRedo');
    expect($map->isDirty())->toBeFalse();
    callEditorMethod($editor, 'performRedo');
    expect($map->getNpcs()->get(0)?->getName())->toBe('Three')
        ->and($map->isDirty())->toBeTrue();

    // Delete, undo, save: the file still holds the NPC.
    setEditorProperty($editor, 'focusedPane', 'canvas');
    callEditorMethod($editor, 'dispatchInput', "\033[3~");
    callEditorMethod($editor, 'performUndo');
    callEditorMethod($editor, 'saveSelectedMap');
    expect(ProjectWorkspace::fromProject($root)->getMapByIndex(0)->getNpcs()->ids())->toBe(['a']);
});

it('duplicates a map with its NPC collection and internal routes intact', function () {
    [$root] = npcProject([
        ['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1],
        ['id' => 'b', 'name' => 'Bob', 'sprite' => 'B', 'x' => 6, 'y' => 3, 'script' => [
            ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'a', 'steps' => [['direction' => 'north']]],
        ]],
    ]);
    $workspace = ProjectWorkspace::fromProject($root);
    $copyId = $workspace->duplicateMap(0);
    $reloaded = ProjectWorkspace::fromProject($root);
    $copyIndex = array_search((string) $copyId, $reloaded->mapIds, true);
    $copy = is_int($copyIndex) ? $reloaded->getMapByIndex($copyIndex) : null;

    expect($copy)->not->toBeNull()
        ->and($copy->getNpcs()->ids())->toBe(['a', 'b'])
        ->and(new NpcReferences($reloaded)->describe($copy, 'a'))->toBe(['NPC Bob script']);
});

it('refuses a shrink that would strand an NPC or its wander area, and grows freely', function () {
    [$root] = npcProject([
        ['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 9, 'y' => 3],
        ['id' => 'w', 'name' => 'Wanderer', 'sprite' => 'W', 'x' => 3, 'y' => 1, 'movement' => 'wander', 'wanderArea' => ['x' => 2, 'y' => 1, 'width' => 8, 'height' => 2]],
    ]);
    $editor = npcEditor($root);
    $map = npcMap($editor);
    callEditorMethod($editor, 'setEditingMode', 'map');

    $width = ['label' => '  X', 'value' => '12', 'target' => 'map-size', 'field' => 'width'];
    callEditorMethod($editor, 'applyInspectorFieldValue', $width, '8');

    expect($map->getWidth())->toBe(12)
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('Cannot shrink to 8x5')
        ->and($map->getNpcs()->get(0)?->getX())->toBe(9)
        ->and($map->getNpcs()->get(1)?->getWanderArea()['width'])->toBe(8);

    expect($map->describeNpcsStrandedBy(8, 5))->toBe([
        'Ann (a) stands at 9,3',
        'Wanderer (w) wanders 2,1 8x2',
    ]);

    callEditorMethod($editor, 'applyInspectorFieldValue', $width, '20');
    expect($map->getWidth())->toBe(20)
        ->and($map->getNpcs()->get(0)?->getX())->toBe(9)
        ->and($map->getNpcs()->count())->toBe(2);
});

// -- Validation ------------------------------------------------------------

it('reports every malformed NPC shape with the runtime consequence', function () {
    [$root] = npcProject([
        'not an entry',
        ['name' => '', 'x' => 1, 'y' => 1],
        ['id' => 'dup', 'name' => 'First', 'x' => 1, 'y' => 1],
        ['id' => 'dup', 'name' => 'Second', 'x' => 1, 'y' => 1],
        ['id' => 'far', 'name' => 'Far', 'x' => 40, 'y' => 1],
        ['id' => 'noxy', 'name' => 'Nowhere'],
        ['id' => 'shape', 'name' => 'Shapes', 'x' => 2, 'y' => 2, 'sprite' => ['no'], 'movement' => 'patrol', 'wanderArea' => 'big', 'sprites' => 'north', 'dialogue' => 'hello', 'script' => 'run', 'conditions' => 'when', 'sets' => 'set', 'extra' => 1],
        ['id' => 'wander', 'name' => 'Wanderer', 'x' => 9, 'y' => 3, 'movement' => 'wander', 'wanderArea' => ['x' => 1, 'y' => 1, 'width' => 0, 'height' => 30]],
        ['id' => 'fixed-area', 'name' => 'Sitter', 'x' => 3, 'y' => 3, 'wanderArea' => ['x' => 1, 'y' => 1, 'width' => 2, 'height' => 2]],
        ['id' => 'shadow', 'name' => 'Shadow', 'x' => 4, 'y' => 3, 'dialogue' => [['text' => 'never']], 'script' => [['type' => 'text', 'text' => 'always']]],
        ['id' => 'mixed', 'name' => 'Mixed', 'x' => 5, 'y' => 3, 'dialogue' => [['conditions' => [], 'lines' => [['text' => 'a']]], ['text' => 'plain']]],
        ['id' => 'empty', 'name' => 'Empty', 'x' => 6, 'y' => 3, 'sprite' => '<fg=red>', 'dialogue' => [['name' => 'x']]],
        ['id' => 'keys', 'name' => 'Keys', 'x' => 7, 'y' => 3, 'sprites' => ['up' => '^', 'north' => ['x']]],
        ['id' => 'wide', 'name' => 'Wide', 'x' => 11, 'y' => 3, 'sprite' => '🐈'],
        ['id' => 'on-event', 'name' => 'Blocker', 'x' => 5, 'y' => 1],
        ['name' => 'Legacy', 'x' => 8, 'y' => 1],
    ]);

    $errors = implode("\n", npcIssueLines($root, Severity::ERROR));
    $warnings = implode("\n", npcIssueLines($root, Severity::WARNING));

    expect($errors)->toContain('NPC entry 1 is string, not a structured entry')
        ->toContain('NPC entry 2: It has no name')
        ->toContain('NPC id "dup" is used more than once')
        ->toContain('It shares tile (1, 1) with NPC Second')
        ->toContain('It stands at (40, 1), outside the 12 x 5 map')
        ->toContain('NPC Nowhere: It has no coordinates')
        ->toContain('Its sprite is array, not text')
        ->toContain('Its movement "patrol" is not one the game supports')
        ->toContain('Its dialogue is string, not a list')
        ->toContain('Its script is string, not a list')
        ->toContain('Its conditions is string, not a list')
        ->toContain('Its sets is string, not a list')
        ->toContain('Dialogue entry 2 is a plain page inside a list of variants')
        ->toContain('Its north sprite is array, not text')
        // A fixed NPC on the fixture's chest tile makes the chest unreachable.
        ->toContain('NPC Blocker: It stands on event marker E (ChestEventTrigger) at (5, 1)');

    expect($warnings)->toContain('Its wander area is string, not an area')
        ->toContain('Its directional sprites are string, not a map')
        ->toContain('It carries the field extra, which the game does not read')
        ->toContain('Its wander area is 0 x 30')
        ->toContain('extends beyond the 12 x 5 map')
        ->toContain('It starts at (9, 3), outside its wander area (1, 1) 1 x 30')
        ->toContain('It has a wander area but does not wander')
        ->toContain('It has both a script and dialogue')
        ->toContain('Its sprite draws nothing')
        ->toContain('Dialogue page 1 has no text')
        ->toContain('Its directional sprite key "up" is not a heading')
        ->toContain('Its 2-column sprite overhangs the right edge of the map')
        ->toContain('NPC Legacy: It has no stable id, so a move_route cannot target it');

    // Unbounded wander is legal and says nothing.
    [$quiet] = npcProject([['id' => 'roamer', 'name' => 'Roamer', 'x' => 3, 'y' => 2, 'movement' => 'wander']]);
    expect(implode("\n", npcIssueLines($quiet)))->not->toContain('Roamer');
});

it('validates NPCs the editor created, not only hand-written ones', function () {
    [$root] = npcProject([]);
    $editor = npcEditor($root);
    createNpcThroughCanvas($editor, 4, 2, 'Gate Guard');
    callEditorMethod($editor, 'saveSelectedMap');

    expect(implode("\n", npcIssueLines($root)))->not->toContain('Gate Guard');

    // Then break it the way an author can: onto the chest, wander unbounded
    // -- one error, and the roaming stays legal.
    callEditorMethod($editor, 'selectNpc', 0);
    setNpcField($editor, 'x', '5');
    setNpcField($editor, 'y', '1');
    setNpcField($editor, 'movement', 'wander');
    callEditorMethod($editor, 'saveSelectedMap');

    $lines = npcIssueLines($root);
    expect(implode("\n", $lines))->toContain('NPC Gate Guard: It starts on event marker E')
        ->and(implode("\n", npcIssueLines($root, Severity::ERROR)))->not->toContain('Gate Guard');
});

// -- Engine ----------------------------------------------------------------

it('loads an editor-written map through the engine\'s own NPC configuration', function () {
    $game = dirname(__DIR__, 3) . '/examples/last-legend';

    if (! is_file($game . '/vendor/autoload.php')) {
        $this->markTestSkipped('The engine is not reachable from this checkout.');
    }

    require_once $game . '/vendor/autoload.php';

    if (! class_exists(\Ichiloto\Engine\Field\NpcManager::class)) {
        $this->markTestSkipped('The engine is not reachable from this checkout.');
    }

    [$root, $path] = npcProject([['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1]]);
    $editor = npcEditor($root);
    createNpcThroughCanvas($editor, 8, 3, 'Gate Guard');
    setNpcField($editor, 'sprite', '<fg=#ffaf00>@</>');
    setNpcField($editor, 'movement', 'wander');
    setNpcField($editor, 'wanderArea.x', '7');
    setNpcField($editor, 'wanderArea.y', '2');
    setNpcField($editor, 'wanderArea.width', '3');
    setNpcField($editor, 'wanderArea.height', '2');
    setNpcField($editor, 'sprites.north', '^');
    setNpcField($editor, 'variant0Line0Text', 'Halt, traveller.');
    setNpcField($editor, 'sets', 'switch:met_guard');
    callEditorMethod($editor, 'saveSelectedMap');

    // The real NpcManager over a scene with only what configure() reads.
    $scene = new class extends \Ichiloto\Engine\Scenes\Game\GameScene {
        public function __construct()
        {
            $this->currentMapId = 'test-map';
            $this->gameState = new \Ichiloto\Engine\Core\GameState();
            $this->party = new \Ichiloto\Engine\Entities\Party();
        }
    };
    $manager = new \Ichiloto\Engine\Field\NpcManager($scene);
    $manager->configure((require $path)['npcs']);

    $guard = $manager->findById('gate-guard');

    expect($guard)->not->toBeNull()
        ->and($guard->name)->toBe('Gate Guard')
        ->and($guard->wanders)->toBeTrue()
        ->and($guard->wanderArea)->toBe(['x' => 7, 'y' => 2, 'width' => 3, 'height' => 2])
        ->and($guard->sprite)->toBe('<fg=#ffaf00>@</>')
        ->and($guard->dialogue)->toBe([['text' => 'Halt, traveller.']])
        ->and($guard->sets)->toBe([['type' => 'switch', 'name' => 'met_guard']])
        ->and($guard->allowsWanderTo(7, 2))->toBeTrue()
        ->and($guard->allowsWanderTo(6, 2))->toBeFalse()
        ->and($manager->npcAt(8, 3)?->id)->toBe('gate-guard')
        ->and($manager->npcAt(2, 1)?->id)->toBe('a')
        ->and($manager->visibleNpcs())->toHaveCount(2);
});

it('enters and leaves script frames from the hosted Inspector with Enter and Esc', function () {
    [$root] = npcProject([['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1, 'dialogue' => [
        ['lines' => [['text' => 'Hi.']]],
        ['conditions' => [['type' => 'switch', 'name' => 'g']], 'lines' => [['text' => 'Open.']]],
    ]]]);
    $editor = npcEditor($root);
    $map = npcMap($editor);
    callEditorMethod($editor, 'selectNpc', 0);
    setEditorProperty($editor, 'focusedPane', 'inspector');

    // Enter on the record's Script row opens its (empty) frame, whose one
    // row invites the first command; Shift+O adds it there.
    restNpcCursorOn($editor, 'commandListScript');
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe(['script'])
        ->and(getEditorProperty($editor, 'statusMessage'))->toBe('Script.')
        ->and(callEditorMethod($editor, 'getInspectorFields')[0]['field'] ?? null)->toBe('frameEmpty');
    callEditorMethod($editor, 'dispatchInput', 'O');
    expect($map->getNpcs()->get(0)?->getScript())->toHaveCount(1);

    // Esc pops back out onto the Script row, not out of NPC mode.
    callEditorMethod($editor, 'dispatchInput', "\033");
    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe([])
        ->and(getEditorProperty($editor, 'editingMode'))->toBe('npc')
        ->and(callEditorMethod($editor, 'getInspectorFields')[getEditorProperty($editor, 'databaseSelectedSettingIndex')]['field'] ?? null)->toBe('commandListScript');

    // A variant's script is a frame too, and the trail says whose.
    restNpcCursorOn($editor, 'variant1Script');
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe([1, 'script'])
        ->and(getEditorProperty($editor, 'statusMessage'))->toBe('Dialogue › Dialogue variant 2 › Script.');
    callEditorMethod($editor, 'dispatchInput', 'O');
    expect($map->getNpcs()->get(0)?->getDialogue()[1]['script'])->toHaveCount(1);
    callEditorMethod($editor, 'dispatchInput', "\033");
    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe([])
        ->and(callEditorMethod($editor, 'getInspectorFields')[getEditorProperty($editor, 'databaseSelectedSettingIndex')]['field'] ?? null)->toBe('variant1Conditions');
});

it('cycles a command type and picks a route target inside a hosted script frame', function () {
    [$root] = npcProject([
        ['id' => 'gate-guard', 'name' => 'Gate Guard', 'sprite' => 'G', 'x' => 8, 'y' => 3],
        ['id' => 'herald', 'name' => 'Herald', 'sprite' => 'H', 'x' => 2, 'y' => 1, 'script' => [['type' => 'text', 'name' => '', 'text' => 'x']]],
    ]);
    $editor = npcEditor($root);
    $map = npcMap($editor);
    callEditorMethod($editor, 'selectNpc', 1);
    setEditorProperty($editor, 'focusedPane', 'inspector');
    restNpcCursorOn($editor, 'commandListScript');
    callEditorMethod($editor, 'dispatchInput', "\r");

    // Right on the Type row steps through the shared command vocabulary.
    expect(callEditorMethod($editor, 'getInspectorFields')[0]['field'] ?? null)->toBe('command0Type');
    callEditorMethod($editor, 'dispatchInput', "\033[C");
    expect($map->getNpcs()->get(1)?->getScript()[0]['type'] ?? null)->toBe('choice');

    // Straight to move_route, then subject npc, then the target from the
    // live map-local picker.
    setNpcField($editor, 'command0Type', 'move_route');
    setNpcField($editor, 'command0Subject', 'npc');
    restNpcCursorOn($editor, 'command0NpcId');
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect(getEditorProperty($editor, 'referencePicker')->isOpen())->toBeTrue()
        ->and(getEditorProperty($editor, 'referencePicker')->matches())->toBe(['(None)', 'gate-guard', 'herald']);
    foreach (mb_str_split('gate') as $character) {
        callEditorMethod($editor, 'dispatchInput', $character);
    }
    callEditorMethod($editor, 'dispatchInput', "\r");

    $command = $map->getNpcs()->get(1)?->getScript()[0] ?? [];
    expect($command['type'] ?? null)->toBe('move_route')
        ->and($command['subject'] ?? null)->toBe('npc')
        ->and($command['npcId'] ?? null)->toBe('gate-guard');
});

it('adds, edits and removes route steps under a move_route inside a hosted script frame', function () {
    [$root, $path] = npcProject([
        ['id' => 'gate-guard', 'name' => 'Gate Guard', 'sprite' => 'G', 'x' => 8, 'y' => 3],
        ['id' => 'herald', 'name' => 'Herald', 'sprite' => 'H', 'x' => 2, 'y' => 1, 'script' => [
            ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'gate-guard'],
        ]],
    ]);
    $editor = npcEditor($root);
    $map = npcMap($editor);
    callEditorMethod($editor, 'selectNpc', 1);
    setEditorProperty($editor, 'focusedPane', 'inspector');
    restNpcCursorOn($editor, 'commandListScript');
    callEditorMethod($editor, 'dispatchInput', "\r");

    // Shift+O on any row of the route adds its first step; the step rows
    // then appear and edit like the root list's.
    restNpcCursorOn($editor, 'command0Subject');
    callEditorMethod($editor, 'dispatchInput', 'O');
    $steps = $map->getNpcs()->get(1)?->getScript()[0]['steps'] ?? null;
    expect($steps)->toBe([['direction' => 'down', 'count' => 1, 'faceOnly' => false]]);

    setNpcField($editor, 'command0Step0Direction', 'left');
    setNpcField($editor, 'command0Step0Count', '3');
    expect($map->getNpcs()->get(1)?->getScript()[0]['steps'][0])->toBe(['direction' => 'left', 'count' => 3, 'faceOnly' => false]);

    // A second step, then Shift+X on the first removes exactly that one;
    // undo brings it back in place.
    restNpcCursorOn($editor, 'command0Step0Count');
    callEditorMethod($editor, 'dispatchInput', 'O');
    expect($map->getNpcs()->get(1)?->getScript()[0]['steps'])->toHaveCount(2);
    restNpcCursorOn($editor, 'command0Step0Direction');
    callEditorMethod($editor, 'dispatchInput', 'X');
    expect($map->getNpcs()->get(1)?->getScript()[0]['steps'])->toBe([['direction' => 'down', 'count' => 1, 'faceOnly' => false]]);
    callEditorMethod($editor, 'performUndo');
    expect($map->getNpcs()->get(1)?->getScript()[0]['steps'][0]['direction'])->toBe('left');

    callEditorMethod($editor, 'saveSelectedMap');
    expect(npcsOnDisk($path)[1]['script'][0]['steps'])->toHaveCount(2)
        ->and(implode("\n", npcIssueLines($root, Severity::ERROR)))->not->toContain('route has no steps');
});

it('types shortcut glyphs into hosted text fields and the name prompt instead of firing them', function () {
    [$root] = npcProject([['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1, 'dialogue' => [['text' => 'Hi.']]]]);
    $editor = npcEditor($root);
    $map = npcMap($editor);

    // The name prompt on the canvas: ? % ^ @ are letters here, not the
    // help overlay or a mode switch.
    createNpcThroughCanvas($editor, 5, 2, 'Who? 100% ^ @home');
    expect($map->getNpcs()->get(1)?->getName())->toBe('Who? 100% ^ @home')
        ->and(getEditorProperty($editor, 'editingMode'))->toBe('npc')
        ->and(getEditorProperty($editor, 'modals')->has(\Ichiloto\Editor\UI\Modal::HELP))->toBeFalse();

    // A hosted text edit in the Inspector: the same glyphs go into the line.
    callEditorMethod($editor, 'selectNpc', 0);
    setEditorProperty($editor, 'focusedPane', 'inspector');
    restNpcCursorOn($editor, 'variant0Line0Text');
    callEditorMethod($editor, 'dispatchInput', "\r");
    foreach (mb_str_split(' Really? 50%') as $character) {
        callEditorMethod($editor, 'dispatchInput', $character);
    }
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect($map->getNpcs()->get(0)?->getDialogue()[0]['text'])->toBe('Hi. Really? 50%')
        ->and(getEditorProperty($editor, 'modals')->has(\Ichiloto\Editor\UI\Modal::HELP))->toBeFalse();

    // Outside a capture, ? still opens help.
    callEditorMethod($editor, 'dispatchInput', '?');
    expect(getEditorProperty($editor, 'modals')->has(\Ichiloto\Editor\UI\Modal::HELP))->toBeTrue();
});

// -- Readable rows ---------------------------------------------------------

it('heads each dialogue variant and shortens its rows, without touching field ids', function () {
    [$root] = npcProject([['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1, 'dialogue' => [
        ['lines' => [['text' => 'Hi.']]],
        ['conditions' => [['type' => 'switch', 'name' => 'gate_open']], 'lines' => [['text' => 'The gate is open.']]],
    ]]]);
    $editor = npcEditor($root);
    callEditorMethod($editor, 'selectNpc', 0);

    $rows = array_values(array_filter(
        array_map(
            static fn(array $f): array => ['label' => (string) ($f['label'] ?? ''), 'field' => $f['field'] ?? null, 'editable' => $f['editable'] ?? null],
            callEditorMethod($editor, 'getInspectorFields'),
        ),
        static fn(array $r): bool => str_starts_with($r['label'], 'Dialogue variant') || str_starts_with((string) $r['field'], 'variant'),
    ));

    expect($rows[0])->toBe(['label' => 'Dialogue variant 1', 'field' => null, 'editable' => false])
        ->and(callEditorMethod($editor, 'getInspectorFields')[array_search('Dialogue variant 1', array_column(callEditorMethod($editor, 'getInspectorFields'), 'label'), true)]['value'])->toBe('')
        ->and(array_column(array_slice($rows, 1, 5), 'label'))->toBe(['When', 'Then Set', 'Script Commands', 'Line 1 Speaker', 'Line 1 Text'])
        ->and(array_column(array_slice($rows, 1, 5), 'field'))->toBe(['variant0Conditions', 'variant0Sets', 'variant0Script', 'variant0Line0Name', 'variant0Line0Text'])
        // The second variant's heading carries its condition line as its
        // value: "Dialogue variant 2 · when switch:gate_open" on a wide pane,
        // the "when …" wrapped under the heading on a narrow one.
        ->and($rows[6]['label'])->toBe('Dialogue variant 2')
        ->and($rows[6]['editable'])->toBeFalse()
        ->and(preg_replace('/\s+/', ' ', implode(' ', callEditorMethod($editor, 'getDatabaseSettingsLines'))))->toContain('Dialogue variant 2 when switch:gate_open')
        ->and(array_column(array_slice($rows, 7, 5), 'field'))->toBe(['variant1Conditions', 'variant1Sets', 'variant1Script', 'variant1Line0Name', 'variant1Line0Text']);
});

it('wraps a long dialogue line in the hosted Inspector, keeps the edited row single, and maps rows to fields', function () {
    $long = str_repeat('Halt, traveller. The road beyond the gate is closed until the harvest festival. ', 2);
    [$root] = npcProject([['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1, 'dialogue' => [['text' => $long]]]]);
    $editor = npcEditor($root);
    callEditorMethod($editor, 'selectNpc', 0);
    setEditorProperty($editor, 'focusedPane', 'inspector');
    restNpcCursorOn($editor, 'variant0Line0Text');

    $width = callEditorMethod($editor, 'recordPaneMetrics')['width'];
    $lines = callEditorMethod($editor, 'getDatabaseSettingsLines');
    $textLines = array_values(array_filter($lines, static fn(string $l): bool => str_contains($l, 'Halt, traveller') || str_starts_with($l, str_repeat(' ', 8)) && trim($l) !== '' && ! str_contains($l, ':')));
    $first = array_search(true, array_map(static fn(string $l): bool => str_starts_with($l, '> Line 1 Text: '), $lines), true);

    expect(strlen($long))->toBeGreaterThanOrEqual(120)
        ->and($first)->not->toBeFalse()
        ->and(count($textLines))->toBeGreaterThanOrEqual(3);

    foreach ($lines as $line) {
        expect(mb_strwidth($line))->toBeLessThanOrEqual($width);
    }

    // Continuation lines sit under the value column of '> Line 1 Text: '.
    expect($lines[$first + 1])->toStartWith(str_repeat(' ', mb_strwidth('> Line 1 Text: ')))
        ->and(trim($lines[$first + 1]))->not->toBe('');

    // The layout maps a continuation row back to its field, and puts the
    // caret on the field's first line.
    $layout = callEditorMethod($editor, 'recordPaneLayout');
    $selected = getEditorProperty($editor, 'databaseSelectedSettingIndex');
    expect($layout->fieldAtRow($layout->rowOfField($selected) + 1))->toBe($selected)
        ->and($layout->spans[$selected][1])->toBeGreaterThanOrEqual(3);

    // Editing: that row is one line again, showing the caret's window.
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect(getEditorProperty($editor, 'isDatabaseEditing'))->toBeTrue();
    $editing = callEditorMethod($editor, 'recordPaneLayout');
    $editedLine = callEditorMethod($editor, 'getDatabaseSettingsLines')[$editing->rowOfField($selected)];
    expect($editing->spans[$selected][1])->toBe(1)
        ->and($editedLine)->toStartWith('> Line 1 Text: ')
        ->and(mb_strwidth($editedLine))->toBeLessThanOrEqual($width)
        // The caret sits at the end of the buffer, so the window shows its tail.
        ->and($editedLine)->toEndWith(mb_substr($long, -10));
    callEditorMethod($editor, 'dispatchInput', "\033");
});
