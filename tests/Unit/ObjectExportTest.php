<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\PhpValueExporter;

/**
 * A record authored as a constructor call, the way items.php is.
 */
final class ExportableRecord
{
    public function __construct(
        public readonly string $name,
        public readonly int $price = 0,
        public readonly array $tags = [],
        public readonly ?ExportableRecord $inner = null,
    ) {
    }
}

/**
 * A record that keeps its argument under another name, so it cannot be
 * rebuilt from what it holds.
 */
final class UnrebuildableRecord
{
    /**
     * @var string[]
     */
    private array $collected = [];

    public function __construct(array $entries = [])
    {
        $this->collected = $entries;
    }
}

it('exports an object as the constructor call that rebuilds it', function () {
    $code = PhpValueExporter::export(new ExportableRecord('S-Potion', 50, ['heal']));

    expect($code)->toContain('new \\ExportableRecord(')
        // Named arguments, so a later change to the parameter order cannot
        // silently reorder anyone's data.
        ->and($code)->toContain("name: 'S-Potion'")
        ->and($code)->toContain('price: 50');
});

it('rebuilds an equal object from what it exported', function () {
    $original = new ExportableRecord('Elixir', 500, ['heal', 'rare'], new ExportableRecord('Vial'));

    $rebuilt = eval('return ' . PhpValueExporter::export($original) . ';');

    expect($rebuilt)->toEqual($original)
        ->and(PhpValueExporter::export($rebuilt))->toBe(PhpValueExporter::export($original));
});

it('exports a list of objects', function () {
    $code = PhpValueExporter::export([new ExportableRecord('A'), new ExportableRecord('B')]);
    $rebuilt = eval("return {$code};");

    expect($rebuilt)->toHaveCount(2)
        ->and($rebuilt[1]->name)->toBe('B');
});

it('reports an object it cannot rebuild, and names it', function () {
    $reason = PhpValueExporter::describeUnexportable([new UnrebuildableRecord(['a'])]);

    // The file stays read-only rather than being rewritten into something
    // that loses what the author wrote.
    expect($reason)->toContain('UnrebuildableRecord')
        ->and($reason)->toContain('cannot be rebuilt');
});

it('accepts an object every argument of which can be read back', function () {
    expect(PhpValueExporter::describeUnexportable([new ExportableRecord('A')]))->toBeNull()
        ->and(PhpValueExporter::isExportable(new ExportableRecord('A')))->toBeTrue();
});

it('reads back the arguments an object was built with', function () {
    $arguments = PhpValueExporter::constructorArguments(new ExportableRecord('Tonic', 25, ['x']));

    // Trailing arguments left at their defaults are dropped, so a rewritten
    // file reads like the one an author wrote.
    expect($arguments)->toBe(['name' => 'Tonic', 'price' => 25, 'tags' => ['x']]);
});

it('drops a default even when a later argument was changed', function () {
    $arguments = PhpValueExporter::constructorArguments(
        new ExportableRecord('Tonic', 0, [], new ExportableRecord('Vial'))
    );

    // Everything is written as a named argument, so the untouched middle ones
    // simply do not appear.
    expect(array_keys($arguments))->toBe(['name', 'inner']);
});

it('has nothing to read back from an object with no constructor', function () {
    expect(PhpValueExporter::constructorArguments(new stdClass()))->toBe([]);
});
