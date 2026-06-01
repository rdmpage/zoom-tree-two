<?php

// read_labels.php — apply a label-mapping TSV to a Newick tree.
//
//   php read_labels.php mapping.tsv tree.nwk   > out.tre
//   cat tree.nwk | php read_labels.php mapping.tsv
//
// The TSV has three columns (header optional):
//
//   original \t cleaned \t lineage
//
// Rules:
//
// * If `cleaned` is non-empty and differs from `original`, every leaf or
//   internal node with that exact `original` label is renamed.
// * `lineage` is a `;`-separated list of taxa, higher rank first (e.g.
//   `Order;Family;Genus`).  After renames, leaves are grouped by each
//   distinct taxon name appearing in any row's lineage; for each group of
//   >= 2 leaves we compute the LCA and label it with the taxon name,
//   provided the LCA is not already labelled and passes a loose-monophyly
//   check (the subtree contains no leaf belonging to a *different* taxon
//   in the TSV; unclassified leaves are tolerated).
//
// Order:  rename first, then lineage labelling.  Bootstrap / posterior
// metacomments survive the round trip.

require_once __DIR__ . '/src/php/node.php';
require_once __DIR__ . '/src/php/tree.php';
require_once __DIR__ . '/src/php/node_iterator.php';
require_once __DIR__ . '/src/php/tree-parse.php';
require_once __DIR__ . '/build.php';      // strip_newick_comments
require_once __DIR__ . '/rewrite.php';    // emit_newick

function read_labels_main($argv)
{
	$tree_path    = null;
	$mapping_path = null;   // default derived from tree path
	$out_path     = null;   // default derived from tree path

	for ($i = 1; $i < count($argv); $i++)
	{
		$a = $argv[$i];
		if (preg_match('/^--mapping=(.+)$/', $a, $m))     { $mapping_path = $m[1]; }
		else if (preg_match('/^--out=(.+)$/', $a, $m))    { $out_path = $m[1]; }
		else if ($a === '-h' || $a === '--help')
		{
			fwrite(STDERR, file_get_contents(__FILE__, false, null, 0, 1400));
			exit(0);
		}
		else if ($a !== '' && $a[0] === '-')
		{
			fwrite(STDERR, "read_labels.php: unknown option '$a'\n");
			exit(1);
		}
		else
		{
			$tree_path = $a;
		}
	}

	if ($tree_path === null)
	{
		fwrite(STDERR, "usage: php read_labels.php [--mapping=PATH] [--out=PATH] tree.tre\n");
		fwrite(STDERR, "       defaults: mapping = <tree>_mapping.tsv,  out = <tree>_labelled.<ext>\n");
		exit(1);
	}

	if ($mapping_path === null) { $mapping_path = sibling_path($tree_path, '_mapping', 'tsv'); }
	if ($out_path     === null) { $out_path     = sibling_path($tree_path, '_labelled'); }

	$rows = read_mapping($mapping_path);

	$newick = read_tree_newick($tree_path);   // sniffs NEXUS vs Newick
	if ($newick === false)
	{
		fwrite(STDERR, "read_labels.php: cannot read $tree_path\n");
		exit(1);
	}
	if ($newick === null)
	{
		fwrite(STDERR, "read_labels.php: no tree found in $tree_path\n");
		exit(1);
	}

	$newick = strip_newick_comments($newick);
	$t = parse_newick($newick);

	$renamed  = rename_by_mapping($t, $rows);
	$lin_stat = apply_lineage_labels($t, $rows);

	if (file_put_contents($out_path, emit_newick($t->GetRoot()) . ";\n") === false)
	{
		fwrite(STDERR, "read_labels.php: cannot write $out_path\n");
		exit(1);
	}

	fwrite(STDERR, sprintf(
		"read_labels.php: wrote %s (renamed=%d, taxa=%d, labelled=%d, not_monophyletic=%d, pre_labelled=%d, singletons=%d)\n",
		$out_path, $renamed, $lin_stat['taxa'], $lin_stat['labelled'],
		$lin_stat['not_monophyletic'], $lin_stat['pre_labelled'], $lin_stat['singletons']
	));
}

