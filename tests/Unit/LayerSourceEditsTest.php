<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;

it('renames only a literal layer key while retaining its comment and expression bytes', function () {
    $source = "<?php return ['layers' => ['buildings' /* keep */ => array_replace([], ['symbols' => []])]];";
    $document = PhpArraySourceDocument::parse($source);
    $old = ['layers' => ['buildings' => ['symbols' => []]]];
    $new = ['layers' => ['houses' => ['symbols' => []]]];
    $rewritten = ArraySourceWriter::rewrite($document, $old, $new,
        [['path' => ['layers', 'buildings'], 'key' => 'houses']]);
    expect($rewritten->source)->toBe(str_replace("'buildings'", "'houses'", $source));
});

it('refuses key renames into an existing key or across an opaque sibling', function (string $source) {
    $document = PhpArraySourceDocument::parse($source);
    expect(fn() => $document->renameKeyEdit(['layers', 'buildings'], 'houses'))->toThrow(SourcePreservationRefusal::class)
        ->and($document->source)->toBe($source);
})->with([
    "<?php return ['layers' => ['buildings' => [], 'houses' => []]];",
    "<?php return ['layers' => ['buildings' => [], strtolower('OTHER') => []]];",
]);

it('wraps a legacy table expression without evaluating or flattening it', function () {
    $source = "<?php return ['tiles2d' => require __DIR__ . '/crops.php'];";
    $document = PhpArraySourceDocument::parse($source);
    expect($document->withEdits([$document->wrapValueEdit(['tiles2d'], ['layers', 'terrain'])])->source)
        ->toBe("<?php return ['tiles2d' => ['layers' => ['terrain' => require __DIR__ . '/crops.php']]];");
});
