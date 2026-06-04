<?php

// tree-colors.php — Tree Colors (Tennekes & de Jonge 2014) over the TAXONOMY.
//
// The phylogeny is binary and deep, so feeding the topology to Tree Colors
// collapses the hue range within ~8 levels.  Instead we colour the *taxonomy*
// implied by the internal-node labels (o__/f__/g__/s__ …): a shallow, n-ary
// tree — exactly what Tree Colors was designed for.
//
//   - Hue encodes branch, assigned recursively over the taxonomy and PERMUTED
//     among siblings so adjacent groups differ (the paper's perm=true).  Hue is
//     CAPPED at a depth (default 2 = family here): taxa below the cap inherit
//     their family's hue, so a family reads as one colour.
//   - Depth (taxonomic rank) is encoded by luminance/chroma (subtractive:
//     L falls, C rises with depth) — so genus/species within a family share the
//     family hue but deepen, which is the "inherit + deepen on zoom-in" cue.
//
// Each phylogeny node takes the colour of its deepest classified ancestor.
// Nodes with no classified ancestor get '' (the viewer falls back to its
// default ink), so unlabelled trees are unaffected.
//
// Hue 'fraction' (f) and even-branch 'reversal' from the paper are omitted:
// because a capped taxon's hue is the MIDPOINT of its slice, neither changes
// the result here — only the sibling permutation and the equal split do.

//-----------------------------------------------------------------------------
// Per-depth luminance / chroma (subtractive method, paper defaults).
function _tc_L($d) { if ($d < 1) { $d = 1; } return max(30, min(85, 70 + ($d - 1) * (-10))); }
function _tc_C($d) { if ($d < 1) { $d = 1; } return max(0,  min(90, 60 + ($d - 1) * 5));   }

//-----------------------------------------------------------------------------
// Sibling permutation order (paper §3.1.1): spread N siblings so adjacent ones
// land far apart on the hue circle.  Returns a permutation of [0..N-1].
function tc_perm_order($n)
{
	if ($n <= 2) { return range(0, $n - 1); }
	if ($n == 3) { return array(0, 2, 1); }
	if ($n == 4) { return array(0, 2, 1, 3); }

	$step = max(1, (int) round($n * 2 / 5));   // ~144° in index units
	$seen = array_fill(0, $n, false);
	$out  = array();
	$idx  = 0;
	while (count($out) < $n)
	{
		while ($seen[$idx]) { $idx = ($idx + 1) % $n; }
		$out[] = $idx;
		$seen[$idx] = true;
		$idx = ($idx + $step) % $n;
	}
	return $out;
}

//-----------------------------------------------------------------------------
// HCL (polar CIELUV / LCh_uv) -> linear sRGB (0..1, possibly out of gamut).
function _tc_hcl_linrgb($L, $C, $H)
{
	$hr = deg2rad($H);
	$U  = $C * cos($hr);
	$V  = $C * sin($hr);

	// D65 white point
	$Xn = 95.047; $Yn = 100.0; $Zn = 108.883;
	$un = (4 * $Xn) / ($Xn + 15 * $Yn + 3 * $Zn);
	$vn = (9 * $Yn) / ($Xn + 15 * $Yn + 3 * $Zn);

	if ($L <= 0) { return array(0.0, 0.0, 0.0); }

	$Y = ($L > 8) ? $Yn * pow(($L + 16) / 116, 3) : $Yn * $L * pow(3 / 29, 3);
	$u = $U / (13 * $L) + $un;
	$v = $V / (13 * $L) + $vn;
	$X = $Y * (9 * $u) / (4 * $v);
	$Z = $Y * (12 - 3 * $u - 20 * $v) / (4 * $v);

	$x = $X / 100; $y = $Y / 100; $z = $Z / 100;
	$r =  3.2404542 * $x - 1.5371385 * $y - 0.4985314 * $z;
	$g = -0.9692660 * $x + 1.8760108 * $y + 0.0415560 * $z;
	$b =  0.0556434 * $x - 0.2040259 * $y + 1.0572252 * $z;
	return array($r, $g, $b);
}

function _tc_gamma($c)
{
	$c = max(0.0, min(1.0, $c));
	return ($c <= 0.0031308) ? 12.92 * $c : 1.055 * pow($c, 1 / 2.4) - 0.055;
}

function _tc_in_gamut($rgb)
{
	foreach ($rgb as $c) { if ($c < -0.0001 || $c > 1.0001) { return false; } }
	return true;
}

// HCL -> "#rrggbb", reducing chroma until the colour fits the sRGB gamut.
function tc_hcl_to_hex($L, $C, $H)
{
	$c = $C;
	while ($c > 0)
	{
		if (_tc_in_gamut(_tc_hcl_linrgb($L, $c, $H))) { break; }
		$c -= 1;
	}
	$rgb = _tc_hcl_linrgb($L, $c, $H);
	$r = (int) round(255 * _tc_gamma($rgb[0]));
	$g = (int) round(255 * _tc_gamma($rgb[1]));
	$b = (int) round(255 * _tc_gamma($rgb[2]));
	return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
}

//-----------------------------------------------------------------------------
// Recursively assign hues over the taxonomy.  Above the cap, split the range
// among children (permuted); at/below the cap, descendants inherit the hue.
function _tc_assign_hue($id, $s, $e, &$children, &$depth, $cap, &$hue)
{
	$hue[$id] = ($s + $e) / 2.0;
	$kids = isset($children[$id]) ? $children[$id] : array();

	if ($depth[$id] < $cap && count($kids) > 0)
	{
		$N = count($kids);
		$w = ($e - $s) / $N;
		$order = tc_perm_order($N);
		for ($k = 0; $k < $N; $k++)
		{
			$cs = $s + $order[$k] * $w;
			_tc_assign_hue($kids[$k], $cs, $cs + $w, $children, $depth, $cap, $hue);
		}
	}
	else
	{
		foreach ($kids as $c) { _tc_inherit_hue($c, $hue[$id], $children, $hue); }
	}
}

