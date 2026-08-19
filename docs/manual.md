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
 │                              │ │ #  ~~~     #               │ │                map.            │
 │                              │ │ #          #               │ │   Size                         │
 │                              │ │ #          #               │ │     X: 12                      │
 │                              │ │ ############               │ │     Y: 5                       │
 │                              │ │                            │ │   Events · 1                   │
 │                              │ │                            │ │   Triggers · 0                 │
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
| `F4` | Open or close the Cutscenes screen (Cinematic and Summon) |
| `Ctrl+S` | Save the selected map |
| `Ctrl+A` | Save every dirty map, database and cutscene |
| `Ctrl+Z` | Undo the last mutation |
| `Ctrl+Y` | Redo |
| `Ctrl+G` | Go to definition |
| `Ctrl+B` | Back to the previous location |
| `Ctrl+T` | Playtest the selected map from the cursor (in the Cutscenes screen: the selected cinematic) |
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
columns the game gives them, styled sprites as the plain glyph — and nothing
you do here paints a tile or an event marker. The selected NPC is shown in
brackets. NPC mode is a function key rather than a glyph so no paintable
character is taken from you (and not a control byte, since the terminal driver
reserves the remaining ones).

| Key | Action |
| --- | --- |
| `Enter` | Select the NPC under the cursor; on an empty tile, name and create a new fixed NPC there |
| `M` | Pick up the selected NPC; the next `Enter` sets it down at the cursor (`Esc` cancels) |
| `D` | Duplicate the selected NPC under a fresh stable id, one column to the right when free |
| `L` | List the map's NPCs by name and id; type to narrow, `Enter` selects one and jumps the cursor to it |
| `[` / `]` | Select the previous / next NPC in the map's list |
| `Del` | Delete the selected NPC — refused, with the list, while anything names its id |
| `Tab` | Edit the selected NPC in the Inspector |

Create, move, duplicate and delete each undo and redo as one step, and dirty
state follows the map's persisted-state fingerprint like every other edit:
undoing back to the last save is clean. A typed glyph in NPC mode is refused
with a hint rather than painted under an NPC.

#### Stable ids

`Enter` on an empty tile asks for the NPC's name first, and derives its stable
`id` from that name — `Gate Guard` becomes `gate-guard`, numbered if the map
already has one — because the id is what `move_route` and script diagnostics
name, and it is **immutable after creation**: renaming the NPC, moving it, or
changing its sprite never touches it. Duplicating assigns a fresh id from the
name. An NPC authored without an id loads and edits normally, shows a
`! No stable id` row (`Enter` there assigns one from its name, the one time an
id is ever written after creation, since nothing can yet name it), and
validates with a warning that scripted movement cannot target it. Changing an
existing id is an identity migration, which the editor does not offer.

#### The NPC Inspector

`Tab` from the canvas edits the selected NPC with the same pane every Database
category uses — pickers, condition lines, world-write rows, command frames,
`Shift+O` / `Shift+X` on lists — grouped as:

| Group | Rows |
| --- | --- |
| Identity | `Id` (read-only), `Name` |
| Placement | `X`, `Y` (the canvas moves it too) |
| Appearance | `Sprite`, `Facing North/South/East/West` |
| Movement | `Movement` (`fixed` / `wander`), and while wandering `Wander X/Y/Width/Height` |
| Visibility | `Visible When` — a condition line |
| Interaction | `Script` (a command frame), then one `Dialogue variant N` heading per variant with its rows `When`, `Then Set`, `Script Commands`, `Line 1 Speaker`, `Line 1 Text`, … |
| Completion Writes | `After Talking` — world-write rows |

Rows read as the game will read them: an unset `Movement` shows `fixed`, an
unset `Sprite` shows `@`. Fields the game does not read are listed in a
`Preserved fields` row and written back untouched. Each dialogue variant is a
heading (`Dialogue variant 2 · when switch:gate_open` once it has a
condition) with short row labels under it, and long lines wrap, so what a
character says is read in the pane rather than in the edit buffer.

- **Movement.** `wander` roams one tile at a time; the wander bounds only
  appear while wandering, and loaded bounds are kept (not shown) for a fixed
  NPC. Omitting every bound leaves the game's unbounded wander. Patrol routes,
  pathfinding and followers are not engine features, so the editor does not
  offer them; scripted movement is a `move_route` command in an event script.
- **Directional sprites.** Optional glyphs shown when the NPC turns; the base
  sprite covers a heading you leave blank. Resting the Inspector cursor on a
  `Facing …` row previews that glyph on the canvas in the NPC's place.
