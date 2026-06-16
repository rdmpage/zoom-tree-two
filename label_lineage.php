<?php

// label_lineage.php — label internal nodes of a tree from a TSV of per-leaf
// lineages, by classification intersection (an "LCA by set intersection").
//
//   php label_lineage.php [--out=PATH] [--label-col=label] [--lineage-col=lineage] \
//       tree.tre lineages.tsv
//
// Approach (after bold-view's label_internal_nodes, with the empty-set bug
// fixed):
//   1. Give each leaf the lineage array from its TSV row (split on ';').
//   2. Post-order: each internal node's classification is the intersection of
//      its descendants' lineages — the deepest rank common to ALL of them.
//      Descendants with no lineage are IGNORED (they don't constrain), so one
//      unclassified leaf can't wipe out a clade's label.
//   3. Label each internal node with the deepest (most terminal) shared rank.
//   4. Blank any label identical to its ancestor's, so a name appears only on
//      the topmost node of its run (the clade's LCA).
//
// Leaves are never relabelled — the original leaf labels are kept and shown.
// Rank prefixes (o__/f__/g__/s__/ssp__) are preserved on internal labels.

require_once __DIR__ . '/src/php/node.php';
require_once __DIR__ . '/src/php/tree.php';
require_once __DIR__ . '/src/php/node_iterator.php';
require_once __DIR__ . '/src/php/tree-parse.php';
require_once __DIR__ . '/src/php/read-tree.php';   // read_tree_newick (sniffs NEXUS/Newick)
require_once __DIR__ . '/build.php';               // strip_newick_comments

//-----------------------------------------------------------------------------
// Newick label emitter that PRESERVES literal underscores by quoting.
//
// parse_newick reads an unquoted '_' as a space, so any label that genuinely
// contains '_' (our rank-prefixed internal labels: "f__Limnephilidae") must be
// single-quoted to survive a round-trip.  Leaf labels hold spaces internally
// (the '_' from the source tree was already converted on read), so they emit
// bare via the standard space->'_' shorthand.
function emit_label($label)
{
	if ($label === '' || $label === null) { return ''; }

	$forbidden = "/[\\s()\\[\\]':;,]/";

	$needs_quote   = preg_match($forbidden, $label) === 1;
	$has_underscore = strpos($label, '_') !== false;

	if (!$needs_quote && !$has_underscore)
	{
		return $label;   // already safe bare, e.g. KJ836409.1
	}

	if ($needs_quote && !$has_underscore)
	{
		// Only spaces/forbidden chars, no literal '_': use the '_' shorthand
		// if that clears every forbidden char (e.g. "Trichoptera 505|1h|CO").
		$u = str_replace(' ', '_', $label);
		if (preg_match($forbidden, $u) !== 1)
		{
			return $u;
		}
	}

	// Literal underscore present, or still unsafe: single-quote it.
	return "'" . str_replace("'", "''", $label) . "'";
}

//-----------------------------------------------------------------------------
// Recursively emit Newick, using emit_label() so internal labels round-trip.
function emit_labelled_newick($node)
{
	$s = '';

	$children = $node->GetChildren();
	if (!empty($children))
	{
		$parts = array();
		foreach ($children as $c)
		{
			$parts[] = emit_labelled_newick($c);
		}
		$s .= '(' . implode(',', $parts) . ')';
	}

	$s .= emit_label($node->GetLabel());

	$bl = $node->GetAttribute('edge_length');
	if ($bl !== null && $bl !== '')
	{
		$s .= ':' . $bl;
	}

	return $s;
}

