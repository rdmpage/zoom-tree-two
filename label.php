<?php

// label.php — infer internal-node labels.  Two modes:
//
//   php label.php [--separator=SEP] input.tre              > out.tre   (auto)
//   php label.php --from-tsv=defs.tsv [--separator=SEP] input.tre > out.tre
//
// auto      Group every leaf by the first --separator-delimited token of
//           its label (default separator " ").  For each group with >= 2
//           members, find the LCA and label it with the group key if the
//           clade is monophyletic (subtree contains exactly those leaves)
//           and the LCA isn't already labelled.
//
// --from-tsv=PATH   Read a `taxon\tmembers` TSV (header row required:
//           `taxon\tmembers`).  Members are comma-separated; each member is
//           either a genus (matches the leaf-key in the tree) or another
//           taxon defined elsewhere in the TSV.  Forward references work —
//           we read the whole file before resolving.  For each taxon,
//           recursively expand members to a leaf-level key set, find the
//           leaves matching, then run an LCA + monophyly + label step.
//
//           Default monophyly check is LOOSE: the LCA is accepted if no
//           leaf in its subtree belongs to a *different* TSV taxon.
//           Unclassified leaves (not in any TSV row) are tolerated, so a
//           clade can still be labelled even when the TSV is missing some
//           recently-described genera.  Add --strict to require exact
//           subtree-size == matched-member-count instead.
//
// Output is Newick to stdout.  Any [&bootstrap=…] metacomments on the input
// survive the round trip (label appears before the bracket).

require_once __DIR__ . '/rewrite.php';   // emit_newick, format_label, strip_newick_comments

//-----------------------------------------------------------------------------
// CLI
//-----------------------------------------------------------------------------

function label_main($argv)
{
	$separator = ' ';
	$tsv_path  = null;
	$strict    = false;
	$input     = null;

	for ($i = 1; $i < count($argv); $i++)
	{
		$a = $argv[$i];
		if (preg_match('/^--separator=(.*)$/', $a, $m))
		{
			$separator = $m[1];
		}
		else if (preg_match('/^--from-tsv=(.+)$/', $a, $m))
		{
			$tsv_path = $m[1];
		}
		else if ($a === '--strict')
		{
			$strict = true;
		}
		else if ($a === '-h' || $a === '--help')
		{
			fwrite(STDERR, file_get_contents(__FILE__, false, null, 0, 1800));
			exit(0);
		}
		else if ($a !== '' && $a[0] === '-')
		{
			fwrite(STDERR, "label.php: unknown option '$a'\n");
			exit(1);
		}
		else
		{
			$input = $a;
		}
	}

	if ($input === null)
	{
		$newick = stream_get_contents(STDIN);
	}
	else
	{
		$newick = @file_get_contents($input);
		if ($newick === false)
		{
			fwrite(STDERR, "label.php: cannot read $input\n");
			exit(1);
		}
	}

	$newick = strip_newick_comments($newick);
	$t = parse_newick($newick);

	if ($tsv_path !== null)
	{
		$taxa  = read_tsv($tsv_path);
		$stats = infer_tsv_labels($t, $taxa, $separator, $strict);
		fwrite(STDERR, sprintf(
			"label.php (tsv %s, %s, %d taxa): labelled=%d, skipped (unmatched=%d, not_monophyletic=%d, already_labelled=%d)\n",
			$strict ? 'strict' : 'loose',
			$tsv_path, count($taxa),
			$stats['labelled'], $stats['unmatched'], $stats['not_monophyletic'], $stats['pre_labelled']
		));
	}
	else
	{
		$stats = infer_group_labels($t, $separator);
		fwrite(STDERR, sprintf(
			"label.php (auto): labelled=%d, skipped (singletons=%d, not_monophyletic=%d, already_labelled=%d)\n",
			$stats['labelled'], $stats['singletons'], $stats['not_monophyletic'], $stats['pre_labelled']
		));
	}

	echo emit_newick($t->GetRoot()) . ";\n";
}

