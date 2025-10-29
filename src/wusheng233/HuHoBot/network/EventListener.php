<?php
namespace wusheng233\HuHoBot\network;
interface EventListener {
    public function onConnected();
    public function onHandShaked();
    public function onDisconnected();
    public function onMessage(string $message);
    public function onBinaryMessage(string $message);
    public function 接收到(string $string);
    public function 将发送(string $string);
}