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

1. `Esc` back to the map, and switch the canvas to Event mode with `^`.
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
