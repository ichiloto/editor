# Ichiloto Editor Guides

Task-oriented walkthroughs for the Ichiloto editor. For the full panel-by-panel
reference, see [the manual](../manual.md).

## Start Here

Read these in order the first time:

1. [Make a New Map](make-a-new-map.md) — create a map, paint it, save it.
2. [Add an NPC](add-an-npc.md) — place an interactive character with dialogue.
3. [Wire a Quest](wire-a-quest.md) — author a quest and connect it to the world.
4. [Author a Cutscene](author-a-cutscene.md) — build an event command list.
5. [Write a Skit](write-a-skit.md) — add optional party banter.

## What The Editor Can Do Today

- Create, paint, duplicate, and delete maps, with full undo.
- Place and configure the five event types: Dialogue, Transfer Player, Shop,
  Sleep, and Chest.
- Author actors, classes, skills, quests, animations, states, troops, skits,
  event scripts, terms, and system settings.
- Browse items, weapons, armors, and enemies.
- Playtest any map from the cursor without touching your project.

## What Still Happens Outside The Editor

Current limits worth knowing before you plan a session:

- **Wandering NPCs.** The `npcs` array in a map's `.data.php` file — the
  characters that walk a patrol area — is authored by hand. The editor places
  *event* characters, which are stationary and interactive.
- **Script event triggers.** The editor authors event command lists in the
  Database, but a map marker cannot yet be set to *run* one; wire the
  `scriptId` into the map's `.data.php` by hand.
- **Items, weapons, armors, enemies.** These files are PHP constructor calls,
  so the editor browses them and refuses to rewrite them. See the manual's
  [category table](../manual.md#editable-and-read-only-categories).
- **Nested event arms.** A `choice` command's options and a `branch` command's
  `then`/`else` round-trip safely but are edited by hand.

## Recommended Build Loop

1. Paint the map's shape first, before placing anything on it.
2. Save with `Ctrl+S` — the playtest reads the file on disk.
3. Playtest with `Ctrl+T` from the tile you care about.
4. Adjust, and repeat.

Keep `Ctrl+A` (Save All) in mind whenever you have been editing both a map and
a Database category — one press covers everything.

## A Few Important Rules To Remember

- Nothing is written to your project until you save. The header shows `*` when
  something is unsaved.
- `Ctrl+Z` undoes everything the editor considers an edit, including Database
  field changes and entry deletions. It does not undo map create, duplicate, or
  delete — those are filesystem operations and confirm first.
- `Esc` always backs out exactly one level.
- A destructive confirmation defaults to Cancel. `Enter` cancels; only an
  explicit `y` proceeds.
