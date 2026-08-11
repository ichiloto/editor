# Ichiloto Editor — Audit & Development Roadmap

*Assessment date: August 2026. Compiled from three audits: a measured
performance/architecture trace of the Ichiloto editor, a comparative study of
the hand-built Sendama console editor (the responsiveness benchmark), and an
authoring-UX review. All measurements taken on this machine.*

## The one-paragraph diagnosis

The editor is not slow because PHP is slow — **a full frame of real rendering
computes in 0.3–0.5 ms**. It is slow because the main loop polls the keyboard
at **~6.4 Hz** (a blocking 100 ms stdin read + a `shell_exec('stty size')`
fork every iteration + an unconditional 50 ms sleep), drops input events (one
event per tick; key-repeat bursts coalesce into a single move; extra mouse
events are discarded), amplifies latency exactly when you hold a key (an
escape-sequence "completion" retry loop costs up to +80 ms), and repaints by
forking `system("clear")` then issuing ~270 unbuffered write syscalls. The
Sendama editor proves the fix on the same terminal stack: non-blocking
drained input, an offline escape tokenizer, overwrite-in-place rendering, a
16.6 ms frame cadence — **≈17 ms key-to-pixel versus Ichiloto's worst-case
~230 ms plus a fork**. Beneath the latency sits a 7,020-line / 232-method /
66-field god object with render calls scattered across 150 sites, no undo,
three silent data-loss paths, no tests, and 2.6 MB of declared-but-unused UI
dependencies. The good news: the top latency fixes are hours of work, not a
redesign.

## The measured contrast

| | Ichiloto editor | Sendama console (benchmark) |
| --- | --- | --- |
| Loop sleep | `usleep(50_000)` — 20 fps ceiling | `usleep(16_666)` — 60 fps target |
| Stdin | `stty min 0 time 1` → `fread` blocks up to **100 ms** | `stream_set_blocking(STDIN, false)` + full drain, never blocks |
| Escape sequences | retry loop: up to 8 × 10 ms sleeps per read | offline longest-match tokenizer, zero extra reads |
| Key repeat | burst coalesces to **one** move; held `j` paints letters | repeat coalescing + 50 ms synthetic hold → smooth ~20 Hz |
| Terminal size | `shell_exec('stty size')` **every iteration** (6.66 ms) | every 10th frame, early-return when unchanged |
| Repaint | `system("clear")` fork (5.07 ms) + ~270 unbuffered writes | overwrite-in-place; `clear` at exactly one site |
| Idle overlay | full-screen repaint pathways | dirty-flagged: idle modal writes **zero bytes** |
| Events consumed per tick | 1 (rest discarded) | queued, none dropped |
| **Felt key→pixel latency** | **~157–230 ms + fork** | **~17 ms** |

## Ranked findings

