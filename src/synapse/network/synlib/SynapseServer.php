<?php
namespace synapse\network\synlib;

use synapse\network\SynapseInterface;
use synapse\Thread;

class SynapseServer extends Thread{
	const VERSION = "0.1.0";

	/** @var \ThreadedLogger */
	private $logger;
	/** @var string */
	private $interface;
	/** @var int */
	private $port;
	private $shutdown = true;
	/** @var \Threaded */
	private $externalQueue, $internalQueue, $clientOpenQueue, $internalClientCloseQueue, $externalClientCloseQueue;
	private $mainPath;
	/** @var SynapseInterface */
	private $server;

	public function __construct(\ThreadedLogger $logger, SynapseInterface $server, \ClassLoader $loader, $port, $interface = "0.0.0.0"){
		$this->logger = $logger;
		$this->server = $server;
		$this->interface = $interface;
		$this->port = (int) $port;
		if($port < 1 or $port > 65536){
			throw new \Exception("Invalid port range");
		}

		$this->setClassLoader($loader);

		$this->shutdown = false;
		$this->externalQueue = new \Threaded;
		$this->internalQueue = new \Threaded;
		$this->clientOpenQueue = new \Threaded;
		$this->internalClientCloseQueue = new \Threaded;
		$this->externalClientCloseQueue = new \Threaded;

		if(\Phar::running(true) !== ""){
			$this->mainPath = \Phar::running(true);
		}else{
			$this->mainPath = \getcwd() . DIRECTORY_SEPARATOR;
		}

		$this->start();
	}

	public function getInternalClientCloseRequest(){
		return $this->internalClientCloseQueue->shift();
	}

	public function addInternalClientCloseRequest(string $hash){
		$this->internalClientCloseQueue[] = $hash;
	}

	public function getExternalClientCloseRequest(){
		return $this->externalClientCloseQueue->shift();
	}

	public function addExternalClientCloseRequest(string $hash){
		$this->externalClientCloseQueue[] = $hash;
	}

	public function getClientOpenRequest(){
		return $this->clientOpenQueue->shift();
	}

	public function addClientOpenRequest(string $hash){
		$this->clientOpenQueue[] = $hash;
	}

	public function getServer(){
		return $this->server;
	}

	public function run(){
		$this->registerClassLoader();
		gc_enable();
		error_reporting(-1);
		ini_set("display_errors", 1);
		ini_set("display_startup_errors", 1);

		set_error_handler([$this, "errorHandler"], E_ALL);
		register_shutdown_function([$this, "shutdownHandler"]);

		try{
			$socket = new SynapseSocket($this->getLogger(), $this->port, $this->interface);
			new SessionManager($this, $socket);
		}catch(\Throwable $e){
			$this->logger->logException($e);
		}
	}

	public function quit(){
		$this->shutdown();
		parent::quit();
	}

	public function shutdownHandler(){
		if($this->shutdown !== true){
			$this->getLogger()->emergency("SynLib crashed!");
		}
	}

	public function errorHandler($errno, $errstr, $errfile, $errline, $context, $trace = null){
		if(error_reporting() === 0){
			return false;
		}
		$errorConversion = [
			E_ERROR => "E_ERROR",
			E_WARNING => "E_WARNING",
			E_PARSE => "E_PARSE",
			E_NOTICE => "E_NOTICE",
			E_CORE_ERROR => "E_CORE_ERROR",
			E_CORE_WARNING => "E_CORE_WARNING",
			E_COMPILE_ERROR => "E_COMPILE_ERROR",
			E_COMPILE_WARNING => "E_COMPILE_WARNING",
			E_USER_ERROR => "E_USER_ERROR",
			E_USER_WARNING => "E_USER_WARNING",
			E_USER_NOTICE => "E_USER_NOTICE",
			E_STRICT => "E_STRICT",
			E_RECOVERABLE_ERROR => "E_RECOVERABLE_ERROR",
			E_DEPRECATED => "E_DEPRECATED",
			E_USER_DEPRECATED => "E_USER_DEPRECATED",
		];
		$errno = isset($errorConversion[$errno]) ? $errorConversion[$errno] : $errno;
		if(($pos = strpos($errstr, "\n")) !== false){
			$errstr = substr($errstr, 0, $pos);
		}
		$oldFile = $errfile;
		$errfile = $this->cleanPath($errfile);

		$this->getLogger()->debug("An $errno error happened: \"$errstr\" in \"$errfile\" at line $errline");

		foreach(($trace = $this->getTrace($trace === null ? 3 : 0, $trace)) as $i => $line){
			$this->getLogger()->debug($line);
		}

		return true;
	}

	public function getTrace($start = 1, $trace = null){
		if($trace === null){
			if(function_exists("xdebug_get_function_stack")){
				$trace = array_reverse(xdebug_get_function_stack());
			}else{
				$e = new \Exception();
				$trace = $e->getTrace();
			}
		}

		$messages = [];
		$j = 0;
		for($i = (int) $start; isset($trace[$i]); ++$i, ++$j){
			$params = "";
			if(isset($trace[$i]["args"]) or isset($trace[$i]["params"])){
				if(isset($trace[$i]["args"])){
					$args = $trace[$i]["args"];
				}else{
					$args = $trace[$i]["params"];
				}
				foreach($args as $name => $value){
					$params .= (is_object($value) ? get_class($value) . " " . (method_exists($value, "__toString") ? $value->__toString() : "object") : gettype($value) . " " . @strval($value)) . ", ";
				}
			}
			$messages[] = "#$j " . (isset($trace[$i]["file"]) ? $this->cleanPath($trace[$i]["file"]) : "") . "(" . (isset($trace[$i]["line"]) ? $trace[$i]["line"] : "") . "): " . (isset($trace[$i]["class"]) ? $trace[$i]["class"] . (($trace[$i]["type"] === "dynamic" or $trace[$i]["type"] === "->") ? "->" : "::") : "") . $trace[$i]["function"] . "(" . substr($params, 0, -2) . ")";
		}

		return $messages;
	}

	public function cleanPath($path){
		return rtrim(str_replace(["\\", ".php", "phar://", rtrim(str_replace(["\\", "phar://"], ["/", ""], $this->mainPath), "/")], ["/", "", "", ""], $path), "/");
	}

	public function getExternalQueue(){
		return $this->externalQueue;
	}

	public function getInternalQueue(){
		return $this->internalQueue;
	}

	public function pushMainToThreadPacket($str){
		$this->internalQueue[] = $str;
	}

	public function readMainToThreadPacket(){
		return $this->internalQueue->shift();
	}

	public function pushThreadToMainPacket($str){
		$this->externalQueue[] = $str;
	}

	public function readThreadToMainPacket(){
		return $this->externalQueue->shift();
	}

	public function isShutdown(){
		return $this->shutdown === true;
	}

	public function shutdown(){
		$this->shutdown = true;
	}

	public function getPort(){
		return $this->port;
	}

	public function getInterface(){
		return $this->interface;
	}

	/**
	 * @return \ThreadedLogger
	 */
	public function getLogger(){
		return $this->logger;
	}

	public function isGarbage() : bool{
		parent::isGarbage();
	}

	public function getThreadName(){
		return "SynapseServer";
	}

}