// PageViewer — paged scroll over a zoom-tree JSON.
//
// Anchor-stable +/-: pick an anchor node id (viewport-centre row, or a clicked
// node), record its absoluteY at the old zoom, rebuild for the new zoom,
// compute its new absoluteY, then set scrollTop so the anchor stays in the
// same screen position.  All heights come from the JSON — we never measure
// laid-out DOM on the zoom path.

// --- Clade-block prototype (brackets-design.md, coloured) -------------------
// A translucent region behind every "frontier" named clade (visible, with no
// visible named descendant), carrying one sticky-centred name.  Keeps a clade
// legible as it fragments into unnamed sub-triangles under zoom.  Toggle with
// the 'b' key.  Pure client-side: no JSON change.
var BLOCKS_ENABLED = true;

// Left padding (px) between a block's left edge and its clade's MRCA node.
var BLOCK_LEFT_PAD = 6;

// Light categorical palette (tab20-ish), cycled along the frontier so adjacent
// blocks differ.  Block fill is this at low alpha; the name picks up a darker
// ink so it reads over the tint.
var CLADE_PALETTE = [
	'#aec7e8', '#ffbb78', '#98df8a', '#ff9896', '#c5b0d5',
	'#c49c94', '#f7b6d2', '#dbdb8d', '#9edae5', '#c7c7c7'
];

function hexToRgba(hex, a)
{
	var r = parseInt(hex.substr(1, 2), 16);
	var g = parseInt(hex.substr(3, 2), 16);
	var b = parseInt(hex.substr(5, 2), 16);
	return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
}

// Strip the rank prefix (g__/s__/f__/o__…) for display.
function displayCladeName(lab)
{
	return lab ? lab.replace(/^[a-z]+__/, '') : '';
}

// First index i with arr[i] >= x  /  first index i with arr[i] > x.
function lowerBound(arr, x)
{
	var lo = 0, hi = arr.length;
	while (lo < hi) { var m = (lo + hi) >>> 1; if (arr[m] < x) { lo = m + 1; } else { hi = m; } }
	return lo;
}
function upperBound(arr, x)
{
	var lo = 0, hi = arr.length;
	while (lo < hi) { var m = (lo + hi) >>> 1; if (arr[m] <= x) { lo = m + 1; } else { hi = m; } }
	return lo;
}

function PageViewer(opts)
{
	this.tree     = opts.tree;
	this.viewerEl = opts.viewer;
	this.pagesEl  = opts.pages;
	this.zoom     = opts.zoom;
	this.onChange = opts.onChange || function () {};

	this.rowsPerPage = 10;
}

PageViewer.prototype.render = function ()
{
	this.precomputeClades();
	this.computeVisible();
	this.buildPages();
	this.setInitialScroll();
	this.observe();
	this.fillVisibleNow();
	this.attachHandlers();
	this.onChange(this);
};

// One-time: inorder position per node, the inorder interval each subtree spans
// (for block extent), a preorder DFS order (for the frontier fold), and the
// list of named node ids.  Uses only child pointers + inorder + labels.
PageViewer.prototype.precomputeClades = function ()
{
	var tree = this.tree, n = tree.n;

	var pos = new Array(n);                         // node id -> index in inorder
	for (var k = 0; k < n; k++) { pos[tree.inorder[k]] = k; }

	// Preorder DFS from root: parent always recorded before its children.
	var order = new Array(n);
	var oi = 0, stack = [0];
	while (stack.length)
	{
		var id = stack.pop();
		order[oi++] = id;
		var cl = tree.child_l[id], cr = tree.child_r[id];
		if (cr !== -1) { stack.push(cr); }
		if (cl !== -1) { stack.push(cl); }
	}

	// Subtree inorder interval [lo, hi], folded children-before-parent (reverse
	// preorder).  A monophyletic clade's leaves are contiguous in inorder, so
	// [lo, hi] is exactly the clade.
	var lo = new Array(n), hi = new Array(n);
	for (var i = n - 1; i >= 0; i--)
	{
		var nid = order[i];
		var a = tree.child_l[nid], b = tree.child_r[nid];
		if (a === -1)
		{
			lo[nid] = hi[nid] = pos[nid];
		}
		else
		{
			lo[nid] = Math.min(pos[nid], lo[a], lo[b]);
			hi[nid] = Math.max(pos[nid], hi[a], hi[b]);
		}
	}

	// A node anchors a block iff it's an INTERNAL node carrying a real taxon
	// label (not a leaf, not a leftover numeric support value).
	var namedFlag = new Uint8Array(n);
	var named = [];
	for (var d = 0; d < n; d++)
	{
		if (tree.child_l[d] !== -1 && isCladeName(tree.labels[d]))
		{
			namedFlag[d] = 1;
			named.push(d);
		}
	}

	this.cladePos   = pos;
	this.cladeLo    = lo;
	this.cladeHi    = hi;
	this.dfsOrder   = order;
	this.namedIds   = named;
	this.namedFlag  = namedFlag;
};

