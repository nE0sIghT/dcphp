<?php
/*
	Filename: class.dc.php
	Version: 0.0.0.1
	Description: Direct Connect class for PHP
	Copyright: 2009 nE0sIghT
	License: http://opensource.org/licenses/gpl-license.php GNU Public License
*/


/*
	Usage:

	<?php
	require("class.dc.php");

	$dcpp = new dcpp(array(
		'host'			=> 'localhost', 	// HUB hostname
		'port'			=> 4111,		// HUB port
		'user'			=> time(),		// User name
		'passwd'		=> 'asdf',		// User password

		'tag_enabled'		=> true,		// Enable tag in description

		'client'		=> 'ApexDC++',		// Client in the tag
		'version'		=> '1.2.0',		// Client version in the tag
		'shared'		=> 20*1024*1024*1024,	// Share amount
		'slots'			=> 4,

		'socket_timeout'	=> 5,
		'stream_timeout'	=> 2,

		'functions'		=> array(		// Callback functions
			# function public_message($nick, $message)
			'on_public_message'		=> 'public_message',	// On message in the chat

			# function private_message($nick, $message)
			'on_private_message'		=> 'private_message',	// On private message

			# function idle()
			'on_idle'			=> 'idle',		// On no action

			# function close()
			'on_close'			=> 'close',		// On connection close

			# function nick_error()
			'on_nick_error'			=> 'nick_error',	// On nick error

			# function login()
			'on_login'			=> 'login',		// When logged in the hub

			# function hubname($hubname)
			'on_hubname'			=> 'hubname',		// Whe received hubname

			# function hubtopic($topic)
			'on_hubtopic'			=> 'hubtopic',		// When received hub topic

			# function unknown_cmd($cmd, $data)
			'on_unknown_cmd'		=> 'unknown_cmd',	// When received unknown DC command

			# function unknown_packet($packet)
			'on_unknown_packet'		=> 'unknown_packet',	// When received unknown network packet
		),
		// More callbacks below
	));

	function login()
	{
		echo "Logged In!!";
	}
	...
	?>
*/

class dcpp
{
	var $link;
	var $socket_timeout;
	var $stream_timeout;
	var $errno;
	var $errstr;

	var $config;
	var $functions = array(
		'on_public_message'		=> '',
		'on_private_message'		=> '',
		'on_idle'			=> '',

		'on_close'			=> '',
		'on_nick_error'			=> '',
		'on_login'			=> '',
		'on_hubname'			=> '',
		'on_hubtopic'			=> '',
		'on_ctm'			=> '',
		'on_rctm'			=> '',
		'on_myinfo_error'		=> '',
		'on_search'			=> '',
		'on_force_move'			=> '',
		'on_unknown_cmd'		=> '',
		'on_unknown_packet'		=> '',
	);

	var $hub;
	var $nicklist;
	var $oplist;

	function dcpp($settings)
	{
		$this->config['host'] = isset($settings['host']) ? $settings['host'] : 'localhost';
		$this->config['port'] = isset($settings['port']) ? $settings['port'] : 411;

		$this->config['user'] = isset($settings['user']) ? $settings['user'] : 'DCBot';
		$this->config['passwd'] = isset($settings['passwd']) ? $settings['passwd'] : '';

		$this->config['tag_enabled'] = isset($settings['tag_enabled']) ? $settings['tag_enabled'] : false;

		$this->config['client'] = isset($settings['client']) ? $settings['client'] : 'DCBot';
		$this->config['version'] = isset($settings['version']) ? $settings['version'] : '1.0';
		$this->config['description'] = isset($settings['description']) ? $settings['description'] : '';
		$this->config['email'] = isset($settings['email']) ? $settings['email'] : '';
		$this->config['shared'] = isset($settings['shared']) ? $settings['shared'] : '0';

		$this->config['slots'] = isset($settings['slots']) ? $settings['slots'] : '3';

		$this->socket_timeout = isset($settings['socket_timeout']) ? $settings['socket_timeout'] : 5;
		$this->stream_timeout = isset($settings['stream_timeout']) ? $settings['stream_timeout'] : 2;

		if(isset($settings['functions']) && is_array($settings['functions']))
			$this->functions = array_merge($this->functions, $settings['functions']);

		$this->errno = false;
		$this->errstr = '';

		$this->hub['features'] = $this->oplist = $this->nicklist = array();
	}