//-----------------------------------------------------------------------------
// Pure function — Newick in (with optional metacomments), Newick out with
// inferred labels attached to monophyletic LCAs.
//-----------------------------------------------------------------------------

function label_newick($newick, $separator)
{
	$newick = strip_newick_comments($newick);
	$t = parse_newick($newick);

	$stats = infer_group_labels($t, $separator);

	fwrite(STDERR, sprintf(
		"label.php: labelled=%d, skipped (singletons=%d, not_monophyletic=%d, already_labelled=%d)\n",
		$stats['labelled'], $stats['singletons'], $stats['not_monophyletic'], $stats['pre_labelled']
	));

	return emit_newick($t->GetRoot()) . ';';
}

//-----------------------------------------------------------------------------
// Helpers
//-----------------------------------------------------------------------------

function infer_group_labels($t, $separator)
{
	$t->BuildWeights($t->GetRoot());   // weight = leaves under subtree

	// Group leaves by the first $separator-delimited token of their label.
	$by_group = array();
	$it = new NodeIterator($t->GetRoot());
	$q  = $it->Begin();
	while ($q != null)
	{
		if ($q->IsLeaf())
		{
			$label = $q->GetLabel();
			if ($label !== '' && $label !== null)
			{
				$key = first_token($label, $separator);
				if ($key !== '')
				{
					$by_group[$key][] = $q;
				}
			}
		}
		$q = $it->Next();
	}

	$stats = array(
		'labelled'         => 0,
		'singletons'       => 0,
		'not_monophyletic' => 0,
		'pre_labelled'     => 0,
	);

	foreach ($by_group as $key => $members)
	{
		if (count($members) < 2)
		{
			$stats['singletons']++;
			continue;
		}

		$lca = compute_lca($members);
		if ($lca === null)
		{
			continue;
		}

		$subtree_size = (int) $lca->GetAttribute('weight');
		if ($subtree_size !== count($members))
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

		$lca->SetLabel($key);
		$stats['labelled']++;
	}

	return $stats;
}

//-----------------------------------------------------------------------------
// TSV-driven mode
//-----------------------------------------------------------------------------

function read_tsv($path)
{
	$content = @file_get_contents($path);
	if ($content === false)
	{
		fwrite(STDERR, "label.php: cannot read tsv $path\n");
		exit(1);
	}
	return parse_tsv_string($content);
}

// Returns an associative array taxon => [member1, member2, ...].  A header
// row of "taxon\tmembers" (case-insensitive) is skipped if present.  Lines
// without a tab are ignored.
function parse_tsv_string($content)
{
	$taxa = array();
	$lines = preg_split('/\r?\n/', $content);
	$saw_header = false;

	foreach ($lines as $line)
	{
		$line = rtrim($line);
		if ($line === '') { continue; }

		$parts = explode("\t", $line, 2);
		if (count($parts) < 2) { continue; }

		$taxon = trim($parts[0]);

		if (!$saw_header && strtolower($taxon) === 'taxon' && strtolower(trim($parts[1])) === 'members')
		{
			$saw_header = true;
			continue;
		}

		$members = array_values(array_filter(
			array_map('trim', explode(',', $parts[1])),
			'strlen'
		));

		if ($taxon !== '')
		{
			$taxa[$taxon] = $members;
		}
	}

	return $taxa;
}

// Recursively expand a taxon to the set of leaf-level keys (typically
// genera).  A member that has no row in $taxa is treated as a leaf-level
// key — the genus name we expect to find in the tree.  Caches results per
// taxon so deep hierarchies stay O(N).
function expand_to_leaves($taxon, $taxa, &$cache)
{
	if (isset($cache[$taxon]))
	{
		return $cache[$taxon];
	}
	if (!isset($taxa[$taxon]))
	{
		$cache[$taxon] = array($taxon);
		return $cache[$taxon];
	}

	$cache[$taxon] = array();   // tentative — guards against cycles
	$out = array();
	foreach ($taxa[$taxon] as $member)
	{
		foreach (expand_to_leaves($member, $taxa, $cache) as $k)
		{
			$out[] = $k;
		}
	}
	$cache[$taxon] = array_values(array_unique($out));
	return $cache[$taxon];
}

