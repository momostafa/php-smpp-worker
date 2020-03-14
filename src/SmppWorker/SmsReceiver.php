<?php

namespace Vemid\SmppWorker;

use OnlineCity\SMPP\SmppClient;
use OnlineCity\SMPP\Unit\SmppDeliveryReceipt;
use OnlineCity\Transport\SocketTransport;

/**
 * Class SmsReceiver
 */
class SmsReceiver
{
    /** @var array */
	protected $options;

	/** @var SocketTransport */
	protected $transport;

	/** @var SmppClient */
	protected $client;

	/** @var QueueModel */
	protected $queue;

	/** @var bool */
	protected $debug;

	/** @var array */
	protected $dlrs;

	/** @var int */
	private $lastEnquireLink;

    /**
     * SmsReceiver constructor.
     * @param array $options
     */
	public function __construct(array $options)
	{
		$this->options = $options;
		$this->debug = $this->options['sender']['debug'];
		pcntl_signal(SIGTERM, [$this, 'disconnect'], true);

		gc_enable();
	}

	public function disconnect()
	{
		if (isset($this->queue) && !empty($this->dlrs)) {
			$this->processDlrs();
		}

		if (isset($this->queue)) {
		    $this->queue->close();
        }

		if (isset($this->transport) && $this->transport->isOpen()) {
			if (isset($this->client)) {
				try {
					$this->client->close();
				} catch (\Exception $e) {
					$this->transport->close();
				}
			} else {
				$this->transport->close();
			}
		}

		exit();
	}


	protected function connect()
	{
		$this->queue = new QueueModel($this->options);

		SocketTransport::$defaultDebug = $this->debug;
		SocketTransport::$forceIpv6 = $this->options['connection']['forceIpv6'];
		SocketTransport::$forceIpv4 = $this->options['connection']['forceIpv4'];

		$h = $this->options['connection']['hosts'];
		$p = $this->options['connection']['ports'];
		$d = $this->options['general']['debug_handler'];

		$this->transport = new SocketTransport($h,$p,false,$d);

		$this->transport->setRecvTimeout($this->options['receiver']['connect_timeout']);
		$this->transport->setSendTimeout($this->options['receiver']['connect_timeout']);
		$this->transport->open();
		$this->transport->setSendTimeout($this->options['receiver']['send_timeout']);
		$this->transport->setRecvTimeout(5000);

		$this->client = new SmppClient($this->transport, $this->options['general']['protocol_debug_handler']);
		$this->client->debug = $this->options['receiver']['smpp_debug'];
		$this->client->bindReceiver($this->options['connection']['login'], $this->options['connection']['password']);
			
		SmppClient::$sms_null_terminate_octetstrings = $this->options['connection']['null_terminate_octetstrings'];
	}

    /**
     * @param $s
     */
	private function debug($s)
	{
		call_user_func($this->options['general']['debug_handler'], sprintf('PID:%s - %s', getmypid(), $s));
	}

    /**
     * @throws \RedisException
     */
	protected function ping()
	{
		$this->queue->ping();
		$this->client->enquireLink();
		$this->client->respondEnquireLink();
	}

	protected function processDlrs()
	{
		$smscIds = array_keys($this->dlrs);
		$smsIds = $this->queue->getSmsIds($smscIds);

		if (!$smsIds) {
		    return;
        }
		
		$reports = [];
		foreach ($smsIds as $smscId => $smsId) {
            /* @var $dlr SmppDeliveryReceipt */
			$dlr = $this->dlrs[$smscId];
				
			$msisdn = $dlr->source->value;
			switch ($dlr->stat) {
				case 'DELIVRD':
					$statusCode = DeliveryReport::STATUS_DELIVERED;
					$errorCode = null;
					break;
				case 'EXPIRED':
					$statusCode = DeliveryReport::STATUS_EXPIRED;
					$errorCode = DeliveryReport::ERROR_EXPIRED;
					break;
				case 'DELETED':
					$statusCode = DeliveryReport::STATUS_EXPIRED;
					$errorCode = DeliveryReport::ERROR_DELETED;
					break;
				case 'ACCEPTD':
					$statusCode = DeliveryReport::STATUS_BUFFERED;
					$errorCode = null;
					break;
				case 'REJECTD':
					$statusCode = DeliveryReport::STATUS_ERROR;
					$errorCode = DeliveryReport::ERROR_UNKNOWN_RECIPIENT;
					break;
				case 'UNKNOWN':
				case 'UNDELIV':
				default:
					$statusCode = DeliveryReport::STATUS_ERROR;
					$errorCode = DeliveryReport::ERROR_UNKNOWN;
					break;
			}
			$report = new DeliveryReport($smsId, $msisdn, $dlr->doneDate, $statusCode, $errorCode, $this->options['receiver']['dlr_provider_id']);

			$reports[$smscId] = $report;
		}
		
		$this->queue->storeDlr($reports);
		foreach ($reports as $smscId => $report) {
			unset($this->dlrs[$smscId]);
		}

		unset($reports);
		foreach ($this->dlrs as $dlrId => $dlr) {
		    /* @var $dlr SmppDeliveryReceipt */
			if ($dlr->doneDate < (time()-3600)) {
				$this->debug('Could not match SMSC ID: '.$dlr->id.' to a SMS ID within an hour. Giving up.');
				unset($this->dlrs[$dlrId]);
				continue;
			}
		}
	}

	private function checkMemory()
	{
		gc_collect_cycles();

		if ((memory_get_usage(true)/1024/1024)>64) {
			$this->debug('Reached memory max, exiting');
			$this->disconnect();
		}
	}

    /**
     * @throws \RedisException
     */
	public function run()
	{
		$this->connect();

		$this->lastEnquireLink = 0;

		try {

			$i = 0;
				
			while (true) {
				if (posix_getppid() === 1) {
					$this->disconnect();
					exit();
				}

				if (time()-$this->lastEnquireLink >= $this->options['connection']['enquire_link_timeout']) {
					$this->ping();
					$this->lastEnquireLink = time();
				}

				if ($i % 500 === 0) {
					if (!empty($this->dlrs)) {
					    $this->processDlrs();
                    }

					$this->checkMemory();
				}

				$sms = $this->client->readSMS();
				if ($sms === false) {
					if (!empty($this->dlrs)) {
					    $this->processDlrs();
                    }

					$this->checkMemory();
					continue;
				}

				$i++;

				if (!$sms instanceof SmppDeliveryReceipt) {
					$this->debug('Received SMS instead of DeliveryReceipt, this should not happen. SMS:'.var_export($sms,true));
					continue;
				}

				if ($this->options['receiver']['override_dlr_donedate']) {
					$sms->doneDate = time();
				}

				$this->dlrs[$sms->id] = $sms;
			}
		} catch (\Exception $e) {
			$this->debug(sprintf('Caught %s: %s%s%s', get_class($e), $e->getMessage(), PHP_EOL, $e->getTraceAsString()));
			$this->disconnect();
		}
	}
}
