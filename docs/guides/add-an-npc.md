# Add an NPC

This guide covers placing an interactive character on a map — someone the
player can walk up to and talk to.

## Two Kinds Of Character

Ichiloto maps hold two different things people call "NPCs":

- **Event characters.** A marker on the event layer with a `Dialogue` trigger
  behind it. Stationary, interactive, and fully editable here. This is what
  this guide builds.
- **Wandering NPCs.** Entries in the map's `npcs` array — they have a sprite, a
  patrol area, and a movement mode.

Current limit: the editor does not expose the `npcs` array yet. Wandering
characters are authored by hand in the map's `.data.php`. Everything below is
about event characters.

## Place The Marker

1. `Tab` to the `Canvas`.
2. Press `^` to switch to Event mode. The footer's `Mode` changes to `Event`.
3. Move the cursor to the tile the player should interact with.
4. Type a letter — `A`, `B`, `C`, and so on. That letter is the marker.

Markers are per-map. Reuse the same letter in two places and both tiles run the
same event; use a fresh letter for a new one.

Painting a marker is an ordinary canvas edit, so `Ctrl+Z` removes it and the
canvas tools work here too — `Ctrl+L` and `Ctrl+U` will copy a row of markers if
you need several.

## Give It A Definition

With the cursor still on the marker:

1. `Tab` to the `Inspector`. It now shows the event's fields.
2. Move to the event type row and press `Enter` to open the type picker.
3. Choose `Dialogue` and press `Enter`.

The five available types are:

| Type | Does |
| --- | --- |
| `Dialogue` | Shows lines of dialogue when the player interacts |
| `Transfer Player` | Moves the player to another map and spawn point |
| `Shop` | Opens a shop with inventory and optional merchant dialogue |
| `Sleep` | Offers rest, restores the party, can charge a fee |
| `Chest` | Gives loot once, or repeatedly if reusable |

The picker filters with `/`, which is quicker than scrolling once a project has
grown.

## Write The Dialogue

The Inspector now shows the dialogue rows. Each line has a speaker `name` and
its `text`.

- Move to a row and press `Enter` to edit it, then `Enter` again to commit.
- `Esc` cancels an edit and leaves the old value.

Give the speaker a name that matches how you want it shown in the dialogue box;
leave it empty for narration with no title.

Every field edit is a separate undo step, so `Ctrl+Z` walks back through your
wording one change at a time.

## Make It Conditional (Optional)

Events can set world state and can be gated on it. The `sets` list records what
interacting with this event causes — accepting a quest, flipping a switch,
recording a story event — and conditions elsewhere can then test it. See
[Wire a Quest](wire-a-quest.md) for the grammar and a worked example.

## Save And Check

1. Press `Ctrl+S`.
2. If the footer warns about a marker without a definition, you painted a letter
   you never configured. Move to it and set its type, or paint over it with a
   space.
3. Press `Ctrl+T` to playtest from a tile next to your character, and talk to
   them.

## Linking Two Maps

A `Transfer Player` event is the same workflow with one extra step. After
choosing the type, move to the `Destination` row and press `Enter`: the editor
jumps to the target map so you can pick the arrival tile *in context*, then
returns with your position and selection restored.

From the Inspector's `Destination` row, `Ctrl+G` follows the link at any time
and `Ctrl+B` comes back.

Continue with [Wire a Quest](wire-a-quest.md).
