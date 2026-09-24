<?php

declare(strict_types=1);

it('canonicalizes a relative game source root before disposable fixture links are made', function () {
    $root = makeTemporaryProject();
    mkdir($root . '/assets/Cutscenes/Summons', 0777, true);
    mkdir($root . '/assets/Audio', 0777, true);
    file_put_contents($root . '/assets/Audio/reference.wav', 'fixture audio bytes');
    $previous = getenv('ICHILOTO_GAME_SRC');
    $directory = getcwd();
    try {
        chdir(dirname($root));
        putenv('ICHILOTO_GAME_SRC=' . basename($root));
        expect(gameSourceRoot())->toBe(realpath($root));
        $copy = disposableLastLegend();
        expect(is_link($copy . '/assets/Audio'))->toBeTrue()
            ->and(file_get_contents($copy . '/assets/Audio/reference.wav'))->toBe('fixture audio bytes');
    } finally {
        chdir($directory);
        putenv($previous === false ? 'ICHILOTO_GAME_SRC' : 'ICHILOTO_GAME_SRC=' . $previous);
    }
});