//-----------------------------------------------------------------------------
// Read the mapping TSV.  Returns array of rows; each row is an associative
// array with keys 'original', 'cleaned', 'lineage' (lineage is array of taxon
// strings, higher rank first).  A leading "original\tcleaned\tlineage"
// header is detected and skipped.
//-----------------------------------------------------------------------------

function read_mapping($path)
{
	$content = @file_get_contents($path);
	if ($content === false)
	{
		fwrite(STDERR, "read_labels.php: cannot read $path\n");
		exit(1);
	}

	$rows = array();
	$saw_header = false;
	foreach (preg_split('/\r?\n/', $content) as $line)
	{
		$line = rtrim($line);
		if ($line === '') { continue; }

		$parts = explode("\t", $line);
		$original = isset($parts[0]) ? trim($parts[0]) : '';

		if (!$saw_header)
		{
			$saw_header = true;
			if (strtolower($original) === 'original') { continue; }
		}
		if ($original === '') { continue; }

		$cleaned = isset($parts[1]) ? trim($parts[1]) : '';
		$lineage = isset($parts[2]) ? trim($parts[2]) : '';
		$lineage = array_values(array_filter(
			array_map('trim', explode(';', $lineage)),
			'strlen'
		));

		$rows[] = array(
			'original' => $original,
			'cleaned'  => $cleaned,
			'lineage'  => $lineage,
		);
	}
	return $rows;
}

//-----------------------------------------------------------------------------
// Walk the tree once and rename any node whose label appears in the mapping
// (and whose 'cleaned' is non-empty and different from 'original').
//-----------------------------------------------------------------------------

function rename_by_mapping($t, $rows)
{
	$rename = array();
	foreach ($rows as $r)
	{
		if ($r['cleaned'] !== '' && $r['cleaned'] !== $r['original'])
		{
			$rename[$r['original']] = $r['cleaned'];
		}
	}
	if (empty($rename)) { return 0; }

	$count = 0;
	$it = new NodeIterator($t->GetRoot());
	$q  = $it->Begin();
	while ($q != null)
	{
		$lab = $q->GetLabel();
		if ($lab !== '' && $lab !== null && isset($rename[$lab]))
		{
			$q->SetLabel($rename[$lab]);
			$count++;
		}
		$q = $it->Next();
	}
	return $count;
}

//-----------------------------------------------------------------------------
// Group leaves by each lineage taxon and label each group's LCA when the
// clade is loosely monophyletic.  Loose = leaves not in any TSV row are
// tolerated; leaves in a *different* TSV taxon are not.
//-----------------------------------------------------------------------------

