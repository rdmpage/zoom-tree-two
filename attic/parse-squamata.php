<?php

// parse-squamata.php — turn the Pyron et al. 2013 classification text into a
// two-column TSV that any consumer (notably a future label-from-file mode of
// label.php) can read without rewriting this messy grammar.
//
//   php parse-squamata.php test-data/Squamata.txt > test-data/Squamata.tsv
//   cat Squamata.txt | php parse-squamata.php
//
// Output:
//   <taxon>\t<comma-delimited list of immediate members>
//
// Members can be any depth (genera, subfamilies, families) — the TSV records
// one level of decomposition per row.  Forward references are expected and
// fine: Squamata's row mentions Gekkota before Gekkota's own row is emitted.
// Readers should slurp the whole file before resolving.
//
// Grammar (best-effort, derived from the Pyron file):
//
//   document    := title-line (blank+ block)+
//   title-line  := <name>            // e.g. "Squamata"  -> row of all block-tops
//   block       := flat-block
//                | named-block
//   flat-block  := <name> "(" <members> ")"          // "Dibamidae (Anelytropsis, Dibamus)"
//   named-block := <name>['(comment)']  \n  families
//   families    := family ( ";" family )*
//   family      := <name> [ "(" <members> ")" ]
//                  ( "," subfamily )*
//                | <name> "incertae sedis" "(" <loose-members> ")"
//                  ( "," subfamily )*
//   subfamily   := <name> "(" <members> ")"
//
// A family with subfamilies emits a row whose members are the subfamily names
// PLUS any incertae-sedis genera as siblings — they're all immediate children
// from a tree-labelling perspective.

//-----------------------------------------------------------------------------
// I/O
//-----------------------------------------------------------------------------

function read_input($argv)
{
	if (count($argv) >= 2)
	{
		$s = @file_get_contents($argv[1]);
		if ($s === false)
		{
			fwrite(STDERR, "parse-squamata.php: cannot read " . $argv[1] . "\n");
			exit(1);
		}
		return $s;
	}
	return stream_get_contents(STDIN);
}

//-----------------------------------------------------------------------------
// Helpers
//-----------------------------------------------------------------------------

// Split $s by single-character $delim, but only at depth-0 (outside parens).
function split_top_level($s, $delim)
{
	$out   = array();
	$depth = 0;
	$start = 0;
	$n     = strlen($s);

	for ($i = 0; $i < $n; $i++)
	{
		$c = $s[$i];
		if      ($c === '(')                      { $depth++; }
		else if ($c === ')')                      { if ($depth > 0) { $depth--; } }
		else if ($c === $delim && $depth === 0)
		{
			$out[] = substr($s, $start, $i - $start);
			$start = $i + 1;
		}
	}
	if ($start < $n)
	{
		$out[] = substr($s, $start);
	}
	return $out;
}

// "Name (m1, m2, ...)" -> [name, [m1, m2, ...]]; null if no match.  Inner
// member list is comma-split; depth-tracking isn't needed because Squamata.txt
// never nests parens inside member lists.
function parse_name_parens($s)
{
	$s = trim($s);
	if (preg_match('/^(.+?)\s*\(([^()]*)\)\s*$/', $s, $m))
	{
		$name    = trim($m[1]);
		$members = array_filter(array_map('trim', explode(',', $m[2])), 'strlen');
		return array($name, array_values($members));
	}
	return null;
}

// Drop a trailing "(comment)" from a block header, e.g.
// "Lacertoidea (including Amphisbaenia)" -> "Lacertoidea".
function strip_trailing_paren($s)
{
	return trim(preg_replace('/\s*\([^()]*\)\s*$/', '', $s));
}

// Preserve insertion order of $rows: PHP arrays do this naturally.
function emit_row(&$rows, $name, $members)
{
	if ($name === '' || $name === null) { return; }
	$rows[$name] = array_values($members);
}

//-----------------------------------------------------------------------------
// Grammar
//-----------------------------------------------------------------------------

function process_squamata($text)
{
	$rows = array();

	// Blocks separated by one or more blank lines.
	$blocks = preg_split('/\n[ \t]*\n+/', trim($text));
	if (empty($blocks)) { return $rows; }

	$title         = strip_trailing_paren(trim($blocks[0]));
	$title_members = array();

	for ($b = 1; $b < count($blocks); $b++)
	{
		$top = process_block(trim($blocks[$b]), $rows);
		if ($top !== null)
		{
			$title_members[] = $top;
		}
	}

	emit_row($rows, $title, $title_members);
	return $rows;
}

function process_block($block, &$rows)
{
	$lines = preg_split('/\r?\n/', $block);
	$lines = array_values(array_filter(array_map('trim', $lines), 'strlen'));
	if (empty($lines)) { return null; }

	// Flat block: a single line that's "Name (member1, member2, ...)".
	if (count($lines) === 1 && strpos($lines[0], '(') !== false)
	{
		$parsed = parse_name_parens($lines[0]);
		if ($parsed !== null)
		{
			emit_row($rows, $parsed[0], $parsed[1]);
			return $parsed[0];
		}
	}

	// Named block: first line is the higher-group's name (possibly with a
	// trailing parenthetical comment); subsequent lines list its families.
	$top_name = strip_trailing_paren($lines[0]);
	$content  = implode(' ', array_slice($lines, 1));

	$family_names = array();
	foreach (split_top_level($content, ';') as $item)
	{
		$family = process_family($item, $rows);
		if ($family !== null)
		{
			$family_names[] = $family;
		}
	}

	emit_row($rows, $top_name, $family_names);
	return $top_name;
}

function process_family($item, &$rows)
{
	$item = trim($item);
	if ($item === '') { return null; }

	// First top-level comma splits off the family from its subfamilies.
	$parts = array_map('trim', split_top_level($item, ','));
	$first = array_shift($parts);

	$first_parsed = parse_name_parens($first);

	$family_name     = null;
	$incertae_genera = array();
	$flat_genera     = null;

	if ($first_parsed !== null)
	{
		$np    = $first_parsed[0];
		$inner = $first_parsed[1];

		if (preg_match('/^(.+?)\s+incertae\s+sedis$/i', $np, $m))
		{
			$family_name     = trim($m[1]);
			$incertae_genera = $inner;
		}
		else if (empty($parts))
		{
			// Flat family — "Familyname (G1, G2, ...)" with nothing after.
			$family_name = $np;
			$flat_genera = $inner;
		}
		else
		{
			// Has direct genera AND further subfamilies — treat direct ones
			// like incertae-sedis members.
			$family_name     = $np;
			$incertae_genera = $inner;
		}
	}
	else
	{
		// "Familyname" with no parens — subfamilies follow.
		$family_name = $first;
	}

	if ($flat_genera !== null)
	{
		emit_row($rows, $family_name, $flat_genera);
		return $family_name;
	}

	$sub_names = array();
	foreach ($parts as $sub)
	{
		$sp = parse_name_parens($sub);
		if ($sp === null) { continue; }
		emit_row($rows, $sp[0], $sp[1]);
		$sub_names[] = $sp[0];
	}

	emit_row($rows, $family_name, array_merge($sub_names, $incertae_genera));
	return $family_name;
}

//-----------------------------------------------------------------------------
// Main
//-----------------------------------------------------------------------------

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)
{
	$text = read_input($argv);
	$rows = process_squamata($text);

	echo "taxon\tmembers\n";
	foreach ($rows as $name => $members)
	{
		echo $name . "\t" . implode(',', $members) . "\n";
	}

	fwrite(STDERR, "parse-squamata.php: " . count($rows) . " taxa\n");
}
