<?php

declare(strict_types=1);

namespace Vemid\SmppWorker;

/**
 * Class QueueManager
 * @package Vemid\SmppWorker
 */
class QueueManager
{
    /** @var array */
    protected $options;


    protected $debug;

    /** @var QueueModel */
    protected $queue;

    /**
     * QueueManager constructor.
     * @param $options
     */
    public function __construct($options)
    {
        $this->options = $options;
        $this->debug = $this->options['queuemanager']['debug'];
        pcntl_signal(SIGTERM, [$this, 'disconnect'], true);
        gc_enable();
    }

    /**
     *
     */
    public function disconnect()
    {
        if (isset($this->queue)) {
            $this->queue->close();
        }
    }

    /**
     * Shorthand method for calling debug handler
     * @param string $s
     */
    private function debug($s)
    {
        call_user_func($this->options['general']['debug_handler'], sprintf('PID:%s - %s', getmypid(), $s));
    }

    /**
     * Run garbage collect and check memory limit
     */
    private function checkMemory()
    {
        gc_collect_cycles();

        if ((memory_get_usage(true) / 1024 / 1024) > 64) {
            $this->debug('Reached memory max, exiting');
            exit();
        }
    }

    /**
     * This service's main loop
     */
    public function run()
    {
        $this->queue = new QueueModel($this->options);

        openlog('php-smpp', LOG_PID, LOG_USER);

        while (true) {
            if (posix_getppid() === 1) {
                exit();
            }

            $deferred = $this->queue->lastDeferred();
            /* @var $deferred SmsMessage */
            if (!$deferred) { // Idle
                $this->checkMemory();
                sleep(5);
                continue;
            }

            $sinceLast = time() - $deferred->lastRetry;
            $timeToRetry = $this->options['queuemanager']['retry_interval'] - $sinceLast;

            if ($timeToRetry > 0) {
                $this->checkMemory();
                sleep(min(5, $timeToRetry));
                continue;
            }

            if ($deferred->retries <= $this->options['queuemanager']['retries']) { // Retry message delivery
                $this->queue->popLastDeferred();

                $msisdns = $this->queue->getMsisdnsForMessage($deferred->id);
                if (!empty($msisdns)) $deferred->recipients = array_diff($deferred->recipients, $msisdns);
                if (empty($deferred->recipients)) {
                    $this->debug('Deferred message without valid recipients: ' . $deferred->id);
                }

                $this->debug('Retry delivery of failed message: ' . $deferred->id . ' retry #' . $deferred->retries);
                $this->queue->produce([$deferred]);

            } else {
                syslog(LOG_WARNING, __FILE__ . ': Deferred message reached max retries, ID:' . $deferred->id);
                $this->debug('Deferred message reached max retries, ID:' . $deferred->id);
                $this->queue->popLastDeferred();
            }
        }
    }
}
