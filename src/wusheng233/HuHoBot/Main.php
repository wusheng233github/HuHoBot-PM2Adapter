<?php
namespace wusheng233\HuHoBot;

use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;

/**
 * @license https://opensource.org/license/MIT MIT
 * @copyright 2025 wusheng233
 */
class Main extends PluginBase implements Listener {
    /** @var \pocketmine\scheduler\TaskHandler */
    private $taskHandler;
    /** @var EventHandleTask */
    private $eventHandleTask;
    private $bindRequests = [];
    public $lastChat = 0; // int
    protected $handshakeConfig;
    public function onEnable() {
        //self::$pluginversion = $this->getDescription()->getVersion();
        $this->saveDefaultConfig();
        $this->jsonConfigUpgrade();
        if(!extension_loaded("openssl")) {
            $this->getLogger()->warning("需要openssl扩展才能使用WebSocket Secure连接");
        }
        $this->handshakeConfig = new HandshakeConfig($this->getConfig()->getNested("id.serverid", str_repeat("0", 16 * 2)), $this->getConfig()->getNested("id.hashkey"), $this->getConfig()->getNested("id.name"), $this->getConfig()->getNested("platform.name"), $this->getConfig()->getNested("platform.version"));
        $this->connect($this->getConfig()->getNested("network.botserver"));
        if($this->getConfig()->getNested("id.hashkey", "") === "") {
            $this->getLogger()->notice("未检测到绑定密钥，要想绑定QQ群，请让HuHoBot机器人执行 /绑定 " . $this->getConfig()->getNested("id.serverid", "服务器ID"));
        }
        if($this->getConfig()->getNested("game-chat.post")) {
            $this->getServer()->getPluginManager()->registerEvents($this, $this); // TODO: 这个不对
        }
    }
    public function connect(string $host) {
        if($this->isConnected()) {
            return false;
        }
        $this->getLogger()->debug("正常启动");
        $this->taskHandler = $this->getServer()->getScheduler()->scheduleRepeatingTask($this->eventHandleTask = new EventHandleTask($this, $host), $this->getConfig()->getNested("network.read-period"));
        $this->eventHandleTask->setHandler($this->taskHandler);
        return true;
    }
    public function isConnected() {
        return $this->eventHandleTask !== null && $this->taskHandler !== null && $this->eventHandleTask->isConnected();
    }
    /**
     * @priority MONITOR
     */
    public function onPlayerChat(PlayerChatEvent $event) { // TODO: 要看到控制台发话
        if($event->isCancelled()) {
            return;
        }
        if(time() - $this->getConfig()->getNested("game-chat.time-limit") > $this->lastChat) {
            return;
        }
        // TODO: 控制不要转发
        $this->eventHandleTask->sendMessage("chat", ["msg" => $this->getServer()->getLanguage()->translateString($event->getFormat(), [$event->getPlayer()->getName(), $event->getMessage()]), "serverId" => $this->getHandshakeConfig()->getServerId()]);
    }
    public function response(string $msg, $uuid, $formatting = false, $success = true) {
        $toolong = "（消息过长）";
        $wordlimit = $this->getConfig()->getNested("group-chat.word-limit");
        if(mb_strlen($msg) > $wordlimit) {
            $msg = mb_substr($msg, 0, $wordlimit - mb_strlen($toolong)) . $toolong;
        }
        $this->eventHandleTask->sendMessage($success ? "success" : "error", ["msg" => $msg, "callbackConvert" => $formatting ? $this->getConfig()->getNested("game-chat.color-image-reply") : 0], $uuid);
    }
    public function onCommand(CommandSender $sender, Command $command, $label, array $args) {
        if($command->getName() !== "huhobot") {
            return true;
        }
        if(!$command->testPermission($sender)) {
            return true;
        }
        if(!isset($args[0])) {
            $args[0] = "help"; // 默认进入help
        }
        switch($args[0]) { // TODO: reload
            case "help":
                $sender->sendMessage(implode("\n", [
                    "命令                                    说明",
                    "/huhobot bind <验证码>                  绑定QQ群",
                    "/huhobot <disconnect|q>                 断开连接，停止互通",
                    "/huhobot <reconnect|connect|c> [地址]   连接服务器",
                    "/huhobot reload                         重新启动整个插件",
                    "/huhobot rtt                            查询网络连接往返时间"
                ]));
                break;
            case "bind":
                if(!$sender->hasPermission("huhobot.bind")) {
                    $sender->sendMessage("你缺少huhobot.bind权限，不能使用该功能");
                    break;
                }
                if(!isset($args[1])) {
                    $sender->sendMessage("未设置验证码: /huhobot bind <验证码>");
                    break;
                }
                if(isset($this->bindRequests[$args[1]])) {
                    $this->eventHandleTask->sendMessage("bindConfirm", [], $this->bindRequests[$args[1]]);
                    $sender->sendMessage("已确认绑定服务器，等待下发绑定密钥");
                    unset($this->bindRequests[$args[1]]);
                }
                break;
            case "disconnect":
            case "q":
                if(!$sender->hasPermission("huhobot.disconnect")) {
                    $sender->sendMessage("你缺少huhobot.disconnect权限，不能使用该功能");
                    break;
                }
                if($this->shutdown()) {
                    $sender->sendMessage("尝试退出" . $this->getName());
                } else {
                    $sender->sendMessage("已断开连接");
                }
                break;
            case "reconnect":
            case "connect":
            case "c":
                if(!$sender->hasPermission("huhobot.connect")) {
                    $sender->sendMessage("你缺少huhobot.connect权限，不能使用该功能");
                    break;
                }
                $host = $this->getConfig()->getNested("network.botserver");
                if(isset($args[1])) {
                    if(!$sender->hasPermission("huhobot.connect.host")) {
                        $sender->sendMessage("你缺少huhobot.connect.host权限，不能指定目标主机");
                        break;
                    }
                    $host = $args[1];
                }
                if($this->connect($host)) {
                    $sender->sendMessage("尝试连接$host");
                } else {
                    $sender->sendMessage("已连接服务器");
                }
                break;
            case "reload":
                $this->getServer()->getPluginManager()->disablePlugin($this);
                $this->getServer()->getPluginManager()->enablePlugin($this);
                break;
            case "rtt":
                $rtt = $this->eventHandleTask->getHeartbeatService()->getRtt();
                if($rtt < 0) {
                    $sender->sendMessage("未启动心跳或正在等待回应");
                } else {
                    $sender->sendMessage("RTT: " . ceil($rtt * 1000) . "ms");
                }
                break;
            default:
                return false;
        }
        return true;
    }
    public function newBindRequest(string $code, string $uuid) {
        $this->getLogger()->notice("有新的绑定请求！使用 /huhobot bind $code 确认绑定");
        $this->bindRequests[$code] = $uuid;
    }
    /*public function getConfig() {
        return $this->config;
    }*/
    public function getEventHandleTask() {
        return $this->eventHandleTask;
    }
    public function getTaskHandler() {
        return $this->taskHandler;
    }
    public function shutdown() {
        if(!$this->isConnected()) {
            return false;
        }
        $this->getServer()->getScheduler()->cancelTask($this->eventHandleTask->getTaskId());
        $this->eventHandleTask = null;
        $this->taskHandler = null;
        return true;
    }
    public function onDisable() {
        $this->shutdown(); // TODO: PluginTask
    }
    public function getHandshakeConfig() {
        return $this->handshakeConfig;
    }
    public function jsonConfigUpgrade() {
        $configfilepath = $this->getDataFolder() . "/config.json";
        if(!file_exists($configfilepath)) {
            return;
        }
        $config = new Config($configfilepath, Config::JSON);
        $map = [
            "huhobotwsserver" => "network.botserver",
            "hashkey" => "id.hashkey",
            "serverid" => "id.serverid",
            "servername" => "id.name",
            "enablefilter" => "group-chat.filter.enable",
            "chatforwarding" => "game-chat.post",
            "whitelistitemsperpage" => "whitelist.items-per-page",
            "pingperiod" => "network.heart-period",
            "chatforwardingtimelimit" => "game-chat.time-limit",
            "imgurl" => "motd.image",
            "postimg" => "motd.post-image",
            "servertype" => "motd.type",
            "serverurl" => "motd.address",
            "showplayernametag" => "show-player-nametag",
            "readperiod" => "network.read-period",
            "commandsendername" => "group-chat.command-sender",
            "platformname" => "platform.name",
            "platformversion" => "platform.version",
            "replacement" => "group-chat.replacement",
            "qqmessageformat" => "group-chat.format",
            "wordlimit" => "group-chat.word-limit",
            "filterinvalidchars" => "group-chat.filter.invalid-chars",
            "filter" => "group-chat.filter.pattern"
        ];
        $usedefaultchatformat = false;
        foreach($config->getAll() as $key => $value) {
            if(isset($map[$key])) {
                if($usedefaultchatformat && $key == "qqmessageformat") {
                    $value = "[群内消息] <%s> %s";
                }
                $this->getConfig()->setNested($map[$key], $value);
            } else if($key == "usedefaultchatformat" && $value) {
                $usedefaultchatformat = true;
            }
        }
        $this->getConfig()->save();
        rename($configfilepath, "$configfilepath.bak");
    }

}
