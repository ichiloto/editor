<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
use Ichiloto\Engine\Battle\Presentation\BattlerBindings;

/** Setting an actor's or enemy's battle art as data, from its own page. Synthetic fixtures only. */

it('binds an enemy\'s battle art as data, previews it unsaved, and saves a file the battle reads', function () {
    $root = battlerArtProject();
    $session = EditorSession::open($root);

    expect($session->describeBattlerArt('enemies', 'Regular Bat'))->toBe(['category' => 'battler_enemies', 'index' => null, 'owner' => null]);

    $index = $session->createDatabaseRecord('battler_enemies', 'Regular Bat')['index'];
    setBattlerRow($session, $index, 'artwork.image', 'Graphics/Enemies/Bat.png');
    setBattlerRow($session, $index, 'artwork.pivot', '0.25, 1');

    // The preview shows the art as set, before it is saved.
    expect($session->readEnemyPreview(0)['formation']['members'][0]['battler']['image']['asset'] ?? null)->toBe('Graphics/Enemies/Bat.png')
        ->and(file_exists($root . '/assets/' . BattlerBindings::FILE))->toBeFalse();

    $session->saveDatabase('battler_enemies');

    expect(BattlerBindings::load($root . '/assets')->enemies['Regular Bat'])->toEqual(new BattlerArtwork('Graphics/Enemies/Bat.png', 40, 80, 10, 80))
        ->and($session->describeBattlerArt('enemies', 'Regular Bat'))->toBe(['category' => 'battler_enemies', 'index' => 0, 'owner' => 'data']);
});

it('keeps poses as the role-keyed map the battle reads', function () {
    $root = battlerArtProject();
    $session = EditorSession::open($root);
    $index = $session->createDatabaseRecord('battler_enemies', 'Regular Bat')['index'];
    setBattlerRow($session, $index, 'artwork.image', 'Graphics/Enemies/Bat.png');
    $heading = array_find($session->readDatabaseRecord('battler_enemies', $index)['rows'], static fn(array $row): bool => ($row['listHeading'] ?? false) === true);
    $session->addDatabaseItem('battler_enemies', $index, $heading['key'], true);
    setBattlerRow($session, $index, 'pose0Role', 'attack');
    setBattlerRow($session, $index, 'pose0Image', 'Graphics/Enemies/BatAttack.png');
    $session->saveDatabase('battler_enemies');

    $written = require $root . '/assets/' . BattlerBindings::FILE;

    expect($written['enemies']['Regular Bat']['poses'])->toBe(['attack' => ['image' => 'Graphics/Enemies/BatAttack.png']])
        ->and(BattlerBindings::load($root . '/assets')->enemyPoses['Regular Bat']->roles)->toHaveKey('attack');
});

it('saves an enemy\'s battle art with the enemy, as its page edits both', function () {
    $root = battlerArtProject();
    $session = EditorSession::open($root);
    $index = $session->createDatabaseRecord('battler_enemies', 'Regular Bat')['index'];
    setBattlerRow($session, $index, 'artwork.image', 'Graphics/Enemies/Bat.png');

    expect($session->saveDatabase('enemies')['saved'])->toBeTrue()
        ->and(BattlerBindings::load($root . '/assets')->enemies)->toHaveKey('Regular Bat')
        ->and($session->listUnsavedChanges())->toBe([]);
});

it('shows the enemy category unsaved while its battle art is, since its Save writes both', function () {
    $session = EditorSession::open(battlerArtProject());
    expect($session->listDatabaseRecords('enemies'))->toMatchArray(['dirty' => false, 'unsavedCategories' => []]);

    $index = $session->createDatabaseRecord('battler_enemies', 'Regular Bat')['index'];
    setBattlerRow($session, $index, 'artwork.image', 'Graphics/Enemies/Bat.png');

    expect($session->listDatabaseRecords('enemies'))->toMatchArray(['dirty' => true, 'unsavedCategories' => ['enemies']])
        ->and($session->listDatabaseRecords('actors')['dirty'])->toBeFalse();
    $session->saveDatabase('enemies');
    expect($session->listDatabaseRecords('enemies'))->toMatchArray(['dirty' => false, 'unsavedCategories' => []]);
});

it('refuses to save art the battle could not read, writing nothing', function () {
    $root = battlerArtProject();
    $session = EditorSession::open($root);
    $session->createDatabaseRecord('battler_enemies', 'Regular Bat');

    expect(fn() => $session->saveDatabase('battler_enemies'))->toThrow(SessionRefusal::class, 'a battler needs artwork, poses or both')
        ->and(file_exists($root . '/assets/' . BattlerBindings::FILE))->toBeFalse();
});

it('leaves art the presentation code binds to the code, and refuses a second owner for it', function () {
    $root = battlerArtProject();
    $battle = (string) file_get_contents($root . '/assets/Data/Presentation/battle.php');
    file_put_contents($root . '/assets/Data/Presentation/battle.php', str_replace('    enemies: [],',
        "    enemies: ['Regular Bat' => new Ichiloto\\Engine\\Battle\\Presentation\\BattlerArtwork('Graphics/Enemies/Bat.png', 40, 80, 20, 80)],", $battle));
    $session = EditorSession::open($root);

    expect($session->describeBattlerArt('enemies', 'Regular Bat'))->toMatchArray(['index' => null, 'owner' => 'code'])
        ->and($session->describeBattlerArt('enemies', 'Regular Bat')['note'])->toContain('binds this art in code');

    $index = $session->createDatabaseRecord('battler_enemies', 'Regular Bat')['index'];
    setBattlerRow($session, $index, 'artwork.image', 'Graphics/Enemies/Bat.png');

    expect(fn() => $session->saveDatabase('battler_enemies'))->toThrow(SessionRefusal::class, 'Regular Bat is bound both in');
});

