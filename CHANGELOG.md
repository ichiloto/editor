# Changelog

## Unreleased

### Added

- Configuration offers the volumes a game starts at: `audio.master_volume`
  and, beneath it, `audio.music_volume`, `audio.sfx_volume` and
  `audio.voice_volume`, each listed with its Engine default even where
  `config.php` leaves it out and refused outside 0 to 100. Players set their
  own in the game's Options and Config.
- Dash authoring, as RPG Maker's: Database > System edits the Engine's
  `system.dash` (Dash, Dash Speed Multiplier, greater than 1 and at most 4).
  The map Inspector's Disable Dashing switch writes the map's `disableDashing`
  and removes it again, in the Terminal editor and the GUI session alike.
  Configuration offers Always Dash's first value (`field.always_dash`) under a
  new `field` root. Validation reports a dash or a map value the Engine would
  not read. The GUI session's walk measurement shows dashing seconds at the
  authored speed, unsaved edits included, and says why when the player cannot
  dash on the map.
- A tileset piece's **Painted Doors** record row (GUI Database), typed as
  `2,4; 4,4 front`, and each piece's doors in the pieces list. Glyph-footprint
  and physical-recipe authoring keep a piece's doors.
- Database > Tilesets, Terrain Kinds: `Joins` names a corner-edged kind's join
  group. Kinds on one tile layer naming the same group share one edge, so a
  river runs into a lake or the sea with no bank between them; validation's
  see-through ground check reads the joined edges as the game draws them.
- Database > Tilesets, Terrain Kinds: `Corner Sheet` chooses a kind's corner
  sheet among the project's PNG assets, so its edges are drawn on the corners
  between cells; none draws them in each cell. Validation warns when a map
  shows a kind whose corner sheet cannot be drawn, and judges see-through
  ground by the corner tiles a corner-edged kind draws on its cells and the
  cells beside them.
- Stacked pieces: stamping a piece on one of its tileset's stacks lays each
  tile into the cell's stack in depth order (further south, then further east,
  in front), refusing a cell that would overfill the stack. Database >
  Tilesets authors Stacks.

### Fixed

- Database > Weapons, Armors: clearing a parameter change (Attack, Defence,
  Speed and the rest) removes it from the record instead of writing a zero, so
  an accessory made from the armor blank can drop the Defence it started with.

- A `map:apply` batch or change set with a step that writes a set of files at
  once (`skills.retire`, `mastery.adopt`, inserting or removing rows, an
  actor's identity freeze) judges only the maps whose files changed. Reading
  the project again from those files used to give every map a new revision,
  so the batch counted every map as changed and errors already on maps it
  never touched refused it. A map read again that holds what it did keeps its
  revision, undo and redo of such a step name only the maps it changed, and
  an open GUI editing canvas starts again on the map read afresh instead of
  drawing the earlier reading.

- Painting a terrain kind clears a stack by whole object instead of by cell:
  an object goes when the paint covers every cell it shows on and otherwise
  stays whole, so painting the road beside a range no longer crops the peaks
  that overhang it. Painting part of an object no longer removes that part.

- Converting a map to terrain kinds removes the map's own atlas
  classifications of the glyphs it converts and of glyphs that name kinds,
  which the kinds now own; before, Last Legend's converted overworld kept
  `':' => 'obstacle'` beside the desert kind's `:` and the game refused to
  prepare it. Atlas terrain the game cannot read is reported as an error, not
  a warning.

## 0.5.0

Ichiloto Editor 0.5.0 delivers a full-screen terminal workspace for authoring,
validating, and playtesting Engine 0.5 projects.

### Highlights

- Added map authoring for metadata, music, encounters, events, transfers,
  collision geometry, and map-local NPCs.
- Added schema-driven database authoring for actors, classes, inventory,
  equipment, enemies, skills, quests, knowledge, event commands, and project
  policy.
- Added a first-class Cutscenes workspace for paired cinematic and summon
  assets, timeline lanes, frame editing, camera routes, cast assignments,
  previews, skip comparisons, and summon playback.
- Added in-place PHP source editing that preserves surrounding project code,
  formatting, stable identities, shared-file changes, and no-op saves.
- Added undoable tree and record operations, durable dirty-state tracking,
  structured pickers, contextual key handling, and responsive terminal panes.
- Added project validation for runtime references, identity conflicts, map and
  event contracts, battle escape policy, summons, achievements, and save
  compatibility manifests.
- Added isolated playtest overlays for maps and cutscenes while keeping project
  saves untouched.

### Requirements

- PHP 8.4.1 or newer within the PHP 8 release line.
- `ichiloto/engine` 0.5.
- Pest 5.1 for the development test suite.
