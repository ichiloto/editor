<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\EdgeTransferAgreementCheck;
use Ichiloto\Editor\Validation\Severity;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\MapGridSource;

/**
 * Two synthetic yards joined by walk-through transfers. Each is laid out by
 * its rows: '#' is solid and '.' open ground; markers in the events rows are
 * the transfers named in $transfers as [destination, x, y, heading or null].
 *
 * @param array<string, array{rows: list<string>, events: list<string>, transfers: array<string, array{0: string, 1: int, 2: int, 3: ?string}>}> $maps
 */
function edgeTransferProject(array $maps): ProjectWorkspace
{
    $root = makeTemporaryProject('editor-edge-transfer-');
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php\n\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n\n"
        . "return ['.' => CollisionType::NONE, '#' => CollisionType::SOLID];\n");
    foreach ($maps as $id => $map) {
        $directory = "{$root}/assets/Maps/{$id}";
        mkdir($directory);
        file_put_contents("{$directory}/{$id}.map.php", MapGridSource::buildSource(implode("\n", $map['rows']), 'ICHILOTO_MAP'));
        file_put_contents("{$directory}/{$id}.event.php", MapGridSource::buildSource(implode("\n", $map['events']), 'EVENTS'));
        $events = [];
        foreach ($map['transfers'] as $marker => [$destination, $x, $y, $heading]) {
            $events[$marker] = ['class' => TransferPlayerTrigger::class, 'data' => [
                'destinationMap' => $destination, 'spawnPoint' => ['x' => $x, 'y' => $y],
                ...($heading === null ? [] : ['spawnSprite' => [ucfirst($heading)]]),
            ]];
        }
        file_put_contents("{$directory}/{$id}.data.php", "<?php\nreturn " . var_export(['name' => $id, 'region' => '', 'events' => $events], true) . ";\n");
    }

    return ProjectWorkspace::fromProject($root);
}

/** @return array<string, string> Each finding's message, by where it was found. */
function readEdgeTransferFindings(ProjectWorkspace $workspace): array
{
    $found = [];
    foreach (new EdgeTransferAgreementCheck()->validate($workspace) as $issue) {
        expect($issue->severity)->toBe(Severity::ERROR);
        $found[$issue->where] = $issue->message;
    }
    ksort($found);

    return $found;
}

/** The east yard: a three-cell gap in its west wall, leading back to the west yard. */
function eastYard(int $arrivalX = 4): array
{
    return ['rows' => ['######', '.....#', '.....#', '.....#', '######'], 'events' => ['      ', 'W     ', 'W     ', 'W     ', '      '],
        'transfers' => ['W' => ['west-yard', $arrivalX, 2, null]]];
}

it('accepts edges that face each other, crossed both ways with the walking heading kept', function () {
    $workspace = edgeTransferProject([
        'west-yard' => ['rows' => ['######', '#.....', '#.....', '#.....', '######'], 'events' => ['      ', '     E', '     E', '     E', '      '],
            'transfers' => ['E' => ['east-yard', 1, 2, 'east']]],
        'east-yard' => eastYard(),
    ]);

    expect(readEdgeTransferFindings($workspace))->toBe([]);
});

it('reports a link whose two maps disagree about which edges meet', function () {
    // The west yard is left by its south edge but its neighbour is entered from the west.
    $workspace = edgeTransferProject([
        'west-yard' => ['rows' => ['######', '#....#', '#....#', '#....#', '##..##'], 'events' => ['      ', '      ', '      ', '      ', '  SS  '],
            'transfers' => ['S' => ['east-yard', 1, 2, null]]],
        'east-yard' => eastYard(2),
    ]);

    expect(readEdgeTransferFindings($workspace))->toBe([
        'east-yard event W' => 'The player crosses the west edge here: it arrives at (2, 2) on west-yard, where walking east does not lead back '
            . 'to it; the way back, west-yard event S, leaves by its south edge, not its east edge.',
        'west-yard event S' => 'The player crosses the south edge here: it arrives at (1, 2) on east-yard, where walking north does not lead back '
            . 'to it; the way back, east-yard event W, leaves by its west edge, not its north edge.',
    ]);
});

it('reports an arrival turned away from the way the player was walking', function () {
    $workspace = edgeTransferProject([
        'west-yard' => ['rows' => ['######', '#.....', '#.....', '#.....', '######'], 'events' => ['      ', '     E', '     E', '     E', '      '],
            'transfers' => ['E' => ['east-yard', 1, 2, 'south']]],
        'east-yard' => eastYard(),
    ]);

    expect(readEdgeTransferFindings($workspace))->toBe([
        'west-yard event E' => 'The player crosses the east edge here: it leaves heading east but turns the player south on arrival.',
    ]);
});

it('leaves a doorway with its own doorstep beyond it to the door checks', function () {
    // The door stands a cell in from the west edge with open ground behind it, so it carries no one across the edge.
    $workspace = edgeTransferProject([
        'west-yard' => ['rows' => ['######', '#.....', '#.....', '#.....', '######'], 'events' => ['      ', '     E', '     E', '     E', '      '],
            'transfers' => ['E' => ['east-yard', 3, 2, null]]],
        'east-yard' => ['rows' => ['######', '.....#', '.....#', '.....#', '######'], 'events' => ['      ', ' W    ', ' W    ', ' W    ', '      '],
            'transfers' => ['W' => ['west-yard', 2, 2, null]]],
    ]);

    expect(readEdgeTransferFindings($workspace))->toBe([]);
});

