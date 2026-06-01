<?php

// Very basic NEXUS parser.
//
// Reads the TAXA and TREES blocks of a NEXUS file far enough to recover the
// tree descriptions (as Newick) plus any Translate table.  The token classes
// and the Scanner are shared with the Newick parser — see scanner.php.

require_once(dirname(__FILE__) . '/scanner.php');   // TokenTypes, Scanner, NexusError, etc.

//--------------------------------------------------------------------------------------------------
class NexusReader extends Scanner
{
	public $nexusCommands = array('begin', 'dimensions', 'end', 'endblock', 'link', 'taxa', 'taxlabels', 'title', 'translate', 'tree');
	public $nexusBlocks = array('taxa', 'trees');

	//----------------------------------------------------------------------------------------------
	function GetBlock()
	{
		$blockname = '';

		$command =  $this->GetCommand();
		if ($command != 'begin')
		{
			$this->error = NexusError::nobegin;
		}
		else
		{
			// get block name
			$t = $this->GetToken();
			if ($t == TokenTypes::String)
			{
				$blockname = strtolower($this->buffer);
				$t = $this->GetToken();
				if ($t != TokenTypes::SemiColon)
				{
					$this->error = NexusError::noblockname;
				}
			}
			else
			{
				$this->error = NexusError::noblockname;
			}

		}
		return $blockname;
	}

	//----------------------------------------------------------------------------------------------
	function GetCommand()
	{
		$command = '';

		$t = $this->GetToken();
		if ($t == TokenTypes::String)
		{
			if (in_array(strtolower($this->buffer), $this->nexusCommands))
			{
				$command = strtolower($this->buffer);
			}
			else
			{
				$this->error = NexusError::badcommand;
			}
		}
		else
		{
			$this->error = NexusError::syntax;
		}
		return $command;
	}

	//----------------------------------------------------------------------------------------------
	function IsNexusFile()
	{
		$this->error = NexusError::ok;

		$nexus = false;
		$t = $this->GetToken();
		if ($t == TokenTypes::Hash)
		{
			$t = $this->GetToken();
			if ($t == TokenTypes::String)
			{
				$nexus = (strcasecmp('NEXUS', $this->buffer) == 0);
			}
		}
		return $nexus;
	}

	//----------------------------------------------------------------------------------------------
	function SkipCommand()
	{
		do {
			$t = $this->GetToken();
		} while (($this->error == NexusError::ok) && ($t != TokenTypes::SemiColon));
		return $this->error;
	}

}

//--------------------------------------------------------------------------------------------------
// Parse a NEXUS string.  Returns a stdclass with:
//   ->translations->translate   map of OTU token -> taxon name (if a Translate command was present)
//   ->trees[]                    one stdclass per tree, each with ->label, ->default, ->newick
function parse_nexus($str)
{
	$nx = new NexusReader($str);

	$nx->IsNexusFile();

	$blockname = $nx->GetBlock();

	$treeblock = new stdclass;
	$treeblock->translations = null;
	$treeblock->trees = array();


	if ($blockname == 'taxa')
	{
		$command = $nx->GetCommand();

		while (
			(($command != 'end') && ($command != 'endblock'))
			&& ($nx->error == NexusError::ok)
			)
		{
			switch ($command)
			{
				case 'taxlabels':
					$nx->SkipCommand();
					$command = $nx->GetCommand();
					break;


				default:
					$nx->SkipCommand();
					$command = $nx->GetCommand();
					break;
			}

			// If end command eat the semicolon
			if (($command == 'end') || ($command == 'endblock'))
			{
				$nx->GetToken();
			}
		}

		$blockname = $nx->GetBlock();

	}

	if ($blockname == 'trees')
	{
		$command = $nx->GetCommand();

		while (
			(($command != 'end') && ($command != 'endblock'))
			&& ($nx->error == NexusError::ok)
			)
		{
			switch ($command)
			{
				case 'translate':
					if (!$treeblock->translations)
					{
						$treeblock->translations = new stdclass;
					}
					$treeblock->translations->translate = array();

					$done = false;
					while (!$done && ($nx->error == NexusError::ok))
					{
						$t = $nx->GetToken();

						if (in_array($t, array(TokenTypes::Number, TokenTypes::String, TokenTypes::QuotedString)))
						{
							$otu = $nx->buffer;
							$t = $nx->GetToken();

							if (in_array($t, array(TokenTypes::Number, TokenTypes::String, TokenTypes::QuotedString)))
							{
								$treeblock->translations->translate[$otu] = $nx->buffer;

								$t = $nx->GetToken();
								switch ($t)
								{
									case TokenTypes::Comma:
										break;

									case TokenTypes::SemiColon:
										$done = true;
										break;

									default:
										$nx->error = NexusError::syntax;
										break;
								}
							}
							else
							{
								$nx->error = NexusError::syntax;
							}
						}
						else
						{
							$nx->error = NexusError::syntax;
						}
					}

					$command = $nx->GetCommand();
					break;

				case 'tree':
					if ($command == 'tree')
					{
						$tree = new stdclass;
						$tree->label = '';
						$tree->default = false;
						$tree->newick = '';

						$t = $nx->GetToken();
						if ($t == TokenTypes::Asterix)
						{
							$tree->default = true;
							$t = $nx->GetToken();
						}
						if ($t == TokenTypes::String)
						{
							$tree->label = $nx->buffer;
						}
						$t = $nx->GetToken();
						if ($t == TokenTypes::Equals)
						{
							$t = $nx->GetToken();
							while ($t != TokenTypes::SemiColon)
							{
								if ($t == TokenTypes::QuotedString)
								{
									$s = $nx->buffer;
									$s = str_replace("'", "''", $s);
									$s = "'" . $s . "'";
									$tree->newick .= $s;
								}
								else
								{
									$tree->newick .= $nx->buffer;
								}
								$t = $nx->GetToken();
							}
							$tree->newick .= ';';
						}

						$treeblock->trees[] = $tree;
					}
					$command = $nx->GetCommand();
					break;

				default:
					$nx->SkipCommand();
					$command = $nx->GetCommand();
					break;
			}

			// If end command eat the semicolon
			if (($command == 'end') || ($command == 'endblock'))
			{
				$nx->GetToken();
			}


		}

	}

	return $treeblock;
}