### Performance (measured)
1. `stty size` subprocess every loop iteration — 6.66 ms, unthrottled
   (`Editor.php:4516` via `update()`). The engine's `Game::syncScreenSize()`
   has the same bug (its "throttle" variables aren't `static`).
2. Blocking `fread` with 100 ms termios timeout; no `stream_select`, no
   non-blocking mode (`Editor.php:261, 475`).
3. One input event per tick; extra key/mouse events in the same read buffer
   silently discarded (`Editor.php:354`, `1518`, `1621`).
4. Escape-sequence completion loop sleeps up to 80 ms and mis-judges
   multi-event bursts as incomplete — a positive-feedback lag amplifier
   (`Editor.php:488-526`).
5. `system("clear")` fork on every full redraw (5.07 ms) + fresh 24×80 grid
   allocated and never read (`termutil Console::clear`).
6. ~270 unbuffered `write()` syscalls per frame; no `ob_start` anywhere —
   the visible tearing (`EditorWindow::renderAt`).
7. Blocking animation preview freezes input entirely (`Editor.php:4112`).
8. Per-frame recomputation: inspector fields built twice per render, full
   O(W×H) event-bounds scans per mouse-move, layout re-derived 5–7× per
   frame, map width recounted per call.
9. Whole-workspace reload (all maps + all databases re-required and
   re-parsed) on save/duplicate/delete/Ctrl+R — 28 ms cold and growing
   linearly with project size.

### Architecture
- One 7,020-line final class holding ~63% of all editor code; five
  distinguishable applications (map canvas, inspector, database suite,
  dialogs, terminal host) interleaved in one namespace of methods.
- Render is a side effect of input: 150 direct render call sites; the loop's
  `render()` is nearly always a no-op; no single frame boundary to attach
  diffing or budgets to. Two byte-identical render helpers.
- 11 boolean modal flags dispatched by two hand-ordered `if` cascades that
  must be kept in sync manually; no modal stack.
- php-tui, laravel/prompts, league/climate: declared, installed (2.6 MB),
  **zero uses**. The editor hand-rolls what php-tui's buffer/diff model
  provides. Meanwhile `EditorWindow` forks the termutil `Window` to fix a
  rendering bug that never flowed upstream.
- `set_error_handler` escalates every PHP notice into a terminal teardown —
  then keeps running with a wrecked screen.
- **Zero tests** (pest + phpstan sit unused in require-dev); two git commits.

### Safety & UX (worst first)
1. **No undo/redo of any kind.**
2. **Silent data loss**: saving map A reloads the workspace and wipes unsaved
   edits on maps B–N; Ctrl+R discards everything unconditionally; Ctrl+Q
   exits with no dirty check; the Assets list has no dirty markers.
3. **Rename = rm -rf**: save derives the target folder from the editable
   Name/Region and deletes the old directory without confirmation.
4. Keyboard palette holes: `!` is swallowed globally (can't be typed or
   painted anywhere); `hjkl` steal 8 glyphs; `%^@` reserved; cancel is
   Esc/`c`/`n` depending on context; Enter is the destructive default on
   delete confirmations.
5. No scrolling in Assets/Inspector/Database-settings lists — selection and
   edit caret walk off-screen (dialogs scroll correctly; the algorithm
   exists).
6. Single truncated status line is the only feedback channel; errors and
   successes look identical; exceptions get cut off mid-message.
7. No help overlay, no keymap screen, no command palette; the Database key
   (`!`) is documented nowhere on screen.
8. Database entries cannot be deleted; 10 of 15 categories are
   indistinguishable dead shells; booleans/floats edited as raw text; two
   contradictory enum idioms; no validation before save (dangling map refs,
   spawn points out of range all save fine).
9. Single-cell keyboard painting only — no fill, rect, line, brush size,
   region copy/paste, or multi-select. (Mouse drag painting is the one bulk
   tool, and it's good.)

### Worth keeping (the good bones)
Mouse drag-painting with Bresenham gap-filling and eyedropper;
the destination/spawn-point round trip (navigate to the target map, pick the
cell in context, return with full state restored — the model for all future
cross-references); loot pickers backed by real project data; dialog list
scrolling; atomic tmp+rename file writes; styled-tile round-tripping;
dirty flags already plumbed through every data model; the live class-curve
preview; helpful empty states; live resize handling.

## The plan

### Phase 1 — The responsiveness sprint (quick wins, no redesign) ✅ *shipped 2026-08*
> Status: implemented. `InputDecoder` (src/IO/InputDecoder.php, unit-tested)
> replaces the blocking reader: non-blocking drained stdin, offline escape
> tokenizer, per-token dispatch (a key-repeat burst now lands one event per
> press). The loop runs a 16.6 ms deadline budget with the whole frame
> emitted as a single buffered write; the size probe is throttled to 250 ms;
> `system("clear")` is gone; animation preview is a non-blocking state
> (Shift+P toggles); warnings no longer tear the session down; layout and
> map-width are memoized. Item 3's Sendama-style synthetic hold window was
> deliberately deferred — native terminal repeat now arrives intact, which
> already reads correctly — revisit with Phase 3's input router if needed.
Port the proven Sendama mechanics onto the existing structure. Expected to
recover ~90% of felt latency:
1. Non-blocking stdin: `stream_set_blocking(STDIN, false)` +
   `stream_get_contents` drain per tick (kills the 100 ms read timeout)
2. Offline escape tokenizer (longest-match table + SGR mouse regex) feeding
   a persistent event queue; consume the queue each tick (kills the 80 ms
   retry amplifier, fixes dropped input and the paint-a-`j` bug)
3. Key-repeat coalescing + ~50 ms synthetic hold window for smooth held-key
   movement
4. Throttle `stty size` to every 10th frame with early-return when
   unchanged; fix the identical non-static-throttle bug in the engine's
   `Game::syncScreenSize()` while at it
5. Replace `system("clear")` with `\033[2J\033[H`; clear only when leaving
   full-screen overlays; overwrite-in-place otherwise
6. Wrap frame output in `ob_start`/`ob_end_flush` — one write per frame,
   no tearing
7. Drop the loop sleep to a 16.6 ms deadline-based budget (only after 1–5)
8. Make animation preview a state ticked from `update()` instead of a
   blocking call
9. Scope the error handler to real errors; stop tearing down the terminal
   for notices
10. Memoize per-frame derived data (layout, inspector fields, map width);
    delete the duplicate render helper

### Phase 2 — The safety sprint (before any refactor invites regressions) ✅ *shipped 2026-08*
> Status: implemented. Command-object undo/redo (`src/History/`, 500-entry
> cap) covers tile/event paints — a mouse drag coalesces into one stroke
> command — plus map resize, metadata, event fields/types/bounds, the
> dialog-driven edits (loot, options, destination+spawn), database field
> edits (identity-pinned so undo hits the right entry after the selection
> moves), and animation frame painting. Ctrl+Z undoes; redo is Ctrl+Y (and
> Ctrl+Shift+Z where the terminal reports CSI-u) — boot now un-maps the tty
> susp/dsusp characters so those keys reach the decoder instead of
> suspending the process. Save persists just the edited map in place; a
> rename swaps in one re-parsed map via `ProjectWorkspace::withReplacedMap`
> instead of reloading the workspace, and demands explicit confirmation
> before the folder move (fixing, in passing, the empty-region slug bug that
> silently relocated region-less maps into `new-map/`). Dirty `*` markers in
> the Assets list and DB categories, Ctrl+A Save All (skips pending renames
> with a warning), and an unsaved-changes guard on Ctrl+Q/Ctrl+R with a
> save-all-and-continue option. Status messages are typed
> (info/success/warn/error), colored, auto-expiring; Ctrl+E opens a detail
> overlay that points at logs/error.log. Pre-save validation warns — never
> blocks — on dangling destination refs, markers without definitions, and
> out-of-range spawn points. Tests grew 12 → 58: history
> push/undo/redo/coalescing/cap, ProjectMap parse/mutate/render/save,
> validator, layout math, editor-level undo, and a golden ANSI frame
> snapshot rendered through the ob_start+reflection harness. Deferred: undo
> for map create/duplicate/delete and DB entry creation (filesystem/identity
> operations with their own confirmations; entry deletion arrives in Phase
> 5), and history clears when a reload or rename replaces the loaded map
> instances. Also fixed: the `AnimationTargetPosition` fatal at
> Editor.php:3457 (missing import) along with the same latent missing
> imports for LootType, ChestType, Item, Weapon, Armor, and Accessory.
> Addendum (2026-08): the Quests database category landed on top of this —
> `assets/Data/quests.php` load/edit/save with flattened objective fields
> (Shift+O/Shift+X add/remove), dirty markers, Save All, identity-pinned
> undo/redo, and a settings-pane scroll window; tests 58 → 66.
1. Command-object undo/redo (Ctrl+Z / Ctrl+Shift+Z) over every mutation,
   with stroke-level coalescing for mouse drags
2. Stop reloading the whole workspace on save — persist the one map,
   in place
3. Global dirty registry: markers in the Assets list and all DB categories,
   Save All, and an unsaved-changes guard on Ctrl+Q and Ctrl+R
4. Explicit confirmation when save would move/delete a map folder
   (rename flow)
5. Typed, auto-expiring status messages (info/success/warn/error) + an
   error-detail overlay; point at the log file
6. Pre-save validation pass (dangling destination refs, markers without
   definitions, out-of-range spawn points) — warn, don't block
7. First tests: the input tokenizer, `ProjectMap` parse/mutate/render,
   layout math, and golden-frame render snapshots (the `ob_start` +
   reflection harness used to measure this audit works today)

### Phase 3 — Decomposition (the structural fix) ✅ *shipped 2026-08*
> Status: implemented as an incremental extraction — Editor.php remains the
> coordinator, delegating to eight new collaborators. `EditorLoop`
> (src/Runtime/EditorLoop.php) owns the 16.6 ms deadline-budget tick;
> `TerminalHost` (src/Runtime/TerminalHost.php) owns every terminal side
> effect — stty snapshot/raw mode, susp/dsusp undef, alt-screen, mouse
> reporting, the throttled size probe, clear-screen, and the buffered
> one-write-per-frame writer. `InputRouter` (src/IO/InputRouter.php) +
> `KeyBinding` consume the decoder queue and replace the hand-ordered
> dispatch cascade with modal handler registrations, global interceptors
> (Ctrl+E, `!`, mouse), an ordered base binding table, and a focused-pane
> fallback; `ModalStack` + a priority-ordered `Modal` enum (src/UI/) replace
> the 12 modal booleans — the old `is*Open` fields survive as property hooks
> over the stack, so dispatch and overlay rendering now share one ordering
> (unit-proven equivalent to the cascade). A `Panel` base
> (update/render/handleInput contract, focus gating, per-panel dirty flag)
> with `AssetsPanel`/`CanvasPanel`/`InspectorPanel` makes render a function
> of state: the ~135 in-handler render calls now mark panels dirty and the
> loop flushes once per tick — an idle frame writes zero bytes (tested).
> `DatabaseScreen` owns the Database screen's pane registry, dirty set, and
> fixed paint order with the six pane painters registered against it. The
> duplicated inspector/database edit buffers collapsed into one
> `TextFieldEditor` (+`TextFieldKeyResult`), byte-for-byte preserving the
> key grammar. Dependency decision: committed to termutil — php-tui,
> laravel/prompts, and league/climate were grep-verified unreferenced and
> removed from composer.json/vendor (~2.6 MB); swapping rendering engines
> mid-refactor was judged higher risk than keeping the proven
> EditorWindow/termutil path. Tests grew 66 → 99 (golden ANSI frame still
> byte-identical; new suites cover TextFieldEditor, ModalStack, InputRouter
> routing order, and editor-level dirty/flush/undo-through-router
> behavior); live expect smoke (boot → Tab cycle → paint → undo → Database
> → Quests → Esc → Ctrl+Q guard → exit) passed with checksummed-zero
> project writes. Deferred: moving the pane/dialog content builders (the
> `getDatabase*Lines`, dialog overlay, and window-body methods, ~5k lines)
> off the coordinator into their panels — the seams now exist and Phase 4/5
> should relocate bodies as they touch them; promoting `EditorWindow`'s
> ANSI fix upstream into termutil (outside this repo); Phase 1's synthetic
> key-repeat hold window (still unnecessary — native repeat arrives intact
> through the router).
Split along the panel seam, following the Sendama shape that is proven to
stay maintainable at similar scale:
- `EditorLoop` (tick/timing), `TerminalHost` (termios, size, alt-screen,
  mouse, buffered writer), `InputDecoder`/`InputRouter` (tokenizer, queue,
  per-mode binding tables), `ModalStack` (replaces the 11 booleans and both
  dispatch cascades)
- A `Panel` base (update/render contract, focus, per-panel dirty flag,
  `hasFocus()` early-return) with `AssetsPanel`, `CanvasPanel`,
  `InspectorPanel`; the Database becomes its own screen with its own panels
- Render becomes a function of state: handlers mutate and mark dirty; the
  loop renders once per tick; per-panel dirty flags mean idle costs nothing
- Extract the duplicated inspector/database edit buffers into one
  `TextFieldEditor`
- Decide the dependency story once: either adopt php-tui's Display/Buffer
  (already installed) or commit to termutil and delete php-tui, prompts, and
  climate from composer.json; promote `EditorWindow`'s ANSI fix upstream so
  engine and editor share one Window implementation

### Phase 4 — Input & UX coherence ✅ *shipped 2026-08*
> Status: implemented. **The Database now opens on Ctrl+D (F2 works too —
> both the `\033OQ` and `\033[12~` encodings)**: a control byte can never
> collide with an authorable glyph and it matches the Ctrl+letter global
> family; the same key toggles the screen closed. `!` and `hjkl`/`HJKL`
> paint on the canvas again (arrows remain the only cursor movement; the
> `!` reservation in the animation preview is gone too — the new costs are
> `?` and Ctrl+P, reserved for help/palette; the character map remains the
> escape hatch for reserved glyphs). Esc pops exactly one level everywhere
> — edit → pane, help/palette → what's beneath, one dialog, one screen —
> and Esc is the one cancel key: the scattered `c` cancel aliases are gone
> (`n` survives as the explicit "No" on yes/no prompts). The destructive
> confirmations (map delete, folder-move rename, discard-changes guard)
> now default to Cancel: Enter cancels, only an explicit `y` confirms;
> overlay copy updated to match. Scrolling: the DB-settings window formula
> was generalized into `UI/ScrollWindow` and now also drives the Assets
> list, the Inspector, and the palette list (selected row lands at
> `min(sel, visible-1)`, which the live edit cursors already assumed).
> `?` opens a help overlay generated from `InputRouter::describeBindings()`
> — KeyBinding grew keyLabel/description fields, so documented shortcuts
> can never drift from the dispatch table (unit-proven) — and the header's
> empty help slot now carries the permanent hint
> `?:Help  Ctrl+P:Palette  Ctrl+D / F2:Database`. Ctrl+P opens a command
> palette (UI/CommandPalette + PaletteItem): fuzzy substring-then-
> subsequence ranking over actions (save/save-all/undo/redo/reload/quit),
> tools (map/event mode, character map, help), every map, every database
> category (jumps straight to it, opening the screen if needed), and the
> event markers placed on the selected map (jumps to the marker in event
> mode). Typed inspector controls: event-data booleans and floats now get
> BOOLEAN/FLOAT controls (Enter toggles a boolean; floats accept digits,
> one `.`, leading `-`, and step on ↑/↓ while editing), and ←/→ in the
> Inspector adopts the quests-pane idiom everywhere — cycle enum options
> (chest/loot types included), toggle booleans, step numbers — with undo
> recorded per adjustment. Visible-but-fixed rows render distinctly
> (`Label · value` instead of the editable `Label: value`). Statuses now
> flow through a snackbar toast queue (Status/Toast + ToastQueue): INFO
> stays an instant ticker, SUCCESS/WARN/ERROR hold the line for their TTL
> while later messages queue (higher severity preempts and requeues the
> displaced toast; duplicates collapse; backlog capped at 5, surfaced as
> "(+N queued)" in the footer). Tests grew 99 → 142 (ScrollWindow,
> ToastQueue, CommandPalette filtering, router rebind/F2/`!`-free,
> Esc-one-level, help-derived-from-bindings, default-to-Cancel
> confirmations, inspector stepping/typed controls); the golden frame
> snapshot was regenerated deliberately for the new header hint and the
> non-editable row styling. Deferred: help/palette cannot open while a
> picker dialog is up (Esc out first — dialogs consume all input by
> design); palette entries for individual database *entries* (needs the
> Phase 5 filter work); mouse interaction inside the palette; and `?`
> painting on canvas/preview (character map covers it).
- Rebind Database off `!` (freeing it for authoring); drop `hjkl` from the
  canvas (freeing 8 glyphs); one cancel key (Esc pops exactly one level);
  destructive confirmations default to Cancel and require explicit `y`
- Scrolling for Assets/Inspector/DB-settings (lift the dialog algorithm)
- Help overlay on `?` generated from the binding tables (can never go
  stale); permanent hint line in the header's empty help slot
- Command palette (jump to map/category/event/tool/save) — solves search
  and discoverability in one screen
- Typed inspector controls: boolean toggle, float, enum picker; one enum
  idiom everywhere; visible non-editable rows; snackbar-style toast queue

### Phase 5 — Authoring power ✅ *shipped 2026-08*
> Status: implemented. **Canvas tools** (src/Canvas/) sit on the Phase 2
> stroke machinery: `ToolGeometry` answers *which cells does this gesture
> touch* (Bresenham line — now the one implementation, shared with the mouse
> drag's gap filling — rectangle outline/fill, square brush footprint,
> 4-connected flood fill), and one commit path, `applyCanvasWrites()`, turns
> any cell set into a single `PaintStrokeCommand`. A filled rectangle, a
> flood fill, a cut, and a paste each undo in exactly one Ctrl+Z (unit-proven
> per tool). The active tool is modal and always visible in the footer
> (`Tool: Rect Fill 2 @0,0 [5x3] clip 2x1` — tool, brush width, live anchor,
> selection, clipboard). New keys, all control bytes so no authorable glyph
> was reserved (`!`, `hjkl`, and `/` stay paintable), all registered with
> labels so the `?` overlay documents them automatically, and all
> canvas-focus gated so they fall through to the focused pane elsewhere:
> **Ctrl+N** next tool (Brush → Line → Rect → Rect Fill → Select),
> **Ctrl+W** brush width (1/2/3/5), **Ctrl+F** flood fill from the cursor,
> **Ctrl+K** eyedropper, **Ctrl+L** copy ("lift") the selection,
> **Ctrl+X** cut, **Ctrl+U** paste/stamp at the cursor (repeatable — each
> stamp is its own undo step). Enter is the universal tool verb: it paints
> under the brush, sets the anchor for a two-point tool, and completes the
> shape or selection on the second press; Esc pops the pending anchor, then
> the selection, before any other Esc level. Typing a glyph under a shape or
> select tool now *loads the brush* instead of dabbing at the cursor
> (the brush itself still paints on type, unchanged). The clipboard is
> layer-tagged, so a block lifted from the event layer refuses to land on
> tiles. Ctrl+B/Ctrl+C/Ctrl+V were deliberately avoided: ^C is still the
> terminal's SIGINT escape hatch and ^V is IEXTEN's literal-next.
> **Incremental `/` filter** (`UI/ListFilter`) narrows the Assets list, the
> Database entry lists, and all four picker dialogs (destination, loot,
> option, event type). Ranking is *not* a second algorithm: `CommandPalette`
> grew a public `filterLabels()`/`rank()` that the palette itself now routes
> through, so search behaves identically everywhere. Filtered lists move
> selection through visible rows only, the caret shows in the pane title
> (`Assets [Focus] /ove_`), a committed query keeps narrowing while returning
> the arrows to navigation, and Esc clears the filter one level before
> closing the surface. The Assets caret joins the inspector edit gate ahead
> of the global binding table, so a query may contain `?`, `/`, or a Ctrl+key
> without firing it. **Navigation stack** (src/Navigation/) generalizes the
> destination round trip into **Ctrl+G go-to-definition** and **Ctrl+B Back**
> over a bounded 50-entry stack of restore closures: actor→class (the new
> reference field), event→destination map (with the configured spawn point,
> also from the Inspector's Destination row), and skill→animation by name.
> Back restores map index, cursor, viewport, pane focus, editing mode, and
> the whole Database selection; a reload clears the stack along with the
> history, filters, and clipboard. **Database entry deletion** finally
> exists: **Del** in the entry list raises a Phase 4 destructive confirm
> (Enter/Esc/N cancel, only an explicit `y` deletes) as a *safety* modal that
> consumes everything while open, and every deletion is undoable —
> actors, classes, skills, quests, and animations all remove in memory and
> re-insert at their original index on Ctrl+Z. Honest note: the file effect
> is deferred to the next save (single-file databases are rewritten whole;
> a deleted actor's asset file is staged and unlinked at save time), so undo
> before saving costs nothing and undo after saving re-creates the entry on
> the following save. **The actor `class` field** landed as an enum picker
> over the `name` values in the project's `assets/Data/classes.php`, written
> to the actor's `data['class']` — matching the engine's new `ClassStore` /
> `Character::fromArray()` contract — with a `none` sentinel that removes the
> key entirely. The payload's top-level `'class' => Character::class` (the
> entity FQCN) is never touched. Option cycling became case-insensitive on
> match and verbatim on write, so the picker stores `Vanguard`, not
> `vanguard`; the editor also reads `data['role']` as the engine's documented
> alias. **Dirty markers** reached the surfaces that lacked them: the header
> carries the one global answer (`Project: X  *  unsaved changes (Ctrl+A
> saves all)`), the Database list-window title carries the category marker so
> animations and system — which track dirtiness per file, not per entry —
> show unsaved state where the author is looking, and animation rows carry
> the file-level marker explicitly. **Backups** (src/Backup/) are the
> autosave answer, and the design choice is deliberate: the editor never
> silently writes an author's source files, so "autosave" here means *a
> timestamped copy taken immediately before an overwriting save*, never a
> background write into the original. It is **off by default** and opt-in per
> project via `ichiloto.json` → `editor.backups`
> (`{"enabled": true, "retain": 5, "directory": ".ichiloto/backups"}`), with
> `ICHILOTO_EDITOR_BACKUPS=1|0` and `ICHILOTO_EDITOR_BACKUP_RETAIN=N`
> overriding it for a single session. Copies mirror the project-relative path
> under the backup root, retention prunes oldest-first per source file, a
> backup failure warns but never blocks the save, and the current policy is
> printed in the `?` overlay. Every save path is covered: single-map Ctrl+S
> (all three split files), Ctrl+A Save All, and each Database save.
> The `?` overlay grew past a 30-row terminal once the canvas tools landed,
> so it now pages through the same `ScrollWindow` (↑/↓, with the help line
> switching to `Arrows:Scroll  Esc:Close` only when there is more to see) and
> widened to 84 columns.
> Tests grew 143 → 198 (canvas geometry + one-command-undo per tool, the
> shared matcher and its three filter surfaces, navigation push/pop/bounds
> and both go-to-definition hops, deletion confirm semantics and undo, the
> actor class round trip through a real save, and the backup writer's
> enable/mirror/prune behaviour); the golden ANSI frame was regenerated
> deliberately for exactly two changes — the Assets help line
> (`/:Filter  Del:Delete`) and the footer's new `| Tool: Brush 1` segment.
> Live expect smoke on `examples/last-legend` (filled rectangle → one
> Ctrl+Z → flood fill → `/` filter → Ctrl+D → actor class picker → Ctrl+G →
> Ctrl+B → Ctrl+Q answered `y`) passed with all 130 `assets/**` files
> checksum-identical before and after.
> Deferrals, with reasons: skills carry no animation *reference* in the
> engine data model (only a `MagicEffectType`), so skill→animation resolves
> by name and says so when nothing matches — revisit when the engine adds a
> real reference; the canvas draws no on-screen preview of the pending
> line/rectangle or the selection rectangle (the footer reports anchor and
> selection instead) because the preview pipeline renders through
> `ProjectMap::renderPreview` and overlaying transient cells there is a
> Phase 6 rendering change; mouse-driven selection and mouse interaction
> inside the palette remain unimplemented (keyboard only); the `/` filter
> does not reach the character map or the command palette (the palette *is*
> the filter); and periodic background snapshots were rejected in favour of
> the save-time backup above.
- Canvas tools: brush sizes, line, rectangle (outline/filled), flood fill,
  eyedropper key, rectangular select with copy/cut/paste/stamp — all cheap
  atop the existing stroke code
- Incremental filter (`/`) in Assets, DB lists, and pickers
- Navigation stack: generalize the destination round-trip into
  go-to-definition (actor→class, skill→animation, event→map) with Back
- Database entry deletion; add the missing actor `class` reference field
- Dirty markers everywhere; autosave/backup file option

### Phase 6 — Product completeness (tracks the engine roadmap) ✅ *shipped 2026-08*
> Status: implemented. **The ten stub categories are gone**, replaced by one
> schema-driven engine (`src/Database/`) rather than ten hand-wired code
> paths: a `RecordSchema` declares where a category's records live, what an
> entry is called, and which fields the settings pane offers, and
> `ProjectRecordDatabase` supplies listing, flattened settings rows, typed
> coercion, dirty tracking, Save All participation, identity-pinned undo, and
> entry deletion for all of them. Adding a category now costs a schema
> declaration (`RecordSchemaCatalog`), not a field on the coordinator — the
> Editor gained one `array<string,int>` selection map and ~8 dispatch
> branches for eleven categories. Three storage shapes are supported:
> `LIST_FILE` (states, troops, items/weapons/armors, enemies), `DIRECTORY`
> (one file per record — skits, event scripts), and `CONFIG_SUBTREE` (terms,
> flattened out of `config.php`'s `vocab`/`messages` trees), plus a
> `FILE_LISTING` mode that inventories a directory *without evaluating it*
> (types — requiring those enum declarations into the editor's process risks
> a redeclaration fatal).
> **Editability is detected, never declared.** `PhpDataFile` loads a data
> file, keeps everything from `<?php` up to the top-level `return`
> byte-for-byte, and regenerates only the returned expression through an
> enum-aware exporter (`PhpValueExporter`, which emits `\Vendor\Enum::CASE`).
> A category is writable only when two probes pass: every leaf is a scalar,
> array, or enum case; and no comment sits *inside* the returned data, since
> a rewrite would drop it. So **items, weapons, armors, and enemies are
> read-only with an honest status message** — those files are PHP that
> *builds* data (`enemies.php` shares `BasicSkill` instances between enemies
> through local variables; `items.php` constructs effects inline), and
> regenerating them would mean inventing source. They are fully *browsable*
> instead: an enemy shows level, all eight stats, sprite, battle rewards, and
> element affinities, and every write path (`setField`, `addRecord`,
> `removeRecord`, `save`) refuses with the reason. Re-author such a file as a
> plain array and the editor picks it up as editable with no editor change.
> **Terms** is genuinely editable — `config.php` round-trips including its
> enum values — but is read-only for `last-legend` today because its config
> carries inline comments; the message says exactly that, and moving the
> comments above `return` restores editing. **Tilesets** stays visible and
> honest: the engine has no tileset system, so the category explains that map
> tiles are painted on the canvas instead. **States** and **troops** are
> fully editable (troop members flatten to `member0Enemy`/`member0Position0`
> rows), and optional keys *disappear* when cleared rather than being written
> as `0`/`false`, matching the engine's defaults.
> **Two new editors landed for the newer engine systems.** *Skits*
> (`assets/Data/Skits/*.php`, a new Database category) edit id/title/where/
> conditions/speed plus flattened `beat<N>Speaker`/`beat<N>Text` rows;
> `Shift+A` creates one as its own file, and since `SkitManager` keys skits by
> their payload `id` rather than filename, editing an id needs no rename.
> *Event scripts* fill the long-empty **Common Events** category
> (`assets/Events/*.php`): the entry list is script ids, and the settings pane
> flattens the command list with **per-type field sets** — a `text` command
> shows Speaker/Text, a `transfer` shows Map/X/Y — covering all 15 interpreter
> command types. `Shift+O`/`Shift+X` became the *one* sub-list idiom
> (quest objectives, skit beats, troop members, event commands), and
> `ConditionCodec` became the one representation of the engine's world-condition
> grammar, shared by quest prerequisites, skit conditions, and `branch` arms.
> **Playtest-from-editor is `Ctrl+T`** and does not mutate the project. The
> engine offers no starting-map or spawn override — verified: `play` passes
> zero argv and zero env to the game, and every engine path is
> CWD-relative — so `PlaytestOverlay` works with that grain: a temp root of
> symlinks back to the real project, with exactly two entries replaced by real
> ones (a generated `assets/Data/system.php` carrying the spawn, and an empty
> `.data/` so playtest saves cannot touch the author's slots). Maps and assets
> are symlinks, so the playtest runs against live files; the overlay is
> deleted on exit; teardown guards `is_link()` before descending so it can
> never recurse into the project. `TerminalHost` grew
> `suspendForChildProcess()`/`resumeAfterChildProcess()` to hand the tty over
> and take it back. The editor refuses to playtest a *dirty* map rather than
> silently running the on-disk version.
> **The manual is real**: `docs/manual.md` (531 lines) plus six task guides
> under `docs/guides/` (1,096 lines total) — index, make-a-new-map,
> add-an-npc, wire-a-quest, author-a-cutscene, write-a-skit — following the
> Sendama console's structure and its "current behavior:" hedging idiom.
> `tests/Unit/ManualCoverageTest.php` is the staleness guard: every key token
> in every registered binding must appear backticked in the manual, every
> Database category must be named, every schema's backing path must be
> documented, every event command type must be listed, all six guides must
> exist, and every relative doc link must resolve. Website pages were drafted
> under `docs/website/` (two `guide/tooling-loop/` pages with the site's YAML
> front matter) and deliberately **not** written into the website repo.
> **Theming** reads the project's own `ui.menu.border` and
> `ui.menu.selection_color` from `config.php`: engine border packs are
> translated into termutil's `BorderPack` (the engine exposes static getters,
> termutil wants a value object) and applied to every window through a static
> default on `EditorWindow`, so no call site needed changing; the focused
> pane is drawn in the game's own selection color via `resolvePaneColor()`.
> Unresolvable or absent theme keys fall back silently — a bad config never
> stops a project opening. The active theme prints in the `?` overlay.
> Tests grew 198 → 250 (record engine load/edit/save round-trips per storage
> shape, header preservation, the interior-comment guard, read-only refusal on
> every write path, per-category fixtures for states/troops/items/terms/types,
> the skit and event-script editors including per-type command fields and
> bare-list saves, editor-level selection/undo/Save-All wiring, playtest
> overlay isolation proven by checksumming the project before and after, five
> theming cases, and the manual coverage guard). The golden ANSI frame was
> regenerated deliberately for exactly one change — the focused pane's border
> escape moved from `\033[1;34m` to `\033[1;33m`, the fixture project's
> configured selection color — with the frame otherwise byte-identical
> (5,800 bytes before and after). Live expect smoke on `examples/last-legend`
> passed with every `assets/**` file checksum-identical before and after.
> Deferrals, with reasons: **the summon timeline editor is out of scope** and
> untouched — it is a sequencing UI of a different shape and deserves its own
> phase. Nested event arms (`choice.options`, `branch.then`/`else`) are shown
> as fixed rows and round-trip untouched rather than being flattened into the
> settings pane, which would be unreadable, or dropped, which would be worse.
> The editor still cannot point a map marker at a `ScriptEventTrigger` — the
> event-type catalog carries five types and the `scriptId` is wired by hand;
> that is the obvious next Phase 7 item now that scripts are authorable. Map
> `npcs` (the wandering-character array) remain hand-authored. Event scripts
> cannot be renamed from the editor because renaming the file would silently
> break every map referencing the old `scriptId`. Terms editing needs a
> comment-preserving surgical writer before it works on comment-carrying
> configs; the same guard is *not* yet applied to the pre-Phase-6 categories
> (quests, classes, skills), which would still drop an interior comment on
> save — worth retrofitting. And the playtest still lands on the title screen:
> booting straight into the field needs an engine hook in
> `GameLoader::loadNewGame()` (override `mapId`/`playerPosition`) plus
> `Game::start()` (skip scene 0), which is engine-side work this phase
> deliberately did not do.
- Implement the 10 stub database categories (items, weapons, armors,
  enemies, troops, states, terms, common events, tilesets, types)
- New editors as engine systems land: quests, skits, cutscene command
  lists, summon timeline editor
- Playtest-from-editor (launch `play` on the current map with a temp spawn)
- A real editor manual (the Sendama console maintains a 646-line manual plus
  task guides — same standard here), plus website docs pages
- Theming: respect the project's border pack and selection color so the
  editor feels like part of the product family

## Production-hardening extension — save-compatibility validation ✅ *shipped 2026-08*

> Status: shipped. Manifest validation, focused/full regression coverage,
> static analysis, Composer validation, and validation through the existing
> Console entry point are complete. This follows the existing Phase 2
> validation conventions and Phase 6 schema-driven database architecture; it
> does not renumber either phase or introduce another editor roadmap.

The existing `ProjectValidator` now validates the project-owned
`assets/Data/save-compatibility.php` manifest using the Engine's shared
content-category vocabulary. It reports missing or invalid content versions,
bad categories and one-shot event identities, self-aliases, cycles,
contradictory mappings, alias/tombstone conflicts, verifiable missing targets,
and incomplete, duplicate, or impossibly ordered migration chains through the
same issue/status path as all other project validation.

No parallel validation command, condition registry, generic record database,
or migration/alias TUI was added. The manifest remains deliberately authored
as PHP because migration registration is executable project code; a safe
specialized editor is outside WP1.

## Sequencing notes
- Phase 1 is days of work and transforms perceived quality; do it first and
  ship it alone.
- Phase 2 before Phase 3: refactoring without undo, dirty guards, or tests
  risks authors' data while the ground moves.
- The Phase 2 test harness (golden-frame snapshots via output buffering) is
  the safety net that makes Phase 3's decomposition mechanical rather than
  brave.
- Phases 4–5 ride on Phase 3's binding tables and panel model; attempting
  them on the god object doubles their cost.