function _tc_inherit_hue($id, $h, &$children, &$hue)
{
	$hue[$id] = $h;
	$kids = isset($children[$id]) ? $children[$id] : array();
	foreach ($kids as $c) { _tc_inherit_hue($c, $h, $children, $hue); }
}

//-----------------------------------------------------------------------------
// Main entry: returns a 0..n-1 array of "#rrggbb" (or '' = no colour) for the
// tree $t (which must already carry internal classification labels).
//
// The taxonomy is keyed by taxon NAME, not by node, so a paraphyletic taxon
// labelled on several clades (e.g. two `f__Beraeidae` nodes) is ONE colour
// everywhere — the whole point of colouring by group.
function compute_node_colors($t, $cap_depth = 2)
{
	$root = $t->GetRoot();
	if ($root === null) { return array(); }

	$parent_id    = array();   // id -> parent id (-1 at root)
	$labelStr     = array();   // id -> label ('' if none)
	$named        = array();   // id -> bool
	$pre          = array();   // id -> preorder index
	$nearestNamed = array();   // id -> nearest named strict ancestor id (-1 none)
	$preCount     = 0;

	// Pass 1 (preorder): topology, labels, nearest named ancestor.
	$it = new PreorderIterator($root);
	$q  = $it->Begin();
	while ($q != null)
	{
		$id  = $q->GetId();
		$anc = $q->GetAncestor();
		$pid = $anc ? $anc->GetId() : -1;
		$parent_id[$id] = $pid;
		$pre[$id] = $preCount++;

		$lab = $q->GetLabel();
		$lab = ($lab === null) ? '' : $lab;
		$labelStr[$id] = $lab;
		$named[$id] = ($lab !== '' && preg_match('/^[A-Za-z]+__/', $lab) === 1);

		$nearestNamed[$id] = ($pid === -1) ? -1 : ($named[$pid] ? $pid : $nearestNamed[$pid]);
		$q = $it->Next();
	}

	// Pass 2: taxonomy keyed by NAME.  Parent of a name = the name of its
	// nearest named ancestor (first occurrence wins if the data is inconsistent).
	$namedIds = array();
	foreach ($named as $id => $is) { if ($is) { $namedIds[$id] = $pre[$id]; } }
	asort($namedIds);   // process in preorder so parents are seen first

	$parentName = array();   // name -> parent name (null at a root)
	$firstPre   = array();   // name -> earliest preorder index (for stable ordering)
	$childSet   = array();   // name -> set of child names
	foreach (array_keys($namedIds) as $id)
	{
		$name = $labelStr[$id];
		$pid  = $nearestNamed[$id];
		$pname = ($pid === -1) ? null : $labelStr[$pid];

		if (!isset($firstPre[$name]))
		{
			$firstPre[$name]   = $pre[$id];
			$parentName[$name] = $pname;
			if ($pname !== null) { $childSet[$pname][$name] = true; }
		}
	}

	// Depth per name (memoised walk up parentName, with a cycle guard).
	$depth = array();
	$depth_of = function ($name) use (&$depth, &$parentName, &$depth_of) {
		if (isset($depth[$name])) { return $depth[$name]; }
		$depth[$name] = 1;   // guard against cycles in malformed data
		$p = isset($parentName[$name]) ? $parentName[$name] : null;
		$depth[$name] = ($p === null) ? 1 : $depth_of($p) + 1;
		return $depth[$name];
	};

	$bypre = function ($a, $b) use ($firstPre) { return $firstPre[$a] - $firstPre[$b]; };

	$roots = array();
	foreach ($parentName as $name => $p) { $depth_of($name); if ($p === null) { $roots[] = $name; } }
	usort($roots, $bypre);

	$children = array();
	foreach ($childSet as $pname => $set)
	{
		$lst = array_keys($set);
		usort($lst, $bypre);
		$children[$pname] = $lst;
	}

	// Pass 3: assign hues over the name-taxonomy, splitting the circle among roots.
	$hue = array();
	$N = count($roots);
	if ($N > 0)
	{
		$w = 360.0 / $N;
		$order = tc_perm_order($N);
		for ($k = 0; $k < $N; $k++)
		{
			$s = $order[$k] * $w;
			_tc_assign_hue($roots[$k], $s, $s + $w, $children, $depth, $cap_depth, $hue);
		}
	}

	// Pass 4 (preorder): each node inherits its deepest classified ancestor's
	// (hue, depth); convert to hex.
	$stateHue   = array();
	$stateDepth = array();
	$color      = array();
	$it = new PreorderIterator($root);
	$q  = $it->Begin();
	while ($q != null)
	{
		$id  = $q->GetId();
		$pid = $parent_id[$id];
		if ($named[$id])
		{
			$name = $labelStr[$id];
			$sh = isset($hue[$name]) ? $hue[$name] : null;
			$sd = $depth[$name];
		}
		else if ($pid === -1)
		{
			$sh = null; $sd = 0;
		}
		else
		{
			$sh = $stateHue[$pid];
			$sd = $stateDepth[$pid];
		}
		$stateHue[$id]   = $sh;
		$stateDepth[$id] = $sd;
		$color[$id] = ($sh === null) ? '' : tc_hcl_to_hex(_tc_L($sd), _tc_C($sd), $sh);
		$q = $it->Next();
	}

	$n = $t->GetNumNodes();
	$out = array();
	for ($i = 0; $i < $n; $i++) { $out[$i] = isset($color[$i]) ? $color[$i] : ''; }
	return $out;
}
