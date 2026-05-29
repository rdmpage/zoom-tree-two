<?php

// Invariant-based tests for build_v1_json().
//
//   php test.php       # exit 0 = green, non-zero = at least one failure
//
// No PHPUnit, no subprocess.  We include build.php (which only runs its CLI
// main when invoked as a script) and call build_v1_json() directly.

require_once __DIR__ . '/build.php';

$PASS = 0;
$FAIL = 0;

function ok($cond, $name)
{
	global $PASS, $FAIL;
	if ($cond)
	{
		$PASS++;
	}
	else
	{
		$FAIL++;
		echo "FAIL  $name\n";
	}
}

function eq($actual, $expected, $name)
{
	global $PASS, $FAIL;
	if ($actual === $expected)
	{
		$PASS++;
	}
	else
	{
		$FAIL++;
		echo "FAIL  $name\n";
		echo "        expected: " . var_export($expected, true) . "\n";
		echo "        got:      " . var_export($actual, true) . "\n";
	}
}

//-----------------------------------------------------------------------------
// Generic invariants — should hold for any tree built by build_v1_json().
//-----------------------------------------------------------------------------

function check_invariants($name, $tree)
{
	$n  = $tree['n'];
	$tw = $tree['config']['tree_width'];

	// All parallel arrays length n.
	foreach (array('labels','parent','x','max_x','first_zoom','style','child_l','child_r','crossings') as $k)
	{
		eq(count($tree[$k]), $n, "$name: $k length == n");
	}
	eq(count($tree['inorder']), $n, "$name: inorder length == n");

	// Root invariants.
	eq($tree['parent'][0],     -1, "$name: parent[root] == -1");
	eq($tree['x'][0],         0.0, "$name: x[root] == 0");
	eq($tree['style'][0],       1, "$name: style[root] == 1 (no half-bar)");
	eq($tree['first_zoom'][0],  1, "$name: first_zoom[root] == 1");

	// inorder is a permutation of [0, n).
	$sorted = $tree['inorder'];
	sort($sorted);
	eq($sorted, range(0, $n - 1), "$name: inorder is a permutation of [0, n)");

	// Walk every node: binary, parent <-> child consistent, style by side.
	$num_leaves     = 0;
	$bad_leaf_cr    = array();
	$bad_internal   = array();
	$bad_parent_lnk = array();
	$bad_style_l    = array();
	$bad_style_r    = array();

	for ($i = 0; $i < $n; $i++)
	{
		$cl = $tree['child_l'][$i];
		$cr = $tree['child_r'][$i];

		if ($cl === -1)
		{
			$num_leaves++;
			if ($cr !== -1) { $bad_leaf_cr[] = $i; }
		}
		else
		{
			if ($cr === -1) { $bad_internal[] = $i; continue; }
			if ($tree['parent'][$cl] !== $i) { $bad_parent_lnk[] = $cl; }
			if ($tree['parent'][$cr] !== $i) { $bad_parent_lnk[] = $cr; }
			if ($tree['style'][$cl] !== 0)   { $bad_style_l[]    = $cl; }
			if ($tree['style'][$cr] !== 2)   { $bad_style_r[]    = $cr; }
		}
	}
	ok(empty($bad_leaf_cr),    "$name: every leaf has child_r == -1");
	ok(empty($bad_internal),   "$name: every internal has child_r != -1 (binary)");
	ok(empty($bad_parent_lnk), "$name: parent[child] points back to its parent for every edge");
	ok(empty($bad_style_l),    "$name: every left child has style == 0");
	ok(empty($bad_style_r),    "$name: every right child has style == 2");
	eq($num_leaves, intdiv($n + 1, 2), "$name: num_leaves == (n+1)/2");

	// No orphan internals — every node's first_zoom >= its parent's first_zoom.
	$orphans = array();
	for ($i = 1; $i < $n; $i++)
	{
		$p = $tree['parent'][$i];
		if ($tree['first_zoom'][$i] < $tree['first_zoom'][$p])
		{
			$orphans[] = $i;
		}
	}
	ok(empty($orphans), "$name: no node visible before its parent");

	// x and max_x in [0, tree_width].
	$bad_x = $bad_max_x = array();
	for ($i = 0; $i < $n; $i++)
	{
		if ($tree['x'][$i]     < 0 || $tree['x'][$i]     > $tw) { $bad_x[]     = $i; }
		if ($tree['max_x'][$i] < 0 || $tree['max_x'][$i] > $tw) { $bad_max_x[] = $i; }
	}
	ok(empty($bad_x),     "$name: all x in [0, $tw]");
	ok(empty($bad_max_x), "$name: all max_x in [0, $tw]");

	// crossings: every entry is a valid node id, and never includes self or
	// the immediate parent (those bar endpoints come from style, not crossings).
	$bad_cross = array();
	for ($i = 0; $i < $n; $i++)
	{
		$p = $tree['parent'][$i];
		foreach ($tree['crossings'][$i] as $a)
		{
			if ($a < 0 || $a >= $n || $a === $i || $a === $p)
			{
				$bad_cross[] = $i;
				break;
			}
		}
	}
	ok(empty($bad_cross), "$name: crossings hold valid ids and exclude self & immediate parent");
}

