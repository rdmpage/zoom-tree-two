<?php

// Shared NEXUS/Newick token scanner.
//
// Both the Newick parser (tree-parse.php) and the NEXUS reader (nexus.php)
// tokenise the same way, so the token classes and the Scanner live here in a
// single source of truth.  This is the BEAST-metacomment-aware Scanner: it
// stashes `[&...]` comment bodies in $pendingMeta for the Newick parser to
// attach to nodes; the NEXUS reader simply ignores that field.

if (!defined('NEXUSPunctuation')) { define('NEXUSPunctuation', "()[]{}/\\,;:=*'\"`+-"); }
if (!defined('NEXUSWhiteSpace'))  { define('NEXUSWhiteSpace', "\n\r\t "); }

//----------------------------------------------------------------------------------------
class TokenTypes
{
	const None 			= 0;
	const String 		= 1;
	const Hash 			= 2;
	const Number 		= 3;
	const SemiColon 	= 4;
	const OpenPar		= 5;
	const ClosePar 		= 6;
	const Equals 		= 7;
	const Space 		= 8;
	const Comma  		= 9;
	const Asterix 		= 10;
	const Colon 		= 11;
	const Other 		= 12;
	const Bad 			= 13;
	const Minus 		= 14;
	const DoubleQuote 	= 15;
	const Period 		= 16;
	const BackSlash 	= 17;
	const QuotedString	= 18;
}

//----------------------------------------------------------------------------------------
class NumberTokens
{
	const start 		= 0;
	const sign 			= 1;
	const digit 		= 2;
	const fraction 		= 3;
	const expsymbol 	= 4;
	const expsign 		= 5;
	const exponent 		= 6;
	const bad 			= 7;
	const done 			= 8;
}

//----------------------------------------------------------------------------------------
class StringTokens
{
	const ok 			= 0;
	const quote 		= 1;
	const done 			= 2;
}

//----------------------------------------------------------------------------------------
class NexusError
{
	const ok 			= 0;
	const nobegin 		= 1;
	const noend 		= 2;
	const syntax 		= 3;
	const badcommand 	= 4;
	const noblockname 	= 5;
	const badblock	 	= 6;
	const nosemicolon	= 7;
}

//----------------------------------------------------------------------------------------
class Scanner
{
	public $error = 0;
	public $comment = '';
	public $pos = 0;
	public $str = '';
	public $token = TokenTypes::None;
	public $buffer = '';
	public $pendingMeta = '';   // BEAST [&key=val,...] content waiting for the parser to attach

	//------------------------------------------------------------------------------------
	function __construct($str)
	{
		$this->str = $str;
	}

	//------------------------------------------------------------------------------------
	function GetToken($returnspace = false)
	{
		$this->token = TokenTypes::None;
		while (($this->token == TokenTypes::None) && ($this->pos < strlen($this->str)))
		{
			if (strchr(NEXUSWhiteSpace, substr($this->str, $this->pos, 1)))
			{
				if ($returnspace && (substr($this->str, $this->pos, 1) == ' '))
				{
					$this->token = TokenTypes::Space;
				}
			}
			else
			{
				if (strchr (NEXUSPunctuation, substr($this->str, $this->pos, 1)))
				{
					$this->buffer = substr($this->str, $this->pos, 1);
 					switch (substr($this->str, $this->pos, 1))
 					{
 						case '[':
 							$this->ParseComment();
 							break;
 						case "'":
 							if ($this->ParseString())
 							{
 								$this->token = TokenTypes::QuotedString;
 							}
 							else
 							{
 								$this->token = TokenTypes::Bad;
 							}
 							break;
						case '(':
							$this->token = TokenTypes::OpenPar;
							break;
						case ')':
							$this->token = TokenTypes::ClosePar;
							break;
						case '=':
							$this->token = TokenTypes::Equals;
							break;
						case ';':
							$this->token = TokenTypes::SemiColon;
							break;
						case ',':
							$this->token = TokenTypes::Comma;
							break;
						case '*':
							$this->token = TokenTypes::Asterix;
							break;
						case ':':
							$this->token = TokenTypes::Colon;
							break;
						case '-':
							$this->token = TokenTypes::Minus;
							break;
						case '"':
							$this->token = TokenTypes::DoubleQuote;
							break;
					   	case '/':
							$this->token = TokenTypes::BackSlash;
							break;
						default:
							$this->token = TokenTypes::Other;
							break;
					}
				}
				else
				{
					if (substr($this->str, $this->pos, 1) == '#')
					{
						$this->token = TokenTypes::Hash;

					}
					else if (substr($this->str, $this->pos, 1) == '.')
					{
						$this->token = TokenTypes::Period;
					}
					else
					{
						if (is_numeric(substr($this->str, $this->pos, 1)))
						{
							$save = $this->pos;
							if ($this->ParseNumber())
							{
								$this->token = TokenTypes::Number;
							}
							else
							{
								// Leading digit but not a clean number, e.g. a
								// label like "9_KF000456_Sebacina_sp" — re-read
								// the whole run as a string token.
								$this->pos = $save;
								if ($this->ParseToken())
								{
									$this->token = TokenTypes::String;
								}
								else
								{
									$this->token = TokenTypes::Bad;
								}
							}
						}
						else
						{
							if ($this->ParseToken())
							{
								$this->token = TokenTypes::String;
							}
							else
							{
								$this->token = TokenTypes::Bad;
							}
						}
					}
				}
			}
			$this->pos++;

		}
		return $this->token;
	}


