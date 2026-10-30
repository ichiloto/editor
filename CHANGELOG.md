# Changelog

## Unreleased

### Added

- NPC beds, as Engine bf49893 reads them (`CinematicCommandSchema::export()['bedOccupancy']`): the NPC Inspector's
  **Bed** rows pick the world object on the NPC's map it rests in (`bed.object`, among objects whose field resource
  declares attachments), the resource attachment its occupant lies on (`bed.attachment`), its `Starting Posture`
  from the Engine's `BedPosture` (`lying`, `sitting`, `standing`) and its `Occupied Bed Glyph` (`bed.terminal`). The
  GUI editor's NPC rows add the character's lying and sitting poses (`bed.poses2d`) and its into-posture clips
  (`bed.transitions2d`), a clip written with `loop => false`. Every edit is read by the Engine's
  `NpcBedDefinition` and refused before anything changes when it would not load; edits stay in the map's own
  source with undo and redo byte for byte, in the Terminal and GUI editors alike. Validation now gives the
  Engine's bed preflight the map's resolved physical occupancy, so `npc.bed` reports a missing bed object, an object
  without the attachment, a bed cell that is not solid, a blocked exit and shared slots on legacy maps too, where it
  previously reported only that the occupancy was missing.
- Cinematic conversations, as Engine bf49893 and e26fa26 read them (`conversationFacing`): a cinematic's
  **Conversation** section binds `Conversation Speakers` (a stable key to the player, an NPC of the maps it runs on
  or a staged cast member), picks each speaker's default addressee, declares `Conversation Groups` of bound
  speakers, and picks the `Initial Conversation Group`; its text lines pick their `Conversation Speaker` and
  `Addressee` among the bound speakers. Renaming a speaker or group renames it wherever the cinematic names it, and
  removing one still named is refused, naming where. The new `cinematic_conversation_speakers` reference kind lists
  a cinematic's speakers, and a list of scoped references (a group's speakers) is refused before writing when it
  names one outside its choices. Validation adds a hint naming each group or addressee that names an unbound
  speaker to the Engine's refusal, and reports speakers the cinematic cannot reach and lines selecting an unbound
  speaker. A group's own addressees and facing overrides, and the conversation's facing overrides, are preserved
  as authored without rows of their own.
- Shared record layer: a keyed list can rewrite its record's references to an entry's key when the key changes, or
  refuse removing an entry still named (`RecordSubList::$remapKeyReferences`); a record-level field chosen among
  values its own record decides now offers them as options, as list entries' fields did; a schema's `prepareEdit`
  receives the record before the edit; and `RecordField::withLabel()` relabels a field where several of its kind
  share a pane. `FieldSpriteFields` takes the path and label of the role it authors.
- Bed posture and conversation group commands, as Engine bf49893 registers them: `set_bed_posture` picks its
  `Occupant` among the NPCs of the map an event belongs to, or of a cinematic's start map and every map it
  transfers to, and chooses its `Posture` and `Transition Seconds`; `select_conversation_group` picks its `Group`
  among the groups the cinematic's `presentation.conversation` declares. Both are edited in their own source with
  undo and redo byte for byte, and a value outside the known choices is refused before anything is written.
  Validation reports an NPC not on the map the script is on at that command, a group the cinematic does not
  declare and a group selected outside a cinematic. The session's `map_npcs` choices for a cinematic now include
  the NPCs of the maps it transfers to, and the new `cinematic_conversation_groups` reference kind lists a
  cinematic's groups.
- Story-selected Skits, as Engine 5b3558b plays them through `CinematicCommandSchema::export()['skitPlayback']`: a
  `play_skit` command in event scripts and cinematics chooses its Skit in a `Skit` picker over the project's Skits,
  listed as `Title (id)` and stored by stable id, and a Skit's `Trigger` (`prompt` or `script`, the Engine's
  default `prompt`) and optional `Open Sound` and `Close Sound` (`sounds.open`, `sounds.close`) are chosen from the
  Engine's values and the project's SFX in the Terminal and GUI editors. Edits are made in the Skit's own source,
  with undo and redo byte for byte; saving refuses a Skit the Engine would skip and a change its source cannot
  hold before anything is written. Validation reports a `play_skit` naming no Skit or a missing one, one playing a
  Skit gated to another map, a trigger or cue the Engine refuses and a cue sound the project has no file for.
