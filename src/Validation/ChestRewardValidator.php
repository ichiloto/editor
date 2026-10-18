<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Database\InventoryCatalog;
use Ichiloto\Engine\Field\Validation\MapCompletenessValidator;
use Ichiloto\Engine\Field\Validation\MapValidationIssue;

/**
 * Checks that a chest can give what it holds, by the Engine's own rule.
 *
 * The rule is `MapCompletenessValidator::validateChestReward()`, the same
 * check the Engine's chest runs before it opens, so the Editor never keeps a
 * second copy of it. This adapter supplies what the Engine asks of an
 * authoring tool: the project's inventory identity, from the Editor's
 * catalogue of the current records, and where each finding belongs. Every
 * finding is an error, because the chest fails when opened; an unfinished
 * chest is still saved as the author left it. One adapter serves the project
 * report, the inspectors of both editors and the map save warnings, so they
 * always agree.
 *
 * @package Ichiloto\Editor\Validation
 */
final readonly class ChestRewardValidator
{
    /** The Engine's rule identity for a chest reward finding, carried as the issue code. */
    public const string CODE = 'chest.reward';

    /**
     * @param InventoryCatalog $inventory What an inventory reference means in the project, by the Engine's identity rules.
     */
    public function __construct(private InventoryCatalog $inventory)
    {
    }

    /**
     * Checks a chest's data.
     *
     * @param string $mapId The map the chest is on.
     * @param string $marker The chest event's marker.
     * @param array<string, mixed> $data The chest event's `data`, as currently authored.
     * @return Issue[] The issues found.
     */
    public function validate(string $mapId, string $marker, array $data): array
    {
        $where = sprintf('%s event %s', $mapId, $marker);

        return array_map(
            static fn(MapValidationIssue $issue): Issue => Issue::error($where, $issue->message, $issue->hint, $issue->rule),
            MapCompletenessValidator::validateChestReward(
                $data,
                $mapId,
                $this->resolveInventoryReference(...),
                $marker,
                ['events', $marker, 'data'],
            ),
        );
    }

    /**
     * Resolves an inventory reference for the Engine's check: the stable id,
     * null when nothing answers to it, and a failure when more than one does.
     */
    private function resolveInventoryReference(string $reference): ?string
    {
        return $this->inventory->isAmbiguous($reference)
            ? $this->inventory->requireDefinitionId($reference, 'validating chest reward')
            : $this->inventory->definitionIdFor($reference);
    }
}
