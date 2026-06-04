<?php

// Build the zoom-tree-two JSON for a Newick tree.
//
//   php build.php <newick-file>
//   cat tree.nwk | php build.php
//
// Output: trees/<id>.json, where id = first 12 hex chars of sha256(newick).

// Large phylogenies (tens of thousands of nodes) need more than PHP's 128M
// default — the in-memory tree plus the per-node data arrays run ~130M+ for a
// 34k-node tree.  Raise the ceiling so build "just works" on big inputs.
if (((int) (ini_get('memory_limit'))) < 1024 && ini_get('memory_limit') !== '-1')
{
	ini_set('memory_limit', '1024M');
}

require_once __DIR__ . '/src/php/node.php';
require_once __DIR__ . '/src/php/tree.php';
require_once __DIR__ . '/src/php/node_iterator.php';
require_once __DIR__ . '/src/php/inorder_iterator.php';
require_once __DIR__ . '/src/php/tree-parse.php';
require_once __DIR__ . '/src/php/read-tree.php';   // NEXUS/Newick sniffing
require_once __DIR__ . '/src/php/tree-colors.php';  // Tree Colors over the taxonomy
require_once __DIR__ . '/src/php/tree-order.php';
require_once __DIR__ . '/src/php/utils.php';
require_once __DIR__ . '/src/php/pq.php';

//-----------------------------------------------------------------------------
// I/O
//-----------------------------------------------------------------------------

function read_newick($argv)
{
	if (count($argv) >= 2)
	{
		$path = $argv[1];
		$s = read_tree_newick($path);   // sniffs NEXUS vs Newick
		if ($s === false)
		{
			fwrite(STDERR, "build.php: cannot read $path\n");
			exit(1);
		}
		if ($s === null)
		{
			fwrite(STDERR, "build.php: no tree found in $path\n");
			exit(1);
		}
		return $s;
	}
	return stream_get_contents(STDIN);
}

//-----------------------------------------------------------------------------
// Collapse unary internals: replace any internal with exactly one child by a
// direct edge from its parent (or by promoting the child to root, if the
// unary is itself the root).  The child's edge_length absorbs the unary's
// edge_length so the leaf-to-root distance is preserved.
//-----------------------------------------------------------------------------

function collapse_unaries($t)
{
	$unaries = array();
	$it = new NodeIterator($t->GetRoot());
	$q  = $it->Begin();
	while ($q != null)
	{
		if (!$q->IsLeaf() && count($q->GetChildren()) == 1)
		{
			$unaries[] = $q;
		}
		$q = $it->Next();
	}

	foreach ($unaries as $u)
	{
		collapse_unary($t, $u);
	}
}

function collapse_unary($t, $u)
{
	$child  = $u->GetChild();
	if ($child === null) { return; }
	$parent = $u->GetAncestor();

	$combined = (float) $u->GetAttribute('edge_length')
	          + (float) $child->GetAttribute('edge_length');
	$child->SetAttribute('edge_length', $combined);

	if ($parent === null)
	{
		// U is the root — promote child.
		$t->SetRoot($child);
		$child->SetAncestor(null);
		$child->SetSibling(null);
	}
	else
	{
		// Splice child into parent's child list in place of U.
		$first = $parent->GetChild();
		if ($first === $u)
		{
			$parent->SetChild($child);
		}
		else
		{
			$p = $first;
			while ($p->GetSibling() !== null && $p->GetSibling() !== $u)
			{
				$p = $p->GetSibling();
			}
			if ($p->GetSibling() === $u)
			{
				$p->SetSibling($child);
			}
		}
		$child->SetAncestor($parent);
		$child->SetSibling($u->GetSibling());
	}
}

//-----------------------------------------------------------------------------
// Renumber every reachable node 0..N-1 in preorder.  Needed after
// auto_binarise (adds nodes) + collapse_unaries (orphans nodes) so the
// parallel-array data model has a contiguous id space.  Preorder = root gets
// id 0, which the rest of the pipeline depends on.
//-----------------------------------------------------------------------------

