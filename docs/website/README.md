# Website documentation drafts

These are **drafts for the website repo**, not published pages. Nothing here is
written into `../../../website` by the editor project — copy a file across when
it is ready.

Target: `website/src/Docs/Content/<path>`, mirroring the tree below.

| Draft | Target path |
| --- | --- |
| `guide/tooling-loop/editing-in-the-editor.md` | `src/Docs/Content/guide/tooling-loop/editing-in-the-editor.md` |
| `guide/tooling-loop/the-database.md` | `src/Docs/Content/guide/tooling-loop/the-database.md` |
| `guide/tooling-loop/cutscenes.md` | `src/Docs/Content/guide/tooling-loop/cutscenes.md` |

Conventions copied from the existing site content:

- YAML front matter with `title`, `description`, `category`, `tags`, `order`,
  `readTime`, and optional `heroImage` / `heroImageAlt` / `heroImageCaption`.
- `order` sequences a page within its category. The Tooling Loop category
  currently uses 50 (index) and 51; these drafts take 52, 53 and 54.
- Body starts immediately after the front matter, with no repeated H1 — the
  layout renders `title` as the page heading.
- Absolute `/images/...` paths for screenshots. The drafts reference existing
  images only; new screenshots would need to be added to the website repo.

The canonical, always-current reference remains
[`docs/manual.md`](../manual.md) in this repository — the website pages are an
introduction, not a replacement, and the manual is the one covered by a
staleness test.