it('makes one record per battler, and keeps battle art out of the terminal\'s category list', function () {
    $session = EditorSession::open(battlerArtProject());
    $session->createDatabaseRecord('battler_enemies', 'Regular Bat');

    expect(fn() => $session->createDatabaseRecord('battler_enemies', 'Regular Bat'))->toThrow(SessionRefusal::class, 'Regular Bat already has enemy battle art.')
        ->and(fn() => $session->createDatabaseRecord('battler_enemies'))->toThrow(SessionRefusal::class, 'Enemy battle art is made for what it belongs to')
        ->and(array_column(array_map(static fn($category): array => (array) $category, DatabaseCatalog::all()), 'key'))->not->toContain('battler_enemies', 'battler_actors', 'battle_scale')
        ->and(fn() => $session->describeBattlerArt('troops', 'Pair'))->toThrow(SessionRefusal::class, 'actors or enemies');
});

it('previews an actor in the lead party slot with its art as set, unsaved edits included', function () {
    $root = battlerArtProject();
    writeTilesetTestPng($root . '/assets/Graphics/Actors/Kaelion.png', 50, 100);
    $session = EditorSession::open($root);
    $actor = array_search('Kaelion', $session->listDatabaseRecords('actors')['records'], true);
    $identity = $session->readActorPreview($actor)['identity'];

    $art = $session->createDatabaseRecord('battler_actors', $identity)['index'];
    $image = array_find($session->readDatabaseRecord('battler_actors', $art)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === 'artwork.image');
    $session->applyDatabaseRecord('battler_actors', $art, $image['key'], 'Graphics/Actors/Kaelion.png');
    $preview = $session->readActorPreview($actor);

    expect($preview['formationIssue'])->toBeNull()
        ->and($preview['formation']['party'][0])->toMatchArray(['name' => 'Kaelion', 'ground' => ['x' => 1050.0, 'y' => 420.0]])
        ->and($preview['formation']['party'][0]['image']['asset'] ?? null)->toBe('Graphics/Actors/Kaelion.png')
        ->and($preview['formation']['members'])->toBe([])
        ->and($session->describeBattlerArt('actors', $identity))->toMatchArray(['index' => $art, 'owner' => 'data']);
});

it('sets the ground point of a base image and the idle pose showing it as one undo step', function () {
    $root = battlerArtProject();
    $session = EditorSession::open($root);
    $index = $session->createDatabaseRecord('battler_enemies', 'Regular Bat')['index'];
    setBattlerRow($session, $index, 'artwork.image', 'Graphics/Enemies/Bat.png');
    $heading = array_find($session->readDatabaseRecord('battler_enemies', $index)['rows'], static fn(array $row): bool => ($row['listHeading'] ?? false) === true);
    $session->addDatabaseItem('battler_enemies', $index, $heading['key'], true);
    setBattlerRow($session, $index, 'pose0Image', 'Graphics/Enemies/Bat.png');
    $both = [
        ['key' => readBattlerRow($session, $index, 'pose0Pivot')['key'], 'value' => '0.25, 0.9'],
        ['key' => readBattlerRow($session, $index, 'artwork.pivot')['key'], 'value' => '0.25, 0.9'],
    ];

    expect($session->applyDatabaseRecordValues('battler_enemies', $index, $both, 'Ground point')['changed'])->toBeTrue()
        ->and(readBattlerRow($session, $index, 'pose0Pivot')['value'])->toBe('0.25, 0.9')
        ->and(readBattlerRow($session, $index, 'artwork.pivot')['value'])->toBe('0.25, 0.9');

    expect($session->undo()['label'])->toBe('Ground point')
        ->and(readBattlerRow($session, $index, 'pose0Pivot')['value'])->toBe('')
        ->and(readBattlerRow($session, $index, 'artwork.pivot')['value'])->toBe('');

    $session->redo();
    expect(readBattlerRow($session, $index, 'artwork.pivot')['value'])->toBe('0.25, 0.9');

    // One refused value takes back the rest.
    $refused = [['key' => readBattlerRow($session, $index, 'pose0Pivot')['key'], 'value' => '0.5, 0.5'],
        ['key' => readBattlerRow($session, $index, 'artwork.pivot')['key'], 'value' => 'off the image']];
    expect(fn() => $session->applyDatabaseRecordValues('battler_enemies', $index, $refused, 'Ground point'))->toThrow(SessionRefusal::class)
        ->and(readBattlerRow($session, $index, 'pose0Pivot')['value'])->toBe('0.25, 0.9');
});

it('names unsaved battle art among the unsaved changes, as the status bar counts them', function () {
    $root = battlerArtProject();
    $session = EditorSession::open($root);
    $index = $session->createDatabaseRecord('battler_enemies', 'Regular Bat')['index'];
    setBattlerRow($session, $index, 'artwork.image', 'Graphics/Enemies/Bat.png');
    setBattlerRow($session, $index, 'artwork.pivot', '0.25, 1');

    expect($session->listUnsavedChanges())->not->toBe([]);

    $session->saveDatabase('battler_enemies');

    expect($session->listUnsavedChanges())->toBe([]);
});
