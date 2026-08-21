# Ichiloto Editor Documentation

Everything here describes the editor as it exists today.

| Document | What it is |
| --- | --- |
| [manual.md](manual.md) | The panel-by-panel reference: layout, focus model, every screen, every keybinding. Covered by tests — `tests/Unit/ManualCoverageTest.php` fails when a registered keybinding is undocumented, and `tests/Unit/ManualLayoutTest.php` fails when the shell picture drifts from what the editor draws. |
| [guides/](guides/README.md) | Task-oriented walkthroughs, in reading order: a first map, an NPC, a quest, a Common Event, a skit, a cinematic, a summon. |
| [roadmap.md](roadmap.md) | The development record: the original diagnosis, each shipped phase and gate, and what was deliberately deferred with the reason. |
| [website/](website/README.md) | Draft introduction pages staged for the website repository — copies, not canon; the manual stays the covered reference. |

The repository's own [README](../README.md) is the front door; [AGENTS.md](../AGENTS.md) carries the engineering rules for anyone (or anything) changing the editor.
