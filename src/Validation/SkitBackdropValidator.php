<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Field\RegionCatalog;
use Ichiloto\Engine\Field\SkitBackdrop;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use InvalidArgumentException;
use Throwable;

/**
 * Checks skit backdrops as their pages hold them, unsaved edits included, by the Engine's own choice
 * ({@see SkitBackdrop::resolve()}): that the regions catalogue reads, that every backdrop a skit, a map or a region
 * names is a PNG the game can draw, that every region record names a region some map is in, and where a skit would
 * show no backdrop. A missing backdrop is a warning: the skit plays on the theme backing and the game logs it.
 */
final class SkitBackdropValidator
{
    private const string REGIONS = 'assets/' . RegionCatalog::FILE;
    /** How many maps a warning names before it counts the rest. */
    private const int LISTED_MAPS = 6;

    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $references = new ReferenceCatalog($workspace);
        $root = $references->getAssetRoot();
        try {
            $regions = $references->getRegionCatalog();
        } catch (Throwable $error) {
            return [Issue::error(self::REGIONS, 'The game refuses the regions: ' . $error->getMessage(),
                'Fix them on the Regions page; until then no region gives its maps a skit backdrop.')];
        }

        $issues = [];
        $usedRegions = [];
        $mapBindings = [];
        foreach ($workspace->maps as $map) {
            $binding = SkitBackdrop::readMapBinding($map->getEditableData());
            $mapBindings[$map->mapId] = $binding;
            if (RegionCatalog::isRegionName($binding['region'] ?? null)) { $usedRegions[$binding['region']] = true; }
            if (($binding[SkitBackdrop::MAP_KEY] ?? null) === null) { continue; }
            $problem = self::describeProblem($binding[SkitBackdrop::MAP_KEY], $root, 'The map\'s backdrop');
            if ($problem !== null) {
                $issues = [...$issues, ...Issue::attributeToMap([Issue::warning(sprintf('%s: %s', $map->mapId, SkitBackdrop::MAP_KEY),
                    'Skits here show no backdrop: ' . $problem, 'Choose a project image on the map\'s Skit Backdrop row.')], $map->mapId)];
            }
        }
        foreach ($regions->regions as $name => $record) {
            $name = (string) $name;
            if (! isset($usedRegions[$name])) {
                $issues[] = Issue::warning(sprintf('%s: %s', self::REGIONS, $name), sprintf('No map is in region %s.', $name),
                    'Choose the region a map names on its Region row, or remove the record on the Regions page.');
            }
            $asset = $record[SkitBackdrop::MAP_KEY] ?? null;
            $problem = $asset === null ? null : self::describeProblem($asset, $root, sprintf('Region %s\'s backdrop', $name));
            if ($problem !== null) {
                $issues[] = Issue::warning(sprintf('%s: %s', self::REGIONS, $name), $problem,
                    'Choose a project image for its Skit Backdrop on the Regions page.');
            }
        }

        return [...$issues, ...$this->checkSkits($workspace, $mapBindings, $regions, $root)];
    }

    /**
     * Every skit backdrop that cannot be drawn, and every map a skit can play on where it would show none.
     *
     * @param array<string, array<string, mixed>> $mapBindings Each map's part in the choice, by map id.
     * @return Issue[]
     */
    private function checkSkits(ProjectWorkspace $workspace, array $mapBindings, RegionCatalog $regions, string $root): array
    {
        $issues = [];
        foreach ($workspace->getRecordDatabase('skits')?->getRecords() ?? [] as $record) {
            $skit = (array) $record->toArray();
            $where = sprintf('skit %s', strval($skit['id'] ?? $record->recordId));
            if (($skit[SkitBackdrop::SKIT_KEY] ?? null) !== null) {
                $problem = self::describeProblem($skit[SkitBackdrop::SKIT_KEY], $root, 'The skit\'s backdrop');
                if ($problem !== null) {
                    $issues[] = Issue::warning($where, $problem, 'Choose a project image for its Backdrop, or none to show the place\'s.');
                }
                continue;
            }
            $gate = trim(strval($skit['where'] ?? ''));
            $maps = $gate === '' ? $mapBindings : array_intersect_key($mapBindings, [$gate => true]);
            $bare = array_keys(array_filter($maps, static fn(array $binding): bool
                => ($binding[SkitBackdrop::MAP_KEY] ?? null) === null && SkitBackdrop::resolve($skit, $binding, $regions)->asset === null));
            if ($bare === []) { continue; }
            sort($bare);
            $listed = implode(', ', array_slice($bare, 0, self::LISTED_MAPS))
                . (count($bare) > self::LISTED_MAPS ? sprintf(' and %d more', count($bare) - self::LISTED_MAPS) : '');
            $issue = Issue::warning($where, $gate === ''
                ? sprintf('It can play on any map, and %d of them show it no backdrop: %s.', count($bare), $listed)
                : sprintf('It plays on map %s, which shows it no backdrop.', $gate),
                'Choose a Skit Backdrop on the map or its region (Regions page), or a Backdrop on the skit when its content needs one setting.');
            $issues = [...$issues, ...($gate === '' ? [$issue] : Issue::attributeToMap([$issue], $gate))];
        }

        return $issues;
    }

    /** Why a named backdrop cannot be drawn, or null when it can. */
    private static function describeProblem(mixed $asset, string $root, string $owner): ?string
    {
        try {
            $asset = SkitBackdrop::validateAsset($asset, $owner);
        } catch (InvalidArgumentException $error) {
            return $error->getMessage();
        }
        try {
            PngAssetPreflight::inspect($root, $asset);
        } catch (Throwable $error) {
            return sprintf('%s %s cannot be drawn: %s', $owner, $asset, $error->getMessage());
        }

        return null;
    }
}
