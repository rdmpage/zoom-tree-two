<?php

// Bucket priority queue keyed by integer priority.
//
// Lifted from ~/Sites/ott-viewer/pq.php — just the PQ class (dropped PQfake
// and the top-level cmp()).  Inspired by ezimuel/FastPriorityQueue.
//
// Scores must be non-negative; they're scaled by 10000 and rounded so
// real-valued scores still bucket sensibly.  For our use (score = subtree
// leaf count, already integer) the scaling is wasted work but harmless.
//
// Complexity:  en_queue O(1).  de_queue O(1) amortised — only non-constant
// piece is max($priorities) when a bucket empties, bounded by the number of
// distinct scores currently in the queue.  For sensible phylogenies this is
// O(log N); pathological caterpillars degrade to O(N).
class PQ
{
	var $values        = array();
	var $priorities    = array();
	var $max           = 0;
	var $id_priorities = array();

	function __construct()
	{
		$this->values        = array();
		$this->priorities    = array();
		$this->max           = 0;
		$this->id_priorities = array();
	}

	function en_queue($id, $name, $score)
	{
		$obj = new stdclass;
		$obj->id    = $id;
		$obj->name  = $name;
		$obj->score = $score;

		// priority must be a positive integer
		$score = 100 * $obj->score;
		if ($score > PHP_INT_MAX)
		{
			$score = PHP_INT_MAX;
		}
		else
		{
			$score = round(100 * $score, 0);
		}

		$obj->priority = max(0, $score);

		$this->values[$obj->priority][] = $obj;

		if (!isset($this->priorities[$obj->priority]))
		{
			$this->priorities[$obj->priority] = $obj->priority;
			$this->max = max($obj->priority, $this->max);
		}

		$this->id_priorities[$obj->id] = $obj->priority;
	}

	function de_queue()
	{
		$obj = null;

		if ($this->valid())
		{
			$obj = array_pop($this->values[$this->max]);

			unset($this->id_priorities[$obj->id]);

			if (empty($this->values[$this->max]))
			{
				unset($this->values[$this->max]);
				unset($this->priorities[$this->max]);
				$this->max = empty($this->priorities) ? 0 : max($this->priorities);
			}
		}

		return $obj;
	}

	function delete_from_queue($id)
	{
		$priority = $this->id_priorities[$id];
		$found = -1;

		foreach ($this->values[$priority] as $k => $v)
		{
			if ($this->values[$priority][$k]->id == $id)
			{
				$found = $k;
				break;
			}
		}

		if ($found != -1)
		{
			unset($this->values[$priority][$found]);
			unset($this->id_priorities[$id]);

			if (empty($this->values[$priority]))
			{
				unset($this->values[$priority]);
				unset($this->priorities[$priority]);
				$this->max = empty($this->priorities) ? 0 : max($this->priorities);
			}
		}
	}

	function valid()
	{
		return isset($this->values[$this->max]);
	}
}
