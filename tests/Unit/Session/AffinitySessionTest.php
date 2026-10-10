<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;

/** What a graphical editor builds elemental affinities from, and how it writes them. Synthetic fixtures only. */

/** The open enemy's Element Affinities row. */
function readAffinityRow(EditorSession $session): array
{
    return array_find($session->readDatabaseRecord('enemies', 0)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === 'elementAffinities');
}

it('offers an affinity row as entries, built from the game\'s elements and the named effects', function () {
    $session = EditorSession::open(enemyPreviewProject());
    $vocabulary = $session->describeAffinityVocabulary();

    expect(readAffinityRow($session))->toMatchArray(['kind' => 'affinities', 'entries' => []])
        ->and($vocabulary['elements'])->toContain('Fire', 'Water')
        ->and($vocabulary['effects'])->toBe([
            ['label' => 'Weak', 'multiplier' => 2.0],
            ['label' => 'Resist', 'multiplier' => 0.5],
            ['label' => 'Null', 'multiplier' => 0.0],
            ['label' => 'Absorb', 'multiplier' => -1.0],
        ]);
});

it('writes any multiplier, named or between, and the row reads it back as entries', function () {
    $session = EditorSession::open(enemyPreviewProject());
    $encoded = $session->encodeAffinities([['element' => 'Water', 'multiplier' => -1], ['element' => 'Fire', 'multiplier' => 2.5]]);

    expect($encoded)->toBe(['line' => 'Water: -1; Fire: 2.5', 'descriptions' => ['Water: Absorb ×-1', 'Fire: ×2.5']]);

    $session->applyDatabaseRecord('enemies', 0, readAffinityRow($session)['key'], $encoded['line']);

    expect(readAffinityRow($session)['entries'])->toBe([['element' => 'Water', 'multiplier' => -1.0], ['element' => 'Fire', 'multiplier' => 2.5]]);
});

it('refuses an element the game does not know, one listed twice, or a multiplier that is not a number', function () {
    $session = EditorSession::open(enemyPreviewProject());

    expect(fn() => $session->encodeAffinities([['element' => 'Plasma', 'multiplier' => 2]]))->toThrow(Ichiloto\Editor\Session\SessionRefusal::class, 'an element the game knows')
        ->and(fn() => $session->encodeAffinities([['element' => 'Fire', 'multiplier' => 2], ['element' => 'Fire', 'multiplier' => 0.5]]))->toThrow(Ichiloto\Editor\Session\SessionRefusal::class, 'listed twice')
        ->and(fn() => $session->encodeAffinities([['element' => 'Fire', 'multiplier' => 'hot']]))->toThrow(Ichiloto\Editor\Session\SessionRefusal::class, 'needs a multiplier')
        ->and($session->encodeAffinities([])['line'])->toBe('');
});
