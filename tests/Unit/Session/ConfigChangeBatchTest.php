<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\ChangeBatch;
use Ichiloto\Editor\Session\EditorSession;

/**
 * Terms and Configuration both edit `config.php` through the project's
 * settings file; a change set writes that file once, with both edits.
 * Synthetic fixtures only.
 */

it('commits Terms and Configuration edits to config.php in one change set', function () {
    $root = makeTemporaryProject();
    $session = EditorSession::open($root);
    $terms = $session->listDatabaseRecords('terms')['records'];
    $settings = $session->listDatabaseRecords('configuration')['records'];
    $term = array_search('messages.income.held', $terms, true);
    $setting = array_search('graphics.field.zoom', $settings, true);

    $outcome = ChangeBatch::apply($root, [
        ['method' => 'database.apply', 'params' => ['category' => 'terms', 'index' => $term,
            'key' => ['target' => 'record', 'field' => 'value', 'frame' => []], 'value' => '%4 %2 waiting at the guild.']],
        ['method' => 'database.apply', 'params' => ['category' => 'configuration', 'index' => $setting,
            'key' => ['target' => 'record', 'field' => 'value', 'frame' => []], 'value' => '3']],
    ]);
    $config = require $root . '/config.php';

    expect($outcome['status'])->toBe('saved')
        ->and($config['messages']['income']['held'] ?? null)->toBe('%4 %2 waiting at the guild.')
        ->and($config['graphics']['field']['zoom'] ?? null)->toEqual(3);
});
