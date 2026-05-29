<?php

// write_labels.php — emit every non-empty label in the tree, one per line,
// sorted and de-duplicated.  Companion to read_labels.php.
//
//   php write_labels.php tree.nwk    > labels.txt
//   cat tree.nwk | php write_labels.php
//
// Leaves and internal nodes are both included.  Use the output as the input
// to a tree-specific transform script that produces the
// (original\tcleaned\tlineage) mapping consumed by read_labels.php.

require_once __DIR__ . '/src/php/node.php';
require_once __DIR__ . '/src/php/tree.php';
require_once __DIR__ . '/src/php/node_iterator.php';
require_once __DIR__ . '/src/php/tree-parse.php';
require_once __DIR__ . '/build.php';     // strip_newick_comments
require_once __DIR__ . '/rewrite.php';   // sibling_path

function write_labels_main($argv)
{
	$input = null;
	$out   = null;

	for ($i = 1; $i < count($argv); $i++)
	{
		$a = $argv[$i];
		if (preg_match('/^--out=(.+)$/', $a, $m))     { $out = $m[1]; }
		else if ($a === '-h' || $a === '--help')
		{
			fwrite(STDERR, file_get_contents(__FILE__, false, null, 0, 1200));
			exit(0);
		}
		else if ($a !== '' && $a[0] === '-')
		{
			fwrite(STDERR, "write_labels.php: unknown option '$a'\n");
			exit(1);
		}
		else
		{
			$input = $a;
		}
	}

	if ($input === null)
	{
		fwrite(STDERR, "usage: php write_labels.php [--out=PATH] tree.tre\n");
		fwrite(STDERR, "       default output is <tree>_labels.txt next to the input.\n");
		exit(1);
	}

	$newick = @file_get_contents($input);
	if ($newick === false)
	{
		fwrite(STDERR, "write_labels.php: cannot read $input\n");
		exit(1);
	}

	$newick = strip_newick_comments($newick);
	$t = parse_newick($newick);

	$labels = collect_labels($t);
	$labels = array_values(array_unique($labels));
	sort($labels);

	$body = implode("\n", $labels) . "\n";
	$out_path = ($out !== null) ? $out : sibling_path($input, '_labels', 'txt');

	if (file_put_contents($out_path, $body) === false)
	{
		fwrite(STDERR, "write_labels.php: cannot write $out_path\n");
		exit(1);
	}
	fwrite(STDERR, "write_labels.php: wrote $out_path (" . count($labels) . " unique labels)\n");
}

function collect_labels($t)
{
	$out = array();
	$it = new NodeIterator($t->GetRoot());
	$q  = $it->Begin();
	while ($q != null)
	{
		$lab = $q->GetLabel();
		if ($lab !== '' && $lab !== null)
		{
			$out[] = $lab;
		}
		$q = $it->Next();
	}
	return $out;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)
{
	write_labels_main($argv);
}
