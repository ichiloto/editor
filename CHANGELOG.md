# Changelog

## 0.5.0

Ichiloto Editor 0.5.0 delivers a full-screen terminal workspace for authoring,
validating, and playtesting Engine 0.5 projects.

### Highlights

- Added map authoring for metadata, music, encounters, events, transfers,
  collision geometry, and map-local NPCs.
- Added schema-driven database authoring for actors, classes, inventory,
  equipment, enemies, skills, quests, knowledge, event commands, and project
  policy.
- Added a first-class Cutscenes workspace for paired cinematic and summon
  assets, timeline lanes, frame editing, camera routes, cast assignments,
  previews, skip comparisons, and summon playback.
- Added in-place PHP source editing that preserves surrounding project code,
  formatting, stable identities, shared-file changes, and no-op saves.
- Added undoable tree and record operations, durable dirty-state tracking,
  structured pickers, contextual key handling, and responsive terminal panes.
- Added project validation for runtime references, identity conflicts, map and
  event contracts, battle escape policy, summons, achievements, and save
  compatibility manifests.
- Added isolated playtest overlays for maps and cutscenes while keeping project
  saves untouched.

### Requirements

- PHP 8.4 or newer within the PHP 8 release line.
- `ichiloto/engine` 0.5.
- Pest 5.1 for the development test suite.
