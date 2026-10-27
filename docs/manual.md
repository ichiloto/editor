# Ichiloto Editor Manual

This document is a living guide to the Ichiloto editor.

It tracks the editor as it exists today, including current keybindings, panel
workflows, Database categories, and known limits. Update this file whenever
the editor gains new tools, panels, controls, or shortcuts - a test in
`tests/Unit/ManualCoverageTest.php` fails if a registered keybinding is not
documented here.

For task-oriented walkthroughs, start with [docs/guides/README.md](guides/README.md).

## Starting the Editor

The editor opens one Ichiloto project - a directory containing `ichiloto.json`.
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
terminal settings on exit - including after a crash.

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
 │                              │ │ ############               │ │   Kind: Not set                │
 │                              │ │ #  ~~~     #               │ │   Description: A tiny fixture  │
 │                              │ │ #          #               │ │                map.            │
 │                              │ │ #          #               │ │   Size                         │
 │                              │ │ ############               │ │     X: 12                      │
 │                              │ │                            │ │     Y: 5                       │
 │                              │ │                            │ │   Events · 1                   │
 │                              │ │                            │ │   Triggers · 0                 │
 │                              │ │                            │ │   Audio                        │
 │                              │ │                            │ │     Background Music: (None)   │
 │                              │ │                            │ │     Music Variants · None      │
 └─/:Filter  Del:Delete─────────┘ └─i:Paint L:Layer ?:Help─────┘ └─Enter:Edit─────────────────────┘
 ┌─Status─────────────────────────────────────────────────────────────────────────────────────────┐
 │ Selected map: test-map | Focus: Assets | Mode: Map | Tool: Brush 1                             │
 │ Cursor: (0, 0) | Viewport: (0, 0) | Ready.                                                     │
 └─Ctrl+P:Palette  Tab:Pane  Ctrl+S:Save  Ctrl+A:Save All  Ctrl+Z:Undo  Ctrl+Y:Redo  Ctrl+Q:Quit──┘
```

- `Assets` lists every map discovered under `assets/Maps`.
- `Canvas` previews and paints the selected map.
- `Inspector` shows the fields of whatever is selected.
- The `Status` footer carries the current selection, mode, active canvas tool,
  and, on its second line, the cursor, the viewport, and the most recent
  message.

A message's colour says what happened: blue for an update, including every
edit, which stays in memory until you save; green only when something reached
disk (a save, or a command that writes files at once) or a check passed;
yellow for a warning; red for an error. Edits never show green, so green
always means saved, and the header's `*` shows what is still unsaved.

Each panel writes its own keys into its bottom border, and shortens them on a
narrow terminal rather than cutting one in half - so what a panel offers is
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

Every selection list wraps: `Down` on the last row selects the first, and
`Up` on the first row selects the last. This holds for the asset list, the
Database categories, entries and settings rows, dialog and picker lists, the command palette, option-value cycling, and the Cutscenes
lists. Map cursors, scrolling panes and text editing keep ordinary bounds -
wrapping is for choosing from a list, not for walking a canvas.

## Global Shortcuts

These work from any panel. They are control bytes on purpose: no shortcut
steals a glyph you might want to paint on a map. Printable keys carry meaning
only in the canvas's Normal mode (see [Canvas Panel](#canvas-panel)); in
Paint mode every printable key, `?` included, paints its glyph.

| Key | Action |
| --- | --- |
| `?` | Toggle the help overlay (in Paint mode, `?` paints like any glyph) |
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
- a connected piece's anchor is dropped, then piece placement ends, a pending
  canvas anchor is dropped, then a selection is cleared
- a filter query is cleared before its list closes
- an overlay or dialog closes
- the Database screen closes

`Esc` is the only cancel key. On yes/no prompts, `n` is an explicit "No".

## Assets Panel

The Assets panel lists every map in the project, sorted by map id. A dirty map
carries a trailing `*`.

Controls:

- `Up` / `Down`: move the selection
- `Shift+A`: create a new map, asking its [kind](#map-kind) first
- `Shift+D`: duplicate the selected map, kind included
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

### Authored Layers

The TUI edits the terminal experience. Maps with `layers/` show numbered
gameplay (`NN.name.map.php`) layers and Events, with NPC overlays in their
authoring context. Graphical decoration (`NN.name.deco.php`) never appears on
the terminal canvas or in its layer selection, palette or inspector. In Normal
mode, `L` opens the layer picker: the map's gameplay layers and Events, each
marked visible or hidden and the one being edited, and last NPCs, which enters
NPC mode. Type to filter, move with the
arrows or `j` / `k`, and press `Enter` to edit that layer (`Esc` keeps the
current one). The canvas title shows the layer being edited, and only that
layer is painted. `[` and `]` still step to the previous or next layer, `v`
toggles the selected layer's visibility, and `d` dims inactive layers. Spaces
above the base show through. Visibility and dimming are session settings, not
map data.

The normal canvas is editable immediately: press `i` and paint terminal glyphs.
The separate read-only terminal-preview toggle has been removed; there is no
graphical-marker view to switch away from.

Use `Ctrl+P` and choose a Layers action to create, rename or remove a gameplay
layer. The TUI cannot create,
select, rename or remove graphical decoration layers. New gameplay layers
preserve each row's width, including ragged
maps. Renaming retains its numeric order. If the actual resolved collisions would
change, the rename dialog names the change and requires `y` confirmation;
shared `collisions.php` is never changed. Removal is confirmed and undoable.
Creating a layer on a legacy map explicitly moves its terrain into
`layers/00.terrain.map.php` in the same save transaction; ordinary legacy
saves keep the original layout.

The GUI offers **Separate collision** for an undeclared map. After explicit
confirmation, the shared session captures its current resolved physical cells
without changing glyphs, artwork or passage. This is one undo step, written only
on save; opening or saving a legacy map never converts it automatically. Once
converted, terminal appearance and layer edits no longer choose collision.
Resize and row/column insertion and removal preserve the declaration with
geometry, adding `SOLID` physical cells independently of blank glyphs. Malformed or unsupported
source edits refuse before mutation. The TUI receives no graphical conversion
control; it preserves the declaration through its existing edit/save/history
workflow.

The GUI **Collision** tool displays the shared physical cells over either
presentation. On converted maps its constrained collision-type picker and the
existing pencil, line, outline, filled-rectangle and fill brushes edit passage
as one undo step without changing glyphs or tiles. The overlay's numbers match
the picker; right-click picks the physical cell's type. Fill follows equal
physical cells and does not bridge missing cells in ragged rows. Unsupported
types, stale revisions, malformed declarations and source-preservation failures
refuse before any change. Escape, map/tool changes and brush changes retire
pending region answers. Conversion remains explicit on legacy maps.

In the GUI tileset inspector, **Physical footprint** is an optional reusable
recipe, not an automatic consequence of placing a picture. Its cells use final
collision types or **Leave unchanged**; adding a recipe initially leaves all
cells unchanged. Edit it by clicking the piece's cells over its art in the
tileset view's Collision mode, or through the Physical Footprint record row,
then save the tileset. Placing the piece in the GUI's Tiles tool sets its
footprint in the same undo step while **With its collision** is checked, on a
map with its own collision; unchecked, placing leaves collision as it is. The
Collision tool's **Object footprint** mode selects a saved recipe and stamps it
alone with one click as one undo step: only map occupancy changes, never glyphs
or tiles. Every non-null cell must fit the actual map, including ragged rows,
or the entire stamp refuses. Stale map/recipe values and unsupported source
expressions refuse before mutation. Removing a recipe does not erase collision
already stamped into a map. The TUI preserves recipes through existing record
edits without adding graphical authoring controls, and its piece stamps leave
collision as it is. Native acceptance of the physical brushes and footprint
controls remains open.

A stamped piece whose art paints doors, such as a building, names them in its
**Painted Doors** record row: each door's cell from the piece's top-left cell,
with `front` for a door entered from the cell before it, separated by
semicolons (`2,4; 4,4 front`), or clicks them on the piece's art in the GUI
tileset view's Doors mode. Validation then requires every copy of the piece
standing whole on a map to meet an entrance at each door, and leaves those cells
to the doorway rule rather than judging their look against passage. The pieces
list reports each piece's doors. The TUI preserves them through record edits.

The GUI tileset view lays each upper sheet (B to E) out as its image is,
sixteen cells across, so a piece as wide as the sheet is one selection; the A
tab keeps RPG Maker's palette layout. Its **Add art** mode copies another
image's cells, such as a building delivered on a sheet of its own, into free
cells of a sheet, pixel for pixel: choose the image (the image picker's
**Import image** brings one in from the computer), drag across its cells, and
click the sheet cell where their top-left goes. Cells that already hold art
are shaded and never drawn over, B's first cell is the empty tile, and every
other pixel of the sheet stays as it was. The sheet is written at once, not on
Save; Undo puts back its exact bytes, and refuses when the file was changed
outside the Editor since. The copied cells come back selected. **Define piece**
then makes a new piece from the selected cells (its name, the gameplay layer
its glyphs go on and the tile layer its tiles go on), with a blank glyph in
every cell, and shows a stamped piece's cells over its art: Glyphs (click a
cell, type its character, Enter; its colour is the brush's), Collision and
Doors. Each edit is one undo step through the tileset record's own rows.
**Above characters** marks a dragged rectangle, such as a roof, in one step.
None of this is offered by the TUI, which edits the same rows as text.

A building or other stamped piece on a map is found by its tiles, as the
doorway rule finds it. Clicking one of its cells in the GUI shows it in the
inspector's **Placed piece** section: where it stands, each door its art paints
with the cell it is entered from and the entrance there, and **Add entrance**,
**Move** and **Remove**. The canvas outlines it, fills its doors and marks each
entrance cell, filled when an entrance stands on it. Add entrance places a
transfer on the cell in front of a `front` door (on the door itself for one
walked onto) and shows it, so its Destination Map, Spawn Point and Spawn
Facing rows choose where it leads, the arrival cell and the facing there. Move carries the piece until a click puts
its top-left down; its glyphs, its tiles, with **With its collision** its
footprint, and the entrances meeting its doors go with it, as one undo step.
Remove takes away its glyphs where they are still its own, with the tiles they
carry, its other tiles, and with its collision the footprint's cells, which
take the collision the glyphs left on them give, as Separate collision would
have read them. Cells a piece covered keep whatever lies beneath on other
layers.

The GUI tileset view's **Appearance** mode sets what tiles show at ground
level, for validation alone: choose Ground, Water, Barrier or Overhead (art
over the player, or a light decoration that says nothing about the ground),
then click a tile or drag over several; **Sheet default** takes a tile's own
class away, and **Whole sheet** sets the selected tile's whole sheet. Each is
one undo step through the tileset record's Appearance rows. Badges show each
tile's class, filled when authored and outlined when its sheet kind's default
decides; `?` marks a tile no class covers yet. Passage never changes. The TUI
preserves the classes through record edits without offering the graphical mode.

Gameplay and event layers use the same Paint/Normal modes, tools, mouse strokes,
selection, colour, clipboard and undo. The layer inspector lists terminal layer
visibility only. Ordinary glyph edits do not rewrite graphical decoration.
Glyph-keyed crop tables (`tiles2d`) are no longer read by the Engine or edited
here; a map that still has one keeps its bytes, and validation warns that it is
no longer read. Canonical source safety, collision and grid checks remain in
force for terminal edits. Untouched layer files are never written; unchanged
rows retain their authored bytes, and all changed files are saved or rolled
back together.

### Inserting and removing rows and columns

In Map mode, `Ctrl+P` offers **Map: Insert rows above the cursor** and
**Map: Insert columns left of the cursor**. Both first refuse while the project
has unsaved changes: save or undo them first. A prompt asks how many to insert
(a whole number of at least 1, `1` by default; `Up` / `Down` step it, `Enter`
plans, `Esc` cancels). Inserting `N` rows at the cursor's row moves everything
from that row down by `N`; inserting columns moves everything from the cursor's
column right. The map grows by `N`.

Nothing is written until you confirm. The confirmation lists every file the
insertion writes, then every coordinate it could not rewrite, as
`Hand edit: file path` with the reason, then whether saves follow. `Enter` on
**Write N files now** writes them all at once, not on Save; `Esc` or **Cancel**
leaves every file unchanged. One `Ctrl+Z` restores every written file byte for
byte and reloads the workspace; `Ctrl+Y` writes them again.

What moves, when it is in the map's space and at or beyond the line:

- every grid: gameplay and decoration layers, the event layer (so event areas
  follow) and the tile layers, whose new cells are empty (`0`); styled cells
  keep their colour and rows that only move keep their bytes;
- the map's NPC positions and wander areas (an area straddling the line
  stretches), bed spawn points, legacy trigger areas and explicit event areas;
- spawn points of transfers into the map from any map, legacy triggers into
  it, and `startingPositions` in `assets/Data/system.php` that start on it;
- script coordinates on the map: `move_player`, `move_route` waypoints (only
  the axes a waypoint names; `retrace` is relative, and relative `steps` are
  listed as a hand edit because a walk across the line ends elsewhere), `camera` and
  `field_animation` position targets, `stage_actor`, and `transfer` into the
  map. Scripts start on their map; after a `transfer` elsewhere, later commands
  are in that map's space and stay. A waypoint, staged actor or cast member
  placed relative to a subject (`at` with an optional `offset`) is never
  rewritten: it follows its subject. When the edited lines fall between the
  subject and the point, or may (a player or staged actor subject), its
  offset is listed as a hand edit;
- reusable scripts in `assets/Events/` started only from this map, and
  cutscenes whose start map it is (their cast, script and finalizer).

A regional `station` and tile-layer `offset` values are not map cells and never
move.

What is reported instead of rewritten: a coordinate written as a PHP expression
or variable, or inside a list built with a spread (such as generated NPCs); a
file that is not one returned array literal; a coordinate in a reusable script
or cutscene that may run on this map or another one. The editor never guesses
or flattens authored PHP; move those by hand after writing.

Saves follow through the project's `assets/Data/save-compatibility.php`: the
insertion raises `contentVersion` by one and appends a `mapShifts` step, so a
saved player position on the map moves too. A project without that manifest is
told that existing saves are not migrated.

**Map: Remove rows from the cursor down** and **Map: Remove columns from the
cursor rightward** take the same count prompt and confirmation. Removing `N`
rows from the cursor's row deletes those rows from every grid and moves
everything below them up by `N`; columns move left. The map shrinks by `N` and
keeps at least one line.

Nothing authored may stand on a removed line: an event marker, an NPC, a
spawn point or transfer into the map, a starting position, a world object's
covered cell, or a script coordinate surely on the map. The removal is refused
before anything is written and the refusal names each one, so move them first.
An area that straddles the removed lines shrinks by the lines it loses; one
wholly on them refuses the removal. Coordinates that may be on this map or
another are listed as hand edits, as for an insertion.

With a save manifest, the removal appends a `mapRemovals` step. A saved
position beyond the removed lines moves back with the map; one standing on a
removed line lands on the cell the confirmation names, the cell the player can
reach today nearest the reachable part of the removed lines, given in the map's
coordinates after the removal. The GUI lets you name another reachable cell
instead.

### Pieces

A map offers the pieces of its [kind](#map-kind)'s tileset (see
[Map Graphics](#map-graphics)): whole items such as a bed or a table, so a map
is built from items instead of single glyphs. In Normal mode on the canvas, `P`
opens the piece picker for the layer being edited: only the pieces whose glyphs
go on that layer, titled after it (`Fixtures piece`), one entry per piece with
its footprint in cells and the tile layers it writes, for example
`1 x 2 · tiles: furniture`, or `connected` in place of the footprint for a
[connected piece](#connected-pieces) such as a wall (`connected · tiles:
walls`). In Event mode it offers the pieces of the layer Map mode edits, and
choosing one returns to Map mode. Move with the arrows or `j` / `k`, press `/`
to filter by name, `Enter` to choose and `Esc` to cancel. The command palette
offers the same picker as `Pieces: Choose a piece to place`.

`T` in Normal mode (or `Pieces: Draw tiles for this layer's glyphs` in the
command palette) draws the tiles for the glyphs already on the layer being
edited, as if each were painted again: every glyph a piece draws gets that
piece's tiles, so a map authored before its pieces existed, or before it had
a kind, draws correctly graphically without a per-map script. A glyph that
could be several pieces asks which, as painting does, and the answer applies
wherever its neighbours do not decide. The glyphs themselves, and glyphs no
piece draws, are left as they are. It is one undo step, saved with the map.

When the layer being edited has no pieces, the status line names the layers
that do. When the map has no kind yet, or its tileset has no pieces or cannot
be loaded, it says so and nothing opens. Placing a piece never chooses a map's
tileset: it comes from the map's kind, set in the Inspector.

Choosing a piece starts placing it, in Map mode. The canvas previews the
piece's glyphs in reverse video with its top-left cell at the cursor, and the
canvas border shows `PIECE <name>  Enter:Stamp  Esc:Done`. The arrows move the
piece; a Normal-mode click moves the cursor, and the piece with it, to the
clicked cell. `Enter` stamps the piece and keeps it for the next stamp; `Esc`
ends placement. Entering Paint mode, choosing a tool or a layer, switching to
Event or NPC mode and selecting another map also end it.

A stamp is one undo step. It writes the piece's glyphs on the gameplay layer
the piece names, whichever layer the canvas is editing, in the brush colour as
painting does, and its tiles on the tile layers it names. A space glyph and a
`0` tile leave their cells as they are, and every other cell takes the piece's
whole tile. A field cell holds one whole RPG Maker tile, so an entry naming
half a tile (`42L`) is refused: in a tileset piece the canvas offers no pieces
and says which entry, in a tile layer the stamp changes nothing, and
validation reports both. A tile layer the map does not have yet is created as
`graphics/NN.name.tiles.php`, empty elsewhere, in the order of the gameplay
layer it belongs to: before the first tile layer of a gameplay layer drawn
above its own, so terrain tiles draw under building tiles and those under
fixtures, and otherwise after them all. It takes the free order just below
that layer; when there is none, that layer and every later one move up one
order, and saving writes their new files and removes the old ones. The terminal never shows the tiles, but a map built from pieces
draws correctly graphically without a second pass. A stamp that cannot be made
whole changes nothing: the map has no gameplay layer with the piece's name,
the footprint does not fit inside the map at the cursor (ragged rows
included), or a tile layer it names cannot be read or does not match the map.
Undo and redo restore glyphs and tiles exactly. `Ctrl+S` saves the changed
terminal and tile layer files in the map's one transaction; untouched files
are not written, and a changed tile layer is rewritten as a literal nowdoc
that keeps its leading comment.

A project adds pieces to its tileset (`assets/Data/Tilesets/<id>.php`) under
`pieces`, keyed by id, each with a `name`, the gameplay `layer` for its
`glyphs` (rows of one-cell characters) and optional `tiles` keyed by tile
layer name, with rows over the same footprint:
`'bed' => ['name' => 'Bed', 'layer' => 'fixtures', 'glyphs' => ['=', '='], 'tiles' => ['furniture' => ['32', '40']]]`.
The Engine's `docs/graphical-field.md` (Pieces) holds the contract. Every
stamp reads the tileset again, so the tileset stays the one source of pieces.

