<?php
namespace wusheng233\HuHoBot;

use pocketmine\Thread;
use Threaded;
use ThreadedLogger;
use WebSocket\ConnectionException;

class NetworkThread extends Thread {
    protected $queuei;
    protected $queueo;
    private $botserver;
    private $logger;
    public function __construct(string $botserver, ThreadedLogger $logger) {
        $this->queueo = new Threaded();
        $this->queuei = new Threaded();
        $this->botserver = $botserver;
        $this->logger = $logger;
    }
    public function run() {
        $wsclient = new HuHoBotClient($this->botserver, [], $this->logger);
        $wsclient->setTimeout(1);
        $wsclient->setConnectedListener(function() {
            $this->pushToMainThread("NetworkThread.connected", []);
        });
        while(true) {
            try {
                $decoded = $this->readToNetworkThread();
                if($decoded !== null) {
                    if($decoded["header"]["type"] === "NetworkThread.shutdown") {
                        break;
                    }

                    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                    if($encoded === false) {
                        $this->logger->warning("无法编码JSON: " . json_last_error() . " " . json_last_error_msg() . " " . serialize($decoded));
                    } else {
                        $wsclient->send($encoded);
                    }
                }

                if($wsclient->getLastOpcode() == "close") {
                    // TODO: 限制重连次数
                }

                $data = $wsclient->receive();
                if($wsclient->getLastOpcode() != "text") {
                    continue;
                }

                $data = json_decode($data, true);
                if($data === null) {
                    $this->logger->warning("JSON解码错误: " . json_last_error() . " " . json_last_error_msg() . " " . $data);
                    continue;
                }

                $this->pushToMainThread($data["header"]["type"], $data["body"], $data["header"]["id"]);

                if($data["header"]["type"] === "shutdown") {
                    $this->logger->warning("远程服务器要求关闭连接，原因如下:");
                    $this->logger->warning($data["body"]["msg"]);
                    break;
                }
            } catch(ConnectionException $e) {
                $socket = $wsclient->getSocket();
                if($socket !== false && stream_get_meta_data($socket)["timed_out"] == true) {
                    continue;
                }
                $this->logger->logException($e); // 这里不做心跳
                break;
            }
        }
        try {
            $wsclient->close();
        } catch(ConnectionException $e) {
            $this->logger->logException($e);
        }
        $this->logger->info("已退出循环");
        $this->pushToMainThread("shutdown", ["msg" => ""]);
    }
    public function sendMessage(string $type, array $body, $uuid = null) {
        $this->queuei[] = serialize(HuHoBotClient::constructDataPacket($type, $body, $uuid));
    }
    protected function pushToMainThread(string $type, array $body, $uuid = null) {
        $this->queueo[] = serialize(HuHoBotClient::constructDataPacket($type, $body, $uuid));
    }
    public function readToMainThread() {
        $result = [];
        foreach($this->queueo as $key => $data) {
            unset($this->queueo[$key]); // shift?
            $result[] = unserialize($data); // 没问题
        }
        return $result;
    }
    protected function readToNetworkThread() {
        $input = $this->queuei->shift();
        if($input === null) {
            return null;
        }
        $decoded = unserialize($input);
        if($decoded === null) {
            $this->logger->warning("无法反序列化" . $input);
            return null;
        }
        return $decoded;
    }
}