// Frontier at the current zoom: named nodes that are visible and have no
// visible named descendant (brackets-design.md §"frontier rule").  O(N) fold.
PageViewer.prototype.computeFrontier = function ()
{
	var tree = this.tree, z = this.zoom, n = tree.n;
	var order = this.dfsOrder;

	var nf = this.namedFlag;
	var hasNamedDesc = new Uint8Array(n);
	for (var i = n - 1; i >= 0; i--)
	{
		var id = order[i];
		var cl = tree.child_l[id], cr = tree.child_r[id];
		if (cl === -1) { continue; }
		var h = 0;
		if ((nf[cl] && tree.first_zoom[cl] <= z) || hasNamedDesc[cl]) { h = 1; }
		if ((nf[cr] && tree.first_zoom[cr] <= z) || hasNamedDesc[cr]) { h = 1; }
		hasNamedDesc[id] = h;
	}

	var front = [], named = this.namedIds;
	for (var j = 0; j < named.length; j++)
	{
		var nid = named[j];
		if (tree.first_zoom[nid] <= z && !hasNamedDesc[nid]) { front.push(nid); }
	}
	return front;
};

// Build the background block layer for the current zoom.  Inserted as the FIRST
// child of #pages so it paints behind the row glyphs; recomputed every
// buildPages.  Block extent comes from cumY (arithmetic, no DOM measurement).
PageViewer.prototype.buildBlocks = function ()
{
	if (!BLOCKS_ENABLED) { return; }

	var frontier = this.computeFrontier();
	var lo = this.cladeLo, hi = this.cladeHi, pos = this.cladePos;

	// inorder position of each visible row, in row order (== inorder order).
	var vis = this.visibleIds;
	var visPos = new Array(vis.length);
	for (var i = 0; i < vis.length; i++) { visPos[i] = pos[vis[i]]; }

	// Deterministic order (top-to-bottom) for stable iteration.
	frontier.sort(function (p, q) { return lo[p] - lo[q]; });

	// Same x->pixel mapping glyph.js uses, so the block's left edge lines up with
	// the clade's MRCA node (with a little padding) — leaving the backbone bare.
	var leftGap = this.tree.config.row_height_open;
	var scale   = GLYPH_TREE_PX / this.tree.config.tree_width;

	var html = [], blocks = [];
	for (var f = 0; f < frontier.length; f++)
	{
		var id = frontier[f];
		var a = lowerBound(visPos, lo[id]);
		var b = upperBound(visPos, hi[id]) - 1;
		if (b < a) { continue; }   // no visible rows (shouldn't happen for a frontier node)
		// NB single-row blocks are KEPT: a collapsed named clade (closed triangle)
		// carries its block + name too, so colour/label are continuous from
		// collapsed through to fully open — the block just grows on zoom.

		var top = this.paddingTop + this.cumY[a];
		var bot = this.paddingTop + this.cumY[b + 1];
		var left = Math.max(0, leftGap + this.tree.x[id] * scale - BLOCK_LEFT_PAD);
		// Colour the block by the clade's own Tree-Colors hue (the SAME per-node
		// value its collapsed triangle uses), so triangle and block match and the
		// colour is stable across zoom — it's a fixed property of the frontier
		// node, not of where it lands in the current frontier.  Fall back to a
		// per-node palette index (still zoom-stable) when the tree has no colour.
		var col = (this.tree.color && this.tree.color[id]) ||
		          CLADE_PALETTE[id % CLADE_PALETTE.length];
		var disp = displayCladeName(this.tree.labels[id]);

		html.push(
			'<div class="clade-block" style="top:' + top + 'px;height:' + (bot - top) +
			'px;left:' + left + 'px;background:' + hexToRgba(col, 0.33) + '">' +
			'<div class="clade-name">' + escapeXml(disp) + '</div></div>');
		blocks.push({ top: top, bot: bot });
	}

	var layer = document.createElement('div');
	layer.className = 'block-layer';
	layer.innerHTML = html.join('');
	this.pagesEl.insertBefore(layer, this.pagesEl.firstChild);

	var nameEls = layer.querySelectorAll('.clade-name');
	for (var m = 0; m < blocks.length; m++) { blocks[m].nameEl = nameEls[m]; }

	this.cladeBlocks = blocks;
	this.positionCladeNames();
};