//-----------------------------------------------------------------------------
// Read the TSV; return map: match-label -> ['lineage'=>array, 'display'=>str].
// The match column is keyed against the tree's leaf labels; the display column
// is what to (re)label the leaf with (here typically identical).
function read_lineages($path, $match_col, $display_col, $lineage_col)
{
	$fh = @fopen($path, 'r');
	if ($fh === false) { return false; }

	$header = fgets($fh);
	if ($header === false) { fclose($fh); return array(); }
	$header = rtrim($header, "\r\n");
	$cols = explode("\t", $header);

	$mi = array_search($match_col, $cols, true);
	$gi = array_search($lineage_col, $cols, true);
	$di = array_search($display_col, $cols, true);   // optional
	if ($mi === false || $gi === false)
	{
		fclose($fh);
		fwrite(STDERR, "label_lineage.php: TSV must have '$match_col' and '$lineage_col' columns (found: " . implode(', ', $cols) . ")\n");
		exit(1);
	}

	$map = array();
	while (($line = fgets($fh)) !== false)
	{
		$line = rtrim($line, "\r\n");
		if ($line === '') { continue; }
		$f = explode("\t", $line);

		// NB: do NOT trim — leading spaces in labels are significant (they
		// correspond to leaves whose source name began with '_').
		$key     = isset($f[$mi]) ? $f[$mi] : '';
		$lin     = isset($f[$gi]) ? $f[$gi] : '';
		$display = ($di !== false && isset($f[$di])) ? $f[$di] : '';
		if ($key === '') { continue; }

		$parts = array();
		foreach (explode(';', $lin) as $p)
		{
			if ($p !== '') { $parts[] = $p; }
		}

		// Key on the trailing-trimmed label: a source leaf ending in '_' parses
		// to a trailing space the TSV doesn't carry.  Leading spaces are kept
		// (they ARE significant — they match leaves whose name began with '_').
		$map[rtrim($key)] = array('lineage' => $parts, 'display' => $display);
	}
	fclose($fh);
	return $map;
}

//-----------------------------------------------------------------------------
// Core: label internal nodes by classification intersection.  Returns stats.
function label_internal_nodes_from_map($t, $lineages)
{
	$classification = array();   // node id -> lineage array (shared so far)
	$seen           = array();   // node id -> has an ancestor received any child set
	$has_data       = array();   // node id -> subtree contains >=1 labelled leaf

	$stats = array(
		'leaves'            => 0,
		'leaves_matched'    => 0,
		'leaves_renamed'    => 0,
		'internal'          => 0,
		'internal_labelled' => 0,
		'unmatched_sample'  => array(),
	);

	// Pass 1: init every node, seed leaves from the TSV.
	$it = new NodeIterator($t->GetRoot());
	$q  = $it->Begin();
	while ($q != null)
	{
		$id = $q->GetId();
		$classification[$id] = array();

		if ($q->IsLeaf())
		{
			$stats['leaves']++;
			$lab = $q->GetLabel();
			$key = rtrim($lab);   // tolerate trailing space (trailing '_' in source)
			if ($lab !== '' && isset($lineages[$key]))
			{
				$row = $lineages[$key];
				$classification[$id] = $row['lineage'];
				$has_data[$id] = true;   // this leaf constrains its ancestors
				$stats['leaves_matched']++;

				// Rename the leaf to its display label if one is given and it
				// actually differs (keeps the original otherwise).
				if ($row['display'] !== '' && $row['display'] !== $lab)
				{
					$q->SetLabel($row['display']);
					$stats['leaves_renamed']++;
				}
			}
			else if (count($stats['unmatched_sample']) < 8)
			{
				$stats['unmatched_sample'][] = $lab;
			}
		}
		else
		{
			$stats['internal']++;
		}
		$q = $it->Next();
	}

	// Pass 2: post-order fold each node's set up into its ancestor.  A child is
	// folded in iff its subtree carries data (has_data) -- NOT merely iff its set
	// is non-empty.  The distinction matters:
	//   * A child with NO data (an unlabelled leaf, or a subtree of them) is
	//     ignored, so it can't wipe out a clade's classification.
	//   * A child WITH data but an EMPTY set is a genuinely heterogeneous clade
	//     (its leaves share no rank).  That emptiness is meaningful: intersect it
	//     in so the ancestor collapses to no common rank too.  Skipping it (the
	//     old `!empty($child)` test) let a single labelled sibling's lineage --
	//     species and all -- leak up across multi-genus clades.
	$q = $it->Begin();
	while ($q != null)
	{
		$anc = $q->GetAncestor();
		$cid = $q->GetId();
		if ($anc && !empty($has_data[$cid]))
		{
			$child = $classification[$cid];
			$aid = $anc->GetId();
			if (empty($seen[$aid]))
			{
				$classification[$aid] = $child;
				$seen[$aid] = true;
			}
			else
			{
				$classification[$aid] = array_intersect($classification[$aid], $child);
			}
			$has_data[$aid] = true;
		}
		$q = $it->Next();
	}

	// Pass 3: label each internal node with the deepest shared rank.
	$q = $it->Begin();
	while ($q != null)
	{
		if (!$q->IsLeaf())
		{
			$set = $classification[$q->GetId()];
			if (count($set) > 0)
			{
				$q->SetLabel(end($set));   // last = most terminal rank
			}
		}
		$q = $it->Next();
	}

	// Pass 4: blank a label equal to its ancestor's, keeping only the topmost
	// occurrence (the clade's LCA).  Post-order: a child is compared while its
	// ancestor still holds its label.
	$q = $it->Begin();
	while ($q != null)
	{
		if (!$q->IsLeaf())
		{
			$anc = $q->GetAncestor();
			if ($anc && $q->GetLabel() !== '' && strcmp($q->GetLabel(), $anc->GetLabel()) === 0)
			{
				$q->SetLabel('');
			}
		}
		$q = $it->Next();
	}

	// Final tally of internal labels.
	$q = $it->Begin();
	while ($q != null)
	{
		if (!$q->IsLeaf() && $q->GetLabel() !== '')
		{
			$stats['internal_labelled']++;
		}
		$q = $it->Next();
	}

	return $stats;
}

