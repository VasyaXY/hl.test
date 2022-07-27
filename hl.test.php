<?php

/*
	High load test (DDOS test)
	v@x8x9.net (Vasya)
*/

if (!extension_loaded('openswoole')){
	echo "For use this program you must install openswoole php module.
How to install: https://openswoole.com/ or try next command:
pecl install openswoole
Say 'yes' for this options: 'enable sockets supports', 'enable openssl support', 'enable curl support'\n";
	die();
}

use Swoole\Coroutine as co;
use Swoole\Coroutine\Http\Client as client;
use Swoole\Coroutine\Channel as Channel;

Co::set(['hook_flags'=> SWOOLE_HOOK_ALL]);

/* Thread manage class */

class oHL {
	private $url = '';			// Url to process
	private $count = 100;		// Default threads count

	public $aTh = [];			// Array of Threads
	public $channel2Th = [];	// Chanels to threads

	function __construct (){
		
	}

	function start(){
		if ($this->checkCommandLine()){

			$this->makeTables();
			$this->makeThs();
			$this->makeRequest();

			$this->waitForDone();
		}
	}

	function checkCommandLine(){
		global $argv;

		if (count($argv) == 1 || count($argv) > 3){
			$this->out('use: php ./hl.test.php <threads count> <url to process>');
			$this->out('example: php ./hl.test.php 100 https://example.com/');
		}
		else{
			if (count($argv) == 2){
				$url = $argv[1];
			}
			elseif (count($argv) == 3){
				$this->count = (int)$argv[1];
				$this->url = $argv[2];
			}	
			$this->out('url to process: ' . $this->url);
			$this->out('count threads: ' . $this->count);

			return true;
		}
	}

	function makeTables(){
		$this->tStatCode = new \Swoole\Table(255);
		$this->tStatCode->column('count', swoole_table::TYPE_INT);
		$this->tStatCode->create();

		$this->tStatTime = new \Swoole\Table(255);
		$this->tStatTime->column('summ', swoole_table::TYPE_FLOAT);
		$this->tStatTime->create();

		$this->tStatPeak = new \Swoole\Table(255);
		$this->tStatPeak->column('max', swoole_table::TYPE_FLOAT);
		$this->tStatPeak->column('min', swoole_table::TYPE_FLOAT);
		$this->tStatPeak->create();
	}

	function makeThs(){
		$this->out("Add " . $this->count . ' threads...');

		$p = $this;

		for ($i=0; $i<$p->count; $i++)
		{
			go(function() use ($p)
			{
				$cid = co::getuid();
				$p->channel2Th[$cid] = new Channel(10);

				$p->aTh[$cid] = [
					'th' => new oTh($p, []),
					'stat' => []
				];
				$p->aTh[$cid]['th']->run();
			});
		}

		$this->out("done");
	}

	function waitForDone(){
		$p = $this;
		go(function () use ($p) {
			while (count($p->channel2Th) > 0)
			{
				Co::sleep(0.5);
			}
			$p->out('All threads done');
			$p->outStat();
		});
	}

	function makeRequest(){
		$p = $this;
		go(function () use ($p) {
			foreach ($p->channel2Th as $cid => $c){
				$c->push(['cmd' => 'checkUrl', 'url' => $p->url]);
			}
		});
	}

	function out(){
		foreach (func_get_args() as $k=>$v){
			if (is_array($v))
				print_r($v);
			else
				echo $v . "\n";
		}
	}

	function fGetMicrotime(){
		list($usec, $sec) = explode(" ",microtime());
		return ((float)$usec + (float)$sec);
	}

	function makeStat($in){
		$this->tStatCode->incr($in['code'], 'count');
		$this->tStatTime->set($in['code'], ['summ' => $this->tStatTime->get($in['code'], 'summ') + $in['time']]);

		if ($this->tStatPeak->get($in['code'], 'min') == 0 || $this->tStatPeak->get($in['code'], 'min') > $in['time']){
			$this->tStatPeak->set($in['code'], ['min' => $in['time']]);
		}

		if ($this->tStatPeak->get($in['code'], 'max') < $in['time']){
			$this->tStatPeak->set($in['code'], ['max' => $in['time']]);
		}
	}

	function outStat(){
		$this->out('--------- Stat ----------');

		foreach($this->tStatCode as $code => $row) {
			$this->out(
				'Status code: ' . $code . 
				', ' . $row['count'] . ' times, ' .
				'avg time request: ' . Round($this->tStatTime->get($code, 'summ') / $row['count'], 4) .
				', max time: ' . $this->tStatPeak->get($code, 'max') .
				', min time: ' . $this->tStatPeak->get($code, 'min')
			);

			if ($code == -1)
				$this->out('-1: The connection timed out, the server does not monitor the port or the network and then the connection is lost');
			elseif ($code == -2)
				$this->out('-2: The request timed out and the server did not return a response within the specified timeout time.');
			elseif ($code == -3)
				$this->out('-3: After the client request is sent, the server forcibly cuts off the connection.');
			elseif ($code == -4)
				$this->out('-4: The client failed to send data to the remote host.');
		}
	}
}

/* Thread class */

class oTh{
	public $p = null;			// Parrent class
	public $cid = 0;			// thread pid
	public $data;				// variable for data from channel
	public $clientHTTP;			// Client http object

	public $params = [			// Params for thread
		'url' => ''
	];

	public $while = true;

	public function __construct($p, $params = '')
	{
		$this->p = $p;
		if (is_array($params))
			$this->params = $params;

		$this->cid = co::getuid();
	}
	
	function run($data = null)
	{
		while ($this->while)
		{
			Co::sleep(0.5);

			if (!$this->p->channel2Th[$this->cid]->isEmpty()){
				$this->data = $this->p->channel2Th[$this->cid]->pop();
//				$this->p->out('Receive cmd: ', $this->data);
				if (method_exists($this, $this->data['cmd'])){
					$cmd = $this->data['cmd'];
					$this->$cmd();
					$this->while = false;
				}
			}
		}

		$this->p->channel2Th[$this->cid]->close();
		unset($this->p->channel2Th[$this->cid]);
	}

	// url checker

	function checkUrl()
	{
		$start_time = $this->p->fGetMicrotime();

		$ui = parse_url($this->data['url']);

		$this->clientHTTP = new client($ui['host'], (isset($ui['port'])?$ui['port']:(isset($ui['scheme']) && $ui['scheme'] == 'https'?443:80)), (isset($ui['scheme']) && $ui['scheme'] == 'https'));
		$this->clientHTTP->setHeaders([
			'Host' => $ui['host'],
			"User-Agent" => 'Chrome/49.0.2587.3',
			'Accept' => 'text/html,application/xhtml+xml,application/xml',
			'Accept-Encoding' => 'gzip',
		]);
		$this->clientHTTP->set(['timeout' => 30]);

		$this->clientHTTP->get(isset($ui['path'])?$ui['path']:'/');
		$this->clientHTTP->close();

		$end_time = $this->p->fGetMicrotime();

//		$this->p->out('checkUrl #' . $this->cid . ' time process: ' . Round($end_time - $start_time, 4) . ' receive code: ' . $this->clientHTTP->getStatusCode() . ' for url ' . $this->data['url']);
		$this->p->makeStat(['code' => $this->clientHTTP->getStatusCode(), 'time' => Round($end_time - $start_time, 4)]);
	}

}

$hl = new oHL;
$hl->start();

?>