//-----------------------------------------------------------------------------
// Tree: balanced-8  =  (((a,b),(c,d)),((e,f),(g,h)));  with branch lengths.
//-----------------------------------------------------------------------------

$newick = file_get_contents(__DIR__ . '/test-data/balanced-8.nwk');
$tree   = build_v1_json($newick);

check_invariants('balanced-8', $tree);

eq($tree['n'],                  15, 'balanced-8: n == 15');
eq($tree['config']['max_zoom'],  3, 'balanced-8: max_zoom == 3 (sqrt(2) growth factor)');

// Labels include each leaf exactly once.
$leaf_labels = array();
for ($i = 0; $i < $tree['n']; $i++)
{
	if ($tree['child_l'][$i] === -1)
	{
		$leaf_labels[] = $tree['labels'][$i];
	}
}
sort($leaf_labels);
eq($leaf_labels, array('a','b','c','d','e','f','g','h'), 'balanced-8: leaf labels == a..h');

//-----------------------------------------------------------------------------
// Comment-stripping: same logical tree with/without [...] annotations must
// build to the same JSON.
//-----------------------------------------------------------------------------

$annotated =
	"(((a[ignore]:1,b:1)[clade1]:1,(c[boot 99]:1,d:1)[nested[inside]]:1):1," .
	"((e:1,f:1)[node_x]:1,(g:1,h:1):1)[clade2]:1)[root];\n";
$treeA = build_v1_json($annotated);

eq($treeA['n'],          $tree['n'],          'comments stripped: same n');
eq($treeA['labels'],     $tree['labels'],     'comments stripped: same labels');
eq($treeA['parent'],     $tree['parent'],     'comments stripped: same parent');
eq($treeA['inorder'],    $tree['inorder'],    'comments stripped: same inorder');
eq($treeA['first_zoom'], $tree['first_zoom'], 'comments stripped: same first_zoom');
eq($treeA['id'],         $tree['id'],         'comments stripped: same id (hash of stripped Newick)');

// Plain trees (no metacomments) have all-empty support arrays.
ok(is_array($tree['bootstrap']) && count($tree['bootstrap']) === $tree['n'], 'bootstrap: array of length n');
ok(is_array($tree['posterior']) && count($tree['posterior']) === $tree['n'], 'posterior: array of length n');
$bs_nonempty = array_filter($tree['bootstrap'], function ($v) { return $v !== ''; });
eq(count($bs_nonempty), 0, 'balanced-8: bootstrap all empty (no metacomments in input)');

//-----------------------------------------------------------------------------
// BEAST-style [&bootstrap=NN] metacomments survive build.php → bootstrap[].
//-----------------------------------------------------------------------------

$with_boot =
	"(((a:1,b:1)[&bootstrap=90]:1,(c:1,d:1)[&bootstrap=80]:1)[&bootstrap=95]:1," .
	"((e:1,f:1)[&bootstrap=70]:1,(g:1,h:1)[&bootstrap=100]:1)[&bootstrap=85]:1);\n";
$treeB = build_v1_json($with_boot);

eq($treeB['n'], 15, 'with bootstrap: same topology (n=15)');
$bs = $treeB['bootstrap'];
$bs_nonempty = array_filter($bs, function ($v) { return $v !== ''; });
sort($bs_nonempty);
eq(array_values($bs_nonempty), array('70','80','85','90','95','100'), 'with bootstrap: 6 internal supports captured');

// IDs are assigned in preorder (root=0).  Spot-check the root has no support
// and at least one leaf has none.
eq($bs[0], '', 'with bootstrap: root[0] has no support');

// Both arrays present and same length n.
eq(count($treeB['bootstrap']), $treeB['n'], 'with bootstrap: bootstrap length == n');
eq(count($treeB['posterior']), $treeB['n'], 'with bootstrap: posterior length == n');

// posterior is untouched in a bootstrap-only tree
$post_nonempty = array_filter($treeB['posterior'], function ($v) { return $v !== ''; });
eq(count($post_nonempty), 0, 'with bootstrap: posterior all empty');

//-----------------------------------------------------------------------------
// Mixed [&bootstrap=N,posterior=P] metacomments on the same node.
//-----------------------------------------------------------------------------

$mixed = "(((a:1,b:1)[&bootstrap=95,posterior=0.98]:1,(c:1,d:1):1):1,(e:1,f:1):1);\n";
$treeM = build_v1_json($mixed);

$bs_m   = array_filter($treeM['bootstrap'], function ($v) { return $v !== ''; });
$post_m = array_filter($treeM['posterior'], function ($v) { return $v !== ''; });
eq(array_values($bs_m),   array('95'),   'mixed: one bootstrap value captured');
eq(array_values($post_m), array('0.98'), 'mixed: one posterior value captured');

//-----------------------------------------------------------------------------

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