function renumber($t)
{
	$new_map      = array();
	$next_id      = 0;
	$count_leaves = 0;

	$it = new PreorderIterator($t->GetRoot());
	$q  = $it->Begin();
	while ($q != null)
	{
		$q->id              = $next_id;
		$new_map[$next_id]  = $q;
		if ($q->IsLeaf()) { $count_leaves++; }
		$next_id++;
		$q = $it->Next();
	}

	$t->id_to_node_map = $new_map;
	$t->num_nodes      = $next_id;
	$t->num_leaves     = $count_leaves;
}

//-----------------------------------------------------------------------------
// Auto-binarise: resolve any multifurcation by inserting a right-leaning
// chain of blank-label, zero-edge-length internal nodes.  A node with k > 2
// children [c1, c2, c3, c4] becomes:
//
//     c1 ─── (new) ─── c2
//              │
//              └────── (new) ─── c3
//                         │
//                         └───── c4
//
// New internals sit at the same x as their parent (edge_length 0), so the
// extra structure is visually compact.
//-----------------------------------------------------------------------------

function auto_binarise($t)
{
	// Snapshot all multifurcating nodes first.  Iterating while we restructure
	// would be awkward — new nodes get added behind us.
	$multifurcating = array();
	$it = new NodeIterator($t->GetRoot());
	$q  = $it->Begin();
	while ($q != null)
	{
		if (count($q->GetChildren()) > 2)
		{
			$multifurcating[] = $q;
		}
		$q = $it->Next();
	}

	foreach ($multifurcating as $node)
	{
		binarise_node($t, $node);
	}
}

function binarise_node($t, $node)
{
	$children = $node->GetChildren();
	if (count($children) <= 2) { return; }

	$first = $children[0];
	$rest  = array_slice($children, 1);
	$chain = build_chain($t, $rest);

	// $node now has exactly two children: $first and the chain.
	$first->SetSibling($chain);
	$chain->SetSibling(null);
	$chain->SetAncestor($node);
}

// Wrap the given children in a binary tree of new internal nodes; return
// the root.  Each new internal has blank label and zero edge_length.
function build_chain($t, $children)
{
	$k = count($children);

	if ($k == 2)
	{
		$a = $children[0];
		$b = $children[1];
		$p = $t->NewNode('');
		$p->SetAttribute('edge_length', 0);
		$p->SetChild($a);
		$a->SetSibling($b);
		$b->SetSibling(null);
		$a->SetAncestor($p);
		$b->SetAncestor($p);
		return $p;
	}

	// k >= 3: first child + sub-chain of the rest
	$first = $children[0];
	$rest  = array_slice($children, 1);
	$sub   = build_chain($t, $rest);

	$p = $t->NewNode('');
	$p->SetAttribute('edge_length', 0);
	$p->SetChild($first);
	$first->SetSibling($sub);
	$sub->SetSibling(null);
	$first->SetAncestor($p);
	$sub->SetAncestor($p);
	return $p;
}

//-----------------------------------------------------------------------------
// Strip ordinary Newick comments: `[...]` blocks may appear anywhere
// whitespace is allowed (Newick 8:45).  PAUP nests them, so we count depth.
// Brackets inside single-quoted labels are part of the label and kept
// verbatim; doubled `''` inside a quoted label is the escape for a single
// quote and doesn't end the quoted span.
//
// `[&...]` BEAST-style metacomments are passed through unchanged — they're
// extracted by the parser's Scanner and attached to the right node.
//-----------------------------------------------------------------------------

