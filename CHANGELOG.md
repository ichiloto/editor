# Changelog

## Unreleased

### Added

- Ordinary battle action cut-ins, as Engine e3bede2 plays them: an effect declaring the shared cinematic stage is
  authored with the same camera, cover, subject and stage image rows, selectors and undo as a Summon stage, and
  bound through an animation's existing Caster Effect and Target Effect pickers. Battle or the caller owning the
  stage admits it, chosen by the effect's consumer: battle when an animation binds it. Saving accepts a stage
  either consumer admits, validation reports a refusal at the consumer that names it, and the GUI host's
  `cutscenes.timeline` reply adds `stageContext`. The Terminal preview's `B` now moves a staged effect between
  battle and its owned stage, where it previously stayed on the owned stage.
- Skit backdrops that follow the place, through the Engine's `SkitBackdrop`: a map's `Skit Backdrop` Inspector
  row (`skitBackdrop`), a Regions Database page over the Engine's `RegionCatalog` (`assets/Data/regions.php`, one
  record per region name with its skit backdrop) and a skit's optional `Backdrop` (`backdrop`), each chosen with
  the shared image picker in the Terminal and GUI editors and stored as an asset-root-relative path. The map row
  says what its skits show when it names none (its region's backdrop, or none). Validation warns where a skit
  would show no backdrop, about a backdrop the game cannot draw and about a region record no map is in.
- Configuration offers how long each battle action category's announcement is read at each animation pace
  (`ui.battle.announcement_seconds.<category>.<pace>`, the Engine's `BattleActionCategory::getAnnouncementConfigPath()`),
  with the Engine's time as its default, and refuses a time outside 0.1 s to 10 s. Choosing the default writes
  nothing. The summon and effect battle previews read the authored time, as the battle does.
- Place Types, a Database page over the Engine's `PlaceTypeCatalog` (`assets/Data/place-types.php`): the kinds
  of place maps lead into, such as town, outpost or shrine. A map chooses its place's type on a `Place Type` row
  (`placeType`); a tileset gives each type the piece shown for it under `Place Icons` on its Database > Tilesets
  record, chosen among its pieces stamped whole. Validation warns at every entrance whose icon, in its tiles or
  terminal glyphs, is not its destination's type's (`place.icon`) or a place icon over a way into a map with no
  type (`place.untyped`), and names a map whose type the project does not have (`map.place-type`). A type stays
  while a map is of it or a tileset gives it an icon.
- Database > Tilesets authors the Engine's thickets in the GUI editor: a **Thickets** list (id, name, depth up
  from the feet, hidden part opacity, rustles, tiles) and a terrain kind's **Thicket**, chosen among the
  tileset's thickets (`tileset_thickets`). The terminal Database neither shows nor drops them.
- Skills author the Engine's `reveal` effect: its facts (`Reveals`, picked from the Engine's
  `reveal_facts`: `hp`, `mp`, `stats`, `affinities`) and its `Chance %`, shown in the Effects pane as
  `Reveal: hp, stats, 75%`. The effect Type list is the Engine's own (`SkillRecord::getEffectTypes()`).
- States author `Confusion Chance %` (`confusionChancePercent`): the chance each turn that the bearer
  attacks a random battler of either side instead of acting as it chose. Cleared, it leaves the file.

- Map review renders: `EditorSession::renderMapReview()` draws a map, or a
  rectangle of it, both ways as the game draws it, unsaved edits included,
  through the game's own field: the Terminal picture the field's camera draws
  through the Engine's Console, and a still of the graphical field the
  Engine's frame composer sends a renderer (world tiles, corner-edged terrain,
  tile shadows, glyph cells, NPCs, world objects, and the player or an actor
  on a cell), drawn by `Review\FieldStillRaster` in the renderer's paint
  order. `Review\MapReviewWriter` writes each view as `.ans`, a Terminal
  page, a native PNG and an optional scaled PNG, and one review page that
  sets every view's Terminal picture beside its still.
  `ChangeBatch::preview()` shows a session as a batch leaves it, then undoes
  the batch, writing nothing. `PreviewField::openProjectMap()` opens the field
  on a map as the Editor holds it, with the player optional.
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

### Removed

- The legacy-tag reading of map rows: an unrecognised tag such as `<blue>` is read as its glyphs, as the Engine
  reads it, no longer as a style.
