# Make a New Map

This guide covers creating a map, giving it a shape, and saving it.

## What A Map Is

An Ichiloto map is a folder under `assets/Maps` holding three files that share
the folder's name:

| File | Holds |
| --- | --- |
| `<name>.data.php` | Metadata, NPCs, event definitions, triggers |
| `<name>.map.php` | The tile layer — the glyphs the player walks on |
| `<name>.event.php` | The event layer — letter markers keyed to definitions |

The editor keeps the three in step. You paint the tile and event layers on the
canvas and edit metadata in the Inspector.

## Create The Map

1. Focus the `Assets` panel with `Tab`.
2. Press `Shift+A`.

The editor creates a folder under `assets/Maps`, names it `new-map` (or
`new-map-2`, and so on), and selects it. This happens on disk immediately — map
creation is not undoable.

## Name It And Set Its Size

1. `Tab` to the `Inspector`.
2. Move to `Name` and press `Enter`, type the display name, press `Enter`.
3. Set `Region` the same way — it groups maps, and becomes part of the map id.
4. Under `Size`, move to `X` and `Y` and use `Left` / `Right` to resize, or
   `Enter` to type a number.

Resizing is undoable. Shrinking a map discards the tiles outside the new bounds,
so `Ctrl+Z` is your friend if you overshoot.

Current behavior: the map id is derived from the region and name. Changing
either means the map's *folder* moves on the next save, and the editor asks for
explicit confirmation before it does.

## Paint The Tile Layer

1. `Tab` to the `Canvas`.
2. Press `%` to be sure you are in Map mode.
3. Move with the arrow keys and type a glyph to paint it.

For anything larger than a few tiles, use the tools:

| Goal | How |
| --- | --- |
| Draw a wall | `Ctrl+N` until the footer reads `Line`, `Enter` at one end, `Enter` at the other |
| Block out a room | `Ctrl+N` until `Rect Fill`, then the two corners |
| Fill an area | Move into it and press `Ctrl+F` |
| Copy a chunk | `Ctrl+N` until `Select`, mark two corners, `Ctrl+L`, move, `Ctrl+U` |
| Widen the brush | `Ctrl+W` cycles 1 / 2 / 3 / 5 |
| Reuse a glyph already on the map | `Ctrl+K` over it |

The footer always shows the active tool, brush width, pending anchor, selection,
and clipboard, so you never have to guess what `Enter` will do next.

Each completed tool action is one undo step. A filled rectangle undoes in one
`Ctrl+Z`, not one press per tile.

For glyphs the keyboard reserves, press `@` to open the character map, pick one,
and press `Enter`.

## Music And Random Encounters

Both live in the Inspector, under the map's identity rows.

Select `Background Music`, press `Enter`, and pick a track from the ones the
project has in `assets/Audio/BGM` — or `(None)` for silence, which removes
the key rather than writing an empty track. There is no audition in the
editor yet; `Ctrl+T` plays the real thing.

For fights, put the cursor on the `Troops` row and press `Shift+O`: the first
row enables encounters, and each row is a troop picked from the project's own
list with a whole-number weight (a troop's chance is its weight over the
sum). `Rate` is the *average* steps between fights — the engine rolls each
gap between half and one-and-a-half times it — and `Tiles` chooses whether
only danger tiles (`;`) count, or every step (`any`, danger tiles counting
double). Both show the engine default in parentheses until you set one, and
neither is written to the file by just looking. `Del` removes the row the
cursor is in; removing the last row turns encounters off and removes the
block entirely.

## Save

Press `Ctrl+S`.

The editor validates first and warns — never blocks — about dangling
destination references, event markers with no definition, and spawn points
outside the map. Press `Ctrl+E` to read a truncated warning in full.

All three files are written together, as one transaction: only the files whose
content changed are touched, each is staged and verified first, and if any of
them cannot be installed the others are put back exactly — an interrupted or
refused save can never leave new data over old tiles, or a half-written map.
A hand-written `.data.php` is edited in place, not regenerated: your comments,
imports and expressions survive every save, and an edit the editor cannot make
without destroying an authored expression is refused by name instead.

## Try It

Press `Ctrl+T` to playtest from the cursor. The map has to be saved first — the
game reads the file on disk, so the editor refuses to playtest a dirty map
rather than quietly running the previous version.

## Duplicating An Existing Map

`Shift+D` in the `Assets` panel copies the selected map to a sibling folder
named `<name>-copy`. This is usually faster than starting from scratch when you
want a variant of a room you already like.

Continue with [Add an NPC](add-an-npc.md) to put something on your map.
