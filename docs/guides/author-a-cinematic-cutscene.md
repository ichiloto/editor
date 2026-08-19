# Author a Cinematic Cutscene

A cinematic is a staged story sequence the engine plays on the field: a cast
of actors and vehicles, a camera that can leave the player, several things
happening at once, music, narration, a title, a transfer — and a way to skip
it that lands on the same ending as watching it. This guide builds one from
nothing, in the editor, and ends with it playing in the engine.

If you want a reusable command list a map or another cutscene can call, you
want a Common Event instead: see [Author a Cutscene](author-a-cutscene.md).
For a battle summon, see [Author a Summon Cutscene](author-a-summon-cutscene.md).

## What A Cinematic Is

One folder, two files, named after the folder:

```text
assets/Cutscenes/Cinematics/dawn-crossing/dawn-crossing.data.php
assets/Cutscenes/Cinematics/dawn-crossing/dawn-crossing.script.php
```

The data file holds the definition (name, start map, cast, skip policy,
checkpoints, finalizer); the script file holds the command tree. The folder
name is the stable id the game launches it by. The editor reads both,
preserves everything in them you did not touch, and saves the pair as one
transaction.

## Create It

1. Press `F4`. The Cutscenes screen opens on **Cinematic**.
2. Focus the list (`Tab`) and press `Shift+A`. A new cinematic appears,
   unsaved, with a placeholder id.
3. Put the cursor on the `Id` row and press `Enter`: while the asset is
   unsaved, its id is yours to type. Give it a lowercase, stable name such
   as `dawn-crossing`. After the first save the id is the folder and turns
   read-only.
4. Fill `Name` and `Description`. Pick `Start Map` from the project's maps —
   this is where the cast is staged and where the preview begins.
5. Set `Initial Presentation` to `hidden` if the scene should begin black
   and fade in.

## Declare The Cast

Under **Cast**, `Shift+O` adds a member. Each is one of:

- `player` — the player's own field representation;
- `party_actor` — a party member, picked from actors;
- `npc` — a map NPC by its stable map-local id on the start map;
- `staged_actor` — a temporary actor the cinematic owns: id, sprite (or an
  asset reference), x and y, facing, visible, collision, directional sprites.

Three kites crossing a plain are three staged actors. Give each an id you
will address in commands (`kite-red`, `kite-blue`, `kite-gold`), a one-line
sprite, and a starting position on the start map.

## Build The Command Tree

Open `Commands` from its row (or press `Enter` on the `Commands` row of the
Command Tree pane). The record pane now shows the script as a frame: `Shift+O`
adds a command, and each command shows the rows its type uses. The Command
Tree pane mirrors the whole tree and lets you reorder (`[` / `]`), nest and
un-nest (`>` / `<`), insert after (`Shift+O`), duplicate (`Shift+D`) and
remove (`Delete`) — all undoable.

An opening structure, top to bottom:

1. `transition` — style `fade`, direction `in`, seconds `0.3`.
2. `camera` — operation `detach`. The camera stops following the player.
3. `cinematic_music` — a track from the project's BGM, loop on, completion
   `continue`.
4. `parallel` — a block of lanes that run at once. With the cursor on the
   parallel command, `Shift+O` adds a lane; name them `formation`, `camera`,
   `words`. Open a lane's `Commands` row to author inside it:
   - `formation`: its own `parallel` with one lane per kite, each a
     `move_route` with subject `staged_actor`, the kite as actor, six steps
     `right` at 0.1 seconds per step;
   - `camera`: `camera` operation `pan` to a position target over 0.6 seconds;
   - `words`: `narration` with a title and a multi-line text (`Enter` on the
     Text row opens the multiline editor; `Ctrl+S` applies it).
5. `field_animation` — an animation from the project, targeted at a staged
   actor.
6. `checkpoint` — `formation-crossed`. Declare the name in the cinematic's
   `Checkpoints` field too; validation warns about one it does not know.
7. `title_card` — the title, for a few seconds.
8. `transfer` — the dawn map, and where the player stands on it.
9. `checkpoint` — `dawn-arrival`.

`Shift+O` on a command that already has its lanes, steps or points adds the
*next command* after it, so a block is never a dead end; on one that has none
yet, it adds the first. Anything with a closed catalogue — maps, tracks,
animations, common events, cast members, checkpoints, NPC ids — is picked,
never typed.

## Make It Skippable

Skip is a second ending, not a cancel. Set `Skip Policy` to `authored` and open
`Finalizer`: the commands the engine runs once, after cancelling every lane,
when the player skips. They must put the world exactly where watching would
have: move the player, transfer to the dawn map, `camera` `attach`,
`remove_actor` for each kite, `clear_presentation`, `cinematic_music` with
its completion, `set_switch` and `record_event` for the writes the story
depends on. The finalizer vocabulary is deliberately small and strict; the
record pane offers exactly it.

The engine accepts `authored` only when every reachable path is safe to
abandon. A `start_battle`, `give_item`, `give_gold`, `accept_quest`,
`recover_party`, `knowledge` or `common_event` on the way makes the cinematic
unskippable, and the Preview pane's standing line says so with the engine's
reason.

## Preview It In The Engine

Focus the Preview pane (`Tab`) and press `Space`. The engine plays the
cinematic as it stands — unsaved — in an isolated scene built from your start
map, with a camera that draws into the pane. You see the map, the NPCs, the
player, the kites moving, the narration box, the title card.

- `Space` pauses and resumes; `.` steps a tenth of a second.
- The column beside the frame lists the lanes the interpreter is running and
  what each is on, the checkpoints recorded, and whether a skip would be
  accepted. The Command Tree marks the running commands with `▶`.
- `K` skips at a legal point, through the finalizer. `R` restarts. `X` stops.
- If a command fails, the run stops with the engine's message; `J` jumps the
  tree and the record pane to the command that failed, marked `✗`.
- `C` runs the cinematic twice, watched to the end and skipped at once, and
  lists every difference between the two final states — the map, the player,
  the camera, the cast, the world state, the music left playing, and who owns
  field input. An empty list is the goal; a row is a finalizer you have not
  finished.
- `V` is the duration overview: every lane and block with the time it is
  authored to take, `+input` where it waits for the player.

The preview writes nothing: not your saves, not your files.

## Save, Validate, Play

`Ctrl+S` writes the pair. Reopen the editor and the same tree comes back;
`ichiloto validate` reports what the engine would refuse and every reference
that names nothing.

To launch it from a map, add a **Cinematic** event (a `CinematicEventTrigger`)
and pick the cinematic: mode `auto` plays it on arrival, `action` on the field
action. `Ctrl+T` on the Cutscenes screen plays the saved cinematic in the real
game through a throwaway playtest root whose start map carries an automatic
trigger on the spawn tile — the real asset through the real trigger, with
your map files untouched.

## Check It

- Skip it before the movement, during it, during the narration and after the
  transfer (`K` at each point in the preview, then let it finish). Every run
  should end on the dawn map, at the same tile, with the camera attached, the
  kites gone, the switch set and the completion event recorded once.
- The watched run records the checkpoints; a skipped run need not — the
  comparison lists that as the one expected difference.
- After completion the field is stable again and saving is allowed.
