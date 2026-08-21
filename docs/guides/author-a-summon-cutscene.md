# Author a Summon Cutscene

A summon cutscene is a frame-driven presentation the battle plays when a
summon is called: tracks of keyframes — ASCII art, captions, flashes, shakes
— over a fixed number of frames, with cues that tell the battle when the
effect lands. This guide authors one, previews it through the engine's own
playback session, and assigns it to an actor.

For a story sequence on the field, see
[Author a Cinematic Cutscene](author-a-cinematic-cutscene.md).

## What A Summon Is

One folder, two files:

```text
assets/Cutscenes/Summons/ember-moth/ember-moth.data.php
assets/Cutscenes/Summons/ember-moth/ember-moth.timeline.php
```

The data file is the definition (name, lore, linked action, availability,
wielder policy, playback and presentation); the timeline file is the frames
(FPS, length, tracks, keyframes, cues). ASCII art in a timeline is usually a
nowdoc held in a local variable and referenced from a keyframe; the editor
keeps that structure when it saves, and writes any multi-line content you
type as a nowdoc of its own.

## Create And Define It

1. `F4`, then `Tab` to the Types pane and choose **Summon** (or `Ctrl+P` →
   `Cutscenes: Summon`).
2. `Shift+A` on the list creates one; `Enter` on `Id` lets you name it while
   it is unsaved.
3. **Identity**: name, description, the move name the battle announces,
   `Linked Action` picked from the project's skills.
4. **Lore** is free text and lists; **Availability** uses the shared condition
   editor — leave it omitted for a summon that is simply available, and it is
   written as nothing.
5. **Wielders**: `all`, or `roles` picked from classes, or `characters`
   picked from actors; tenancy `shared` or `exclusive`.
6. **Playback**: default speed, transitions in and out, and `Effect Timing` —
   `end`, or `cue` with the cue id, or `frame`.
7. **Timeline**: `FPS` and `Length (frames)`.

## Author The Timeline

Open `Tracks` from its row. `Shift+O` adds a track; give it an id and a type
(`glyph`, `text`, `flash`, `shake`). With the cursor on the track's rows,
`Shift+O` adds a keyframe under it; once it has keyframes, `Shift+O` on the
track's own rows adds the next track, and `Shift+O` on a keyframe row adds a
keyframe after it.

Each keyframe has a frame, a duration, a position, content, an asset id, a
color, visibility, z-index, blend mode, easing and a free-form payload. Put
the cursor on `Content` and press `Enter`: the multiline editor opens, and
keeps every leading space, backslash, blank line and wide glyph exactly as you
type or paste it. `Ctrl+S` applies, `Esc` cancels.

Open `Cues` for the cues: an id, the frame, a type (`applyEffect` is the one
the battle's effect timing reads), and a payload. Bind the definition's
`Effect Timing` to `cue` and the cue's id.

The Timeline pane lists tracks, keyframes and cues as rows: `[` / `]` reorder
within a list, `Shift+D` duplicates (a copied track or cue gets a free id),
`Shift+O` inserts after, `Delete` removes, and `+` / `-` on a keyframe or cue
row nudge its frame by one. All of it is undoable.

## Preview It

Focus the Preview pane and press `Space`. The editor compiles the summon as
it stands, unsaved, through the engine's compiler, and plays it through the
engine's playback session: the playhead, the frame clock, which segments are
active and when cues fire are the engine's decisions, not the editor's.

- The ruler shows the keyframe bars per track, `◆` for cues, `▼` at the
  playhead; the frame beneath is drawn as the battle field draws it.
- `.` and `,` step; `<` and `>` jump between keyframe and cue boundaries;
  `Home` / `End` seek; `+` / `-` change speed; `O` loops; `R` restarts.
- The cue log beside the frame lists the cues the playhead crossed. Audio is
  not hosted in the editor, so a cue is reported, never claimed audible.
- Stepping and seeking are inspection: a cue fires only when playback crosses
  it.

## Assign It

Open the Database (`Ctrl+D`), choose **Actors**, and find the `Summons` row:
`Enter` opens a picker over the project's summons, and each pick toggles a
member in or out. Under the row, one verdict per assignment: the summon
exists, the actor is eligible under its wielder policy, it is not story-locked,
it appears once, and an exclusive summon has no second starting holder. The
same rules run in `ichiloto validate`.

## Save And Validate

`Ctrl+S` saves the pair; saving an actor writes that one actor file and no
other. Reopen, and the tracks and art come back as authored. `ichiloto validate`
reports what the compiler refuses, a missing linked action, a malformed policy,
and assignment conflicts.

## Check It

- Every keyframe lies within the length; the cue the effect timing names
  exists.
- The preview completes, and the cue log shows the effect cue exactly once.
- Saving the summon leaves every other file in the project byte-identical.
