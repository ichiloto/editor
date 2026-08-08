# Write a Skit

This guide covers adding a skit — optional party banter the player can choose
to watch.

## What A Skit Is

A short, skippable conversation between party members, offered rather than
forced. The engine announces "Skit available" when one becomes possible and the
player presses `T` to watch it. Each skit plays at most once per save.

Skits live one per file under `assets/Data/Skits`. The engine scans the whole
directory and keys each skit by its `id` field, not by its filename — so the
filename is only for your own convenience.

## Create The Skit

1. Press `Ctrl+D` to open the Database.
2. Move to the `Skits` category.
3. `Tab` to the entry list and press `Shift+A`.

A new file appears with one placeholder beat.

## Fill In The Header

| Field | Holds |
| --- | --- |
| `Id` | The stable key. Also the `skit_seen:<id>` flag the engine records |
| `Title` | What the availability notification shows |
| `Where (map id)` | Restricts the skit to one map; empty means anywhere |
| `Conditions` | A condition line gating availability |
| `Speed (chars/sec)` | Typing speed; leave empty for the project default |

`Where` takes a map id like `happyville/town-center` — the slash-separated id,
not a filename — and is matched case-insensitively.

`Conditions` uses the shared grammar from [Wire a Quest](wire-a-quest.md). A
skit tied to a quest in progress reads:

```text
quest:breakfast-duty:active
```

Leave `Conditions` empty and the skit is available as soon as the player is in
the right place.

Because the id doubles as the seen-flag, changing `Id` after a player has
watched the skit makes it available again. Settle it early.

## Write The Beats

Beats are flattened into the settings pane as `Beat 1 Speaker`, `Beat 1 Text`,
and so on, in order.

- `Shift+O` appends a beat.
- `Shift+X` removes the last one.
- `Enter` edits a row, `Enter` commits, `Esc` cancels.

Each beat is one dialogue box: the speaker becomes its title, the text its body.

Current limit: beats support `speaker` and `text` only. The compact skit overlay
and per-beat emotes described in the engine roadmap are not implemented, so
beats render through the standard dialogue box.

## Save

Press `Ctrl+S`.

The file is rewritten with your fields; anything you did not edit round-trips
untouched. As everywhere in the editor, the source header above the `return` is
preserved byte-for-byte.

## Check It

1. `Ctrl+T` to playtest.
2. Walk to the map named in `Where`, with the conditions satisfied.
3. Wait for the "Skit available" notification, then press `T`.

If it never becomes available, check in this order:

- the map id in `Where` matches the map exactly
- the conditions are actually satisfiable at that point in the story
- the skit has not already been watched in this save — the engine records
  `skit_seen:<id>` permanently

Skits are loaded once when the game scene starts, so a skit edited while a
playtest is running will not appear until you restart the playtest.

Return to [the guide index](README.md), or the full
[manual](../manual.md).