#### Connected pieces

A connected piece (`'connects' => 'lines'`), such as a wall or a fence, is
drawn rather than stamped, one cell per map cell, and joins the cells of the
same piece beside it. A cell belongs to the piece when its glyph on the
piece's gameplay layer is one of the piece's shape glyphs, so walls typed by
hand join too, while other glyphs (a `_`, say) are left alone and do not join.
Each cell takes a shape from the member cells beside it: joined only across it
is `horizontal`, only down it is `vertical`, and anything else (a corner, a
junction, a lone post) is a `corner`. A line's end cells are joined on one
side only, so a wall drawn across reads `----`, not `+--+`.

While a connected piece is placed, the canvas border shows
`PIECE <name>  Enter:Draw  Del:Erase  Esc:Done`, and `Esc:Unanchor` while an
anchor is set.

- `Enter` with no anchor draws the cell at the cursor and anchors there.
- `Enter` with an anchor draws from the anchor to the cursor: a straight line
  when they share a row or a column, otherwise the outline of the rectangle
  with the anchor and the cursor as opposite corners, which is a room. The
  anchor then moves to the cursor, so the next `Enter` carries on from there.
- The erase keys (`Backspace`, and `Delete`) erase the piece's cell under the
  cursor. A cell that is not part of the piece is left alone, and the status
  line says so.
- `Esc` drops the anchor when one is set; otherwise it ends placement.

The canvas previews the cells `Enter` would draw, each with the glyph it would
take, in reverse video. Every draw or erase is one undo step. It writes the
drawn cells, then reshapes every drawn cell and every member cell beside a
drawn or erased cell: its glyph becomes its shape's glyph and its entry on each
of the piece's tile layers becomes that shape's tile. So drawing a wall that
meets another turns the meeting cell into a corner, and erasing a cell
straightens the corners beside it. Drawn cells take the brush colour; reshaped
cells keep theirs. An erased cell becomes a space on the gameplay layer and
`0` on the piece's tile layers. A draw that reaches a cell beyond the map
(ragged rows included) changes nothing, and neither does a piece whose
gameplay layer the map lacks. A missing tile layer is created as for a stamp,
and undo, redo and `Ctrl+S` work exactly as they do for stamps. The tileset
lists a connected piece with one glyph per shape and one tile entry per tile
layer, or one per shape:
`'wall' => ['name' => 'Wall', 'layer' => 'buildings', 'connects' => 'lines', 'glyphs' => ['horizontal' => '-', 'vertical' => '|', 'corner' => '+'], 'tiles' => ['walls' => '5888']]`.
An A4 wall top can be the one entry for every shape, since the autotile shapes
its own edges.

### Terrain Kinds

A world map is painted in terrain kinds: grassland, woodland, mountains,
water and the like, declared in its tileset (Database > Tilesets, Terrain
Kinds). A kind is the cell's identity; its glyph in its colour and its
autotile are its two presentations, and its passage and encounters come with
it. `K` on the canvas opens the kind picker for the layer being edited, one
entry per kind with its glyph, whether it is walkable, its encounters, the
ground a feature stands on and whether its art is still to be drawn. The
picker opens on the kind under the cursor. Choosing one paints it like an
item piece: `Enter` paints the cursor's cell and anchors there, `Enter` again
fills the area to the cursor, a mouse drag fills the area it covers, and `Esc`
drops the anchor and then ends painting. The canvas border shows
`KIND <name> Enter:Paint Drag:Fill Esc:Done`.

Painting a kind makes the cell's terrain the kind's alone, as one undo step:
the tiles the layer's tile layers held there are cleared, except the layers
the kind keeps (a substrate beneath it), then its glyph is written in its own
colour and its autotile with its ground's. A kind awaiting its art draws no
tiles, so its glyph shows in the graphical field until the art is bound. On a
map with its own collision, the kind's passage is written into the cells
where nothing stands above it.

A kind's `Corner Sheet` (chosen among the project's PNG assets) draws its
edges on the corners between cells instead of in each cell, so diagonal
shorelines and bends are smooth curves rather than stair steps. Painting,
the terminal, collision and saves are unchanged: the kind still writes the
same glyph and autotile. Validation warns when a map shows a kind whose
corner sheet cannot be drawn; those cells show their glyphs.

A corner-edged kind's `Joins` names a join group, such as `water`. Kinds on
the same tile layer naming one group share one edge: a river runs into a lake
or the sea with no bank between them, and each still shows its own water.
Leave it empty for a kind that draws its edge against every other kind. The
tileset refuses a join on a kind without a corner sheet, and kinds of one
group that paint different tile layers.

Only a map painted in terrain kinds is painted by kind (`'terrainKinds' =>
true` in its data file). Until then `K` says how to convert it:
`ichiloto map:convert-kinds <map>` lists the map's glyphs and the kinds, and
`--kind ';=grassland' --kind '~=water' ...` maps each old glyph to a kind,
shows each mapping's cells and the collision it changes, and converts the map
through the Editor's validated change set (`--check` writes nothing). A glyph
that already names a kind must be mapped too, so nothing is reread unseen. A
kind owns its glyph's atlas role, so the map's own atlas classifications of
the glyphs it converts, and of glyphs that name kinds on that layer, go with
the conversion; the preview lists them. Validation reports atlas terrain the
game cannot read as an error, since the game refuses to prepare that map.

Validation warns about cells whose glyph and tiles show different kinds,
about cells of kinds awaiting art, and lists each tileset's kinds awaiting
art. A troop of the map's encounters can be limited to terrains (the
inspector's `Terrains` row, offered on maps painted in kinds), typed as the
encounter terrains the map's kinds meet.

### Thickets

Woodland, marsh reeds and tall grass hide a character walking through them
in the graphical field, as classic JRPGs do. The GUI editor's Database >
Tilesets declares a tileset's **Thickets**, one per foliage type: its id and
name, its **Depth** (field pixels of a character hidden, up from its feet, 1
to 48), its **Hidden Part Opacity** (0 masks the hidden part behind the
foliage; up to just below 1 shows it through, as RPG Maker draws a bush),
whether it **Rustles** around a character walking through it, and the
**Tiles**, other than terrain kinds' own autotiles, that are it. A terrain
kind's **Thicket** row chooses among the tileset's thickets the one its own
autotile is. The game hides a character in the deepest thicket among the
tiles its cell shows. The terminal, collision, movement and encounters are
unchanged, and the terminal Database neither shows nor drops thickets.

### Stacked Pieces

Some decorations overlap their neighbours, such as the peaks over a world
map's mountains and the crests over its hills. A tileset declares the tile
layers they stack on (Database > Tilesets, Stacks: an id and its tile layers,
bottom to top, such as `relief-1, relief-2, relief-3, relief-4`), and a piece
stacks by putting its tiles on the stack's first layer. It has no effect,
kept layers or doorway. A decoration such as a peak has spaces for glyphs and
no physical footprint, so it never changes passage, encounters or the atlas:
the mountain kind beneath it is solid already. An obstacle standing on
walkable ground, such as a hill crest's mound, declares its ground footprint
like any object: glyphs on the cells it blocks, on a gameplay layer above the
terrain kinds (never the kinds' own layer), and the occupancy those cells
take on a map with declared occupancy. Stamping it writes those glyphs.

Stamping a stacked piece lays each of its tiles into the cell's stack in
depth order, whichever was placed first: an object whose bottom row is
further south draws in front, and of two on one row the one further east.
The map gets the stack's tile layers as they are first needed, in the
stack's order. A cell holds at most one tile per stack layer, so a stamp
that would overfill one is refused and changes nothing; stamping the same
piece on the same cells again adds nothing. Each tile of a stack's pieces
belongs to one piece cell, so a cell's objects are read back from its tiles.
Removing one object from a stack is a remaining GUI capability. Repainting
terrain kinds clears a stack by whole object: an object goes when the paint
covers every cell it shows on, and stays whole when it also shows on a cell
the paint leaves, so painting the road beside a range never crops the peak
that overhangs it. An object that goes takes its footprint glyphs with it
where they are still its own. To remove an object, paint over all of its
cells at once.

Each stacked object belongs to its piece's gameplay layer, so a stack whose
objects belong to several layers moves with none of them as whole tile
layers. Copying, cutting and pasting a selection carries the selected
layer's own objects, cell by cell, and lays them into the stacks where they
land in depth order: copying the terrain takes the peaks and leaves the hill
crests, which travel with the layer their footprint glyphs are on.

### Map Graphics

A map's [kind](#map-kind) names its tileset in its data file
(`'tileset' => 'interior'`, read from `assets/Data/Tilesets/interior.php`),
and the map may keep RPG Maker tile layers in
`graphics/NN.name.tiles.php`. A tile layer holds one tile identity per map
cell, so each of its rows is exactly as wide as the map's row in terminal
columns. The TUI never displays tiles or paints single tiles; painting them
belongs to the GUI editor. It writes tiles only when it stamps a
[piece](#pieces) or edits glyphs the tiles follow, and otherwise keeps them
intact:

- Tiles follow a gameplay layer's glyphs however they are edited: typing,
  painting, erasing, the line, rectangle and fill tools, the mouse, cutting and
  pasting. A glyph that leaves a cell takes the tiles of the piece it drew
  there; a glyph that arrives draws its piece's tiles, in the same undo step.
  A piece's tiles over blank cells, such as a sofa's back over its seat, go
  with the glyph nearest them. When a glyph belongs to several pieces (`-` for
  a chair facing north or south), the neighbours decide when they hold the
  rest of one piece, such as the left half of a table beside the right;
  otherwise the editor asks which piece it is, or No tiles, and Esc leaves the
  map as it was. The brush remembers the answer for its glyph until a glyph is
  typed again, and the eyedropper picks up the piece a glyph draws. Tiles no
  piece accounts for, such as a floor under a wall, stay where they are. A
  tile that a glyph-less piece also draws, such as a stone wall cap that also
  tops the stone wall, is free: placed on a cell it plays the piece of the
  glyph already there, or none, so a cap beside a wall never becomes a wall,
  and validation never asks for its glyph. A
  piece that keeps the tiles beneath it, such as a window mounted on a wall
  face, leaves them when it arrives; erasing it gives the cell back to the
  piece those kept tiles stand for, such as the face's `#`, so the wall is
  whole again. When they stand for several pieces, the editor asks which, or
  none to leave the cell blank.

- Glyphs follow the tiles that stand for them, the other way round. Any tile
  a tileset piece draws stands for that piece's glyph on its gameplay layer,
  such as a bed's tiles for `O` and `U` on fixtures or a wall's tile for `-`,
  `|` and `+` on buildings. Placing such a tile in the GUI editor writes its
  glyph where the piece puts it, with the rest of that
  glyph's tiles and the piece's glyph cells that draw no tile (the lower half
  of a window); erasing or covering one removes the glyph it stood for, with
  its other tiles; a wall's cells take the shapes their neighbours give them.
  On undeclared maps, glyph-derived collision follows in that same undo step;
  converted maps retain their independently authored physical cells. Picked-up tiles
  move in one step too. A tile two pieces draw is asked about. A tile whose
  glyph would fall off the map is refused. Tiles no piece draws, such as a
  rug or a picture, stand for nothing in the terminal and are placed freely.

- Each tile layer moves with one gameplay layer: the one the map data names
  (`'tileLayers' => ['floor' => ['movesWith' => 'buildings']]`), or else the
  one whose tileset pieces write it, when only one does. Copying, cutting and
  pasting a block on that gameplay layer carries its tiles cell for cell, in
  the same undo step, so a moved chest or room keeps its art. Other tile
  layers, and blocks on the event layer, leave tiles where they are.

- Resizing the map crops or pads every tile layer with empty tiles (`0`) in the
  same undo step and save as the terminal layers. A resized tile layer is
  rewritten as a literal nowdoc that keeps its leading comment; an unchanged
  one keeps its bytes. A resize is refused, changing nothing, while a tile
  layer cannot be read or does not match the map.
- Duplicating, moving and deleting a map take `graphics/` with it, in the same
  transaction and rollback. Other files in `graphics/` are the author's and
  stay.
- A tile layer changed or added on disk after opening refuses the save, as
  terminal layers do.

Validation (`Ctrl+E`, or `ichiloto validate`) reads graphics as the Engine does.
A missing or invalid tileset, graphics without a tileset, misnamed or
duplicate-order tile layers, rows or cells that do not match the map, and
invalid tile identities are errors. Unusable sheets and tiles from a sheet the
tileset does not provide are warnings. The game shows terminal glyphs for
anything it cannot draw.

For a map with a kind, validation also warns about what the graphical field
still shows as terminal glyphs, using the Engine's rule for which tiles hide
which glyphs:

- glyph cells no tile covers, grouped by glyph and layer, or a single warning
  when the map has no tiles yet;
- NPCs without a field sprite (`sprites2d`) whose glyph therefore shows; an
  NPC with an empty sprite draws nothing and is not counted;
- a copy of an NPC's own glyph in the map, which shows under its sprite;
  remove the copy when the NPC is always there.
- tiles a tileset piece draws whose glyph is no longer there, such as a
  window's tile left after its `x` was removed outside the editor. Tiles no
  piece draws, such as a house's walls over blank cells, are not judged;
- cells showing the tileset's missing-art placeholder (its `missingArt`
  tile), which mark art nobody could yet infer.
- cells whose ground tiles (the A1 to A5 sheets) can show the background
  through see-through edges in some animation frame, because no ground is
  painted beneath them: the game shows that as a dark seam. Paint the map's
  base or floor beneath on a lower tile layer. Upper-sheet props are
  see-through by design and are not judged. This reads the sheets through
  PHP's GD extension; without it, validation says it could not check.

These are art still to do, not faults: the game plays the same either way,
so saving a map does not warn about them; `ichiloto validate` reports them.

Validation also warns where what a map shows disagrees with what it lets the
player do, by the Engine's passage agreement rule (see the Engine's graphical
field guide, *Look and passage*): solid cells that show only walkable ground
(invisible walls), walkable cells that show water, a barrier or a world
object's art (walk-throughs), and world objects whose art leaves most of their
solid footprint bare. Each warning names its cells; the GUI editor's
**Collision** tool marks every one over the map with a coloured outline and
corner flag, counted by kind in its options. They are warnings, never
refusals: change the art or the collision as the map needs. A tile whose
ground appearance is unknown is not judged; give it one in the GUI tileset
view's **Appearance** mode.

Text catalogues in `assets/Graphics/Tilesets/*.txt` appear as Facade brushes in
`Ctrl+P`. Separate multi-row shapes with blank lines. Select a brush to target
the gameplay `buildings` layer, then press `Enter` to stamp it as one undo
step. In Paint mode (`i`), a left click also stamps; Normal-mode clicks only
move the cursor. Mode and map changes clear the stamp brush; `b` returns to a
glyph brush. Every stamp rereads its catalogue, so
the catalogue remains the single source of shapes. Clipping never grows rows.

The canvas previews the selected map and is where you paint. It is modal, in
the vim tradition: in **Normal mode** letters are commands, and in **Paint
mode** every printable key is a glyph. This is what guarantees that no
command can ever steal a paintable character - `?`, `%`, `^`, `@` and
anything else all paint in Paint mode, with no reserved list.

The canvas starts in Normal mode. `i` enters Paint mode; `Esc` returns to
Normal. While painting, the Status pane shows `[PAINT]` beside the mode.
Control-byte and function-key shortcuts (`Ctrl+S`, `F3`, ...) work in both
modes, since they are not glyphs.

### Terminal Editing and Pending Graphical GUI

Keep walkable floors and solid walls readable as terminal gameplay glyphs.
A readable notice or interactive fixture needs its gameplay glyph and
interaction, not a graphical decoration marker. Graphical representations
must not drive or change the terminal editing experience.

1. Focus the canvas and choose a gameplay or event layer with `L`, or step
   through them with `[` / `]`. Press `i` to paint; `Esc` returns to Normal
   mode. `L`, brackets and
   other printable characters remain paintable while in Paint mode.
2. Use the existing glyph tools, colours, selections, clipboard and mouse
   strokes. Continue authoring events and NPCs in their existing modes.
3. Use `Ctrl+Z` / `Ctrl+Y` to undo/redo, including after changing layers.
   `Ctrl+S` saves; `Ctrl+R` reloads the saved workspace and clears history.
   Existing graphical source data remains preserved during ordinary glyph edits.

**Removed TUI workflows:** painting decoration markers, choosing graphical
layers, inspecting graphical crop tables and editing selected-cell crops are
no longer terminal authoring features. Decoration data is not deleted, and
its low-level APIs and source-preserving round trips remain available for the
graphical editor. Glyph-keyed crop authoring (`tiles2d` and its cell
overrides) has been removed along with the Engine's support for it; tilesets
replace it.

**Measuring walks** is a GUI editor tool: its Measure tool shows the
shortest walk between cells with its steps, walking and dashing seconds,
screens crossed and expected encounters, read from the session's `map.measure`.
Dashing seconds use the game's Dash Speed Multiplier from Database > System,
unsaved edits included; a game without a dash, a map with Disable Dashing on,
or a dash the game could not read shows walking time only and says why (see Measuring
A Walk in [Change Maps From A Script](guides/change-maps-from-a-script.md)).
The terminal editor keeps its workflow and has no measuring mode.

**Rendering maps for review** draws a map, or a rectangle of it, both ways
from the project: `ichiloto map:render MAP[@X,Y,WIDTH,HEIGHT] --out DIR`
writes the Terminal field's characters and colours (`.ans` and a page of its
own), a native-size PNG of the graphical field, and a `review.html` with the
two side by side for every view. `--player X,Y[,HEADING]` stands the player
on a cell for door checks, `--actor ID` shows an actor there, `--scale 0.5`
adds an overview, and `--changes BATCH` renders a batch before it is applied,
writing nothing. The session serves the same render to an editor as
`EditorSession::renderMapReview()`, unsaved edits included, and writes it as
`map.review`: the GUI toolbar's review button asks for a folder, writes the
review of the open map there and opens its page. See Rendering
Maps For Review in [Change Maps From A Script](guides/change-maps-from-a-script.md).

