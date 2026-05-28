// PageViewer — paged scroll over a zoom-tree JSON.
//
// Anchor-stable +/-: pick an anchor node id (viewport-centre row, or a clicked
// node), record its absoluteY at the old zoom, rebuild for the new zoom,
// compute its new absoluteY, then set scrollTop so the anchor stays in the
// same screen position.  All heights come from the JSON — we never measure
// laid-out DOM on the zoom path.

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
	this.computeVisible();
	this.buildPages();
	this.setInitialScroll();
	this.observe();
	this.fillVisibleNow();
	this.attachHandlers();
	this.onChange(this);
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

