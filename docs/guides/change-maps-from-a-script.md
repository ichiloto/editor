# Change Maps From A Script

This guide is for agents and scripts that change a project's maps without a
window. Every map change goes through the Editor, the same source-preserving
session the GUI editor uses, so it is written only when the project it leaves
is valid. Never edit map files (`*.data.php`, `*.event.php`, `layers/`,
`graphics/`) by hand: that path skips every check below, and it is how raw
glyphs, events left behind by moved furniture and chests with no reward
reached the game.

## The Two Commands

```bash
ichiloto map:apply changes.json --directory path/to/game
```

Applies a batch of changes as one change set. It writes them only when every
map they change has no errors and nothing new is broken anywhere else.
Otherwise it writes nothing, undoes what it did, and prints each problem with
its map, cells and fix. It exits `0` once written, `1` when refused.

| Option | Does |
| --- | --- |
| `--check` | Reports what writing would find, and writes nothing. |
| `--relocate-saves` | Lets saves that may stand on cells the change makes solid move to open floor, as an author confirms in the editors. Without it such a change is refused. |
| `--json` | Prints the outcome as JSON: `status`, `maps`, `saved`, `issues`, `blocking`, `relocated`, `reason`. |

```bash
ichiloto validate --directory path/to/game --map happyville/bsa-annex --map happyville/home
```

Reports only what is wrong on the named maps and exits `1` on any of their
errors. Use it to gate a commit on the maps it touched. Without `--map` it
checks the whole project.

## What Counts As An Error

An error is a defect the player would meet. The editors still save an
author's map with errors as a draft and show them; validation and
`map:apply` fail until they are fixed.

The map rules are the Engine's own (`MapCompletenessValidator`), the checks
the game itself relies on; the Editor keeps no copy. Each finding carries the
rule as its `code`:

- `graphics.glyph`: a gameplay glyph with no graphical presentation, shown
  raw in the field. `graphics.missing`: a map with no kind in a project with
  tilesets.
- `npc.art`, `object.art`, `object.reference`: an NPC or world object without
  usable field art, or a world object resource that does not resolve.
- `event.unmapped`, `event.area`, `event.subject`, `event.definition`,
  `event.grid`: a marker with no definition, an event with no cells and no
  subject, a subject that is missing or competes with painted cells, a
  malformed event, an event layer that does not match the map.
- `chest.reward`: a chest whose reward the Engine cannot give, or that has
  none chosen.
- `event.speaker`: an event's speaker who is not an NPC on the map, is never
  shown while the event can run, wanders freely or stands more than one cell
  from the event's area.
- `object.doorway`: a world object whose resource paints a doorway with no
  entrance (transfer event) under it, or an entrance inside an object's
  covered cells behind its ground row, where the art hides the player.

The Editor's project checks add the rest `ichiloto validate` reports, also as
errors: floor the player can reach with no tile, an event the player can
never reach, an arrival on a cell they cannot stand on, a door or edge to a
map that does not exist, a spawn point outside its map, unknown references,
invalid scripts, and a door, edge or scripted transport whose destination
map's role is not one its own map's role leads to (`map.role-destination`;
see Map Roles in the manual), or a map naming a role the project does not have
(`map.role`).

Warnings never refuse a change: tiles a piece draws whose glyph is gone, the
missing-art placeholder, see-through ground, a map nothing reaches yet.

A change to a map that already has an error is refused until that error is
fixed too. Put the fix in the same batch.

## The Batch

A batch is a JSON list of editor session requests, or `{"changes": [...]}`:

```json
{"changes": [
  {"method": "event.move", "params": {"map": "happyville/bsa-annex", "marker": "F", "dx": 8, "dy": 0}}
]}
```

Each change is a request of the session protocol: the GUI editor's own
requests, listed in `src/Session/SessionHost.php`. Two things are filled in
for you:

- **Revisions.** A change naming a `map` without a `revision` edits that map
  as it stands at that step. Give a `revision` only when the batch must refuse
  a map that changed.
