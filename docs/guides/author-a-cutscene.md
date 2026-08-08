# Author a Cutscene

This guide covers building an event command list — the scripts the engine runs
for cutscenes, notes, and scripted moments.

## What An Event Script Is

One file per script under `assets/Events`, returning an ordered list of command
maps. The engine's interpreter walks the list top to bottom. A failing command
is logged and skipped rather than aborting the script, so a typo costs you one
beat, not the whole scene.

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
| `transfer` | Map Id, X, Y | |
| `start_battle` | Troop | Switches scenes — put it last |
| `branch` | Conditions, Then, Else | See below |

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

Current limit: the editor cannot yet point a map marker at a script. The five
event types it places are Dialogue, Transfer Player, Shop, Sleep, and Chest.

To run a script, add a `ScriptEventTrigger` to the map's `.data.php` by hand:

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

## Check It

`Ctrl+A` saves everything, then `Ctrl+T` playtests from the cursor. Stand next
to the marker and interact.

If nothing happens, check that the `scriptId` matches the filename stem exactly
and that the marker letter matches the key in `events`.

Continue with [Write a Skit](write-a-skit.md).
