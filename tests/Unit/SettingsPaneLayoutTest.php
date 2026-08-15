<?php

declare(strict_types=1);

use Ichiloto\Editor\UI\ScrollWindow;
use Ichiloto\Editor\UI\SettingsPaneLayout;

/**
 * Builds a row for the layout.
 *
 * @return array{prefix: string, label: string, value: string, editable: bool, singleLine: bool}
 */
function paneRow(string $label, string $value, bool $editable = true, bool $selected = false, bool $singleLine = false): array
{
    return ['prefix' => $selected ? '> ' : '  ', 'label' => $label, 'value' => $value, 'editable' => $editable, 'singleLine' => $singleLine];
}

it('keeps short rows on one line and matches ScrollWindow exactly for them', function () {
    $rows = [];

    foreach (range(1, 12) as $n) {
        $rows[] = paneRow("Field $n", "v$n", selected: $n === 10);
    }

    $layout = SettingsPaneLayout::layout($rows, 40, 5, 9);

    expect(count($layout->lines))->toBe(12)
        ->and($layout->spans[9])->toBe([9, 1])
        ->and($layout->offset)->toBe(ScrollWindow::offset(9, 5))
        ->and($layout->visibleLines())->toBe(ScrollWindow::slice($layout->lines, 9, 5))
        ->and($layout->rowOfField(9))->toBe(4)
        ->and($layout->rowOfField(0))->toBeNull()
        ->and($layout->lines[9])->toBe('> Field 10: v10')
        ->and($layout->lines[0])->toBe('  Field 1: v1');
});

it('wraps a long value at word boundaries under the value column, mb-safe', function () {
    $value = 'The gate is closed until the harvest festival, traveller — come back at dawn with 🐈 in tow.';
    $layout = SettingsPaneLayout::layout([paneRow('Text', $value)], 40, 10, 0);

    expect(count($layout->lines))->toBeGreaterThanOrEqual(3)
        ->and($layout->spans[0])->toBe([0, count($layout->lines)])
        ->and($layout->lines[0])->toStartWith('  Text: The gate is closed');

    foreach ($layout->lines as $i => $line) {
        expect(mb_strwidth($line))->toBeLessThanOrEqual(40);

        if ($i > 0) {
            // Continuation lines start under the value column (after '  Text: ').
            expect($line)->toStartWith(str_repeat(' ', 8))
                ->and(substr($line, 8, 1))->not->toBe(' ');
        }
    }

    // Nothing was lost or split mid-word: rejoining gives the value back.
    $rejoined = trim(implode(' ', array_map(static fn(string $l): string => trim(substr($l, $l === $layout->lines[0] ? 8 : 0)), $layout->lines)));
    expect($rejoined)->toBe($value);
});

it('gives a long label a hanging indent instead of a sliver of value per line', function () {
    $label = 'Command 1 Route step 1 Direction';
    $layout = SettingsPaneLayout::layout([paneRow($label, str_repeat('north ', 12))], 40, 10, 0);

    // The label plus ': ' leaves nothing useful: the value starts on the
    // next line under a hanging indent, never a couple of glyphs per line.
    expect($layout->lines[0])->toBe('  ' . $label . ':')
        ->and($layout->lines[1])->toStartWith('    north');

    // A read-only row in the same spot keeps its label bare: no dangling dot.
    $note = SettingsPaneLayout::layout([paneRow('Dialogue variant 12 of many words', 'when switch:gate_open and more', editable: false)], 30, 10, 0);
    expect($note->lines[0])->toBe('  Dialogue variant 12 of many words')
        ->and($note->lines[1])->toStartWith('    when switch:gate_open');

    foreach ($layout->lines as $line) {
        expect(mb_strwidth($line))->toBeLessThanOrEqual(40);
    }
});

it('never wraps the row being edited, and hard-breaks only a word wider than a line', function () {
    $edited = SettingsPaneLayout::layout([paneRow('Text', str_repeat('x', 80), selected: true, singleLine: true)], 40, 10, 0);
    expect($edited->lines)->toHaveCount(1)
        ->and($edited->lines[0])->toBe('> Text: ' . str_repeat('x', 80));

    $word = SettingsPaneLayout::layout([paneRow('Id', str_repeat('y', 70))], 40, 10, 0);
    expect(count($word->lines))->toBeGreaterThanOrEqual(2)
        ->and(implode('', array_map(trim(...), $word->lines)))->toBe('Id: ' . str_repeat('y', 70));
});

it('keeps a tall selected field whole when the pane can hold it, and its first line always', function () {
    $rows = [paneRow('A', 'a'), paneRow('B', 'b'), paneRow('Text', str_repeat('lorem ipsum ', 20)), paneRow('D', 'd')];
    $layout = SettingsPaneLayout::layout($rows, 30, 6, 2);
    [$first, $count] = $layout->spans[2];

    expect($count)->toBeGreaterThan(3)
        // The whole span fits in 6 rows only if it is short enough; either
        // way its first line is inside the window.
        ->and($layout->rowOfField(2))->not->toBeNull()
        ->and($layout->offset)->toBeLessThanOrEqual($first);

    // A continuation line resolves to its field; a heading-only row too.
    expect($layout->fieldAtRow($layout->rowOfField(2) + 1))->toBe(2);

    // Past the end reads as the last field.
    $clamped = SettingsPaneLayout::layout($rows, 30, 3, 99);
    expect($clamped->offset)->toBe($clamped->spans[3][0] - 3 + 1);
});

it('wraps prose keeping its indent, and leaves fitting prose alone', function () {
    expect(SettingsPaneLayout::wrapProse('  None. Nothing changes when this completes.', 30))
        ->toBe(['  None. Nothing changes when', '  this completes.'])
        ->and(SettingsPaneLayout::wrapProse('  a to add a write.', 30))->toBe(['  a to add a write.']);
});
