<?php

// transform_genus_species.php — for trees with leaf labels of the form
// `Genus_species` (the simplest and most common biological binomial).
//
// After the Newick parser turns `_` into ` `, the labels look like
// "Sphenodon punctatus", "Phyllurus kabikabi", …  Each row maps:
//
//   cleaned  =  Genus species          (unchanged)
//   lineage  =  Genus ; Genus species
//
// So read_labels.php gets two levels to work with: every clade of
// same-genus leaves picks up the Genus name, and every clade of
// same-species samples picks up the full binomial.
//
// Labels that don't look like a binomial are skipped — they'll keep their
// original label in the tree.

require_once __DIR__ . '/rewrite.php';   // sibling_path()

function genus_species_main($argv)
{
	$input = null;
	$out   = null;

	for ($i = 1; $i < count($argv); $i++)
	{
		$a = $argv[$i];
		if (preg_match('/^--out=(.+)$/', $a, $m))     { $out = $m[1]; }
		else if ($a === '-h' || $a === '--help')
		{
			fwrite(STDERR, file_get_contents(__FILE__, false, null, 0, 1000));
			exit(0);
		}
		else if ($a !== '' && $a[0] === '-')
		{
			fwrite(STDERR, "transform_genus_species.php: unknown option '$a'\n");
			exit(1);
		}
		else
		{
			$input = $a;
		}
	}

	if ($input === null)
	{
		fwrite(STDERR, "usage: php transform_genus_species.php [--out=PATH] labels.txt\n");
		exit(1);
	}

	$content = @file_get_contents($input);
	if ($content === false)
	{
		fwrite(STDERR, "transform_genus_species.php: cannot read $input\n");
		exit(1);
	}

	$body    = "original\tcleaned\tlineage\n";
	$kept    = 0;
	$skipped = 0;

	foreach (preg_split('/\r?\n/', $content) as $line)
	{
		$line = rtrim($line);
		if ($line === '') { continue; }

		$row = genus_species_label($line);
		if ($row === null)
		{
			$skipped++;
			continue;
		}
		$body .= $row['original'] . "\t" . $row['cleaned'] . "\t" . $row['lineage'] . "\n";
		$kept++;
	}

	$out_path = ($out !== null)
		? $out
		: (substr($input, -strlen('_labels.txt')) === '_labels.txt'
			? substr($input, 0, -strlen('_labels.txt')) . '_mapping.tsv'
			: sibling_path($input, '_mapping', 'tsv'));

	if (file_put_contents($out_path, $body) === false)
	{
		fwrite(STDERR, "transform_genus_species.php: cannot write $out_path\n");
		exit(1);
	}
	fwrite(STDERR, "transform_genus_species.php: wrote $out_path ($kept transformed, $skipped skipped)\n");
}

// Returns ['original' => ..., 'cleaned' => ..., 'lineage' => ...] when the
// label is a clean Genus + species binomial; otherwise null.
function genus_species_label($label)
{
	$tokens = preg_split('/\s+/', trim($label));
	if (count($tokens) !== 2) { return null; }

	list($genus, $species) = $tokens;
	if (!preg_match('/^[A-Z][a-z]+$/', $genus))   { return null; }
	if (!preg_match('/^[a-z]/',         $species)) { return null; }

	$cleaned = $genus . ' ' . $species;
	$lineage = $genus . ';' . $cleaned;

	return array(
		'original' => $label,
		'cleaned'  => $cleaned,
		'lineage'  => $lineage,
	);
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)
{
	genus_species_main($argv);
}
