<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryBranchKind;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeDefinition;
use Ichiloto\Engine\Progression\Mastery\MasteryTree;
use InvalidArgumentException;

/**
 * Checks the mastery tree and the characters' maps as the Engine reads them,
 * and reports what still awaits an author: skill records whose values are
 * unassigned, tree nodes nothing prices yet, foundations that grant no skill
 * yet, a ladder step the tree prices or opens below the step it builds on,
 * a region, trunk or branch without the summary its Info shows, and a
 * character's own terms that leave out a node's chain step. A
 * tree or map the Engine cannot read is an error; the rest are warnings,
 * since the game runs and simply offers nobody those nodes.
 */
final class MasteryValidator
{
    public const string UNASSIGNED = 'skill.unassigned';
    public const string UNPRICED = 'mastery.unpriced';
    public const string UNGRANTED = 'mastery.foundation-ungranted';
    public const string UNCHAINED = 'mastery.unchained';
    public const string LADDER = 'mastery.ladder';
    public const string UNSUMMARISED = 'mastery.summary-missing';

    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $skills = $workspace->loadSkillCatalog();
        $catalog = MasteryCatalog::load($workspace->projectRoot . '/assets', $skills);
        $issues = [
            // The catalogue names the settings file from assets/, and a map by its file in the maps folder.
            ...array_map(static fn(string $problem): Issue => Issue::error(
                str_starts_with($problem, MasteryCatalog::SETTINGS) ? 'assets/' . MasteryCatalog::SETTINGS : sprintf('assets/%s/%s', MasteryCatalog::DIRECTORY, strstr($problem, ':', true)),
                ltrim((string) strstr($problem, ':'), ': '),
                'Correct it in Database > Mastery Regions, Mastery Branches or Mastery Maps; until then the game reads no Mastery from it.'),
                $catalog->problems),
            ...$this->checkDrafts($skills),
        ];
        try {
            $elements = $workspace->getElementIdentities();
        } catch (InvalidArgumentException) {
            // The elements' own check reports them.
            $elements = null;
        }
        $tree = $catalog->tree;
        foreach ($tree->branches as $branch) {
            $where = sprintf('%s: branch %s', 'assets/' . MasteryCatalog::SETTINGS, $branch->id);
            if ($branch->kind === MasteryBranchKind::ELEMENT && $elements !== null && ! in_array($branch->element, $elements, true)) {
                $issues[] = Issue::error($where, sprintf('Branch %s practises element "%s", which the project does not have.', $branch->name, $branch->element),
                    'Choose one of the project\'s elements (Database > Types) on the branch.');
            }
            $unpriced = array_values(array_filter($branch->nodes, static fn($node): bool => $node->cost === null));
            if ($unpriced !== []) {
                $issues[] = new Issue(Severity::WARNING, $where, sprintf('%s has no price yet for %s: only characters whose own map prices them can learn them.',
                    $branch->name, implode(', ', array_map(static fn($node): string => $node->label, $unpriced))),
                    'Price them on the branch (Database > Mastery Branches), or in a character\'s own terms.', self::UNPRICED);
            }
            $foundation = $branch->findFoundation();
            if ($foundation !== null && $foundation->skill === null) {
                $issues[] = new Issue(Severity::WARNING, $where, sprintf('The foundation %s grants no skill yet; learning it opens its branch and nothing more.', $foundation->label),
                    'Choose the skill it grants once its author defines one.', self::UNGRANTED);
            }
            $issues = [...$issues, ...$this->checkLadder($tree, $branch->nodes, $where)];
            if (trim($branch->description) === '') {
                $issues[] = new Issue(Severity::WARNING, $where, sprintf('%s has no summary: Info offers nothing on its family or its foundation.', $branch->name),
                    'Write its Summary in Database > Mastery Branches.', self::UNSUMMARISED);
            }
        }
        foreach ($tree->regions as $region) {
            $regionWhere = sprintf('assets/%s: region %s', MasteryCatalog::SETTINGS, $region->id);
            if (trim($region->description) === '') {
                $issues[] = new Issue(Severity::WARNING, $regionWhere, sprintf('%s has no summary: Info offers nothing on it.', $region->name),
                    'Write its Summary in Database > Mastery Regions.', self::UNSUMMARISED);
            }
            if ($region->nodes !== [] && trim($region->trunkDescription) === '') {
                $issues[] = new Issue(Severity::WARNING, $regionWhere, sprintf('The %s trunk has no summary: Info offers nothing on the family its own nodes form.', $region->name),
                    'Write its Trunk Summary in Database > Mastery Regions.', self::UNSUMMARISED);
            }
            $unpriced = array_values(array_filter($region->nodes, static fn($node): bool => $node->cost === null));
            if ($unpriced !== []) {
                $issues[] = new Issue(Severity::WARNING, sprintf('assets/%s: region %s', MasteryCatalog::SETTINGS, $region->id),
                    sprintf('The %s trunk has no price yet for %s: only characters whose own map prices them can learn them.', $region->name,
                        implode(', ', array_map(static fn($node): string => $node->label, $unpriced))),
                    'Price them on the region (Database > Mastery Regions), or in a character\'s own terms.', self::UNPRICED);
            }
        }
        foreach ($catalog->getMaps() as $actor => $map) {
            foreach ($map->findUnchainedTerms() as $id => $step) {
                $node = $tree->nodes[$id];
                $branch = $tree->branches[$node->branch ?? ''] ?? null;
                $issues[] = new Issue(Severity::WARNING, sprintf('assets/%s: %s', MasteryCatalog::DIRECTORY, $actor),
                    sprintf('%s learns %s without %s, the step before it in %s.', $actor, $node->label, $tree->nodes[$step]->label, $branch?->name ?? $node->region),
                    sprintf('Make %s require %s in %s\'s own terms (Database > Mastery Maps), or leave it if %s starts there.', $node->label,
                        $tree->nodes[$step]->label, $actor, $actor), self::UNCHAINED);
            }
        }

