<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Battle\Presentation\BattleFormationLayout;
use InvalidArgumentException;

/**
 * A physical-height guide an author stands on a battle arena's preview to
 * judge its measured scale: a figure of an authored real height, in the
 * unit the arena's pixels per unit counts, drawn at the reference row, at
 * rows the author picks (the front of a stage) and at each battler's feet.
 * Every height comes from the Engine's projection of the arena's measured
 * ground ({@see \Ichiloto\Engine\Battle\Presentation\BattleGroundScale::getProjectedHeight()}),
 * the one the battle stands battlers by.
 *
 * It is an Editor authoring aid and never runtime data. A project keeps its
 * guide height as an editor preference in `ichiloto.json`
 * (`editor.battleScaleGuide.height`); a preview request may name another
 * height and the rows to stand it on, which are written nowhere.
 */
final readonly class BattleScaleGuide
{
    /** The editor preference holding the project's guide height. */
    public const string CONFIG_KEY = 'battleScaleGuide';

    /**
     * @param list<array{y: float, x: ?float, label: ?string}> $rows Rows the author stands the guide on, in canvas units.
     */
    public function __construct(public float $height, public string $source, public array $rows = []) {}

    /**
     * The guide a preview asks for: its height, else the project's, and its
     * rows; null when neither names a height.
     *
     * @param array<string, mixed> $request `height` and `rows` (each `y`, optional `x` and `label`).
     * @throws InvalidArgumentException When the request or the project preference is not a guide.
     */
    public static function read(array $request, string $projectRoot): ?self
    {
        if (array_diff(array_keys($request), ['height', 'rows']) !== []) {
            throw new InvalidArgumentException('A scale guide takes a height and rows.');
        }
        $rows = [];
        foreach ($request['rows'] ?? [] as $position => $row) {
            if (! is_array($row) || array_diff(array_keys($row), ['y', 'x', 'label']) !== [] || ! self::isNumber($row['y'] ?? null)
                || (isset($row['x']) && ! self::isNumber($row['x'])) || (isset($row['label']) && ! is_string($row['label']))) {
                throw new InvalidArgumentException(sprintf('Scale guide row %d needs a canvas y, and may name a canvas x and a label.', $position));
            }
            $rows[] = ['y' => (float) $row['y'], 'x' => isset($row['x']) ? (float) $row['x'] : null, 'label' => $row['label'] ?? null];
        }
        if (array_key_exists('height', $request)) {
            return self::isNumber($request['height']) ? new self((float) $request['height'], 'request', $rows)
                : throw new InvalidArgumentException('A scale guide height must be a number.');
        }
        $project = self::readProjectHeight($projectRoot);

        return $project === null ? null : new self($project, 'project', $rows);
    }

    /**
     * The guide on a composed formation: the arena's measured horizon,
     * reference row, pixels per unit and vanishing point, and the guide's
     * projected height at the reference row, at each authored row and at
     * each battler's feet beside that battler's body span. A row the
     * projection refuses (one at or above the horizon) carries its reason.
     * An arena without a measured scale has no physical guide; that is said.
     *
     * @param list<string> $partyNames
     * @param list<string> $enemyNames
     * @return array<string, mixed>
     * @throws InvalidArgumentException With the Engine's reason when the height cannot be projected.
     */
    public function describe(BattleFormationLayout $formation, array $partyNames, array $enemyNames): array
    {
        $scale = $formation->staging->ground->scaleReference;
        $guide = ['height' => $this->height, 'source' => $this->source];
        if ($scale === null) {
            return [...$guide, 'placements' => [],
                'issue' => 'This arena\'s ground has no measured scale, so a physical height cannot be projected on it. Use Measured Scale to set one.'];
        }
        // The Engine refuses a height it cannot project, once, for the whole guide.
        $scale->getProjectedHeight($scale->referenceY, $this->height);
        $centre = $scale->vanishingX ?? $formation->layout->width / 2;
        $place = static function (array $placement, float $y) use ($scale, $guide): array {
            try {
                return [...$placement, 'y' => $y, 'height' => $scale->getProjectedHeight($y, $guide['height'])];
            } catch (InvalidArgumentException $refused) {
                return [...$placement, 'y' => $y, 'height' => null, 'issue' => $refused->getMessage()];
            }
        };
        $placements = [$place(['role' => 'reference', 'label' => 'Reference row', 'x' => $centre], $scale->referenceY)];
        foreach ($this->rows as $position => $row) {
            $placements[] = $place(['role' => 'row', 'label' => $row['label'] ?? sprintf('Row %d', $position + 1), 'x' => $row['x'] ?? $centre],
                $row['y']);
        }
        foreach (['party' => [$formation->party, $partyNames], 'enemy' => [$formation->enemies, $enemyNames]] as $role => [$battlers, $names]) {
            foreach ($battlers as $position => $battler) {
                $placements[] = $place(['role' => $role, 'position' => $position, 'label' => $names[$position] ?? $battler->id,
                    'x' => $battler->ground->x, 'bodySpan' => $battler->bodySpan], $battler->ground->y);
            }
        }

        return [...$guide, ...$scale->toArray(), 'placements' => $placements];
    }

    /**
     * The project's guide height from its editor preferences, or null when it keeps none.
     *
     * @throws InvalidArgumentException When the preference is not a height.
     */
    private static function readProjectHeight(string $projectRoot): ?float
    {
        $path = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ichiloto.json';
        $config = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $guide = is_array($config) && is_array($config['editor'] ?? null) ? ($config['editor'][self::CONFIG_KEY] ?? null) : null;
        if ($guide === null) {
            return null;
        }
        if (! is_array($guide) || array_keys($guide) !== ['height'] || ! self::isNumber($guide['height'])) {
            throw new InvalidArgumentException(sprintf('ichiloto.json editor.%s must be {"height": number}.', self::CONFIG_KEY));
        }

        return (float) $guide['height'];
    }

    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
