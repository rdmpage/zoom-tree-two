<?php

// prune-labels.php — keep only specified tokens of each leaf label.
//
//   php prune-labels.php --keep=N1,N2,... [--separator=SEP] input.tre > out.tre
//   cat input.tre | php prune-labels.php --keep=2,3
//
// For each leaf, split the label by --separator (default " ") and keep only
// the 0-indexed token positions listed in --keep, joined back with the same
// separator.  Internal-node labels and metacomments are untouched.
//
// Example.  treeoflife leaves like "Boraginales Boraginaceae Turricula
// parryi ERR7621535" pruned with --keep=2,3 become "Turricula parryi".
//
// If a leaf has fewer tokens than requested (e.g. an outgroup labelled just
// "Foo" pruned with --keep=2,3), the original label is preserved rather
// than replaced with an empty string.

require_once __DIR__ . '/rewrite.php';   // emit_newick, strip_newick_comments, ...

//-----------------------------------------------------------------------------
// CLI
//-----------------------------------------------------------------------------

function prune_main($argv)
{
	$keep_str  = null;
	$separator = ' ';
	$input     = null;

	for ($i = 1; $i < count($argv); $i++)
	{
		$a = $argv[$i];
		if (preg_match('/^--keep=(.+)$/', $a, $m))
		{
			$keep_str = $m[1];
		}
		else if (preg_match('/^--separator=(.*)$/', $a, $m))
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
			fwrite(STDERR, "prune-labels.php: unknown option '$a'\n");
			exit(1);
		}
		else
		{
			$input = $a;
		}
	}

	if ($keep_str === null)
	{
		fwrite(STDERR, "prune-labels.php: --keep=N1,N2,... is required\n");
		exit(1);
	}

	$keep = array();
	foreach (explode(',', $keep_str) as $tok)
	{
		$tok = trim($tok);
		if ($tok === '' || !ctype_digit($tok)) { continue; }
		$keep[] = (int) $tok;
	}
	if (empty($keep))
	{
		fwrite(STDERR, "prune-labels.php: --keep must contain at least one non-negative integer\n");
		exit(1);
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
			fwrite(STDERR, "prune-labels.php: cannot read $input\n");
			exit(1);
		}
	}

	$newick = strip_newick_comments($newick);
	$t = parse_newick($newick);

	$count = prune_leaf_labels($t, $separator, $keep);

	fwrite(STDERR, sprintf(
		"prune-labels.php: pruned %d leaf labels (kept tokens %s)\n",
		$count, implode(',', $keep)
	));

	echo emit_newick($t->GetRoot()) . ";\n";
}

//-----------------------------------------------------------------------------
// Walk tree, rewrite each leaf's label to the kept tokens.  Returns the
// number of labels actually changed.
//-----------------------------------------------------------------------------

function prune_leaf_labels($t, $separator, $keep)
{
	$count = 0;
	$it = new NodeIterator($t->GetRoot());
	$q  = $it->Begin();
	while ($q != null)
	{
		if ($q->IsLeaf())
		{
			$label = $q->GetLabel();
			if ($label !== '' && $label !== null)
			{
				$parts = explode($separator, $label);
				$kept = array();
				foreach ($keep as $idx)
				{
					if ($idx >= 0 && $idx < count($parts))
					{
						$kept[] = $parts[$idx];
					}
				}
				if (!empty($kept))
				{
					$new_label = implode($separator, $kept);
					if ($new_label !== $label)
					{
						$q->SetLabel($new_label);
						$count++;
					}
				}
			}
		}
		$q = $it->Next();
	}
	return $count;
}

//-----------------------------------------------------------------------------

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)
{
	prune_main($argv);
}
