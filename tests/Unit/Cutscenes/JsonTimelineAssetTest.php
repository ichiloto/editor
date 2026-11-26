<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Storage\FileSetTransactionFailure;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;

function jsonTimelineAssetFixture(): array
{
    $root = effectProject();
    $folder = $root . '/assets/Animations/dusk-slash';
    $adapter = $folder . '/dusk-slash.timeline.php';
    $json = $folder . '/dusk-slash.json';
    $timeline = require $adapter;
    $source = json_encode($timeline, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION) . "\n";
    file_put_contents($json, $source);
    file_put_contents($adapter, <<<'ADAPTER'
<?php

// The game loads the authored timeline through this entry point.
return json_decode(
    file_get_contents(__DIR__ . '/dusk-slash.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
ADAPTER);

    return [$root, $folder, $adapter, $json, $source];
}

it('writes only the edited JSON sequence and restores its exact bytes through history', function () {
    [$root, , $adapter, $json, $original] = jsonTimelineAssetFixture();
    $adapterSource = file_get_contents($adapter);
    $library = CutsceneLibrary::fromProject($root);
    $asset = $library->find(CutsceneType::EFFECT, 'dusk-slash');
    $asset->selectPresentation(EffectPresentation::GRAPHICAL);
    $index = array_search($asset->id, $library->ids(CutsceneType::EFFECT), true);
    $before = $asset->captureEditState();
    $graphicalFps = $asset->payload()['fps'];

    expect($asset->isEditable())->toBeTrue()
        ->and($asset->paths())->toBe([$adapter, $json])
        ->and($asset->save())->toBeFalse();

    $change = $library->changeAsset(CutsceneType::EFFECT, $index, 'Edit tempo', function () use ($asset, $graphicalFps): void {
        $asset->apply([...$asset->payload(), 'fps' => $graphicalFps + 1]);
    });
    $api = new EffectTimelineLibrary($root . '/assets');
    expect($asset->save())->toBeTrue()
        ->and(file_get_contents($adapter))->toBe($adapterSource)
        ->and(file_get_contents($json))->toBe(str_replace(
            '"graphical": {' . "\n" . '            "fps": ' . $graphicalFps,
            '"graphical": {' . "\n" . '            "fps": ' . ($graphicalFps + 1),
            $original,
        ))
        ->and($api->load('dusk-slash', true, EffectPresentation::GRAPHICAL)->fps)->toBe($graphicalFps + 1)
        ->and($asset->save())->toBeFalse();
    $edited = file_get_contents($json);

    $change['command']->undo();
    expect($asset->captureEditState()['partner'])->toBe($before['partner'])
        ->and($asset->save())->toBeTrue()
        ->and(file_get_contents($json))->toBe($original);
    $change['command']->execute();
    expect($asset->save())->toBeTrue()
        ->and(file_get_contents($json))->toBe($edited);
});

it('duplicates and restores both owned files under the new identity', function () {
    [$root, , $adapter, $json, $original] = jsonTimelineAssetFixture();
    $library = CutsceneLibrary::fromProject($root);
    ['asset' => $asset, 'command' => $command] = $library->duplicateAsset(CutsceneType::EFFECT, 'dusk-slash', 'another-effect');
    [$copyAdapter, $copyJson] = $asset->paths();

    expect($asset->save())->toBeTrue()
        ->and(require $copyAdapter)->toBe(require $adapter)
        ->and(file_get_contents($copyJson))->toBe($original)
        ->and($copyJson)->toEndWith('/another-effect.json');

    $command->undo();
    expect($asset->save())->toBeTrue()
        ->and(file_exists($copyAdapter))->toBeFalse()
        ->and(file_exists($copyJson))->toBeFalse()
        ->and(file_get_contents($json))->toBe($original);
    $command->execute();
    expect($asset->save())->toBeTrue()
        ->and(require $copyAdapter)->toBe(require $adapter)
        ->and(file_get_contents($copyJson))->toBe($original);
});

it('refuses an external change or removal of either file', function (bool $adapt, bool $remove) {
    [$root, $folder, $adapter, $json, $original] = jsonTimelineAssetFixture();
    $asset = CutsceneAsset::load(CutsceneType::EFFECT, $folder, $root);
    $asset->apply([...$asset->payload(), 'fps' => 8]);
    $sources = [$adapter => file_get_contents($adapter), $json => $original];
    $external = $adapt ? $adapter : $json;
    if ($remove) {
        unlink($external);
        unset($sources[$external]);
    } else {
        $sources[$external] .= "\n";
        file_put_contents($external, $sources[$external]);
    }

    expect(fn() => $asset->save())->toThrow(FileSetTransactionFailure::class, 'changed after opening');
    foreach ($sources as $path => $contents) {
        expect(file_get_contents($path))->toBe($contents);
    }
    expect(file_exists($external))->toBe(! $remove);
})->with([[true, true], [true, false], [false, true], [false, false]]);

it('keeps dynamic and linked timeline sources read-only', function () {
    [$root, $folder, $adapter, $json, $original] = jsonTimelineAssetFixture();
    $bytes = file_get_contents($adapter);
    file_put_contents($adapter, str_replace("'/dusk-slash.json'", "'/' . basename(__DIR__) . '.json'", $bytes));
    expect(CutsceneAsset::load(CutsceneType::EFFECT, $folder, $root)->isEditable())->toBeFalse();

    file_put_contents($adapter, $bytes);
    $other = $root . '/outside.json';
    rename($json, $other);
    symlink($other, $json);
    expect(CutsceneAsset::load(CutsceneType::EFFECT, $folder, $root)->readOnlyReason())->toContain('symbolic link')
        ->and(file_get_contents($other))->toBe($original);
});

it('retains untouched JSON spellings during structural edits and rejects ambiguous members', function () {
    $document = Ichiloto\Editor\Cutscenes\Source\JsonTimelineDocument::parse(<<<'JSON'
{
    "tracks": [ {"id":"\u0061","gain":1.00}, { "id": "b", "gain": 2e0 } ],
    "untouched": { "0": {} }
}
JSON);
    $values = $document->getValues();
    array_unshift($values['tracks'], array_pop($values['tracks']));
    $values['tracks'][] = ['id' => 'c', 'gain' => 3.0];
    $values['duration'] = 4;
    expect($document->rewrite($values))->toBe(<<<'JSON'
{
    "tracks": [ { "id": "b", "gain": 2e0 }, {"id":"\u0061","gain":1.00}, {"id":"c","gain":3.0} ],
    "untouched": { "0": {} },
    "duration": 4
}
JSON);
    expect(fn() => Ichiloto\Editor\Cutscenes\Source\JsonTimelineDocument::parse('{"fps": 2, "\u0066ps": 3}'))
        ->toThrow(Ichiloto\Editor\Cutscenes\Source\SourceUnreadable::class, 'duplicate JSON object key');
});
