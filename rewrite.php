<?php

// rewrite.php — convert numeric internal-node labels into BEAST-style
// metacomments.  Bootstrap support becomes [&bootstrap=NN]; posterior
// probability becomes [&posterior=PP].
//
//   php rewrite.php [--internal=MODE] input.tre   > cleaned.tre
//   cat input.tre | php rewrite.php [--internal=MODE]
//
// --internal:
//   auto       (default)  classify by inspecting every non-empty internal
//                         label.  All-numeric in [0, 1]  -> posterior;
//                         all-numeric in [0, 100]        -> bootstrap;
//                         otherwise                      -> label (no change).
//   bootstrap            force: every numeric internal label -> support
//   posterior            force: every numeric internal label -> posterior
//   label                no rewriting; just normalise + re-emit
//
// The output is plain Newick — the metacomments live inside `[...]`, which
// our build.php strips before parsing.  Once we add metacomment capture
// to the parser, the support values will surface in the JSON.

require_once __DIR__ . '/src/php/node.php';
require_once __DIR__ . '/src/php/tree.php';
require_once __DIR__ . '/src/php/node_iterator.php';
require_once __DIR__ . '/src/php/tree-parse.php';
require_once __DIR__ . '/build.php';   // for strip_newick_comments

//-----------------------------------------------------------------------------
// CLI
//-----------------------------------------------------------------------------

function rewrite_main($argv)
{
	$mode  = 'auto';
	$input = null;

	for ($i = 1; $i < count($argv); $i++)
	{
		$a = $argv[$i];
		if (preg_match('/^--internal=(.+)$/', $a, $m))
		{
			$mode = $m[1];
		}
		else if ($a === '-h' || $a === '--help')
		{
			fwrite(STDERR, file_get_contents(__FILE__, false, null, 0, 1400));
			exit(0);
		}
		else if ($a[0] === '-')
		{
			fwrite(STDERR, "rewrite.php: unknown option '$a'\n");
			exit(1);
		}
		else
		{
			$input = $a;
		}
	}

	$valid = array('auto', 'bootstrap', 'posterior', 'label');
	if (!in_array($mode, $valid, true))
	{
		fwrite(STDERR, "rewrite.php: --internal must be one of: " . implode(', ', $valid) . "\n");
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
			fwrite(STDERR, "rewrite.php: cannot read $input\n");
			exit(1);
		}
	}

	echo rewrite_newick($newick, $mode) . "\n";
}

//-----------------------------------------------------------------------------
// Pure function — same input, same mode, same output.
//-----------------------------------------------------------------------------

function rewrite_newick($newick, $mode)
{
	$newick = strip_newick_comments($newick);
	$t = parse_newick($newick);

	if ($mode === 'auto')
	{
		$labels = collect_internal_labels($t);
		$mode   = classify_internal_labels($labels);
		fwrite(STDERR, "rewrite.php: auto-classified internal labels as '$mode'\n");
	}

	if ($mode === 'bootstrap' || $mode === 'posterior')
	{
		annotate_numeric_internals($t, $mode);
	}

	return emit_newick($t->GetRoot()) . ';';
}

//-----------------------------------------------------------------------------
// Helpers — used by rewrite_main and the tests.
//-----------------------------------------------------------------------------

function collect_internal_labels($t)
{
	$labels = array();
	$it = new NodeIterator($t->GetRoot());
	$q  = $it->Begin();
	while ($q != null)
	{
		if (!$q->IsLeaf())
		{
			$label = $q->GetLabel();
			if ($label !== '' && $label !== null)
			{
				$labels[] = $label;
			}
		}
		$q = $it->Next();
	}
	return $labels;
}

// All values in [0, 1]  -> 'posterior'
// All values in [0,100] -> 'bootstrap'
// Anything non-numeric or out of range -> 'label' (don't touch).
function classify_internal_labels($labels)
{
	if (empty($labels))
	{
		return 'label';
	}

	$max = 0.0;
	foreach ($labels as $lab)
	{
		if (!is_numeric($lab))
		{
			return 'label';
		}
		$v = (float) $lab;
		if ($v < 0)
		{
			return 'label';
		}
		if ($v > $max)
		{
			$max = $v;
		}
	}

	if ($max <= 1.0)  { return 'posterior'; }
	if ($max <= 100)  { return 'bootstrap'; }
	return 'label';
}

// Walk the tree; for every internal whose label is numeric, store the value
// under the meta_<key> attribute and clear the label.
function annotate_numeric_internals($t, $key)
{
	$it = new NodeIterator($t->GetRoot());
	$q  = $it->Begin();
	while ($q != null)
	{
		if (!$q->IsLeaf())
		{
			$label = $q->GetLabel();
			if ($label !== '' && is_numeric($label))
			{
				$q->SetAttribute('meta_' . $key, $label);
				$q->SetLabel('');
			}
		}
		$q = $it->Next();
	}
}

//-----------------------------------------------------------------------------
// Newick emitter — recursive descent.  Preserves child order (no
// ladderisation).  Emits BEAST-style metacomments after the node label.
//-----------------------------------------------------------------------------

function emit_newick($node)
{
	$s = '';

	$children = $node->GetChildren();
	if (!empty($children))
	{
		$parts = array();
		foreach ($children as $c)
		{
			$parts[] = emit_newick($c);
		}
		$s .= '(' . implode(',', $parts) . ')';
	}

	$label = $node->GetLabel();
	if ($label !== '' && $label !== null)
	{
		$s .= format_label($label);
	}

	$meta = array();
	foreach (array('bootstrap', 'posterior') as $k)
	{
		$v = $node->GetAttribute('meta_' . $k);
		if ($v !== null && $v !== '')
		{
			$meta[] = $k . '=' . $v;
		}
	}
	if (!empty($meta))
	{
		$s .= '[&' . implode(',', $meta) . ']';
	}

	$bl = $node->GetAttribute('edge_length');
	if ($bl !== null && $bl !== '')
	{
		$s .= ':' . $bl;
	}

	return $s;
}

// Newick labels: unquoted labels must be drawn from a restricted character
// set.  We reverse the read-time `_` -> ` ` conversion (spaces back to
// underscores), and quote anything else that needs it.
function format_label($label)
{
	$safe = '/^[A-Za-z0-9._\-]+$/';

	if (preg_match($safe, $label))
	{
		return $label;
	}

	$with_underscores = str_replace(' ', '_', $label);
	if (preg_match($safe, $with_underscores))
	{
		return $with_underscores;
	}

	// Anything else: single-quoted, doubling embedded quotes.
	return "'" . str_replace("'", "''", $label) . "'";
}

//-----------------------------------------------------------------------------
// Run as a script, not when included by test.php.
//-----------------------------------------------------------------------------

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)
{
	rewrite_main($argv);
}
