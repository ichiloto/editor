# Ichiloto Editor Manual

This document is a living guide to the Ichiloto editor.

It tracks the editor as it exists today, including current keybindings, panel
workflows, Database categories, and known limits. Update this file whenever
the editor gains new tools, panels, controls, or shortcuts — a test in
`tests/Unit/ManualCoverageTest.php` fails if a registered keybinding is not
documented here.

For task-oriented walkthroughs, start with [docs/guides/README.md](guides/README.md).

## Starting the Editor

The editor opens one Ichiloto project — a directory containing `ichiloto.json`.
Run it from inside the project:

```bash
cd my-game
ichiloto edit
```

That is the command when the tooling is installed globally
(`composer global require ichiloto/console`, with Composer's `bin` directory on
your `PATH`). A project that requires `ichiloto/console` itself runs
`vendor/bin/ichiloto edit` instead. Both open the current directory, so nothing
here depends on where the project lives.

Options:

- `--directory`, `-d`: the project directory to open. Defaults to the current
  working directory.
- `--no-tmux`: run in this terminal instead of creating or reusing a tmux
  session named `ichiloto-editor-<project>`.

The editor requires a terminal at least 80 columns wide. It runs on the
alternate screen, so your scrollback is untouched, and it restores your
terminal settings on exit — including after a crash.

