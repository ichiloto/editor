# Add an NPC

This guide places a character on a map — someone the player can walk up to,
bump into, and talk to — and then gives them somewhere to wander, something to
say depending on the state of the world, and a script when words are not
enough. A second, shorter section covers the other kind of character a map can
hold: a stationary event character.

## Two Kinds Of Character

Ichiloto maps hold two different things people call "NPCs". They are not
interchangeable, so decide first.

- **Genuine NPCs** live in the map's `npcs` collection. Each has a stable
  map-local id, a sprite and a tile, takes part in collision, may stand still
  or wander, may be targeted by a `move_route`, and supports visibility
  conditions, dialogue and conditional dialogue, an inline script, and
  completion writes. This is what the first half of this guide builds.
- **Event characters** are a glyph painted into the tile map with an event
  marker on top of it for the interaction. They are stationary, have no
  runtime identity or movement, and are ideal for a sign, a bookshelf, or a
  guard who never moves. See [Event Characters](#event-characters) below.

## Create The NPC

1. Select the map in `Assets` and `Tab` to the `Canvas`.
2. Press `F3`. The footer's `Mode` reads `NPC`, and every NPC the map already
   has is drawn over the tiles at its authored position.
3. Move the cursor to an empty tile and press `Enter`.
4. Type the NPC's name — say `Gate Guard` — and press `Enter`.

The NPC appears at the cursor with an `@` sprite, standing still, saying
`Hello.`, and the Inspector opens on it. Nothing was painted: the tile map and
the event layer are exactly as they were.

The name you typed is where the NPC's **stable id** comes from — `Gate Guard`
becomes `gate-guard`, numbered if the map already has one. The id is what
movement routes and script diagnostics name, so it never changes afterwards:
rename, move, or restyle the NPC freely and `gate-guard` stays `gate-guard`.
The `Id` row in the Inspector is read-only for that reason.

`Ctrl+Z` removes the NPC again; `Ctrl+Y` brings it back exactly as it was.

## Place, Select, And Move

- `Enter` on an NPC selects it. `L` lists every NPC on the map by name and id
  (type to narrow, `Enter` jumps to one); `[` and `]` step through them.
- `M` picks the selected NPC up; move the cursor and press `Enter` to set it
  down, or `Esc` to leave it where it was.
- `D` duplicates the selected NPC under a fresh id, one column to the right
  when that tile is free.
- `Del` deletes it — unless something names its id (see
  [Reference-Safe Deletion](#reference-safe-deletion)).

The Inspector's `X` and `Y` rows move the NPC too. Every one of these is a
single undo step.

## Give It A Look

`Tab` to the Inspector. Under `Appearance`:

- `Sprite` is the glyph the game draws — one character, an emoji, or a styled
  glyph such as `<fg=#ffaf00>@</>`. Wide glyphs take the two columns the game
  gives them; styled ones draw as their plain glyph on the canvas.
- `Facing North` / `South` / `East` / `West` are optional glyphs shown when the
  NPC turns that way. Leave a heading blank and the base sprite covers it.
  Rest the cursor on a `Facing …` row and the canvas previews that glyph in
  the NPC's place.

## Let It Wander

Under `Movement`, `Left` / `Right` on the `Movement` row cycles `fixed` and
`wander`. A wandering NPC steps one tile at a time in a random direction, never
onto the player, another NPC, or a tile it cannot walk.

Choosing `wander` reveals `Wander X`, `Wander Y`, `Wander Width` and
`Wander Height`: the rectangle the NPC stays inside. Leave all four blank and
the game lets it roam the whole map, which is legal and sometimes wanted.
Switching back to `fixed` hides the bounds but keeps them, so switching again
finds them where you left them.

Patrol routes — a fixed path walked in order — are not something the engine
does, so the editor does not offer them. For scripted movement, put a
`move_route` command in an event script and pick this NPC's id as its target
(see [Author a Cutscene](author-a-cutscene.md)).

## Decide When It Appears

`Visibility` › `Visible When` takes a condition line, the same grammar quests
and skits use:

```text
switch:gate_open; !event:left_town
```

Press `Enter` on the row to build it part by part with the condition editor
rather than typing it. An NPC whose conditions do not hold is neither drawn
nor solid.

## Give It Something To Say

Under `Interaction` the dialogue is shown as variants: a `Dialogue variant 1`
heading, then its rows — `When`, `Then Set`, `Script Commands`, and each line
as `Line 1 Speaker` and `Line 1 Text`. Long lines wrap in the pane, so you
read them where they are.

- Move to a `Line 1 Text` row and press `Enter` to change what is said;
  `Shift+O` on a line adds a line after it; `Shift+X` removes one.
- Each line's `Speaker` is picked, not typed: `(the NPC's name)` lets the game
  title the box with the NPC's current name (so a rename carries through),
  `(No speaker)` shows an untitled box for narration, or choose an actor.

One variant with lines is saved as plain dialogue pages. To make what is said
depend on the world, `Shift+O` on a variant's `When` row adds
`Dialogue variant 2`; give its `When` a condition line and its lines their
text — the heading then reads `Dialogue variant 2 · when …`. The game speaks
the first variant whose conditions hold, top to bottom, so put the specific
cases above the general one. A variant may also carry a `Then Set` (writes
applied when that variant is spoken) and `Script Commands` (a frame of
commands run after its lines).

## Run A Script Instead

`Interaction` › `Script` opens a command frame with the same commands as a
Common Event — text, choices, branches, gold, items, movement routes, and the
rest. Fill it in and the game runs the script when the player talks to the NPC.

Be aware: a non-empty script **replaces** the dialogue. The pane shows a
`! Script replaces dialogue` row while both exist, and deletes neither; remove
the one you do not want, or move the lines into `text` commands.

## Record That The Player Talked

`Completion Writes` › `After Talking` takes world-write rows — a switch to
flip, a variable to set or add to, a story event to record, a quest to offer or
grant — applied by the game after each finished conversation. Press `Enter` on
the row to build them. Conditions elsewhere can then test what was written;
[Wire a Quest](wire-a-quest.md) walks through the whole loop.

## Save, Validate, Playtest

1. `Ctrl+S`. An NPC edit changes only the map's `.data.php`; the tile and
   event files are untouched. Undo back to the save and the map is clean.
2. If the footer warns, `Ctrl+E` shows why. Validation covers every NPC field:
   coordinates off the map, an NPC standing on an event tile it makes
   unreachable, a wander area that leaves the map or that the NPC starts
   outside, a script shadowing dialogue, malformed shapes the game would drop.
3. `Ctrl+T` playtests from the cursor. Walk into the NPC — it blocks — face
   it, and press the action key.

## Reference-Safe Deletion

`Del` on an NPC that a `move_route` names — in a map event's script, in
another NPC's script or dialogue variant, or in a Common Event this map
triggers — is refused, and the status lists exactly where. Fix or remove those
references first, then delete. Nothing is ever left pointing at an id that no
longer exists.

Shrinking the map is guarded the same way: the Inspector refuses a `Size` that
would leave an NPC, or a wander area, outside the map, and names each one.

## Event Characters

An event character is a glyph painted into the map with a `Dialogue` event
marker on the same tile. It cannot move, has no id, cannot be targeted by a
route, and is not an NPC to the game — but it is quick, and right for a sign
or a fixture.

1. On the `Canvas` in Map mode, type the glyph on the tile — `i` for a
   noticeboard, say.
2. Press `^` for Event mode and type a letter on the same tile. That letter is
   the marker; reuse it elsewhere and both tiles run the same event.
3. `Tab` to the Inspector, open the type picker with `Enter`, and choose
   `Dialogue`.
4. Edit the dialogue rows: each line has a speaker `name` and its `text`.
   Leave the name empty for narration with no title.

The five event types are `Dialogue`, `Transfer Player`, `Shop`, `Sleep`, and
`Chest`. A `Transfer Player` event links two maps: after choosing the type,
`Enter` on `Destination` jumps to the target map to pick the arrival tile in
context, and returns you afterwards; `Ctrl+G` follows the link any time and
`Ctrl+B` comes back.

Painting a glyph or a marker is an ordinary canvas edit, so `Ctrl+Z` removes it
and the canvas tools apply.

Continue with [Wire a Quest](wire-a-quest.md).
