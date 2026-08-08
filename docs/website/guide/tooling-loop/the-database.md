---
title: The Database
description: Author states, troops, quests, skits, and event command lists in the editor's Database screen, and understand which categories are read-only and why.
category: Tooling Loop
tags: [editor, database, data, quests, skits]
order: 53
readTime: 4
---
`Ctrl+D` (or `F2`) opens the Database over the main shell: categories on the left, entries in the middle, fields on the right. `Tab` moves between the three, `Shift+A` creates an entry, `Delete` removes one, and `/` filters the list.

Every field edit, entry creation, and deletion is undoable. Undo is identity-pinned, so `Ctrl+Z` applies to the entry you edited even after you have moved on to another one.

## Repeating fields

Quest objectives, skit beats, troop members, and event commands are all *sub-lists*, flattened into the field pane as numbered rows. Two keys manage them everywhere:

- `Shift+O` appends an entry
- `Shift+X` removes the last one

One idiom, five categories.

## Condition lines

Quest prerequisites, skit availability, and event `branch` arms share a single grammar on one line. Entries are separated by `;`, each is `[!]type:name[:extras]`, and a leading `!` negates:

```text
quest:breakfast-duty:active; !switch:door_open:false; item:S-Potion:3
```

The types are `quest`, `switch`, `event`, `item`, `key_item`, and `variable`. Unparseable entries are dropped rather than written back as garbage.

## Editable and read-only

Whether a category can be written is *detected*, not assumed. When a category loads, the editor asks two questions about its file: can every value be written back out losslessly, and would a rewrite drop a comment? Only when both answers are safe does the category accept edits.

Editable today: Actors, Classes, Skills, Troops, States, Animations, Quests, Skits, Common Events, System, and Terms.

Browsable but not writable: Items, Weapons, Armors, and Enemies.

The reason is worth understanding, because it is a property of your project rather than a missing feature. `items.php` and `enemies.php` are not data files — they are PHP that *builds* data, constructing effect objects inline and sharing skills between enemies through local variables. Regenerating such a file from the values the editor loaded would mean inventing source, and anything the editor did not recognise would be silently lost.

So the editor shows you the values and refuses to write. An enemy displays its level, every stat, its sprite, its battle rewards, and its element affinities — you simply edit the file itself when you want to change them.

Re-author one of those files as a plain array and the editor picks it up as editable automatically. Nothing in the editor needs to change.

`Types` is read-only for a related reason: those files are PHP enum declarations, not data the engine loads. `Tilesets` is empty because the engine has no tileset system — map tiles are painted directly on the canvas.

## What saving preserves

When the editor rewrites a data file it regenerates only the returned value. Everything from `<?php` up to the `return` is written back byte-for-byte: file docblocks, `use` imports, blank lines, and the explanatory comment above a cutscene.

If a comment sits *inside* the returned data, the editor turns that category read-only instead of dropping it, and says so. Move the comment above the `return` and editing resumes.

## Skits and event scripts

Skits live one file per skit under `assets/Data/Skits`, with an id, a title, an optional map gate, conditions, and a list of beats. The engine keys them by their `id` field rather than by filename.

Event scripts live one file per script under `assets/Events` and hold an ordered command list. The editor exposes the full interpreter vocabulary — `text`, `choice`, `wait`, `set_switch`, `set_variable`, `record_event`, `give_item`, `give_gold`, `play_sound`, `play_music`, `accept_quest`, `move_player`, `transfer`, `start_battle`, and `branch` — with the field rows following the command type you pick.

Nested arms are the current limit: a `choice` command's options and a `branch` command's `then` and `else` are shown but edited by hand. They round-trip untouched when you save, so authoring the outer script in the editor and the nested arms in your text editor is safe.