function strip_newick_comments($newick)
{
	$out   = '';
	$depth = 0;
	$in_q  = false;
	$n     = strlen($newick);

	for ($i = 0; $i < $n; $i++)
	{
		$c = $newick[$i];

		if ($in_q)
		{
			$out .= $c;
			if ($c === "'")
			{
				if ($i + 1 < $n && $newick[$i + 1] === "'")
				{
					$out .= "'";
					$i++;
					continue;
				}
				$in_q = false;
			}
			continue;
		}

		if ($c === "'")
		{
			$in_q = true;
			$out .= $c;
			continue;
		}

		if ($c === '[' && $depth === 0)
		{
			// Top-level `[`.  If it's a `[&...]` metacomment, pass it through;
			// otherwise begin stripping (and count depth for nested cases).
			if ($i + 1 < $n && $newick[$i + 1] === '&')
			{
				$end = $i + 1;
				while ($end < $n && $newick[$end] !== ']')
				{
					$end++;
				}
				$out .= substr($newick, $i, $end - $i + 1);
				$i = $end;
				continue;
			}
			$depth++;
			continue;
		}

		if ($c === '[')
		{
			$depth++;
			continue;
		}

		if ($c === ']')
		{
			if ($depth > 0) { $depth--; }
			continue;
		}

		if ($depth === 0)
		{
			$out .= $c;
		}
	}

	return $out;
}

//-----------------------------------------------------------------------------
// Reject multifurcations.  Kept as a safety net after auto_binarise().
//-----------------------------------------------------------------------------

function require_binary($t)
{
	$it = new NodeIterator($t->GetRoot());
	$q = $it->Begin();
	while ($q != null)
	{
		$c = $q->GetChild();
		if ($c != null)
		{
			$count = 0;
			$p = $c;
			while ($p != null)
			{
				$count++;
				$p = $p->GetSibling();
			}
			if ($count != 2)
			{
				fwrite(STDERR, "build.php: tree is not binary (node " . $q->GetId() . " has $count children)\n");
				exit(1);
			}
		}
		$q = $it->Next();
	}
}

//-----------------------------------------------------------------------------
// For each internal node, the inorder range [left-most descendant, right-most]
// — keyed by the node's own inorder position.
//-----------------------------------------------------------------------------

function compute_spans($order_to_node)
{
	$n = count($order_to_node);
	$spans = array();
	for ($i = 0; $i < $n; $i++)
	{
		$q = $order_to_node[$i];
		if (!$q->IsLeaf())
		{
			$left  = $q->GetChild()->GetAttribute('order');
			$right = $q->GetChild()->GetRightMostSibling()->GetAttribute('order');
			$spans[$i] = array($left, $right);
		}
	}
	return $spans;
}

//-----------------------------------------------------------------------------
// crossings[i] = ancestors of node at inorder pos i whose vertical bar passes
// strictly through row i (i.e. excluding the immediate parent's bar endpoints,
// which the renderer derives from style[]).
//
// Returned indexed by inorder position, values = inorder positions.  We
// re-key to node ids in build_arrays().
//-----------------------------------------------------------------------------

function compute_crossings($order_to_node, $spans)
{
	$n = count($order_to_node);
	$crossings = array();
	for ($i = 0; $i < $n; $i++)
	{
		$crossings[$i] = array();
		$q = $order_to_node[$i];
		while ($q->GetAncestor())
		{
			$anc = $q->GetAncestor();
			$ao  = $anc->GetAttribute('order');
			if ($i > $spans[$ao][0] && $i < $spans[$ao][1])
			{
				$crossings[$i][] = $ao;
			}
			$q = $anc;
		}
	}
	return $crossings;
}

//-----------------------------------------------------------------------------
// Priority-queue growth: first_zoom[id] = z, where z is the smallest zoom
// level at which the node is visible.  Mirrors the loop in
// zoom-tree/read_tree.php:273-289 but records per-node z instead of per-z
// inorder lists.
//-----------------------------------------------------------------------------