Current behavior: the editor never writes to your project until you ask it to.
Every edit lives in memory until a save, and every save path is listed under
[Saving](#saving).

## Layout Overview

The main shell is three panels between a header and a status footer. This is
the editor rendering itself at 100x24 against the test fixture project, so it
is what the code draws rather than a sketch of it:

```text
 ┌─Ichiloto Editor────────────────────────────────────────────────────────────────────────────────┐
 │ Project: Sample Project                                                                        │
 └─?:Help  Ctrl+P:Palette  Ctrl+D / F2:Database───────────────────────────────────────────────────┘
 ┌─Assets [Focus]───────────────┐ ┌─Canvas─────────────────────┐ ┌─Inspector──────────────────────┐
 │ Maps                         │ │ Preview: test-map          │ │   Name: Test Map               │
 │ > test-map                   │ │ Test Map |  | 12 x 5 | vie │ │   Region:                      │
 │                              │ │ ############               │ │   Description: A tiny fixture  │
 │                              │ │ #  ~~~     #               │ │   Size                         │
 │                              │ │ #          #               │ │     X: 12                      │
 │                              │ │ #          #               │ │     Y: 5                       │
 │                              │ │ ############               │ │   Events · 1                   │
 │                              │ │                            │ │   Triggers · 0                 │
 │                              │ │                            │ │                                │
 │                              │ │                            │ │                                │
 │                              │ │                            │ │                                │
 │                              │ │                            │ │                                │
 │                              │ │                            │ │                                │
 └─/:Filter  Del:Delete─────────┘ └─%:Map  ^:Event  @:Chars────┘ └─Enter:Edit─────────────────────┘
 ┌─Status─────────────────────────────────────────────────────────────────────────────────────────┐
 │ Selected map: test-map | Focus: Assets | Mode: Map | Tool: Brush 1                             │
 │ Cursor: (0, 0) | Viewport: (0, 0) | Ready.                                                     │
 └─?:Help  Ctrl+P:Palette  Tab:Pane  Enter:Edit  Ctrl+S:Save  Ctrl+A:Save All  Ctrl+Z:Undo  Ctrl+Y┘
```

- `Assets` lists every map discovered under `assets/Maps`.
- `Canvas` previews and paints the selected map.
- `Inspector` shows the fields of whatever is selected.
- The `Status` footer carries the current selection, mode, active canvas tool,
  and, on its second line, the cursor, the viewport, and the most recent
  message.

Each panel writes its own keys into its bottom border, and shortens them on a
narrow terminal rather than cutting one in half — so what a panel offers is
always on the panel. `?` lists everything at any width.

The header's second line is a permanent hint; the header's first line shows the
project name and a `*` marker when anything is unsaved. The focused panel
carries `[Focus]` in its title and is drawn in the project's own selection
color.

## The Focus Model

Exactly one panel has focus at a time, marked `[Focus]` in its title and drawn
in the project's selection color (see [Theming](#theming)).

| Key | Action |
| --- | --- |
| `Tab` | Move focus to the next panel |
| `Shift+Tab` | Move focus to the previous panel |
| `Shift+Arrows` | Move focus directionally |

`Tab / Shift+Tab` and `Shift+Arrows` cycle the same ring, so use whichever you
prefer. Arrow keys always act inside the focused panel.

## Global Shortcuts

These work from any panel. They are control bytes on purpose: no shortcut
steals a glyph you might want to paint on a map.

| Key | Action |
| --- | --- |
| `?` | Toggle the help overlay |
| `Ctrl+P` | Open the command palette |
| `Ctrl+D` | Open or close the Database screen |
| `F2` | Open or close the Database screen (same as `Ctrl+D`) |
| `Ctrl+S` | Save the selected map |
| `Ctrl+A` | Save every dirty map and database |
| `Ctrl+Z` | Undo the last mutation |
| `Ctrl+Y` | Redo |
| `Ctrl+G` | Go to definition |
| `Ctrl+B` | Back to the previous location |
| `Ctrl+T` | Playtest the selected map from the cursor |
| `Ctrl+R` | Reload the workspace (guards unsaved changes) |
| `Ctrl+E` | Open the status detail overlay |
| `Ctrl+Q` | Quit (guards unsaved changes) |
| `/` | Filter the focused list |

`Ctrl+Z / Ctrl+Y` is the undo pair; on terminals that report CSI-u,
`Ctrl+Shift+Z` also redoes. `Ctrl+C` and `Ctrl+V` are deliberately unbound:
`Ctrl+C` remains your escape hatch, and `Ctrl+V` is the terminal's literal-next.

Current behavior: the help overlay is generated from the same binding table
the editor dispatches on, so `?` can never document a shortcut that does not
exist.

### Escape

`Esc` backs out exactly one level, everywhere:

- a field edit returns to the pane
- a pending canvas anchor is dropped, then a selection is cleared
- a filter query is cleared before its list closes
- an overlay or dialog closes
- the Database screen closes

`Esc` is the only cancel key. On yes/no prompts, `n` is an explicit "No".

## Assets Panel

The Assets panel lists every map in the project, sorted by map id. A dirty map
carries a trailing `*`.

Controls:

- `Up` / `Down`: move the selection
- `Shift+A`: create a new map
- `Shift+D`: duplicate the selected map
- `Delete`: delete the selected map (destructive confirmation)
- `/`: filter the list incrementally
- `Enter`: focus the canvas on the selected map

Filtering narrows the list as you type; `Enter` keeps the query and returns the
arrows to navigation, and `Esc` clears it. While the filter caret is open, `?`,
`/`, and `Ctrl` keys are typed into the query instead of firing.

Current behavior: creating, duplicating, and deleting a map are filesystem
operations that happen immediately and are not undoable. Each destructive one
asks first and defaults to Cancel.

## Canvas Panel

The canvas previews the selected map and is where you paint.

| Key | Action |
| --- | --- |
| `%` | Switch to Map mode (paint tiles) |
| `^` | Switch to Event mode (paint event markers) |
| `F3` | Toggle NPC mode (place and edit the map's NPCs) |
| `@` | Open the character map |
| `Arrows` | Move the cursor |
| `Enter` | Apply the active tool |

Typing any other printable glyph paints it at the cursor with the brush tool.
Under a shape or select tool, typing a glyph loads it into the brush instead.

### NPC Mode

`F3` enters NPC mode: the map's `npcs` collection is drawn over the tiles as
an overlay — sprites at their authored anchor, wide glyphs occupying the two
columns the game gives them — and nothing you do here paints a tile or an
event marker. The selected NPC is shown in brackets. NPC mode is a function
key rather than a glyph so no paintable character is taken from you (and not
a control byte, since the terminal driver reserves the remaining ones).

| Key | Action |
| --- | --- |
| `Enter` | Select the NPC under the cursor, or create a new fixed NPC there |
| `M` | Pick up the selected NPC; the next `Enter` sets it down at the cursor (`Esc` cancels) |
| `D` | Duplicate the selected NPC under a fresh stable id |
| `Del` | Delete the selected NPC — refused, with the list, while anything names its id |
| `Tab` | Edit the selected NPC in the Inspector |

Create, move, duplicate and delete each undo and redo as one step, and dirty
state follows the map's persisted-state fingerprint like every other edit:
undoing back to the last save is clean. A typed glyph in NPC mode is refused
with a hint rather than painted under an NPC.

A new NPC receives a stable `id` derived once from its name and unique on its
map. The id is what `move_route` and script diagnostics name, and it is
**immutable after creation** — renaming the NPC, moving it, or changing its
sprite never touches it. Duplicating assigns a fresh id. Existing NPCs authored
without an id load and edit normally, with a validation warning that scripted
movement cannot target them; changing an id is an identity migration, which
the editor does not yet offer.

Mouse drag-painting works, with gap filling, and a whole drag coalesces into a
single undo step.

### Canvas Tools

The active tool is modal and always shown in the footer, for example
`Tool: Rect Fill 2 @0,0 [5x3] clip 2x1` — tool, brush width, pending anchor,
selection size, and clipboard size.

| Key | Action |
| --- | --- |
| `Ctrl+N` | Next tool (Brush → Line → Rect → Rect Fill → Select) |
| `Ctrl+W` | Cycle brush width (1 / 2 / 3 / 5) |
| `Ctrl+F` | Flood fill from the cursor |
| `Ctrl+K` | Eyedropper — pick up the symbol under the cursor |
| `Ctrl+L` | Lift (copy) the selection |
| `Ctrl+X` | Cut the selection |
| `Ctrl+U` | Paste/stamp the clipboard at the cursor |

`Enter` is the universal tool verb: it paints under the brush, sets the anchor
for a two-point tool, and completes the shape or selection on the second press.

Every tool commits as exactly one undo step. A filled rectangle, a flood fill,
a cut, and a paste each undo in a single `Ctrl+Z`. Repeated pastes are separate
steps, so you can stamp freely.

The clipboard is layer-tagged: a block lifted from the event layer refuses to
land on tiles.

Current limit: the canvas draws no on-screen preview of a pending line,
rectangle, or selection rectangle. The footer reports the anchor and selection
size instead.

## Inspector Panel

The Inspector shows the fields of the current selection — the map's metadata in
Map mode, the selected event's data in Event mode.

Controls:

- `Up` / `Down`: move between fields
- `Enter`: start editing a text or number field
- `Left` / `Right`: adjust without entering edit mode — cycle enum options,
  toggle a boolean, step a number
- `Esc`: cancel an edit

Field rows are typed. A text field accepts anything; an integer field accepts
digits and a leading `-`; a float field also accepts one `.`; a boolean toggles;
an enum cycles through its options.

Rows shown as `Label · value` are visible but fixed — they display information
the editor does not let you edit here. Rows shown as `Label: value` are
editable.

The Destination row on an event is a reference: `Ctrl+G` follows it.

In Event mode, painting or selecting a marker with no definition opens the
Event Type picker. **Story Script** creates the engine's
`ScriptEventTrigger`. Its inspector exposes `Script Id`, `Mode`
(`action`/`auto`), `Reusable`, `Conditions`, `Sets`, and `When Blocked`.
Select `Script Id` and use the reference picker to choose an existing Common
Event; the value is not free-typed. Conditions and completion writes use the
same structured inspector-list controls as other event types.

## Database Screen

`Ctrl+D` or `F2` opens the Database screen over the main shell; the same key
closes it. The screen has a category list on the left, an entry list, and a
settings pane.

Controls:

- `Up` / `Down`: move within the focused pane
- `Tab`, or `Shift+Arrows`: move between the category, entry, and settings panes
- `Enter`: edit the selected setting
- `Left` / `Right`: cycle an enum option or step a number
- `Shift+A`: create a new entry in the current category
- `Delete`: delete the selected entry (destructive confirmation)
- `Shift+O`: append a sub-list entry (objective, beat, member, command, or
  movement-route step when a route-step row is selected)
- `Shift+X`: remove the last sub-list entry at the selected level
- `/`: filter the entry list
- `Ctrl+S`: save the current category

Every field edit, entry creation, entry deletion, and sub-list change is
undoable with `Ctrl+Z`. Undo is identity-pinned: it applies to the entry you
edited even after the selection has moved on.

Current behavior: deleting an entry takes effect in memory immediately, and the
file changes on the next save. Undo before saving costs nothing.

### Editable And Read-Only Categories

Whether a category can be written is *detected*, not assumed. On load the
editor evaluates the authored file and asks two questions: can every value be
written back out losslessly, and would a rewrite drop a comment? A category is
editable only when both answers are safe. Anything else is browsable, and the
status line says exactly why.

| Category | Backing file | Status |
| --- | --- | --- |
| Actors | `assets/Data/Actors/*.php` | Editable |
| Classes | `assets/Data/classes.php` | Editable |
| Skills | `assets/Data/skills.php` | Editable |
| Items | `assets/Data/items.php` | Read-only — authored as `new Item(...)` calls |
| Weapons | `assets/Data/items.php` | Read-only — authored as `new Weapon(...)` calls |
| Armors | `assets/Data/items.php` | Read-only — authored as `new Armor(...)` calls |
| Enemies | `assets/Data/enemies.php` | Read-only — authored as `new Enemy(...)` calls |
| Troops | `assets/Data/troops.php` | Editable |
| States | `assets/Data/states.php` | Editable |
| Animations | `assets/Data/animations.php` | Editable |
| Tilesets | — | Read-only — the engine has no tileset system |
| Common Events | `assets/Events/*.php` | Editable |
| Quests | `assets/Data/quests.php` | Editable |
| Skits | `assets/Data/Skits/*.php` | Editable |
| System | `assets/Data/system.php` | Editable |
| Types | `assets/Data/Types/*.php` | Read-only — PHP enum declarations |
| Terms | `config.php` (`vocab`, `messages`) | Editable when the config carries no inline comments |

Why the read-only ones are read-only: those files are not data, they are PHP
code that *builds* data. `enemies.php` assigns skills to local variables and
shares them between enemies; `items.php` constructs effect objects inline.
Regenerating such a file from the values the editor loaded would mean inventing
source, and anything the editor did not understand would be silently lost. The
editor would rather show you the values and refuse to write.

You can still browse everything: an enemy shows its level, every stat, its
sprite, its battle rewards, and its element affinities. Making one of these
categories editable is a matter of re-authoring its file as a plain array —
the editor picks that up automatically, with no change to the editor.

### States

Status effects, read by the engine's `StateRegistry`.

Fields: `Id`, `Name`, `Icon`, `Description`, `Duration Turns`, `Tick Formula`,
`Prevents Action`, `Persists After Battle`.

`Id` is the only field the engine requires. Optional fields disappear from the
file when you clear them, so a state with no duration lasts until it is cured
rather than being written as `0`. `Tick Formula` is the same formula language
skill effects use, with `$target` bound to the afflicted battler.

### Troops

Encounter groups. Each troop has a `Name`, an optional `Escape Policy`, and a
list of members flattened into the settings pane as `Member 1 Enemy`,
`Member 1 X`, `Member 1 Y`, and so on. An omitted escape policy preserves the
engine default (`allowed`); choose `forbidden` for a battle that must be won or
resolved by its authored continuation.

`Shift+O` appends a member, `Shift+X` removes the last one. The `Enemy` value
must match a name in the Enemies category.

### Terms

The `vocab` and `messages` trees of the project's `config.php`, flattened to
one row per term with its dotted path — `vocab.game.new_game`,
`messages.confirm.quit`, and so on.

Current limit: the category is read-only when `config.php` contains comments
inside the returned array, because rewriting the file would drop them. Move
such comments above the `return` statement and the category becomes editable —
everything before `return` is preserved byte-for-byte on save.

### Skits

Optional party banter, one file per skit under `assets/Data/Skits`. The engine
scans the directory and keys skits by their `id` field, not by filename, so
renaming an `Id` in the editor does not require renaming the file.

Fields: `Id`, `Title`, `Where (map id)`, `Conditions`, `Speed (chars/sec)`,
then one pair of `Beat N Speaker` / `Beat N Text` rows per beat.

- `Where` gates the skit to one map id, matched case-insensitively. Leave it
  empty to make the skit available anywhere.
- `Conditions` is the shared world-condition line described under
  [Condition Lines](#condition-lines).
- `Shift+O` and `Shift+X` add and remove beats.

`Shift+A` creates a new skit as its own file, named after its generated id.

Current behavior: a skit plays at most once per save file, and the engine
announces availability rather than interrupting — the player presses `T`.

### Common Events

Cutscene and event command lists, one file per script under `assets/Events`.
A map's `ScriptEventTrigger` runs the script whose `scriptId` matches the
filename stem.

The entry list shows script ids. The settings pane flattens the command list:
each command contributes a `Command N Type` row plus the rows that type uses.
Changing the type changes the rows beneath it.

Supported command types, matching the engine's interpreter:

| Type | Fields |
| --- | --- |
| `text` | Speaker, Text |
| `choice` | Prompt, Title, Options (fixed) |
| `wait` | Seconds |
| `set_switch` | Switch, Value |
| `set_variable` | Variable, Operation (`set` / `add`), Value |
| `record_event` | Story Event |
| `give_item` | Item, Quantity |
| `give_gold` | Amount |
| `play_sound` | Sound |
| `play_music` | Music |
| `accept_quest` | Quest Id |
| `move_player` | X, Y |
| `move_route` | Subject, NPC Id, Wait, Seconds Per Step, Speed, Steps |
| `transfer` | Map Id, X, Y |
| `start_battle` | Troop, Result Variable, Defeat Policy, Escape Policy |
| `recover_party` | None |
| `branch` | Conditions, Then (fixed), Else (fixed) |

`Shift+O` appends a command, `Shift+X` removes the last one.

For `move_route`, place the settings cursor on one of its `Step` rows before
using `Shift+O`, `Shift+X`, or `Delete`; the operation then adds or removes a
step inside that command instead of changing the outer command list. Each step
has a cardinal `Direction`, repeat `Count`, and `Face Only` flag. Subject is
`player` or `npc`; NPC routes require the stable map-local NPC `id` authored in
the map data. Routes in this phase are sequential and awaited, so `Wait` must
remain true. Set either seconds-per-step (with an optional per-step override)
or speed in steps per second.

Current limit: nested arms are shown but not edited. A `choice` command's
`Options` and a `branch` command's `Then` / `Else` appear as fixed rows, and
their contents round-trip untouched when you save. Editing a nested arm means
editing the file directly — flattening a command tree into one settings pane
would be unreadable, and dropping it on save would be worse.

`start_battle` may occur in the middle of a script. The event suspends until
the existing battle return path restores the field, then continues with the
next command. `Result Variable` is optional and receives `victory`, `defeat`,
or `escape`; leaving it empty writes nothing. `Defeat Policy` defaults to
`game_over`. Select `continue` only for a scripted battle that is explicitly
allowed to return after defeat.

`recover_party` fully restores HP, MP, and AP for the whole travelling roster
and clears battle-only state. It is intended for explicit story recovery
points and does not change party order, equipment, progression, or persistent
conditions.

Story-event sessions also survive an authored `transfer` in memory. While a
session is active, manual save and quicksave are blocked and transfer
autosaves are deferred until successful completion; the editor does not
author or serialize execution checkpoints.

The runtime and editor use the same event-command vocabulary. Validation
reports an unknown command before playtesting, while the runtime independently
fails closed if validation was skipped: no later or enclosing command runs,
completion state and rewards remain unapplied, deferred autosave is discarded,
and field input plus saving return for a corrected retry.

### Command Frames

Choice options and branch arms are edited as frames, mirroring how the
runtime executes them. A `choice` command lists each option as two rows: its
text, editable in place, and a `Commands · N` row that opens the option's own
command list on Enter. A `branch` shows `Then Commands` and `Else Commands`
rows the same way. Inside a frame the pane shows only that list — the same
rows, pickers, and Shift+O / Shift+X / Del as the top level, at any depth —
and the pane title is the trail back out (`Commands › Choice 2 › Option 1`).
Esc pops exactly one frame; at the top it closes the Database as before.

Shift+O with the cursor on an option row adds an option to that choice;
removing an option takes its whole arm with it, and undo puts both back.

Current limits: there is no cutscene skipping, camera/focus or screen-fade
command, field-animation command, parallel movement route, NPC patrol-route
authoring, pathfinding, or complete NPC placement editor.

### Condition Lines

Quest prerequisites, skit conditions, and `branch` conditions all use one
grammar, written on a single line. Entries are separated by `;`, and each entry
is `[!]type:name[:extras]`. A leading `!` negates.

```text
quest:breakfast-duty:active; !switch:door_open:false; item:S-Potion:3
```

| Type | Form |
| --- | --- |
| `quest` | `quest:<id>:<completed\|active>` |
| `switch` | `switch:<name>`, or `switch:<name>:false` |
| `event` | `event:<name>` |
| `item` | `item:<name>`, or `item:<name>:<quantity>` |
| `key_item` | `key_item:<name>` |
| `variable` | `variable:<name>:<op>:<value>` |

Unparseable entries are dropped rather than written back as garbage.

## Overlays

| Overlay | Opens with | Notes |
| --- | --- | --- |
| Help | `?` | Generated from the binding table; scrolls with arrows |
| Command palette | `Ctrl+P` | Fuzzy search over actions, tools, maps, categories, and event markers |
| Character map | `@` | Insert glyphs the keyboard reserves |
| Status detail | `Ctrl+E` | Full text of the last message, and the log file path |

Current limit: the help overlay and command palette cannot open while a picker
dialog is up. Press `Esc` first — dialogs consume all input by design.

## Navigation

`Ctrl+G` follows the reference under the cursor, and `Ctrl+B` returns:

- an actor's `Class` field jumps to that class
- an event's `Destination` jumps to the target map, at the configured spawn
- a skill jumps to the animation matching its name

`Ctrl+B` restores the map, cursor, viewport, pane focus, editing mode, and the
whole Database selection. The stack holds 50 entries and is cleared by a
reload.

Current limit: skills carry no animation *reference* in the engine data model,
only a name, so `Ctrl+G` on a skill resolves by name and says so when nothing
matches.

## Saving

| Change | Written to | When |
| --- | --- | --- |
| Map tiles, events, metadata | the map's three split files | `Ctrl+S`, or `Ctrl+A` |
| Database field edits | that category's file(s) | `Ctrl+S` in the Database, or `Ctrl+A` |
| Database entry deletion | that category's file(s) | the next save of that category |
| Map create / duplicate / delete | the filesystem | immediately |

`Ctrl+A` saves every dirty map and every editable database in one pass.
Read-only categories are never included — they hold no edits.

Saving writes through a temporary file and a rename, so an interrupted save
cannot truncate your work. When a save regenerates a data file, everything from
`<?php` up to the top-level `return` is preserved byte-for-byte: file
docblocks, `use` imports, blank lines, and the explanatory comment above a
cutscene all survive.

### Stable map identities

A map's project-relative path is its stable identity — doors transfer to it,
quests reach for it, saves record it. The display name and region are
metadata: editing them saves in place and never moves or renames the map's
directory. A new map derives its initial path from its name once, at
creation; after that, ordinary saves never infer a rename.

Moving a map is its own operation — *Move Map to Derived Path* in the
command palette. It shows the current and proposed ids, requires an explicit
`y`, rejects collisions, and fails closed rather than half-moving. References
are **not** migrated: anything naming the old id keeps naming it, and the
prompt says so before you confirm.

### Dirty means "differs from the last save"

An asset is dirty exactly when its content differs from what the last
successful save wrote — not because a mutator ran. Undoing your way back to
the saved state clears the marker; redoing away restores it; saving partway
through history simply sets a new checkpoint; a failed save leaves the old
one intact. Setting a field to the value it already has, painting a tile
with its own symbol, or resizing to the current size creates neither dirt
nor a history entry.

Clean saves are no-ops, everywhere: `Ctrl+S` on an unchanged asset or
database writes nothing, keeps mtimes, and never canonicalizes authored
formatting. File-per-entry categories (actors, skits, event scripts) write
only their dirty, new, or explicitly deleted entries — saving one actor
leaves every other actor file byte-for-byte untouched.

### Validation

Before a map save the editor runs a validation pass and warns — never blocks —
on dangling destination references, event markers without definitions, and
spawn points outside the map. The warnings appear in the status footer;
`Ctrl+E` shows the full text.

### Backups

Backups are off by default and opt-in per project, in `ichiloto.json`:

```json
{
  "editor": {
    "backups": { "enabled": true, "retain": 5, "directory": ".ichiloto/backups" }
  }
}
```

`ICHILOTO_EDITOR_BACKUPS=1|0` and `ICHILOTO_EDITOR_BACKUP_RETAIN=N` override
this for a single session.

A backup is a timestamped copy taken immediately *before* an overwriting save.
The editor never writes into your source files in the background — "autosave"
here means a safety copy, not a silent write. Copies mirror the
project-relative path under the backup root, retention prunes oldest-first per
file, and a backup failure warns without blocking the save. The active policy
is printed in the `?` overlay.

## Playtesting

`Ctrl+T` launches the game on the selected map, spawning at the canvas cursor.
The editor hands the terminal to the game and takes it back when the game
exits.

How it avoids touching your project: the editor builds a temporary project root
of symlinks back to your real files, and replaces exactly two entries — a
generated `assets/Data/system.php` carrying the playtest spawn, and an empty
`.data/` so playtest saves cannot overwrite your save slots. Maps and every
other asset are symlinks, so the playtest runs against your live files. The
overlay is deleted when the game exits.

The map must be saved first: the game reads the file on disk, so the editor
refuses to playtest a dirty map rather than silently running the old version.

Current limits, both engine-side:

- The engine offers no starting-map override, which is why the overlay exists.
- The game still boots to its title screen, so a playtest is `Ctrl+T` then
  "New Game". Booting straight into the field would need an engine hook in
  `GameLoader::loadNewGame()` and `Game::start()`.

`Ctrl+T` needs the console binary, which it looks for in this order: the
project's own `vendor/bin/ichiloto`, any `vendor/bin/ichiloto` above the
installed editor package, then `ichiloto` on your `PATH`. Set
`ICHILOTO_CONSOLE_BIN` to override all of that. If none of them match, the
error names every path it tried.

## Theming

The editor adopts the look of the project it has open, reading two keys from
`config.php`:

- `ui.menu.border` — the border pack every editor window draws with. Any of the
  engine's packs works (`DefaultBorderPack`, `FancyBorderPack`, `SlimBorderPack`,
  `BoldBorderPack`, `CrossedBorderPack`, `HashedBorderPack`), given either as an
  instance or a class name.
- `ui.menu.selection_color` — the color marking the focused panel, the same
  color the game uses to highlight a selected menu row.

A project that declares neither keeps the editor's defaults. A project that
declares something the editor cannot resolve keeps the defaults too, rather
than failing to open. The active theme is printed in the `?` overlay.

## Quitting

`Ctrl+Q` quits. If anything is unsaved, a guard appears first:

- `Y` discards and quits
- `S` saves everything and quits
- `Esc` cancels

`Ctrl+R` reloads the workspace from disk behind the same guard. A reload clears
the undo history, the navigation stack, filters, and the clipboard.

## Notes

- The editor is keyboard-first. Mouse support is limited to canvas painting.
- The editor never writes to your project except through the save paths listed
  above.
- Read-only Database categories are a safety decision, not a missing feature —
  see [Editable And Read-Only Categories](#editable-and-read-only-categories).
- This document should be updated whenever a new shortcut, panel workflow, or
  Database category is added. `tests/Unit/ManualCoverageTest.php` enforces the
  keybinding half of that automatically.
