<?php

declare(strict_types=1);

/**
 * Resolves map music through the accepted engine head.
 *
 * Run in a subprocess by the parity suite, with every `Ichiloto\Engine\`
 * class loaded from a detached read-only export of the accepted head, ahead
 * of the vendored release. The engine's own `resolveMapBackgroundMusic` is
 * exercised through a probe subclass built the way the engine's own tests
 * build one: reflection, no constructor, with a real `GameState` and a real
 * inventory seeded from the case description.
 *
 * argv: [1] engine source root, [2] a JSON case file:
 * {"cases": [{"bgm": ..., "variants": [...], "state": {"switches": {...},
 * "events": [...], "variables": {...}, "items": [{"name", "quantity",
 * "isKeyItem"}]}}]}
 *
 * Prints {"results": [selected-track-or-null, ...]}. Quest conditions
 * evaluate against no quest system here, which the engine answers as false;
 * the suite asserts that boundary rather than pretending otherwise.
 */

[, $engineRoot, $caseFile] = $argv + [null, '', ''];

require dirname(__DIR__, 2) . '/vendor/autoload.php';

spl_autoload_register(static function (string $class) use ($engineRoot): void {
    if (! str_starts_with($class, 'Ichiloto\\Engine\\')) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen('Ichiloto\\Engine\\')));
    $file = $engineRoot . '/src/' . $relative . '.php';

    if (is_file($file)) {
        require $file;
    }
}, prepend: true);

$payload = json_decode((string) file_get_contents($caseFile), true, flags: JSON_THROW_ON_ERROR);

// A running game always has a project item catalogue; without one the
// engine's key-item resolution fails closed by throwing. The probe gives it
// the same environment a project gives it: a catalogue that defines the
// fixture items, so held and unheld references both answer as they would in
// a real game.
$projectRoot = sys_get_temp_dir() . '/ichiloto-bgm-parity-' . bin2hex(random_bytes(4));
mkdir($projectRoot . '/assets/Data', 0755, true);
file_put_contents($projectRoot . '/assets/Data/items.php', <<<'PHP'
<?php

use Ichiloto\Engine\Entities\Inventory\Items\Item;

return [
    new Item('Lantern', 'Parity fixture item.', '?', 1),
    new Item('Rusty Key', 'Parity fixture key item.', '?', 1, isKeyItem: true),
];
PHP);
chdir($projectRoot);
Ichiloto\Engine\Util\Config\ConfigStore::put(
    Ichiloto\Engine\Util\Stores\ItemStore::class,
    new Ichiloto\Engine\Util\Stores\ItemStore(),
);

final class MapBgmParityProbe extends Ichiloto\Engine\Field\MapManager
{
    public function resolve(mixed $bgm, mixed $variants): ?string
    {
        return $this->resolveMapBackgroundMusic($bgm, $variants);
    }
}

$results = [];

foreach ($payload['cases'] as $case) {
    $gameState = new Ichiloto\Engine\Core\GameState();
    $state = $case['state'] ?? [];

    foreach ($state['switches'] ?? [] as $name => $value) {
        $gameState->setSwitch(strval($name), (bool) $value);
    }

    foreach ($state['events'] ?? [] as $event) {
        $gameState->recordStoryEvent(strval($event));
    }

    foreach ($state['variables'] ?? [] as $name => $value) {
        $gameState->setVariable(strval($name), $value);
    }

    $inventory = new Ichiloto\Engine\Entities\Inventory\Inventory();

    foreach ($state['items'] ?? [] as $item) {
        $inventory->addItems(new Ichiloto\Engine\Entities\Inventory\Items\Item(
            strval($item['name']),
            'Parity fixture item.',
            '?',
            1,
            intval($item['quantity'] ?? 1),
            isKeyItem: (bool) ($item['isKeyItem'] ?? false),
        ));
    }

    $party = new ReflectionClass(Ichiloto\Engine\Entities\Party::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(Ichiloto\Engine\Entities\Party::class, 'inventory')->setValue($party, $inventory);

    $gameScene = new ReflectionClass(Ichiloto\Engine\Scenes\Game\GameScene::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(Ichiloto\Engine\Scenes\Game\GameScene::class, 'gameState')->setValue($gameScene, $gameState);
    new ReflectionProperty(Ichiloto\Engine\Scenes\Game\GameScene::class, 'party')->setValue($gameScene, $party);

    $probe = new ReflectionClass(MapBgmParityProbe::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(Ichiloto\Engine\Field\MapManager::class, 'gameScene')->setValue($probe, $gameScene);

    $results[] = $probe->resolve($case['bgm'] ?? null, $case['variants'] ?? []);
}

echo json_encode(['results' => $results]);
