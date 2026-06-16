<?php

// make_binomial_lineage.php — build a stage-1 lineage TSV for a tree whose leaf
// labels are bare binomials (Genus_species), with NO higher taxonomy embedded.
//
//   php make_binomial_lineage.php tree.tre > lineage.tsv
//
// Emits the columns label_lineage.php consumes (original_label, new_label,
// lineage) plus scratch genus/species columns for later expansion. The lineage
// here is intentionally shallow:
//
//   g__Genus;s__Genus species
//
// so internal nodes get genus labels at most (the colour layer reads ~flat
// until family/order are layered in from an external taxonomy — that just means
// regenerating this file with deeper lineage strings; the next stage is
// unchanged).
//
// Labels are taken from the PROJECT PARSER so original_label matches exactly
// what label_lineage.php will key against (the source '_' is already a space).

require_once __DIR__ . '/../src/php/node.php';
require_once __DIR__ . '/../src/php/tree.php';
require_once __DIR__ . '/../src/php/node_iterator.php';
require_once __DIR__ . '/../src/php/tree-parse.php';
require_once __DIR__ . '/../src/php/read-tree.php';   // read_tree_newick (sniffs NEXUS/Newick)
require_once __DIR__ . '/../build.php';               // strip_newick_comments

if ($argc < 2)
{
	fwrite(STDERR, "usage: php make_binomial_lineage.php tree.tre > lineage.tsv\n");
	exit(1);
}

$tree_path = $argv[1];

$newick = read_tree_newick($tree_path);
if ($newick === false) { fwrite(STDERR, "make_binomial_lineage.php: cannot read $tree_path\n"); exit(1); }
if ($newick === null)  { fwrite(STDERR, "make_binomial_lineage.php: no tree found in $tree_path\n"); exit(1); }

$newick = strip_newick_comments($newick);
$t = parse_newick($newick);

$keys = array('original_label', 'new_label', 'genus', 'species', 'lineage');
echo implode("\t", $keys) . "\n";

$leaves = 0; $binomial = 0;

$it = new NodeIterator($t->GetRoot());
$q  = $it->Begin();
while ($q != null)
{
	if ($q->IsLeaf())
	{
		$leaves++;
		$label = $q->GetLabel();

		// First whitespace-delimited token is the genus; the whole label is the
		// species binomial. NB the parser has already turned source '_' -> ' '.
		$parts = preg_split('/\s+/', trim($label));
		$genus = isset($parts[0]) ? $parts[0] : '';

		$lineage = array();
		$species = '';
		if ($genus !== '')
		{
			$lineage[] = 'g__' . $genus;
		}
		if (count($parts) >= 2)
		{
			$binomial++;
			$species = $label;                 // e.g. "Sabatinca heighwayi"
			$lineage[] = 's__' . $species;
		}

		$row = array(
			$label,                            // original_label (matches the tree)
			$label,                            // new_label (unchanged)
			$genus,
			$species,
			implode(';', $lineage),
		);
		echo implode("\t", $row) . "\n";
	}
	$q = $it->Next();
}

fwrite(STDERR, "make_binomial_lineage.php: $leaves leaves ($binomial binomial, " . ($leaves - $binomial) . " genus-only)\n");
