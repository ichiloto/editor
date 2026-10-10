<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Battle\Presentation\BattleFormationLayout;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlerSlot;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Checks the graphical battle formation by the Engine's own clearance
 * check: the starting party in its slots, then every placed troop beside
 * it, over the default arena. A battler whose visible art leaves the
 * layout's battler or enemy area, overlaps another, or leaves no room for
 * its target cursor is reported, never moved. The battle still runs, so
 * these are warnings; a formation the Engine cannot compose at all is an
 * error. Terminal battles are unaffected.
 */
final class TroopFormationValidator
{
    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $assets = $workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets';
        $layoutFile = 'assets/' . BattlePresentationCatalog::FILE;
        try {
            $catalog = BattlePresentationCatalog::load($assets);
        } catch (Throwable $problem) {
            return [Issue::error($layoutFile, sprintf('The battle presentation cannot be read, so no formation can be checked: %s', $problem->getMessage()),
                sprintf('Correct %s.', $layoutFile))];
        }
        if ($catalog?->ui === null) {
            return [];
        }

        $names = [];
        foreach ($workspace->actorDatabase->getActors() as $actor) {
            $names[$actor->getDefinitionId()] = $actor->getName();
        }
        $starting = $workspace->getSystemField('startingParty');
        $party = array_slice(array_values(array_filter(is_array($starting) ? $starting : [], is_string(...))), 0, count($catalog->ui->partySlots));

        try {
            $alone = BattleFormationLayout::compose($catalog, null, [], $party, $assets)->getClearanceDiagnostics($assets);
        } catch (InvalidArgumentException|RuntimeException $problem) {
            return [Issue::error($layoutFile, sprintf('The starting party cannot be stood in its battle slots: %s', $problem->getMessage()),
                'Correct the party slots in the battle layout, or the actors\' battle art.')];
        }
        $issues = [];
        foreach ($alone['party'] as $position => $diagnostics) {
            foreach ($diagnostics as $diagnostic) {
                $issues[] = Issue::warning(sprintf('%s: party slot %d', $layoutFile, $position + 1),
                    sprintf('%s: %s', $names[$party[$position]] ?? $party[$position], $diagnostic),
                    'Move the party slot in the battle layout, or adjust the actor\'s battle art size or ground point.');
            }
        }

        foreach ($workspace->getRecordDatabase('troops')?->getRecords() ?? [] as $record) {
            $troop = strval($record->get('name'));
            $where = sprintf('assets/Data/troops.php: %s', $troop);
            $placed = $members = [];
            foreach ($record->getSubList('enemies') as $index => $entry) {
                if (!is_array($entry) || !is_array($entry['graphicalPlacement'] ?? null)) {
                    continue;
                }
                try {
                    // A malformed placement is the troop schema's to report.
                    $placed[] = ['enemyId' => strval($entry['enemy'] ?? ''), 'slot' => BattlerSlot::fromArray($entry['graphicalPlacement'])];
                    $members[] = $index;
                } catch (InvalidArgumentException) {
                }
            }
            if ($placed === []) {
                continue;
            }
            try {
                $clearance = BattleFormationLayout::compose($catalog, null, $placed, $party, $assets)->getClearanceDiagnostics($assets);
            } catch (InvalidArgumentException|RuntimeException $problem) {
                $issues[] = Issue::error($where, sprintf('Its formation cannot be composed: %s', $problem->getMessage()),
                    'Move its members on the troop\'s formation canvas until each stands on the battle canvas.');
                continue;
            }
            foreach ($clearance['enemies'] as $position => $diagnostics) {
                foreach ($diagnostics as $diagnostic) {
                    $issues[] = Issue::warning($where,
                        sprintf('%s (member %d): %s', $placed[$position]['enemyId'], $members[$position] + 1, $diagnostic),
                        'Move it on the troop\'s formation canvas, or adjust its battle art size or ground point.');
                }
            }
        }

        return $issues;
    }
}
