<p align="center">
    <img src="docs/images/ichiloto-logo-md.png" alt="Ichiloto Logo" width="200">
</p>

# Ichiloto Editor

The full-screen terminal editor for [Ichiloto Engine](https://github.com/ichiloto/engine) projects.

This package powers `ichiloto edit`: one screen where a game's maps, NPCs, events, database records and cutscenes are authored, previewed and validated — without hand-writing PHP for any supported field, and without the editor ever rewriting a byte an author didn't change. It edits the same files the engine runs, in place, and plays cinematics and summons through the engine itself before anything is saved.

```text
 ┌─Ichiloto Editor────────────────────────────────────────────────────────────────────────────────┐
 │ Project: Sample Project                                                                        │
 └─?:Help  Ctrl+P:Palette  Ctrl+D / F2:Database───────────────────────────────────────────────────┘
 ┌─Assets [Focus]───────────────┐ ┌─Canvas─────────────────────┐ ┌─Inspector──────────────────────┐
 │ Maps                         │ │ Preview: test-map          │ │   Name: Test Map               │
 │ > test-map                   │ │ Test Map |  | 12 x 5 | vie │ │   Region:                      │
 │                              │ │ ############               │ │   Description: A tiny fixture  │
 │                              │ │ #  ~~~     #               │ │                map.            │
 │                              │ │ #          #               │ │   Size                         │
 │                              │ │ #          #               │ │     X: 12                      │
 │                              │ │ ############               │ │     Y: 5                       │
 │                              │ │                            │ │   Events · 1                   │
 │                              │ │                            │ │   Triggers · 0                 │
 │                              │ │                            │ │   Audio                        │
 │                              │ │                            │ │     Background Music: (None)   │
 │                              │ │                            │ │   Encounters · off             │
 │                              │ │                            │ │     Troops · 0                 │
 └─/:Filter  Del:Delete─────────┘ └─%:Map  ^:Event  @:Chars────┘ └─Enter:Edit─────────────────────┘
 ┌─Status─────────────────────────────────────────────────────────────────────────────────────────┐
 │ Selected map: test-map | Focus: Assets | Mode: Map | Tool: Brush 1                             │
 │ Cursor: (0, 0) | Viewport: (0, 0) | Ready.                                                     │
 └─?:Help  Ctrl+P:Palette  Tab:Pane  Enter:Edit  Ctrl+S:Save  Ctrl+A:Save All  Ctrl+Z:Undo  Ctrl+Y┘
```

That frame is the editor rendering itself at 100x24 — the manual's copy of it is enforced by a test, so the picture cannot drift from the program.

## What It Does

- **Maps** — create, paint, resize, duplicate and delete maps; brush, line, rectangle and fill tools; an event layer; a character map for reserved glyphs; map-level background music and weighted random encounters.
- **NPCs** — first-class map NPCs (`F3`): placement, sprites and directional sprites, fixed or bounded wander, visibility conditions, dialogue with conditional variants, inline scripts, and completion writes.
- **Events** — the engine's trigger types placed and configured on the canvas: Dialogue, Transfer Player, Shop, Sleep, Chest, Story Script, and Cinematic.
- **Database** (`Ctrl+D` / `F2`) — actors, classes, skills, quests, animations, states, troops, skits, event scripts, terms and system settings authored; items, weapons, armors and enemies browsed with the reason they are read-only stated.
- **Cutscenes** (`F4`) — cinematic and summon cutscenes as paired-file assets: metadata, cast, a nested command tree with parallel lanes, skip policy and finalizer, summon tracks, keyframes and cues — previewed through the engine's own interpreter and playback session before a byte is written.
- **Safety** — everything is undoable and identity-pinned; dirty state is tracked by content, so undoing back to the saved state is clean again; saves are atomic and pair-aware; references are picked from what the project actually has, never spelled from memory.
- **Validation** — one `ProjectValidator` behind the editor and `ichiloto validate`, judging content by what the engine will actually do with it.
- **Playtest** (`Ctrl+T`) — the real game, launched from the cursor against a throwaway overlay, so a playtest can never write into the project.

## Getting Started

The editor is opened through the [Ichiloto Console](https://github.com/ichiloto/console):

```bash
composer global require ichiloto/console
cd my-rpg
ichiloto edit
```

Useful options:

```bash
ichiloto edit --directory /path/to/my-rpg
ichiloto edit --no-tmux
```

Press `?` anywhere for every shortcut the current context offers, and `Ctrl+P` for the command palette.

## Documentation

- [The manual](docs/manual.md) — the panel-by-panel reference, kept current by tests: a registered keybinding that is not documented fails the suite.
- [Guides](docs/guides/README.md) — task-oriented walkthroughs, from a first map to a staged cinematic.
- [Roadmap](docs/roadmap.md) — what shipped, in what order, and what was deliberately deferred.
- [Website drafts](docs/website/README.md) — introduction pages staged for the website repository.

## Stack

- PHP `^8.4`
- Symfony Console
- `atatusoft-ltd/termutil` for terminal control
- `ichiloto/engine` for previews, hydration and validation

## Architecture

- `src/Editor.php` is the coordinator: the loop, focus model, input dispatch and rendering meet here.
- `src/IO` and `src/UI` carry the input router, key bindings, modal stack, panels and screens.
- `src/Database` is the schema-driven record engine behind every Database category — one `RecordSchema` per category rather than one hand-wired code path each.
- `src/Field` models map-local content: NPCs, encounters.
- `src/Cutscenes` holds the paired-file cutscene models, the token-level source-preserving writer, and the engine-hosted preview.
- `src/Playtest` builds the throwaway overlay a playtest runs against.
- `src/Validation` is the shared `ProjectValidator` the console's `validate` command also runs.

## Local Development

Install and verify from the `editor` repo:

```bash
composer install
./vendor/bin/pest
composer analyse
```

Set `ICHILOTO_GAME_SRC=/path/to/game` to include a game project in the
production-verification tests. `docs/manual.md` is covered by
`tests/Unit/ManualCoverageTest.php`; registered keybindings must be documented.

## Project Links

- Engine repository: [github.com/ichiloto/engine](https://github.com/ichiloto/engine)
- Console repository: [github.com/ichiloto/console](https://github.com/ichiloto/console)
- Website repository: [github.com/ichiloto/website-v2](https://github.com/ichiloto/website-v2)
- Editor issues: [github.com/ichiloto/editor/issues](https://github.com/ichiloto/editor/issues)
