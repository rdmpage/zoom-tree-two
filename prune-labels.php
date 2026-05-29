<?php

// prune-labels.php — keep only specified tokens of each leaf label.
//
//   php prune-labels.php --keep=N1,N2,... [--separator=SEP] input.tre   > out.tre
//   php prune-labels.php --keep-from=N    [--separator=SEP] input.tre   > out.tre
//
// Two modes (pick one):
//
//   --keep=N1,N2,...   Keep the 0-indexed tokens at these positions
//                      (joined by --separator).  treeoflife leaves like
//                      "Boraginales Boraginaceae Turricula parryi
//                      ERR7621535" with --keep=2,3 become "Turricula
//                      parryi".
//
//   --keep-from=N      Start at token N, accumulate non-empty tokens until
//                      one contains a pipe `|` (or the label ends).  Use
//                      this for trees with variable-length species names
//                      followed by a `id|haplo|locality` suffix —
//                      "Genus burksi 2|1h|FL" -> "Genus burksi" AND
//                      "Genus sp IT 1|1h|FL" -> "Genus sp IT".
//
// Internal-node labels and metacomments are untouched.  If a leaf has too
// few tokens for the requested mode, the original label is preserved
// rather than being replaced with an empty string.  Tokenisation uses
// preg_split('/\s+/', trim(...)) so the `  ` from `__` in the source
// folds into a single separator and doesn't shift token positions.

require_once __DIR__ . '/rewrite.php';   // emit_newick, strip_newick_comments, ...

//-----------------------------------------------------------------------------
// CLI
//-----------------------------------------------------------------------------

function prune_main($argv)
{
	$keep_spec = null;
	$separator = ' ';
	$input     = null;

	for ($i = 1; $i < count($argv); $i++)
	{
		$a = $argv[$i];
		if (preg_match('/^--keep=(.+)$/', $a, $m))
		{
			$indices = array();
			foreach (explode(',', $m[1]) as $tok)
			{
				$tok = trim($tok);
				if ($tok !== '' && ctype_digit($tok)) { $indices[] = (int) $tok; }
			}
			$keep_spec = $indices;
		}
		else if (preg_match('/^--keep-from=(\d+)$/', $a, $m))
		{
			$keep_spec = array('from' => (int) $m[1]);
		}
		else if (preg_match('/^--separator=(.*)$/', $a, $m))
		{
			$separator = $m[1];
		}
		else if ($a === '-h' || $a === '--help')
		{
			fwrite(STDERR, file_get_contents(__FILE__, false, null, 0, 1600));
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

	if ($keep_spec === null || (is_array($keep_spec) && !isset($keep_spec['from']) && empty($keep_spec)))
	{
		fwrite(STDERR, "prune-labels.php: --keep=N1,N2,... or --keep-from=N is required\n");
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

	$count = prune_leaf_labels($t, $separator, $keep_spec);

	fwrite(STDERR, sprintf(
		"prune-labels.php: pruned %d leaf labels (%s)\n",
		$count, describe_keep_spec($keep_spec)
	));

	echo emit_newick($t->GetRoot()) . ";\n";
}

function describe_keep_spec($spec)
{
	if (is_array($spec) && isset($spec['from'])) { return 'keep-from=' . $spec['from']; }
	if (is_array($spec))                          { return 'keep=' . implode(',', $spec); }
	return '?';
}

//-----------------------------------------------------------------------------
// Walk tree, rewrite each leaf's label to the kept tokens.  Returns the
// number of labels actually changed.
//-----------------------------------------------------------------------------

function prune_leaf_labels($t, $separator, $keep_spec)
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
				// Tokenise with whitespace collapse so the `  ` from `__`
				// folds away and doesn't introduce empty slots.
				if ($separator === ' ')
				{
					$parts = preg_split('/\s+/', trim($label));
				}
				else
				{
					$collapsed = preg_replace('/' . preg_quote($separator, '/') . '+/', $separator, trim($label));
					$parts = explode($separator, $collapsed);
				}

				$kept = compute_kept($parts, $keep_spec);
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

function compute_kept($parts, $keep_spec)
{
	if (is_array($keep_spec) && isset($keep_spec['from']))
	{
		$start = $keep_spec['from'];
		$kept  = array();
		for ($i = $start; $i < count($parts); $i++)
		{
			if ($parts[$i] === '') { continue; }
			if (strpos($parts[$i], '|') !== false) { break; }
			$kept[] = $parts[$i];
		}
		return $kept;
	}

	// Otherwise: integer list of positions.
	$indices = is_array($keep_spec) ? $keep_spec : array();
	$kept = array();
	foreach ($indices as $idx)
	{
		if ($idx >= 0 && $idx < count($parts) && $parts[$idx] !== '')
		{
			$kept[] = $parts[$idx];
		}
	}
	return $kept;
}

//-----------------------------------------------------------------------------

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)
{
	prune_main($argv);
}
