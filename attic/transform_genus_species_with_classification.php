<?php

// transform_genus_species_with_classification.php — Genus_species labels +
// an external `taxon\tmembers` TSV that names higher ranks (families,
// orders, etc.).  For each `Genus species` leaf we look up the genus in
// the classification and walk up the parent chain to build a full lineage.
//
//   php transform_genus_species_with_classification.php \
//       --classification=test-data/Squamata.tsv \
//       test-data/12862_..._labels.txt
//
// Output (sibling `_mapping.tsv` by default):
//
//   original \t cleaned \t lineage
//
// where cleaned = "Genus species" and lineage is `Squamata;Gekkota;
// Carphodactylidae;Phyllurus;Phyllurus kabikabi` — i.e. every ancestor of
// the genus in the classification, followed by the genus itself and the
// binomial.  If the genus isn't in the classification, the lineage falls
// back to `Genus;Genus species` (same as transform_genus_species.php).

require_once __DIR__ . '/rewrite.php';   // sibling_path()

function gsc_main($argv)
{
	$input               = null;
	$classification_path = null;
	$out                 = null;

	for ($i = 1; $i < count($argv); $i++)
	{
		$a = $argv[$i];
		if (preg_match('/^--classification=(.+)$/', $a, $m)) { $classification_path = $m[1]; }
		else if (preg_match('/^--out=(.+)$/', $a, $m))        { $out = $m[1]; }
		else if ($a === '-h' || $a === '--help')
		{
			fwrite(STDERR, file_get_contents(__FILE__, false, null, 0, 1400));
			exit(0);
		}
		else if ($a !== '' && $a[0] === '-')
		{
			fwrite(STDERR, "transform_genus_species_with_classification.php: unknown option '$a'\n");
			exit(1);
		}
		else
		{
			$input = $a;
		}
	}

	if ($input === null || $classification_path === null)
	{
		fwrite(STDERR, "usage: php transform_genus_species_with_classification.php"
			. " --classification=PATH [--out=PATH] labels.txt\n");
		exit(1);
	}

	$taxa   = read_classification($classification_path);
	$parent = build_parent_index($taxa);

	$content = @file_get_contents($input);
	if ($content === false)
	{
		fwrite(STDERR, "transform_genus_species_with_classification.php: cannot read $input\n");
		exit(1);
	}

	$body                  = "original\tcleaned\tlineage\n";
	$kept                  = 0;
	$skipped               = 0;
	$with_classification   = 0;
	$without_classification = 0;

	foreach (preg_split('/\r?\n/', $content) as $line)
	{
		$line = rtrim($line);
		if ($line === '') { continue; }

		$tokens = preg_split('/\s+/', trim($line));
		if (count($tokens) !== 2) { $skipped++; continue; }

		list($genus, $species) = $tokens;
		if (!preg_match('/^[A-Z][a-z]+$/', $genus))   { $skipped++; continue; }
		if (!preg_match('/^[a-z]/',         $species)) { $skipped++; continue; }

		$cleaned = $genus . ' ' . $species;

		$higher = lineage_for_taxon($genus, $parent);
		if (!empty($higher)) { $with_classification++; }
		else                 { $without_classification++; }

		$lineage_parts = $higher;
		$lineage_parts[] = $genus;
		$lineage_parts[] = $cleaned;
		$lineage = implode(';', $lineage_parts);

		$body .= $line . "\t" . $cleaned . "\t" . $lineage . "\n";
		$kept++;
	}

	$out_path = ($out !== null)
		? $out
		: (substr($input, -strlen('_labels.txt')) === '_labels.txt'
			? substr($input, 0, -strlen('_labels.txt')) . '_mapping.tsv'
			: sibling_path($input, '_mapping', 'tsv'));

	if (file_put_contents($out_path, $body) === false)
	{
		fwrite(STDERR, "transform_genus_species_with_classification.php: cannot write $out_path\n");
		exit(1);
	}

	fwrite(STDERR, sprintf(
		"transform_genus_species_with_classification.php: wrote %s (%d transformed: %d with classification, %d without; %d skipped)\n",
		$out_path, $kept, $with_classification, $without_classification, $skipped
	));
}

// Reads a `taxon\tmembers` TSV (header optional).  Returns
// array(taxon => [member1, member2, ...]).
function read_classification($path)
{
	$content = @file_get_contents($path);
	if ($content === false)
	{
		fwrite(STDERR, "transform_genus_species_with_classification.php: cannot read $path\n");
		exit(1);
	}

	$taxa = array();
	$saw_header = false;
	foreach (preg_split('/\r?\n/', $content) as $line)
	{
		$line = rtrim($line);
		if ($line === '') { continue; }

		$parts = explode("\t", $line, 2);
		if (count($parts) < 2) { continue; }

		$name = trim($parts[0]);
		if (!$saw_header)
		{
			$saw_header = true;
			if (strtolower($name) === 'taxon') { continue; }
		}

		$members = array_values(array_filter(
			array_map('trim', explode(',', $parts[1])),
			'strlen'
		));
		$taxa[$name] = $members;
	}
	return $taxa;
}

// Reverse-index: member -> parent taxon.  Assumes each member appears in at
// most one taxon's member list; if not, the last occurrence wins.
function build_parent_index($taxa)
{
	$parent = array();
	foreach ($taxa as $name => $members)
	{
		foreach ($members as $m)
		{
			$parent[$m] = $name;
		}
	}
	return $parent;
}

// Walk parent chain upward from $taxon.  Returns ancestors highest-first
// (so they're already in lineage display order).  Empty array if no
// classification entry.
function lineage_for_taxon($taxon, $parent_index)
{
	$chain = array();
	$cur   = $taxon;
	$seen  = array();
	while (isset($parent_index[$cur]) && !isset($seen[$cur]))
	{
		$seen[$cur] = true;
		$cur = $parent_index[$cur];
		$chain[] = $cur;
	}
	return array_reverse($chain);
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)
{
	gsc_main($argv);
}
