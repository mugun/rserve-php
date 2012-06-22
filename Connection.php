<?php
/**
 * Rserve client for PHP
 * Supports Rserve protocol 0103 only (used by Rserve 0.5 and higher)
 * $Revision$
 * @author Cl�ment TURBELIN
 * Developped using code from Simple Rserve client for PHP by Simon Urbanek Licensed under GPL v2 or at your option v3
 * $Id$
 */
require_once 'helpers.php';
require_once 'Parser.php';

/**
 * Handle Connection and communicating with Rserve instance (QAP1 protocol)
 * @author Cl�ment Turbelin
 *
 */
class Rserve_Connection {

	const PARSER_NATIVE = 0;
	const PARSER_REXP = 1;
	const PARSER_DEBUG = 2;
	const PARSER_NATIVE_WRAPPED = 3;

	const DT_INT = 1;
	const DT_CHAR = 2;
	const DT_DOUBLE = 3;
	const DT_STRING = 4;
	const DT_BYTESTREAM = 5;
	const DT_SEXP = 10;
	const DT_ARRAY = 11;

	/** this is a flag saying that the contents is large (>0xfffff0) and hence uses 56-bit length field */
	const DT_LARGE = 64;

	const CMD_login			= 0x001;
	const CMD_voidEval		= 0x002;
	const CMD_eval			= 0x003;
	const CMD_shutdown		= 0x004;
	const CMD_openFile		= 0x010;
	const CMD_createFile	= 0x011;
	const CMD_closeFile		= 0x012;
	const CMD_readFile		= 0x013;
	const CMD_writeFile		= 0x014;
	const CMD_removeFile	= 0x015;
	const CMD_setSEXP		= 0x020;
	const CMD_assignSEXP	= 0x021;

	const CMD_setBufferSize	= 0x081;
	const CMD_setEncoding	= 0x082;

	const CMD_detachSession	= 0x030;
	const CMD_detachedVoidEval = 0x031;
	const CMD_attachSession = 0x032;

	// control commands since 0.6-0
	const CMD_ctrlEval		= 0x42;
	const CMD_ctrlSource	= 0x45;
	const CMD_ctrlShutdown	= 0x44;

	// errors as returned by Rserve
	const ERR_auth_failed	= 0x41;
	const ERR_conn_broken	= 0x42;
	const ERR_inv_cmd		= 0x43;
	const ERR_inv_par		= 0x44;
	const ERR_Rerror		= 0x45;
	const ERR_IOerror		= 0x46;
	const ERR_not_open		= 0x47;
	const ERR_access_denied = 0x48;
	const ERR_unsupported_cmd=0x49;
	const ERR_unknown_cmd	= 0x4a;
	const ERR_data_overflow	= 0x4b;
	const ERR_object_too_big = 0x4c;
	const ERR_out_of_mem	= 0x4d;
	const ERR_ctrl_closed	= 0x4e;
	const ERR_session_busy	= 0x50;
	const ERR_detach_failed	= 0x51;

	public static $machine_is_bigendian = NULL;

	private static $init = FALSE;

	private $host;
	private $port;
	private $socket;
	private $auth_request;
	private $auth_method;

	/**
	 * initialization of the library
	 */
	public static function init() {
		if( self::$init ) {
			return;
		}
		$m = pack('s', 1);
		self::$machine_is_bigendian = ($m[0] == 0);
		spl_autoload_register('Rserve_Connection::autoload');
		self::$init = TRUE;
	}

	public static function autoload($name) {
		$s = strtolower(substr($name, 0, 6));
		if($s != 'rserve') {
			return FALSE;
		}
		$s = substr($name, 7);
		$s = str_replace('_', '/', $s);
		$s .= '.php';
		require $s;
		return TRUE;
	}

	/**
	 *  if port is 0 then host is interpreted as unix socket,
	 *  otherwise host is the host to connect to (default is local) and port is the TCP port number (6311 is the default)
	 */
	public function __construct($host='127.0.0.1', $port = 6311, $debug = FALSE) {
		if( !self::$init ) {
			self::init();
		}
		$this->host = $host;
		$this->port = $port;
		$this->socket = $this->openSocket();
	}

