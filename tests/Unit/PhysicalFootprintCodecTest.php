<?php

declare(strict_types=1);

use Ichiloto\Editor\Maps\PhysicalFootprintCodec;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Editor\Tests\Fixtures\SourceWriterUnit;

require_once fixturePath('SourceWriterEnums.php');

it('round trips every final collision type and null without scalar coercion', function () {
    $types = array_values(array_filter(CollisionType::cases(), static fn(CollisionType $type): bool => $type !== CollisionType::PASS_THROUGH));
    $rows = [[...$types, null]];
    $wire = [array_map(static fn(?CollisionType $type): ?int => $type?->value, [...$types, null])];
    expect(PhysicalFootprintCodec::exportRows($rows))->toBe($wire)
        ->and(PhysicalFootprintCodec::decode(PhysicalFootprintCodec::encode($rows)))->toBe($rows)
        ->and(PhysicalFootprintCodec::decodeRows($wire))->toBe($rows)
        ->and(PhysicalFootprintCodec::decode('[[null,null]]'))->toBe([[null, null]])
        ->and(PhysicalFootprintCodec::encode(null))->toBe('');
});

it('keeps unsupported enum declarations visible for explicit raw row repair without normalizing them', function () {
    $rows = [[SourceWriterUnit::FIRST]];
    expect(PhysicalFootprintCodec::encode($rows))->toContain('SourceWriterUnit::FIRST')
        ->and(fn() => PhysicalFootprintCodec::decode(PhysicalFootprintCodec::encode($rows)))->toThrow(InvalidArgumentException::class)
        ->and(fn() => PhysicalFootprintCodec::exportRows($rows))->toThrow(InvalidArgumentException::class);
});

it('rejects JSON null non lists and cells that are not final integer values before enum conversion', function (string $wire) {
    expect(fn() => PhysicalFootprintCodec::decode($wire))->toThrow(InvalidArgumentException::class);
})->with(['null', 'false', '1', '"rows"', '{}', '{"0":[1]}', '[{"0":1}]', '[null]', '[1]',
    '[[true]]', '[["1"]]', '[[1.0]]', '[["SOLID"]]', '[[9]]', '[[999]]', '[[[1]]]', '[[1]] trailing']);

it('does not normalize invalid authored scalar cells into valid wire recipes', function (mixed $rows) {
    expect(fn() => PhysicalFootprintCodec::exportRows($rows))->toThrow(InvalidArgumentException::class);
})->with(['null' => [null], 'scalar' => ['rows'], 'keyed' => [['rows' => []]],
    'integer' => [[[1]]], 'name' => [[['SOLID']]], 'pass through' => [[[CollisionType::PASS_THROUGH]]]]);
