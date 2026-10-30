<?php

declare(strict_types=1);

/**
 * Opens a project under an Engine that knows one script command reference
 * kind more than this Editor does.
 *
 * Run in a subprocess, because an enum's cases are fixed once loaded: the
 * Engine's own ScriptCommandReference source is read, given one extra case,
 * and loaded ahead of the real one; every other Engine class comes from the
 * Engine checkout named. The project declares a command whose field
 * references that kind, as a later Engine's command would.
 *
 * argv: [1] Engine checkout, [2] project root, [3] scratch directory.
 * Prints one JSON object: {loaded, field, issues} or {loaded: false, error}.
 */

[, $engineRoot, $projectRoot, $scratch] = $argv + [null, '', '', ''];

$loader = require dirname(__DIR__, 2) . '/vendor/autoload.php';
$loader->addPsr4('Ichiloto\\Engine\\', rtrim($engineRoot, '/') . '/src', prepend: true);

$source = (string) file_get_contents(rtrim($engineRoot, '/') . '/src/Events/Interpreter/Commands/ScriptCommandReference.php');
$closing = strrpos($source, '}');
$extended = substr($source, 0, $closing) . "  case FUTURE_KIND = 'future_kind';\n}\n";
$enumPath = rtrim($scratch, '/') . '/ScriptCommandReference.php';
file_put_contents($enumPath, $extended);
spl_autoload_register(static function (string $class) use ($enumPath): void {
    if ($class === 'Ichiloto\\Engine\\Events\\Interpreter\\Commands\\ScriptCommandReference') {
        require $enumPath;
    }
}, prepend: true);

try {
    $workspace = Ichiloto\Editor\ProjectWorkspace::fromProject($projectRoot);
    $variant = Ichiloto\Editor\Database\RecordSchemaCatalog::eventCommandVariants()['consult_oracle'] ?? [];
    $field = array_find(is_array($variant) ? $variant : [], static fn($field): bool => $field->key === 'oracle');
    $issues = new Ichiloto\Editor\Validation\ProjectValidator()->validate($workspace);
    echo json_encode([
        'loaded' => true,
        'maps' => count($workspace->maps),
        'field' => $field === null ? null : ['label' => $field->label, 'reference' => $field->reference, 'readOnly' => $field->isReadOnly],
        'issues' => array_values(array_map(static fn($issue): array => ['where' => $issue->where, 'message' => $issue->message, 'hint' => $issue->hint],
            array_filter($issues, static fn($issue): bool => str_contains($issue->message, 'consult_oracle')))),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['loaded' => false, 'error' => $error::class . ': ' . $error->getMessage()], JSON_THROW_ON_ERROR);
}
