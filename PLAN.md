# Zoom-tree v2 — sketch plan

A clean-slate plan for the zoomable tree viewer, drawing from `~/Sites/zoom-tree`
(server-side glyph generation) and `~/Sites/tile-o` (client-side viewer
experiments). Read this, mark it up, and we'll use it as the basis for a new
repo.

## Goals

- Render arbitrarily large binary phylogenies as a Google-Maps-style zoomable,
  scrollable tree.
- A user pastes/uploads a Newick tree and gets a shareable URL to the viewer.
- The viewer supports `+`/`-` and click-to-zoom-on-this-node. Both keep a chosen
  anchor row at a constant screen position across zoom changes (the bit that
  didn't quite work in tile-o).

## Scope decisions for v1

- **Binary trees only.** Multifurcations rejected at upload (or arbitrarily
  binarised — TBD). The doubling-by-zoom invariant assumes binary.
- **PHP server, vanilla JS client.**
- **Glyphs rendered client-side from JSON.** No per-node SVG files. Smaller
  artefact footprint, easier to re-theme. Cost: ~100 lines of glyph-builder JS.
- **One JSON per tree on disk, keyed by content hash.** No DB. Add SQLite later
  if/when we need server-side label search.
- **No animation.** FLIP-based open/close transitions deferred to v2.
- **Labelling internal nodes is out of scope.** v1 displays whatever the Newick
  carries; the McDonald/kraken heuristics in current `rows.php` are a separate
  research problem and stay separate.

## Data model

One JSON per tree, generated once at upload. **Parallel arrays keyed by node
id**, not an object-per-node — at 200K nodes the latter is ~40 MB, the former
~12 MB raw / ~2-4 MB gzipped (PHP serves with `Content-Encoding: gzip`):

```json
{
  "id": "abc123def456",
  "config": {
    "tree_width": 1000,
    "row_height_open": 12,
    "row_height_closed": 24,
    "min_zoom": 1,
    "max_zoom": 14,
    "initial_size": 9
  },
  "n": 199999,
  "labels":     ["", "Gekkonidae", "Phyllurus_amnicola", ...],
  "parent":     [-1, 0, 0, 1, 1, ...],
  "x":          [0, 134, 256, 643, ...],
  "max_x":      [1000, 800, 0, 800, ...],
  "first_zoom": [1, 1, 2, 2, 3, ...],
  "style":      [0, 0, 1, 2, ...],
  "inorder":    [3, 7, 9, 11, ...],
  "child_l":    [1, 3, -1, ...],
  "child_r":    [2, 4, -1, ...],
  "crossings":  [[], [], [0], [0,1], ...]
}
```

Each array is length `n` (or `n_internal` for internal-only fields).
`max_x[i]` is the x of the rightmost descendant — only meaningful for
internals; used to draw the closed-triangle polygon at render time. `child_l`,
`child_r` are -1 for leaves. (We don't need both `parent` and children but
both make some operations one-liners; the duplication costs ~3 MB and saves
client-side complexity.)

`crossings[i]` is the list of ancestor ids whose vertical bar passes through
row i — invariant under zoom (we settled this), so it's a one-time
precomputation at upload time, not a per-viewer cost. At 200K nodes, average
~9 entries per node, ~1.8M ints total, ~3-5 MB gzipped on the wire.

What's *not* stored, intentionally:

- **No polygons.** The closed-triangle for node i is
  `[(x[i], mid), (max_x[i], 0), (max_x[i], h), (x[i], mid)]` — one literal
  line at render time.
- **No `leaf` flag.** `child_l[i] === -1` is the test.
- **No `inverse_inorder` (id→inorder).** Built once on the client during JSON
  ingest, not stored on the wire.

Invariants:

- Node `i` is **visible** at zoom z iff `first_zoom[i] <= z`.
- `i` is **open** at zoom z iff at least one of `child_l[i]`/`child_r[i]`
  satisfies the same. Else **closed triangle**. Leaves render as themselves.

Pages, heights, scroll positions are all derived from these arrays.

## Glyph rendering (client-side)

Three SVG fragments per visible node, built by a tiny `glyph.js`:

- `leaf` → dot + label at (x, midpoint).
- `open` → horizontal stub back to parent's x + circle + label + vertical
  half-segment at parent's x (top, middle, or bottom depending on `style`).
- `closed` → horizontal stub + triangle from
  `(x[i], mid), (max_x[i], 0), (max_x[i], h)`.

Plus, in every row, a 1px vertical at each crossing-ancestor's x — looked up
from the precomputed `crossings[i]` array. Bar endpoints (top of parent's bar
at left-child row, bottom at right-child row) come from the child's own glyph
via the `style` field — they're *not* crossings.

## Viewer architecture

Carry over from `tile-o/step-8-fork.html`, fixing the geometry/timing bug:

- `#viewer` is the scroll container.
- `#pages` holds N empty `.page` divs, each `style="height:Xpx"` where X is the
  pre-computed sum of its rows' heights at the current zoom.
- IntersectionObserver fills page content on entry (debounced ~100ms).
- LRU cache (size ~5) blanks innerHTML on eviction.
- Rows per page: 10 (validated as a sweet spot for layout stability in
  step-8).

## The zoom operation (the bit that broke)

Given current zoom z, target zoom z′, and anchor node id a:

1. Compute `oldY = absoluteY(a, z)` *arithmetically* — sum of row heights for
   all visible nodes preceding a in inorder at zoom z.
2. Capture `oldScreenY = oldY - viewer.scrollTop`.
3. Rebuild pages for z′ (new visible set, new page heights).
4. Compute `newY = absoluteY(a, z′)` arithmetically.
5. Set `viewer.scrollTop = newY - oldScreenY`.

**Zero `getBoundingClientRect` on the zoom path.** The bug in step-8-fork was
"DOM hasn't laid out yet when we measure"; the fix is to never measure —
heights are known from JSON, not from layout.

Picking the anchor:

- **`+`/`-` buttons:** anchor = the row whose absoluteY at z straddles
  `scrollTop + viewport.height/2`. Found by binary search on the
  cumulative-height array.
- **Double-click on a node:** anchor = the clicked id directly (it's already in
  `data-id`).
- **Zoom-out when anchor is collapsed at z′:** walk parent pointers from a
  upward until you hit an id with `first_zoom <= z′`. The JSON has parent ids,
  so this is one short loop.

## Upload flow

1. `index.html` — form: textarea (paste Newick) + file input + submit.
2. POST → `upload.php`:
   - Parse and validate Newick. Reject non-binary, malformed, or > N nodes.
   - `id = sha256(newick)[:12]`.
   - If `trees/<id>.json` exists, skip straight to redirect.
   - Else: parse → compute inorder, x-coords, polygons, crossings, first_zoom
     (priority queue) → write `trees/<id>.json`.
   - Redirect to `viewer.html?id=<id>`.
3. `viewer.html?id=<id>` — fetches `trees/<id>.json`, hands it to `PageViewer`.

Expected timing: sub-second for trees up to ~10k leaves; tree-of-life-sized
trees should still complete in seconds. If we ever need it async, a job queue +
"email-when-ready" comes later.

Public-by-unguessable-id is the simplest sharing model. No login. Garbage-
collect trees not accessed in N days via cron — also later.

## Repo layout

```
/
  README.md
  index.html             # upload form
  viewer.html            # the viewer
  upload.php             # POST handler
  api/
    tree.php             # serve trees/<id>.json (or use static)
  src/
    php/
      tree-parse.php     # Newick parser (lift from zoom-tree/tree/)
      tree-build.php     # parsed tree -> v2 JSON
      priority-queue.php # real heap (Fibonacci), not the usort fake
    js/
      viewer.js          # PageViewer class
      glyph.js           # SVG fragment builder
      lru.js             # LRU cache (lift from tile-o)
  trees/                 # generated JSON, gitignored
  test-data/             # sample Newick trees
```

## What carries over

From **zoom-tree**:

- `tree/tree.php`, `tree/node.php`, `tree/tree-parse.php`,
  `tree/node_iterator.php`, `tree/inorder_iterator.php`,
  `tree/utils.php` — Newick parsing, inorder iteration, branch-length /
  cladogram handling, weights.
- `tree/tree-order.php` — Left/Right ordering for ladderisation.
- The growth loop in `read_tree.php:273-289` — priority queue → `first_zoom` per
  node.
- The crossings algorithm in `read_tree.php:62-80` — confirmed correct over the
  full tree, no per-zoom recomputation.

From **tile-o**:

- `lru.js`.
- IntersectionObserver + LRU + paged content pattern from `step-8-fork.html`.
- The `PageViewer` class skeleton.
- `heap.html`'s Fibonacci heap — use server-side to replace the `usort()`
  fake-priority-queue.

## What we leave behind

- Pre-rendered per-node SVG files (`rows/`, `rows-gecko/` etc).
- All the experimental `step-N.html` viewers except the `step-8-fork` skeleton.
- The Google-Maps-style `tile-o/viewer.html` tile-pool approach — superseded.
- Internal-label heuristics in `rows.php` (McDonald, kraken-style, MRCA hand-
  tuning) — separate problem, comes back as v2.
- The half-implemented `index.html` and the duplicated tree-building logic
  spread across `zoom.php`, `zoom_levels.php`, `read_tree.php`.

## Scalability — 10K to 100K leaves

Target range: 10K–100K leaves, i.e. ≈20K–200K total nodes. Tree-of-life-sized
(>1M leaves) is out of scope for v1.

**Server-side build (one-time per tree, at upload).** The priority-queue growth
loop is O(N log N) with a real heap (replacing the `usort()` fake). For 200K
nodes that's ~4M comparisons, well under a second in PHP. Inorder, x-coords,
max_x, first_zoom — all single tree walks. Total upload-to-JSON wall time
should be 1–3 s at the 100K-leaf end.

**JSON size.**

| Leaves | Nodes  | JSON raw | JSON gzipped |
|-------:|-------:|---------:|-------------:|
| 10K    | 20K    | ~2 MB    | ~300 KB      |
| 100K   | 200K   | ~22 MB   | ~5–8 MB      |

(Estimates assume ~30-char average label and parallel-array encoding,
including precomputed crossings.) Always serve gzipped. Browser parses 22 MB
JSON in ~1 second on desktop.

**Browser DOM.** At full zoom on a 100K-leaf tree, ~200K visible rows means
~20K page divs (10 rows/page). Empty divs are cheap — Chrome handles 20K
sized-but-empty `<div>` elements in single-digit milliseconds. The
IntersectionObserver scales the same way. The LRU-bound innerHTML means at any
moment only ~50 rows of SVG markup are realised.

**Page rebuild on zoom.** Filtering 200K `first_zoom` entries and bucketing
into pages is one linear pass + one cumulative-sum pass: ~10 ms. The
arithmetic-only `absoluteY` lookup is a binary search on a 20K-entry array:
microseconds.

**Where it would break first.** Three places to watch as we approach the upper
end:

1. **Initial JSON parse.** 12 MB JSON.parse is fine; 50 MB starts to feel slow
   on phones. If we ever go above 100K leaves, switch to a streaming binary
   format (e.g. one fetch per array as a typed-array blob).
2. **Page-div count.** Above ~50K page divs the layout-tree gets heavy in
   Chrome. Mitigation: bigger page bundles (e.g. 50 rows/page) at higher node
   counts.
3. **Crossings storage at extreme depth.** Caterpillar trees (depth ≈ N) blow
   up the size of `crossings[]` from O(N · log N) to O(N²). For real
   phylogenies depth is O(log N) and this is a non-issue. If we ever ingest a
   pathological tree, reject at upload with a clear error rather than serving
   a 5 GB JSON.

So: the v1 plan handles the stated range with comfort. We're nowhere near the
fundamental limits of the architecture — the limits show up at >1M leaves,
which we're explicitly punting on.

## Open questions for you

1. **Upload formats.** Newick only? Or also Nexus / PhyloXML / Beast? My
   recommendation: Newick only for v1; handle others by accepting and
   normalising server-side later.
2. **Multifurcations.** Reject? Auto-binarise (with zero-length internal
   branches)? Auto-binarise is friendlier but mildly opinionated.
3. **Glyph rendering.** Client-side JS (my recommendation) vs. server-pre-
   rendered SVG files. The latter is what you currently have working and is
   inspectable in the filesystem, so there's a case for it. Tradeoff: 3N files
   per tree vs. one JSON.
4. **Animation in v1.** I'd say no — get the geometry right first, layer FLIP
   transitions on top later.
5. **Tree size cap.** What's a reasonable upper bound? 10k? 50k? Affects
   whether we need an async upload pipeline at all.

## Backlog (added since the original sketch)

Done since this plan was written: NEXUS reading (sniffed vs Newick), TSV-driven
internal-node labelling (`label_lineage.php`), and a **colour layer** — Tree
Colors (Tennekes & de Jonge 2014) computed over the *taxonomy* implied by the
internal labels and emitted as a per-node `color` in the JSON, tinting markers
and labels in the viewer (`src/php/tree-colors.php`, `glyph.js`).

To do, roughly in priority order:

- **Colour options (UI + build params).** The prototype hard-codes one scheme.
  Want user-selectable:
  - *Taxonomic level / hue cap* — currently `compute_node_colors($t, 2)`
    (2 = family). Should be a per-tree choice; the right cap differs by tree
    (a barcoding tree wants genus/species hues; a broad tree wants family).
  - *Colour scheme* — light vs dark background tuning (dark mode looks best),
    additive vs subtractive depth, hue range / rotation, and at least one
    CVD-safe alternative (Tree Colors are not colour-blind-safe — the paper
    says so; the brackets gutter is the fallback cue for those users).
  - *Tint scope* — markers+labels (current) vs also branch lines (full wash).
  - *Backbone colour* — a single-order tree (e.g. Trichoptera) gives one
    light-hued backbone; offer grey-above-cap instead.
  These split into build-time params (baked into the JSON) and viewer-time
  toggles (recompute in JS). Decide which belong where.
- **Static brackets gutter** (see `brackets-design.md`) — the other "where am
  I" cue; should share the clade colours.
- **Colour by a second variable** — the paper notes the colour channel can
  carry a non-taxonomic attribute (support, a trait) instead of/over the
  classification. Possible later mode.

## What this plan does *not* yet sketch

- Search-for-a-taxon / scroll-to-found-node. Easy to add — same `absoluteY`
  arithmetic, anchor = found id.
- Sharing a specific *view* (zoom + scroll position) via URL hash. Easy.
- Embedding the viewer in a host page (iframe? web component?). Defer.
- Server-side label search across all uploaded trees. SQLite when we get
  there.
- Authentication / private trees. Not needed for v1.
