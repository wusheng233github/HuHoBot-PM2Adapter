<?php
namespace wusheng233\HuHoBot\event;

use pocketmine\event\Cancellable;
use pocketmine\event\Event;

class DataPacketReceiveEvent extends Event implements Cancellable {
    public static $handlerList = null;
    protected $data;
    public function __construct(array $data) {
        $this->data = $data;
    }
    public function getPacket() {
        return $this->data;
    }
}