- The dialogue catalogue's `skits` key: Dialogue Portraits and Speaker Names refuse a catalogue that still has it
  and name `ichiloto upgrade` (format 4), which moves each skit background to its skit's `Backdrop`.
- The refusal of `<` as a stamped piece's cell glyph (`tilesets.setPieceGlyph`). A piece cell now takes `<`, `>`
  and `\` like any other glyph; formatter markup or several characters offered as one glyph are still refused.

### Fixed

- `composer analyse` and a bare `vendor/bin/phpstan analyse` read the Engine named by `ICHILOTO_ENGINE_SRC`, as
  `vendor/bin/pest` does: `phpstan.neon` includes `tests/phpstan-engine.php`, which adds that checkout's source
  when the variable is set. Before, only `-c tests/phpstan-engine.php` read it, and the default run analysed the
  installed Engine and reported its missing classes and methods as Editor errors.
- A Crisis Abilities variant's Skill picker offers only the skills the Engine's `CrisisAbility` accepts, from a
  `crisis_skills` reference source; it previously offered every skill, summons and menu-only skills included,
  which the Engine then refused. A new record's first variant comes from the same source.
- Map rows are read by the Engine's `TerminalText::parseSourceCells()`, so the Editor reads every row exactly as
  the Engine does. A row the Engine cannot split into independent cells makes its map read-only with the row and
  reason, and a changed row is read back the same way before it is written. A cell's colour is read as the
  Engine's formatter shows it, closed styles included.
- A new Crisis Abilities record starts as one the Engine reads (active below 10% HP, on the project's first
  battle-usable basic or special skill) and saves; without such a skill, creation is refused and says so.
- A mastery branch's Within Branch offers the tree's other branches, never the branch itself.
- Removing the last entry of plain data writes `[]`, so adding a value and undoing it restores the file's bytes.

- A coloured `\` painted on a map, or set as a piece cell, no longer escapes its closing colour tag, and painted
  `<` and `>` cells no longer join their neighbours into a formatter tag. The Editor writes those cells through
  the Engine's `TerminalText::composeSourceCell()`, so the game, a reload and the terminal canvas read the glyph
  painted, in its column and colour. Rows nobody edited keep their authored bytes.
- Save relocation planning and workspace reachability read the current tileset catalogue, including a newly staged tileset in the same atomic transaction. Saved baseline reachability remains separate; missing or invalid current dependencies still refuse.

- Directory records identified by filename, including tilesets and event scripts, now honor explicit identities when creating and duplicating records. Unsafe or occupied names are refused, and generated names remain unique across unsaved records.

- Batch piece placement (`pieces.place` with `origins`) now honors an explicit `footprint: true` for every copy, with artwork and physical occupancy in one undo/redo step. Unrequested collision remains unchanged.
- `map:render` review pages carry their graphical stills inside the page, so they show when the page is opened or sent on its own; they used to reference the PNG files beside them and showed no pictures elsewhere.

- A preview field's Terminal picture draws its world objects' Terminal cells,
  as the game's field does; cinematic and effect previews left them out.
- A preview's graphical view draws field effects: the effect manager a
  graphical view installs learns the window's capabilities, where before it
  knew none and drew no effect.
- A preview field's map events are bound to its world state, as the game's
  are: an event cue shows in both pictures only while its event is
  available, and the Terminal picture draws event cues, which it left out.
  Before, the graphical view cued events whose conditions a new game has not
  met.
- Save relocations land saves only on floor the player reaches once the change
  is saved, and also move saves off floor the change walls off. A wall that
  closed off a pocket of floor reachable before the change could send saves into
  that pocket, or leave saves standing in it, where they loaded with no way out;
  moving a building onto open ground did both with its enclosed interior.
  Saves on floor an event now covers that no event covered before, such as a
  door moved there, move too, so loading one starts nothing new.

- World objects keep the footprint their resource stamps (a tree's trunk) in a
  map's own declared collision: making, moving, changing or deleting an object
  moves its solid cells with it, and a cell it leaves goes back to plain floor
  unless another object still stamps it. A footprint may only take plain floor,
  as the tree brush's trunks do, so moving a tree no longer leaves an invisible
  wall behind or arrives without one.

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