	/**
	 * Open a new socket to Rserv
	 * @return resource socket
	 */
	private function openSocket() {
		if( $this->port == 0 ) {
			$socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
		} else {
			$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		}
		if( !$socket ) {
			throw new Rserve_Exception('Unable to create socket ['.socket_strerror(socket_last_error()).']');
		}
		//socket_set_option($socket, SOL_TCP, SO_DEBUG,2);
		$ok = socket_connect($socket, $this->host, $this->port);
		if( !$ok ) {
			throw new Rserve_Exception('Unable to connect ['.socket_strerror(socket_last_error()).']');
		}
		$buf = '';
		$n = socket_recv($socket, $buf, 32, 0);
		if( $n < 32 || strncmp($buf, 'Rsrv', 4) != 0 ) {
			throw new Rserve_Exception('Invalid response from server.');
		}
		$rv = substr($buf, 4, 4);
		if( strcmp($rv, '0103') != 0 ) {
			throw new Rserve_Exception('Unsupported protocol version.');
		}
		for($i = 12; $i < 32; $i += 4) {
			$attr = substr($buf, $i, $i + 4);
			if($attr == 'ARpt') {
				$this->auth_request = TRUE;
				$this->auth_method = 'plain';
			} elseif($attr == 'ARuc') {
				$this->auth_request = TRUE;
				$this->auth_method = 'crypt';
			}
			if($attr[0] === 'K') {
				$key = substr($attr, 1, 3);
			}
		}
		return $socket;
	}

	/**
	 * Parse a response from Rserve
	 * @param string $r
	 * @param int $parser
	 * @return parsed results 
	 */
	private function parseResponse($buf, $parser) {
		$type = int8($buf, 0);
		if($type != self::DT_SEXP) { // Check Data type of the packet
			throw new Rserve_Exception('Unexpected packet Data type (expect DT_SEXP)', $buf);
		}
		$i = 4; // + 4 bytes (Data part HEADER)
		$r = NULL;
		switch($parser) {
			case self::PARSER_NATIVE:
				$r = Rserve_Parser::parse($buf, $i);
				break;
			case self::PARSER_REXP:
				$r = Rserve_Parser::parseREXP($buf, $i);
				break;
			case self::PARSER_DEBUG:
				$r = Rserve_Parser::parseDebug($buf, $i);
				break;
			case self::PARSER_NATIVE_WRAPPED:
				$old = Rserve_Parser::$use_array_object;
				Rserve_Parser::$use_array_object = TRUE;
				$r = Rserve_Parser::parse($buf, $i);
				Rserve_Parser::$use_array_object = $old;
				break;
			default:
				throw new Rserve_Exception('Unknown parser');
		}
		return $r;
	}

	/**
	 * Evaluate a string as an R code and return result
	 * @param string $string
	 * @param int $parser
	 */
	public function evalString($string, $parser = self::PARSER_NATIVE) {
		$r = $this->command(self::CMD_eval, $string );
		if( !$r['is_error'] ) {
			//$this->debugPacket($r);
			return $this->parseResponse($r['contents'], $parser);
		}
		throw new Rserve_Exception('unable to evaluate', $r);
	}

	
	
	/**
	 * Get the response from a command
	 * @param resource	$socket
	 * @return array contents
	 */
	public function getResponse($socket) {
		$header = NULL;
		$n = socket_recv($socket, $header, 16, 0);
		if ($n != 16) {
			// header should be sent in one block of 16 bytes
			return FALSE;
		}
		$len = int32($header, 4);
		$ltg = $len; // length to get
		$buf = '';
		while ($ltg > 0) {
			$n = socket_recv($socket, $b2, $ltg, 0);
			if ($n > 0) {
				$buf .= $b2;
				unset($b2);
				$ltg -= $n;
			} else {
			 break;
			}
		}
		$res = int32($header);
		return(array(
			'code'=>$res,
			'is_error'=>($res & 15) != 1,
			'error'=>($res >> 24) & 127,
			'header'=>$header,
			'contents'=>$buf // Buffer contains messages part
		));
	}

	/**
	 * Create a new connection to Rserve for async calls
	 * @return	resource the socket ref
	 */
	public function newConnection() {
		return $this->openSocket();
	}

