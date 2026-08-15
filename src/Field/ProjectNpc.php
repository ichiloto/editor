<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

/**
 * One map-local NPC, as the engine's `Field\Npc` will read it.
 *
 * The runtime contract, verbatim from `NpcManager::configure()`: `name`,
 * `x`, `y` required; `sprite` (default `@`); `movement` `fixed`|`wander`;
 * optional `wanderArea` {x,y,width,height}, unbounded when omitted;
 * `dialogue` (plain pages or conditional variants); `script` (event
 * commands, which take precedence over dialogue when non-empty);
 * `conditions` (visibility); `sets` (world writes after each conversation);
 * `id` (stable map-local identity, targeted by `move_route`); `sprites`
 * (directional glyphs, either case of key accepted on load).
 *
 * The payload is kept verbatim: a key this class does not know survives
 * load, edit, save, and reload untouched. Identity is immutable after
 * creation — nothing here renames an id, because doors and routes that name
 * it would not follow.
 *
 * @package Ichiloto\Editor\Field
 */
final class ProjectNpc
{
    /**
     * The fields the runtime reads, in the order a fresh entry is written.
     */
    public const array KNOWN_FIELDS = [
        'id', 'name', 'sprite', 'x', 'y', 'movement', 'wanderArea', 'sprites',
        'conditions', 'dialogue', 'script', 'sets',
    ];

    public const array MOVEMENTS = ['fixed', 'wander'];
    public const array DIRECTIONS = ['north', 'south', 'east', 'west'];

    /**
     * @param array<string, mixed> $payload The entry exactly as authored.
     */
    public function __construct(private array $payload)
    {
    }

    /**
     * Builds a fresh NPC at a tile.
     *
     * @param string $id The stable id, already unique on its map.
     * @param string $name The display name.
     * @param int $x The anchor column.
     * @param int $y The anchor row.
     * @return self The NPC.
     */
    public static function createAt(string $id, string $name, int $x, int $y): self
    {
        return new self([
            'id' => $id,
            'name' => $name,
            'sprite' => '@',
            'x' => $x,
            'y' => $y,
            'movement' => 'fixed',
            'dialogue' => [
                ['name' => $name, 'text' => 'Hello.'],
            ],
        ]);
    }

    /**
     * @return array<string, mixed> The entry exactly as it will be written.
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    public function getId(): ?string
    {
        $id = trim(strval($this->payload['id'] ?? ''));

        return $id === '' ? null : $id;
    }

    public function getName(): string
    {
        return strval($this->payload['name'] ?? '');
    }

    public function getSprite(): string
    {
        return strval($this->payload['sprite'] ?? '@');
    }

    public function getX(): int
    {
        return intval($this->payload['x'] ?? 0);
    }

    public function getY(): int
    {
        return intval($this->payload['y'] ?? 0);
    }

    public function getMovement(): string
    {
        return strval($this->payload['movement'] ?? 'fixed') === 'wander' ? 'wander' : 'fixed';
    }

    public function wanders(): bool
    {
        return $this->getMovement() === 'wander';
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}|null The bounds, or null when the NPC wanders freely.
     */
    public function getWanderArea(): ?array
    {
        $area = $this->payload['wanderArea'] ?? null;

        if (! is_array($area)) {
            return null;
        }

        return [
            'x' => intval($area['x'] ?? 0),
            'y' => intval($area['y'] ?? 0),
            'width' => intval($area['width'] ?? 1),
            'height' => intval($area['height'] ?? 1),
        ];
    }

    /**
     * @return array<string, string> The directional sprites, keyed by lower-case direction.
     */
    public function getDirectionalSprites(): array
    {
        $sprites = [];

        foreach ((array) ($this->payload['sprites'] ?? []) as $direction => $glyph) {
            // The runtime accepts NORTH or north; the editor speaks one case.
            $sprites[strtolower(strval($direction))] = strval($glyph);
        }

        return $sprites;
    }

    /**
     * @return array<int, array<string, mixed>> The visibility conditions.
     */
    public function getConditions(): array
    {
        return array_values(array_filter((array) ($this->payload['conditions'] ?? []), is_array(...)));
    }

    /**
     * @return array<int, array<string, mixed>> The dialogue, pages or variants.
     */
    public function getDialogue(): array
    {
        return array_values(array_filter((array) ($this->payload['dialogue'] ?? []), is_array(...)));
    }

