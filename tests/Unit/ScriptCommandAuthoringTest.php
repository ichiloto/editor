<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Events\ProjectScriptCommands;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandRegistry;

/** Declares a project command whose handler class the Editor never loads. */
function writeCarriageDeclarations(string $root): void
{
    file_put_contents($root . '/assets/' . ScriptCommandRegistry::PROJECT_FILE, <<<'PHP'
    <?php

    use Project\Commands\HireCarriage;

    return [[
        'type' => 'hire_carriage',
        'class' => HireCarriage::class,
        'label' => 'Hire Carriage',
        'fields' => [
            ['key' => 'destination', 'label' => 'Destination', 'kind' => 'reference', 'reference' => 'map', 'required' => true],
            ['key' => 'arrival', 'label' => 'Arrival', 'kind' => 'position'],
            ['key' => 'speed', 'label' => 'Speed', 'kind' => 'option', 'options' => ['slow', 'fast']],
            ['key' => 'stops', 'label' => 'Stops', 'kind' => 'list', 'fields' => [
                ['key' => 'map', 'label' => 'Map', 'kind' => 'reference', 'reference' => 'map', 'required' => true],
            ]],
            ['key' => 'tolls', 'label' => 'Tolls', 'kind' => 'list', 'fields' => [
                ['key' => 'amount', 'label' => 'Amount', 'kind' => 'integer'],
            ]],
        ],
    ]];
    PHP);
}

/** Writes an authored PHP data file. */
function writeScriptCommandScript(string $path, array $payload): void
{
    file_put_contents($path, "<?php\n\nreturn " . var_export($payload, true) . ";\n");
}

/** @return array<string, Ichiloto\Editor\Database\RecordField> */
function registeredVariantFields(string $type): array
{
    $fields = [];

    foreach (RecordSchemaCatalog::forKey('common_events')?->subList?->variants[$type] ?? [] as $field) {
        $fields[$field->key] = $field;
    }

    return $fields;
}

afterEach(function (): void {
    ScriptCommandRegistry::reset();
});

it('reads a project\'s declared commands without loading their handlers', function (): void {
    $root = makeTemporaryProject();

    expect(ProjectScriptCommands::fromProject($root)->catalog->types)->toBe(['shop', 'inn']);

    writeCarriageDeclarations($root);
    $commands = ProjectScriptCommands::fromProject($root);

    expect($commands->catalog->types)->toBe(['shop', 'inn', 'hire_carriage'])
        ->and($commands->catalog->findDefinition('hire_carriage')?->handlerClass)->toBe('Project\\Commands\\HireCarriage')
        ->and(class_exists('Project\\Commands\\HireCarriage', false))->toBeFalse()
        ->and($commands->declarationProblem)->toBeNull();
});

it('offers the open project\'s registered commands with their declared fields', function (): void {
    $root = makeTemporaryProject();
    writeCarriageDeclarations($root);
    ProjectWorkspace::fromProject($root);
    $list = RecordSchemaCatalog::eventCommandList('script');
    $shop = registeredVariantFields('shop');
    $inn = registeredVariantFields('inn');
    $carriage = registeredVariantFields('hire_carriage');

    expect($list->fields[0]->options)->toBe([...RecordSchemaCatalog::EVENT_COMMAND_TYPES, 'shop', 'inn', 'hire_carriage'])
        ->and(array_keys($shop))->toBe(['buyRate', 'sellRate'])
        ->and($shop['sellRate']->type)->toBe(InputControlType::FLOAT)
        ->and(array_keys($inn))->toBe(['confirmDialogue.text', 'confirmDialogue.name', 'cost', 'spawnPoint.x', 'spawnPoint.y', 'bgm', 'resultVariable'])
        ->and($inn['bgm']->reference)->toBe('bgm')
        ->and($inn['bgm']->allowsNone)->toBeTrue()
        ->and($inn['cost']->type)->toBe(InputControlType::INTEGER)
        ->and($carriage['destination']->reference)->toBe('maps')
        ->and($carriage['destination']->allowsNone)->toBeFalse()
        ->and($carriage['speed']->options)->toBe(['slow', 'fast'])
        // The first list is edited as the command's entries; a second is kept as written.
        ->and($carriage)->not->toHaveKey('stops')
        ->and($carriage['tolls']->isReadOnly)->toBeTrue();

    $merchandise = $list->nestedListFor(['type' => 'shop']);
    $stops = $list->nestedListFor(['type' => 'hire_carriage']);

    expect($merchandise?->key)->toBe('items')
        ->and(array_map(static fn($field): string => $field->key, $merchandise->fields))->toBe(['item', 'price'])
        ->and($merchandise->fields[0]->reference)->toBe('inventory')
        ->and($merchandise->blank)->toBe(['item' => ''])
        ->and($stops?->key)->toBe('stops');
});

