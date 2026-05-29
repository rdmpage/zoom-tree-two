<?php

// label.php — infer internal-node labels from leaf-name patterns.
//
//   php label.php [--separator=SEP] input.tre   > out.tre
//   cat input.tre | php label.php [--separator=SEP]
//
// Each leaf label is split by --separator (default " ") and the first token
// is used as a "group key" — typically the genus when leaves are
// `Genus species`.  For every group with >= 2 members, the LCA is found and
// the clade checked for monophyly (its subtree contains exactly those
// leaves).  If monophyletic AND the LCA is not already labelled, we set its
// label to the group key.
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
	$input     = null;

	for ($i = 1; $i < count($argv); $i++)
	{
		$a = $argv[$i];
		if (preg_match('/^--separator=(.*)$/', $a, $m))
		{
			$separator = $m[1];
		}
		else if ($a === '-h' || $a === '--help')
		{
			fwrite(STDERR, file_get_contents(__FILE__, false, null, 0, 1200));
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

	echo label_newick($newick, $separator) . "\n";
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
