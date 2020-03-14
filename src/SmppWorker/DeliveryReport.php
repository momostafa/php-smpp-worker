<?php

declare(strict_types=1);

namespace Vemid\SmppWorker;

/**
 * Class DeliveryReport
 * @package Vemid\SmppWorker
 */
class DeliveryReport implements \Serializable
{
    public $providerId;
    public $messageId;
    public $msisdn;
    public $statusReceived;
    public $statusCode;
    public $errorCode;

    // Normal status codes
    const STATUS_DELIVERED = 1;
    const STATUS_BUFFERED = 2;
    const STATUS_ERROR = 3;
    const STATUS_EXPIRED = 4;

    const STATUS_QUEUED = 5;
    const STATUS_INSUFFICIENT_CREDIT = 6;
    const STATUS_BLACKLISTED = 7;
    const STATUS_UNKNOWN_RECIPIENT = 8;
    const STATUS_PROVIDER_ERROR = 9;
    const STATUS_INVALID_SMS_ENCODING = 10;
    const STATUS_DELETED = 11;

    // Error codes
    const ERROR_UNKNOWN = 1;
    const ERROR_EXPIRED = 2;
    const ERROR_INSUFFICIENT_CREDIT = 3;
    const ERROR_BLACKLISTED = 4;
    const ERROR_UNKNOWN_RECIPIENT = 5;
    const ERROR_INVALID_SMS_ENCODING = 6;
    const ERROR_DELETED = 7;

    /**
     * DeliveryReport constructor.
     * @param $messageId
     * @param $msisdn
     * @param $statusReceived
     * @param $statusCode
     * @param null $errorCode
     * @param null $providerId
     */
    public function __construct($messageId, $msisdn, $statusReceived, $statusCode, $errorCode = null, $providerId = null)
    {
        $this->messageId = $messageId;
        $this->msisdn = $msisdn;
        $this->statusReceived = $statusReceived;
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->providerId = $providerId;
    }

    /**
     * @return string
     */
    public function serialize()
    {
        return serialize([
            $this->providerId,
            $this->messageId,
            $this->msisdn,
            $this->statusReceived,
            $this->statusCode,
            $this->errorCode]
        );
    }

    /**
     * @param $data
     */
    public function unserialize($data)
    {
        list($this->providerId, $this->messageId, $this->msisdn, $this->statusReceived, $this->statusCode, $this->errorCode) = unserialize($data);
    }
}