//-----------------------------------------------------------------------------
function label_lineage_main($argv)
{
	$out         = null;
	$match_col   = 'original_label';
	$display_col = 'new_label';
	$lineage_col = 'lineage';
	$pos         = array();

	for ($i = 1; $i < count($argv); $i++)
	{
		$a = $argv[$i];
		if (preg_match('/^--out=(.+)$/', $a, $m))             { $out = $m[1]; }
		else if (preg_match('/^--match-col=(.+)$/', $a, $m))     { $match_col = $m[1]; }
		else if (preg_match('/^--display-col=(.+)$/', $a, $m))   { $display_col = $m[1]; }
		else if (preg_match('/^--lineage-col=(.+)$/', $a, $m))   { $lineage_col = $m[1]; }
		else if ($a === '-h' || $a === '--help')
		{
			fwrite(STDERR, file_get_contents(__FILE__, false, null, 0, 1500));
			exit(0);
		}
		else if ($a !== '' && $a[0] === '-')
		{
			fwrite(STDERR, "label_lineage.php: unknown option '$a'\n");
			exit(1);
		}
		else { $pos[] = $a; }
	}

	if (count($pos) < 2)
	{
		fwrite(STDERR, "usage: php label_lineage.php [--out=PATH] [--label-col=label] [--lineage-col=lineage] tree.tre lineages.tsv\n");
		exit(1);
	}

	list($tree_path, $tsv_path) = $pos;

	$newick = read_tree_newick($tree_path);   // sniffs NEXUS vs Newick
	if ($newick === false) { fwrite(STDERR, "label_lineage.php: cannot read $tree_path\n"); exit(1); }
	if ($newick === null)  { fwrite(STDERR, "label_lineage.php: no tree found in $tree_path\n"); exit(1); }

	$lineages = read_lineages($tsv_path, $match_col, $display_col, $lineage_col);
	if ($lineages === false) { fwrite(STDERR, "label_lineage.php: cannot read $tsv_path\n"); exit(1); }

	$newick = strip_newick_comments($newick);
	$t = parse_newick($newick);

	$stats = label_internal_nodes_from_map($t, $lineages);

	$out_newick = emit_labelled_newick($t->GetRoot()) . ";\n";

	if ($out === null)
	{
		// default: <tree>_labelled.tre next to the input
		$dot = strrpos($tree_path, '.');
		$out = ($dot === false ? $tree_path : substr($tree_path, 0, $dot)) . '_labelled.tre';
	}

	if (@file_put_contents($out, $out_newick) === false)
	{
		fwrite(STDERR, "label_lineage.php: cannot write $out\n");
		exit(1);
	}

	fwrite(STDERR, sprintf(
		"label_lineage.php: wrote %s\n  leaves %d (matched %d, unmatched %d, renamed %d), internal %d, labelled %d\n",
		$out,
		$stats['leaves'], $stats['leaves_matched'], $stats['leaves'] - $stats['leaves_matched'], $stats['leaves_renamed'],
		$stats['internal'], $stats['internal_labelled']
	));

	if (!empty($stats['unmatched_sample']))
	{
		fwrite(STDERR, "  unmatched leaf labels (sample):\n");
		foreach ($stats['unmatched_sample'] as $u)
		{
			fwrite(STDERR, "    [$u]\n");
		}
	}
}

// Run only when invoked directly (not when included by a test harness).
if (isset($argv) && realpath($argv[0]) === realpath(__FILE__))
{
	label_lineage_main($argv);
}
