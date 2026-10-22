<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Database\EnemySprites;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Battle\Presentation\BattleFormationLayout;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattleStageSlot;
use Ichiloto\Engine\Battle\Presentation\TerminalBattleEffects;
use Ichiloto\Engine\Core\Vector2;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Checks the graphical battle formation by the Engine's own clearance
 * check: the starting party in its slots, then every troop beside it as the
 * battle stands it (placed members where placed, the rest arranged), over
 * the default arena's ground. A battler whose visible art leaves the
 * layout's battler or enemy area, stands on the same ground as another, or
 * leaves no room for its target cursor is reported, never moved. The battle
 * still runs, so these are warnings; a placement the Engine cannot read or a
 * formation it cannot compose is an error. Each member's terminal position is
 * checked separately, by the terminal battle's own troop zone.
 */
final class TroopFormationValidator
{
    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        return [...$this->validateTerminal($workspace), ...$this->validateGraphical($workspace)];
    }

    /**
     * Warns of a member whose terminal position leaves out its target badge,
     * its focus marker, its step towards the party or the field's bottom.
     *
     * @return Issue[]
     */
    private function validateTerminal(ProjectWorkspace $workspace): array
    {
        $troops = $workspace->getRecordDatabase('troops')?->getRecords() ?? [];
        if ($troops === []) {
            return [];
        }
        $sprites = new EnemySprites($workspace->projectRoot)->readByEnemy($workspace->getRecordDatabase('enemies'));
        $field = new TerminalBattleEffects();
        $issues = [];
        foreach ($troops as $record) {
            $where = sprintf('assets/Data/troops.php: %s', strval($record->get('name')));
            foreach ($record->getSubList('enemies') as $index => $entry) {
                $enemy = strval($entry['enemy'] ?? '');
                $position = is_array($entry['position'] ?? null) ? array_values($entry['position']) : [];
                // An unreadable sprite is reported where enemies are checked.
                if (($sprites[$enemy] ?? []) === [] || !is_int($position[0] ?? null) || !is_int($position[1] ?? null)) {
                    continue;
                }
                foreach ($field->getTroopPlaceProblems(new Vector2($position[0], $position[1]), $sprites[$enemy]) as $problem) {
                    $issues[] = Issue::warning($where, sprintf('%s (member %d) on the terminal field %s.', $enemy, $index + 1, $problem),
                        'Move it on the troop\'s terminal field.');
                }
            }
        }

        return $issues;
    }

    /** @return Issue[] */
    private function validateGraphical(ProjectWorkspace $workspace): array
    {
        $assets = $workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets';
        $layoutFile = 'assets/' . BattlePresentationCatalog::FILE;
        try {
            $catalog = $workspace->catalogs->loadBattlePresentation();
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
        $starting = array_values(array_filter(is_array($starting) ? $starting : [], is_string(...)));
        // A theme formation holds so many; without one the battle arranges the whole party.
        $party = $catalog->ui->partySlots === [] ? $starting : array_slice($starting, 0, count($catalog->ui->partySlots));

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
            // A member without a placement is arranged by the battle beside the others.
            $placed = [];
            $readable = true;
            foreach ($record->getSubList('enemies') as $index => $entry) {
                $slot = null;
                if (is_array($entry['graphicalPlacement'] ?? null)) {
                    try {
                        $slot = BattleStageSlot::fromArray($entry['graphicalPlacement']);
                    } catch (InvalidArgumentException $problem) {
                        // The battle refuses the troop's graphics while any placement is unreadable.
                        $issues[] = Issue::error($where, sprintf('%s (member %d): %s', strval($entry['enemy'] ?? ''), $index + 1, $problem->getMessage()),
                            'Align the troop, arrange it automatically, or drag the member on the troop\'s formation canvas.');
                        $readable = false;
                    }
                }
                $placed[] = ['enemyId' => strval($entry['enemy'] ?? ''), 'slot' => $slot];
            }
            if ($placed === [] || !$readable) {
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
                        sprintf('%s (member %d): %s', $placed[$position]['enemyId'], $position + 1, $diagnostic),
                        'Move it on the troop\'s formation canvas, or adjust its battle art size or ground point.');
                }
            }
        }

        return $issues;
    }
}
