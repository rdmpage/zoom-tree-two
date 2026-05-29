# zoom-tree-two

A Google-Maps-style zoomable viewer for large binary phylogenies, rebuilt
clean from `~/Sites/zoom-tree` (PHP server-side glyphs) and `~/Sites/tile-o`
(client-side viewer experiments).

`PLAN.md` is the design spec — read it first.

## Layout

```
build.php             # CLI: Newick → trees/<id>.json
viewer.html           # the viewer (defaults to ?id=62057be74ac6 = gekkota)
src/php/              # lifted tree code (Newick parser, iterators, ordering, …)
src/js/viewer.js      # PageViewer class — paging, scroll, zoom orchestration
src/js/glyph.js       # SVG fragment builder (one row at a time)
test-data/*.nwk|tre   # sample Newick trees
trees/<id>.json       # generated, gitignored
```

## Building a tree

```
php build.php test-data/gekkota.tre
# wrote trees/62057be74ac6.json  (n=1319, zoom_levels=16)
```

Tree id = first 12 hex chars of `sha256(newick)`. Identical inputs reuse the
same file.

## Running tests

```
php test.php   # exit 0 = green
```

Builds the 8-leaf tree in-memory via `build_v1_json()` and asserts structural
invariants (array lengths, root parent/x/style/first_zoom, inorder is a true
permutation, binary structure with parent↔child consistency, style by side,
no orphan internals, x/max_x in `[0, tree_width]`, well-formed crossings).
Plain PHP, no PHPUnit.

## Viewing

Served by Apache at `http://localhost/zoom-tree-two/viewer.html?id=<id>`.
Don't run `php -S` — Apache already covers `~/Sites/`.

Controls:

| Action                  | Effect                                |
|-------------------------|---------------------------------------|
| `+` / `-`               | Zoom in/out, anchored on viewport-centre row |
| Buttons (top-right)     | Same                                   |
| double-click a node     | Zoom in, anchored on that node         |
| shift+double-click      | Zoom out, anchored on that node        |

Anchor-stable zoom: heights come from the JSON, so we compute the anchor row's
new screen position arithmetically — no `getBoundingClientRect` on the zoom
path. (That was the bug in `tile-o/step-8-fork.html`.)

## Status

**Working:**
- Newick → parallel-array JSON (`labels`, `parent`, `x`, `max_x`,
  `first_zoom`, `style`, `inorder`, `child_l`, `child_r`, `crossings`).
- Verified on 8-leaf, gekkota (1319 nodes), reptile (8323 nodes).
- Viewer: paged scroll, `IntersectionObserver` fill/empty, SVG glyphs
  (leaf / open / closed-triangle) with parent stubs, half-bars by `style`,
  and crossing verticals.
- Anchor-stable `+`/`-` zoom. Centring padding so small trees sit in the
  viewport middle and scroll range > 0 at every zoom.
- Zoom growth factor √2 (every two levels doubles visible count) — felt
  smoother than the original ×2.

**Known limitations / deferred:**
- Server-side priority queue is still the `usort` fake from zoom-tree —
  fine to ~10 k nodes (gekkota ≈ 60 ms, reptile ≈ 1 s); above that, port
  the Fibonacci heap from `tile-o/heap.html`.
- No upload form yet (`index.html` + `upload.php` from PLAN.md repo layout
  still to do).
- No URL-shareable view (zoom + scroll position).
- No LRU cache for evicted pages — `IntersectionObserver` blanks them on exit.
- Window resize doesn't repaginate (padding stale until next zoom).
- No animation / FLIP transitions between zoom levels.
- Zoom is intentionally **discrete** integer levels — trackpad pinch was tried
  and removed because continuous gestures fight the step function.
- Internal-label heuristics (McDonald, kraken, MRCA hand-tuning) from
  `zoom-tree/rows.php` are out of scope for v1.

## Bugs squashed along the way

- `tree-parse.php` line 454 stored branch lengths via
  `number_format($n, 5, '', '')`, turning `:1` into `"100000"`. Replaced both
  edge-length-storing sites with `(float)`.
- `get_node_heights` left the root with a non-zero x when the Newick had a
  leading branch length; pinned `x[root] = 0` in `build.php` after the call.

## Next milestones (likely order)

1. Decent landing-position for big trees opened at a deep zoom.
2. URL-encoded `?id=…&z=…&center=<nodeId>` for shareable views.
3. Upload form + `upload.php` so non-CLI users can paste a Newick.
4. Real Fibonacci heap, retire the `usort` fake.