- **Questions.** Some requests ask before they act (inserting rows reviews
  the coordinates they shift). Give the change an `"answer"` beside `params`;
  the batch confirms what the question showed. A question without an answer
  refuses the batch.
- **Pieces.** When several pieces draw a glyph you paint, the batch is
  refused with their role keys. Answer as the editors do, in the change's
  params: `"choices": {"x": "crate:0:0"}`, or `null` for no tiles.

Edits are made in order and held in memory, and every later change reads
them: a field resource, tileset piece, place category, skill, effect timeline,
database record or world object made earlier in the batch is usable by the
changes after it, before anything is written. Painting, piece lists, pickers,
the map world the GUI draws and the validation `changes.commit` runs all read
these current records. Battle previews and the cinematic preview field still
read the saved items, enemies, summons and maps until the Engine can take them
from the Editor; commit or save first when one of those must show an edit.
Inserting or removing rows, and creating or duplicating a map, write their
files when they run; a refused batch undoes them. Inserting or removing rows
needs no pending edits, so put it first, or in its own batch.

A step that writes a set of files at once (inserting or removing rows,
`skills.retire`, `mastery.adopt`, an actor's identity freeze) reads the
project again from them. A map whose files the step did not change keeps its
revision and is not one of the maps the batch changed, so errors already on
maps the batch never touches do not refuse it.

The batch alone writes. When it is valid, `changes.commit` saves everything
it changed, databases (field resources, common events and the rest),
cutscenes, maps and the save relocations its maps need, as one transaction.
Every file is worked out and staged first, and each document proves its
staged file reads back as what it holds. Only then is any file installed,
and a file that cannot be installed puts back the ones already installed.
So the batch is written whole or not at all. A document that refuses here
refuses the batch as an error does: nothing is written, the batch is undone,
and `reason` starts with `Nothing was written:` and names the document. A
saved result's `written` names each document written and the files it wrote
or removed. `database.save`, `map.save` and `project.saveAll` are refused
inside a batch; leave them out. Moving or deleting a map is refused too, since it clears the
undo history a refused batch relies on. Deleting a database record inside a
batch removes its file only when the batch is written.

## Finding Ids And Keys

Read before you write. Over the protocol (see Driving The Session Directly)
or with the GUI editor open, these reads give every id a change needs:

| Read | Gives |
| --- | --- |
| `maps.list` | Map ids and revisions. |
| `map.read {map}` | Layers (`id`, `name`), `baseLayer`, events, NPCs and world objects, unsaved changes included. |
| `inspector.read {map, event?}` | The map's rows, and an event's rows when `event` names its marker. Each row's `key` goes back unchanged to `inspector.apply`, `inspector.add` or `inspector.remove`. |
| `worldObjects.read {map, id}` | A world object's rows, for `worldObjects.apply`. |
| `npc.read {map, index, frame?}` | An NPC's rows, for `npc.apply`. |
| `pieces.list {map}` | The pieces the map's tileset can stamp. |
| `kinds.list {map}` | The terrain kinds of the map's tileset, and whether the map is painted in them (`painted`, with an `issue` saying how to convert it when not). |
| `kinds.at {map, x, y}` | The kind a cell shows in both its glyph and its tiles, or null. |
| `kinds.previewConversion {map, mapping?}` | What converting the map would do: each mapped glyph's cells, the glyphs left, those that already name a kind (`reread`) and the collision changes. Without a mapping it lists the map's glyphs. |
| `event.types` | Event type labels for `event.create`. |
| `references.list {map, category}` | The values a reference row accepts, such as `inventory` ids. |
| `map.measure {map, points}` | The shortest walk through `[x, y]` points in order (a start, any waypoints, an end), and its steps, seconds and expected encounters. See Measuring A Walk. |

Layers are named by `id` in edits. An event row key looks like
`{"target": "event", "marker": "C", "path": ["data", "loot"]}`.

## Condition And Write Lines

A conditions or writes row takes one line. Entries are separated by `;`,
never `&` or a comma, and each entry is `type:name` plus the parts its type
takes:

| Conditions | Writes |
| --- | --- |
| `[!]quest:id[:completed\|active]` | `quest:id[:confirm\|grant]` |
| `[!]switch:name[:true\|false]` | `switch:name[:true\|false]` |
| `[!]event:name` | `event:name` |
| `[!]map_event:map:marker` | `variable:name[:set\|add][:value]` |
| `[!]variable:name[:op[:value]]`, op one of `==` `!=` `>` `>=` `<` `<=` | |
| `[!]item:id[:quantity]`, `[!]key_item:id[:quantity]` | |

A leading `!` negates a condition. A name or value that holds `:`, `;` or
`\` escapes it with a backslash: the event flag `deckers:won` is written
`event:deckers\:won` (in JSON, `"event:deckers\\:won"`). A line is read only
as written: a part its type does not take, an unknown type or a value the
Engine would refuse makes the row refuse the whole line, and nothing changes.
`event:a & !event:b` is refused, because the event condition takes nothing
after its name; write `event:a; !event:b`.

## Named Areas

A map's `areas` name territory it owns, with no art, passage or discovery of
their own. An entry is `['id' => ..., 'area' => ...]`, the area either exact
`cells` (`['cells' => [[x, y], ...]]`) or a rectangle (`x`, `y`, `width`,
`height`); the Engine reads every edit against the map's physical grid and
refuses a duplicate or malformed id, an empty area, a repeated cell or a cell
off the map, naming the area. `areas.create` and `areas.setArea` take the
area as written (`area`), or selected `cells`, which are written as a
rectangle when they fill one. An event bound with
`{kind: area, id}` covers the area's cells and follows its reshaping; a
landmark anchors to it with `area:<id>`. `areas.rename` rewrites the events
and landmarks naming the area in the same undo step; `areas.delete` is
refused while one names it, listing them. All of these work inside a batch,
so an area made earlier in the batch can be bound and anchored to before
anything is written. Row and column inserts move areas with the lines; a
removal through one of an area's exact cells is refused until it is reshaped.

## Measuring A Walk

`map.measure {map, points}` measures how far a player walks, on request, and
changes nothing. `points` lists two to 64 `[x, y]` cells: the start, any
waypoints, then the end. The answer holds the whole walk as `path` (every cell,
start included), `steps` with the steps `across` and `down`, `walkSeconds`
(each step lasting the field's walk, `stepSeconds`), `screens` (steps across
over the field view's columns plus steps down over its rows, for the GPUI field
at the game's default screen and the project's field zoom, named in `view`),
and one entry per leg in `legs`. Where the map has encounters, `encounters`
gives the map's `rate`, the steps the walk counts toward an encounter
(`countedSteps`, a danger tile counting twice on a map that counts every
tile), the encounters `expected` on the way (counted steps over the rate,
with no repel or lure assumed) and the `troops` that can be met along it;
without encounters it is null. `dashSeconds` is null, with `dashUnavailable`
saying why, while the game authors no dash speed.

The walk is the game's: the Engine's movement over the map as the session
holds it, unsaved edits included, so walls, fixed NPCs and cells that take the
player off the map stand where the game puts them. Of the equally short walks
it keeps its heading wherever it can. When a point cannot be stood on, a
waypoint would leave the map, or no walk joins two points, `reachable` is
false, `problem` says which, and `path` and `legs` are empty. A map the game
could not load, such as one with an event marker that has no definition, is
refused with the reason.

## Save Migrations

`saveCompatibility.appendStep {class}` registers the project's own save
migration class as the next step of `assets/Data/save-compatibility.php`:
`contentVersion` rises by exactly one, and the step is
`['from' => N, 'to' => N + 1, 'class' => ...]`. The class must load through
the project's own `vendor/autoload.php` and implement the Engine's
`ContentMigrationInterface`; the staged manifest is read back as the game
reads it, so a class that does not load or is not a migration is refused
with nothing written, as is a manifest the source-preserving writer cannot
rewrite. It is one undo step, and inside a batch it is undone with the batch.

The Engine reads a step as a migration class or as declared position edits,
never both. So a batch that registers a class and also changes a map in a
way that moves saves (the relocations `changes.commit` reports) is refused
whole, saying so; register the migration and change the map in separate
batches.

## Which Request Makes Which Change

| Change | Request |
| --- | --- |
| Paint glyphs | `map.paint {map, layer, cells, symbol, color?}`; a whole block of rows at once with `map.paintRegion {map, layer, x, y, rows}` |
| Draw the tiles a layer's glyphs need | `layer.drawTiles {map, layer}` |
| Paint or stamp tiles | `tiles.paint`, `tiles.stamp` |
| Stamp a tileset piece | `pieces.place {map, piece, from, to, color?}` |
| Paint a terrain kind | `kinds.paint {map, kind, from, to}` on a map painted in kinds; its glyph, its tiles and, under declared occupancy, its collision |
| Convert a map to terrain kinds | `kinds.convert {map, mapping}`, such as `{";": "grassland", "~": "water"}`; or `ichiloto map:convert-kinds` |
| Layers | `layer.create`, `layer.rename`, `layer.remove`, `layer.reorder`, `layer.decoration`; tile layers `tileLayer.*` |
| Collision | `map.paintOccupancy`, `occupancy.stampPiece` |
| Resize | `map.insertLines`, `map.removeLines` (with an `answer`), or the map's size rows |
| Events | `event.create {map, cells, type, marker?}`, `event.move {map, marker, dx, dy}`, `event.bounds`, `event.destination`, `event.delete`; fields with `inspector.apply` |
| Name who speaks an event | `inspector.add {map, key: {target: event-speakers, marker}}` names the nearest NPC not yet listed; `inspector.apply` with `{target: event-speakers, marker, field: speaker, index}` and an NPC id chooses another; `inspector.remove` with that key removes it |
| A resource's doorway | the field resource's Doorway list: `database.add` on its heading (`child: true`), then `database.apply` its `doorway<N>X` / `doorway<N>Y` rows |
| Bind an event to the object or area it describes | `event.subject {map, marker, subject}`, `subject` as `{kind: world_object, id}` or `{kind: area, id}` (a bare id names a world object); `subject: null` with `cells` unbinds it |
| Named areas | `areas.read {map}`; `areas.create {map, id?, area or cells}` (no `id` takes the first free `area-N`); `areas.setArea {map, id, area or cells}`; `areas.rename {map, id, to}`; `areas.delete {map, id}` |
| A landmark anchored to an area | the landmark's anchor row (`inspector.apply` with `{target: map-landmarks, field: anchor, index}`), value `area:<id>` |
| An event's conditions, writes, blocked message, cue conditions | `inspector.apply` with `{target: event-record, marker, field, frame: []}`, `field` one of `conditions`, `sets`, `whenBlocked`, `cue.conditions`; the value is a condition or write line (`conditions.grammar`), empty removes it |
| A Story Script event's inline commands | `inspector.read {map, event, frame: ["script"]}`, then `inspector.apply`/`add`/`remove` with the frame's row keys; the first command goes on the `frameEmpty` row |
| Dialogue pages with conditions and writes, talk-to id | the event's `variantList`, `variantNConditions`, `variantNSets`, `variantNLineM...` rows; the Talk-To Id row sets `npcId` |
| Where a transfer arrives facing | the Spawn Facing row, or `event.destination {..., heading}` |
| A route waypoint or staged actor placed relative to a subject | the waypoint's or actor's Relative To row (player, npc, world_object, staged_actor, party_actor, marker), then its id row and Offset X / Offset Y rows: `database.apply` in a cinematic (`database.record {category: "cutscenes/cinematic", index, frame: ["commands"]}`), `npc.apply` in an NPC script, `inspector.apply` in an event script. A relative point follows its subject and is not shifted by row or column inserts |
| Which way a waypoint or staged actor faces once there | the same entry's Facing row (north, east, south, west), or its Face Toward row (the same subject kinds) and then its Face Toward id row; choosing one clears the other |
| A map's region-map cell | `inspector.apply` with `{target: map-station, field: station}`, value `x, y`, empty removes it |
| World objects | `worldObjects.create`, `worldObjects.move {map, id, dx, dy}`, `worldObjects.place`, `worldObjects.apply`, `worldObjects.add`, `worldObjects.remove`, `worldObjects.delete` |
| NPCs | `npc.create`, `npc.move`, `npc.apply`, `npc.add`, `npc.reorder`, `npc.remove`, `npc.duplicate`, `npc.delete` |
| Move a character's own skills onto their Mastery Map | `mastery.adopt {actor}` (asks; answer `write` with its `confirm`): each skill the actor file says they know gets terms known from the start (cost 0, level 1), each learnable terms costing its EXP requirement, for the tree node that teaches it (a skill the tree does not place yet joins the first region's trunk), skills their role teaches stay with the role, and the actor's learned lists and learnables are emptied; then edit the terms in the Mastery Maps category |
| Place or link Mastery Map nodes | `mastery.page {index}` reads the regions and each node the map gives terms for, with the tree's `name`, `skill`, `region`, `branch` and `kind` and its place (`x`, `y` as fractions of the map; `placed` false where the Engine places it in its region); `mastery.placeNode {index, node, x, y}` places a node; `mastery.toggleRequirement {index, node, requires}` makes `node` build on `requires`, or stops it, refusing a loop. Each is one undo step |
| Retire a skill | `skills.retire {index, replacement?}` (asks; answer `write` with its `confirm`): every class grant, actor learned skill and learnable, attack and counter skill, enemy action and skill or state counter that names it moves onto the replacement (a list already holding the replacement drops the old entry) and saves read the old skill as the replacement; without a replacement each is removed and saves drop the skill. A replacement must be a spell for a spell and an ability for an ability, and a summon's action follows its summon. Then `database.delete` the skill, which is refused while anything still names it |
| Move a story event onto the NPC who delivers it | `npc.adoptEvent {map, marker, npc?}`: the event becomes the NPC's first page (its conditions, writes, cue, action and run-once), its cells are cleared, a run-once event's saved completion is aliased to the page, and the result lists what was dropped and the quest waymarks to repoint at the NPC (`database.apply` on the waymark's NPC row) |
| Map fields (name, kind, music, encounters, atlas, landmarks) | `inspector.apply` with the map row's key |
| A map's role | `inspector.apply` with `{target: map-role, field: role}`, value a role id from the Map Roles page (`database.create {category: map_roles}`), empty removes it |
| New map | `map.create {name, kind, width, height, id?}`; `id` is its folder, such as `town/inn/cellar` |
| Copy or move a map | `map.duplicate {map, to?}`; `map.move {map, to}` asks first, then `{map, to, destination, confirm: true}` |
| Landmark id | `inspector.apply` with `{target: map-landmarks, index, field: id}`; other maps' atlas parents follow the rename |
| NPC id | `npc.create {..., id?}`, `npc.assignId {..., id?}`, or the NPC's Id row with `npc.apply` |

Two NPCs may anchor on one tile only as phases the game never shows
together: one has a visibility condition (its Visible When row) that the
other negates, such as `!event:evidence` and `event:evidence`, or
`switch:door` and `switch:door:false`. Variable `==` and `!=` against the
same value count too. Nothing else does, however disjoint it looks: quest
statuses, variable ranges and item counts can hold together. So give the
phases their conditions first, then move one onto the other's tile. A move,
create or conditions edit that would leave two NPCs visible on one tile at
once is refused with nothing changed, and validation reports such a tile.
In the terminal editor, Enter on that tile steps through its phases.

`worldObjects.move` moves an object with what shows it in the terminal: its
anchor, the cells it covers and their glyphs, with the tiles those glyphs
draw. Moving only the anchor (`worldObjects.place`) leaves its glyphs behind.

## Worked Example: Bind An Event To Its Object, Then Move Them

An inspection event that describes lockers is bound to them once. From then
on its area is the lockers' covered cells, so the event can never be left
behind when they move:

```json
{"changes": [
  {"method": "event.subject", "params": {"map": "town/annex", "marker": "F", "subject": "annex-lockers"}},
  {"method": "inspector.apply", "params": {"map": "town/annex",
    "key": {"target": "event", "marker": "F", "path": ["engagement"]}, "value": "facing"}},
  {"method": "worldObjects.move", "params": {"map": "town/annex", "id": "annex-lockers", "dx": 2, "dy": 0}}
]}
```

Binding clears the event's painted cells. A bound event cannot be moved or
reshaped by itself, and its object cannot be deleted while the event names
it. Run the batch with `--check` first: a move onto another glyph or another
object's cells is refused and names the cell, and moving furniture onto open
floor usually needs `--relocate-saves`.

## Worked Example: Stamp A Piece

Read `pieces.list` for the piece's id, then stamp it with its top-left at
`from`. With `to` on the same cell it stamps one copy; a larger area stamps as
many whole copies as fit, and a connected piece (a wall) is drawn along the
area's outline:

```json
{"changes": [
  {"method": "pieces.place", "params": {"map": "town/inn", "piece": "bookshelf", "from": [4, 2], "to": [4, 2]}}
]}
```

The piece writes its glyphs and its tiles together, so nothing shows as a raw
glyph. Stamping on cells that make the floor solid may need
`--relocate-saves`.

## Worked Example: Place A Chest With A Reward

Create the event, then set what it gives. The reward is an inventory id from
`references.list {map, category: "inventory"}`:

```json
{"changes": [
  {"method": "event.create", "params": {"map": "town/cellar", "cells": [[10, 5]], "type": "Chest", "marker": "C"}},
  {"method": "inspector.apply", "params": {"map": "town/cellar",
    "key": {"target": "event", "marker": "C", "path": ["data", "lootType"]}, "value": "item"}},
  {"method": "inspector.apply", "params": {"map": "town/cellar",
    "key": {"target": "event", "marker": "C", "path": ["data", "loot"]}, "value": "item.tonic"}}
]}
```

Without the last change the batch is refused: the chest has no item chosen.

## Driving The Session Directly

For a long piece of work, an agent can hold one session open instead of
writing batches: start `ichiloto edit:host --directory path/to/game`, send one
JSON request per line on its standard input and read one response per line
from its standard output. The first request is
`{"id": 1, "method": "hello", "params": {"protocol": 1, "project": "<project root>"}}`.
Then:

1. `changes.begin` opens a change set. It needs no pending edits.
2. Make the edits, passing each map's current `revision` (every edit returns
   the new one), and read back what you need.
3. `project.validate {maps?}` reports the issues the project would have now,
   unsaved edits included.
4. `changes.commit {relocateSaves?, check?}` writes the change set when it is
   valid, or refuses it, writes nothing and undoes it. `changes.cancel`
   undoes it. Closing the host with a change set open cancels it.

A response is `{"id", "result"}` or `{"id", "error": {"kind", "message"}}`.
`map.save` and `project.saveAll` are the author's draft saves: they write a
map with errors. Agents commit change sets instead.

## Before You Run

- Do not apply a batch to a project an author has open in an editor with
  unsaved changes to the same maps. The editor's own save refuses a file that
  changed since it read it, so nothing is overwritten silently, but the
  author's edits then have to be redone.
- `map:apply` validates the whole project twice, before and after. Expect it
  to take as long as `ichiloto validate` does, twice.
