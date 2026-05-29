<?php

// transform_taxhierarchy.php — for trees whose leaf labels encode the full
// taxonomic hierarchy as "Order Family Genus species sample-id" (5
// whitespace-separated tokens; the parser turned the source's underscores
// into spaces).  Reads labels one per line (the output of
// write_labels.php) and emits a 3-column TSV consumed by read_labels.php:
//
//   original \t cleaned \t lineage
//
// where cleaned = "Genus species" and lineage = "Order;Family;Genus;Genus
// species".  Labels that don't fit the 5-token pattern are silently
// skipped — those nodes stay as-is in the tree.
//
//   php write_labels.php tree.tre                              # tree_labels.txt
//   php transform_taxhierarchy.php tree_labels.txt              # tree_mapping.tsv
//   php read_labels.php tree.tre                                # tree_labelled.tre

require_once __DIR__ . '/rewrite.php';   // sibling_path()

function transform_main($argv)
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
			fwrite(STDERR, "transform_taxhierarchy.php: unknown option '$a'\n");
			exit(1);
		}
		else
		{
			$input = $a;
		}
	}

	if ($input === null)
	{
		fwrite(STDERR, "usage: php transform_taxhierarchy.php [--out=PATH] labels.txt\n");
		fwrite(STDERR, "       default output strips '_labels.txt' from the input and adds '_mapping.tsv'.\n");
		exit(1);
	}

	$content = @file_get_contents($input);
	if ($content === false)
	{
		fwrite(STDERR, "transform_taxhierarchy.php: cannot read $input\n");
		exit(1);
	}

	$body    = "original\tcleaned\tlineage\n";
	$kept    = 0;
	$skipped = 0;

	foreach (preg_split('/\r?\n/', $content) as $line)
	{
		$line = rtrim($line);
		if ($line === '') { continue; }

		$row = transform_label($line);
		if ($row === null)
		{
			$skipped++;
			continue;
		}
		$body .= $row['original'] . "\t" . $row['cleaned'] . "\t" . $row['lineage'] . "\n";
		$kept++;
	}

	$out_path = ($out !== null) ? $out : derive_mapping_path($input);

	if (file_put_contents($out_path, $body) === false)
	{
		fwrite(STDERR, "transform_taxhierarchy.php: cannot write $out_path\n");
		exit(1);
	}
	fwrite(STDERR, "transform_taxhierarchy.php: wrote $out_path ($kept transformed, $skipped skipped)\n");
}

// `<base>_labels.txt` → `<base>_mapping.tsv`; otherwise append `_mapping.tsv`
// to the stem with the same extension swapped to `.tsv`.
function derive_mapping_path($input)
{
	if (substr($input, -strlen('_labels.txt')) === '_labels.txt')
	{
		return substr($input, 0, -strlen('_labels.txt')) . '_mapping.tsv';
	}
	return sibling_path($input, '_mapping', 'tsv');
}

// Returns ['original' => ..., 'cleaned' => ..., 'lineage' => ...] when the
// label fits the 5-token pattern; otherwise null.
function transform_label($label)
{
	$tokens = preg_split('/\s+/', trim($label));
	if (count($tokens) !== 5) { return null; }

	list($order, $family, $genus, $species, $accession) = $tokens;

	// Loose sanity check: orders end in "ales" or "ida"; families end in
	// "aceae", "idae", or similar.  We just require the first three to start
	// with a capital letter and the fourth (species epithet) to start with a
	// lowercase letter — enough to reject leaves like
	// "Trichoptera 396|1h|Argentina" that happen to have 5 tokens but a
	// different structure.
	if (!preg_match('/^[A-Z]/', $order))     { return null; }
	if (!preg_match('/^[A-Z]/', $family))    { return null; }
	if (!preg_match('/^[A-Z]/', $genus))     { return null; }
	if (!preg_match('/^[a-z]/', $species))   { return null; }

	$cleaned = $genus . ' ' . $species;

	$lineage = implode(';', array(
		$order,
		$family,
		$genus,
		$cleaned,
	));

	return array(
		'original' => $label,
		'cleaned'  => $cleaned,
		'lineage'  => $lineage,
	);
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)
{
	transform_main($argv);
}