	/**
	 * Evaluate string asyncronously
	 * @param resource	$socket
	 * @param string $string
	 * @return results @see evalString()
	 */
	public function evalStringAsync($socket, $string) {
		return $this->command(self::CMD_eval, $string, $socket);
	}

	/**
	 * Get response from socket, for async
	 * @param resource $socket
	 * @param int $parser
	 * @return mixed contents of response
	 */
	public function getResponseAsync($socket, $parser = self::PARSER_NATIVE) {
		$r = $this->getResponse($socket);
		if( !$r['is_error'] ) {
			return $this->parseResponse($r['contents'], $parser);
		}
		throw new Rserve_Exception('unable to evaluate', $r);
	}

	/**
	 * Close a connection for async calling
	 * @param resource $socket
	 * @return bool
	 */
	public function closeConnection($socket) {
		if($socket) {
			return socket_close($socket);
		}
		return TRUE;
	}

	/**
	 * Close the current connection
	 */
	public function close() {
		return $this->closeConnection($this->socket);
	}

	/**
	 * send a command to R
	 * @param int $command command code
	 * @param string $v command contents
	 * @param	resource	$socket socket to use
	 * @return int	if $async, the new socket
	 */
	private function command($command, $v, $socket = 0) {
		
		if ( !is_resource($socket) ) {
			$socket = $this->socket;
		}
		
		$pkt = _rserve_make_packet($command, $v);
		
		socket_send($socket, $pkt, strlen($pkt), 0);

		// if different, async call
		if ( $socket !== $this->socket) {
			return;
		}

		// get response
		return $this->getResponse($socket);
	}

	public function debugPacket($packet) {
		/*
		  [0]  (int) command
		  [4]  (int) length of the message (bits 0-31)
		  [8]  (int) offset of the data part
		  [12] (int) length of the message (bits 32-63)
		*/
		$header = $packet['header'];
		$command = int32($header, 0);
		$lengthLow = int32($header, 4);
		$offset = int32($header, 8);
		$lenghtHigh = int32($header, 12);
		echo '[header:<'.dechex($command).' Length:'.dechex($lenghtHigh).'-'.dechex($lengthLow).' offset'.$offset.">\n";
		$buf = $packet['contents'];
		$len = strlen($buf);
		$i = 0;
		while($len > 0) {
			$type = int8($buf, $i);
			$m_len = int24($buf, 1);
			$i += 4;
			$i += $m_len;
			$len -= $m_len + 4;
			echo 'data:<'.$this->getDataTypeTitle($type).' length:'.$m_len.">\n"; 
		}
		echo "]\n";
	}
	
	/**
	 * Assign a value to a symbol in R
	 * @param string $symbol name of the variable to set (should be compliant with R syntax !)
	 * @param Rserve_REXP $value value to set
	 Commented because not ready for this release
	 public function assign($symbol, $value) {
		if(! is_object($symbol) and !$symbol instanceof Rserve_REXP_Symbol) {
		$symbol = (string)$symbol;
		$s = new Rserve_REXP_Symbol();
		$s->setValue($symbol);
		}
		if(!is_object($value) AND ! $value instanceof Rserve_REXP) {
		throw new InvalidArgumentException('value should be REXP object');
		}
		$contents .= Rserve_Parser::createBinary($s);
		$contents .= Rserve_Parser::createBinary($value);
		}
		*/

	public function getDataTypeTitle($x) {
		switch($x) {
		case self::DT_INT : 
			$m = 'int';
			break;
		case self::DT_CHAR : 			
			$m = 'char';
			break;
		case self::DT_DOUBLE : 			
			$m = 'double';
			break;
		case self::DT_STRING : 			
			$m = 'string';
			break;
		case self::DT_BYTESTREAM : 			
			$m = 'stream';
			break;
			
		case self::DT_SEXP : 			
			$m = 'sexp';
			break;
			
		case self::DT_ARRAY :			
			$m = 'array';
			break;
		default:
			$m = 'unknown';			
		}
		return $m;
	}
	
}

class Rserve_Exception extends Exception {
	public $packet;
	public function __construct($message, $packet=NULL) {
		parent::__construct($message);
		$this->packet = $packet;
	}
}

class Rserve_Parser_Exception extends Rserve_Exception {
}

Rserve_Connection::init();