function compute_first_zoom($t, $initial_size)
{
	$N = $t->num_nodes;
	$first_zoom = array_fill(0, $N, -1);
	$in_subtree = array_fill(0, $N, false);

	// Visible-node cap at zoom z is  initial_size * ZOOM_FACTOR^(z-1).
	// ZOOM_FACTOR=2 doubles each level; √2 doubles every two levels (smoother).
	$ZOOM_FACTOR = sqrt(2);

	$zoom_levels = (int) ceil(log(max($N, 1) / $initial_size, $ZOOM_FACTOR)) + 1;
	if ($zoom_levels < 1)
	{
		$zoom_levels = 1;
	}

	$root = $t->GetRoot();
	$first_zoom[$root->GetId()] = 1;
	$in_subtree[$root->GetId()] = true;
	$count = 1;

	foreach (get_children($root) as $c)
	{
		$first_zoom[$c->GetId()] = 1;
		$in_subtree[$c->GetId()] = true;
		$count++;
	}

	$queue = new PQ();
	foreach (get_children($root) as $c)
	{
		if (!$c->IsLeaf())
		{
			$queue->en_queue($c->GetId(), $c->GetLabel(), score_node($c));
		}
	}

	for ($z = 1; $z <= $zoom_levels; $z++)
	{
		$k_target = pow($ZOOM_FACTOR, $z - 1) * $initial_size;

		while ($queue->valid() && $count < $k_target)
		{
			$obj  = $queue->de_queue();
			$node = $t->id_to_node_map[$obj->id];

			foreach (get_children($node) as $child)
			{
				$cid = $child->GetId();
				if (!$in_subtree[$cid])
				{
					$first_zoom[$cid] = $z;
					$in_subtree[$cid] = true;
					$count++;
				}
				if (!$child->IsLeaf())
				{
					$queue->en_queue($cid, $child->GetLabel(), score_node($child));
				}
			}
		}
	}

	// Anything not reached (shouldn't happen for binary trees that we visited
	// fully) gets bumped to the last zoom.
	for ($i = 0; $i < $N; $i++)
	{
		if ($first_zoom[$i] == -1)
		{
			$first_zoom[$i] = $zoom_levels;
		}
	}

	return array($first_zoom, $zoom_levels);
}

//-----------------------------------------------------------------------------
// Walk the tree once and fill the per-node parallel arrays.
//-----------------------------------------------------------------------------

function build_arrays($t, $order_to_node, $crossings_by_order)
{
	$N = $t->num_nodes;

	$labels    = array_fill(0, $N, '');
	$parent    = array_fill(0, $N, -1);
	$x         = array_fill(0, $N, 0);
	$max_x     = array_fill(0, $N, 0);
	$style     = array_fill(0, $N, 1);   // 1 = root / no half-bar
	$child_l   = array_fill(0, $N, -1);
	$child_r   = array_fill(0, $N, -1);
	$bootstrap = array_fill(0, $N, '');
	$posterior = array_fill(0, $N, '');

	$it = new NodeIterator($t->GetRoot());
	$q = $it->Begin();
	while ($q != null)
	{
		$id = $q->GetId();
		$labels[$id] = $q->GetLabel();

		$xy = $q->GetAttribute('xy');
		$x[$id]     = isset($xy['x']) ? (float) $xy['x'] : 0;
		$max_x[$id] = (float) $q->GetAttribute('max_x');

		$b = $q->GetAttribute('meta_bootstrap');
		if ($b !== null && $b !== '') { $bootstrap[$id] = $b; }

		$p = $q->GetAttribute('meta_posterior');
		if ($p !== null && $p !== '') { $posterior[$id] = $p; }

		$anc = $q->GetAncestor();
		if ($anc != null)
		{
			$parent[$id] = $anc->GetId();
			$style[$id]  = ($anc->GetChild() === $q) ? 0 : 2;
		}

		$c = $q->GetChild();
		if ($c != null)
		{
			$child_l[$id] = $c->GetId();
			$r = $c->GetSibling();
			if ($r != null)
			{
				$child_r[$id] = $r->GetId();
			}
		}

		$q = $it->Next();
	}

	// inorder list (length n, keyed by inorder position, value = node id)
	$n = count($order_to_node);
	$inorder = array_fill(0, $n, -1);
	for ($k = 0; $k < $n; $k++)
	{
		$inorder[$k] = $order_to_node[$k]->GetId();
	}

	// crossings: convert (inorder pos) -> (ancestor inorder positions)
	// into (node id) -> (ancestor node ids).
	$crossings = array_fill(0, $N, array());
	for ($k = 0; $k < $n; $k++)
	{
		$node_id = $order_to_node[$k]->GetId();
		$list = array();
		foreach ($crossings_by_order[$k] as $ao)
		{
			$list[] = $order_to_node[$ao]->GetId();
		}
		$crossings[$node_id] = $list;
	}

	return array($labels, $parent, $x, $max_x, $style, $inorder, $child_l, $child_r, $crossings, $bootstrap, $posterior);
}