// Sticky-centre each block's name in the visible slice of its block, so the
// clade label is always on screen while scrolling inside a tall clade.
PageViewer.prototype.positionCladeNames = function ()
{
	if (!this.cladeBlocks) { return; }
	var vTop = this.viewerEl.scrollTop;
	var vBot = vTop + this.viewerEl.clientHeight;
	var pad = 12;

	for (var i = 0; i < this.cladeBlocks.length; i++)
	{
		var bk = this.cladeBlocks[i];
		if (!bk.nameEl) { continue; }
		var visTop = Math.max(bk.top, vTop + pad);
		var visBot = Math.min(bk.bot, vBot - pad);
		var center = (visBot > visTop) ? (visTop + visBot) / 2 : (bk.top + bk.bot) / 2;
		bk.nameEl.style.top = (center - bk.top) + 'px';   // relative to clade-block
	}
};

// First view: centre small trees, pin big trees just below the top buffer.
PageViewer.prototype.setInitialScroll = function ()
{
	var vp = this.viewerEl.clientHeight;
	var th = this.treeHeight;
	if (th < vp)
	{
		this.viewerEl.scrollTop = this.paddingTop + th / 2 - vp / 2;
	}
	else
	{
		this.viewerEl.scrollTop = this.paddingTop - 20;
	}
};

// Build the ordered list of node ids visible at this.zoom (in inorder),
// plus per-row kind (leaf / open / closed) and height.
PageViewer.prototype.computeVisible = function ()
{
	var tree = this.tree;
	var z    = this.zoom;

	var ids = [];
	for (var k = 0; k < tree.n; k++)
	{
		var id = tree.inorder[k];
		if (tree.first_zoom[id] <= z)
		{
			ids.push(id);
		}
	}

	var kinds   = new Array(ids.length);
	var heights = new Array(ids.length);
	var hOpen   = tree.config.row_height_open;
	var hClosed = tree.config.row_height_closed;

	for (var i = 0; i < ids.length; i++)
	{
		var nid = ids[i];
		var cl  = tree.child_l[nid];
		var cr  = tree.child_r[nid];
		var kind;

		if (cl === -1)
		{
			kind = 'leaf';
		}
		else
		{
			var openLeft  = (cl !== -1 && tree.first_zoom[cl] <= z);
			var openRight = (cr !== -1 && tree.first_zoom[cr] <= z);
			kind = (openLeft || openRight) ? 'open' : 'closed';
		}

		kinds[i]   = kind;
		heights[i] = (kind === 'closed') ? hClosed : hOpen;
	}

	this.visibleIds = ids;
	this.rowKinds   = kinds;
	this.rowHeights = heights;

	// id -> row index, for later anchor lookups
	var idToRow = Object.create(null);
	for (var j = 0; j < ids.length; j++)
	{
		idToRow[ids[j]] = j;
	}
	this.idToRow = idToRow;
};