    /**
     * Determines whether the dialogue is conditional variants rather than
     * plain pages — the runtime's own test, so the editor never disagrees
     * with the game about which it is looking at.
     *
     * @return bool True for variants.
     */
    public function hasDialogueVariants(): bool
    {
        foreach ($this->getDialogue() as $entry) {
            if (isset($entry['lines']) || isset($entry['script']) || isset($entry['conditions'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the dialogue as variants, plain pages folded into one.
     *
     * The runtime speaks either shape; the editor edits one. Plain pages
     * become a single variant with no conditions, and dialogueFromVariants()
     * folds that back into plain pages, so a file authored as pages is
     * saved as pages.
     *
     * @return array<int, array<string, mixed>> The variants.
     */
    public function getDialogueAsVariants(): array
    {
        $dialogue = $this->getDialogue();

        if ($dialogue === []) {
            return [];
        }

        if ($this->hasDialogueVariants()) {
            return $dialogue;
        }

        return [['lines' => $dialogue]];
    }

    /**
     * Returns the stored form of a variant list: plain pages when the list
     * is one unconditioned, write-less, script-less variant.
     *
     * @param array<int, mixed> $variants The variants.
     * @return array<int, mixed> The dialogue to store.
     */
    public static function dialogueFromVariants(array $variants): array
    {
        $variants = array_values(array_filter($variants, is_array(...)));

        if (count($variants) === 1) {
            $only = $variants[0];
            $extras = array_diff(array_keys($only), ['lines']);

            if ($extras === [] && is_array($only['lines'] ?? null)) {
                return array_values($only['lines']);
            }
        }

        return $variants;
    }

    /**
     * @return array<int, array<string, mixed>> The inline script commands.
     */
    public function getScript(): array
    {
        return array_values(array_filter((array) ($this->payload['script'] ?? []), is_array(...)));
    }

    /**
     * Determines whether the script will silence the dialogue at runtime.
     *
     * @return bool True when a non-empty script shadows non-empty dialogue.
     */
    public function scriptShadowsDialogue(): bool
    {
        return $this->getScript() !== [] && $this->getDialogue() !== [];
    }

    /**
     * @return array<int, array<string, mixed>> The completion writes.
     */
    public function getSets(): array
    {
        return array_values(array_filter((array) ($this->payload['sets'] ?? []), is_array(...)));
    }

    /**
     * Returns the keys this class does not know, so the pane can say they are
     * preserved rather than pretend they are not there.
     *
     * @return string[] The unknown keys.
     */
    public function getUnknownFields(): array
    {
        return array_values(array_diff(array_keys($this->payload), self::KNOWN_FIELDS));
    }

    /**
     * Returns a copy with one top-level value replaced, or removed on null.
     *
     * The id is not settable here: identity changes are a migration, not an
     * edit, and nothing that names the old id would follow a rename.
     *
     * @param string $key The payload key.
     * @param mixed $value The value; null removes the key.
     * @return self The rewritten NPC.
     */
    public function with(string $key, mixed $value): self
    {
        if ($key === 'id') {
            return $this;
        }

        $payload = $this->payload;

        if ($value === null) {
            unset($payload[$key]);
        } else {
            $payload[$key] = $value;
        }

        return new self($payload);
    }

    /**
     * Returns a copy at another tile.
     *
     * @param int $x The anchor column.
     * @param int $y The anchor row.
     * @return self The moved NPC.
     */
    public function movedTo(int $x, int $y): self
    {
        return $this->with('x', $x)->with('y', $y);
    }

    /**
     * Returns a copy under a new stable id — for duplication only, where the
     * copy is a new NPC rather than a renamed one.
     *
     * @param string $id The new id.
     * @return self The copy.
     */
    public function asCopyWithId(string $id): self
    {
        $payload = $this->payload;
        $payload['id'] = $id;

        // A fresh entry writes id first, like every entry the editor creates.
        return new self(['id' => $id] + $payload);
    }

    /**
     * Determines whether another NPC has identical persisted content.
     *
     * @param self $other The other NPC.
     * @return bool True when a save would write the same bytes.
     */
    public function equals(self $other): bool
    {
        return $this->payload === $other->payload;
    }
}