//-----------------------------------------------------------------------------
// Pure builder — Newick string in, data-model array out.  Used by both the
// CLI main below and by test.php.
//-----------------------------------------------------------------------------

function build_v1_json($newick)
{
	$newick = strip_newick_comments($newick);  // [...] anywhere whitespace is allowed
	$t = parse_newick($newick);
	collapse_unaries($t);   // first — removes degenerate unary internals
	auto_binarise($t);      // then — splits any remaining multifurcations
	renumber($t);           // contiguous ids 0..N-1, root = 0
	require_binary($t);     // safety net

	cladogram_to_branch_lengths($t);  // no-op if branch lengths already present

	$t->BuildWeights($t->GetRoot());
	$o = new RightOrder($t);
	$o->Order();

	$order_to_node = array();
	get_inorder($t, $order_to_node);

	$spans              = compute_spans($order_to_node);
	$crossings_by_order = compute_crossings($order_to_node, $spans);

	$tree_width = 1000;
	get_node_heights($t, $tree_width);
	get_max_subtree_height($t);

	// get_node_heights treats the root's own edge_length as a left-margin offset,
	// which puts x[root] > 0 when the Newick has a leading branch length.  The
	// data model wants the root at x=0; force it.
	$root_xy = $t->GetRoot()->GetAttribute('xy');
	$root_xy['x'] = 0;
	$t->GetRoot()->SetAttribute('xy', $root_xy);

	$initial_size = 9;
	list($first_zoom, $zoom_levels) = compute_first_zoom($t, $initial_size);

	list($labels, $parent, $x, $max_x, $style, $inorder, $child_l, $child_r, $crossings, $bootstrap, $posterior)
		= build_arrays($t, $order_to_node, $crossings_by_order);

	// Tree Colors over the taxonomy implied by the internal labels (hue capped
	// at family by default).  All '' if the tree carries no classification.
	$color = compute_node_colors($t, 2);

	$id_str = substr(hash('sha256', $newick), 0, 12);

	return array(
		'id' => $id_str,
		'config' => array(
			'tree_width'        => $tree_width,
			'row_height_open'   => 12,
			'row_height_closed' => 24,
			'min_zoom'          => 1,
			'max_zoom'          => $zoom_levels,
			'initial_size'      => $initial_size,
		),
		'n'          => $t->num_nodes,
		'labels'     => $labels,
		'parent'     => $parent,
		'x'          => $x,
		'max_x'      => $max_x,
		'first_zoom' => $first_zoom,
		'style'      => $style,
		'inorder'    => $inorder,
		'child_l'    => $child_l,
		'child_r'    => $child_r,
		'crossings'  => $crossings,
		'bootstrap'  => $bootstrap,
		'posterior'  => $posterior,
		'color'      => $color,
	);
}

//-----------------------------------------------------------------------------
// CLI main — runs only when invoked as a script (not when test.php includes us).
//-----------------------------------------------------------------------------

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)
{
	$newick = read_newick($argv);
	if (trim($newick) === '')
	{
		fwrite(STDERR, "build.php: no input\n");
		exit(1);
	}

	$out = build_v1_json($newick);

	$out_dir = __DIR__ . '/trees';
	if (!is_dir($out_dir))
	{
		mkdir($out_dir, 0755, true);
	}
	$out_path = $out_dir . '/' . $out['id'] . '.json';
	file_put_contents($out_path, json_encode($out));
	fprintf(STDERR, "wrote %s  (n=%d, zoom_levels=%d)\n", $out_path, $out['n'], $out['config']['max_zoom']);
}
