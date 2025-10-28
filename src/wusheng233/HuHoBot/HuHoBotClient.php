<?php
namespace wusheng233\HuHoBot;

use pocketmine\utils\UUID;
use ThreadedLogger;
use WebSocket\Client;

class HuHoBotClient extends Client {
    protected $logger;
    /**
     * @var callable
     */
    protected $onConnected;
    public function __construct(string $uri, array $options, ThreadedLogger $logger) {
        parent::__construct($uri, $options);
        $this->logger = $logger;
    }
    public function getLogger() {
        return $this->logger;
    }
    public function setConnectedListener(callable $onConnected) {
        $this->onConnected = $onConnected;
    }
    public static function constructDataPacket(string $type, array $body, $uuid = null) {
        return [
            "header" => [
                "type" => (string) $type,
                "id" => $uuid === null ? bin2hex(UUID::fromRandom()->toBinary()) : ($uuid instanceof UUID ? bin2hex($uuid->toBinary()) : $uuid)
            ],
            "body" => (array) $body
        ];
    }
    public function connect() {
        parent::connect();
        $this->logger->info("已建立连接");
        if($this->onConnected !== null) {
            call_user_func($this->onConnected);
        }
    }
    public function send($payload, $opcode = "text", $masked = true) {
        $this->logger->debug("[将发送] " . $payload);
        parent::send($payload, $opcode, $masked);
    }
    public function receive() {
        $data = parent::receive();
        $this->logger->debug("[接收到] " . $data);
        return $data;
    }
    public function getSocket() {
        return $this->socket;
    }
}