function infer_tsv_labels($t, $taxa, $separator, $strict = false)
{
	$t->BuildWeights($t->GetRoot());

	// Index leaves by their first-token key (genus, typically).
	$leaves_by_key = array();
	$it = new NodeIterator($t->GetRoot());
	$q  = $it->Begin();
	while ($q != null)
	{
		if ($q->IsLeaf())
		{
			$label = $q->GetLabel();
			if ($label !== '' && $label !== null)
			{
				$leaves_by_key[first_token($label, $separator)][] = $q;
			}
		}
		$q = $it->Next();
	}

	$stats = array(
		'labelled'         => 0,
		'unmatched'        => 0,
		'not_monophyletic' => 0,
		'pre_labelled'     => 0,
	);

	$cache = array();

	// Loose mode: union of every key defined in any TSV taxon.  A leaf whose
	// key is in `$known` belongs to some classification; a leaf whose key is
	// absent from `$known` is "unclassified" and tolerated.
	$known = array();
	if (!$strict)
	{
		foreach ($taxa as $taxon => $_)
		{
			foreach (expand_to_leaves($taxon, $taxa, $cache) as $k)
			{
				$known[$k] = true;
			}
		}
	}

	foreach ($taxa as $taxon => $_)
	{
		$keys    = expand_to_leaves($taxon, $taxa, $cache);
		$key_set = array_flip($keys);

		$members = array();
		foreach ($keys as $k)
		{
			if (isset($leaves_by_key[$k]))
			{
				foreach ($leaves_by_key[$k] as $leaf)
				{
					$members[] = $leaf;
				}
			}
		}

		if (count($members) < 2)
		{
			$stats['unmatched']++;
			continue;
		}

		$lca = compute_lca($members);
		if ($lca === null)
		{
			continue;
		}

		if ($strict)
		{
			$subtree_size = (int) $lca->GetAttribute('weight');
			if ($subtree_size !== count($members))
			{
				$stats['not_monophyletic']++;
				continue;
			}
		}
		else
		{
			if (count_conflicting_leaves($lca, $key_set, $known, $separator) > 0)
			{
				$stats['not_monophyletic']++;
				continue;
			}
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

// Number of leaves in $node's subtree whose key is in $known_keys but not in
// $own_keys.  Zero means "no leaf belonging to a different TSV taxon" —
// i.e. loose-monophyletic.
function count_conflicting_leaves($node, $own_keys, $known_keys, $separator)
{
	$count = 0;
	$stack = array($node);
	while (!empty($stack))
	{
		$n = array_pop($stack);
		if ($n->IsLeaf())
		{
			$label = $n->GetLabel();
			if ($label !== '' && $label !== null)
			{
				$key = first_token($label, $separator);
				if (isset($known_keys[$key]) && !isset($own_keys[$key]))
				{
					$count++;
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

//-----------------------------------------------------------------------------
// Shared helpers
//-----------------------------------------------------------------------------

function first_token($s, $separator)
{
	$pos = strpos($s, $separator);
	if ($pos === false)
	{
		return $s;
	}
	return substr($s, 0, $pos);
}

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

// LCA(a, b) by marking a's ancestors then walking up from b.
function lca_pair($a, $b)
{
	if ($a === $b) { return $a; }

	$marks = array();
	$p = $a;
	while ($p !== null)
	{
		$marks[$p->GetId()] = true;
		$p = $p->GetAncestor();
	}

	$p = $b;
	while ($p !== null)
	{
		if (isset($marks[$p->GetId()]))
		{
			return $p;
		}
		$p = $p->GetAncestor();
	}

	return null;
}

//-----------------------------------------------------------------------------

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)
{
	label_main($argv);
}
