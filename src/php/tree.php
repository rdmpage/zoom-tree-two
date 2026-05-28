<?php

require_once(dirname(__FILE__) . '/node.php');

define('CHILD', 0);
define ('SIB', 1);

//----------------------------------------------------------------------------------------
function write_nexus_label($label)
{
	if (preg_match('/^(\w|\d)+$/', $label))
	{
	}
	else
	{
		str_replace ("'", "\'", $label);
		$label = "'" . $label . "'";
	}
	return $label;
}

//----------------------------------------------------------------------------------------
/**
 *
 *
 */
class Tree
{
	var $root;
	var $num_nodes;
	var $id_to_node_map = array();	
	var $num_leaves;
	var $rooted = true;
	var $has_edge_lengths = false;

	//------------------------------------------------------------------------------------
	function __construct()
	{
		$this->root = NULL;;
		$this->num_nodes = 0;
		$this->num_leaves = 0;
	}	
	
	//------------------------------------------------------------------------------------
	function GetNumLeaves() { return $this->num_leaves; }

	//------------------------------------------------------------------------------------
	function GetNumNodes() { return $this->num_nodes; }
		
	//------------------------------------------------------------------------------------
	function GetRoot() { return $this->root; }

	//------------------------------------------------------------------------------------
	function HasBranchLengths() { return $this->has_edge_lengths; }

	//------------------------------------------------------------------------------------
	function IsRooted() { return $this->rooted; }
	
	//------------------------------------------------------------------------------------
	function SetRoot($root)
	{
		$this->root = $root;
	}
	
	//------------------------------------------------------------------------------------
	function NewNode($label = '')
	{
		$node = new Node($label);
		$node->id = $this->num_nodes++;
		$this->id_to_node_map[$node->id] = $node;

		return $node;
	}
		
	//------------------------------------------------------------------------------------
	function Dump()
	{
		echo "Num leaves = " . $this->num_leaves . "\n";
		
		$n = new NodeIterator ($this->root);
		$a = $n->Begin();
		while ($a != NULL)
		{
			//echo "Node=\n:";
			$a->Dump();
			$a = $n->Next();
		}
	}
	
	//------------------------------------------------------------------------------------
	function WriteDot()
	{
		$dot = "digraph{\n";
		
		
		// output nodes
		foreach ($this->id_to_node_map as $q)
		{
			$dot .= "node [label=\"";
			
			$label = $q->GetLabel();
			if ($label == "")
			{
				$label = $q->GetAttribute('taxon');
			}
			
			$dot .= addcslashes($label, '"') . "\"";
						
			$dot .= "] n" . $q->GetId() . ";\n";
		
		}
		
		
		// output edges
		$n = new NodeIterator ($this->root);
		$a = $n->Begin();
		while ($a != NULL)
		{
			if ($a->GetAncestor())
			{
				$dot .= "n" . $a->GetAncestor()->GetId() . " -> n" . $a->GetId() . ";\n";
			}
			$a = $n->Next();
		}
		$dot .= "}\n";
		return $dot;
	}
		
	//------------------------------------------------------------------------------------
	function WriteNewick()
	{
		$newick = '';
		
		$stack = array();
		$curnode = $this->root;
		
		while ($curnode != NULL)
		{	
			if ($curnode->GetChild())
			{
				$newick .= '(';
				$stack[] = $curnode;
				$curnode = $curnode->GetChild();
			}
			else
			{
				$newick .= write_nexus_label($curnode->GetLabel());
				
				$length = $curnode->GetAttribute('edge_length');
				if ($length != '')
				{
					$newick .= ':' . $length;
				}
											
				while (!empty($stack) && ($curnode->GetSibling() == NULL))
				{
					$newick .= ')';
					$curnode = array_pop($stack);
					
					// Write internal node
					if ($curnode->GetLabel() != '')
					{
						$newick .= write_nexus_label($curnode->GetLabel());
					}
					$length = $curnode->GetAttribute('edge_length');
					if ($length != '')
					{
						$newick .= ':' . $length;
					}					

				}
				if (empty($stack))
				{
					$curnode = NULL;
				}
				else
				{
					$newick .= ',';
					$curnode = $curnode->GetSibling();
				}
			}		
		}
		$newick .= ";";
		return $newick;
	}	
			
	
	//------------------------------------------------------------------------------------
	// Build weights
	function BuildWeights($p)
	{
		if ($p)
		{
			$p->SetAttribute('weight', 0);
			
			$this->BuildWeights($p->GetChild());
			$this->BuildWeights($p->GetSibling());
			
			if ($p->Isleaf())
			{
				$p->SetAttribute('weight', 1);
			}
			if ($p->GetAncestor())
			{
				$p->GetAncestor()->AddWeight($p->GetAttribute('weight'));
			}
		}
	}
	
	//------------------------------------------------------------------------------------
	// Traverse tree and update leaf count, weights
	function Update()
	{
		$this->num_leaves = 0;
		$this->num_nodes = 0;
		
		$this->updateTraverse($this->root);

	}	
	
	//------------------------------------------------------------------------------------
	function updateTraverse($p)
	{
		if ($p)
		{
			$this->num_nodes++;
			
			$p->SetAttribute('weight', 0);
			
			$this->updateTraverse($p->GetChild());
			$this->updateTraverse($p->GetSibling());
			
			if ($p->Isleaf())
			{
				$p->SetAttribute('weight', 1);
				$this->num_leaves++;
			}
			if ($p->GetAncestor())
			{
				$p->GetAncestor()->AddWeight($p->GetAttribute('weight'));
			}
		}
	}		

	//------------------------------------------------------------------------------------
	function markPath($p)
	{
		$q = $p;
		while ($q)
		{
			$q->SetAttribute('marked', true);
			$q = $q->GetAncestor();
		}
	}
	
	//------------------------------------------------------------------------------------
	function unMarkPath($p)
	{
		$q = $p;
		while ($q)
		{
			$q->SetAttribute('marked', false);
			$q = $q->GetAncestor();
		}
	}


}

?>