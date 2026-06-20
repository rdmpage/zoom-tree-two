// glyph.js — build one SVG fragment per row of the viewer.
//
// Three kinds (PLAN.md §Glyph rendering):
//   leaf    → horizontal stub + dot + label
//   open    → horizontal stub + circle + label + full vertical bar at own x
//   closed  → horizontal stub + triangle from (x, mid) to (max_x, 0..h) + label
//
// Plus, in every row:
//   - half-bar at parent's x (top half if style=0, bottom half if style=2,
//     none if style=1 = root)
//   - 1px vertical at each ancestor's x in crossings[id]
//
// JSON x values live in [0, tree.config.tree_width].  We scale into a fixed
// pixel area at render time — tweak the constants below to resize.

var GLYPH_TREE_PX  = 700;   // pixels for the tree drawing
var GLYPH_LABEL_PX = 250;   // pixels reserved to the right for labels

// A label is a clade NAME (vs a leftover numeric support value that the source
// Newick carried on internal nodes and label_lineage didn't overwrite).  Used
// to keep support strings out of the clade-block gutter and frontier.
function isCladeName(lab)
{
	return !!lab && !/^[\d.]+$/.test(lab);
}

// When the clade-block layer is on, the block gutter is the single source of
// truth for internal clade names — don't also draw them in-tree.
function blocksOn()
{
	return typeof BLOCKS_ENABLED !== 'undefined' && BLOCKS_ENABLED;
}

function buildGlyph(tree, nodeId, kind, height)
{
	var label  = tree.labels[nodeId];
	var parent = tree.parent[nodeId];
	var style  = tree.style[nodeId];
	var mid    = height / 2;

	// Tree Colors tint for this node's markers (and a darker variant for its
	// label text).  Empty string = no classification → fall back to CSS ink.
	var color  = (tree.color && tree.color[nodeId]) || '';
	var inkCol = color ? darkenHex(color, 0.65) : '';

	// Left margin = one row-height, so vertical lines off the root are visible.
	var leftGap = tree.config.row_height_open;
	var scale   = GLYPH_TREE_PX / tree.config.tree_width;
	var sx = function (xv) { return leftGap + xv * scale; };

	var x    = sx(tree.x[nodeId]);
	var maxX = sx(tree.max_x[nodeId]);

	var parts = [];

	// Crossings — vertical bars from ancestors whose bar passes through this row
	var crossings = tree.crossings[nodeId];
	for (var c = 0; c < crossings.length; c++)
	{
		var cx = sx(tree.x[crossings[c]]);
		parts.push('<path d="M ' + cx + ' 0 ' + cx + ' ' + height + '"/>');
	}

	// Horizontal stub from parent's x → node's x, at row midpoint
	// + half-bar at parent's x
	if (parent !== -1)
	{
		var px = sx(tree.x[parent]);
		parts.push('<path d="M ' + px + ' ' + mid + ' ' + x + ' ' + mid + '"/>');

		if (style === 0)
		{
			parts.push('<path d="M ' + px + ' ' + mid + ' ' + px + ' ' + height + '"/>');
		}
		else if (style === 2)
		{
			parts.push('<path d="M ' + px + ' 0 ' + px + ' ' + mid + '"/>');
		}
	}

	// The node itself
	if (kind === 'leaf')
	{
		// Use inline style (not a presentation attribute) so it overrides the
		// class-based CSS fills in viewer.html.
		var dotFill = color ? ' style="fill:' + color + '"' : '';
		var labFill = inkCol ? ' style="fill:' + inkCol + '"' : '';
		parts.push('<circle class="g-dot" cx="' + x + '" cy="' + mid + '" r="2.5"' + dotFill + '/>');
		parts.push('<text class="g-label" x="' + (x + 5) + '" y="' + mid + '"' + labFill + '>' + escapeXml(label || ('#' + nodeId)) + '</text>');
	}
	else if (kind === 'open')
	{
		// Full vertical bar at own x — continues this node's bar through its own row.
		parts.push('<path d="M ' + x + ' 0 ' + x + ' ' + height + '"/>');
		var circStroke = color ? ' style="stroke:' + color + '"' : '';
		parts.push('<circle class="g-circle" cx="' + x + '" cy="' + mid + '" r="3"' + circStroke + '/>');

		var support = composeSupport(tree, nodeId);
		var labelX  = x + 6;
		if (support !== '')
		{
			parts.push('<text class="g-support" x="' + (x + 8) + '" y="' + mid + '">' + escapeXml(support) + '</text>');
			labelX = x + 8 + support.length * 5.5 + 4;
		}
		if (label && !(blocksOn() && isCladeName(label)))
		{
			var intFill = inkCol ? ' style="fill:' + inkCol + '"' : '';
			parts.push('<text class="g-internal" x="' + labelX + '" y="' + mid + '"' + intFill + '>' + escapeXml(label) + '</text>');
		}
	}
	else if (kind === 'closed')
	{
		var triFill = color ? ' style="fill:' + color + ';stroke:' + inkCol + '"' : '';
		parts.push('<polygon class="g-tri" points="' + x + ',' + mid + ' ' + maxX + ',0 ' + maxX + ',' + height + '"' + triFill + '/>');

		var support2 = composeSupport(tree, nodeId);
		var labelX2  = maxX + 6;
		if (support2 !== '')
		{
			parts.push('<text class="g-support" x="' + (maxX + 6) + '" y="' + mid + '">' + escapeXml(support2) + '</text>');
			labelX2 = maxX + 6 + support2.length * 5.5 + 4;
		}
		if (label && !(blocksOn() && isCladeName(label)))
		{
			var clFill = inkCol ? ' style="fill:' + inkCol + '"' : '';
			parts.push('<text class="g-closed" x="' + labelX2 + '" y="' + mid + '"' + clFill + '>' + escapeXml(label) + '</text>');
		}
	}

	var W = leftGap + GLYPH_TREE_PX + GLYPH_LABEL_PX;
	return '<svg width="' + W + '" height="' + height + '" xmlns="http://www.w3.org/2000/svg">' + parts.join('') + '</svg>';
}

// Format the node's support values for display.  Bootstrap and posterior may
// each be present or absent; both present become "B/P".
function composeSupport(tree, id)
{
	var b = (tree.bootstrap && tree.bootstrap[id]) || '';
	var p = (tree.posterior && tree.posterior[id]) || '';
	if (b !== '' && p !== '') { return b + '/' + p; }
	if (b !== '')             { return b; }
	if (p !== '')             { return p; }
	return '';
}

// Darken a "#rrggbb" toward black by factor f (0..1) for legible label text
// over white.  Returns '' unchanged for the empty (no-colour) case.
function darkenHex(hex, f)
{
	if (!hex || hex.charAt(0) !== '#' || hex.length !== 7) { return hex; }
	var r = Math.round(parseInt(hex.substr(1, 2), 16) * f);
	var g = Math.round(parseInt(hex.substr(3, 2), 16) * f);
	var b = Math.round(parseInt(hex.substr(5, 2), 16) * f);
	var h = function (v) { return ('0' + v.toString(16)).slice(-2); };
	return '#' + h(r) + h(g) + h(b);
}

function escapeXml(s)
{
	return String(s)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;');
}