        return $issues;
    }

    /**
     * One warning per node whose terms on the tree undercut the step it
     * builds on: a higher rung costs at least as much, and opens no earlier,
     * than the rung below it. Characters' own terms may differ.
     *
     * @param list<MasteryNodeDefinition> $nodes
     * @return Issue[]
     */
    private function checkLadder(MasteryTree $tree, array $nodes, string $where): array
    {
        $issues = [];
        foreach ($nodes as $node) {
            $step = $tree->findChainPredecessor($node);
            if ($step === null || $node->cost === null || $step->cost === null) {
                continue;
            }
            $level = $node->opensAtLevel ?? 1;
            $stepLevel = $step->opensAtLevel ?? 1;
            if ($node->cost < $step->cost || $level < $stepLevel) {
                $issues[] = new Issue(Severity::WARNING, $where, sprintf('%s (cost %d, level %d) costs less or opens earlier than %s (cost %d, level %d), the step it builds on.',
                    $node->label, $node->cost, $level, $step->label, $step->cost, $stepLevel),
                    sprintf('Give %s a cost and opening level at or above %s\'s (Database > Mastery Branches).', $node->label, $step->label), self::LADDER);
            }
        }

        return $issues;
    }

    /** @return Issue[] One warning per skill record still awaiting its author's values. */
    private function checkDrafts(SkillCatalog $skills): array
    {
        $issues = [];
        foreach ($skills->getDrafts() as $name => $draft) {
            $issues[] = new Issue(Severity::WARNING, 'assets/Data/' . ($skills->getSourceFile($name) ?? SkillCatalog::DIRECTORY),
                sprintf('%s awaits author values (%s); nobody can learn, cast or be given it until they are assigned.', $name, implode(', ', $draft->unassigned)),
                'Assign the values in Database > Skills and clear Awaiting Author.', self::UNASSIGNED);
        }

        return $issues;
    }
}
