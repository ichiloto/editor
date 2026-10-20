<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\History\GenericCommand;
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
     * Adds a member fighting the enemy named, after the last one. The
     * member starts at the members list's blank terminal position and with
     * no graphical placement, which it is given on the formation canvas.
     *
     * @param list<string> $enemies The enemies the project has, by name.
     * @throws RecordRefusal When the troops cannot be written, there is no such troop, or the project has no such enemy.
     */
    public function addMember(ProjectRecordDatabase $troops, int $index, string $enemy, array $enemies): RecordChange
    {
        $enemy = trim($enemy);
        if (! in_array($enemy, $enemies, true)) {
            throw new RecordRefusal($enemy === '' ? 'Choose the enemy the new member fights.' : sprintf('The project has no enemy named %s.', $enemy));
        }
        $list = $this->requireMembers($troops, $index);
        $at = $troops->addSubItem($index, [...$list->blank, 'enemy' => $enemy], listKey: $list->key);
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
