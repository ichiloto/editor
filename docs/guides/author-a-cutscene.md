# Author a Cutscene

This guide covers building an event command list — the scripts the engine runs
for cutscenes, notes, and scripted moments.

## What An Event Script Is

One file per script under `assets/Events`, returning an ordered list of command
maps. The engine's interpreter walks the list top to bottom. Dialogue,
choices, waits, movement routes, transfers, and battles yield or suspend and
then resume through the game loop. Unknown command types retain the runtime's
legacy warn-and-skip behavior, but validation reports them as authoring errors;
other command failures stop the session with a controlled diagnostic.

The filename stem is the script id: `assets/Events/dresser-note.php` is the
script `dresser-note`.

## Create The Script

1. Press `Ctrl+D` to open the Database.
2. Move to the `Common Events` category.
3. `Tab` to the entry list and press `Shift+A`.

A new file appears with one `text` command in it. The `Script Id` row shows its
name and is fixed — renaming a script means renaming the file, which would
silently break any map that references it, so the editor does not offer it.

## Add Commands

The settings pane flattens the command list. Each command contributes a
`Command N Type` row plus the rows that type uses; changing the type changes the
rows beneath it.

- `Shift+O` appends a command.
- `Shift+X` removes the last one.
- `Left` / `Right` on a `Type` row cycles through the command vocabulary.

The full vocabulary:

| Type | Fields | Notes |
| --- | --- | --- |
| `text` | Speaker, Text | An empty speaker means narration |
| `choice` | Prompt, Title, Options | Options are fixed here; see below |
| `wait` | Seconds | Accepts fractions |
| `set_switch` | Switch, Value | |
| `set_variable` | Variable, Operation, Value | Operation is `set` or `add` |
| `record_event` | Story Event | What conditions test with `event:` |
| `give_item` | Item, Quantity | Item must exist in `items.php` |
| `give_gold` | Amount | Negative debits |
| `play_sound` | Sound | |
| `play_music` | Music | |
| `accept_quest` | Quest Id | Starts a quest from a scene |
| `move_player` | X, Y | |
| `move_route` | Subject, NPC Id, Wait, Seconds Per Step, Speed, Steps | Awaited cardinal route; see below |
| `transfer` | Map Id, X, Y | |
| `start_battle` | Troop, Result Variable, Defeat Policy | Suspends and resumes through battle return |
| `branch` | Conditions, Then, Else | See below |

## Movement Routes

`move_route` uses a structured step list rather than a free-text PHP field.
Set `Subject` to `player` or `npc`. NPC routes require the target's stable,
map-local `id`; display names are not script identity.

Move the settings cursor onto one of the route's `Step` rows:

- `Shift+O` adds a step to that route.
- `Shift+X` or `Delete` removes a route step.
- `Left` / `Right` cycles `Direction` (`up`, `down`, `left`, `right`) and
  `Face Only`; edit `Count` for repeated cells.

`Seconds Per Step` sets route pacing, while `Speed` expresses steps per
second. A step may carry its own seconds value when authored in PHP. Leave
`Wait` true: Phase 7 supports deterministic sequential routes, not parallel
actors. Movement uses the engine's normal passability and collision. A blocked
step fails the script immediately with route context instead of hanging or
being skipped.

## Branching

A `branch` command's `Conditions` row uses the shared condition grammar
documented in [Wire a Quest](wire-a-quest.md). All conditions must hold.

Current limit: the `Then` and `Else` arms are shown as fixed rows and edited by
hand in the file. The same applies to a `choice` command's `Options`. Both
round-trip untouched when you save from the editor, so it is safe to author the
outer script here and the nested arms in your editor of choice.

The reason is deliberate: flattening a command *tree* into one settings pane
would be unreadable, and dropping the tree on save would be worse than not
offering the edit.

## Continue After A Transfer Or Battle

`transfer` may have later commands. The interpreter suspends before the normal
map load, lets map and NPC managers configure, and resumes the same in-memory
session on the destination map.

`start_battle` may also have later commands. Set its optional `Result Variable`
to expose `victory`, `defeat`, or `escape` to a later `branch`; leave it empty
to write nothing. `Defeat Policy` is `game_over` by default, preserving normal
defeat. Choose `continue` only when this particular scripted battle is meant to
return a defeat result and carry on.

While either continuation is pending, numbered/manual save and quicksave are
blocked. Transfer autosave waits until the full event and its completion writes
succeed. Active sessions are not stored in save files.

## What Saving Preserves

Press `Ctrl+S` to save the category.

The editor regenerates only the returned list. Everything from `<?php` up to
the `return` is written back byte-for-byte — including a comment like:

```php
<?php

// A small demo cutscene: reading the note on the dresser.
return [
```

If a comment sits *inside* the list, the editor turns the category read-only
instead of dropping it, and the status line says so. Move such comments above
the `return` and editing resumes.

## Run It From A Map

Switch the canvas to Event mode, paint or select a marker, and choose **Story
Script** in the Event Type picker. In the Inspector:

1. Select `Script Id` and choose the Common Event from its reference picker.
2. Set `Mode` to `action` or `auto`.
3. Turn `Reusable` off for a one-shot event.
4. Add any root `Conditions`, completion `Sets`, or `When Blocked` message.

The resulting definition is the existing runtime shape:

```php
'E' => [
  'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
  'data' => [
    'mode' => 'action',      // 'auto' runs on step-in
    'reusable' => false,     // one-shot scenes persist like chests
    'scriptId' => 'dresser-note',
  ],
],
```

Then paint the matching `E` marker on the event layer in the editor, and the
script runs when the player interacts with that tile.

A one-shot Story Script records completion only after its final command and
completion writes succeed. Auto and action triggers refuse re-entry while the
session is active, so input cannot duplicate an item, quest, or story flag.

## Check It

`Ctrl+A` saves everything, then `Ctrl+T` playtests from the cursor. Stand next
to the marker and interact.

If nothing happens, check that the `scriptId` matches the filename stem exactly
and that the marker letter matches the key in `events`. Project validation also
reports missing scripts, duplicate NPC IDs, missing route targets where map
context is known, malformed route steps/timing, unknown commands, bad result
variables, and invalid defeat policies.

Current limits: the editor preserves but does not structurally edit nested
choice/branch trees. Cutscene skipping and finalizers, camera and fade
commands, field-animation commands, parallel routes, patrol routes,
pathfinding, and full NPC creation/placement remain deferred.

Continue with [Write a Skit](write-a-skit.md).
