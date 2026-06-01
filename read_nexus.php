<?php

// read_nexus.php — parse a NEXUS file and emit the first/default tree as Newick.
//
//   php read_nexus.php <file.nex>          first (or default) tree as Newick
//   php read_nexus.php --list <file.nex>   list every tree (label, default flag)
//
// The Translate table, if present, is applied so the emitted Newick carries
// real taxon names rather than the integer OTU codes.

require_once __DIR__ . '/src/php/nexus.php';

$args = array_slice($argv, 1);
$list = false;

$rest = array();
foreach ($args as $a)
{
	if ($a === '--list') { $list = true; }
	else { $rest[] = $a; }
}

if (count($rest) < 1)
{
	fwrite(STDERR, "usage: php read_nexus.php [--list] <file.nex>\n");
	exit(1);
}

$path = $rest[0];
$str = @file_get_contents($path);
if ($str === false)
{
	fwrite(STDERR, "error: cannot read '$path'\n");
	exit(1);
}

$treeblock = parse_nexus($str);

if (empty($treeblock->trees))
{
	fwrite(STDERR, "error: no trees found in '$path'\n");
	exit(1);
}

$map = array();
if ($treeblock->translations && isset($treeblock->translations->translate))
{
	$map = $treeblock->translations->translate;
}

if ($list)
{
	$n = count($treeblock->trees);
	fwrite(STDERR, "$n tree(s) in '$path'\n");
	foreach ($treeblock->trees as $i => $tree)
	{
		$flag = $tree->default ? ' *' : '';
		$label = $tree->label !== '' ? $tree->label : '(unnamed)';
		echo "[$i]$flag $label\n";
	}
	exit(0);
}

$tree = nexus_pick_tree($treeblock);
echo nexus_apply_translate($tree->newick, $map) . "\n";