//--------------------------------------------------------------------------------------------------
// Quote a label for emission into Newick if it contains anything that isn't a
// bare-word character.  Internal single quotes are doubled, per the standard.
function nexus_newick_label($s)
{
	if ($s === '') { return $s; }
	if (preg_match('/^[A-Za-z0-9._]+$/', $s))
	{
		return $s;
	}
	return "'" . str_replace("'", "''", $s) . "'";
}

//--------------------------------------------------------------------------------------------------
// Apply a Translate table (OTU token -> taxon name) to a Newick string.  Only
// tokens in *label* position (immediately after '(', ',' or ')', or at the very
// start) are translated, so branch-length numbers after ':' are left alone.
function nexus_apply_translate($newick, $map)
{
	if (empty($map)) { return $newick; }

	$s = new Scanner($newick);
	$out = '';
	$prev = TokenTypes::None;   // previous emitted token type

	$labelPos = array(TokenTypes::None, TokenTypes::OpenPar, TokenTypes::Comma, TokenTypes::ClosePar);

	$t = $s->GetToken();
	while ($t != TokenTypes::None)
	{
		switch ($t)
		{
			case TokenTypes::OpenPar:   $out .= '('; break;
			case TokenTypes::ClosePar:  $out .= ')'; break;
			case TokenTypes::Comma:     $out .= ','; break;
			case TokenTypes::Colon:     $out .= ':'; break;
			case TokenTypes::SemiColon: $out .= ';'; break;
			case TokenTypes::Minus:     $out .= '-'; break;

			case TokenTypes::Number:
			case TokenTypes::String:
			case TokenTypes::QuotedString:
				$val = $s->buffer;
				if (in_array($prev, $labelPos) && isset($map[$val]))
				{
					$val = $map[$val];
				}
				$out .= nexus_newick_label($val);
				break;

			default:
				$out .= $s->buffer;
				break;
		}

		$prev = $t;
		$t = $s->GetToken();
	}

	return $out;
}

//--------------------------------------------------------------------------------------------------
// Pick the tree to use from a parsed tree block: the one flagged default
// (`tree * name = ...`) if any, otherwise the first.  Returns the stdclass
// tree (with ->newick, ->label, ->default) or null if there are no trees.
function nexus_pick_tree($treeblock)
{
	if (empty($treeblock->trees)) { return null; }

	foreach ($treeblock->trees as $tree)
	{
		if (!empty($tree->default)) { return $tree; }
	}
	return $treeblock->trees[0];
}

//--------------------------------------------------------------------------------------------------
// Convenience: NEXUS string in, a single Newick string out (first/default
// tree, with the Translate table applied).  Returns null if no tree is found.
function nexus_to_newick($str)
{
	$treeblock = parse_nexus($str);
	$tree = nexus_pick_tree($treeblock);
	if ($tree === null) { return null; }

	$map = array();
	if ($treeblock->translations && isset($treeblock->translations->translate))
	{
		$map = $treeblock->translations->translate;
	}

	return nexus_apply_translate($tree->newick, $map);
}