	//------------------------------------------------------------------------------------
	function ParseComment()
	{
		$this->buffer = '';

		while ((substr($this->str, $this->pos, 1) != ']') && ($this->pos < strlen($this->str)))
		{
			$this->buffer .= substr($this->str, $this->pos, 1);
			$this->pos++;
		}
		$this->buffer .= substr($this->str, $this->pos, 1);

		// BEAST-style metacomments start with `[&...`.  Save the content
		// (without the surrounding brackets) for the parser to attach to the
		// current node on the next iteration of its state loop.
		$content = substr($this->buffer, 1, -1);
		if (strlen($content) > 0 && $content[0] === '&')
		{
			$this->pendingMeta = $content;
		}
	}

	//------------------------------------------------------------------------------------
	function ParseNumber()
	{
		$this->buffer = '';
		$state = NumberTokens::start;

		while (
			($this->pos < strlen($this->str))
			&& ($state != NumberTokens::bad)
			&& ($state != NumberTokens::done)
			)
		{
			if (is_numeric(substr($this->str, $this->pos, 1)))
			{
				switch ($state)
				{
					case NumberTokens::start:
					case NumberTokens::sign:
						$state =  NumberTokens::digit;
						break;
					case NumberTokens::expsymbol:
					case NumberTokens::expsign:
						$state =  NumberTokens::exponent;
						break;
					default:
						break;
				}
			}
			else if ((substr($this->str, $this->pos, 1) == '-') || (substr($this->str, $this->pos, 1) == '+'))
			{
				switch ($state)
				{
					case NumberTokens::start:
						$state = NumberTokens::sign;
						break;
					case NumberTokens::digit:
						$state = NumberTokens::done;
						break;
					case NumberTokens::expsymbol:
						$state = NumberTokens::expsign;
						break;
					default:
						$state = NumberTokens::bad;
						break;
				}
			}
			else if ((substr($this->str, $this->pos, 1) == '.') && ($state == NumberTokens::digit))
			{
				$state = NumberTokens::fraction;
			}
			else if (((substr($this->str, $this->pos, 1) == 'E') || (substr($this->str, $this->pos, 1) == 'e')) && (($state == NumberTokens::digit) || ($state == NumberTokens::fraction)))
			{
				$state = NumberTokens::expsymbol;
			}
			else if (strchr(NEXUSWhiteSpace, substr($this->str, $this->pos, 1)) || strchr (NEXUSPunctuation, substr($this->str, $this->pos, 1)))
			{
				$state = NumberTokens::done;
			}
			else
			{
				$state = NumberTokens::bad;
			}

			if (($state != NumberTokens::bad) && ($state != NumberTokens::done))
			{
				$this->buffer .= substr($this->str, $this->pos, 1);
				$this->pos++;
			}
		}

		$this->pos--;
		// A 'bad' state means we hit a non-numeric char that isn't a token
		// terminator (e.g. '_' or a letter), so this run isn't really a number.
		return ($state != NumberTokens::bad);
	}

	//------------------------------------------------------------------------------------
	function ParseString()
	{
		$this->buffer = '';

		$this->pos++;

		$state = StringTokens::ok;
		while ($state != StringTokens::done)
		{
			switch ($state)
			{
				case StringTokens::ok:
					if (substr($this->str, $this->pos, 1) == "'")
					{
						$state = StringTokens::quote;
					}
					else
					{
						$this->buffer .= substr($this->str, $this->pos, 1);
					}
					break;

				case StringTokens::quote:
					if (substr($this->str, $this->pos, 1) == "'")
					{
						$this->buffer .= substr($this->str, $this->pos, 1);
						$state = StringTokens::ok;
					}
					else
					{
						$state = StringTokens::done;
						$this->pos--;
					}
					break;

				default:
					break;
			}
			$this->pos++;
		}
		$this->pos--;
		return true;
	}


	//------------------------------------------------------------------------------------
	function ParseToken()
	{
		$this->buffer = '';

		while (
			($this->pos < strlen($this->str))
			&& (!strchr (NEXUSWhiteSpace, substr($this->str, $this->pos, 1)))
			&& (!strchr (NEXUSPunctuation, substr($this->str, $this->pos, 1)))
			)
		{
			$this->buffer .= substr($this->str, $this->pos, 1);
			$this->pos++;
		}
		$this->pos--;
		return true;
	}

}