// Bucket rows into pages, compute per-page height, append empty .page divs.
// Heights are summed arithmetically — never measured.
PageViewer.prototype.buildPages = function ()
{
	var n   = this.visibleIds.length;
	var per = this.rowsPerPage;
	var numPages = Math.ceil(n / per);

	this.pagesEl.innerHTML = '';

	var pages = new Array(numPages);
	var y = 0;

	for (var p = 0; p < numPages; p++)
	{
		var start = p * per;
		var end   = Math.min(start + per, n);

		var h = 0;
		for (var i = start; i < end; i++)
		{
			h += this.rowHeights[i];
		}

		var div = document.createElement('div');
		div.className = 'page';
		div.style.height = h + 'px';
		div.dataset.page = p;
		this.pagesEl.appendChild(div);

		pages[p] = { start: start, end: end, top: y, height: h, el: div, filled: false };
		y += h;
	}

	// Cumulative row Y — cumY[k] = sum of rowHeights[0..k-1].  Used for both
	// absoluteY(id) and the binary search in rowAtY().
	var cumY = new Array(n + 1);
	cumY[0] = 0;
	for (var i = 0; i < n; i++)
	{
		cumY[i + 1] = cumY[i] + this.rowHeights[i];
	}

	// Padding = half viewport, top and bottom, INDEPENDENT of tree height.
	// This guarantees scroll range >= treeHeight even when the tree is smaller
	// than the viewport — without it, scrollTop clamps to 0 and the anchor
	// algorithm can't translate the tree at all on zoom.  Initial scrollTop
	// (set in setInitialScroll) decides whether the small tree appears centred
	// or top-aligned.
	var vp = this.viewerEl.clientHeight;
	var pad = vp / 2;

	this.pages         = pages;
	this.cumY          = cumY;
	this.treeHeight    = y;
	this.paddingTop    = pad;
	this.paddingBottom = pad;
	this.totalHeight   = pad + y + pad;

	this.pagesEl.style.paddingTop    = pad + 'px';
	this.pagesEl.style.paddingBottom = pad + 'px';
	this.pagesEl.style.height        = y + 'px';

	this.buildBlocks();
};

// Screen-y (in scrollable coords) of the midpoint of the row holding `id`.
PageViewer.prototype.absoluteY = function (id)
{
	var k = this.idToRow[id];
	if (k === undefined) { return -1; }
	return this.paddingTop + this.cumY[k] + this.rowHeights[k] / 2;
};

// Largest row index k such that paddingTop + cumY[k] <= y.  Binary search.
// Clamps to the visible range if y falls in the padding above or below.
PageViewer.prototype.rowAtY = function (y)
{
	var yTree = y - this.paddingTop;
	if (yTree <= 0) { return 0; }
	if (yTree >= this.treeHeight) { return this.visibleIds.length - 1; }

	var cumY = this.cumY;
	var lo = 0;
	var hi = cumY.length - 1;
	while (lo < hi)
	{
		var m = (lo + hi + 1) >>> 1;
		if (cumY[m] <= yTree) { lo = m; }
		else                  { hi = m - 1; }
	}
	if (lo >= this.visibleIds.length) { lo = this.visibleIds.length - 1; }
	return lo;
};

PageViewer.prototype.pickCenterAnchor = function ()
{
	var midY   = this.viewerEl.scrollTop + this.viewerEl.clientHeight / 2;
	var rowIdx = this.rowAtY(midY);
	return this.visibleIds[rowIdx];
};

// If `id` is collapsed at zoom `z`, walk parents until we find a visible one.
PageViewer.prototype.findVisibleAncestor = function (id, z)
{
	var tree = this.tree;
	while (id !== -1 && tree.first_zoom[id] > z)
	{
		id = tree.parent[id];
	}
	return id;
};