function apply_lineage_labels($t, $rows)
{
	$t->BuildWeights($t->GetRoot());

	// Index leaves by their (post-rename) label.
	$leaves_by_label = array();
	$it = new NodeIterator($t->GetRoot());
	$q  = $it->Begin();
	while ($q != null)
	{
		if ($q->IsLeaf())
		{
			$lab = $q->GetLabel();
			if ($lab !== '' && $lab !== null)
			{
				$leaves_by_label[$lab][] = $q;
			}
		}
		$q = $it->Next();
	}

	$taxon_to_leaves = array();
	$taxon_depth     = array();   // max lineage-position seen per taxon
	$leaf_lineage    = array();   // spl_object_id => lineage array (for sibling checks)
	$parent_of       = array();   // child taxon => its parent (taxon at depth-1 in any lineage)

	foreach ($rows as $r)
	{
		$leaf_label = ($r['cleaned'] !== '') ? $r['cleaned'] : $r['original'];
		if (!isset($leaves_by_label[$leaf_label])) { continue; }

		// Record parent relationships from this row's lineage.
		for ($i = 1; $i < count($r['lineage']); $i++)
		{
			$parent_of[$r['lineage'][$i]] = $r['lineage'][$i - 1];
		}

		foreach ($leaves_by_label[$leaf_label] as $leaf)
		{
			$leaf_lineage[spl_object_id($leaf)] = $r['lineage'];
			foreach ($r['lineage'] as $depth => $taxon)
			{
				$taxon_to_leaves[$taxon][spl_object_id($leaf)] = $leaf;
				if (!isset($taxon_depth[$taxon]) || $depth > $taxon_depth[$taxon])
				{
					$taxon_depth[$taxon] = $depth;
				}
			}
		}
	}

	// For each parent, the set of its children.  Siblings(T) = children(parent(T)) \ {T}.
	$children_of = array();
	foreach ($parent_of as $child => $par)
	{
		$children_of[$par][$child] = true;
	}

	// Process most-specific taxa first so that, when several ranks share an
	// LCA, the deepest one (e.g. Family) claims it instead of the broadest
	// (e.g. Order).  arsort sorts by value descending, preserving keys.
	arsort($taxon_depth);

	$stats = array(
		'taxa'             => count($taxon_to_leaves),
		'labelled'         => 0,
		'not_monophyletic' => 0,
		'pre_labelled'     => 0,
		'singletons'       => 0,
	);

	foreach (array_keys($taxon_depth) as $taxon)
	{
		$leaves_map = $taxon_to_leaves[$taxon];
		$leaves = array_values($leaves_map);
		if (count($leaves) < 2)
		{
			$stats['singletons']++;
			continue;
		}

		$lca = compute_lca($leaves);
		if ($lca === null) { continue; }

		// Sibling-aware conflict: a leaf in the LCA subtree is only a conflict
		// for $taxon if its lineage contains a *sibling* of $taxon (another
		// child of the same parent in the classification).  Under-classified
		// leaves — whose lineage only goes down to their own genus/species —
		// don't name any sibling and are therefore tolerated.
		$par  = isset($parent_of[$taxon])    ? $parent_of[$taxon]    : null;
		$sibs = ($par !== null && isset($children_of[$par])) ? $children_of[$par] : array();
		unset($sibs[$taxon]);

		if (!empty($sibs) && count_sibling_conflicts($lca, $sibs, $leaf_lineage) > 0)
		{
			$stats['not_monophyletic']++;
			continue;
		}

		$existing = $lca->GetLabel();
		if ($existing !== '' && $existing !== null)
		{
			$stats['pre_labelled']++;
			continue;
		}

		$lca->SetLabel($taxon);
		$stats['labelled']++;
	}

	return $stats;
}

//-----------------------------------------------------------------------------
// LCA helpers — ancestor-marking, pairwise reduction.
//-----------------------------------------------------------------------------

function compute_lca($nodes)
{
	$n = count($nodes);
	if ($n === 0) { return null; }
	if ($n === 1) { return $nodes[0]; }

	$result = $nodes[0];
	for ($i = 1; $i < $n; $i++)
	{
		$result = lca_pair($result, $nodes[$i]);
		if ($result === null) { return null; }
	}
	return $result;
}

function lca_pair($a, $b)
{
	if ($a === $b) { return $a; }

	$marks = array();
	$p = $a;
	while ($p !== null)
	{
		$marks[spl_object_id($p)] = true;
		$p = $p->GetAncestor();
	}
	$p = $b;
	while ($p !== null)
	{
		if (isset($marks[spl_object_id($p)])) { return $p; }
		$p = $p->GetAncestor();
	}
	return null;
}

// Count leaves under $node whose lineage contains any taxon in $sibling_set
// (the hash set of siblings of the taxon being checked).  Stops counting at
// the first match per leaf — one sibling is enough to mark the leaf as a
// conflict.  Under-classified leaves (lineage missing or only Genus species)
// never match a sibling, so they're tolerated.
function count_sibling_conflicts($node, $sibling_set, $leaf_lineage)
{
	$count = 0;
	$stack = array($node);
	while (!empty($stack))
	{
		$n = array_pop($stack);
		if ($n->IsLeaf())
		{
			$id = spl_object_id($n);
			if (isset($leaf_lineage[$id]))
			{
				foreach ($leaf_lineage[$id] as $entry)
				{
					if (isset($sibling_set[$entry]))
					{
						$count++;
						break;
					}
				}
			}
		}
		else
		{
			$c = $n->GetChild();
			while ($c !== null)
			{
				$stack[] = $c;
				$c = $c->GetSibling();
			}
		}
	}
	return $count;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)
{
	read_labels_main($argv);
}
