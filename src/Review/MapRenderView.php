<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use InvalidArgumentException;

/**
 * One view of a map to render for review: the whole map or a rectangle of it
 * in map cells, with whoever stands on it, under a label the review page
 * shows. The same view renders as the Terminal field and as the graphical
 * field draw it.
 */
final readonly class MapRenderView
{
    /** The most cells a view spans across or down: the Engine's retained world extent. */
    public const int MAX_EXTENT = 16384;

    public string $label;

    /**
     * @param array{x: int, y: int, width: int, height: int}|null $region The rectangle in map cells; null is the whole map.
     */
    public function __construct(
        public string $mapId,
        public ?array $region = null,
        public ?MapRenderSubject $subject = null,
        ?string $label = null,
    ) {
        if (trim($mapId) === '') {
            throw new InvalidArgumentException('A view names the map it shows.');
        }
        if ($region !== null) {
            foreach (['x', 'y', 'width', 'height'] as $key) {
                if (! is_int($region[$key] ?? null)) {
                    throw new InvalidArgumentException(sprintf('A region gives x, y, width and height in whole cells; %s is missing.', $key));
                }
            }
            if ($region['x'] < 0 || $region['y'] < 0 || $region['width'] < 1 || $region['height'] < 1
                || $region['width'] > self::MAX_EXTENT || $region['height'] > self::MAX_EXTENT) {
                throw new InvalidArgumentException('A region starts at a cell of the map and spans at least one cell each way.');
            }
        }
        $this->label = $label !== null && trim($label) !== '' ? trim($label) : self::describe($mapId, $region);
    }

    /**
     * Reads a view written `MAP` or `MAP@X,Y,WIDTH,HEIGHT`.
     *
     * @throws InvalidArgumentException When it is neither.
     */
    public static function readSpecification(string $specification, ?MapRenderSubject $subject = null): self
    {
        [$mapId, $region] = array_pad(explode('@', trim($specification), 2), 2, null);

        return new self((string) $mapId, $region === null ? null : self::readRegion($region), $subject);
    }

    /**
     * Reads a region written `X,Y,WIDTH,HEIGHT` in whole cells.
     *
     * @return array{x: int, y: int, width: int, height: int}
     * @throws InvalidArgumentException When it is not four whole numbers.
     */
    public static function readRegion(string $region): array
    {
        $parts = array_map(trim(...), explode(',', $region));
        if (count($parts) !== 4 || array_any($parts, static fn(string $part): bool => preg_match('/\A\d+\z/', $part) !== 1)) {
            throw new InvalidArgumentException(sprintf('"%s" is no region: write X,Y,WIDTH,HEIGHT in whole cells.', $region));
        }

        return ['x' => (int) $parts[0], 'y' => (int) $parts[1], 'width' => (int) $parts[2], 'height' => (int) $parts[3]];
    }

    /**
     * A file-name stem for the view's pictures, unique among views of
     * different maps and regions: the map id and region, in safe characters.
     */
    public function createFileStem(): string
    {
        $stem = str_replace('/', '--', $this->mapId);
        if ($this->region !== null) {
            $stem .= sprintf('@%d,%d,%d,%d', $this->region['x'], $this->region['y'], $this->region['width'], $this->region['height']);
        }

        return preg_replace('/[^A-Za-z0-9@,._-]/', '_', $stem) ?? $stem;
    }

    /** @param array{x: int, y: int, width: int, height: int}|null $region */
    private static function describe(string $mapId, ?array $region): string
    {
        return $region === null ? $mapId
            : sprintf('%s, x %d, y %d, %d by %d', $mapId, $region['x'], $region['y'], $region['width'], $region['height']);
    }
}
