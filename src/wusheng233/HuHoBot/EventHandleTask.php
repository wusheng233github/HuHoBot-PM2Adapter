<?php
namespace wusheng233\HuHoBot;

use pocketmine\scheduler\Task;
use pocketmine\utils\UUID;
use wusheng233\HuHoBot\event\DataPacketReceiveEvent;
use wusheng233\HuHoBot\network\EventListener;
use wusheng233\HuHoBot\network\WebSocketClient;
use wusheng233\HuHoBot\network\WebSocketFrame;
use wusheng233\HuHoBot\utils\QQCommandSender;

class EventHandleTask extends Task implements EventListener {
    const STATUS_DISCONNECTED = 0;
    const STATUS_CONNECTED = 1;
    const STATUS_HANDSHAKED = 2;
    protected $owner;

    // 未发送/未收到为false，已发送/接收到为时间戳
    protected $lastping = false;
    protected $lastpong = false;
    protected $status = self::STATUS_DISCONNECTED;
    protected $client;
    public function __construct(Main $owner, string $host) {
        $this->owner = $owner;
        $this->client = new WebSocketClient($host, $this);
    }
    public function isConnected() {
        return $this->client->isConnected();
    }
    public function isHandshaked() {
        return $this->status === self::STATUS_HANDSHAKED;
    }
    public function sendMessage(string $type, array $body, $uuid = null) {
        $array = [
            "header" => [
                "type" => (string) $type,
                "id" => $uuid === null ? bin2hex(UUID::fromRandom()->toBinary()) : ($uuid instanceof UUID ? bin2hex($uuid->toBinary()) : $uuid)
            ],
            "body" => (array) $body
        ];
        $encoded = json_encode($array, JSON_UNESCAPED_UNICODE);
        if($encoded === false) {
            $this->owner->getLogger()->warning("无法编码JSON: " . json_last_error() . " " . json_last_error_msg() . " " . serialize($array));
        } else {
            $this->client->send(new WebSocketFrame(WebSocketFrame::OPCODE_TEXT, $encoded));
        }
    }
    public function onRun($currentTick) {
        try {
            $this->client->onRun();
        } catch(\Exception $e) {
            $this->owner->getLogger()->logException($e);
            $this->cancel();
        }
        if($this->isHandshaked()) { // 无效的客户端连接
            $currentTime = time();
            if($this->lastping !== false && $this->lastpong < $this->lastping - 15) { // timeout
                $this->owner->getLogger()->warning("连接断开？pong已超时");
                $this->owner->getLogger()->debug("lastping: " . var_export($this->lastping, true));
                $this->owner->getLogger()->debug("lastpong: " . var_export($this->lastpong, true));
                $this->cancel();
                return;
            } else if($currentTime - $this->owner->getConfig()->getNested("network.heart-period") > $this->lastping) {
                $this->lastping = $currentTime;
                if($this->lastpong === false) {
                    $this->lastpong = $currentTime;
                }
                $this->sendMessage("heart", []); // 未捕获异常
            }
        }
    }
    protected function handlePacket($pk) {
        $pktype = $pk["header"]["type"];
        switch($pktype) {
            case "bindRequest":
                $this->owner->newBindRequest($pk["body"]["bindCode"], $pk["header"]["id"]);
                break;
            case "sendConfig":
                $this->owner->getConfig()->setNested("id.hashkey", $pk["body"]["hashKey"]);
                $this->owner->getConfig()->save();
                $this->owner->getLogger()->notice("下发了新的绑定密钥");
                break;
            case "heart":
                $this->lastpong = time(); // TODO: 查看延迟
                break;
            case "shaked":
                switch($pk["body"]["code"]) {
                    case 1:
                    case 2:
                        $this->owner->getLogger()->info("握手成功");
                        $this->status = self::STATUS_HANDSHAKED;
                        break;
                    case 3:
                        $this->owner->getLogger()->warning("绑定密钥信息不匹配");
                        break;
                    case 4:
                        $this->owner->getLogger()->warning("客户端版本不匹配");
                        break;
                    case 5:
                        $this->owner->getLogger()->warning("内部错误");
                        break;
                    case 6:
                        $this->owner->getLogger()->notice("等待绑定");
                        $this->status = self::STATUS_HANDSHAKED;
                        break;
                    case 7:
                        $this->owner->getLogger()->warning("IP被封");
                        break;
                    case 8:
                        $this->owner->getLogger()->warning("服务器被封");
                        break;
                    default:
                        $this->owner->getLogger()->warning("Code: " . $pk["body"]["code"] . " Message: " . $pk["body"]["msg"]);
                        break;
                }
                if($pk["body"]["msg"] != "") {
                    $this->owner->getLogger()->notice($pk["body"]["msg"]);
                }
                if(!$this->isHandshaked()) {
                    $this->cancel();
                    return;
                }
                break;
            case "chat":
                $lines = explode("\n", $pk["body"]["msg"]);
                $res = [];
                foreach($lines as $msg) {
                    // Warning: preg_replace(): Compilation failed: disallowed Unicode code point (>= 0xd800 && <= 0xdfff)
                    if($this->owner->getConfig()->getNested("group-chat.filter.enable")) {
                        $msg = preg_replace($this->owner->getConfig()->getNested("group-chat.filter.pattern"), $this->owner->getConfig()->getNested("group-chat.replacement"), $msg);
                    }
                    if($msg === null) {
                        $msg = "（错误）";
                    }
                    if($this->owner->getConfig()->getNested("group-chat.filter.invalid-chars")) {
                        $msg = self::filterInvalidChars($msg);
                    }
                    if($msg === "") {
                        $msg = "（空白消息）";
                    }
                    $msg = sprintf($this->owner->getConfig()->getNested("group-chat.format"), $pk["body"]["nick"], $msg);
                    if($msg === false) {
                        $msg = "（聊天格式配置有误）";
                    }
                    $this->owner->getServer()->broadcastMessage($msg);
                    $res[] = $msg;
                }
                $this->sendMessage("chat", ["msg" => implode("\n", $res), "serverId" => $this->owner->getHandshakeConfig()->getServerId()], $pk["header"]["id"]);
                $this->owner->lastChat = time();
                break;
            case "queryOnline":
                $server = $this->owner->getServer();
                $onlineplayers = $server->getOnlinePlayers();
                $str = count($onlineplayers) . "/" . $server->getMaxPlayers() . " 在线";
                $num = 1;
                $showplayernametag = $this->owner->getConfig()->getNested("show-player-nametag");
                foreach($onlineplayers as $player) {
                    $str .= "\n{$num}. {$player->getName()}" . ($showplayernametag ? ": " . $player->getNameTag() : "");
                    $num++; // ?
                }
                $this->sendMessage("queryOnline", ["list" => ["msg" => $str, "url" => $this->owner->getConfig()->getNested("motd.address"), "imgUrl" => $this->owner->getConfig()->getNested("motd.image"), "post_img" => $this->owner->getConfig()->getNested("motd.post-image"), "serverType" => $this->owner->getConfig()->getNested("motd.type")]], $pk["header"]["id"]);
                break;
            case "cmd":
                $sender = new QQCommandSender();
                $sender->setName($this->owner->getConfig()->getNested("group-chat.command-sender"));
                $this->owner->getServer()->dispatchCommand($sender, $pk["body"]["cmd"]);
                $this->owner->respone(implode("\n", $sender->getAllMessages()), $pk["header"]["id"]);
                break;
            case "run":
            case "runAdmin":
                $this->sendMessage("success", ["msg" => "未实现"], $pk["header"]["id"]);
                break;
            case "add":
                $this->owner->getServer()->addWhitelist($pk["body"]["xboxid"]);
                $this->sendMessage("success", ["msg" => "已尝试添加白名单: " . $pk["body"]["xboxid"]], $pk["header"]["id"]);
                break;
            case "delete":
                $this->owner->getServer()->removeWhitelist($pk["body"]["xboxid"]);
                $this->sendMessage("success", ["msg" => "已尝试移除白名单: " . $pk["body"]["xboxid"]], $pk["header"]["id"]);
                break;
            case "queryList":
                $keywords = isset($pk["body"]["key"]) ? explode(" ", $pk["body"]["key"]) : [];

                $whitelist = $this->owner->getServer()->getWhitelisted()->getAll(true);
                if(empty($keywords)) {
                    $filtered = $whitelist;
                } else {
                    $filtered = array_filter($whitelist, function($v) use($keywords) {
                        foreach($keywords as $keyword) {
                            if(stripos($v, $keyword) === false) {
                                return false;
                            }
                        }
                        return true;
                    });
                }

                $pageIndex = isset($pk["body"]["page"]) ? max(0, $pk["body"]["page"]) : 0;
                $length = $this->owner->getConfig()->getNested("whitelist.items-per-page");
                $paged = array_slice($filtered, $pageIndex * $length, $length, true);

                $message = "找不到";
                if(!empty($paged)) {
                   $totalPages = ceil(count($filtered) / $length);
                   $pageNumber = $pageIndex + 1;
                   $message = "第 $pageNumber/$totalPages 页\n" . implode("\n", array_map(function($key, $value) {
                       return ($key + 1) . ". $value";
                   }, array_keys($paged), $paged));
                }

                $this->sendMessage("queryWl", ["list" => $message], $pk["header"]["id"]);
                break;
            case "shutdown":
                if($pk["body"]["msg"]) {
                    $this->owner->getLogger()->notice($pk["body"]["msg"]);
                }
                $this->cancel();
                break;
            default:
                $this->owner->getLogger()->debug("未实现: " . json_encode($pk, JSON_UNESCAPED_UNICODE));
                break;
        }
    }
    public function onCancel() {
        $this->status = self::STATUS_DISCONNECTED;
        $this->client->close();
    }
    private function cancel() {
        $this->owner->getServer()->getScheduler()->cancelTask($this->getTaskId());
        $this->owner->shutdown();
    }
    public function getLastPing() {
        return $this->lastping;
    }
    public function getLastPong() {
        return $this->lastpong;
    }
    public static function mb_str_split(string $string, int $length = 1, $encoding = null) {
        $array = [];
        $offset = 0;
        $processed = 0;
        $strlen = strlen($string);
        while($processed < $strlen) {
            if($encoding !== null) {
                $char = mb_substr($string, $offset, $length, $encoding);
            } else {
                $char = mb_substr($string, $offset, $length);
            }
            $array[] = $char;
            $processed += strlen($char);
            $offset += $length;
        }
        return $array;
    }
    public static function mb_ord(string $string, string $encoding = null) {
        if($encoding !== null) { // Warning: mb_convert_encoding(): Illegal character encoding specified
            $utf32 = mb_convert_encoding($string, "UTF-32BE", $encoding);
        } else {
            $utf32 = mb_convert_encoding($string, "UTF-32BE");
        }
        if($utf32 === false) {
            return false;
        }
        return unpack("N", $utf32)[1];
    }
    public static function filterInvalidChars(string $string) {
        $result = "";
        foreach(self::mb_str_split($string) as $char) {
            $code = self::mb_ord($char);
            if($code < 0xD800 || $code > 0xDFFF) {
                if(!(($code >= 0xE000 && $code <= 0xF8FF) || ($code >= 0x10000 && $code <= 0x10FFFF))) {
                    $result .= $char;
                }
            }
        }
        return $result;
    }
    public function onConnected() {
        $this->owner->getLogger()->info("已建立连接");
    }
    public function onHandShaked() {
        $this->sendMessage("shakeHand", [
            "serverId" => $this->owner->getHandshakeConfig()->getServerId(),
            "hashKey" => $this->owner->getHandshakeConfig()->getHashKey(), // bin2hex(random_bytes(32))
            "name" => $this->owner->getHandshakeConfig()->getServerName(),
            "version" => $this->owner->getHandshakeConfig()->getPlatformVersion(), // 设置dev版本将提示 "您正在使用的是开发版，如有问题请在对应适配器的GitHub仓库中提出Issues"
            "platform" => $this->owner->getHandshakeConfig()->getPlatformName()
        ]);
        $this->status = self::STATUS_CONNECTED;
        $this->owner->getLogger()->info("WebSocket握手成功");
    }
    public function onClosed() {
        $this->owner->getLogger()->info("断开连接");
        $this->cancel();
    }
    public function onMessage(string $message) {
        $message = json_decode($message, true);
        if($message === null) {
            $this->owner->getLogger()->warning("JSON解码错误: " . json_last_error() . " " . json_last_error_msg() . " " . $message);
            return;
        }
        $event = new DataPacketReceiveEvent($message);
        $this->owner->getServer()->getPluginManager()->callEvent($event);
        if($event->isCancelled()) {
            return;
        }
        $this->handlePacket($message);
        $this->lastpong = time();
    }
    public function onBinaryMessage(string $message) {
        $this->owner->getLogger()->notice("BinaryMessage: " . bin2hex($message));
    }
    public function 接收到(string $string) {
        $this->owner->getLogger()->debug("[接收到] $string");
    }
    public function 将发送(string $string) {
        $this->owner->getLogger()->debug("[将发送] $string");
    }
}