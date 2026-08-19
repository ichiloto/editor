---
title: Cutscenes
description: Author cinematic story sequences and summon presentations on the editor's Cutscenes screen, and preview both through the engine itself.
category: Tooling Loop
tags: [editor, cutscenes, cinematics, summons, preview]
order: 54
readTime: 5
---
`F4` opens the Cutscenes screen over the main shell. Two kinds of asset live there, and they are not the same thing as the event command lists the Database calls Common Events:

- a **Cinematic** is a staged story sequence on the field — a cast, a camera that can leave the player, lanes that run at once, music, narration, a title card, a transfer, and a skip that lands on the same ending as watching;
- a **Summon** is a frame-driven battle presentation — tracks of keyframes over a fixed number of frames, with cues that tell the battle when the effect lands.

Each is one folder holding a pair of files named after the folder (`<id>.data.php` with `<id>.script.php` or `<id>.timeline.php`). The folder name is the stable id. The editor reads both files, preserves every comment, local variable, heredoc and shared ASCII block you did not touch, and saves the pair as one transaction: if anything fails, neither file changes.

## The screen

Types on the left, the asset list beside them, the record pane with the asset's fields grouped, the Command Tree (or, for a summon, the Timeline), and the Preview. `Tab` cycles the panes, `/` filters, `Shift+A` creates, `Shift+D` duplicates, `Delete` removes with the references named first, `Ctrl+S` saves the asset, `Ctrl+A` saves everything dirty, and `Ctrl+Z` undoes every mutation, pinned to the asset it happened on.

## Cinematics

The record pane groups the definition: identity, staging (start map, initial presentation), the script, skip (policy, checkpoints, finalizer) and the cast. `Commands` opens as a frame, the same way the Database opens a choice's options: one row per command plus the rows its type uses, and nested blocks — `sequence`, `parallel` lanes, `branch` arms, `choice` options — open frames of their own. The Command Tree mirrors the whole thing and moves commands around: reorder, nest, un-nest, insert, duplicate, remove.

Skip is a second ending, not a cancel. With the policy `authored` and a finalizer written, the engine cancels every lane and runs the finalizer once, so a skipped run and a watched run reach the same final state. The editor explains this in the Skip group, and the Preview's comparison (`C`) runs the cinematic both ways and lists every difference.

## Preview through the engine

The Preview pane does not simulate anything. It builds an isolated scene — a fresh game state, the start map's tiles, collision and NPCs from your project, a camera that draws into the pane — and hands the asset, unsaved, to the engine's own cinematic controller and interpreter. You see what the engine draws; the column beside it lists the lanes the interpreter is running, the checkpoints, and whether a skip would be accepted. `Space` plays, `.` steps, `K` skips, `R` restarts, `J` jumps to a failed command. `Ctrl+T` plays the saved cinematic in the real game through a throwaway playtest root whose start map carries an automatic trigger.

## Summons

The record pane groups identity, lore, availability, wielders, playback and the timeline. Tracks and cues open as frames; a keyframe's multi-line art opens in a true multiline editor that keeps every space and backslash. `Space` on the Preview compiles the summon through the engine's compiler and plays it through the engine's playback session, with a ruler, the keyframe bars, the playhead, and a log of the cues the playhead crossed — reported, never claimed audible. An actor's starting summons are a multi-pick on the actor pane, each assignment judged by the validator's rules.

The always-current reference is the editor manual's *Cutscenes Screen* section; the two guides *Author a Cinematic Cutscene* and *Author a Summon Cutscene* walk through each from nothing to playing.
