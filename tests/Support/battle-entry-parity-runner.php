<?php

declare(strict_types=1);

/**
 * Hydrates a battle-entry rule file through the accepted engine head.
 *
 * Run in a subprocess by the parity suite: the editor's own process holds
 * the vendored stable engine, so the accepted head's classes are loaded
 * here instead, ahead of the vendored ones, from a detached read-only
 * source checkout.
 *
 * argv: [1] engine source root, [2] rule file path, [3] actors directory
 * (empty string for none). Prints one JSON object: {ok, order} on success,
 * {ok: false, phase, class, error} on refusal.
 */

[, $engineRoot, $ruleFile, $actorsDirectory] = $argv + [null, '', '', ''];

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Registered after Composer's loader and prepended past it, so every
// engine class resolves from the accepted head, not the vendored release.
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

$store = null;

if ($actorsDirectory !== '') {
    try {
        $store = new Ichiloto\Engine\Util\Stores\ActorStore($actorsDirectory);
    } catch (Throwable $throwable) {
        echo json_encode([
            'ok' => false,
            'phase' => 'store',
            'class' => $throwable::class,
            'error' => $throwable->getMessage(),
        ]);

        exit(0);
    }
}

try {
    $catalog = Ichiloto\Engine\Battle\Entry\BattleEntryRuleCatalog::fromProject($ruleFile, $store);
    echo json_encode([
        'ok' => true,
        'order' => array_map(
            static fn(Ichiloto\Engine\Battle\Entry\BattleEntryRule $rule): string => $rule->id,
            $catalog->rules(),
        ),
    ]);
} catch (Throwable $throwable) {
    echo json_encode([
        'ok' => false,
        'phase' => 'catalog',
        'class' => $throwable::class,
        'error' => $throwable->getMessage(),
    ]);
}
