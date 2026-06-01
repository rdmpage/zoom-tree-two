<?php

// read-tree.php — front door for reading a tree file regardless of format.
//
// We accept both Newick and NEXUS.  The rule is the one suggested for the
// project: sniff the file, and if it looks like NEXUS convert its first/default
// tree to Newick; otherwise pass the Newick through unchanged.  Everything
// downstream (strip_newick_comments, parse_newick) then works the same way.

require_once(dirname(__FILE__) . '/nexus.php');   // nexus_to_newick (pulls in scanner.php)

//-----------------------------------------------------------------------------
// True if $s looks like a NEXUS file: a `#NEXUS` token at the very start,
// ignoring a UTF-8 BOM and leading whitespace.  Newick files start with '('.
function looks_like_nexus($s)
{
	// strip UTF-8 BOM
	if (substr($s, 0, 3) === "\xEF\xBB\xBF") { $s = substr($s, 3); }
	$s = ltrim($s);
	return strncasecmp($s, '#NEXUS', 6) === 0;
}

//-----------------------------------------------------------------------------
// Tree-file contents in, a Newick string out.  NEXUS is reduced to its
// first/default tree (with any Translate table applied); Newick is returned
// as-is.  Returns null if the contents are NEXUS but contain no tree.
function tree_contents_to_newick($s)
{
	if (looks_like_nexus($s))
	{
		return nexus_to_newick($s);
	}
	return $s;
}

//-----------------------------------------------------------------------------
// Read a tree file from disk and return Newick.  On error writes to STDERR and
// returns false (cannot read) or null (NEXUS with no tree), mirroring the
// existing call sites' expectations.
function read_tree_newick($path)
{
	$s = @file_get_contents($path);
	if ($s === false) { return false; }
	return tree_contents_to_newick($s);
}
