<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Database\InventoryCatalog;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Field\Validation\MapCompletenessValidator;
use Ichiloto\Engine\Field\Validation\MapTerrainKindRule;
use Ichiloto\Engine\Field\Validation\MapValidationIssue;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Throwable;

/**
 * Checks a map against the Engine's completeness rule, the one every tool
 * and the game share ({@see MapCompletenessValidator}): its events and their
 * subjects, its chests' rewards, the gameplay glyphs it would show raw in the
 * graphical field, and its world objects' and NPCs' field art. The Editor
 * keeps no copy of these rules; this adapter only supplies the map as it
 * stands in the workspace, unsaved edits included, and words the findings
 * for an author.
 *
 * Every finding is an error, a defect the player would meet. The map still
 * saves as a draft; validation fails until it is fixed.
 */
final class MapCompletenessCheck
{
    /** How many cells a finding names before summing up the rest. */
    private const int CELLS_NAMED = 3;

    private function __construct()
    {
    }

    /**
     * @param InventoryCatalog|null $inventory The project's inventory, for what its chests give; null when it could
     *     not be read, which project validation reports itself.
     * @return list<Issue> The errors found, each naming the map.
     */
    public static function validate(ProjectMap $map, ?InventoryCatalog $inventory): array
    {
        $resolve = $inventory instanceof InventoryCatalog
            ? new ChestRewardValidator($inventory)->resolveInventoryReference(...)
            : static fn(string $reference): ?string => null;
        try {
            $found = MapCompletenessValidator::validate(
                $map->mapId,
                $map->getEditableData(),
                $map->getEventLayerCells(),
                $map->getLayerSet(),
                MapGraphicsValidator::readGraphics($map),
                $map->getAssetRoot(),
                $resolve,
                // The resources as this session holds them: one added in the same change set resolves.
                self::readFieldCatalog($map),
                requiresGraphics: $map->listTilesetIds() !== [],
            );
            if (array_any($map->getEditableData()['events'] ?? [], static fn($event): bool => is_array($event)
                && is_a($event['class'] ?? '', \Ichiloto\Engine\Events\Triggers\PassageEventTrigger::class, true))) {
                $found = [...$found, ...MapCompletenessValidator::validateOpeningFloors($map->mapId,
                    $map->getEditableData(), $map->getEventLayerCells(), $map->getPhysicalOccupancy()->collisionGrid)];
            }
        } catch (Throwable $failure) {
            // The map's own reading checks say why it cannot be read; nothing is judged as complete.
            return [new Issue(Severity::ERROR, $map->mapId, 'Its completeness could not be checked: ' . $failure->getMessage(),
                'Repair what the other findings on this map name, then validate again.', 'map.unreadable', $map->mapId)];
        }
        if ($map->getMapDataField(['tileset']) !== null) {
            // A kind that cannot be read is reported with its reason by MapGraphicsValidator::validate().
            $found = array_values(array_filter($found, static fn(MapValidationIssue $issue): bool => $issue->rule !== 'graphics.missing'));
        }
        if ($inventory === null) {
            $found = array_values(array_filter($found, static fn(MapValidationIssue $issue): bool => $issue->rule !== ChestRewardValidator::CODE));
        }

        return self::describeIssues($map, $found);
    }

    /**
     * The field presentation the map's world objects name resources from, as
     * its workspace holds it; null when it names none, so a map of inline
     * objects never depends on the catalogue.
     */
    public static function readFieldCatalog(ProjectMap $map): ?FieldPresentationCatalog
    {
        $objects = $map->getEditableData()['worldObjects'] ?? [];

        return is_array($objects) && array_any($objects, static fn(mixed $entry): bool => is_array($entry) && array_key_exists('resource', $entry))
            ? $map->getFieldCatalog() : null;
    }

    /**
     * Words the Engine's findings for an author: one issue per finding, the
     * findings that differ only by cell joined into one that names its cells.
     *
     * @param list<MapValidationIssue> $found
     * @return list<Issue>
     */
    private static function describeIssues(ProjectMap $map, array $found): array
    {
        $groups = [];
        foreach ($found as $issue) {
            $key = implode("\0", [$issue->rule, $issue->message, $issue->marker ?? '', json_encode($issue->path), $issue->hint]);
            $groups[$key] ??= ['issue' => $issue, 'cells' => []];
            array_push($groups[$key]['cells'], ...$issue->cells);
        }

        $issues = [];
        foreach ($groups as ['issue' => $issue, 'cells' => $cells]) {
            $named = array_map(static fn(array $cell): string => "({$cell[0]}, {$cell[1]})", array_slice($cells, 0, self::CELLS_NAMED));
            $rest = count($cells) - count($named);
            $message = $cells === [] ? $issue->message : sprintf('%s. At %s%s%s.', rtrim($issue->message, '.'),
                count($cells) === 1 ? '' : sprintf('%d cells: ', count($cells)), implode(', ', $named), $rest > 0 ? " and {$rest} more" : '');
            $where = $issue->marker === null ? $map->mapId : sprintf('%s event %s', $map->mapId, $issue->marker);
            $issues[] = new Issue(self::isWarning($issue) ? Severity::WARNING : Severity::ERROR, $where, $message,
                self::describeHint($map, $issue), $issue->rule, $map->mapId, array_map(static fn(array $cell): array => [$cell[0], $cell[1]], $cells));
        }

        return $issues;
    }

    /**
     * Findings an author weighs rather than errors the player meets: a glyph
     * and tiles showing different terrain kinds, and cells of a kind whose
     * art is still to be drawn. The map plays, saves and loads unchanged.
     */
    private static function isWarning(MapValidationIssue $issue): bool
    {
        return in_array($issue->rule, [MapTerrainKindRule::DISAGREEMENT, MapTerrainKindRule::AWAITING_ART], true);
    }

    /** The fix, in the author's terms where the Editor knows a better one than the Engine's. */
    private static function describeHint(ProjectMap $map, MapValidationIssue $issue): string
    {
        return match (true) {
            $issue->rule === 'graphics.missing'
                => sprintf('Set its Kind in the Inspector, one of the tilesets in assets/%s.', Tileset::DIRECTORY),
            $issue->rule === 'graphics.glyph'
                => 'Draw its tiles (T in the terminal editor, layer.drawTiles through the session), give the object it belongs to its art, or remove the glyph if it is obsolete.',
            default => $issue->hint,
        };
    }
}
