---
title: Editing in the Editor
description: Tour the Ichiloto editor's panels, canvas tools, and safety net, then playtest any map from the tile your cursor is on.
category: Tooling Loop
tags: [editor, workflow, maps, playtest]
order: 52
readTime: 4
heroImage: /images/screenshots/editor-default-view.png
heroImageAlt: The Ichiloto editor with the assets panel, map canvas, and inspector side by side.
heroImageCaption: Three panels, one keyboard-first loop: pick an asset, shape it on the canvas, tune it in the inspector.
---
The editor opens any directory containing an `ichiloto.json`:

```bash
cd my-rpg
ichiloto edit
```

It runs on the alternate screen, restores your terminal on exit, and never writes to your project until you ask it to. The header carries a `*` whenever something is unsaved.

## Three panels and a footer

`Assets` lists every map in the project. `Canvas` previews and paints the selected one. `Inspector` shows the fields of whatever is selected. The footer reports your selection, mode, active tool, and the last message.

Move between panels with `Tab` and `Shift+Tab`, or directionally with `Shift+Arrows`. The focused panel is outlined in your project's own menu selection color — the editor reads `ui.menu.border` and `ui.menu.selection_color` from `config.php`, so it looks like the game it builds.

## Painting a map

The canvas has two layers. `%` switches to Map mode for tiles; `^` switches to Event mode for the letter markers that events hang off. Type a glyph to paint it, or reach for a tool:

- `Ctrl+N` cycles Brush, Line, Rect, Rect Fill, and Select
- `Ctrl+W` cycles brush width through 1, 2, 3, and 5
- `Ctrl+F` flood fills from the cursor
- `Ctrl+K` picks up the glyph under the cursor
- `Ctrl+L`, `Ctrl+X`, and `Ctrl+U` lift, cut, and stamp a selection

`Enter` is the universal verb: it paints, sets an anchor, and completes a shape. Every tool commits as exactly one undo step, so a filled rectangle undoes in a single `Ctrl+Z` rather than one press per tile.

## The safety net

Undo covers everything the editor treats as an edit — tile strokes, map resizes, event fields, database changes, entry deletions. `Ctrl+Z` and `Ctrl+Y` walk the history.

Saving is deliberate. `Ctrl+S` saves the selected map; `Ctrl+A` saves every dirty map and database in one pass. Writes go through a temporary file and a rename, so an interrupted save cannot truncate your work, and a save that would move a map folder asks first.

Before each map save the editor validates and *warns* — dangling destinations, markers with no definition, spawn points off the map. It never blocks the save; you stay in control of your own half-finished work.

Opt-in backups take a timestamped copy immediately before an overwriting save:

```json
{
  "editor": {
    "backups": { "enabled": true, "retain": 5, "directory": ".ichiloto/backups" }
  }
}
```

The editor never writes into your source files in the background. "Autosave" here means a safety copy, never a silent write.

## Playtest from the cursor

`Ctrl+T` launches the game on the selected map, spawning where your cursor sits.

It does this without touching your project: the editor assembles a temporary project root of symlinks back to your real files, swapping in a generated `system.php` that carries the playtest spawn and an empty save directory so playtest saves cannot overwrite yours. Your maps and assets are the live ones — the overlay is deleted when the game exits.

Save the map first. The game reads the file on disk, so the editor refuses to playtest a dirty map rather than quietly running the previous version.

## Finding your way

`?` opens a help overlay generated from the same binding table the editor dispatches on, so it can never document a shortcut that does not exist. `Ctrl+P` opens a command palette over actions, tools, maps, database categories, and the event markers on the current map. `/` filters whichever list has focus.

`Ctrl+G` follows the reference under the cursor — an actor to its class, an event to its destination map at the configured spawn — and `Ctrl+B` returns with your position and selection restored.

`Esc` always backs out exactly one level.
