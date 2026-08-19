# Ichiloto Editor Guides

Task-oriented walkthroughs for the Ichiloto editor. For the full panel-by-panel
reference, see [the manual](../manual.md).

## Start Here

Read these in order the first time:

1. [Make a New Map](make-a-new-map.md) — create a map, paint it, save it.
2. [Add an NPC](add-an-npc.md) — place a character that talks, wanders, and
   answers to the state of the world; or a stationary event character.
3. [Wire a Quest](wire-a-quest.md) — author a quest and connect it to the world.
4. [Author a Cutscene](author-a-cutscene.md) — build a Common Event: a
   reusable event command list.
5. [Write a Skit](write-a-skit.md) — add optional party banter.
6. [Author a Cinematic Cutscene](author-a-cinematic-cutscene.md) — stage a
   story sequence: cast, camera, parallel lanes, skip and finalizer, played
   through the engine from the editor.
7. [Author a Summon Cutscene](author-a-summon-cutscene.md) — a frame-driven
   battle presentation: tracks, keyframes, cues, and the actor assignment.

## What The Editor Can Do Today

- Create, paint, duplicate, and delete maps, with full undo.
- Create, place, move, duplicate, and delete a map's NPCs, and author every
  field the game reads: sprite and directional sprites, fixed or bounded
  wander, visibility conditions, dialogue and conditional variants, inline
  scripts, and completion writes.
- Place and configure the event types: Dialogue, Transfer Player, Shop,
  Sleep, Chest, Story Script, and Cinematic.
- Author cinematic and summon cutscenes on the Cutscenes screen (`F4`), and
  preview both through the engine itself without writing a file.
- Author actors, classes, skills, quests, animations, states, troops, skits,
  event scripts, terms, and system settings.
- Browse items, weapons, armors, and enemies.
- Playtest any map from the cursor without touching your project.

## What Still Happens Outside The Editor

Current limits worth knowing before you plan a session:

- **Patrol routes.** The engine has no patrol or pathfinding for NPCs: they
  stand still, wander a rectangle, or follow a `move_route` in a script. The
  editor authors exactly that and no more.
- **Items, weapons, armors, enemies.** These files are PHP constructor calls.
  The editor edits them entry by entry rather than regenerating them, and
  refuses, with the reason, an edit it cannot express in the author's own
  source. See the manual's
  [category table](../manual.md#editable-and-read-only-categories) and
  [how such a file is written](../manual.md#how-a-file-of-constructor-calls-is-written).

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