- Tileset layer offsets, as Engine f76964e reads them: Database > Tilesets in the GUI editor gives a tile layer of
  the tileset's own pieces, kinds and stacks its drawing **Offset** under **Layer Offsets** (`layerOffsets`),
  typed as decimals or fractions such as `0, -1/3` and validated by the Engine's `TileLayerOffset`. A map's tile
  layer settings take any fraction within the Engine's limit, and `tileLayer.settings` takes `offset` `null` to
  inherit the tileset's. The session's tile layers add `inheritedOffset`, `overridesTileset` and `offsetLimit`,
  and `offset` is the offset the layer draws at. The Terminal editor is unchanged.

- World-object tiles, as Engine 81c5961 composes them: a world object's **Graphical Tiles** rows (`tiles2d`) choose
  one of the map's tile layers, a cell the object owns and a tile of the map's tileset (the row carries
  `tilePicker` for the palette), and the session's `worldObjects.setTiles` and `worldObjects.unsetTiles` set and
  remove them for scripts and `map:apply`. The Engine's declaration rules validate them before anything changes,
  save keeps the rest of the source as written, and undo and redo restore it byte for byte. Moving an object moves
  its tiles; an object left without tiles or art keeps an explicit absence of art. The editing canvas draws them
  before neighbour shaping, and an object presented by its tiles alone keeps its covered glyphs, as the field does.
  The Terminal editor is unchanged.
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

- Typing an NPC id that is not on the map or cinematic being edited: a `move_route` `NPC Id`, like the new
  `set_bed_posture` `Occupant`, is now refused before anything is written wherever the Editor knows the script's
  maps, where it was previously written and reported only by validation.
- A map's tile layer offset no longer has to be -0.5, 0 or 0.5: the Editor's refusal of other fractions is
  removed, as the Engine now accepts any offset from -0.5 to 0.5 field cells.
- A map no longer writes a tile layer offset equal to the one its tileset gives the layer, and an explicit
  `[0, 0]` is now written where the tileset gives the layer another offset. Setting a tile layer's offset or
  `movesWith` is now refused while the map's tileset cannot load, where it previously wrote the settings without
  knowing what the layer inherits.
- The legacy-tag reading of map rows: an unrecognised tag such as `<blue>` is read as its glyphs, as the Engine
  reads it, no longer as a style.
- The dialogue catalogue's `skits` key: Dialogue Portraits and Speaker Names refuse a catalogue that still has it
  and name `ichiloto upgrade` (format 4), which moves each skit background to its skit's `Backdrop`.
- The refusal of `<` as a stamped piece's cell glyph (`tilesets.setPieceGlyph`). A piece cell now takes `<`, `>`
  and `\` like any other glyph; formatter markup or several characters offered as one glyph are still refused.

### Fixed

- Every project failed to open, validate or render with `Unhandled match case ... CONVERSATION_GROUP` after Engine
  bf49893 added the `npc` and `conversation_group` reference kinds: the Editor mapped Engine reference kinds to
  pickers in a closed `match` that the schema catalogue runs for every project. `ScriptReferenceSource` now maps
  every current kind to its picker and where its values come from, and a kind this Editor does not know becomes
  a plain text field reported by validation, so a later Engine kind no longer stops a project from loading.
  `ProjectScriptCommands::getReferenceCategory()` is removed; `ScriptReferenceSource::forReference()` replaces it.
- `references.list` for a cinematic's row reads the cinematic's own maps. The map selected beside it no longer
  replaces them, where it offered that map's NPCs, world objects and markers for a command the cinematic runs elsewhere.
- The `skits` reference kind lists Skits by the stable id the Engine keys them by, where it listed their titles.
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