	function connect()
	{
		if(!($this->link = fsockopen('tcp://' . $this->config['host'], $this->config['port'], $this->errno, $this->errstr, $this->socket_timeout)))
		{
			$this->close();
		}
		else
		{
			stream_set_timeout($this->link, $this->stream_timeout);

			while($this->link)
			{
				$this->run();
			}
		}
	}

	function close()
	{
		if($this->link)
		{
			$this->send('$Quit ' . $this->config['user'] . '|');
			fclose($this->link);
			$this->link = null;
		}

		if($this->functions['on_close'])
			$this->functions['on_close']();
	}

	function run()
	{
		$meta_data = stream_get_meta_data($this->link);
		if($meta_data['eof'])
		{
			$this->link = null;
			$this->close();
		}
		$packet = $this->get_packet();

		if($packet)
		{
			//file_put_contents('log', 'RECEIVE: ' . $packet . "\n", FILE_APPEND);
			$this->parse_packet($packet);
		}
	}

	function get_packet()
	{
		if($this->link)
		{
			$buffer = '';

			while(($char = fgetc($this->link)) !== false)
			{
				$buffer .= $char;

				if($char == '|')
				{
					return $buffer;
				}
			}
		}

		if($this->functions['on_idle'])
			$this->functions['on_idle']();

		return null;
	}

	function send($string)
	{
		if($this->link)
		{
			//file_put_contents('log', 'SEND: ' . $string . "\n", FILE_APPEND);
			$write = 0;
			$length = strlen($string);

			while($write < $length)
			{
				$out = @fwrite($this->link, substr($string, $write));
				if($out !== false)
				{
					$write += $out;
				}
				else
				{
					echo "fwrite: failed\n";exit;
				}
			}
		}
	}

	function key($lock)
	{
		$len = strlen($lock);
		$key = array();
		$key[0] = ord($lock{0}) ^ ord($lock{$len - 1}) ^ ord($lock{$len - 2}) ^ 5;

		for ($i = 1; $i < $len; $i++)
			$key[$i] = ord($lock{$i}) ^ ord($lock{$i - 1});

		for ($i = 0; $i < $len; $i++)
			$key[$i] = (($key[$i]<<4) & 240) | (($key[$i]>>4) & 15);

		$key = array_map('chr',$key);

		for($i = 0; $i<$len; $i++) 
		{
			if( $key[$i] == chr(0))
				$key[$i] = '/%DCN000%/';
			if( $key[$i] == chr(5))
				$key[$i] = '/%DCN005%/';
			if( $key[$i] == chr(36))
				$key[$i] = '/%DCN036%/';
			if( $key[$i] == chr(96))
				$key[$i] = '/%DCN096%/';
			if( $key[$i] == chr(124))
				$key[$i] = '/%DCN124%/';
			if( $key[$i] == chr(126))
				$key[$i] = '/%DCN126%/';
		}
		$key = implode('',$key);

		return $key;
	}

