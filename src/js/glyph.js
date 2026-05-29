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

function buildGlyph(tree, nodeId, kind, height)
{
	var label  = tree.labels[nodeId];
	var parent = tree.parent[nodeId];
	var style  = tree.style[nodeId];
	var mid    = height / 2;

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
		parts.push('<circle class="g-dot" cx="' + x + '" cy="' + mid + '" r="2.5"/>');
		parts.push('<text class="g-label" x="' + (x + 5) + '" y="' + mid + '">' + escapeXml(label || ('#' + nodeId)) + '</text>');
	}
	else if (kind === 'open')
	{
		// Full vertical bar at own x — continues this node's bar through its own row.
		parts.push('<path d="M ' + x + ' 0 ' + x + ' ' + height + '"/>');
		parts.push('<circle class="g-circle" cx="' + x + '" cy="' + mid + '" r="3"/>');

		var support = composeSupport(tree, nodeId);
		var labelX  = x + 6;
		if (support !== '')
		{
			parts.push('<text class="g-support" x="' + (x + 8) + '" y="' + mid + '">' + escapeXml(support) + '</text>');
			labelX = x + 8 + support.length * 5.5 + 4;
		}
		if (label)
		{
			parts.push('<text class="g-internal" x="' + labelX + '" y="' + mid + '">' + escapeXml(label) + '</text>');
		}
	}
	else if (kind === 'closed')
	{
		parts.push('<polygon class="g-tri" points="' + x + ',' + mid + ' ' + maxX + ',0 ' + maxX + ',' + height + '"/>');

		var support2 = composeSupport(tree, nodeId);
		var labelX2  = maxX + 6;
		if (support2 !== '')
		{
			parts.push('<text class="g-support" x="' + (maxX + 6) + '" y="' + mid + '">' + escapeXml(support2) + '</text>');
			labelX2 = maxX + 6 + support2.length * 5.5 + 4;
		}
		if (label)
		{
			parts.push('<text class="g-closed" x="' + labelX2 + '" y="' + mid + '">' + escapeXml(label) + '</text>');
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

function escapeXml(s)
{
	return String(s)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;');
}
