<?php

declare(strict_types=1);

use Ichiloto\Editor\Maps\UnsavedMapLines;

it('holds the lines a change set inserted, in the coordinates its later line edits leave', function () {
    $lines = new UnsavedMapLines();
    expect($lines->isEmpty)->toBeTrue();

    $lines->recordInsertion('town', 'x', 38, 16);
    expect($lines->containsCell('town', 38, 0))->toBeTrue()
        ->and($lines->containsCell('town', 53, 25))->toBeTrue()
        ->and($lines->containsCell('town', 37, 5))->toBeFalse()
        ->and($lines->containsCell('town', 54, 5))->toBeFalse()
        ->and($lines->containsCell('elsewhere', 40, 5))->toBeFalse()
        ->and($lines->isEmpty)->toBeFalse();

    // An insertion before them pushes them on; one inside them is inserted too.
    $lines->recordInsertion('town', 'x', 10, 2);
    expect($lines->containsCell('town', 39, 0))->toBeFalse()
        ->and($lines->containsCell('town', 40, 0))->toBeTrue()
        ->and($lines->containsCell('town', 11, 0))->toBeTrue();

    // A removal drops the lines it takes and pulls the rest back.
    $lines->recordRemoval('town', 'x', 10, 2);
    expect($lines->containsCell('town', 10, 0))->toBeFalse()
        ->and($lines->containsCell('town', 38, 0))->toBeTrue()
        ->and($lines->containsCell('town', 53, 0))->toBeTrue();

    // Rows and columns are held apart: a row insertion marks whole rows.
    $lines->recordInsertion('town', 'y', 3, 1);
    expect($lines->containsCell('town', 0, 3))->toBeTrue()
        ->and($lines->containsCell('town', 0, 4))->toBeFalse();
});