- **Dialogue.** Pages are shown as variants: one variant with lines is written
  back as plain pages; add a second variant, or give one a `When` condition, a
  `Then Set`, or a `Script`, and the whole thing is written as conditional
  variants. The game speaks the first variant whose conditions hold. Each
  line's `Speaker` is picked by meaning: `(the NPC's name)` leaves it to the
  game to title the box with the NPC's current name, `(No speaker)` shows an
  untitled box, or an actor's name.
- **Scripts.** `Script` opens a command frame with the same event commands as
  a Common Event. A non-empty script **replaces** the dialogue at runtime; the
  pane says so with a `! Script replaces dialogue` row rather than deleting
  either. A variant's own `Script` runs after that variant's lines.
- **Visibility and writes.** `Visible When` uses the condition line grammar
  below; `After Talking` uses the world-write rows, applied by the game after
  each finished conversation.

Deleting is reference-safe: an NPC named by a `move_route` in a map event, in
another NPC's script or dialogue variant, or in a Common Event this map
triggers is refused, and the status lists exactly where. Shrinking the map
from the Inspector's `Size` rows is refused while it would strand an NPC or a
wander area, naming each one (`Ctrl+E` shows the list); move or resize them
first. Growing a map never touches an NPC.

Validation (`Ctrl+E` after a save, or `ichiloto validate`) checks every NPC
field against what the game does with it — malformed shapes the game would
drop, coordinates off the map, an NPC on an event tile it would make
unreachable, wander areas that leave the map or that the NPC starts outside,
a script shadowing dialogue, unknown fields — as errors where the authored
content cannot happen and warnings where it can but probably not as meant.

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
Map mode, the selected event's data in Event mode, the selected NPC in NPC mode
(see [NPC Mode](#npc-mode)).

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

A value longer than the pane wraps onto continuation lines indented under the
value column, so a long description or dialogue line reads in full; the row
being edited stays on one line and scrolls sideways around the caret. The pane
scrolls by rows, keeping the selected row's first line in view.

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

### Actors

An actor's Inspector opens with **Identity**: a `Definition Id`, which is
what a save resolves the actor by. A project that declares none is resolved
by display name, so the row says so, and renaming such an actor strands
every save that named it.

**Nature** is what this actor is, as distinct from the class it shares with
others: an adjustment per canonical stat, applied on top of the class
baseline. Adjustments keep their sign, because being slower than the
baseline is a legitimate nature, and an adjustment of zero is removed rather
than written.

An actor may also declare named *natural variants* — one set of adjustments
per variant, with a `Default Variant` the game starts on. The game
**composes** the two: the fixed adjustments always apply, and the selected
variant is added on top, summing where both name the same stat. So a fixed
`attack 4` under a variant's `attack 12` is `16`, and a variant may also
take away what the fixed layer gave. Both layers are therefore shown and
both are editable — `Fixed, always applied` and `Variant <id>, added on top`
— with an `In force` row showing what they come to together.
`Editing Variant` chooses which variant the rows show, and is a view of the
pane rather than a change to the project. A variant the actor does not
declare contributes nothing, which leaves the fixed layer standing.

**Resolved Stats** is what each stat actually comes to, computed by the
game's own resolver rather than by the editor: the class baseline, this
actor's nature, permanent growth the party has earned, what it is holding,
and whatever a battle is doing to it, then capped. A row reads
`33 · 10 natural, +12 nature, +6 growth, +5 battle · 966 to the cap`, and
says `41 lost to the 999 cap` when the total runs past the cap — which is
the case worth seeing, since further adjustments there do nothing. Player
and enemy caps differ, and the preview never writes anything.

`Assumed Growth` chooses which permanent growth the preview pretends the
party has already earned — none of it, all of it, or one definition. Earned
growth belongs to a save file rather than to a project, so this is a fixture
for looking at: choosing one changes what the rows read and writes nothing.

**Optimize Preview** shows what the game's Optimize command would pick for
this actor, best first, scored by the game's own policy rather than by the
editor. `Policy` names which policy is doing the scoring. `Slot` chooses the
kind of slot being filled. Each candidate reads as its score followed by the
components that made it — `18 · attack +12, speed -20` — and a candidate the
project excludes from automatic selection says so instead of scoring zero.

Accuracy and Critical are deliberately absent from nature and from the
resolved-stat preview: the game does not resolve them as layered stats. They
*are* weighable by Optimize, because a piece of equipment carries them.

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
| Items | `assets/Data/items.php` | Editable — authored as `new Item(...)` calls, edited entry by entry |
| Weapons | `assets/Data/items.php` | Editable — authored as `new Weapon(...)` calls, edited entry by entry |
| Armors | `assets/Data/items.php` | Editable — authored as `new Armor(...)` calls, edited entry by entry |
| Enemies | `assets/Data/enemies.php` | Editable — authored as `new Enemy(...)` calls, edited entry by entry |
| Troops | `assets/Data/troops.php` | Editable |
| States | `assets/Data/states.php` | Editable |
| Animations | `assets/Data/animations.php` | Editable |
| Tilesets | — | Read-only — the engine has no tileset system |
| Common Events | `assets/Events/*.php` | Editable |
| Quests | `assets/Data/quests.php` | Editable |
| Skits | `assets/Data/Skits/*.php` | Editable |
| Knowledge | `assets/Data/knowledge.php` | Editable |
| Knowledge Reports | `assets/Data/knowledge.php` | Editable |
| Knowledge Types | `assets/Data/knowledge.php` | Editable |
| Knowledge Enemies | `assets/Data/knowledge.php` | Editable |
| Permanent Growth | `assets/Data/permanent-growth.php` | Editable |
| Optimize Weights | `assets/Data/equipment-optimization.php` | Editable |
| Optimize Outcomes | `assets/Data/equipment-optimization.php` | Editable |
| Optimize Exclusions | `assets/Data/equipment-optimization.php` | Editable |
| System | `assets/Data/system.php` | Editable |
| Types | `assets/Data/Types/*.php` | Read-only — PHP enum declarations |
| Terms | `config.php` (`vocab`, `messages`) | Editable when the config carries no inline comments |

Why a category can still turn out read-only: a file the editor cannot
evaluate, a value it could not write back out, or a comment sitting inside
the returned data are each a reason, and the status line names it. You can
still browse everything: an enemy shows its level, every stat, its sprite,
its battle rewards, and its element affinities.

### How A File Of Constructor Calls Is Written

`items.php` and `enemies.php` are not data, they are PHP code that *builds*
data: `new Item(...)` and `new Enemy(...)` calls with named arguments,
imports, comments, and enum expressions the author chose. Regenerating such
a file from loaded values would reorder arguments, spell out defaults nobody
wrote, and rewrite every entry to change one. So the editor does not
regenerate it. It edits the author's own source, entry by entry:

- **A changed value** is patched where its argument sits. Every other byte of
  the file — the other arguments, the other entries, the comments between
  them — is the same afterwards.
- **A new entry** is written as a constructor call in the file's own
  indentation, after the last entry.
- **A deleted entry** is cut whole, with its separator.
- **A deleted entry put back** by undo goes back exactly where it was when
  the file has not been saved in between, and, when it has, is written back
  ahead of the entry that follows it in the list — so the file reads in the
  order the editor does.

Every entry is found by the identity it declares — an item's stable id, an
enemy's name — looked up in a fresh reading of the file at the moment of
writing, never by where it happened to sit when it was loaded. That is what
lets three categories share one file: Items, Weapons and Armors are three
views of `items.php`, and saving one of them, or all of them with `Ctrl+A`,
reads the file once, composes every dirty category's changes against that
one reading, and writes it once.

Where identity cannot prove the address, nothing is written and the status
line says why: two entries in the file declaring one id, an entry declaring
none, or a save that would leave two entries declaring one id. Give each
entry a distinct id, reload, and save again.

Files that are data — Troops, States, Permanent Growth — are regenerated as
data, keeping everything from `<?php` to the top-level `return` byte for
byte, and a file several categories share is folded from all of them into
one payload before its one write.

### Project-Owned Parameters

A weapon or armor's **Special Property** parameters and a Permanent Growth
definition's **Metadata** are the project's own vocabulary: the game carries
them without reading them. Both are edited as one line of `name=value`
pairs, and the line reads back exactly what was written:

- Pairs are separated by commas; spaces around names, values and commas
  mean nothing.
- Bare, `true` and `false` are booleans, `INF`, `-INF` and `NAN` are the
  floats digits cannot spell, digits are an integer, digits with a fraction
  or an exponent are a float — `1.0` stays `1.0` and `1.0E+20` stays exact —
  and anything else is the string it spells. A leading zero makes an
  identifier such as `007`, not a number.
- Anything that would read back as something else is quoted: the string
  `"true"`, the string `"1.0"`, an empty string `""`, a value with a comma,
  an equals sign or a space at either end, and a name with any of those.
  Inside quotes exactly two escapes exist, `\"` for a quote and `\\` for a
  backslash; any other backslash is refused rather than silently dropped.
- Nested lists and maps do not fit on the line. They are not shown, and they
  are not touched.
- A line the editor cannot read — a name without a value, a value with a
  stray quote, an unknown escape — is refused with the reason on the status
  line, and the record is left exactly as it was.

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

### Knowledge

What the party can come to know, in `assets/Data/knowledge.php`. The file
holds several lists and the vocabularies they share, so the editor opens it
as two categories and writes back everything it did not edit exactly as it
read it.

**Knowledge** authors the subjects. A subject is anything the party can
learn about: a creature, a person, a place, a practice. Nothing here needs
an enemy — a subject the party never fights is an ordinary record — and
defeat is not the only outcome a record can have. Each subject has a stable
`Id`, a `Record Type` chosen from the kinds the project declares, a display
name, a `Quick Card` for what is known at a glance and an optional
`Deep Card` for what is known in full, optional `Family` and `Species`,
comma-separated `Tags`, `Habitats` and `Observations`, a `Display Order`,
and `Hidden Until Discovered` for a subject that should not be listed until
something discovers it. `Relationships` is a sub-list: a type, and the
related subject picked from the catalogue.

**Knowledge Reports** authors claims about those subjects: a stable `Id`,
the `Subject` it concerns, a title and summary, optional details, a display
order, and the reports it disagrees with — each one a sub-list row picked
from the project's reports rather than spelled, because a disagreement
spelled by hand is a disagreement with nothing. The file keeps the flat list
of ids the game reads.

**Knowledge Types** authors `recordTypes`: the kinds of record a subject can
be. A subject's `Record Type` is picked from what this declares, so this is
where a new kind comes from.

**Knowledge Enemies** authors `enemyMappings`: which subject an enemy is a
record of. Both sides are picked — the enemy from the project's enemies, the
subject from its knowledge subjects. Knowledge does not require an enemy;
this is only for the subjects that are fought. A mapping missing either side
is kept out of the file, because the game refuses the whole catalogue over a
subject id it cannot read.

All four categories share one file, and saving any of them writes back
everything the others own exactly as it was.

A story event unlocks, amends, withdraws or supersedes a report through the
`knowledge` command. The command asks for exactly what its operation reads
and nothing else:

| Operation | Asks for |
| --- | --- |
| `discover` | subject, source |
| `observe` | subject, observation, source, confidence |
| `unlock_report` | subject, report, source, confidence |
| `amend_report` | subject, report, source, confidence |
| `record_outcome` | subject, outcome |
| `withdraw_report` | subject, report, source |
| `supersede_report` | subject, report, replacement, source |

The operations themselves come from the game's own list, and validation
checks what the game checks: that the operation exists, that required fields
are filled, that confidence sits between 0 and 1, that a report belongs to
the subject it is named with, that an observation is one the subject
authors, and that a report is not superseded by itself.

Runtime progress — what has actually been discovered, observed or unlocked
— lives in a save, not here. This is the catalogue those records point at.

### Permanent Growth

Stat increases the party can earn and keep, in
`assets/Data/permanent-growth.php`. A definition has a stable `Id` (what the
game grants and what a save records), an optional `Label` for recognising it
here, the `Stat` it moves, a signed `Amount`, and its provenance: a
`Source Type` for what kind of thing granted it and a `Source Id` for which
one. Both are deliberately open — the game imposes no vocabulary of sources,
because it does not know what a project grants growth from. A `Note` may be
added, and any other project-owned metadata authored by hand is carried
through untouched.

Growth can be a loss: an amount of `-3` is as valid as `+25`, and a curse is
the same contract as a blessing.

Two definitions may share an id only if they are otherwise identical, which
the game treats as one grant already made. Two that disagree over one id is
an error the game raises the moment the second one is granted, so validation
raises it here first.

**What is not authored here.** The growth a party has actually *earned* is
save state, in the game's permanent-growth ledger, and the editor does not
edit save files. **This engine version grants growth through runtime API
only — there is no event-script command for it** — so a project defines what
a grant would be and the game decides when one happens. Authoring the grant
itself is deferred until the engine offers a project command for it; the
Actors Inspector's `Assumed Growth` row is a preview fixture, not a grant.

### Optimize

What the game's Optimize command values, in
`assets/Data/equipment-optimization.php`. One file, opened as three
categories, each writing back everything it did not edit exactly as it read
it. A project that declares no policy does not get "no policy": it gets the
engine's legacy equal-weight sum, which is a compatibility fallback and says
nothing about what the project wants. The Actors Inspector says which of the
two is scoring.

**Optimize Weights** authors what each stat is worth, as one record per
weight vector. `Scope` chooses how far the vector reaches, and the game
applies them in this order, each replacing the last:

| Scope | Applies to | Asks for |
| --- | --- | --- |
| base | every character and every slot | the weights |
| role | characters of one class | `Role` |
| slot | one kind of slot | `Slot` |
| role+slot | one class filling one kind of slot | `Role` and `Slot` |

A vector weighs the nine canonical stats plus `accuracy` and `critical`,
which equipment carries even though neither is a resolved stat layer. A
weight of zero is removed rather than written.

**Optimize Outcomes** authors what an element or a special property is
worth. The game looks these up by composed name, so the parts are picked and
the name is composed for you:

| Kind | Means | Composes |
| --- | --- | --- |
| offence | dealing this element | `offence:<element>` |
| defence | this outcome against this element | `defence:<element>:<outcome>` |
| special | carrying this kind of special property | the property's own type |

`Element` may be a specific element or `*`, which matches whichever element
an outcome happened to be. An outcome is one of `weak`, `resist`, `null`,
`absorb` or `neutral` — what the game derives from an affinity multiplier. A
name that matches neither shape is shown as authored and kept, and
validation says the game will never look it up.

**Optimize Exclusions** authors what automatic selection may not take. Each
exclusion picks one thing, from the vocabulary its `Kind` implies: an item
by definition id, an `availability`, or an `acquisition policy`. The last two
are the words the project's own equipment uses; the editor offers what the
project has said rather than a vocabulary of its own.

Validation checks the part the game cannot: whether a weight, an outcome or
an exclusion names something this project actually has. One that does not is
never looked up, and reads exactly like a policy that is working.

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
| `knowledge` | Operation, Subject, Report, Replacement Report, Observation, Outcome, Source, Confidence |
| `move_player` | X, Y |
| `move_route` | Subject, NPC Id, Wait, Seconds Per Step, Speed, Steps |
| `transfer` | Map Id, X, Y |
| `start_battle` | Troop, Result Variable, Defeat Policy, Escape Policy |
| `recover_party` | None |
| `branch` | Conditions, Then (fixed), Else (fixed) |

A `knowledge` command records what the party has learned. `Operation` is
chosen from the vocabulary the runtime itself defines, and each operation
reads only the fields it needs, so the others are left empty and are not
written:

| Operation | Records | Needs |
| --- | --- | --- |
| `discover` | that a subject is now known at all | Subject |
| `observe` | one authored observation of a subject | Subject, Observation |
| `unlock_report` | that a report is readable | Subject, Report |
| `amend_report` | a revision of a report already unlocked | Subject, Report |
| `record_outcome` | an outcome the subject reached | Subject, Outcome |
| `withdraw_report` | that a report no longer stands | Subject, Report |
| `supersede_report` | that one report replaces another | Subject, Report, Replacement Report |

`Subject`, `Report` and `Replacement Report` are picked from the project's
own knowledge catalogue. `Source` says what taught the party (`story.event`
when left empty) and `Confidence` is a fraction from `0` through `1`,
defaulting to certainty. Authored card and report text lives in the
project's knowledge records; a save keeps only what was learned.

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
Esc pops exactly one frame; at the top it closes the Database as before. An
arm with no commands yet opens all the same, showing one `No commands yet`
row; Shift+O there adds the first. A `move_route` inside a frame owns its
route steps exactly as one at the top level does: Shift+O on any of its rows
adds a step, Shift+X on a step row removes that step.

Shift+O with the cursor on an option row adds an option to that choice;
removing an option takes its whole arm with it, and undo puts both back.

Skipping, camera operations, screen transitions, title cards and narration,
field animations, staged actors, parallel lanes and checkpoints belong to
*cinematics*, which have their own screen: see [Cutscenes Screen](#cutscenes-screen).
A Common Event stays a reusable command list, and may be called from a
cinematic with `common_event`. The engine has no NPC patrol routes or
pathfinding to author (NPCs stand still, wander, or follow a `move_route`).

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

## Cutscenes Screen

`F4` opens the Cutscenes screen over the main shell, the way `Ctrl+D` opens
the Database; `F4` or `Esc` closes it, `Ctrl+D` from inside goes straight
across to the Database, and `Ctrl+P` offers `Cutscenes: Cinematic` and
`Cutscenes: Summon`. Five panes: **Types** (Cinematic or Summon), the asset
**list**, the **record pane** (the asset's fields, grouped), the **Command
Tree** (for a summon, the **Timeline**) and the **Preview**. `Tab` and
`Shift+Tab` cycle the panes; `/` filters the list; `Shift+A` creates,
`Shift+D` duplicates, `Delete` removes (with the places that reference the
asset named first); `Ctrl+S` saves the selected asset, `Ctrl+A` saves every
dirty map, database and cutscene; `Ctrl+Z` / `Ctrl+Y` undo and redo every
mutation, pinned to the asset it happened on.

Four things look alike and are not. Keep them apart:

| Asset | What it is | Where |
| --- | --- | --- |
| Common Event | a reusable command list, called by id from maps and cinematics | Database › Common Events |
| Cinematic Cutscene | a staged story sequence: cast, camera, parallel lanes, skip, finalizer | Cutscenes › Cinematic |
| Summon Cutscene | a frame-driven battle presentation: tracks, keyframes, cues | Cutscenes › Summon |
| Skit | an optional conversation overlay | Database › Skits |
| Animation | a reusable visual asset a command plays | Database › Animations |

### Files, identity and preservation

A cutscene is one folder holding a pair of files, named after the folder:

```text
assets/Cutscenes/Cinematics/<id>/<id>.data.php
assets/Cutscenes/Cinematics/<id>/<id>.script.php
assets/Cutscenes/Summons/<id>/<id>.data.php
assets/Cutscenes/Summons/<id>/<id>.timeline.php
```

The stable id *is* the folder name; the display name is a field. A new
asset's id is yours to choose until its first save makes the folder; after
that it is read-only (duplicate under the new id and delete the old one to
migrate). The list shows `*` for unsaved work and a folder the engine cannot
read as read-only, with the reason in the record pane; an orphaned file, a
misnamed pair, an id that disagrees with its folder, or two folders that
differ only in case are reported by validation and in the list.

Saving rewrites only what changed, in place: headers, comments, local
variables, heredocs and nowdocs, shared ASCII blocks, unknown keys,
backslashes, trailing spaces and wide glyphs all survive, a data-only edit
leaves the script or timeline file byte-identical, a no-op save writes
nothing, and multi-line text is written as a nowdoc. A value shared through
one source variable is edited in place when only the selected entry uses it,
and otherwise retargeted to a new variable so nothing else changes. A save is
one transaction over the pair: both sources are built, evaluated, hydrated
through the engine (and, for a summon, compiled), written to temporary
files, backed up, and then swapped in; if any step fails neither file
changes.

### Cinematics

The record pane groups the engine's cinematic definition: **Identity** (id,
name, description, version), **Staging** (start map, initial presentation,
reduced-motion policy), **Script** (the command list, opened as a frame),
**Skip** (policy, checkpoints, the finalizer as a frame), **Metadata**
(authoring) and **Cast**. Cast rows are `player`, `party_actor`, `npc` (a
stable map-local id on the start map) or `staged_actor` with every engine
field: id, sprite or asset, x and y, facing, visible, collision and
directional sprites. `Shift+O` on a cast row adds a member; `Delete` removes
one.

#### The command tree

The script is a nested tree. Open `Commands` (or `Finalizer`) from its row,
or press `Enter` on any row of the Command Tree, and the record pane shows
that list as a frame: each command contributes a `Command N Type` row plus
the rows its type uses, nested blocks appear as rows that open their own
frames, and the pane title is the trail back out (`Commands › Parallel 3 ›
Lane 2`). `Esc` pops exactly one frame.

| Type | Fields |
| --- | --- |
| `sequence` | Commands (a frame) |
| `parallel` | Lanes, each a stable id with its own Commands frame; the block completes when every lane has |
| `branch` | Conditions, Then, Else (frames) |
| `choice` | Prompt, Title, Options (each with a Commands frame), Cancel (frame) |
| `common_event` | Common Event (picker) |
| `checkpoint` | Checkpoint name (from the cinematic's declared checkpoints) |
| `camera` | Operation (`detach`, `attach`, `reset`, `focus`, `pan`, `route`, `track`, `shake`, `restore`), Target (kind, id or x/y), Seconds, Magnitude; a `route` owns Points |
| `stage_actor` | the staged-actor fields |
| `show_actor`, `hide_actor`, `remove_actor` | Actor (from the cast) |
| `field_animation` | Animation, Target (kind, id or x/y), Seconds Per Frame |
| `title_card`, `narration` | Title, Text (multiline), Seconds |
| `transition` | Style (`fade`, `wipe`, `none`), Direction (`in`, `out`), Seconds |
| `clear_presentation` | none |
| `cinematic_music` | Track, Loop, Fade In, Fade Out, On Completion (`continue`, `stop`, `restore_previous`) |
| `move_route` | Subject (`player`, `npc`, `staged_actor`), NPC Id or Staged Actor, Seconds Per Step or Speed, Wait, Steps |

Every other type from the Common Events table is available too, with the
same rows. `Shift+O` adds: on a route, lane or point row, another step, lane
or point; on a command that has none of those yet, its first; otherwise the
next command. `Shift+X` removes the last entry, `Delete` the selected one.

The Command Tree is a projection of the same tree. Its rows fold (`Space`,
`-`, `+`), open a frame (`Enter`), and move the command under the cursor:
`[` / `]` reorder within its list, `>` nests it into the block just above
(a sequence's commands, a parallel block's last lane, a branch's then arm,
a choice's first option), `<` moves it back out after that block, `Shift+O`
inserts a new command after it, `Shift+D` duplicates it, `Delete` removes
it. Every one of these is undoable, and a running preview marks the
commands its lanes are on with `▶` and a failure with `✗`.

#### Lanes and the duration overview

`V` on the Preview pane shows the duration overview: every command, lane and
block with the time it is authored to take, using the engine's own defaults
where a field is unset (a wait of 0.5s, a route step of 0.15s, a narration
of 2.5s, a transition of 0.24s). A parallel block is as long as its longest
lane; a branch or choice as its longest arm. Dialogue, choices, battles and
common events cannot be timed from the asset and are marked `+input`,
`+battle` and `+?` instead of guessed. `Up` / `Down` move over the rows and
`Enter` opens the row's command in the tree; a running preview marks the
rows it is on.

#### Staging and preview

The Preview pane plays the cinematic **through the engine itself**. Nothing
is simulated: the editor builds an isolated scene (a fresh game state, an
empty party, the start map's tiles, collision and NPCs read from your
project, a camera that draws into the pane) and hands the asset, as it
stands in memory and unsaved, to the engine's cinematic controller and
event interpreter. The frame you see is what the engine draws: the map,
NPCs, the player, staged actors, title cards, narration, transitions.

| Key (Preview focused) | Action |
| --- | --- |
| `Space` | Start the preview playing; pause or resume it |
| `.` | Step one tick (0.1s of cinematic time) |
| `K` | Skip, at a legal point, through the authored finalizer |
| `R` | Restart from the beginning |
| `X` | Stop (the engine runs its failure cleanup) or close a finished preview |
| `J` | Jump the tree and record pane to the command that failed |
| `C` | Compare a watched run with a skipped one |
| `V` | The duration overview |
| `L` | Cycle the views: stage, lanes, log, overview, comparison |
| `Enter` | Continue waiting dialogue; confirm a choice (`Up` / `Down` choose) |
| `Ctrl+T` | Playtest the saved cinematic in the real game |

The stage shows, beside the frame, the session's lanes and what each is
doing, the checkpoints recorded, whether a skip would be accepted and why
not, and the log of launches, transfers, battles and failures. Dialogue
waits for you as it would for the player; a battle resolves as a victory
on the next step (the pane says so); a transfer loads the destination map.
The preview starts from the map event that triggers the cinematic when one
exists, otherwise from the start map at its first open tile. It writes
nothing: not your saves, not your files.

`Ctrl+T` plays the saved cinematic in the real game through the playtest
overlay: the start map is copied into the throwaway root with one extra
event, an automatic single-use `CinematicEventTrigger` on the spawn tile,
so the game launches the real asset through its real trigger the moment
the playtest begins. Your map files are not modified.

#### Skip and the finalizer

Skip is a second ending, not a cancel. When the policy is `authored` and a
finalizer is written, the engine cancels every lane and pending operation,
clears temporary presentation, and runs the finalizer once through the same
interpreter, so a skipped run and a watched run reach the same final map,
player position, camera mode, cast cleanup, completion event and state
writes. The engine accepts `authored` only when every reachable path is safe
to abandon (no `start_battle`, `give_item`, `give_gold`, `accept_quest`,
`recover_party`, `knowledge` or `common_event` on the way), and the
finalizer uses its restricted vocabulary: `set_switch`, `set_variable`,
`record_event`, `move_player`, `transfer`, `camera` (`attach` or `reset`),
`remove_actor`, `clear_presentation` and `cinematic_music`, each with its
explicit shape. The Skip group of the record pane says which road the
current policy takes; `C` on the Preview pane runs the cinematic twice —
watched to the end and skipped at once — and lists every observable
difference between the two final states, so an unfinished finalizer shows
up as a row rather than a surprise.

#### Map triggers

A map event of type **Cinematic** (`CinematicEventTrigger`) launches a
cinematic by stable id: `cinematicId` is picked from the project's
cinematics, `mode` is `auto` (on arrival) or `action` (the field action),
and `reusable` says whether it fires again. Conditions, sets, the blocked
message and the cue work as for any event. Validation checks the trigger
against the engine's contract.

### Summons

The record pane groups the engine's summon definition: **Identity** (id,
name, description, move name, version, linked summon id, linked action
picked from the project's skills, tags), **Lore** (lore, element,
strengths, weaknesses, free-form attributes, authoring metadata),
**Availability** (conditions through the shared condition editor; an
omitted policy is open and is written as nothing), **Wielders** (mode
`all`, `roles` or `characters`, with roles picked from classes and
characters from actors; tenancy `shared` or `exclusive`), **Playback**
(default speed, allow skip, loop preview, transitions in and out, effect
timing by `end`, `cue` or `frame`, target presentation) and **Timeline**
(format version, FPS, length in frames, editor metadata, and the Tracks
and Cues frames). `allowSkip` is authored data; whether a battle honours it
is the engine's business, and this manual claims nothing more.

#### Tracks, keyframes and cues

Open `Tracks` and each track is a row (`Id`, `Type`: `glyph`, `text`,
`flash`, `shake`) followed by its keyframes: frame, duration, position,
content, asset id, color, visible, z-index, blend mode, easing and a
free-form payload. `Content` opens the **multiline editor** (`Enter` on the
row), which keeps every space, backslash, blank line, tab and wide glyph
exactly as typed or pasted; `Ctrl+S` there commits, `Esc` cancels. Open
`Cues` for the cue rows: id, frame, type and payload. `Shift+O` adds a
track, a keyframe under the cursor's track, or a cue; `Shift+X` and
`Delete` remove. The Timeline pane lists tracks, keyframes and cues as
rows: `[` / `]` reorder, `Shift+D` duplicates (a copied track or cue gets a
free id), `Shift+O` inserts after, `Delete` removes, and `+` / `-` on a
keyframe or cue row nudge its frame by one.

#### Summon preview

`Space` on the Preview pane compiles the summon as it stands, unsaved,
through the engine's compiler and plays it through the engine's
non-blocking playback session: the playhead, frame clock, active segments
and cue schedule are the engine's. The pane draws each frame as the battle
field would (position, content lines, an `[ASSET]` placeholder for an asset
reference, visibility), with a ruler across the timeline, the keyframe bars
per track, `◆` for cues and `▼` at the playhead; beside it, the frame
counter, the cues on this frame, the cues the playhead has crossed (the cue
log — audio cannot be hosted here, so a cue is reported, never claimed
audible), and what is drawn now.

| Key (Preview focused, summon) | Action |
| --- | --- |
| `Space` | Play or pause |
| `.` / `,` | Step forward or back one frame |
| `<` / `>` | Jump to the previous or next keyframe or cue boundary |
| `Home` / `End` | Seek to the first or last frame |
| `+` / `-` | Speed: 0.25×, 0.5×, 1×, 2×, 4× |
| `O` | Loop or play once |
| `R` | Restart |
| `L` | Timeline view (ruler and active segments) or stage view |
| `X` | Close the preview |

Stepping and seeking are inspection: the engine repositions the playhead
without pretending the frame was traversed, so cues fire only when playback
crosses them. Reduced-motion settings never alter authored data.

#### Actor summon assignments

An actor's starting summons are a `Summons` row in the Actors category: a
multi-pick over the project's summons where each pick toggles a member in
or out, undoable and dirty-tracked, written as a list of stable ids (and
removed entirely when emptied). Under it, one verdict row per assignment,
judged by the same rules the validator applies: the summon must exist, the
actor must be eligible under its wielder policy (by character, by role, or
open to all), a story-locked summon cannot be a starting assignment, each
id appears once, and an exclusive summon has at most one starting holder
across the cast.

### Validation

`ichiloto validate` and the editor's validation cover both forms: missing or
malformed files, pairs and identities; what the engine refuses when it
hydrates a cinematic (structure, nested shapes, cast, skip policy,
finalizer vocabulary and skip safety) or compiles a summon (FPS, length,
tracks, keyframes, cues, effect timing), reported with the engine's own
message and path; and the references the engine only meets at play time:
start maps, transfer maps, music tracks, animations, common events,
declared checkpoints, cast and staged actors, NPC ids on the map the
cinematic is on, and the cinematic map triggers. Unknown forward-compatible
fields are preserved and are not errors.

### Limitations

- The staging canvas is the engine's own frame: staged actors, NPCs, the
  player and the camera viewport are seen where the engine puts them at
  the playhead, and positions are edited in the cast and command rows.
  Route points, camera targets and transfer targets are not drawn as
  overlays on the map canvas.
- A battle inside a previewed cinematic is resolved as a victory on the next
  step, and the pane says so; the real battle is a playtest away.
- A saved asset's id cannot be renamed in place; duplicate and delete.
- The duration overview is an estimate from authored seconds; the engine's
  clock during preview is the truth.

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
`Ctrl+E` shows the full text. The project-wide pass (`ichiloto validate`) also
covers every NPC field, as described under [NPC Mode](#npc-mode).

An inventory id two definitions claim is reported once, naming every
claimant with the category it was authored in, the aliases it brought, and
its entry in `items.php` — the game refuses the whole catalogue until one
of them is renamed, and until then nothing under any claimant is offered by
a picker, resolved by a reference, or accepted as a save alias target.

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
