<?php
namespace wusheng233\HuHoBot\network;

interface HeartbeatExecutor {
    public function sendHeart();
    public function onTimeout();
}