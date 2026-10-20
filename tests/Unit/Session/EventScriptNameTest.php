<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * A new event script can be made under the name scripts and triggers will
 * call it by, which becomes its file's name. Synthetic fixtures only.
 */

it('makes an event script under a given name, saved as that file', function () {
    $root = makeTemporaryProject();
    $session = EditorSession::open($root);

    $created = $session->createDatabaseRecord('common_events', 'payday-notice');
    $session->saveDatabase('common_events');

    expect($created['records'])->toContain('payday-notice')
        ->and(is_file($root . '/assets/Events/payday-notice.php'))->toBeTrue()
        ->and(require $root . '/assets/Events/payday-notice.php')->toBe([['type' => 'text', 'name' => '', 'text' => 'Something happens.']]);
});

it('refuses a name that is not in id form or that a script already has', function () {
    $root = makeTemporaryProject();
    $session = EditorSession::open($root);

    expect(fn() => $session->createDatabaseRecord('common_events', 'Payday Notice'))
        ->toThrow(SessionRefusal::class, 'written in lower case with hyphens, such as "payday-notice"')
        ->and(fn() => $session->createDatabaseRecord('common_events', 'dresser-note'))
        ->toThrow(SessionRefusal::class, 'There is already an event script named dresser-note.');
});