**Pending, not implemented:** richer renderers will use a separate,
RPG Maker-like GUI editor for graphical materials, atlases and crop authoring.
The planned `ichiloto edit` entry point will offer a TUI/GUI choice; that choice
and the GUI itself are not delivered by this TUI boundary correction. See the
[GUI editor plan](https://github.com/ichiloto/gui-editor/blob/develop/docs/plan.md).

### Normal mode

| Key | Action |
| --- | --- |
| `i` | Enter Paint mode (every key paints; `Esc` returns to Normal) |
| `m` | Switch to Map mode (paint tiles) |
| `e` | Switch to Event mode (paint event markers) |
| `n` / `F3` | Toggle NPC mode (place and edit the map's NPCs) |
| `a` | Switch to Area mode (select cells for the map's named areas; see [Area Mode](#area-mode)) |
| `c` | Open the character map |
| `P` | Choose a tileset piece to place (see [Pieces](#pieces)) |
| `T` | Draw the tiles for the glyphs already on this layer (see [Pieces](#pieces)) |
| `o` | Open the brush colour picker (see [Colour](#colour)) |
| `b` / `l` / `r` / `R` / `s` | Choose a tool: Brush, Line, Rectangle, Filled Rectangle, Select |
| `f` | Flood fill from the cursor (same as `Ctrl+F`) |
| `k` | Eyedropper - pick up the symbol under the cursor (same as `Ctrl+K`) |
| `w` | Cycle brush width (same as `Ctrl+W`) |
| `y` | Lift (yank) the selection (same as `Ctrl+L`) |
| `x` | Cut the selection (same as `Ctrl+X`) |
| `p` | Paste/stamp the clipboard at the cursor (same as `Ctrl+U`) |
| `u` / `U` | Undo / redo (same as `Ctrl+Z` / `Ctrl+Y`) |
| `?` | Open the help overlay |
| `Arrows` | Move the cursor |
| `Enter` | Apply the active tool, or stamp or draw the piece being placed |
| `Esc` | Pop one canvas level: a connected piece's anchor, piece placement, a pending tool anchor, then the selection |

Typing an unassigned printable key in Normal mode paints nothing; the status
line points to `i` instead.

### Paint mode

Every printable key paints its glyph at the cursor with the brush tool. Under
the Line or Rectangle tools a key chooses the glyph instead, and `Enter` sets
the anchor and then draws; the canvas border says which. Selecting is a Normal
mode job: entering Paint mode with the Select tool active switches to the
brush, and choosing Select returns to Normal mode. `Arrows` move, `Enter`
applies the active tool, erase keys erase, and `Esc` returns to Normal mode. Entering NPC mode or moving focus off the canvas also returns
to Normal.

### Mouse

The mouse honors the canvas's modality. In Normal mode a click **selects**:
the cursor jumps to the clicked cell and the status line reads out its
coordinates - the fastest way to find a tile's position for a spawn point or
event without walking the cursor there. With the Select tool, dragging in
Normal mode selects the rectangle from where the press began to where it is
released; a click still only moves the cursor. In Paint mode a left click
paints the brush symbol and a right click erases, dragging paints a stroke,
and in Event mode clicks keep their event-editing behavior.

The mouse follows the active tool. With Line, Rectangle or Filled Rectangle
in Paint mode, the press sets the anchor, dragging moves the other end with
the shape previewed on the canvas, and releasing draws it in one undo step,
in the brush colour; dragging with the right button erases the shape. The
same preview shows while a tool anchored with `Enter` waits for its second
`Enter`. In Normal mode the canvas border names the tool keys
(`b/l/r/R/s:Tool`).

The wheel scrolls the viewport without moving the cursor - free look for
surveying a map larger than the canvas (horizontal wheel scrolls sideways).
The next cursor movement reclaims the view; clicking a surveyed tile in
Normal mode brings the cursor to it instead. The canvas header always shows
`view x,y` and `cursor x,y`, and the Status pane repeats the cursor position
beside the viewport offset.

### Colour

`o` in Normal mode opens the brush colour picker: the 16 standard 4-bit
ANSI colours (in the map format's Symfony colour names, where `gray` is
bright black), rendered as live swatches, plus two brush states above them.
The brush colour applies to every paint on every layer - brush dabs,
shapes, flood fills - and is written as `<fg=...>` tags, exactly the
styling authored by hand.

- **Keep cell colour** (the default): painting changes the glyph and leaves
  each cell's existing styling byte-for-byte, authored options included.
- **No colour**: painting strips styling and writes plain glyphs.
- **A colour**: painting writes the glyph in that colour. Choosing a
  colour recolours in place, keeping the glyphs, as one undoable stroke:
  with a selection active (`s`), every cell in the selection; otherwise,
  under the brush tool, the cell at the cursor. Spaces stay uncoloured.

The eyedropper (`k` / `Ctrl+K`) picks up a cell's colour and the piece it
draws along with its glyph; an uncoloured cell loads an uncoloured brush. A painted space is
always uncoloured, so erasing never leaves invisible styling behind. Event
markers remain authoring geometry, but can now carry colour through the same
picker and undo workflow. The Status pane shows the brush colour beside the tool.

### NPC Mode

`F3` enters NPC mode: the map's `npcs` collection is drawn over the tiles as
an overlay - sprites at their authored anchor, wide glyphs occupying the two
columns the game gives them, styled sprites as the plain glyph - and nothing
you do here paints a tile or an event marker. The selected NPC is shown in
brackets, except an explicitly empty sprite: selection highlights its existing
map cell without replacing the glyph or neighbouring cells. An unselected empty
sprite draws no overlay. NPC mode sits on `n` in Normal mode and on `F3`
everywhere, so no paintable character is taken from you (and not a control byte, since the terminal driver
reserves the remaining ones).

| Key | Action |
| --- | --- |
| `Enter` | Select the NPC under the cursor; on an empty tile, name and create a new fixed NPC there |
| `M` | Pick up the selected NPC; the next `Enter` sets it down at the cursor (`Esc` cancels) |
| `D` | Duplicate the selected NPC under a fresh stable id, one column to the right when free |
| `L` | List the map's NPCs by name and id; type to narrow, `Enter` selects one and jumps the cursor to it |
| `[` / `]` | Select the previous / next NPC in the map's list |
| `Del` | Delete the selected NPC - refused, with the list, while anything names its id |
| `Tab` | Edit the selected NPC in the Inspector |
| `Esc` | Cancel a pending move or prompt first; otherwise exit NPC mode back to the Map layer (`F3` and `n` also toggle out) |

Create, move, duplicate and delete each undo and redo as one step, and dirty
state follows the map's persisted-state fingerprint like every other edit:
undoing back to the last save is clean. A typed glyph in NPC mode is refused
with a hint rather than painted under an NPC.

#### Stable ids

`Enter` on an empty tile asks for the NPC's name first, and derives its stable
`id` from that name - `Gate Guard` becomes `gate-guard`, numbered if the map
already has one - because the id is what routes, dialogue events, cinematics
and script diagnostics name. Renaming the NPC re-derives an id its old name
derived from the new name **while nothing refers to it**, so an NPC created as
`New NPC` and named later gets the id its name suggests. Once an event, script,
route or cinematic starting on the map names the id, it stays: a rename keeps
it and the status says what names it. Moving the NPC or changing its sprite
never touches it. Duplicating assigns a fresh id from the name.

The `Id` row sets the id directly, for an NPC whose id should differ from its
name (one character met as several NPCs, such as `djin-contract-trial` for
`Djin`). The id is lowercase letters and digits joined by single hyphens and
unique on the map. Changing it is refused, with each reference listed, while
anything names the current id: references are not migrated, so change them
first. A chosen id stays when the NPC is renamed. An NPC authored without an
id loads and edits normally, shows a `! No stable id` row (`Enter` there
assigns one from its name; the `Id` row takes a chosen one), and validates
with a warning that scripted movement cannot target it.

#### The NPC Inspector

`Tab` from the canvas edits the selected NPC with the same pane every Database
category uses - pickers, condition lines, world-write rows, command frames,
`Shift+O` / `Shift+X` on lists - grouped as:

| Group | Rows |
| --- | --- |
| Identity | `Id` (read-only), `Name` |
| Placement | `X`, `Y` (the canvas moves it too) |
| Appearance | `Sprite`, `Facing North/South/East/West`, `Graphical Sprites` |
| Movement | `Movement` (`fixed` / `wander`), `Direction Fix` (`false` / `true`), and while wandering `Wander X/Y/Width/Height` |
| Visibility | `Visible When` - a condition line |
| Cue | Only beside a top-level `Script`: `Cue Symbol` (one cell; empty means no cue), `Cue Colour`, `Cue When`. Pages carry their own cue |
| Interaction | `Script` (a command frame), then one `Page N` heading per page with its rows `When`, `Then Set`, `Cue Symbol`, `Cue Colour`, `Cue When`, `Event Script`, `Cinematic`, `Repeats`, `Page Id`, `Staged Speakers`, `Script Commands`, `Line 1 Speaker`, `Line 1 Text`, … |
| Completion Writes | `After Talking` - world-write rows |

Rows read as the game will read them: an unset `Movement` shows `fixed`, an
unset `Direction Fix` shows `false`, an unset `Sprite` shows `@`. Fields the game does not read are listed in a
`Preserved fields` row and written back untouched. Each page is a
heading (`Page 2 · when switch:gate_open` once it has a
condition) with short row labels under it, and long lines wrap, so what a
character says is read in the pane rather than in the edit buffer.

- **Pages.** An NPC's pages are what talking to it does, as RPG Maker's
  event pages: talking runs the first page whose `When` holds, so put the
  most advanced story page first and an unconditional page last. `[` / `]`
  on any row of a page moves it one place earlier or later. A page shows
  its lines, then runs its `Script Commands`, its `Event Script` or its
  `Cinematic` (one of them), and applies `Then Set`. Its cue is the NPC's
  guidance while it is the page talking would run, so a story beat the NPC
  delivers needs no separate event or stand-in cell, and a quest waymark
  on the NPC follows the cue. `Repeats: false` retires the page once its
  action completes; such a page needs a `Page Id`, which names its
  completion in saves. `Staged Speakers` lists the other NPCs (by id,
  comma-separated) its scene stages; each must stand beside this NPC
  whenever the page can run. Lines alone with at most a cue are saved as
  plain lines with the cue on the NPC, as they always were. Validation
  reports a page an earlier page always pre-empts, and a story event an
  NPC delivers from a separate cell (`event.npc-interaction`): move it
  onto that NPC's pages. The session's `npc.adoptEvent` does that move as
  one undo step, keeping a run-once event's saved completion for old saves.

- **Map-owned appearance.** For a fixed interaction already drawn on a map
  layer (such as a mounted notice on `fixtures`), clear the `Sprite` text
  completely and press `Enter`. This stores `'sprite' => ''`, not a missing
  field, and removes the duplicate NPC overlay. The NPC retains its stable id,
  dialogue, conditions and blocking anchor. Select it at that anchor, through
  `L`, or with `[` / `]`; selection highlights the underlying cell. Omitting
  `sprite` still defaults to `@`. Whitespace-only or style-only sprite text
  still warns: use genuinely empty text when the map owns the appearance.
- **Movement.** `wander` roams one tile at a time; the wander bounds only
  appear while wandering, and loaded bounds are kept (not shown) for a fixed
  NPC. Omitting every bound leaves the game's unbounded wander. Patrol routes,
  pathfinding and followers are not engine features, so the editor does not
  offer them; scripted movement is a `move_route` command in an event script.
- **Direction fix.** When the player talks to an NPC, the game turns it to
  face the player before it speaks and turns it back to its previous heading
  when the conversation ends, unless the conversation's own script turned,
  moved or staged it. `Direction Fix` (RPG Maker's option of the same name)
  set to `true` keeps its heading throughout instead, for a clerk behind a
  counter or a guard watching a gate. It stores `'directionFix' => true`;
  setting it back to `false` removes the key, the game's default. It affects
  only the talk turn: wandering and `move_route` still turn the NPC. The
  validator reports a `directionFix` that is not `true` or `false`.
- **Stands facing.** The heading the NPC stands with when its map loads,
  such as a clerk facing the door their desk is approached from. It stores
  `'facing' => 'north'` and shows that heading's directional sprite; clearing
  it removes the key, which the game reads as `south`. Talking turns the NPC and back
  to it. The validator warns about a facing that is not one of the four.
- **Directional sprites.** Optional glyphs shown when the NPC turns; the base
  sprite covers a heading you leave blank. Resting the Inspector cursor on a
  `Facing …` row previews that glyph on the canvas in the NPC's place.
- **Graphical sprites are not edited in the TUI.** The `Graphical Sprites`
  inspector action and its numeric PNG/crop/sheet dialog have been removed.
  Existing NPC `sprites2d` data remains preserved when editing terminal glyphs,
  and its model services and source-preserving validation remain available.
  Graphical NPC authoring belongs in the pending
  [GUI editor](https://github.com/ichiloto/gui-editor/blob/develop/README.md), not a terminal form. Terminal base
  and directional glyphs, identity, dialogue, movement and conditions remain
  editable as before. This correction does not claim that all other actor or
  database artwork controls have been removed.
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
field against what the game does with it - malformed shapes the game would
drop, coordinates off the map, an NPC on an event tile it would make
unreachable, wander areas that leave the map or that the NPC starts outside,
a script shadowing dialogue, unknown fields - as errors where the authored
content cannot happen and warnings where it can but probably not as meant.

### Area Mode

A map's named areas are territory it owns, with no art, passage or discovery
of their own: an event can be bound to an area as its subject, covering the
area's cells, and a landmark can be anchored to one. `a` enters Area mode. The
cells you select show in reverse video over the map's own glyphs; nothing
here paints a tile or a marker.

| Key | Action |
| --- | --- |
| `Space` | Add the cursor's cell to the selection, or take it away |
| `r` | Mark one corner of a rectangle; move, then `r` again adds the whole rectangle |
| `Enter` | With cells selected, make an area of them (type its id, then `Enter`), or apply them to the area picked up; with nothing selected, pick up the area under the cursor to change its cells |
| `n` | Step to the next area sharing the cursor's cell |
| `c` | Clear the selection |
| `Del` | Delete the area picked up, or the one under the cursor - refused, naming them, while an event or landmark names it |
| `Esc` | Drop a rectangle corner, then the selection, then leave Area mode |

A selection that fills a rectangle exactly is written as one (`x`, `y`,
`width`, `height`); any other selection is written as its exact cells. An id
is lowercase letters, digits, `.`, `_` and `-`, starting with a letter. The
Inspector lists the map's areas under **Areas**: rename one there, and the
events and landmarks naming it follow in the same undo step, or remove it.
An event's **Bound To** row chooses its own painted cells, a world object or an
area; choosing its own cells again keeps it on the cells its subject gave it.

Under **Speakers** an event names the NPCs who speak its lines, chosen from
the map's NPCs that have an id. Adding one (`a` on the heading or a speaker,
or the GUI's add) names the nearest NPC not yet listed; choose another on its
row, and delete the row to remove it. Naming a speaker moves no one:
validation reports, as `event.speaker`, a speaker who is not on the map, is
never shown while the event can run, wanders freely or stands more than one
cell from the event, so stand them beside it or bind the event to what they
stand at. An NPC an event names as a speaker keeps its id and cannot be
deleted until the event stops naming it.
Inserting or removing map rows and columns moves areas with the lines; a
removal through one of an area's exact cells is refused until you reshape it.

### Canvas Tools

The active tool is modal and always shown in the footer, for example
`Tool: Rect Fill 2 @0,0 [5x3] clip 2x1` - tool, brush width, pending anchor,
selection size, and clipboard size.

| Key | Action |
| --- | --- |
| `Ctrl+N` | Next tool (Brush → Line → Rect → Rect Fill → Select) |
| `Ctrl+W` | Cycle brush width (1 / 2 / 3 / 5) |
| `Ctrl+F` | Flood fill from the cursor |
| `Ctrl+K` | Eyedropper - pick up the symbol under the cursor |
| `Ctrl+L` | Lift (copy) the selection |
| `Ctrl+X` | Cut the selection |
| `Ctrl+U` | Paste/stamp the clipboard at the cursor |

`Enter` is the universal tool verb: it paints under the brush, sets the anchor
for a two-point tool, and completes the shape or selection on the second press.

Every tool commits as exactly one undo step. A filled rectangle, a flood fill,
a cut, and a paste each undo in a single `Ctrl+Z`. Repeated pastes are separate
steps, so you can stamp freely.

The clipboard is layer-tagged: a block lifted from the event layer refuses to
land on tiles. A block lifted from a gameplay layer carries the graphical
tiles that move with that layer, and a paste replaces the tiles under it.

A pending line or rectangle is previewed on the canvas in the brush glyph from
its anchor to the cursor. A pending selection is not drawn; the footer reports
its anchor and size.

## Inspector Panel

The Inspector shows the fields of the current selection - the map's metadata in
Map mode, the selected event's data in Event mode, the selected NPC in NPC mode
(see [NPC Mode](#npc-mode)).

Controls:

- `Up` / `Down`: move between fields
- `Enter`: start editing a text or number field
- `Left` / `Right`: adjust without entering edit mode - cycle enum options,
  toggle a boolean, step a number
- `Esc`: cancel an edit

Field rows are typed. A text field accepts anything; an integer field accepts
digits and a leading `-`; a float field also accepts one `.`; a boolean toggles;
an enum cycles through its options.

Rows shown as `Label · value` are visible but fixed - they display information
the editor does not let you edit here. Rows shown as `Label: value` are
editable.

A value longer than the pane wraps onto continuation lines indented under the
value column, so a long description or dialogue line reads in full; the row
being edited stays on one line and scrolls sideways around the caret. The pane
scrolls by rows, keeping the selected row's first line in view.

The Destination row on an event is a reference: `Ctrl+G` follows it.

### Map region

`Region` is the place name the game shows for where the party is, such as
Happyville or Garden of Roads. Enter opens a picker of the region names the
project's maps already use, so a region is spelled one way everywhere; it is
never typed. Choosing one changes only the map data's `region`: the map stays
in its folder under `assets/Maps`.

### Map role

`Role`, under `Region`, is what the map is to the world, one of the project's
[Map Roles](#database-screen), shown by name, such as Overworld, Outside or
Interior. Enter opens the picker; `None` removes it. It is stored as the map
data's `role`. The role's **Leads To** decides where the map's doors and edges
may lead, so an overworld whose role leads only to outside maps and dungeons
cannot lead into an interior. A role the project no longer has stays visible
as `id · not in Map Roles`, and validation names it.

### Place type

`Place Type`, under `Role`, is the kind of place the map belongs to, one of
the project's [Place Types](#database-screen), shown by name, such as Town,
Outpost or Shrine. Enter opens the picker; `None` removes it. It is stored as
the map data's `placeType`. A map drawn with a tileset that gives place icons,
such as a world map, shows every entrance into this map by the icon its
tileset gives the type (Database > Tilesets, Place Icons), so a larger
settlement and a single building read differently at a glance. Changing the
type changes the icon every such entrance should show; validation warns at
each entrance whose icon, in its tiles or its terminal glyphs, is not its
destination's type's, and at a place icon over a way into a map with no place
type. Restamp the icon over the entrance with the piece it names, keeping the
entrance cell. A type the project no longer has stays visible as
`id · not in Place Types`, and validation names it.

### Skit backdrop

`Skit Backdrop`, under `Place Type`, is the scene a skit's stage shows while
the skit plays on this map, unless the skit's own `Backdrop` names one (see
[Skits](#skits)). Enter opens the project's image picker (the GUI editor
shows each image); `None` removes it. It is stored as the map data's
`skitBackdrop`, a path under `assets`, so replacing the file keeps the
binding. With none, the row says what skits here show instead: its region's
backdrop from the [Regions](#database-screen) page, or no backdrop, which the
game reports with the skit and the map. A path the project no longer has
stays visible as `path · not a PNG in assets`, and validation names it.

### Map kind

`Kind`, under `Region`, is the setting the map draws: one of the project's
tilesets in `assets/Data/Tilesets/`, shown by name, such as Interior,
Exterior, World or Dungeon. Every tile and [piece](#pieces) on the map comes
from its kind, so the tiles a map offers change only when its kind does.
Enter opens the picker; the kind is stored as the map data's `tileset`.

- A new map is asked its kind before it is created (`Shift+A` in the Assets
  panel); `Esc` creates nothing. A duplicate keeps its original's kind. In a
  project with no tilesets, a new map is created without a kind.
- A map without a kind shows `Not set`, and `P` offers no pieces until it
  has one. Choosing a kind keeps any tiles the map already has.
- Changing one kind to another on a map with tiles asks first, since its
  tiles name places on the old kind's sheets and would show the wrong art.
  `Cancel`, the default, keeps everything; `Clear N tile layers and change`
  clears its tile layers and their `tileLayers` settings. Glyphs, collision
  and events stay.
- A map without tiles changes kind at once.
- Each change is one undo step and is written on save; saving a cleared map
  removes its `graphics/` tile layer files.
- A kind the project no longer has stays visible as
  `id · not in assets/Data/Tilesets`.
- In a project with tilesets, validation and the pre-save checks warn about a
  map without a kind. A project without tilesets is not warned.

### Map audio and encounters

In Map mode the Inspector carries the map's runtime metadata beneath its
identity rows.

**Audio.** `Background Music` is a picker over the project's own tracks
(`assets/Audio/BGM`, shown by file name): Enter opens it, `(None)` clears
the authored track by removing the `bgm` key - the editor never writes a
misleading empty one. A track the project no longer has stays visible as
`name · not in assets/Audio/BGM` rather than silently jumping to a valid
one, and validation names it. There is no in-editor audition in this gate;
the playtest plays the real thing.

**Music Variants.** Beneath the static track, `Music Variants` authors the
engine's ordered `bgmVariants` list: story-dependent tracks the map plays
instead of its default. The engine evaluates the variants in declaration
order and **the first whose conditions all hold selects the track**; when
none matches, the static `Background Music` plays, and when neither
resolves to a track the current music simply continues. An empty conditions
list is an unconditional match - that is what the engine evaluates - so an
unconditional variant belongs last, and validation warns when one shadows
later variants.

- `Shift+O` on the heading or any variant row appends a variant, which is
  born visibly incomplete: `(no track yet)`. The engine skips a variant
  without a track, and validation names it, so nothing plausible is ever
  inserted silently.
- Each variant's `Track` is the same picker over the project's own tracks.
  A variant offers no `(None)` row - an empty track is a skipped variant,
  not silence - so removing the variant is how it is un-authored.
- Each variant's `When` opens the same shared Condition editor every other
  surface uses (`a`/`d` add and remove, `t` cycles the type, `n` names or
  picks, `!` negates, Enter keeps, Esc leaves it alone), hosted right in
  the Inspector pane.
- `[` and `]` move the variant the cursor is in, because declaration order
  is runtime behavior; `Del`/`Shift+X` removes it, and removing the last
  variant removes the `bgmVariants` key with it. Every one of these is
  undoable, saves with the map, and reopens in the authored order.

Keys inside a variant the editor does not own ride every edit untouched. A
list the editor cannot hold as rows - a keyed block, a variant that is not
an array - is shown read-only with the shape named, and a variant whose
conditions carry something the shared editor cannot (an unknown key, an
unrecognised type) keeps an editable track while its conditions say why
they are not. Validation reports every shape the engine would silently
skip: a missing, empty, or unknown track, malformed conditions, and the
unreachable-variant warning above. The editor authors and validates the
structure; the engine alone evaluates conditions and plays anything.

**Movement.** `Disable Dashing` is a switch, RPG Maker's map setting of the
same name: on, the player walks on this map even when the game's Dash is on in
Database > System and Dash is held. Switching it on writes the map's
`disableDashing`; switching it off removes the key, so the map follows the
game's dash. A value the Engine would not read stays visible with what it
must be, and validation names it.

**Encounters.** The `Encounters` heading summarises the map's random
encounters (`off`, or `2 troops, every ~22 steps, danger tiles`), and the
rows beneath it edit the engine's own `encounters` block:

- `Troops` is the Inspector's usual list: `Shift+O` adds a row (naming a
  troop the table does not already use), `Del`/`Shift+X` removes the row the
  cursor is in, and removing the last row disables encounters and removes
  the empty block. Each row is a `Troop` picker over the project's troops
  and a positive whole-number `Weight` - the chance of a fight being that
  troop is its weight over the sum. Two rows can never name the same troop:
  PHP would keep the last weight and discard the other without a word, so
  the editor refuses the edit and says which row already has it.
- Each troop row has `Areas`: the map's named areas (see `Areas` above the
  map's events) the troop is met in, typed as area ids separated by commas,
  or left empty for `(whole map)`. A troop with areas is met only while the
  player stands in one of them, and a step where no troop can be met does not
  count, so a map whose troops all live in areas is quiet between them. Only
  the map's declared areas are accepted. Renaming an area renames it in every
  troop that names it, in the same undo step, and an area a troop names
  cannot be deleted until the troop no longer names it. Written
  `'Roost Bats' => ['weight' => 3, 'areas' => ['east-roost']]`.
- `Rate` is the **average** number of steps between fights, not an exact
  interval - the engine rolls each gap between half and one-and-a-half
  times this value. Until you set one it shows `(engine default: 15)`, and
  merely opening or browsing the map never writes that default into the
  file.
- `Tiles` chooses which steps count: `encounter` (only danger tiles - the
  `;` glyphs) or `any` (every step, with danger tiles counting double).
  Its default, `encounter`, is likewise shown in parentheses and never
  written by browsing.
- In the GUI editor, `Arena` picks the battle presentation's arena a graphical
  fight here takes place in, `(default arena)` leaving it to the
  presentation's default, and each troop row has an `Arena` of its own that
  outranks the map's, `(map arena)` leaving it to the map. A troop with an
  arena is written `'Loch Ness' => ['weight' => 1, 'battleArena' =>
  'arena.secret-lake']`. Arenas are graphical presentation only: they change
  no weight, no fight and nothing the terminal shows, so the terminal editor
  keeps them exactly as written without offering them.
- `Arena Rules` (GUI editor) pick the arena for one of the map's areas, one of
  its encounter terrains, or a terrain within an area, each chosen from the
  map's own; Shift+O adds a rule and Del removes it. A rule for a terrain
  draws that arena outright; a rule for an area alone, the map's `Arena` and
  the default arena are refined by the arena's terrain variants, so a battle
  started in woodland is drawn on the region's woodland arena. A troop's own
  arena and `start_battle` arenas stay as authored. Written
  `'battleArenas' => [['area' => 'glade', 'battleArena' => 'arena.glade'],
  ['terrain' => 'woodland', 'battleArena' => 'arena.deep-woods']]`.
  Validation lists every arena and encounter terrain a battle on the map
  starts on without a variant, which draws the arena's own background, and
  every rule the Engine leaves out.

A key inside the block or a troop's entry the editor does not own - a field a
later engine will read - is preserved untouched, and keeps the block alive
even when the last troop is removed; an entry written as a map stays one. A
block shaped in a way the editor cannot hold exactly (troops keyed by number,
a weight that is an array, an arena that is not a key) is shown read-only
with the shape named, and no edit anywhere rewrites it. Validation reports an
arena, in an encounter or a `start_battle` command, that the battle
presentation does not declare, or that a project without one names.

### Minimap landmarks

`Minimap Landmarks` authors the map's `landmarks` list, the places the
minimap marks (Engine `docs/maps.md`, "Active-map landmarks and discovery").
A landmark names its anchor and never copies its cells: an event by marker, a
world object by id, or a legacy transfer that carries an `id`. Moving the
event or object moves the landmark.

- `Unnamed Route Markers` counts the transfers no landmark describes. Every
  transfer is marked on the minimap at once without a name; describing one
  names it, keeps it secret or makes it an entrance or exit.
- On an event, the `Minimap` row says what the event is on the minimap. A
  transfer steps through `Unnamed route marker`, `Passage`, `Entrance` and
  `Exit`; any other event through `Not on the minimap`, `Building` and
  `Landmark`. Choosing a kind describes the event; choosing the first option
  removes the description. In the GUI editor a selected world object has the
  same row.
- `Landmarks` is the Inspector's usual list. `Shift+O` describes the next
  place no landmark describes yet; `Del`/`Shift+X` removes a landmark.
  `Anchor` is picked from the map's events, world objects and legacy
  transfers that no other landmark uses, and `Kind` from the kinds that
  anchor may be. Moving a landmark between a transfer and anything else makes
  it a passage or a landmark accordingly.
- `Id` is the landmark's stable id, shown on its heading. A new landmark
  takes an object's own id when it is a valid landmark id, otherwise the next
  free `landmark-N`; the row renames it to any id the Engine accepts that no
  other landmark on the map has. Other maps name a landmark as their atlas
  entry, and a rename changes their `atlas.parent.landmark` in the same undo
  step; when one of those maps cannot take the edit, nothing is renamed. The
  name's translation key stays. Saves remember what the party has learned by
  id, so a save that learned the landmark under its old id learns it again.
- `Category` says what the place offers, whatever its kind: the Engine's
  `inn`, `shop`, `home` and `organization`, or a category of the project's
  own (see Place Categories). An entrance into a shop is a route with the
  shop category. `(none)` leaves the marker to the kind.
- `Name` is the label the minimap shows once the place is known; clearing it
  removes the name. A first name takes the Engine's map-scoped translation
  key, shown and editable as `Name Translation Key`.
- `Secret` hides the marker until play reveals it explicitly; a secret says
  nothing about approach. Otherwise `Learned On Approach` and `Approach
  Distance (cells)` set how near the party must come, `(engine default: 3)`
  until set. `Revealed When` builds the world conditions that reveal it
  through play with the shared conditions editor; an empty list removes them.

Every edit is checked by the Engine's own validator against the map as it
stands and is one undo step. An event or world object a landmark anchors
cannot be deleted, and an anchored event cannot change between a transfer and
any other type, until the landmark is changed or removed. Validation reports
landmarks the Engine would refuse.

### Atlas

`Atlas` places the map in the atlas (Engine `docs/maps.md`). `Scope` is
`Local` (a town, a house, a dungeon; what a map is when it says nothing) or
`Global`, the overworld geography itself. `Terminal View` is how the
Terminal's map draws it: `Places` (simplified terrain and buildings from the
map's Terrain classifications, with a square for each location and a chevron
for each route, for outdoors) or `Floorplan` (rooms and corridors as simple
outlines, for interiors). It shows what the Engine reads for the map's
scope until an author chooses otherwise, and choosing that again says nothing;
graphical maps are unaffected. A local map's `Parent Entry` is the
landmark on another map that is its entrance there: a house's on its town, the
town's on the world. It is picked from the landmarks other maps declare, those
whose door leads to this map first; a global map has no parent. `Global Map`
shows where the chain of parents reaches the global map, as the Engine
resolves it. No coordinates are copied: moving the parent's door moves the
entry. An edit the Engine would refuse (a cycle, a missing landmark, too deep
a chain) changes nothing and says why. A landmark another map names as its
entry cannot be removed until that map chooses another, and deleting or moving
a map lists the maps that name it as parent. Validation reports an atlas the
Engine would refuse.

In Event mode, painting or selecting a marker with no definition opens the
Event Type picker. **Story Script** creates the engine's
`ScriptEventTrigger`. Its inspector exposes `Script Id`, `Mode`
(`action`/`auto`), `Reusable`, `Conditions`, `Sets`, and `When Blocked`.
Select `Script Id` and use the reference picker to choose an existing Common
Event; the value is not free-typed. Conditions and completion writes use the
same structured inspector-list controls as other event types.

An event triggers on exactly the cells painted with its marker, in any shape:
paint the same marker in two places, such as a town exit on the east edge
and another on the south side, and they are one event. The cells between
placements are not part of it. For a marker that fills a rectangle the
inspector's Position and Size edit that rectangle. For any other shape it
shows `Cells` (how many, in how many places) instead of Size: Position moves
every cell together, keeping the shape, and is refused when a cell would
leave the map or cover another marker; paint or erase cells to reshape it.

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
  movement-route step when a route-step row is selected); a troop member is
  added by choosing its enemy first
- `Shift+C` / `Shift+N` (Troops): clear the troop's members, or name the troop
  after them
- `Shift+X`: remove the last sub-list entry at the selected level
- `/`: filter the entry list
- `Ctrl+S`: save the current category

Every field edit, entry creation, entry deletion, and sub-list change is
undoable with `Ctrl+Z`. Undo is identity-pinned: it applies to the entry you
edited even after the selection has moved on.

Current behavior: deleting an entry takes effect in memory immediately, and the
file changes on the next save. Undo before saving costs nothing.

### Terrain

`Terrain` classifies the map's own cells for its atlas, so the Terminal's map
can show simplified terrain (the Engine's `MapAtlasTerrain` roles, such as
ground, roads, water, vegetation, obstacles, highland, buildings and
boundaries) around its place markers. It lists each
source layer in the order the map composes them. `Other Glyphs` gives every
non-blank glyph of that layer one role; each glyph the layer holds, blank
ground included, has a row of its own with its cell count, and a glyph's own
role outranks the layer's. `(unclassified)` draws nothing. Classifying any
glyph binds the layer; clearing the last one removes the binding, and an atlas
with nothing left removes its key. Only the glyphs are named: no grid,
coordinate or collision is copied, so editing the map changes what the atlas
shows. A layer the map no longer has stays listed with a way to clear it, and
validation reports terrain the Engine would refuse.

### Actors

An actor's Inspector opens with **Identity**: a `Definition Id`, which is
what a save resolves the actor by. A project that declares none is resolved
by display name, so the row says so, and renaming such an actor strands
every save that named it.

The row's action freezes the current name as the permanent id. When that
is the whole repair, it is one edit, written on Save. When other files name
actors by what the repair changes (skits, maps, other actors), both editors
first list every file it writes; confirming writes them all at once, not on
Save, and Undo restores them. Pending edits are saved or undone first.

**Attack Style** is the character's own weapon: the weapon type they fight
with when no weapon is equipped, part of who they are and with no stats
(`Unarmed`, the default, writes nothing). A battle's basic attack plays the
animation bound to the equipped weapon's type, else to this style, else to
`attack-unarmed`; equipment stays the stat-bearing upgrade. The row offers
the Engine's weapon types, writes them as the Engine spells them, and
validation reports a written style the Engine does not know.

**Nature** is what this actor is, as distinct from the class it shares with
others: an adjustment per canonical stat, applied on top of the class
baseline. Adjustments keep their sign, because being slower than the
baseline is a legitimate nature, and an adjustment of zero is removed rather
than written.

An actor may also declare named *natural variants* - one set of adjustments
per variant, with a `Default Variant` the game starts on. The game
**composes** the two: the fixed adjustments always apply, and the selected
variant is added on top, summing where both name the same stat. So a fixed
`attack 4` under a variant's `attack 12` is `16`, and a variant may also
take away what the fixed layer gave. Both layers are therefore shown and
both are editable - `Fixed, always applied` and `Variant <id>, added on top`
- with an `In force` row showing what they come to together.
`Editing Variant` chooses which variant the rows show, and is a view of the
pane rather than a change to the project. A variant the actor does not
declare contributes nothing, which leaves the fixed layer standing.

**Resolved Stats** is what each stat actually comes to, computed by the
game's own resolver rather than by the editor: the class baseline, this
actor's nature, permanent growth the party has earned, what it is holding,
and whatever a battle is doing to it, then capped. A row reads
`33 · 10 natural, +12 nature, +6 growth, +5 battle · 966 to the cap`, and
says `41 lost to the 999 cap` when the total runs past the cap - which is
the case worth seeing, since further adjustments there do nothing. Player
and enemy caps differ, and the preview never writes anything.

`Assumed Growth` chooses which permanent growth the preview pretends the
party has already earned - none of it, all of it, or one definition. Earned
growth belongs to a save file rather than to a project, so this is a fixture
for looking at: choosing one changes what the rows read and writes nothing.

**Optimize Preview** shows what the game's Optimize command would pick for
this actor, best first, scored by the game's own policy rather than by the
editor. `Policy` names which policy is doing the scoring. `Slot` chooses the
kind of slot being filled. Each candidate reads as its score followed by the
components that made it - `18 · attack +12, speed -20` - and a candidate the
project excludes from automatic selection says so instead of scoring zero.

Accuracy and Critical are deliberately absent from nature and from the
resolved-stat preview: the game does not resolve them as layered stats. They
*are* weighable by Optimize, because a piece of equipment carries them.

### Editable And Read-Only Categories

Whether a category can be written is *detected*, not assumed. On load the
editor evaluates the authored file and asks two questions: can every value be
written back out losslessly, and would a save drop a comment? A file of plain
data (an array literal of values, with no objects) is saved by editing its own
source, so comments, nowdocs and layout inside the data stay as written, a
change the source cannot express is refused rather than flattened, and a file
changed on disk since it was read is refused rather than overwritten. A category
is editable only when both answers are safe. Anything else is browsable, and the
status line says exactly why.

Both editors edit every category through the same service, so a value
changed in one reads the same in the other, and both save it the same
source-preserving way. The GUI shows a record's rows as the terminal does,
with the summaries the terminal keeps in its side panes (a class's curves, a
skill's effects, a quest's rewards) listed under them.

| Category | Backing file | Terminal editor | GUI editor |
| --- | --- | --- | --- |
| Actors | `assets/Data/Actors/*.php` | Editable | Same, plus `images.field2d` artwork selected for the established actor id |
| Classes | `assets/Data/classes.php` | Editable - levels, the experience curve, every stat curve, equipment types and skills learned | Same |
| Skills | `assets/Data/Skills/*.php` | Editable - one data record per numbered file, attacks, abilities and spells alike, with their effects; `skills.php` loads them | Same |
| Mastery Regions | `assets/Data/mastery.php` (`regions`) | Editable - the regions of the mastery tree every character shares, each with the nodes on its trunk | Same |
| Mastery Branches | `assets/Data/mastery.php` (`branches`) | Editable - each region's branches (element, aspect or non-elemental) with their nodes in the order they progress | Same |
| Mastery Maps | `assets/Data/Mastery/*.php` | Editable - one file per character stating their own terms for tree nodes: price, opening level, what each builds on | Same, plus a graphical page that places and links the character's nodes |
| Items | `assets/Data/Items/*.php` | Editable - one data record per numbered file, with scope, occasion, animation and effects; `items.php` loads Items, Weapons and Armors | Same |
| Weapons | `assets/Data/Weapons/*.php` | Editable - one data record per numbered file, with type, stats, element, affinities and equipment metadata | Same |
| Armors | `assets/Data/Armors/*.php` | Editable - one data record per numbered file, armor or accessory, with type, slot, stats and affinities | Same |
| Enemies | `assets/Data/Enemies/*.php` | Editable - one data record per file, which `enemies.php` loads | Same |
| Troops | `assets/Data/troops.php` | Editable | Same |
| Battle Entry | `assets/Data/battle-entry-rules.php` | Editable | Same |
| States | `assets/Data/states.php` | Editable | Same |
| Animations | `assets/Data/animations.php` | Editable | Same |
| Tilesets | `assets/Data/Tilesets/*.php` | Editable - name, RPG Maker sheets, missing-art tile, above and table tiles, shadow, pieces by id with their glyphs and tiles, and place icons (the piece shown for each place type) | Same |
| Common Events | `assets/Events/*.php` | Editable | Same |
| Income | `assets/Data/income.php` (`schedules`) | Editable - cadence, interval, idle timeout, pay, delivery, run conditions and an ordered pay-rate table; the id follows the name until a script names it | Same |
| Quests | `assets/Data/quests.php` | Editable - objectives (the target picked by type) with their waymarks, reward items with quantities, prerequisites, optional; the id follows the name until something refers to it | Same |
| Field Cues | `assets/Data/Presentation/field.php` (`cueStyle`, `cues`, `waymarks`) | Editable - one row per setting: bob height and seconds, edge pointer spacing across and up or down, Terminal background and bold; each cue colour's mark effect, plate image and its eight pointer images and turns; the waymark colour, one-cell symbol and edge inset. Engine defaults show until set | Same |
| Skits | `assets/Data/Skits/*.php` | Editable | Same |
| Knowledge | `assets/Data/knowledge.php` | Editable | Same |
| Knowledge Reports | `assets/Data/knowledge.php` | Editable | Same |
| Knowledge Types | `assets/Data/knowledge.php` | Editable | Same |
| Knowledge Enemies | `assets/Data/knowledge.php` | Editable | Same |
| Permanent Growth | `assets/Data/permanent-growth.php` | Editable | Same |
| Optimize Weights | `assets/Data/equipment-optimization.php` | Editable | Same |
| Optimize Outcomes | `assets/Data/equipment-optimization.php` | Editable | Same |
| Optimize Exclusions | `assets/Data/equipment-optimization.php` | Editable | Same |
| System | `assets/Data/system.php` | Editable - title, starting gold, party, inventory and position, battle engine, openings and time gauge settings, whether a level up restores HP and MP (on unless turned off), and the dash (Dash, Dash Speed Multiplier) | Same |
| Configuration | `config.php` (`save`, `accessibility`, `ui`, `field`, `graphics`, `audio`, `inn`) | Editable - one row per setting, typed by what it holds; field zoom is kept from 1 to 8 | Same |
| Types | `assets/Data/system.php` | Editable - the project's elements, one per row; an empty list means the Engine's defaults. Weapon, armor and equipment types are the Engine's own | Same |
| Terms | `config.php` (`vocab`, `messages`) | Literal terms editable; comments and unrelated expressions preserved | Same |
| Dialogue Portraits | `assets/Data/Presentation/dialogue.php` (`actors`, `resources`) | Editable - one record per speaker identity: what it speaks as (an actor, chosen, or a non-actor character with a stable id), its dialogue portrait and skit bust, and its emotions, each a name with its own portrait and bust, chosen as project images | Same, with each image shown beside its path |
| Speaker Names | `assets/Data/Presentation/dialogue.php` (`speakers`) | Editable - one record per label lines are spoken under, and the dialogue portrait it shows | Same |
| Place Categories | `assets/Data/map-categories.php` (`categories`) | Editable - one record per category: stable id, name and translation key, and the generic category it specializes | Same |
| Map Roles | `assets/Data/map-roles.php` (`roles`) | Editable - one record per role: stable id, name and translation key, and the roles its maps may lead to | Same |
| Place Types | `assets/Data/place-types.php` (`types`) | Editable - one record per type: stable id, name and translation key | Same |
| Regions | `assets/Data/regions.php` (`regions`) | Editable - one record per region, keyed by its name: its skit backdrop, chosen as a project image | Same, with the image shown beside its path |
| Player Field Appearance | `assets/Data/Entities/player.php` | Not shown: graphical ownership and art | Choose fixed-player, selected party leader, or absent legacy selector; fixed sheet picker with index/layer controls |
| Field Resources | `assets/Data/Presentation/field.php` (`resources`) | Not shown: graphical resources | One record per named resource, keyed by its stable id: name, ground contact, an image or a sign board, and an optional occupancy stamp |
| Battle Arenas | `assets/Data/Presentation/arenas.php` (`arenas`) | Not shown: graphical battle presentation | One record per arena, keyed by its stable id (set when it is created): name, background image and optional crop, and the ground its picture shows: far edge (`Ground Far Edge`, just below the horizon or walkable edge), near edge and far-edge scale, each defaulting to the battle theme's. Every troop's formation stands on the chosen arena's ground. `Terrain Variants` name the arena drawn for each encounter terrain a random battle on this arena starts on (`variants`, terrain => arena), the arena itself when its picture already shows that terrain |
| Default Battle Arena | `assets/Data/Presentation/arenas.php` (`default`) | Not shown | The arena a battle that names none is fought on |
| Text Roles | `assets/Data/Presentation/text.php` (`roles`) | Not shown: graphical text | One record per text role: the project's fonts, first choice first, a size and a colour |
| Menu Theme | `assets/Data/Presentation/menus.php` (`atlas`, `icons`) | Not shown: graphical presentation | One row per setting: the minimap atlas's corner sizes, margin, marker size and frame, and an image per icon role |
| Actor Battle Art, Enemy Battle Art, Battle Scale | `assets/Data/Presentation/battlers.php` | Not shown: graphical battle art | Editable from the actor's or enemy's own page (see Battle Art) |

Player Field Appearance edits only `graphicalSubject` and `sprites2d`, preserving
the player's Terminal glyphs and unrelated authored source. Selecting
`party-leader` retains inactive fixed art read-only and uses the selected actor's
`images.field2d` role; missing leader art is diagnosed, never replaced with
another actor. Actor and fixed-player sheet pickers share current-file
four-direction previews. Clearing removes only the optional binding; undo/redo
and save/reopen restore its settings. Unsupported expressions and external
source conflicts are refused before overwriting files. These controls have
automated coverage; native visual inspection remains pending.

Menu Theme edits the game's shared menu presentation catalog by setting, as
Configuration edits `config.php`. It offers the minimap atlas: the compact and
larger corner sizes, their margin, the marker size and the frame (`quiet` or
`panel`) the atlas is drawn in, all in logical pixels of the GPUI atlas, and
the corner map's opacity, from 0 (clear) to 1 (solid); markers and the opened
map stay solid. Each
setting the catalog leaves out shows the Engine's default, and choosing that
default writes nothing. A value is checked by the Engine with its neighbours,
so the larger corner must first be widened before the compact one may exceed
it. Only changed leaves are written; comments and settings other pages own are
kept. A project without `menus.php` shows the category read-only and says why.
Menu Theme also binds icon images by role (`icons`): every role the catalogue
already binds, and every marker the atlas draws, offered even before one is
bound: `map.player`, each landmark kind, and `map.category.<id>` for each place
category, the project's own included as soon as its Place Categories record
exists. Each is a project image chosen with the shared picker; `(Engine
pictogram)` leaves the Engine's generic marker, and choosing it for a bound
role removes the binding. An image the Engine cannot draw is refused. The
Terminal's maps keep their own symbols, so the Terminal editor does not list
this category.

Place Categories name the kinds of place a game marks on its maps beyond the
Engine's generic `inn`, `shop`, `home` and `organization`: a school, a temple,
a guild. Each record has a stable id, a name with its translation key, and the
generic category it specializes, whose marker it shows until the Menu Theme
gives it one of its own. A new category starts as a kind of `inn`, the
Engine's first generic category, for the author to change. Its id may change
until a landmark uses it; while one does, the id stays and the category cannot
be deleted.

Map Roles name what a game's maps are to its world: an overworld, an outside
place, an interior, a dungeon. Each record has a stable id, a name with its
translation key, and **Leads To**, the roles a map of this role may lead to,
chosen from the project's roles; `(any map)` leaves it unrestricted. A map
chooses its role on its [Role](#map-role) row. Validation and the pre-save
checks then hold every way off a map of a restricted role to those roles:
transfer events, edge triggers and repeatable scripted transports, from events
and NPC pages alike. A way to a map of another role is an error; a way to a map
with no role is a warning, since it cannot be checked. A new role leads
anywhere until the author chooses. Its id may change until a map is of it;
while one is, the id stays and the role cannot be deleted.

Place Types name the kinds of place a game's maps lead into: a city, a town,
a village, a castle, an outpost, an inn, a shrine, a cave, a wild area. Each
record has a stable id and a name with its translation key. A map chooses the
type of its place on its [Place Type](#place-type) row, and each tileset gives
a type its icon under **Place Icons** on its Database > Tilesets record: a
place type and the piece shown for it, chosen among the tileset's pieces
stamped whole. Validation then holds every entrance on a map drawn with that
tileset to its destination's type. Its id may change until a map is of it or a
tileset gives it an icon; while one does, the id stays and the type cannot be
deleted.

Regions hold what a whole region shows, one record per region, chosen among
the region names the project's maps use. A region's **Skit Backdrop** is the
scene skits show on any of its maps whose own Skit Backdrop is none, interiors
included, so give a region one only where it suits every such map. A region
needs no record to be a region; validation warns about a record no map is in.

Field Resources are reusable whole images, such as a tree, that a map places by
reference. A resource owns its art and its ground contact (the pivot, such as a
trunk base) and may carry an occupancy stamp: cells relative to a placement's
anchor and the physical type a brush gives each when it places the resource.
The stamp is applied only by an explicit authoring placement; a resource never
adds collision to a map by itself, and changing a stamp never changes cells an
earlier placement stamped. A resource whose art paints a door, such as a cave
mouth or a town's front door, lists those cells under **Doorway**, relative to
a placement's anchor, each with its **Entrance**: `on` (the default) when the
player walks onto the painted opening, `front` when the door is painted on a
solid face and entered from the cell directly below it. Validation then
requires an entrance (a transfer event) exactly there wherever the resource is
placed, and none on a `front` door itself, so correct the doorway here or the
art itself when they disagree, never the map's entrance.
A resource whose art has a feature visuals attach to, such as an aerial a
signal pulses on, lists it under **Attachments**: a **Stable Id**, the
**Terminal Cell X/Y** that shows the feature, relative to a placement's anchor,
and the **Art Point X/Y (0..1)** where it is on the image, measured like the
ground contact. When the art is replaced or re-cropped, update the art point
here once; every visual staged on the feature follows it.
Only the `resources` dictionary is edited; the
catalogue's cues and action prompt are kept exactly as written. A new resource
starts on the project's first image for the author to replace, an id is refused
as it is typed if the Engine would refuse it, and a save the Engine would refuse
writes nothing. A map's world object that names a resource shows a Field
Resource picker in place of its own pivot and art, which the resource owns.
Every world object offers that picker, a new one included: choosing a
resource makes the object a placement of it, dropping its own pivot, art and
variants, and choosing none gives it back an art of its own, starting empty
on the ground, as a newly created object does. Every world object, a placement
of a resource or one with its own art, also has Visible When: the saved world
conditions under which the game shows it, all of which must hold. None means
always shown; clearing the list removes it from the map. Hiding an object
changes only what is drawn: its cells, collision and events stay as authored,
and the map's own glyphs show where it stood.

A resource shows an image or a sign board (Shows). A sign board names its
board image, cut into fixed left and right caps and a centre that repeats so
the board widens to fit its label, the text role its label is set in, the
label's padding and the board's width limits; cuts are in source pixels,
padding and widths in logical pixels. Switching what a resource shows keeps
the image chosen. Text Roles name the project's own font files, first choice
first, with a size and a colour: the Engine embeds no font, so a project uses
whichever fonts it has the right to. A new role starts on the project's first
font, and the file gains its schema id when it is first written. A role's
Glyph Effects give its letters an outline and a shadow together, each with its
own colour: a one-pixel shadow down and to the right lifts letters off a board,
and a light one cut the same way reads as engraving. Choosing None removes them.
Effects written in the file as constructor calls are shown but never rewritten.

A sign is placed as a world object naming a sign-board resource, with the
text its board shows. Its label is translatable from the start: the text is
the default, under a translation key of the sign's own id
(`signs.<id>`), which translations supply. A sign without text, or text on
what is not a sign, is refused before anything is written; changing a
placement's resource to a sign gives it a label, starting on the board's name
for the author to change under Sign Text, and changing it away removes it. So
a sign can also be made by creating an object at a cell and choosing its board
as the Field Resource. Neither the label nor the board changes the map's collision. The GUI
editor's Game view draws a placed sign's board and label as the game does,
laid out by the window in its text role; until the label is laid out, the
sign's cells show their glyphs.

Forests are drawn with the tree brush. A tree type is any image resource with
an occupancy stamp, its trunk. Choose the types to vary between and drag over a
region: the brush plants whole trees across it, choosing type and spot in an
order fixed by the map and cell, so drawing the same region again plants
nothing new and drawing beside existing trees fills the gaps between them.
Crowns may overlap; trunks keep half a crown apart. A trunk goes only on
plain floor that no event, NPC or other trunk takes, never outside the
region, and never where making it solid would cut a walkable area in two, so
every cell the player could reach stays reachable, grass, exits and save
points included. Each trunk becomes solid in
the map's own collision, never through its image, so the brush asks you to
Separate collision first on a map that does not have it yet. Erasing removes
the trees standing in the region and makes their trunk cells walkable again,
unless another tree still stands there. The terminal sees the same forest on a
`canopy` gameplay layer the brush owns and redraws, on top of the map's other
layers: bright green `(` `)` crowns, as wide as the image above the trunk and
narrowing upwards, over gray `|` trunks. Its glyphs never decide collision,
which the map's own collision holds. Each tree covers the canopy cells it
draws, a crown shared by two trees going to the tree drawn first, so the
graphical field and the Game view show the tree's image there instead of its
glyphs, and the glyphs again if the image cannot be shown. A canopy left as a
decoration layer, which the terminal never draws, becomes gameplay the next
time trees are drawn on the map, even where none are planted. A drawing that would take the map past its 128 world objects is
refused with the number that still fit. Each drawing or erasing is one undo
step and is written on Save. The GUI editor's Trees tool chooses the types and
covers the region with any brush shape, Shift or the eraser erasing; in the
terminal editor, `Ctrl+P` offers **Map: Draw trees in the selection**, which
varies between every tree type, and **Map: Erase trees in the selection**.

Why a category can still turn out read-only: a file the editor cannot
evaluate, a value it could not write back out (such as an object only its own
factory may build, which a rewritten file could not call), or a comment inside returned
data that holds objects or is not an array literal are each a reason, and the status line names it. You can
still browse everything: an enemy shows its level, every stat, its sprite,
its battle rewards, and its element affinities.

### How Data Files Are Written

Files that are data - Troops, States, Permanent Growth - are regenerated as
data, keeping everything from `<?php` to the top-level `return` byte for
byte, and a file several categories share is folded from all of them into
one payload before its one write.

### One Record Per File

An enemy is one file under `assets/Data/Enemies`, returning
`['class' => Enemy::class, 'data' => [...]]` with plain values: name,
level, sprite, stats, rewards, element affinities, state resistances, its
knowledge subject, and action patterns that name a skill from the skill
catalogue with a rating and an optional condition. Its drops are a list
under the Drops heading: each picks an item, weapon or armor and gives its
chance from 0 to 1. Validation reports a drop that names no item or one the
project does not define, as it does for any record's references. `enemies.php` is the
barrel that loads the folder, so the game and every other reader get the
same enemies. Editing a value changes only that value in that enemy's file,
keeping its imports and comments. A new enemy gets a file named after it
and starts on the project's first enemy sprite; a duplicate gets a file and
a name of its own. A file in the folder that is not an enemy record stays
in the list, read-only, saying why.

A skill is one file under `assets/Data/Skills`, returning
`['class' => Skill::class, 'data' => [...]]`. Its kind is a value: `basic`
(an attack), `special` (an ability) or `magic` (a spell, which also states
its effect type). The rest is cost, cooldown, occasion, scope, how it is
invoked, its animation, and its effects: a list under the Effects heading,
each a type with that type's own values (a formula with its element and
variance for damage and recovery, a state for adding one, the states to
remove, a stat and its stages, or what a reveal uncovers about a foe and
its chance). A reveal's facts are picked from the Engine's own list: `hp`,
`mp`, `stats` and `affinities` (the element that strikes the foe hardest). Changing an effect's type, or a skill's
kind, drops the values only the old one read. Files are numbered
(`0001-attack.php`) because menus list skills in file order; a new or copied
skill takes the next number and goes last. `skills.php` is the barrel that
loads the folder.

A skill whose values its author has not decided yet (its MP cost, when it
can be used, whom it reaches, its effects, or one effect's amount or spread), or whose effect the
Engine does not offer yet, lists those values under Awaiting Author
(`unassigned`) and leaves them out of the record. The Engine keeps it as a
draft: its name is taken and the mastery tree can place it, but nobody can
learn, cast or be given it, and nothing guesses the missing values.
Validation lists every draft and what it awaits.

The mastery tree, in `assets/Data/mastery.php`, is shared by every
character: anyone can reach any node. Its regions (Mastery Regions) hold
nodes on their trunks, ungraded applications not yet placed on a branch.
Its branches (Mastery Branches) each grow from a region and practise one
element, one aspect, or the region's non-elemental practice. A branch may
instead grow Within Branch another branch of its region: the game shows it
as a sub-family inside that branch's family, with its own heading, Summary
and count, and where the outer branch has a foundation, the sub-family's
chain grows from it. Its node ids stay as they are, so saves are unaffected.
Their nodes
are listed in the order they progress, and each node's kind places it in
the chain. A foundation comes first and may grant a skill or nothing yet;
graded applications (I, II, III) climb from it; advanced applications
follow the highest grade, and an advanced application that Extends another
(a single-target spell's multi-target evolution) follows that one and
stands below it on the board; a special grade runs from its demi phase to
the full phase that extends it. Each node builds on the step before it, plus
anything it also requires. A node may carry a price and opening level for
everyone; without a price, only characters whose own map prices it can
learn it. Validation warns where the tree prices a node below the step it
builds on, or opens it earlier. A character's Mastery Map states their own
terms for some nodes: those replace the tree's terms for them in full,
including what the node builds on, and validation warns where their terms
leave out a chain step. A node whose skill is a draft, or that nothing
prices, is unavailable: the game offers it to nobody until its author
finishes it. So is a foundation that grants no skill while nothing built on
it can be learned yet. A character who learns a node built on such a
foundation counts the foundation as learned too. Each region and branch
has a Summary, and a region with trunk nodes also has a Trunk Summary for
the family those nodes form: the game's Info shows them on the
discipline, the family and its foundation. Validation warns where one is
missing, because Info then offers nothing there.

Items, weapons and armors are one file each under `assets/Data/Items`,
`Weapons` and `Armors`, returning `['class' => InventoryItem::class, 'data'
=> [...]]` with a `kind` of `item`, `weapon`, `armor` or `accessory`, a
stable id, and only the values the record sets: the Engine's defaults fill
the rest. An item has its scope, occasion, battle animation and effects: a
list under the Effects heading, each a type (HP or MP recovery, resurrection,
HP damage, or a max HP or MP increase) with a value, a success rate and
whether the value is an amount or a percentage. Equipment has its type,
parameter changes, element and affinities, and its metadata (form, size,
material, slot, availability, acquisition policy, special property). An
armor that becomes an accessory drops its equipment type. Files are numbered
because shops and menus list definitions in file order, items first, then
weapons, then armors; a new or copied record takes the next number and goes
last. `items.php` is the barrel that loads all three folders. A record the
game cannot build, such as a sell rate outside 0 through 10000, is left out
of the game and reported by validation against its own file, in the Engine's
own words.

Equipment is shown everywhere, in every renderer, by the one icon of its
type (one for all swords, one for all daggers), which the game's theme
binds. A weapon's or armor's own `icon` is legacy compatibility data: the
Weapons and Armors records show it as a read-only `Legacy Icon` row and keep
it exactly as written. A consumable item's `Icon` stays its own and
editable.

A tileset is one file under `assets/Data/Tilesets`, named by the id maps
give it, so renaming a tileset keeps its file. It names its RPG Maker sheets
(each picked from the project's PNGs), the tiles drawn above characters and
those drawn as tables (tile identities, comma-separated), the shadow its raised
tiles cast (casters, width and opacity, all three or none), the tile that marks
missing art, and its pieces, keyed by piece id. A stamped piece has glyph
rows, an optional effect, and tile rows on each tile layer; a connected piece
(`Connects: lines`) has a glyph for each shape and a tile on each layer, one
for every shape or one per shape (`horizontal: 5888, vertical: 5890, corner:
5892`). A new piece gets an id no other piece has, and an id another piece
already has is refused, since keys are what maps stamp by. Validation reports
a tileset the Engine would refuse, whether or not a map uses it yet.

### Project-Owned Parameters

A weapon or armor's **Special Property** parameters and a Permanent Growth
definition's **Metadata** are the project's own vocabulary: the game carries
them without reading them. Both are edited as one line of `name=value`
pairs, and the line reads back exactly what was written:

- Pairs are separated by commas; spaces around names, values and commas
  mean nothing.
- Bare, `true` and `false` are booleans, `INF`, `-INF` and `NAN` are the
  floats digits cannot spell, digits are an integer, digits with a fraction
  or an exponent are a float - `1.0` stays `1.0` and `1.0E+20` stays exact -
  and anything else is the string it spells. A leading zero makes an
  identifier such as `007`, not a number.
- Anything that would read back as something else is quoted: the string
  `"true"`, the string `"1.0"`, an empty string `""`, a value with a comma,
  an equals sign or a space at either end, and a name with any of those.
  Inside quotes exactly two escapes exist, `\"` for a quote and `\\` for a
  backslash; any other backslash is refused rather than silently dropped.
- Nested lists and maps do not fit on the line. They are not shown, and they
  are not touched.
- A line the editor cannot read - a name without a value, a value with a
  stray quote, an unknown escape - is refused with the reason on the status
  line, and the record is left exactly as it was.

### States

Status effects, read by the engine's `StateRegistry`.

Fields: `Id`, `Name`, `Icon`, `Description`, `Duration Turns`, `Tick Formula`,
`Prevents Action`, `Persists After Battle`, `Disposition` (`harmful`, the
default, `beneficial` or `neutral`: whether it harms or enhances its bearer,
which battle poses follow), and `Confusion Chance %` (0 to 100: the chance
each turn that the bearer attacks a random battler of either side instead of
acting as it chose).

`Id` is the only field the engine requires. Optional fields disappear from the
file when you clear them, so a state with no duration lasts until it is cured
rather than being written as `0`. `Tick Formula` is the same formula language
skill effects use, with `$target` bound to the afflicted battler.

### Animations

An animation names what plays on the caster and the target of a battle
action: its **Caster Effect** and **Target Effect**, each one of the
project's effect timelines (authored on the Cutscenes screen, where
terminal glyph frames are timeline tracks), picked from a list. `Id` is
read-only; a new animation takes the next number after the largest. Like
every Database category, edits are undoable and only the values you change
are rewritten in `assets/Data/animations.php`, so its comments stay.

**Roles** say which battle actions play the animation when they name no
animation of their own. Enter opens a picker of every role the Engine
supports; each pick adds or removes one role:

- `attack-sword`, `attack-staff`, `attack-flail` and the other weapon types:
  a party member's basic attack with that weapon equipped;
- `attack-unarmed`: a party member's basic attack with no weapon;
- `attack`: enemy and other basic attacks;
- `skill` and `restorative`: skills and restorative magic with no animation
  of their own.

A role plays only when exactly one animation holds it, so the picker names
the animation that holds each role, and taking one held elsewhere is refused
until it is removed there. Validation reports a role the Engine does not
support (the runtime then skips the whole animation), a role held twice, and
a role the project's battles reach with no animation: the neutral attack,
the unarmed attack, and each weapon type its weapons use.

An older animation that carries its own frames and cues keeps them exactly as
written, shown read-only as **Legacy Frames** and **Legacy Cues** beside its
**Legacy Position**; the Engine still plays them through its importer. New
animation is authored as effect timelines.

Such a record can be converted to a timeline: **Shift+T** in the terminal,
or **Convert to timeline** on its page in the GUI editor. Both use the same
conversion, which lists what plays the record now (skills, items, roles and
field scripts) and asks for the timing its consumer needs. Nothing is filled
in for it:

- Who plays the timeline:
  - a battle paced by its phases;
  - a battle at a fixed rate;
  - a field script at a fixed rate.
- For a battle, whether the record names it as its **Target Effect** or its
  **Caster Effect**.
- The timeline's name, its frames per second (for a fixed rate), the ticks
  each original frame lasts, and the rest frame shown with reduced motion.
- Whether the record's flash cues become flash tracks. Battles have always
  played them; field animations never did.

Preview shows every file as it would be written: the new
`assets/Animations/<name>/<name>.timeline.php`, and for a battle the record
naming it. In the terminal it opens a review of those files: Up/Down read
them a line at a time, Enter writes them, and Esc goes back to the choices.
The pane follows the cursor, so the selected choice and every reviewed line
are shown at any terminal size. Write applies exactly that preview as one
undo step. A changed
choice, or a file changed since, needs a fresh preview, and pending edits are
saved or undone first. The record's legacy frames and cues stay as written,
and field scripts keep playing the record until their own command names the
timeline.

### Troops

Encounter groups. Each troop has a `Name`, an optional `Escape Policy`, and a
list of members flattened into the settings pane as `Member 1 Enemy`,
`Member 1 X`, `Member 1 Y`, and so on. An omitted escape policy preserves the
engine default (`allowed`); choose `forbidden` for a battle that must be won or
resolved by its authored continuation.

Members are composed as in RPG Maker's Troops tab. `Shift+O` opens the
project's enemies (type to narrow, `Enter` to choose) and adds a member
fighting the one chosen, with the cursor on it; `Esc` adds nothing.
`Shift+X` or `Del` removes the member the cursor is on, `Shift+C` clears every
member and `Shift+N` names the troop after its members (`Regular Bat x 2,
Marsh Wisp`), numbering a name another troop already has. Each is one undo
step. A new troop starts with no members.

A new member starts at the blank terminal position and has no `Battle
Placement`. Set its terminal `X`/`Y` here. The graphical battle stands every
member without a placement itself, beside the placed ones, in a staggered
echelon spaced by the members' current art, front rank on the ground's near
edge. A placement is `x, depth, width, height` (and optionally a display
scale): the column the member's feet stand on and how far back from the
arena ground's near edge, in canvas units, so one formation stands on every
arena's own ground; width and height are its movement and legacy fitting
limits. Set it on the GUI editor's troop canvas (Align, Arrange
Automatically, or dragging a member) or type it into the row. A placement
written as a fixed canvas `y` (before October 2026) is refused with what to
do: place the member again.

The terminal and graphical positions are independent: the terminal `X`/`Y`
never moves a graphical battler, and the graphical placement never changes
the terminal battle.

`Battle Music` names a track in `assets/Audio/BGM` that the battle plays in
place of the project's battle theme; left unset, the project's theme plays.
Keys the editor does not show, such as a troop's `id`, are kept as they are.

A member's `Cannot Fall Below HP %` (the Engine's `knockoutFloorPercent`,
0 to 99) holds it above that share of its HP against every kind of battle
damage, so an event page can end the fight before an ordinary knockout.

`Event Pages` are the troop's battle events, as in RPG Maker. `Shift+O` on the
heading adds a page that runs on turn 1. A page has a `Name`, a `Span`
(`battle`: once per battle; `turn`: once per turn; `moment`: at every check
where its conditions hold) and conditions, every one of which must hold:
`Turn` with `Then Every N Turns`, `Enemy` (chosen from the troop's own members,
as `1 · Djin`) with `Enemy HP At Or Below %`, `Party Member` with `Party Member
HP At Or Below %`, and `World Conditions` (switches, variables, story events).
A blank or 0 leaves a condition out. `Enter` on its `Commands` opens them; the
command types offered are the ones a battle page can run, including `concede`
(an enemy yields: it counts as defeated, stays on screen and does not fade out
like a knockout) and `end_battle` (victory, escape or defeat). A member a page
names cannot be removed until the page names another, and pages follow their
members when another member is removed. Saving refuses a page the Engine would
refuse, with the Engine's own message, and validation reports the same.

A troop may also declare a `Classification` - `ordinary` or `boss` - which
battle-entry rules match against. An omitted classification is the engine's
`ordinary` default; the picker shows that without writing the key into older
data.

### Counter Attacks

An actor, an enemy, a state or a non-magic skill can grant a **Counter
Attack**: the skill its bearer answers with when a physical hit lands on
it, after the attacker has returned, never chaining into another counter.
It is off unless chosen. The picker offers only skills the Engine accepts
for a counter (a battle-usable basic or special skill aimed at one living
opponent), and choosing none removes the grant, as the Engine reads one
left out. A state grants it while active, and a skill once learned; spells
do not grant one. Validation reports a grant the Engine would refuse, with
the Engine's own reason.

### Battle Art

The art an actor or enemy fights with in a graphical battle is bound to it as
data in `assets/Data/Presentation/battlers.php`, the Engine's battler
bindings file. Per actor (by definition id) or enemy (by name) it holds:

- Image: base artwork, picked from the project's PNGs.
- Ground point: where the battler stands on its image, from 0 to 1 across and
  down. It defaults to bottom centre.
- Poses: each role the battle shows (idle, attack, guard, knockout and the
  rest), a still or a sheet of frames with columns, rows, frames, frames per
  second, looping and a rest frame.
- Body profile: its size against the scale reference, and the share of its
  image that size measures.

The file can also name the scale reference: the actor every battler's size is
measured against, and that actor's height. Image sizes are always read from
the files, so replacing an image needs no edit here.

This is graphical presentation, so it is set in the GUI editor, on the actor's
or enemy's own page, and the terminal editor does not list it. Each battler
has one owner. Art the project's `battle.php` registers in code stays there:
the editor says so and does not rewrite program code. A battler's art is set
here once it moves to `battlers.php`. A save that the battle could not read is
refused before anything is written, and so is one that binds a battler
`battle.php` also binds.

### Battle Entry

Rules the engine applies as a battle begins, in
`assets/Data/battle-entry-rules.php`. Each rule grants temporary stat stages
to specific actors before the first action, and may commit durable world
writes when it succeeds. The editor authors the file; the engine alone
decides eligibility, applies effects, and rolls back - nothing here
duplicates that runtime.

Each rule has:

- a required unique stable `Id`;
- an optional integer `Priority` - the runtime defaults it to `0`, lower
  runs first, and the pane shows that default without writing the key;
- an optional `Classification` (`ordinary` or `boss`) matched against the
  troop's own, defaulting to `ordinary` the same silent way;
- `Actor Predicates`, a required non-empty list built in its own editor:
  `a`/`d` add and remove a row, `n` picks the actor from the project's
  durable actor identities (never typed, never a display label), and
  `x`/`X` cycle the presence through `active`, `reserve`, and `any` - the
  entry roster the actor must occupy when the battle begins;
- optional `Conditions`, the same shared world-condition editor every other
  surface uses;
- required non-empty effects, flattened into the pane as `Effect 1 Type`,
  `Effect 1 Actor`, `Effect 1 Stat`, `Effect 1 Delta`, and so on -
  `Shift+O`/`Shift+X` append and remove. The one effect type is
  `stat_stage`; the stat picker offers exactly the engine's stage-capable
  seven (`attack`, `defence`, `magicAttack`, `magicDefence`, `speed`,
  `grace`, `evasion`), and the signed delta is stored exactly, never
  clamped or applied by the editor;
- optional `World Writes` through the shared write editor, restricted here
  to the engine's reversible transactional vocabulary: `switch`, `event`,
  and `variable`. Quest acceptance cannot be authored in this category -
  its confirmation flow cannot be rolled back - and one already in the
  source is preserved untouched but reported by validation.

Rules run in priority then declaration order, so the order of the list is
part of the data: `[` and `]` move the selected rule, `Shift+D` duplicates
it under a fresh id, and both are undoable like any other edit. The
Execution Order pane beside the settings shows the exact order the runtime
will use, with the selected rule marked.

Reordering with `[` and `]` is offered only where a category's file
actually stores its list order - here, and in the knowledge categories,
whose saves write the list back in record order. A plain or
constructor-authored data file keeps its authored entry order (the editor
writes those entries in place), and a per-file category has no list order
to store, so the move is refused with the reason in the status line rather
than pretending an order the next reload would lose.

Validation checks the whole file with the engine's own diagnostic wording -
file, rule id, field, offending value - including duplicate ids, empty
required lists, unknown or contested actor identities (a reference two
definitions claim fails loudly rather than resolving to either), stats that
are canonical but not stage-capable, and quest writes. A missing file means
no rules and no finding, exactly as the runtime treats it.

### Income

Recurring pay for the party, in `assets/Data/income.php`: a salary, a
stipend, rent. A project without the file has no income; saving the first
schedule creates it. The Engine's income guide (`docs/income.md` in the
Engine) describes the runtime.

Each schedule has:

- a `Name`, shown as the title of its notices; the `Id` follows the name until
  a script names the schedule, and then stays, so `payday`, `collect_income`
  and `adjust_income` keep resolving. A schedule a script names cannot be
  deleted; the status line lists the scripts;
- `Runs When`, the shared world-condition editor: while the conditions do not
  hold, nothing is counted and `payday` pays nothing;
- `Counts`, the cadence: `active_time` (seconds of play, menus and idle time
  excluded), `steps`, `battles` (battles won) or `event` (only `payday`);
- `Pays Every`, the seconds, steps or won battles per payment. The `event`
  cadence has none, and changing to it drops the value;
- `Idle After (s)`, for `active_time` only: how long without input before play
  stops counting. The Engine's 120 shows until set;
- `Pay`, the amount per period;
- `Delivery`: `deposit` (straight into party gold, the default) or `hold`
  (kept until a `collect_income` command collects it);
- `Pay Rates`, an ordered table flattened into the pane as `Rate 1 When`,
  `Rate 1 Pay`, and so on; `Shift+O`/`Shift+X` add and remove a row. The first
  row whose conditions hold when a payment is made sets that payment, so a
  rank variable, a story event or a quest can set the pay. `Pay` applies when
  no row holds.

Validation reads each schedule as the game will and names every one the game
would refuse. The notices' wording lives in Terms (`messages.income.*`).

### Terms

The `vocab` and `messages` trees of the project's `config.php`, flattened to
one row per term with its dotted path - `vocab.game.new_game`,
`messages.confirm.quit`, and so on.

Terms and Configuration share one source-preserving configuration owner.
Saving either writes all pending configuration edits in one atomic operation.
Only edited literal values are patched; comments, spacing, enum references and
unrelated executable expressions remain untouched. An opaque term expression
is individually read-only, and ambiguous parent arrays or nonliteral returned
configuration are refused rather than regenerated. A config changed on disk
must be reloaded before saving, so newer external edits are not overwritten.

### Configuration

The rest of the project's `config.php`: the `save`, `accessibility`, `ui`,
`field`, `graphics`, `audio` and `inn` settings, one row per setting with its dotted
path. A switch is a switch, a number a number, and a setting authored as an
enum case (a selection colour, a window position) is a choice of that enum,
written back as the case. A setting the engine reads with a default is listed
even where the file leaves it out, showing that default; choosing the default
writes nothing.

#### Save Point Script

`save.point_script` is the event script that runs when the player steps onto a
save point, picked from the project's event scripts (None keeps the Engine's
save point notice, worded in Terms as `messages.save.point_prompt` and
`messages.save.point_title`). Validation names a script that does not exist.

#### Volumes

`audio.master_volume` (default `75`) and, beneath it, `audio.music_volume`,
`audio.sfx_volume` and `audio.voice_volume` (default `100` each) are the
volumes the game starts at, each a whole number from `0` to `100`. A sound
plays at the master volume times its bus's volume times its own volume
(`audio.sound_volumes.<sound>`), so a game sets how loud music sits beside
sound effects and voices without editing any file. Players change all four
in the game's Options and Config; their choices stay in their player
settings, and these values only decide where a new player starts. A value
outside the range is refused before anything changes.

#### Always Dash

`field.always_dash` is the first value of the player's Always Dash option, a
switch (off by default, as in RPG Maker). Players change it in the game's
Options and Config; with it on they dash without holding Dash. It applies
only to a game whose Dash is on in Database > System.

#### Field Zoom

Open **Database > Configuration > graphics.field.zoom**. Enter a finite number
from `1` through `8` (`2.5` is valid); the default is `1`. This writes the
game's `config.php` at `graphics.field.zoom`, not `system.php` or
`ichiloto.json`. It magnifies the GPUI field only: terminal maps, menus and UI
sizing do not change. `Ctrl+S` saves, `Ctrl+Z` undoes, and `Ctrl+Y` redoes. Undoing the first
edit restores an originally absent setting without leaving a default entry.
The same pending Terms edits are included when this configuration is saved.

### Knowledge

What the party can come to know, in `assets/Data/knowledge.php`. The file
holds several lists and the vocabularies they share, so the editor opens it
as two categories and writes back everything it did not edit exactly as it
read it.

**Knowledge** authors the subjects. A subject is anything the party can
learn about: a creature, a person, a place, a practice. Nothing here needs
an enemy - a subject the party never fights is an ordinary record - and
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
order, and the reports it disagrees with - each one a sub-list row picked
from the project's reports rather than spelled, because a disagreement
spelled by hand is a disagreement with nothing. The file keeps the flat list
of ids the game reads.

**Knowledge Types** authors `recordTypes`: the kinds of record a subject can
be. A subject's `Record Type` is picked from what this declares, so this is
where a new kind comes from.

**Knowledge Enemies** authors `enemyMappings`: which subject an enemy is a
record of. Both sides are picked - the enemy from the project's enemies, the
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

Runtime progress - what has actually been discovered, observed or unlocked
- lives in a save, not here. This is the catalogue those records point at.

### Permanent Growth

Stat increases the party can earn and keep, in
`assets/Data/permanent-growth.php`. A definition has a stable `Id` (what the
game grants and what a save records), an optional `Label` for recognising it
here, the `Stat` it moves, a signed `Amount`, and its provenance: a
`Source Type` for what kind of thing granted it and a `Source Id` for which
one. Both are deliberately open - the game imposes no vocabulary of sources,
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
only - there is no event-script command for it** - so a project defines what
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
`absorb` or `neutral` - what the game derives from an affinity multiplier. A
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

Fields: `Id`, `Title`, `Where (map id)`, `Backdrop`, `Conditions`,
`Speed (chars/sec)`, then each beat's `Actor` or `Non-actor Speaker`, its `Emotion` and its `Text`.
The emotion is chosen among the speaker's emotions (see
[Dialogue Portraits and Emotions](#dialogue-portraits-and-emotions)).

- `Where` gates the skit to one map id, matched case-insensitively. Leave it
  empty to make the skit available anywhere.
- `Backdrop` is for a skit whose content needs one particular setting
  wherever it plays. Leave it `None` and the skit shows the backdrop of the
  place it plays in: the map's [Skit Backdrop](#skit-backdrop), else its
  region's. Validation warns where a skit would show none.
- `Conditions` is the shared world-condition line described under
  [Condition Lines](#condition-lines).
- `Shift+O` and `Shift+X` add and remove beats.

`Shift+A` creates a new skit as its own file, named after its generated id.

Current behavior: a skit plays at most once per save file, and the engine
announces availability rather than interrupting - the player presses `T`.

### Dialogue Portraits and Emotions

The game's dialogue catalogue, `assets/Data/Presentation/dialogue.php`, says
whose artwork a line shows. Two pages edit it.

**Dialogue Portraits** holds one record per speaker identity. `Speaks As`
says whether it is an actor's identity, chosen among the project's actors
by stable id, or a non-actor character (a parent, a shopkeeper) with a
stable id of its own. Each identity has a `Dialogue Portrait` (the dialogue
box's) and a `Skit Bust` (the skit stage's), and an `Emotions` list: each
emotion has a `Name` and its own portrait and bust. Images are chosen with
the shared project image picker and stored as paths under `assets`; a missing
emotion image falls back to Neutral's and then the identity's own, as the
game draws it. `Shift+O` and `Shift+X` add and remove emotions.

**Speaker Names** binds the labels lines are spoken under ("Mom", "Fira Nel")
to an identity. A line spoken under a label shows that identity's artwork; a
label the page does not list shows none. Several labels may share one
identity.

Every line the game reads an emotion for has an `Emotion` row: an event or
cinematic `text` command, an NPC's dialogue line, a skit beat, and a map
Dialogue or Shop event's page. It is chosen among the emotions of the line's
speaker: an actor id names its identity directly, and a label is resolved
through Speaker Names (an NPC line with no speaker of its own speaks under
the NPC's name). `Neutral` is always offered and is what a line without an
emotion shows. A line that holds an emotion its speaker lacks keeps it, and
an `Emotion Warning` row under it says so; the game shows the identity's
Neutral artwork until that emotion exists.

A catalogue that builds its identities with PHP code (a loop over
directories) is shown read-only on Dialogue Portraits, saying so, since a
save would replace that code with its result; Speaker Names stays editable
when its labels are plain data. Validation reports a speaker name bound to
no identity, an image the game cannot draw, and a line emotion its speaker
lacks.

### Common Events

Cutscene and event command lists, one file per script under `assets/Events`.
A map's `ScriptEventTrigger` runs the script whose `scriptId` matches the
filename stem.

The entry list shows script ids. A script made from the Terminal is named
`new-event-script`; the GUI editor's session (`database.create` with an
`identity`) and editor change batches can name it at creation, in id form
(`payday-notice`), and that name becomes its file. A name another script or
file already has is refused.

The settings pane flattens the command list:
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
| `common_event` | Event Script |
| `knowledge` | Operation, Subject, Report, Replacement Report, Observation, Outcome, Source, Confidence |
| `move_player` | X, Y |
| `move_route` | Subject, NPC Id, Wait, Seconds Per Step, Speed, Steps |
| `transfer` | Map Id, X, Y |
| `start_battle` | Troop, Result Variable, Defeat Policy, Escape Policy |
| `recover_party` | None |
| `branch` | Conditions, Then (fixed), Else (fixed) |

Registered commands follow the built-in ones in the `Type` options: the
Engine's `shop`, `inn`, `payday`, `collect_income`, `adjust_income` and `recruit_actor`, then any the project declares in
`assets/Data/script-commands.php` (see the Engine's story events guide). Their
rows come from their declarations:

| Type | Fields |
| --- | --- |
| `shop` | Buy Rate, Sell Rate, Merchandise (Item, Price) |
| `inn` | Question, Speaker, Cost, Wake At X / Y, Rest Music, Result Variable |
| `payday` | Schedule (picked from Income), Result Variable |
| `collect_income` | Schedule (picked from Income), Result Variable |
| `adjust_income` | Schedule (picked from Income), Amount (negative for a deduction) |
| `recruit_actor` | Actor (picked from Actors; required) |

A command's first list, such as a shop's merchandise, is edited like a
route's steps: with the settings cursor on one of its entry rows, `Shift+O`
adds an entry and `Shift+X` removes one. A further list in a project command is shown
read-only and kept as written. The Editor reads the declarations without
running the project's handler code; the game checks the handlers when it
starts. Validation reports a malformed declaration file, a registered command
missing what its declaration requires, and any resource it names that the
project does not have.

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

The shared route fields also preserve `waypoints` and `retrace` as distinct
authored modes. A waypoint's omitted X or Y keeps the subject's coordinate for
that leg; adding a point leaves both axes blank until authored, not zero.
Incomplete new or changed routes refuse Save without discarding the draft or
undo history. Unchanged legacy data survives unrelated edits.

A waypoint, an independent `stage_actor` and a staged cast member may instead
stand relative to a map subject, the Engine's `at`/`offset` position. `Relative
To` chooses the subject kind from the Engine's exported list: the player, an NPC,
a world object (graphical editor only, as for every subject picker), a staged
actor (cinematics only), a party actor or an event marker. A named subject's id
is picked from what exists (the map's NPCs, world objects and markers, the
cinematic's cast); the player and the leading party actor take none. `Offset X`
and `Offset Y` are whole cells, zero when left out. Choosing a subject removes
the point's X and Y; clearing it from a waypoint is refused rather than inventing
a coordinate. A visual bound to a real subject inherits its position and is never
offered a subject or offset; binding one removes them. A subject still waiting
for its id refuses Save. The map placement view shows each relative point at its
subject's cell on the owner's map (an event's or NPC's own map, a cinematic's
Start Map), or says why it cannot: the player, party actors and staged actors are
placed only while the scene runs, and a common event runs on whichever map calls
it. A relative point is changed through its subject and offset, never by a map
click.

An independent staged visual relative to a world object may also sit on one of
the **Attachments** its field resource declares, such as an aerial: the
`Attachment` row offers that object's attachments. The visual then shows on the
attachment's terminal cell and, in the graphical game, centred on its point of
the object's current art, so replacing the art carries it along. It takes no
offset, and choosing another object or subject clears the attachment.
Validation reports a visual whose attachment the resource no longer declares.

A common event (a reusable event script) is edited with the full cinematic
command vocabulary, staging included, since cinematic sessions call it. Its
map-local choices (world objects, their attachments) are on the map its callers
run it on: a map whose events or NPCs call it, a cinematic's Start Map, or the
map a calling script runs on. When its callers run it on several maps, or none
yet, it offers no map-local choice. A transfer earlier in a caller is not
followed here; validation follows it.

The same waypoints and independent staged actors also name the way their
character faces once there, the Engine's end facing. `Facing` fixes a cardinal;
`Face Toward` picks a subject from the same list as `Relative To`, then its id in
the `Face Toward …` row (the player and the leading party actor take none). The
character turns toward that subject's cell when the leg or staging begins; an
even diagonal faces north or south. Choosing one clears the other. A waypoint
with neither keeps its last step's heading (`(last step)`); a staged actor with
neither faces south. A visual bound to a real subject inherits its heading and
offers neither row. A subject still waiting for its id refuses Save.

`Remember As` declares a session-local identity. `Recorded Route` selects only
declarations in that script invocation and its referenced common events,
including nested command arms, not other NPCs, dialogue variants, map events or
cinematics. Ordinary NPC/map/common-event sessions and cinematics all support
recording real subjects. Runtime verifies completion, subject/map/generation,
endpoint and one-use consumption; the reference choice does not guarantee a
conditional record has executed. Retrace uses actual successful movement, not
authored reverse geometry, and records disappear at completion or cancellation.

Graphical clients request these choices with the explicit owning row context:
`references.list` keeps its optional `record` argument as `{category,index,frame}`
for Database, `{kind:'npc',index,frame}` for NPC, or
`{kind:'event',marker,path}` for an inline event, with the owning `map` for the
latter two. Existing `{category,index}` cutscene requests remain compatible.
Omitting the owner does not create a global recorded-route catalogue. This
transport contract does not require graphical controls in the Terminal editor.

A `choice` command's options and a `branch` command's `Then` / `Else` arms
are not flattened into this list. Each arm opens as its own frame, described
under [Command Frames](#command-frames).

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
rows the same way. Inside a frame the pane shows only that list - the same
rows, pickers, and Shift+O / Shift+X / Del as the top level, at any depth -
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
across to the Database, and `Ctrl+P` offers `Cutscenes: Cinematic`,
`Cutscenes: Summon` and `Cutscenes: Effect`. Five panes: **Types**
(Cinematic, Summon or Effect), the asset **list**, the **record pane** (the
asset's fields, grouped), the **Command Tree** (for a summon or an effect,
the **Timeline**) and the **Preview**. `Tab` and
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
| Effect | an effect timeline battle animations, the field and cinematics play | Cutscenes › Effect |
| Skit | an optional conversation overlay | Database › Skits |
| Animation | a reusable visual asset a command plays | Database › Animations |

### Files, identity and preservation

A cutscene is one folder holding a pair of files, named after the folder:

```text
assets/Cutscenes/Cinematics/<id>/<id>.data.php
assets/Cutscenes/Cinematics/<id>/<id>.script.php
assets/Cutscenes/Summons/<id>/<id>.data.php
assets/Cutscenes/Summons/<id>/<id>.timeline.php
assets/Animations/<id>/<id>.timeline.php
```

An effect is its timeline file alone, exactly as the engine's effect library
reads it: no data file, and an id of lowercase letters, digits, `_` and `-`
(no dots). The stable id *is* the folder name; the display name is a field. A new
asset's id is yours to choose until its first save makes the folder; after
that it is read-only (duplicate under the new id and delete the old one to
migrate). The list shows `*` for unsaved work and a folder the engine cannot
read as read-only, with the reason in the record pane; an orphaned file, a
misnamed pair, an id that disagrees with its folder, or two folders that
differ only in case are reported by validation and in the list.

The editor holds a pair as one record: the data file's keys plus the
script's commands or the timeline's keys. It offers that record for editing
only when it can prove the merge is exactly reversible -- that splitting it
returns the two arrays that were read, key for key. A pair it would have to
normalise is shown read-only with the precise reason, and never rewritten:
commands keyed by name that the tree would renumber, a `commands` value that
is not a list, a script that is a bare map of settings with no `commands`
list, or a top-level key authored in both files, where the merged record
could hold only one of the two values. Unknown keys and commands it can hold
exactly are not a reason to refuse; they stay editable and survive the save.
This matters beyond the one asset: saving any cutscene writes every record
of that type back through the same path, so a pair the editor could not
reproduce must be left alone rather than reshaped because something else was
edited.

Saving rewrites only what changed, in place: headers, comments, local
variables, heredocs and nowdocs, shared ASCII blocks, unknown keys,
backslashes, trailing spaces and wide glyphs all survive, a data-only edit
leaves the script or timeline file byte-identical, a no-op save writes
nothing, and multi-line text is written as a nowdoc. A value shared through
one source variable is edited in place when only the selected entry uses it,
and otherwise retargeted to a new variable so nothing else changes. A
variable the file builds with its own code is never replaced by a copy of
its result: an edit under it is refused, naming the variable.

A save is one transaction over the pair, and so is a first save, an
overwrite and a deletion. Each proposed file is staged beside its
destination and read back, so what the engine evaluates and hydrates is the
exact bytes that will be installed; the backup is taken once for the pair,
before anything destructive; then both files are installed. If either cannot
be, every file already touched is put back -- the same bytes, at the same
modification time -- and a folder the save created is removed, so a refused
save leaves the complete old pair or, for a new asset, nothing at all. In
the rare case where putting a file back also fails, the editor says so and
names the file rather than reporting that nothing changed.

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
| `field_animation` | Effect (picker), or Animation and Seconds Per Frame; Target (kind, id or x/y) |
| `title_card`, `narration` | Title, Text (multiline), Seconds |
| `transition` | Style (`fade`, `wipe`, `none`), Direction (`in`, `out`), Seconds |
| `clear_presentation` | none |
| `cinematic_music` | Track, Loop, Fade In, Fade Out, On Completion (`continue`, `stop`, `restore_previous`) |
| `move_route` | Subject (`player`, `npc`, `staged_actor`), NPC Id or Staged Actor, Seconds Per Step or Speed, Wait, Steps |

A `field_animation` plays either an effect timeline from `assets/Animations`,
chosen by its stable id, or a legacy animation from the Animations database.
The effect owns its frame rate, so choosing an Effect removes the Animation
and Seconds Per Frame rows and their values; clearing the Effect brings them
back. Validation plays the effect through the Engine for both presentations
and reports one that is missing, cannot be read, or loops: the command waits
for its effect to end, and loops belong to a map's own field effects.

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
`+battle` and `+?` instead of guessed. A field effect is as long as its
timeline, the longer of its two presentations, and is marked `+?` when its
timeline cannot be read. `Up` / `Down` move over the rows and
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
doing, the checkpoints recorded, the music the engine is playing (and
whether it is the field's own track, restored), who owns field input,
whether a skip would be accepted and why not, and the log of launches,
transfers, battles and failures. Dialogue
waits for you as it would for the player; a battle resolves as a victory
on the next step (the pane says so); a transfer loads the destination map.
The preview starts from the map event that triggers the cinematic when one
exists, otherwise from the start map at its first open tile. It writes
nothing: not your saves, not your files.

Missing or malformed preview maps are reported beside the controls; the stage
remains usable on explicitly identified undefined terrain. The diagnostic
follows the current map and clears after a successful transfer. GPUI restarts,
resizes and reopened views reconnect to the new scene automatically, even when
the grid is unchanged; a retired view cannot supply its picture or feedback to
the replacement. An effect without a selected start map now previews on
diagnosed undefined terrain instead of refusing to open.

`Ctrl+T` plays the saved cinematic in the real game: a new game starts on
the cinematic's origin cell, and the Engine plays the cinematic once the
player has arrived, through the same controller a trigger would start it
with. No map file is copied or modified for it.

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
current policy takes; `C` on the Preview pane runs the cinematic twice -
watched to the end and skipped at once - and lists every observable
difference between the two final states, so an unfinished finalizer shows
up as a row rather than a surprise. What it compares: the final map, the
player's position and facing, the camera, the staged cast, switches,
variables, story events, checkpoints, the music (the track playing, whether
it loops, and whether it is the one the field was playing before the
cinematic began), who owns ordinary field input, and whether saving is
available again.

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
characters picked by stable actor id, as the battle matches them, so
renaming an actor never changes who may call it; tenancy `shared` or
`exclusive`), **Playback**
(default speed, allow skip, loop preview, transitions in and out, effect
timing by `end`, `cue` or `frame`, target presentation) and **Timeline**
(the Sequence row, format version, FPS, length in frames, rest frame,
editor metadata, and the Tracks and Cues frames). `allowSkip` is authored
data; whether a battle honours it is the engine's business, and this manual
claims nothing more.

**Sequence** works as an effect's does: a summon whose timeline every
renderer shares stays that way unless you choose `separate`, which gives it
a terminal and a graphical sequence, each a copy of the shared one (undo
puts the shared file back). The definition (identity, wielders, playback
and the rest) stays one for both; the timeline rows, Tracks and Cues edit
the sequence chosen, and the other is kept exactly as written. A key a
sequence did not hold, such as a first rest frame, is written into that
sequence.

#### Stage

A summon's graphical sequence may have a **stage**: a presentation space of
its own, in stage units, shown in place of the arena from its start frame
until its restore frame, when the arena, the formation and the battlers'
current state return. The terminal sequence never has one. In the graphical
sequence, the **Stage** row is `none` or `added`; adding gives the sequence
the smallest stage the engine reads (a `1280, 720` canvas shown from the
first frame to the last, its camera on the canvas's centre at its own
scale), and `none` takes it away, each one undo step.

With a stage, its rows are **Stage Canvas** (`width, height`), **Stage Start
Frame**, **Stage Restore Frame** and **Stage Background** (`black`,
`white` or `#RRGGBB`; empty is black), and three frames to open:

- **Stage Subjects**: each subject has an id, a `Position` (`x, y`), a
  `Size` (`width, height`, the box its art is fitted to, not a copy of an
  image's size) and a `Pivot` (the point of that box at its position, `x,
  y` from 0 to 1; empty is the bottom centre). `Shift+O` on a subject row
  adds a named **point** to it, such as `chest` or `ground`: an id and an
  `X` and `Y` from 0 to 1 across and down its box.
- **Stage Camera**: keys in frame order from frame 0, each a `Focus`
  (`x, y`, the stage point at the centre of the view), a `Zoom` from 0.125
  to 4 and an `Easing` to the next key (`linear`, `hold` or `smoothstep`;
  empty is linear).
- **Stage Covers**: full-screen colour keys in frame order, from frame 0 to
  a fully clear key on the last frame, each a `Color`, an `Opacity` from 0
  to 1 and an `Easing`.

An image track whose `Anchor` is `stage` is placed on the stage: its
`Subject` and `Subject Point` are picked from the stage's subjects and
their points (none: the stage origin, or the subject's pivot), with an
`Offset` and a `Size` in stage units (empty: no offset, and the subject's
size), and a `Z Index` among the stage's art. Battler rows (cells, facing,
battler attachment) do not apply on the stage. Its keyframes add an
`Offset` and an `Opacity` (empty is 1). The sequence's rest frame is also
the stage's reduced-motion view, so it must fall inside the stage's frames
with art showing; a save the engine refuses names the stage row it refuses,
such as `stage.camera[initial].zoom`.

#### Tracks, keyframes and cues

Open `Tracks` and each track is a row (`Id`, `Type`: `glyph`, `text`,
`image`, `flash`, `shake`; `Presentation`: `all`, `terminal` or `graphical`, the
renderers that draw it) followed by its keyframes: frame, duration, position,
content, asset id, color, visible, z-index, blend mode, easing and a
free-form payload; an image track has the image rows an effect's has.
`Content` opens the **multiline editor** (`Enter` on the
row), which keeps every space, backslash, blank line, tab and wide glyph
exactly as typed or pasted; `Ctrl+S` there commits, `Esc` cancels. Open
`Cues` for the cue rows: id, frame, type (including `restoreBattlefield`,
which clears flash, shake and effect art and redraws the field) and
payload. `Shift+O` adds a
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
log - audio cannot be hosted here, so a cue is reported, never claimed
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
actor must be eligible under its wielder policy (by character id, by role,
or open to all), a story-locked summon cannot be a starting assignment, each
id appears once, and an exclusive summon has at most one starting holder
across the cast.

### Effects

An effect is edited like a summon's timeline, over the keys the engine's
effect library reads and nothing else. The record pane groups **Identity**
(the id), **Sequence**, **Timing** (FPS, length in frames, playback `once`
or `loop`, loop from, rest frame), **Impact (battle)** (when a battle
command's result lands: at the `end`, a `frame` or a `cue`) and
**Timeline** (the Tracks and Cues frames). Tracks are `glyph`, `text`,
`image`, `flash` or `shake`, each with its `Presentation` (`all`,
`terminal` or `graphical`), an anchor (`target`, `caster` or `screen`) and,
for a battle stroke drawn one way, `Facing` (`west` or `east`; the empty
choice removes it, and the stroke stays as drawn). An image track names its
PNG through the asset picker, its sheet columns and rows, its cell size, its
`Fit` (`stretch` fills the cells; `contain` keeps the sheet cell's own
proportions inside them; the empty choice removes it, so it stretches), its
depth and its `Pivot` (the point of the sheet cell placed at the effect's
position, two finite coordinates `x, y` from 0 to 1). The GUI's image-cell
picker sets that point through the same undoable record edit. Empty removes
the authored pivot: field images use bottom-centre (`0.5, 1`), while battle
images keep their centred default (`0.5, 0.5`). Opening or saving an effect
does not materialize either default in the file; an effect shared between
field and battle keeps each consumer's default until a pivot is authored.
The picker marks an explicit pivot, or the default for the current preview
context when omitted, and labels which it is. While a shared effect's preview
context is still loading, no default is guessed. Stage images retain their
centred default. In battle only, `Attachment` selects `center`,
`head` or `ground` on the battler; empty removes it and uses the centre.
Field image tracks do not accept battler attachments. Image keyframes give
the sheet frame, the position and, in battle,
`Flip Horizontally` and `Flip Vertically` for an authored reverse stroke.
Glyph and text keyframes give frame, duration, position `x` and `y`,
content (the multiline editor), asset id, color, visibility, z-index and a
payload. Cues are `playSound` on the field and any battle cue in battle.
Impact timing, facing, image attachment, flips, flashes and shakes
are battle-only;
validation names the place that uses an effect where they are refused.

**Sequence** says how the effect is drawn. A flat effect has one sequence
for the terminal and graphical renderers alike, and stays flat unless you
choose `separate`, which gives it a terminal and a graphical sequence, each
a copy of the flat one (undo puts the flat file back). An effect with
separate sequences is edited one at a time: choose `terminal` or
`graphical`, and every row, the Timeline pane and the preview are that
sequence's; the other is kept exactly as written. Undo returns to the
sequence an edit was made in. The TUI has no graphical workflow: an image is
chosen by path and its frames by number, and pictures belong to the GUI
editor.

An effect may instead own a graphical stage. In the GUI, its Stage, subject,
attachment and camera controls use the same record services and Engine stage
contract as summons. The stage owns its surroundings: it is never previewed
against a fabricated battle or field. Its independent Terminal sequence keeps
its own tracks, cues and clock without needing to decode graphical images.
An incomplete stage can be edited and undone, but the Engine must admit it
before Save; removing it preserves the sequence's tracks and cues.

`Shift+O` on a Timeline row inserts the blank the effect's own lists take:
an image keyframe after an image keyframe, starting where the selected one
ends, and a track whose keyframe has a position `x` and `y`. A save is
compiled by the engine in its declared stage, or for battle and the field
when no stage is declared. Save is refused before writing unless each sequence
has a valid context; a paired Terminal sequence retains its independent
admission. `Delete` names everything that plays the effect first: battle
animations, the field presentation's cues and action prompt, maps' field
effects, tileset pieces, and the `field_animation` commands of map events,
event scripts and cinematics.

#### Effect preview

`Space` on the Preview pane compiles the sequence being edited, unsaved,
and plays it through the engine's shared effect playhead, with the summon
preview's keys (step, keyframe boundaries, `Home` / `End`, speed, `R`, `L`,
`X`); `O` loops, and an effect authored to loop starts looping, from its
loop frame. The frame is drawn as a terminal consumer places it: through the
engine's composition for that presentation, glyph and text at their anchor,
`C` marking the caster and `T` the target (`screen` draws from the top
left). Beside it, the context and what the terminal cannot draw: image
frames with their flips, flashes and shakes, each with its subject.

| Key (Preview focused, effect) | Action |
| --- | --- |
| `B` | Play as battle or as the field compiles it (recompiles; a refusal is reported) |
| `D` | Put the battle caster on the other side |

The preview starts as battle when a battle animation uses the effect or
nothing on the field does. In battle a stroke with a facing is turned toward
its target exactly as the battle turns it, so `D` shows both directions.
An owned stage instead starts in its declared context; `B` does not change its
ownership. The GUI GPUI tab paints the Engine's seekable stage canvas through
the runtime's shared painter. A stage error is shown rather than replaced by
a field frame, and late seek replies cannot overwrite a newer frame. The
Terminal tab shows its separately authored sequence, not a graphical imitation.

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
- An authored music **fade-out** finishes on the game's own clock: the
  engine completes it from its audio update, which advances by the game
  frame, and a preview owns its own clock instead. The preview reports the
  music state the engine has actually reached, so a cinematic that fades its
  music out at the end shows that track still playing. Completion behaviour
  stated without a fade - which is what the finalizer vocabulary requires -
  settles at once and compares exactly.

## Overlays

| Overlay | Opens with | Notes |
| --- | --- | --- |
| Help | `?` | Generated from the binding table; scrolls with arrows |
| Command palette | `Ctrl+P` | Fuzzy search over actions, tools, maps, categories, and event markers |
| Character map | `c` in Normal mode, or *Tool: Character Map* in the command palette | Insert glyphs the keyboard reserves; lists the map's symbols, the project's collision vocabulary, and the reserved glyphs |
| Status detail | `Ctrl+E` | Full text of the last message, and the log file path |

Current limit: the help overlay and command palette cannot open while a picker
dialog is up. Press `Esc` first - dialogs consume all input by design.

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
Read-only categories are never included - they hold no edits.

### Saves follow what a map makes solid

When a map is saved after an edit takes floor that the player could stand on
in the saved map, a save standing on such a cell would load inside it. That
includes walkable floor no route reaches today, since an older layout may have
reached it. A cell is
taken when it becomes something that blocks movement (furniture, a wall,
painted collision, trees) or when an NPC is added or moved onto it. Walkable
grass, exits and save points take nothing, and an NPC that stays put moves no
save. With a save compatibility
manifest (`assets/Data/save-compatibility.php`), `Ctrl+S` first shows each such
cell and where saves on it will land: the nearest plain floor the player can
reach today, found by walking from the cell, never an event or NPC cell.
**Save and move saves** writes the map and a `relocations` step that raises
the manifest's content version by one; **Cancel** saves nothing. The step is
its own undo: `Ctrl+Z` after the save removes it from the manifest, and undoing
the edit itself is then saved as usual. `Ctrl+A` leaves such a map unsaved and
names it, so its save is always reviewed on its own. A cell no open floor can
be reached from refuses the save until a path is opened or the edit undone. A
project without a manifest is saved at once, as before. The GUI editor shows
the same review when it saves. A cell that a resize cuts off the map is not
covered by this review; removing rows or columns lands saves through its own
review instead.

Saving writes through a temporary file and a rename, so an interrupted save
cannot truncate your work. When a save regenerates a data file, everything from
`<?php` up to the top-level `return` is preserved byte-for-byte: file
docblocks, `use` imports, blank lines, and the explanatory comment above a
cutscene all survive.

### Map saves are surgical, and one transaction

A map's `.data.php` is authored PHP the editor did not write, and the editor
never regenerates it. An edit is rewritten into the file as the smallest
change that expresses it: change the description and one line changes;
everything else - comments and blank lines, `namespace` and `use`
declarations, enum and class-constant expressions, `require` expressions and
the prelude variables they feed, string quoting, key order, trailing commas,
unknown and future keys - keeps its exact bytes. An entry added or removed
(an NPC, an event definition) is inserted or cut as its own lines; the rest
of the file is untouched. Structural edits find their entry by durable
identity, never by position: an event by its marker, an NPC by its stable
`id`, and a legacy NPC without one by its name while that name is unique on
the map (validation warns when it is not).

What the editor cannot rewrite reversibly it refuses, precisely: a value
written as an expression - `'script' => require …` in place - cannot take an
edit without destroying the expression, so that one edit is refused with the
map, file and path named, and nothing changes. A value written as a
*variable* follows how the file writes that variable. One written as data
(assigned once, by a literal, and named nowhere else but by plain
references) is edited where it is assigned when only this entry names it;
when several entries share it, the edited entry gets its own copy and the
variable stays for the others, unless the shared value holds comments,
variables or expressions a copy would lose, which is refused. A variable the
file builds with its own code (a `require`, a call, a loop that fills it) is
refused the same way as an expression: its code is never swapped for a
literal of what it produced. The refusal is that narrow: an opaque sibling
never blocks an edit beside it, and browsing, previewing and validation
always work. Only a data file whose whole source cannot be parsed for
preservation makes the map's *data* read-only - the grids stay editable, and
validation names the file.

The three files save as one transaction. Only the members whose content
actually changed are written at all - a metadata edit touches `.data.php`
alone; a tile stroke touches `.map.php` alone, byte and mtime, and a tile
file an author generates with a helper class is never rewritten by a data
edit. Each written member is staged beside its destination, the staged data
file is evaluated from the project root and must read back as exactly the
map being saved, backups are taken once per member being replaced (and only
those members - a clean save backs up nothing), and then the set installs.
If any member cannot be installed, every file already touched is restored to
its exact bytes and modification time, no temporary survives, and the map
stays dirty with its checkpoint unmoved - a refused save leaves the complete
old triplet, never new data over old tiles. The explicit move carries the
same guarantee, plus one more: the authored bytes must still evaluate at the
destination, so a `require` written against the old folder depth refuses the
move whole rather than installing a map the game cannot load.

Creating, duplicating and deleting a map are the same kind of transaction.
A new map's triplet installs complete or not at all - a failure leaves no
file and no folder. A duplicate carries the original's authored bytes with
only its display name rewritten, is proven to evaluate before anything is
installed, and is **refused** - before any file or folder exists - when the
source cannot be rewritten reversibly; duplicating never flattens a file the
editor promised to preserve. Deleting a map removes only its own three
files: anything else you keep in that folder (notes, sketches, references)
survives, the folder itself goes only once it is empty, and a failed
deletion puts every removed member back at its exact bytes and time.

### Stable map identities

A map's project-relative path is its stable identity - doors transfer to it,
quests reach for it, saves record it. The display name and region are
metadata: editing them saves in place and never moves or renames the map's
directory. A new map derives its initial path from its name once, at
creation; after that, ordinary saves never infer a rename.

Moving a map is its own operation - *Move Map to Derived Path* in the
command palette. It shows the current and proposed ids, requires an explicit
`y`, rejects collisions, and fails closed rather than half-moving. References
are **not** migrated: anything naming the old id keeps naming it, and the
prompt says so before you confirm.

A map may live inside another map's folder (a building's rooms inside it) or
inside a folder that is no map. The editor session creates, copies and moves
a map at a folder path the author chooses: `map.create` takes `id`, and
`map.duplicate` and `map.move` take `to`. A chosen path is checked before
anything is written: each part between slashes is lowercase letters and digits
joined by single hyphens, nothing may already stand there, and no map nested
directly in a map's folder may be called `layers` or `graphics`. A move to a
chosen path is reviewed exactly as a derived one is. Deleting a nested map
also removes the folders above it that it leaves empty.

### Dirty means "differs from the last save"

An asset is dirty exactly when its content differs from what the last
successful save wrote - not because a mutator ran. Undoing your way back to
the saved state clears the marker; redoing away restores it; saving partway
through history simply sets a new checkpoint; a failed save leaves the old
one intact. Setting a field to the value it already has, painting a tile
with its own symbol, or resizing to the current size creates neither dirt
nor a history entry.

Clean saves are no-ops, everywhere: `Ctrl+S` on an unchanged asset or
database writes nothing, keeps mtimes, and never canonicalizes authored
formatting. File-per-entry categories (actors, skits, event scripts) write
only their dirty, new, or explicitly deleted entries - saving one actor
leaves every other actor file byte-for-byte untouched.

### Validation

Before a map save the editor runs a validation pass and warns - never blocks -
on dangling destination references, event markers without definitions, and
spawn points outside the map. The warnings appear in the status footer;
`Ctrl+E` shows the full text. The project-wide pass (`ichiloto validate`) also
covers every NPC field, as described under [NPC Mode](#npc-mode). The GUI's
map inspector checks the open map the same way on request (**Check this map**,
`map.validate`), unsaved edits included, and lists every problem with what to
do about it, errors first; **Show** moves to the first cell a problem names.

Skills are read as one catalogue from their record files, as the game reads
them: a skill name defined twice, or a record the game cannot read, is an
error naming its file, and save aliases for spells must point at spells and
those for abilities at abilities.

Save migration steps are read as the Engine reads them: each declares a
project class, or declared position edits, `mapShifts` for inserted rows and
columns, `mapRemovals` for removed ones, `relocations` for cells authored
content now occupies (map, cells, and a landing that is not one of them) and
`mapRetirements` for a retired map whose saves move onto another map (map,
into, a landing `to`, and optional landing rectangles). A malformed step is
an error, and so is a retirement whose saves end on no map of the project:
its `into`, followed through later retirements and map aliases, must be a
current map. Relocations are written by hand when an NPC or furniture moves
onto open floor; add them to a new step, never to an earlier one.

Reachability is checked as the game moves the player, from where the game
starts and every way onto each map it can reach, never from where content
stands: moving an NPC or rebuilding a room is fine unless it traps something.
An event no reachable cell lies in, or an arrival outside the map or on a
wall, is an error; a talkable NPC with no reachable cell beside it, and a map
nothing reachable leads to yet, are warnings. Doors and edge triggers that
name a map the project does not have are errors of their own. On a map with
a kind, a cell the player can reach that no tile covers is a warning: the
game shows the dark background there, so paint its floor or close it off.

Effect timelines in `assets/Animations` are checked as each consumer plays
them: an animation's `sourceEffect` and `targetEffect` in battle, and the field
presentation's cue and action prompt effects, maps' `fieldEffects` and tileset
pieces' effects on the field. Each is compiled for the terminal and for the
graphical presentation, so an effect only one renderer would refuse is still
an error; until it is fixed that presentation keeps its fallback.

Screen transitions in `assets/Data/Presentation/transitions.php` are checked
as the Engine plays them, including treatments nothing selects yet: a file the
Engine refuses, a battle choice naming no treatment, or an image that is
missing or cannot be loaded is an error. Until it is fixed the graphical
renderers enter battle with a direct cut; the terminal intro is unaffected.

An inventory id two definitions claim is reported once, naming every
claimant with the category it was authored in, the aliases it brought, and
its record file - the game refuses the whole catalogue until one
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
The editor never writes into your source files in the background - "autosave"
here means a safety copy, not a silent write. Copies mirror the
project-relative path under the backup root, retention prunes oldest-first per
file, and a backup failure warns without blocking the save. The active policy
is printed in the `?` overlay.

## Playtesting

`Ctrl+T` starts a new game on the selected map at the canvas cursor, past the
title. The editor hands the terminal to the game and takes it back when the
game exits.

How it avoids touching your project: the game runs from your project's own
root and reads your live files, and the editor tells the Engine, through its
launch context, where to start and where to keep the playtest's player data.
That data lives in a fresh directory outside the project, holding a copy of
your player settings (so your volume and mute carry over) and nothing else:
settings changed during the playtest, its saves and its logs stay there, and
your own saves are never read or written. The directory is deleted when the
game exits. The Engine checks the start before the game takes any input, and
refuses a cell that is outside the map, blocks movement or has an NPC on it.

The map must be saved first: the game reads the file on disk, so the editor
refuses to playtest a dirty map rather than silently running the old version.

`Ctrl+T` needs the console binary, which it looks for in this order: the
project's own `vendor/bin/ichiloto`, any `vendor/bin/ichiloto` above the
installed editor package, then `ichiloto` on your `PATH`. Set
`ICHILOTO_CONSOLE_BIN` to override all of that. If none of them match, the
error names every path it tried.

## Theming

The editor adopts the look of the project it has open, reading two keys from
`config.php`:

- `ui.menu.border` - the border pack every editor window draws with. Any of the
  engine's packs works (`DefaultBorderPack`, `FancyBorderPack`, `SlimBorderPack`,
  `BoldBorderPack`, `CrossedBorderPack`, `HashedBorderPack`), given either as an
  instance or a class name.
- `ui.menu.selection_color` - the color marking the focused panel, the same
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
- Read-only Database categories are a safety decision, not a missing feature -
  see [Editable And Read-Only Categories](#editable-and-read-only-categories).
- This document should be updated whenever a new shortcut, panel workflow, or
  Database category is added. `tests/Unit/ManualCoverageTest.php` enforces the
  keybinding half of that automatically.
