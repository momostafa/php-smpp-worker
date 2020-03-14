<?php

namespace Vemid\SmppWorker;

/**
 * Class SmsMessage
 * @package Vemid\SmppWorker
 */
class SmsMessage
{
    public $id;
    public $sender;
    public $message;
    public $recipients;
    public $retries;
    public $lastRetry;
    public $isFlashSms;

    /**
     * SmsMessage constructor.
     * @param $id
     * @param $sender
     * @param $message
     * @param $recipients
     * @param bool $isFlashSms
     */
    public function __construct($id, $sender, $message, $recipients, $isFlashSms=false)
    {
        $this->id = $id;
        $this->sender = $sender;
        $this->message = $message;
        $this->recipients = $recipients;
        $this->retries = 0;
        $this->lastRetry = null;
        $this->isFlashSms = $isFlashSms;
    }
}