	function parse_packet($packet)
	{
		if($packet == '|')
			return;

		if($this->is_cmd($packet))
		{
			if(strpos($packet, ' '))
			{
				$cmd = explode(' ', $packet);
				$data = str_replace($cmd[0] . ' ', '', substr($packet, 0, -1));
				$cmd = substr($cmd[0], 1);
			}
			else
				$cmd = substr($packet, 1);

			switch($cmd)
			{
				case 'Lock':
					list($lock,) = explode(' ', $data);
					$this->send('$Supports NoGetINFO|');
					$this->send('$Key ' . $this->key($lock) . '|$ValidateNick ' . $this->config['user'] . '|');
					break;
				case 'ValidateDenide':
					if($this->functions['on_nick_error'])
					{
						if($this->functions['on_nick_error']())
						{
							$this->send('$ValidateNick ' . $this->config['user'] . '|');
							break;
						}
					}

					$this->close();
					break;
				case 'GetPass':
					$this->send('$MyPass ' . $this->config['passwd'] . '|');
					break;
				case 'LogedIn':
					if($this->functions['on_login'])
						$this->functions['on_login']();
					break;
				case 'HubName':
					$this->hub['name'] = $data;

					if($this->functions['on_hubname'])
						$this->functions['on_hubname']($data);
					break;
				case 'Hello':
					$this->send('$Version ' . $this->config['version'] . '|');
					$this->send('$MyINFO $ALL ' . $this->config['user'] . ' ' . $this->config['description'] . $this->get_tag() . '|');
					$this->send('$GetNickList|');
					break;
				case 'HubTopic':
					$this->hub['topic'] = $data;

					if($this->functions['on_hubtopic'])
						$this->functions['on_hubtopic']($data);
					break;
				case 'To:':
					$matches = array();
					if(preg_match('/^' . $this->config['user'] . ' From: (.+) \$(.+) (.+)$/sU', $data, $matches))
					{
						$matches = array_map('trim', $matches);

						if($this->functions['on_private_message'])
							$this->functions['on_private_message']($matches[1], $matches[3]);
					}
					break;
				case 'NickList':
					$users = explode('$$', $data);
					foreach($users AS $tmp)
					{
						if(!in_array('NoGetINFO', $this->hub['features']))
							$this->send('$GetINFO ' . $tmp . ' ' . $this->config['user'] . '|');
						$this->nicklist[$tmp] = array();
					}
					unset($tmp);
					break;
				case 'OpList':
					$users = explode('$$', $data);
					foreach($users AS $tmp)
					{
						$this->oplist[$tmp] = true;
					}
					unset($tmp);
					break;
				case 'ConnectToMe':
					if($this->functions['on_ctm'])
						$this->functions['on_ctm']();
					break;
				case 'RevConnectToMe':
					if($this->functions['on_rctm'])
						$this->functions['on_rctm']();
					break;
				case 'Quit':
					if(array_key_exists($data, $this->nicklist))
						unset($this->nicklist[$data]);

					if(array_key_exists($data, $this->oplist))
						unset($this->oplist[$data]);
					break;
				case 'MyINFO':
					$matches = array();
					if(preg_match('/^\$ALL (.+) (.*)\$ \$(.*)\$(.*)\$(.+)\$$/sU', $data, $matches))
					{
						$matches = array_map('trim', $matches);

						$this->nicklist[$matches[1]] = array(
							'interest'	=> $matches[1],
							'speed'		=> $matches[2],
							'email'		=> $matches[3],
							'share'		=> $matches[4],
						);
					}
					else
					{
						if($this->functions['on_myinfo_error'])
							$this->functions['on_myinfo_error']();
					}
					break;
				case 'Search':
					if($this->functions['on_search'])
						$this->functions['on_search']();
					break;
				case 'ForceMove':
					if($this->functions['on_force_move'])
						$this->functions['on_force_move']();

					$this->send('$Quit ' . $this->config['user'] . '|');
					$this->close();
					break;
				case 'Supports':
					$this->hub['features'] = explode(' ', $data);
					break;
				default:
					if($this->functions['on_unknown_cmd'])
						$this->functions['on_unknown_cmd']($cmd, $data);
					break ;
			}
		}
		else if($this->is_public_message($packet))
		{
			$matches = array();
			if(preg_match('/^<(.+)> (.+)\|$/sU', $packet, $matches))
			{
				if($this->functions['on_public_message'])
				{
					$this->functions['on_public_message']($matches[1], $matches[2]);
				}
			}
		}
		else
		{
			if($this->functions['on_unknown_packet'])
				$this->functions['on_unknown_packet']($packet);
		}
	}

	function is_cmd($packet)
	{
		if(strpos($packet, '$') === 0)
			return true;
		else
			return false;
	}

	function is_public_message($packet)
	{
		if(preg_match('/^<.+>/', $packet))
			return true;
		else
			return false;
	}

	function is_op($user)
	{
		return array_key_exists($user, $this->oplist);
	}

	function is_online($user)
	{
		return array_key_exists($user, $this->nicklist);
	}

	function send_private_message($user, $message)
	{
		if($this->is_online($user) && strlen($message))
		{
			$this->send('$To: ' . $user . ' From: ' . $this->config['user'] . ' $<' . $this->config['user'] . '> ' . $message . '|');

		}
	}

	function get_tag()
	{
		if($this->config['tag_enabled'])
		{
			return '<' . $this->config['client'] . ' V:' . $this->config['version'] . ' ,M:A,H:1/0/0,S:' . $this->config['slots'] . '>$ $DSL4$' . $this->config['email'] . '$' . $this->config['shared'] . '$';
		}
		else
			return '';
	}
}
?>
