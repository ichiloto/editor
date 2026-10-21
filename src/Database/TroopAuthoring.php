<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Engine\Battle\Presentation\BattleStageSlot;
use Ichiloto\Engine\Battle\Presentation\TerminalBattleEffects;
use Ichiloto\Engine\Core\Vector2;
use RuntimeException;

/**
 * Composing a troop's members, as RPG Maker's Troops tab does, for every
 * editor interface: add a member by choosing its enemy, remove one, clear
 * them all, and name the troop after them. Each operation applies its change
 * through the troops category's own record rules and returns a
 * {@see RecordChange} whose command redoes and undoes exactly that change,
 * so each is one undo step. Keys a member or troop carries that these do not
 * touch (battle events, a stable id, keys a later Engine adds) stay as they
 * were authored. A change that cannot be made is a {@see RecordRefusal}, and
 * nothing changed.
 *
 * @package Ichiloto\Editor\Database
 */
final readonly class TroopAuthoring
{
    /** The list a troop's members are held in. */
    public const string MEMBERS = 'enemies';

    public function __construct(private RecordAuthoring $records = new RecordAuthoring())
    {
    }

    /**
     * Adds a member fighting the enemy named, after the last one, standing
     * at a terminal position (the Engine's place for it beside the members
     * there, {@see \Ichiloto\Engine\Battle\Presentation\TerminalBattleEffects::findTroopPlace()})
     * and with no graphical placement, so the graphical battle arranges it
     * beside the placed members until it is placed on the formation canvas.
     *
     * @param list<string> $enemies The enemies the project has, by name.
     * @param array<string, list<string>> $sprites Each enemy's terminal sprite rows, by name ({@see EnemySprites}).
     * @throws RecordRefusal When the troops cannot be written, there is no such troop, or the project has no such enemy.
     */
    public function addMember(ProjectRecordDatabase $troops, int $index, string $enemy, array $enemies, array $sprites = []): RecordChange
    {
        $enemy = trim($enemy);
        if (! in_array($enemy, $enemies, true)) {
            throw new RecordRefusal($enemy === '' ? 'Choose the enemy the new member fights.' : sprintf('The project has no enemy named %s.', $enemy));
        }
        $list = $this->requireMembers($troops, $index);
        $entry = [...$list->blank, 'enemy' => $enemy, 'position' => $this->findTerminalPlace($troops, $index, $list->key, $sprites, $enemy)];
        $at = $troops->addSubItem($index, $entry, listKey: $list->key);
        $entry = $at === null ? null : ($troops->getSubItems($index, $list->key)[$at] ?? null);
        if ($at === null || ! is_array($entry)) {
            throw new RecordRefusal(sprintf('A member cannot be added to %s.', $this->describeTroop($troops, $index)));
        }
        try {
            $troops->assertSourceAccepts($index);
        } catch (SourcePreservationRefusal|SourceIdentityConflict|RuntimeException $refused) {
            $troops->removeSubItem($index, $at, $list->key);

            throw new RecordRefusal($refused->getMessage(), previous: $refused);
        }

        return new RecordChange(new GenericCommand(
            sprintf('Add %s', $enemy),
            static fn() => $troops->insertSubItem($index, $at, $entry, $list->key),
            static fn() => $troops->removeSubItem($index, $at, $list->key),
        ), $at);
    }

    /**
     * Where the Engine stands a new member fighting an enemy on the troop's
     * terminal field, beside the members there with readable positions, by
     * their sprites as the terminal battle draws them.
     *
     * @param array<string, list<string>> $sprites
     * @return list<int>
     */
    private function findTerminalPlace(ProjectRecordDatabase $troops, int $index, string $listKey, array $sprites, string $enemy): array
    {
        $members = [];
        foreach ($troops->getSubItems($index, $listKey) as $member) {
            $position = is_array($member) && is_array($member['position'] ?? null) ? array_values($member['position']) : [];
            if (is_int($position[0] ?? null) && is_int($position[1] ?? null)) {
                $members[] = ['position' => new Vector2($position[0], $position[1]), 'sprite' => $sprites[trim(strval($member['enemy'] ?? ''))] ?? []];
            }
        }
        $place = new TerminalBattleEffects()->findTroopPlace($members, $sprites[$enemy] ?? []);

        return [(int) $place->x, (int) $place->y];
    }

    /**
     * Removes one member with everything it carries.
     *
     * @throws RecordRefusal When the troops cannot be written, or there is no such troop or member.
     */
    public function removeMember(ProjectRecordDatabase $troops, int $index, int $member): RecordChange
    {
        $list = $this->requireMembers($troops, $index);
        if (! array_key_exists($member, $troops->getSubItems($index, $list->key))) {
            throw new RecordRefusal(sprintf('%s has no member %d.', $this->describeTroop($troops, $index), $member + 1));
        }

        return $this->records->removeEntry($troops, $index, [], $member, $list->key);
    }

    /**
     * Removes every member at once; nothing changes for a troop with none.
     *
     * @throws RecordRefusal When the troops cannot be written, or there is no such troop.
     */
    public function clearMembers(ProjectRecordDatabase $troops, int $index): RecordChange
    {
        $list = $this->requireMembers($troops, $index);
        $record = $troops->getRecordByIndex($index);
        if ($record === null || $troops->getSubItems($index, $list->key) === []) {
            return new RecordChange(null, $index);
        }
        $before = $record->toArray();
        // A page that still names a member keeps the troop's members.
        TroopEventPageContract::remapMembers((array) $before, static fn(int $member): ?int => null);
        $record->setEntries($list, []);
        $after = $record->toArray();
        try {
            $troops->assertSourceAccepts($index);
        } catch (SourcePreservationRefusal|SourceIdentityConflict|RuntimeException $refused) {
            $record->restorePayload($before);

            throw new RecordRefusal($refused->getMessage(), previous: $refused);
        }

        return new RecordChange(new GenericCommand(
            'Clear members',
            static fn() => $record->restorePayload($after),
            static fn() => $record->restorePayload($before),
        ), $index);
    }

    /**
     * Places every member of a troop in its graphical formation at once, as
     * one step: each member gets the place given, as the Engine's stage
     * place array, or none (null), which the battle then arranges beside
     * the others. Align, arranging automatically and dragging one member of
     * a troop the battle arranges all place every member together, so no
     * member moves except the one the author moved. The Engine's place rules
     * decide what is accepted.
     *
     * @param list<?array<string, int|float>> $places One per member, in member order.
     * @throws RecordRefusal When the troops cannot be written, there is no such troop, the count differs or a place is refused.
     */
    public function placeMembers(ProjectRecordDatabase $troops, int $index, array $places, string $label): RecordChange
    {
        $list = $this->requireMembers($troops, $index);
        $record = $troops->getRecordByIndex($index);
        $members = $troops->getSubItems($index, $list->key);
        if (! array_is_list($places) || count($places) !== count($members)) {
            throw new RecordRefusal(sprintf('A troop of %d members needs %d places, one each.', count($members), count($members)));
        }
        $placed = [];
        foreach ($members as $member => $entry) {
            $entry = is_array($entry) ? $entry : [];
            unset($entry['graphicalPlacement']);
            if ($places[$member] !== null) {
                try {
                    $place = BattleStageSlot::fromArray($places[$member], sprintf('member %d\'s battle placement', $member + 1))->toArray();
                    // Whole canvas units stay integers, as an author writes them; the rest keep two decimals.
                    $entry['graphicalPlacement'] = array_map(static fn(float $value): int|float => round($value, 2) === floor($value)
                        ? (int) $value : round($value, 2), $place);
                } catch (\InvalidArgumentException $refused) {
                    throw new RecordRefusal($refused->getMessage(), previous: $refused);
                }
            }
            $placed[] = $entry;
        }
        if ($placed === $members) {
            return new RecordChange(null, $index);
        }
        $before = $record->toArray();
        $record->setEntries($list, $placed);
        $after = $record->toArray();
        try {
            $troops->assertSourceAccepts($index);
        } catch (SourcePreservationRefusal|SourceIdentityConflict|RuntimeException $refused) {
            $record->restorePayload($before);

            throw new RecordRefusal($refused->getMessage(), previous: $refused);
        }

        return new RecordChange(new GenericCommand(
            $label,
            static fn() => $record->restorePayload($after),
            static fn() => $record->restorePayload($before),
        ), $index);
    }

    /**
     * Names the troop after its members, through its name row's own rules.
     * The name another troop already has gets a number after it, since a
     * troop is known by its name.
     *
     * @throws RecordRefusal When the troops cannot be written, there is no such troop, or it has no member to be named after.
     */
    public function nameAfterMembers(ProjectRecordDatabase $troops, int $index): RecordChange
    {
        $list = $this->requireMembers($troops, $index);
        $enemies = array_map(static fn(array $member): string => trim(strval($member['enemy'] ?? '')), $troops->getSubItems($index, $list->key));
        $name = self::describeMembers($enemies);
        if ($name === '') {
            throw new RecordRefusal('Add a member first; the troop is named after its members.');
        }
        $taken = [];
        foreach ($troops->getRecords() as $position => $record) {
            if ($position !== $index) {
                $taken[] = strtolower(trim(strval($record->get($troops->schema->labelKey))));
            }
        }
        $unique = $name;
        for ($number = 2; in_array(strtolower($unique), $taken, true); $number++) {
            $unique = sprintf('%s %d', $name, $number);
        }

        return $this->records->applyField($troops, $index, [], $troops->schema->labelKey, $unique, 'Auto-name');
    }

    /**
     * What a troop of these enemies is called: each enemy once, in the order
     * it first appears, with how many there are when more than one.
     *
     * @param list<string> $enemies The members' enemies, in order.
     */
    public static function describeMembers(array $enemies): string
    {
        $counts = [];
        foreach ($enemies as $enemy) {
            if ($enemy !== '') {
                $counts[$enemy] = ($counts[$enemy] ?? 0) + 1;
            }
        }
        $parts = [];
        foreach ($counts as $enemy => $count) {
            $parts[] = $count > 1 ? sprintf('%s x %d', $enemy, $count) : $enemy;
        }

        return implode(', ', $parts);
    }

    /** @throws RecordRefusal When the troops cannot be written, or there is no such troop. */
    private function requireMembers(ProjectRecordDatabase $troops, int $index): RecordSubList
    {
        if (! $troops->isEditable()) {
            throw new RecordRefusal(sprintf('Read-only: %s.', $troops->getReadOnlyReason() ?? 'the troops cannot be written'));
        }
        $troops->getRecordByIndex($index) ?? throw new RecordRefusal(sprintf('troops has no record %d.', $index));

        return $troops->schema->findInlineSubList(self::MEMBERS)
            ?? throw new RecordRefusal('Troops have no members list to compose.');
    }

    private function describeTroop(ProjectRecordDatabase $troops, int $index): string
    {
        $record = $troops->getRecordByIndex($index);

        return $record === null ? sprintf('Troop %d', $index + 1) : $troops->getEntryLabel($record);
    }
}