it('forgets a previous project\'s commands when another project opens', function (): void {
    $root = makeTemporaryProject();
    writeCarriageDeclarations($root);
    ProjectWorkspace::fromProject($root);

    ProjectWorkspace::fromProject(makeTemporaryProject());

    expect(RecordSchemaCatalog::getEventCommandTypes())->not->toContain('hire_carriage')
        ->and(RecordSchemaCatalog::getEventCommandTypes())->toContain('shop', 'inn');
});

it('reports registered commands the runtime would refuse and resources they name that do not exist', function (): void {
    $root = makeTemporaryProject();
    writeCarriageDeclarations($root);
    writeScriptCommandScript($root . '/assets/Events/services.php', [
        ['type' => 'shop', 'items' => [['item' => 'S-Potion', 'price' => 40], ['item' => 'Elixir of Nowhere']], 'sellRate' => -1],
        ['type' => 'inn', 'cost' => 30],
        ['type' => 'hire_carriage', 'destination' => 'test-map', 'stops' => [['map' => 'atlantis']]],
        ['type' => 'hire_carriage', 'destination' => 'test-map', 'speed' => 'warp'],
    ]);

    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));
    $messages = array_values(array_map(
        static fn($issue): string => $issue->message,
        array_filter($issues, static fn($issue): bool => str_contains($issue->where, 'services')),
    ));

    expect($messages)->toContain(
        'Its shop command cannot run: "sellRate" must be at least 0.',
        'It names the item "Elixir of Nowhere", which does not exist.',
        'Its inn command cannot run: "confirmDialogue.text" is required.',
        'Its hire_carriage command cannot run: "speed" must be one of slow, fast.',
    )
        ->and(implode("\n", $messages))->toContain('"atlantis"')
        ->and(implode("\n", $messages))->not->toContain('unknown event command type')
        ->and(implode("\n", $messages))->not->toContain('"S-Potion"');
});

it('reports declarations the game would refuse to start with', function (): void {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/' . ScriptCommandRegistry::PROJECT_FILE, "<?php\nreturn [['type' => 'transfer', 'class' => 'X', 'label' => 'Teleport']];\n");
    writeScriptCommandScript($root . '/assets/Events/teleport.php', [['type' => 'shop', 'items' => [['item' => 'S-Potion']]]]);

    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));
    $declaration = array_values(array_filter($issues, static fn($issue): bool => $issue->where === 'assets/Data/script-commands.php'));

    expect($declaration)->toHaveCount(1)
        ->and($declaration[0]->message)->toContain('"transfer", a built-in command type')
        // The Engine's own commands stay authorable meanwhile.
        ->and(array_filter($issues, static fn($issue): bool => str_contains($issue->where, 'teleport')))->toBe([]);
});

it('edits a shop command\'s merchandise and keeps what it does not edit', function (): void {
    $root = makeTemporaryProject();
    ProjectWorkspace::fromProject($root);
    $database = loadRecordDatabase($root, 'common_events');
    // The fixture's first Common Event.
    $path = $root . '/assets/Events/dresser-note.php';
    $commandIndex = $database->addSubItem(0, [
        'type' => 'shop',
        'items' => [['item' => 'S-Potion']],
        'technicalMetadata' => ['preserve' => true],
    ]);
    $fieldIds = static fn(): array => array_column($database->getSettingsFields(0), 'field');
    $find = static fn(string $suffix): string => array_values(array_filter(
        $fieldIds(),
        static fn(string $id): bool => str_starts_with($id, "command{$commandIndex}") && str_ends_with($id, $suffix),
    ))[0] ?? throw new RuntimeException("No field ending {$suffix}: " . implode(', ', $fieldIds()));

    $database->setField(0, $find('0Price'), '40');
    expect($database->addNestedSubItem(0, $commandIndex))->toBe(1);
    $database->setField(0, $find('1Item'), 'Antidote');
    $database->setField(0, $find('SellRate'), '0.25');
    $database->save();

    $payload = require $path;

    expect($payload[$commandIndex])->toBe([
        'type' => 'shop',
        'items' => [['item' => 'S-Potion', 'price' => 40], ['item' => 'Antidote']],
        'technicalMetadata' => ['preserve' => true],
        'sellRate' => 0.25,
    ]);
});