PageViewer.prototype.setZoom = function (zNew, anchorId)
{
	var tree = this.tree;

	if (zNew < tree.config.min_zoom) { zNew = tree.config.min_zoom; }
	if (zNew > tree.config.max_zoom) { zNew = tree.config.max_zoom; }
	if (zNew === this.zoom)          { return; }

	if (anchorId === undefined || anchorId === null)
	{
		anchorId = this.pickCenterAnchor();
	}
	anchorId = this.findVisibleAncestor(anchorId, zNew);
	if (anchorId === -1) { return; }   // shouldn't happen — root is always at z=1

	// Step 1-2: capture anchor's current screen position
	var oldY       = this.absoluteY(anchorId);
	var oldScreenY = oldY - this.viewerEl.scrollTop;

	// Step 3: tear down observer + rebuild pages for new zoom
	if (this.io) { this.io.disconnect(); }
	this.zoom = zNew;
	this.computeVisible();
	this.buildPages();
	this.observe();

	// Step 4-5: arithmetic newY, set scrollTop so anchor stays put
	var newY = this.absoluteY(anchorId);
	this.viewerEl.scrollTop = newY - oldScreenY;

	// Fill anything in the new viewport synchronously so there's no blank flash
	// before the IntersectionObserver's first asynchronous callback.
	this.fillVisibleNow();

	this.onChange(this);
};

// Pixel height of the tree at zoom z — sum of row heights for the visible
// set.  Used by pickInitialZoom() to land on an overview that actually fits.
// Monotonically non-decreasing in z: when a closed internal becomes open it
// saves 12 px (24 → 12), but each new visible node adds ≥ 12 px, so the
// total never goes down.
PageViewer.prototype.treeHeightAt = function (z)
{
	var tree    = this.tree;
	var hOpen   = tree.config.row_height_open;
	var hClosed = tree.config.row_height_closed;
	var n       = tree.n;
	var total   = 0;

	for (var i = 0; i < n; i++)
	{
		if (tree.first_zoom[i] > z) { continue; }

		var cl = tree.child_l[i];
		if (cl === -1)
		{
			total += hOpen;                              // leaf
		}
		else if (tree.first_zoom[cl] <= z)
		{
			total += hOpen;                              // open internal
		}
		else
		{
			total += hClosed;                            // closed triangle
		}
	}
	return total;
};

// Largest zoom level whose tree height fits inside the viewer (with a small
// buffer).  Walks upward from min_zoom — treeHeightAt is monotonic, so we
// stop as soon as a level overflows.  Falls back to min_zoom if even the
// overview overflows (i.e. tiny viewports).
PageViewer.prototype.pickInitialZoom = function ()
{
	var tree   = this.tree;
	var budget = this.viewerEl.clientHeight - 40;
	if (budget <= 0) { return tree.config.min_zoom; }

	var best = tree.config.min_zoom;
	for (var z = tree.config.min_zoom; z <= tree.config.max_zoom; z++)
	{
		if (this.treeHeightAt(z) <= budget) { best = z; }
		else                                { break; }
	}
	return best;
};

// First node id whose label contains `query` (case-insensitive substring).
// Returns -1 on no match.  O(N) — fine for trees up to ~100 k nodes.
PageViewer.prototype.searchByLabel = function (query)
{
	var q = String(query || '').toLowerCase();
	if (!q) { return -1; }
	var labels = this.tree.labels;
	for (var i = 0; i < labels.length; i++)
	{
		var lab = labels[i];
		if (lab && lab.toLowerCase().indexOf(q) !== -1)
		{
			return i;
		}
	}
	return -1;
};

