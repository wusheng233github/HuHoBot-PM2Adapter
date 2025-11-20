<?php
namespace wusheng233\HuHoBot\network;

class HeartbeatService {
    const STATUS_STOPPED = 0; // 停止，不发心跳不会超时，忘掉ping和pong
    const STATUS_WAITING_PONG = 1; // 发了ping，等待pong，只能超时，不许说话
    const STATUS_IDLE = 2; // 可发ping，可超时
    protected $status = self::STATUS_STOPPED;
    protected $heartperiod; // 默认10
    protected $timeout; // 默认15
    protected $lastping = -1;
    protected $lastpong = -1; // 现在用来算rtt
    protected $executor;
    public function __construct(HeartbeatExecutor $executor, $heartperiod = 10, $timeout = 15) {
        $this->executor = $executor;
        $this->heartperiod = $heartperiod;
        $this->timeout = $timeout;
    }
    public function start() {
        if($this->status === self::STATUS_STOPPED) {
            $this->lastping = -1; // ?
            $this->lastpong = -1;
            $this->sendHeart();
        }
    }
    public function shutdown() {
        $this->status = self::STATUS_STOPPED;
    }
    public function onRun() {
        if($this->status === self::STATUS_STOPPED) {
            return;
        }

        $now = microtime(true);

        if($this->status === self::STATUS_WAITING_PONG) {
            // 检查心跳超时
            if($now > $this->lastping + $this->timeout) {
                $this->executor->onTimeout();
                $this->shutdown();
            }
        } else if($now - $this->lastping > $this->heartperiod) { // self::STATUS_IDLE
            // 检查需要发送心跳
            $this->sendHeart();
        }
    }
    private function sendHeart() {
        $this->status = self::STATUS_WAITING_PONG;
        $this->lastping = microtime(true);
        $this->executor->sendHeart();
    }
    public function onPongReceived() {
        if($this->status === self::STATUS_WAITING_PONG) {
            $this->status = self::STATUS_IDLE;
        }
        $this->lastpong = microtime(true);
    }
    public function getStatusCode() {
        return $this->status;
    }
    public function getRtt() {
        if($this->lastping < 0 || $this->lastpong < $this->lastping) {
            return -1;
        }
        return $this->lastpong - $this->lastping;
    }
    public function getLastPing() {
        return $this->lastping;
    }
    public function getLastPong() {
        return $this->lastpong;
    }
}