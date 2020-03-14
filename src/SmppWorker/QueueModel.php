<?php

declare(strict_types=1);

namespace Vemid\SmppWorker;

/**
 * Class QueueModel
 * @package Vemid\SmppWorker
 */
class QueueModel
{
    protected $redis;
    protected $key;
    protected $useIgBinary;
    protected $options;

    /**
     * QueueModel constructor.
     * @param $options
     */
    public function __construct($options)
    {
        $this->redis = new \Redis();
        $this->redis->connect($options['queue']['host'], $options['queue']['port'], $options['queue']['connect_timeout']);
        $this->redis->select($options['queue']['index']);
        $this->key = $options['queue']['queuekey'];
        $this->useIgBinary = ($options['queue']['use_igbinary'] && function_exists('igbinary_serialize'));
        $this->options = $options;
    }

    /**
     * Close connection to queue backend
     */
    public function close()
    {
        $this->redis->close();
    }

    /**
     * @param $messages
     * @return mixed
     */
    public function produce($messages)
    {
        $pipeline = $this->redis->multi(\Redis::PIPELINE);

        foreach ($messages as $m) {
            $pipeline->lpush($this->key . ':inactive', $this->serialize($m));
        }

        $replies = $pipeline->exec();

        return end($replies);
    }

    /**
     * @param $pid
     * @param int $timeout
     * @return mixed|null
     */
    public function consume($pid, $timeout = 5)
    {
        $m = $this->redis->brpoplpush($this->key . ':inactive', $this->key . ':active:' . $pid, $timeout);

        if ($m === null || $m === '*-1' || $m === '*') {
            return null;
        }

        return $this->unserialize($m);
    }

    /**
     * @param $smsId
     * @param array $smscIds
     * @param array $msisdns
     * @return mixed
     */
    public function storeIds($smsId, array $smscIds, array $msisdns)
    {
        $retention = (int)$this->options['queue']['retention'];
        $pipeline = $this->redis->multi(\Redis::PIPELINE);

        foreach ($smscIds as $i => $id) {
            $pipeline->sAdd($this->key . ':ids:' . $smsId, $id);
            $pipeline->sAdd($this->key . ':msisdns:' . $smsId, $msisdns[$i]);
            $pipeline->setex($this->key . ':id:' . $id, 3600 * $retention, $smsId);
        }

        $pipeline->expire($this->key . ':ids:' . $smsId, 3600 * $retention);
        $pipeline->expire($this->key . ':msisdns:' . $smsId, 3600 * $retention);
        $replies = $pipeline->exec();

        return end($replies);
    }

    /**
     * @param $smscIds
     * @return array|bool
     */
    public function getSmsIds($smscIds)
    {
        $pipeline = $this->redis->multi(\Redis::PIPELINE);

        foreach ($smscIds as $i => $id) {
            $pipeline->get($this->key . ':id:' . $id);
        }

        $replies = $pipeline->exec();
        if (!$replies) {
            return false;
        }

        $smsids = [];
        foreach ($replies as $i => $reply) {
            if ($reply) $smsids[$smscIds[$i]] = $reply;
        }

        if (empty($smsids)) {
            return false;
        }

        return $smsids;
    }

    /**
     * @param array $dlrs
     * @return mixed
     */
    public function storeDlr(array $dlrs)
    {
        $pipeline = $this->redis->multi(\Redis::PIPELINE);

        foreach ($dlrs as $dlr) {
            $d = call_user_func((($this->options['queue']['use_igbinary_for_dlr']) ? 'igbinary_serialize' : 'serialize'), $dlr);
            $pipeline->lPush($this->options['queue']['dlr_queue'], $d);
        }

        $replies = $pipeline->exec();

        return end($replies);
    }

    /**
     * @param $pid
     * @param SmsMessage $message
     */
    public function defer($pid, SmsMessage $message)
    {
        $m = $this->serialize($message);
        $this->redis->lRem($this->key . ':active:' . $pid, $m);
        $this->redis->lPush($this->key . ':deferred', $m);
    }

    /**
     * @param $smsId
     * @return array
     */
    public function getMsisdnsForMessage($smsId)
    {
        return $this->redis->sMembers($this->key . ':msisdns:' . $smsId);
    }

    /**
     * @return mixed|null
     */
    public function lastDeferred()
    {
        $m = $this->redis->lIndex($this->key . ':deferred', -1);

        if ($m === null || $m === '*-1' || $m === '*') {
            return null;
        }

        return $this->unserialize($m);
    }

    /**
     * @return bool|mixed
     */
    public function popLastDeferred()
    {
        $m = $this->redis->rPop($this->key . ':deferred');
        $m = $this->unserialize($m);

        return $m;
    }

    /**
     * @throws \RedisException
     */
    public function ping()
    {
        $this->redis->ping();
    }

    /**
     * @param $d
     * @return mixed
     */
    private function unserialize($d)
    {
        return call_user_func(($this->useIgBinary ? 'igbinary_unserialize' : 'unserialize'), $d);
    }

    /**
     * @param $d
     * @return mixed
     */
    private function serialize($d)
    {
        return call_user_func(($this->useIgBinary ? 'igbinary_serialize' : 'serialize'), $d);
    }
}
