<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Storage\FileSetTransactionFailure;

/** Synthetic source forms exercise history across saves, not authored game content. */
function sourceHistoryCutscene(CutsceneType $type): array
{
    $root = $type === CutsceneType::EFFECT ? effectProject() : cutsceneProject();
    $id = match ($type) {
        CutsceneType::EFFECT => 'ember-spark',
        CutsceneType::SUMMON => 'lantern-wisp',
        CutsceneType::CINEMATIC => 'harbour-lanterns',
    };
    if ($type === CutsceneType::EFFECT) {
        $path = $root . '/assets/Animations/' . $id . '/' . $id . '.timeline.php';
        $values = require $path;
        file_put_contents($path, "<?php\n// Keep the author's long-array syntax and this comment.\nreturn " . var_export($values, true) . ";\n");
    }
    $library = CutsceneLibrary::fromProject($root);
    $index = array_search($id, $library->ids($type), true);
    $asset = $library->find($type, $id);

    return [$root, $library, $index, $asset];
}

function readCutsceneHistorySources(CutsceneAsset $asset): array
{
    return array_combine($asset->paths(), array_map(file_get_contents(...), $asset->paths()));
}

function removeCutsceneHistoryBlock(CutsceneAsset $asset): void
{
    $payload = $asset->payload();
    switch ($asset->type) {
        case CutsceneType::EFFECT:
            unset($payload['cues']);
            break;
        case CutsceneType::SUMMON:
            unset($payload['playback']);
            array_shift($payload['tracks']);
            break;
        case CutsceneType::CINEMATIC:
            unset($payload['authoring']);
            array_pop($payload['commands']);
            break;
    }
    $asset->apply($payload);
}

it('preserves effect summon and cinematic source syntax through remove save undo redo and later edits', function (CutsceneType $type) {
    [, $library, $index, $asset] = sourceHistoryCutscene($type);
    $before = readCutsceneHistorySources($asset);
    $command = $library->changeAsset($type, $index, 'Remove authored block', static fn() => removeCutsceneHistoryBlock($asset))['command'];
    expect($command)->not->toBeNull()->and($asset->save())->toBeTrue();
    $removed = readCutsceneHistorySources($asset);
    $baseline = [];
    foreach (['baselineSources', 'loadedData', 'loadedPartner'] as $property) {
        $baseline[$property] = new ReflectionProperty(CutsceneAsset::class, $property)->getValue($asset);
    }
    $command->undo();
    // History remembers syntax, not an obsolete persisted baseline or an
    // obsolete changed-reference checkpoint.
    foreach ($baseline as $property => $value) {
        expect(new ReflectionProperty(CutsceneAsset::class, $property)->getValue($asset))->toBe($value);
    }
    $asset->assertSourcesUnchanged();
    expect($asset->save())->toBeTrue()->and(readCutsceneHistorySources($asset))->toBe($before);
    $command->execute();
    expect($asset->save())->toBeTrue()->and(readCutsceneHistorySources($asset))->toBe($removed);
    $command->undo();
    $payload = $asset->payload();
    if ($type === CutsceneType::CINEMATIC) {
        $payload['name'] = 'A newly authored name';
    } else {
        $payload['fps'] = 8;
    }
    $asset->apply($payload);
    expect($asset->save())->toBeTrue();
    $changed = readCutsceneHistorySources($asset);
    $expected = $before;
    $path = $type === CutsceneType::CINEMATIC ? $asset->dataPath() : $asset->partnerPath();
    $expected[$path] = match ($type) {
        CutsceneType::EFFECT => str_replace("'fps' => 10", "'fps' => 8", $before[$path]),
        CutsceneType::SUMMON => str_replace("'fps' => 12", "'fps' => 8", $before[$path]),
        CutsceneType::CINEMATIC => str_replace("'name' => 'Harbour Lanterns'", "'name' => 'A newly authored name'", $before[$path]),
    };
    expect($changed)->toBe($expected)->and($asset->save())->toBeFalse();
})->with(CutsceneType::cases());

it('refuses external replacement after saved undo without overwriting it or accepting an old disk baseline', function (CutsceneType $type) {
    [, $library, $index, $asset] = sourceHistoryCutscene($type);
    $command = $library->changeAsset($type, $index, 'Remove authored block', static fn() => removeCutsceneHistoryBlock($asset))['command'];
    $asset->save();
    $command->undo();
    $asset->assertSourcesUnchanged();
    $path = $asset->partnerPath();
    $external = file_get_contents($path) . "\n// Another author changed this source.\n";
    file_put_contents($path, $external);
    $state = $asset->captureEditState();
    expect(fn() => $asset->save())->toThrow(FileSetTransactionFailure::class, 'changed after opening')
        ->and(file_get_contents($path))->toBe($external)
        ->and($asset->captureEditState())->toBe($state);
})->with(CutsceneType::cases());

it('still validates proposed source templates before installing restored histories', function (CutsceneType $type) {
    [, $library, $index, $asset] = sourceHistoryCutscene($type);
    $command = $library->changeAsset($type, $index, 'Remove authored block', static fn() => removeCutsceneHistoryBlock($asset))['command'];
    $asset->save();
    $written = readCutsceneHistorySources($asset);
    $command->undo();
    $payload = $asset->payload();
    if ($type === CutsceneType::CINEMATIC) {
        $payload['commands'][0]['type'] = 'not_a_cinematic_command';
    } elseif ($type === CutsceneType::SUMMON) {
        $payload['tracks'][0]['presentation'] = 'not_a_presentation';
    } else {
        $payload['fps'] = 0;
    }
    $asset->apply($payload);
    expect(fn() => $asset->save())->toThrow(RuntimeException::class)
        ->and(readCutsceneHistorySources($asset))->toBe($written);
})->with(CutsceneType::cases());
