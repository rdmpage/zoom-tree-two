<?php

// Parse a Newick tree 

error_reporting(E_ALL);

require_once(dirname(__FILE__) . '/node.php');
require_once(dirname(__FILE__) . '/tree.php');
require_once(dirname(__FILE__) . '/node_iterator.php');

define('NEXUSPunctuation', "()[]{}/\\,;:=*'\"`+-");
define('NEXUSWhiteSpace', "\n\r\t ");

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
			//echo "[" . __LINE__ . "] +" . $this->str{$this->pos} . "\n";
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
					//echo "[" . __LINE__ . "] -" . $this->str{$this->pos} . "\n";
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
							if ($this->ParseNumber())
							{
								$this->token = TokenTypes::Number;
							}
							else
							{
								$this->token = TokenTypes::Bad;
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
			//&& (!strchr (NEXUSWhiteSpace, $this->str{$this->pos}))
			//&& (!strchr (NEXUSPunctuation, $this->str{$this->pos}))
			//&& ($this->str{$this->pos} != '-')
			&& ($state != NumberTokens::bad)
			&& ($state != NumberTokens::done)
			)
		{
			//echo $state . ' ' . $this->pos . ' ' . $this->str{$this->pos} . "\n";
				
			if (is_numeric(substr($this->str, $this->pos, 1)))
			{
				//echo "[" . __LINE__ . "] number\n";
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
				//echo "[" . __LINE__ . "] -|+\n";
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
				//echo "[" . __LINE__ . "] fraction\n";
				$state = NumberTokens::fraction;
			}
			else if (((substr($this->str, $this->pos, 1) == 'E') || (substr($this->str, $this->pos, 1) == 'e')) && (($state == NumberTokens::digit) || ($state == NumberTokens::fraction)))			
			{
				//echo "[" . __LINE__ . "] exp\n";
				$state = NumberTokens::expsymbol;
			}
			else if (strchr(NEXUSWhiteSpace, substr($this->str, $this->pos, 1)) || strchr (NEXUSPunctuation, substr($this->str, $this->pos, 1)))
			{
				//echo "[" . __LINE__ . "] whitespace, punctuation\n";
				$state = NumberTokens::done;
			}
			else
			{
				//echo "[" . __LINE__ . "] bad\n";
				$state = NumberTokens::bad;
			}
			
			if (($state != NumberTokens::bad) && ($state != NumberTokens::done))
			{
				//echo "[" . __LINE__ . "] OK\n";
				$this->buffer .= substr($this->str, $this->pos, 1);
				$this->pos++;
			}
		}
		//echo "[" . __LINE__ . "] done number\n";
		
		$this->pos--;
		return true; 		
	}
	
	//------------------------------------------------------------------------------------
	function ParseString()
	{
		//echo "[" . __LINE__ . "] ParseString\n";
		$this->buffer = '';
		
		$this->pos++;
		
		$state = StringTokens::ok;
		while ($state != StringTokens::done)
		{
			//echo "[" . __LINE__ . "] --" . $this->str{$this->pos} . "\n";
			
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

//----------------------------------------------------------------------------------------
// Parse a BEAST-style metacomment "&key=val,key=val,..." and stash each pair
// on the node as a meta_<key> attribute.  NHX (which uses `:` as separator,
// not `,`) is not handled — store the whole content under meta_raw so it's
// at least preserved.
function apply_metacomment($node, $content)
{
	$content = ltrim($content, '&');

	// NHX format ([&&NHX:S=human:E=1.1.1.1]): the second & is gone, content
	// now starts with "NHX:".  We don't parse it for now — preserve raw.
	if (strpos($content, 'NHX:') === 0)
	{
		$node->SetAttribute('meta_raw', $content);
		return;
	}

	foreach (explode(',', $content) as $pair)
	{
		$eq = strpos($pair, '=');
		if ($eq === false) { continue; }
		$key = trim(substr($pair, 0, $eq));
		$val = trim(substr($pair, $eq + 1));
		if ($key !== '')
		{
			$node->SetAttribute('meta_' . $key, $val);
		}
	}
}

function parse_newick($newick)
{
	// read a tree

	$scanner = new Scanner($newick);
	$token = $scanner->GetToken();

	$state = 0;
	$stack = array();


	$t = new Tree();
	$curnode = $t->NewNode();
	$t->root = $curnode;


	while ($state != 99)
	{
		// Attach any metacomment the scanner picked up during the last GetToken
		// to whatever node is current right now.  Works because metacomments
		// always appear after a node-finalising token (`)`, label, branch
		// length) — $curnode at this point is the node that was just closed.
		if ($scanner->pendingMeta !== '')
		{
			apply_metacomment($curnode, $scanner->pendingMeta);
			$scanner->pendingMeta = '';
		}

		switch ($state)
		{
			case 0: // getname
				switch ($token)
				{
					case TokenTypes::Number:
					case TokenTypes::QuotedString:
					case TokenTypes::String:
						$label = $scanner->buffer;
						// Newick convention: in unquoted labels, '_' means space.
						if ($token == TokenTypes::String)
						{
							$label = str_replace('_', ' ', $label);
						}
						$curnode->SetLabel($label);
						$t->num_leaves++;

						$token = $scanner->GetToken();
						$state = 1;
						break;

					case TokenTypes::OpenPar:
						$state = 2;
						break;
					
					default:
						$state = 99;
						break;
				}
				break;
			
			case 1: // getinternode
				switch ($token)
				{
					case TokenTypes::Colon:
					case TokenTypes::Comma:
					case TokenTypes::ClosePar:
						$state = 2;
						break;
					
					default:
						$state = 99;
						break;
				}
				break;
			
			case 2: // nextmove
				switch ($token)
				{
					case TokenTypes::Colon:							
						$token = $scanner->GetToken();

						$prefix = '';
						
						// negative branch length will have a minus sign
						if ($token == TokenTypes::Minus)
						{
							$prefix = '-';
							$token = $scanner->GetToken();
						}
						
						if ($token == TokenTypes::Number)
						{
							$number = (float) ($prefix . $scanner->buffer);
							$curnode->SetAttribute('edge_length', $number);
							$t->has_edge_lengths = true;
							$token = $scanner->GetToken();
						}
						else
						{
							echo "[" . __LINE__ . "] Expecting a number, got " . $scanner->buffer . " instead"; exit();
							$state = 99;
						}
						break;

					case TokenTypes::Comma:
						$stack_size = count($stack);
						if ($stack_size == 0)
						{
							// "Tree description unbalanced, this \")\" has no matching \"(\"";
							echo "[" . __LINE__ . "] Tree description unbalanced, this \")\" has no matching \"(\""; exit();
							$state = 99;
						}
						else
						{						
							$q = $t->NewNode();
							$curnode->SetSibling($q);		
							$q->SetAncestor($stack[$stack_size - 1]);
							$curnode = $q;
					
							$token = $scanner->GetToken();
							$state = 0;
						}
						break;	
											
					case TokenTypes::OpenPar:
						$stack[] = $curnode;
						$q = $t->NewNode();
						$curnode->SetChild($q);
						$q->SetAncestor($curnode);
						$curnode = $q;
						$state = 0;
						$token = $scanner->GetToken();
						break;
					
					case TokenTypes::ClosePar:
						if (empty($stack))
						{
							echo "[" . __LINE__ . "] Tree description unbalanced (an extra \")\")"; exit();
							$state = 99;
						}
						else
						{
							$curnode = array_pop($stack);
							$state = 3;
							$token = $scanner->GetToken();
						}
						break;
				
					case TokenTypes::SemiColon:
						if (empty($stack))
						{
							$state = 99;
						}
						else
						{
							echo "[" . __LINE__ . "] Tree description ended prematurely (stack not empty)"; exit();
							$state = 99;
						}
						break;
				
					default:
						$state = 99;
						break;
				}
				break;
		
			case 3: // finishchildren
				switch ($token)
				{
					case TokenTypes::Number:
					case TokenTypes::QuotedString:
					case TokenTypes::String:
						$label = $scanner->buffer;
						// Newick convention: in unquoted labels, '_' means space.
						if ($token == TokenTypes::String)
						{
							$label = str_replace('_', ' ', $label);
						}
						$curnode->SetLabel($label);
						$token = $scanner->GetToken();
						$state = 1;
						break;
		
					case TokenTypes::Colon:							
						$token = $scanner->GetToken();
						
						$prefix = '';
						
						// negative branch length will have a minus sign
						if ($token == TokenTypes::Minus)
						{
							$prefix = '-';
							$token = $scanner->GetToken();
						}
						
						if ($token == TokenTypes::Number)
						{
							$number = (float) ($prefix . $scanner->buffer);
							$curnode->SetAttribute('edge_length', $number);
							$t->has_edge_lengths = true;
							$token = $scanner->GetToken();
						}
						else
						{
							echo "[" . __LINE__ . "] expected a number, got " . $scanner->buffer . ' ' . $token; exit();
							$state = 99;
						}
						break;
					
					case TokenTypes::ClosePar:
						if (empty($stack))
						{
							echo "[" . __LINE__ . "] Tree description unbalanced (an extra \")\")"; exit();
							$state = 99;
						}
						else
						{
							$curnode = array_pop($stack);
							$state = 3;
							$token = $scanner->GetToken();
						}
						break;
					
					case TokenTypes::Comma:
						$stack_size = count($stack);
						if ($stack_size == 0)
						{
							echo "[" . __LINE__ . "] Tree description unbalanced, this \")\" has no matching \"(\""; exit();
							$state = 99;
						}
						else
						{			
							$q = $t->NewNode();
							$curnode->SetSibling($q);							
							$q->SetAncestor($stack[$stack_size - 1]);
							$curnode = $q;
					
							$token = $scanner->GetToken();
							$state = 0;
						}
						break;	
					
					case TokenTypes::SemiColon:
						$state = 2;
						break;
					
					default:
						if (empty($stack))
						{
							echo "[" . __LINE__ . "] Tree description unbalanced"; exit();
							$state = 99;
						}
						else
						{
							/*
						errormsg = "Syntax error [FINISHCHILDREN]: expecting one of \":,();\" or internal label, got ";
						errormsg += parser.GetToken();
						errormsg += " instead";
								*/
								
							echo "[" . __LINE__ . "] Syntax error [FINISHCHILDREN]: expecting one of \":,();\" or internal label, got " . $scanner->buffer . " instead"; exit();
							$curnode = array_pop($stack);
							$state = 99;
						}
						break;
				}
		
			default:
				break;
		}
	}
	
	return $t;
}

if (0)
{
	$newick = "(KJ836409.1:0.0394167,((((KJ837499.1:0.000790185,(HQ948094.1:0.005835,((KJ836642.1:0.00223928,(KJ836862.1:1.48198e-07,KJ836538.1:0.00237892):0.000143574):0.000696052,(((KJ838652.1:0,KJ839820.1:7.36166e-05):7.36166e-05,KJ836425.1:0):7.36166e-05,HQ948093.1:0):0.00170478):0.00135383):0.000386304):0.0209252,(((((KT074045.1:0,KJ836515.1:0):0,KJ838226.1:0):0,HM901923.1:0):0.000592874,KJ836448.1:0.00178619):0.000974122,KJ839494.1:0.00140873):0.0347398):0.0326188,(KR786504.1:0.00510067,((FJ582232.1:0,(KJ163559.1:0.00235992,FJ582230.1:0.00481717):8.77903e-05):0.000672437,FJ582231.1:0.00171802):0.00450818):0.0518167):0.0119368,((((((((KX957905.1:0,GU705980.1:0.00478067):1.49689e-05,KJ837790.1:0):1.49689e-05,KJ837713.1:0):0.000689581,(((((((((((((((KX957869.1:0,KX374766.1:5.56418e-09):5.56418e-09,KX957868.1:0):5.56418e-09,KX957867.1:0):5.56418e-09,KX957865.1:0):5.56418e-09,KX957864.1:0):5.56418e-09,KX957863.1:0):5.56418e-09,KX957862.1:0):5.56418e-09,KX957861.1:0):5.56418e-09,KX957860.1:0):5.56418e-09,KX374767.1:0):5.56418e-09,GU705983.1:0):0.000600043,(KX957904.1:0.00178088,((KX374770.1:0,KX957903.1:7.10403e-06):7.10403e-06,GU705978.1:0):0.000602462):0.00178281):0.00060507,KY121842.1:0.0011822):0.00115752,(KX957866.1:0,(((((JQ909849.1:0.00237954,GU705979.1:0):4.78638e-07,KT164634.1:0):4.78638e-07,KT074073.1:0):4.78638e-07,KY121818.1:0):4.78638e-07,KY121823.1:0):4.78638e-07):3.21721e-05):0.000188326,KC709831.1:0):0.00171617):0.0110093,AF250940.1:0.00779681):0.00451422,(AF250941.1:0.0115977,(((((EU726628.1:0,EU726627.1:0):0,EU726626.1:0):0,EU726621.1:0):0,EU726549.1:0):0.00124566,(EU726624.1:0,EU726601.1:0):0.00113908):0.0213067):0.00363395):0.0511406,(((KJ838228.1:0.00238854,HM901915.1:0.00238854):0.000383077,KJ837591.1:0.00200547):0.0398015,((KM568799.1:0,KM562022.1:0):0.0367986,HQ948088.1:0.038264):0.0135556):0.0220487):0.0134001,(KC560283.1:0.00647048,(KY072536.1:0.00206123,((((((((((((((((((KY072692.1:3.09203e-09,KY072384.1:0.00237906):0,KY072512.1:3.09203e-09):0,KY072457.1:3.09203e-09):0,KY072408.1:3.09203e-09):0,KY072378.1:3.09203e-09):0,KY072344.1:3.09203e-09):0,KY072316.1:3.09203e-09):0,KY072314.1:3.09203e-09):0,KY072271.1:3.09203e-09):0,KY072263.1:3.09203e-09):0,KY072262.1:3.09203e-09):0,KY072261.1:3.09203e-09):0,KY072174.1:3.09203e-09):0,KY072146.1:3.09203e-09):0,KP259020.1:3.09203e-09):0,KP259004.1:3.09203e-09):0.00234357,((KY072458.1:0,((KY072490.1:0,KY072495.1:0):0,(KY072601.1:0,KY072662.1:0):0):0):0,((KY072382.1:0,KP259033.1:0):0,KY072383.1:0):0):3.54967e-05):0.000187633,(KY072600.1:0.00236741,((KY072422.1:0,(KY072388.1:0,KY072389.1:0):0):0,((KY072370.1:0,KY072377.1:0):0,(KP259058.1:0,KY072268.1:0):0):0):0):1.16517e-05):0.00220091):0.000322574):0.000708561):0.0266027):0.0069557):0.0394167);";

	//$newick = "(d,e,(c,(a,b)));";
	
	$newick = file_get_contents('problem trees/phylo.io.1.nwk-fixed');
	$newick = file_get_contents('legume.tre');

	$t = parse_newick($newick);

	echo $t->WriteNewick() . "\n";
	
	$n = new NodeIterator ($t->root);
	$a = $n->Begin();
	while ($a != NULL)
	{
		echo $a->GetAttribute('edge_length') . "\n";
		$a = $n->Next();
	}


	
}



?>
