# Wire a Quest

This guide covers authoring a quest and connecting it to the world: who gives
it, what completes it, and what it pays.

## Author The Quest

1. Press `Ctrl+D` to open the Database.
2. Move to the `Quests` category.
3. `Tab` to the entry list and press `Shift+A`.

A new quest appears with a generated id and one placeholder objective, and the
editor drops you into editing.

Fill in the fields:

| Field | Holds |
| --- | --- |
| `Id` | The stable key other things reference. Keep it kebab-case |
| `Name` | The journal title |
| `Description` | The journal body |
| `Giver` | Who hands it out, for the journal |
| `Reward Gold` | Gold on completion; `0` removes the key |
| `Reward EXP` | Experience on completion |
| `Reward Items` | Comma-separated item names |
| `Prereqs` | A condition line — see below |

`Id` is what everything else refers to, so settle it before you wire anything
up. Renaming it later means updating every reference by hand.

## Add Objectives

Objectives are flattened into the settings pane as `Objective 1 Type`,
`Objective 1 Target`, and so on.

- `Shift+O` appends an objective.
- `Shift+X` removes the last one.
- `Left` / `Right` on a `Type` row cycles the objective type.

A `Quantity` of `1` is the default and is left out of the file; set it higher
for "defeat 3 rats". A blank `Description` lets the engine phrase the objective
from its type and target.

Press `Ctrl+S` to save the category.

## Point The Way

Each objective has a `Waymarks` row. A waymark tells the player where to go:
the field shows it on screen, or as an arrow hugging the screen edge when it
is off screen, and the local and world maps mark it. When it is on another
map, the exit that leads there is marked instead.

- `Shift+O` on an objective's `Waymarks` row adds a waymark to it (on the
  objective's other rows it still adds an objective).
- Pick its `Map` first. `Event`, `NPC`, `Object` and `Landmark` then offer
  what that map holds; choose one, or none to mean the whole map. Choosing
  one clears the others, since a waymark points at one thing.
- `Shows When` takes a condition line. Use it for phases (show the next
  step only once the previous one's flag is set) and for free-order work
  (several waymarks, each hidden once its own part is done).

Waymarks store the subject's stable id, never coordinates, so moving an NPC
or an event moves its waymark too. Validation reports a waymark whose map or
subject no longer exists. The `Field Cues` Database category sets how they
look: a terminal colour (which also picks the cue art drawn in graphics), a
one-cell symbol and how far edge markers sit in from the edge, along with the
cue plate, bob and pointer images every cue mark shares.

## Lead To The Quest's Start

Objectives only lead once the quest is in the journal. To lead the player to
whoever hands it over before that, use the quest's `Start Waymarks` heading:

- `Shift+O` on the `Start Waymarks` row adds a start waymark.
- Pick its `Map`, then the `NPC` or `Event` that starts the quest, the same
  way as an objective's waymark. `Shows When` limits it further.

A start waymark shows while the quest can start: it is not yet accepted or
finished and its `Prereqs` hold. Once the quest is accepted its objectives
take over. The story's opening is the first main quest's start waymark: a
new game leads to its giver.

## Keep The Story Path Led

Validation warns where the wayfinder would have nothing authored to lead with:

- A main quest's prerequisite, or a door that turns the player back
  (`whenBlocked`), needs a story flag that nothing in the project sets, or
  that no objective of a main quest leads to. An objective leads to a flag
  when it tracks the flag, or when its waymark points at what sets it: the
  event or NPC, or, for a flag set in a battle (its script after
  `start_battle`, or the troop's event pages), the event that starts the
  battle or the map whose encounters bring it.
- Nothing in the project starts a main quest, or nothing leads to what does.
  The warning names where the quest starts. Lead there with the quest's own
  start waymark, or with an objective of the main quest active before it.

Guidance counts only when it can show before the step: a waymark waiting for
the step, or a quest that needs it, does not lead to it. A start waymark
disappears once its quest starts, so it leads only to what starts its quest
(and to a flag set by the same NPC page or event). Should a gap reach the
game anyway, the game leads to what takes the next step itself and notes the
gap in `logs/warning.log`; close it with an authored waymark all the same.

## Condition Lines

Quest prerequisites, skit conditions, and event `branch` arms share one grammar,
written on a single line. Entries are separated by `;`; each is
`[!]type:name[:extras]`; a leading `!` negates.

```text
quest:breakfast-duty:completed; !switch:door_open:false; item:S-Potion:3
```

| Type | Form | Reads as |
| --- | --- | --- |
| `quest` | `quest:<id>:<completed\|active>` | that quest is completed / active |
| `switch` | `switch:<name>` or `switch:<name>:false` | the switch is on / off |
| `event` | `event:<name>` | that story event was recorded |
| `item` | `item:<name>` or `item:<name>:<n>` | the party holds at least n |
| `key_item` | `key_item:<name>` | the party holds the key item |
| `variable` | `variable:<name>:<op>:<value>` | the comparison holds |

Unparseable entries are dropped rather than written back as garbage, so check
the row after you commit it — what you see is what was stored.

## Hand It Out

A quest nobody offers never starts. Put it on an event:

1. `Esc` back to the map, and switch the canvas to Event mode with `e`.
2. Place or select the marker for the character giving the quest (see
   [Add an NPC](add-an-npc.md)).
3. In the `Inspector`, set the event's `sets` entry to
   `quest:<your-quest-id>`.

Now talking to that character accepts the quest.

An alternative, if the quest starts from a cutscene rather than a conversation,
is an `accept_quest` command in an event script — see
[Author a Cutscene](author-a-cutscene.md).

## Gate Something On It

The point of a quest is that the world reacts. Two common shapes:

- **A door that opens once the quest is done.** Give the `Transfer Player`
  event a condition of `quest:<id>:completed`.
- **Dialogue that changes mid-quest.** Author a `branch` command in an event
  script with `quest:<id>:active` and put the new lines in its `then` arm.

## Check Your Work

1. `Ctrl+A` saves everything — the quest category and the map you edited.
2. `Ctrl+T` playtests from the cursor.
3. Talk to the giver, then check the journal.

If the quest does not appear, the usual causes are a typo between the event's
`sets` id and the quest `Id`, or a prerequisite that is not satisfied yet. The
condition grammar is unforgiving about names but silent about mistakes — it
drops what it cannot parse.

## Cross-References

`Ctrl+G` follows a reference and `Ctrl+B` returns, restoring your map, cursor,
viewport, focus, and Database selection. It works on an actor's `Class`, an
event's `Destination`, and a skill's animation.

Continue with [Author a Cutscene](author-a-cutscene.md).