// Make `id` visible (bumping zoom up to first_zoom[id] if needed) and centre
// it in the viewport.  Briefly highlights the row.
PageViewer.prototype.scrollToNode = function (id)
{
	var needed = this.tree.first_zoom[id];

	if (needed > this.zoom)
	{
		if (this.io) { this.io.disconnect(); }
		this.zoom = needed;
		this.computeVisible();
		this.buildPages();
		this.observe();
		this.onChange(this);
	}

	var y = this.absoluteY(id);
	this.viewerEl.scrollTop = y - this.viewerEl.clientHeight / 2;
	this.fillVisibleNow();

	var self = this;
	requestAnimationFrame(function ()
	{
		var row = self.pagesEl.querySelector('.row[data-id="' + id + '"]');
		if (!row) { return; }
		row.classList.add('highlight');
		// The CSS transition handles the fade-out; we just need to drop the
		// class so the colour returns to its default.
		setTimeout(function () { row.classList.remove('highlight'); }, 30);
	});
};

PageViewer.prototype.fillVisibleNow = function ()
{
	var pad = this.paddingTop;
	var top = this.viewerEl.scrollTop;
	var bot = top + this.viewerEl.clientHeight;
	for (var p = 0; p < this.pages.length; p++)
	{
		var info  = this.pages[p];
		var pTop  = pad + info.top;
		var pBot  = pTop + info.height;
		if (pBot >= top && pTop <= bot)
		{
			this.fillPage(p);
		}
	}
	this.positionCladeNames();
};

PageViewer.prototype.fillPage = function (p)
{
	var info = this.pages[p];
	if (!info || info.filled) { return; }

	var tree  = this.tree;
	var parts = new Array(info.end - info.start);

	for (var i = info.start; i < info.end; i++)
	{
		var id   = this.visibleIds[i];
		var kind = this.rowKinds[i];
		var h    = this.rowHeights[i];

		parts[i - info.start] =
			'<div class="row ' + kind + '" data-id="' + id + '" style="height:' + h + 'px">' +
			buildGlyph(tree, id, kind, h) +
			'</div>';
	}

	info.el.innerHTML = parts.join('');
	info.filled = true;
};

PageViewer.prototype.emptyPage = function (p)
{
	var info = this.pages[p];
	if (!info || !info.filled) { return; }
	info.el.innerHTML = '';
	info.filled = false;
};

PageViewer.prototype.observe = function ()
{
	var self = this;

	this.io = new IntersectionObserver(function (entries)
	{
		for (var e = 0; e < entries.length; e++)
		{
			var ent = entries[e];
			var p   = parseInt(ent.target.dataset.page, 10);
			if (ent.isIntersecting) { self.fillPage(p); }
			else                    { self.emptyPage(p); }
		}
	}, { root: this.viewerEl, rootMargin: '200px 0px' });

	for (var p = 0; p < this.pages.length; p++)
	{
		this.io.observe(this.pages[p].el);
	}
};

PageViewer.prototype.attachHandlers = function ()
{
	var self = this;

	// Keep clade names sticky-centred as the user scrolls (rAF-throttled).
	var ticking = false;
	this.viewerEl.addEventListener('scroll', function ()
	{
		if (ticking) { return; }
		ticking = true;
		requestAnimationFrame(function () { self.positionCladeNames(); ticking = false; });
	});

	window.addEventListener('keydown', function (e)
	{
		var tag = (e.target && e.target.tagName) || '';
		if (tag === 'INPUT' || tag === 'TEXTAREA') { return; }

		if (e.key === '+' || e.key === '=')
		{
			self.setZoom(self.zoom + 1);
			e.preventDefault();
		}
		else if (e.key === '-' || e.key === '_')
		{
			self.setZoom(self.zoom - 1);
			e.preventDefault();
		}
		else if (e.key === 'b' || e.key === 'B')
		{
			// Toggle the block layer for before/after comparison.
			self.pagesEl.classList.toggle('blocks-hidden');
			e.preventDefault();
		}
	});

	// dblclick = zoom in;  shift+dblclick = zoom out;  both anchor on the clicked row.
	this.viewerEl.addEventListener('dblclick', function (e)
	{
		var row = e.target.closest && e.target.closest('.row');
		if (!row) { return; }
		var id = parseInt(row.dataset.id, 10);
		if (isNaN(id)) { return; }
		var step = e.shiftKey ? -1 : +1;
		self.setZoom(self.zoom + step, id);
	});
};

