<?php
namespace wusheng233\HuHoBot;

use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\command\PluginCommand;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\Permission;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;

/**
 * @license https://opensource.org/license/MIT MIT
 */
class Main extends PluginBase implements Listener {
    const DEFAULT_CONFIG = [ // 提交记录
        "huhobotwsserver" => "", // TODO: 格式验证、清理
        "hashkey" => "",
        "servername" => "",
        "enablefilter" => false,
        "chatforwarding" => true,
        "whitelistitemsperpage" => 10,
        "pingperiod" => 10,
        "chatforwardingtimelimit" => 5 * 60,
        "imgurl" => "https://picsum.photos/500/100", // FIXME: picsum.photos经常出现后端错误
        "postimg" => true,
        "servertype" => "bedrock", // TODO: 不是Bedrock版，信息图片不正常？
        "serverurl" => "1.14.51.4:19198",
        "showplayernametag" => true,
        "readperiod" => 10,
        "commandsendername" => "QQ Console",
        "platformname" => "",
        "platformversion" => "dev",
        "filter" => "/(?!)/",
        "replacement" => "",
        "usedefaultchatformat" => false,
        "qqmessageformat" => "[群内消息] <%s> %s",
        "wordlimit" => 7000, // TODO: 没有解决问题
        "filterinvalidchars" => true
    ];
    /** @var Config */
    private $config;
    /** @var \pocketmine\scheduler\TaskHandler */
    private $taskhandler;
    /** @var EventHandleTask */
    private $eventHandleTask;
    private $bindrequests = [];
    public $lastqqchat = 0; // int
    protected $handshakeConfig;
    public function onEnable() {
        //self::$pluginversion = $this->getDescription()->getVersion();
        $datafolder = rtrim($this->getDataFolder(), "/");
        if(!file_exists($datafolder)) {
            mkdir($datafolder);
        }
        $error = false; // 重复
        if(!is_dir($datafolder)) {
            $this->getLogger()->error("数据文件夹错误");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return false;
        }
        $this->config = new Config($this->getDataFolder() . "/config.json", Config::JSON, self::DEFAULT_CONFIG);
        if(!$this->config->exists("serverid")) {
            $this->config->set("serverid", bin2hex(random_bytes(16))); // 不能Utils::getMachineUniqueId
            $this->config->save();
        }
        if(!is_readable($datafolder . "/config.json")) {
            $this->getLogger()->error("配置文件没有读取权限");
            $error = true;
        }
        if(!is_writable($datafolder . "/config.json")) {
            $this->getLogger()->error("配置文件没有写入权限");
            $error = true;
        }
        if(!is_file($datafolder . "/config.json")) {
            $this->getLogger()->error("配置文件不是文件");
            $error = true;
        }
        if($this->config->get("huhobotwsserver", "") === "") {
            $this->getLogger()->error("未配置后台地址，请在config.json中配置huhobotwsserver，带URL Scheme");
            $error = true;
        }
        if($error) {
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return false;
        }
        if(!extension_loaded("openssl")) {
            $this->getLogger()->warning("需要openssl扩展才能使用WebSocket Secure连接");
        }
        $root = new Permission("huhobot", "允许控制HuHoBot插件", Permission::DEFAULT_OP);
        DefaultPermissions::registerPermission($root); // TODO: 不要重复注册权限
        DefaultPermissions::registerPermission(new Permission("huhobot.bind", "允许通过命令让服务器绑定QQ群", Permission::DEFAULT_OP), $root);
        DefaultPermissions::registerPermission(new Permission("huhobot.disconnect", "允许通过命令让插件断开连接", Permission::DEFAULT_OP), $root);
        DefaultPermissions::registerPermission(new Permission("huhobot.connect.host", "允许通过命令让插件向指定主机连接", Permission::DEFAULT_OP), DefaultPermissions::registerPermission(new Permission("huhobot.connect", "允许通过命令让插件启动连接", Permission::DEFAULT_OP), $root));
        $command = new PluginCommand("huhobot", $this);
        $command->setDescription("HuHoBot控制命令"); // TODO: i18n
        $command->setUsage("详情请在 /huhobot help 查看");
        $command->setPermission("huhobot");
        $command->setExecutor($this);
        $this->getServer()->getCommandMap()->register($this->getName(), $command);
        $this->handshakeConfig = new HandshakeConfig($this->config->get("serverid", str_repeat("0", 32)), $this->config->get("hashkey"), $this->config->get("servername"), $this->config->get("platformname"), $this->config->get("platformversion"));
        $this->connect($this->config->get("huhobotwsserver"));
        if($this->config->get("hashkey", "") === "") {
            $this->getLogger()->notice("未检测到绑定密钥，要想绑定QQ群，请让HuHoBot机器人执行 /绑定 " . $this->config->get("serverid", "服务器ID"));
        }
        if($this->config->get("chatforwarding")) {
            $this->getServer()->getPluginManager()->registerEvents($this, $this); // TODO: 这个不对
        }
    }
    public function connect(string $host) {
        if($this->isConnected()) {
            return false;
        }
        $this->getLogger()->debug("正常启动");
        $this->taskhandler = $this->getServer()->getScheduler()->scheduleRepeatingTask($this->eventHandleTask = new EventHandleTask($this, $host), $this->config->get("readperiod"));
        $this->eventHandleTask->setHandler($this->taskhandler);
        return true;
    }
    public function isConnected() {
        return $this->eventHandleTask !== null && $this->taskhandler !== null && $this->eventHandleTask->isConnected();
    }
    /**
     * @priority MONITOR
     */
    public function onPlayerChat(PlayerChatEvent $event) { // TODO: 要看到控制台发话
        if($event->isCancelled()) {
            return;
        }
        if(time() - $this->config->get("chatforwardingtimelimit") > $this->lastqqchat) {
            return;
        }
        // TODO: 控制不要转发
        $this->eventHandleTask->sendMessage("chat", ["msg" => $this->getServer()->getLanguage()->translateString($event->getFormat(), [$event->getPlayer()->getName(), $event->getMessage()]), "serverId" => $this->getHandshakeConfig()->getServerId()]);
    }
    public function respone(string $msg, $uuid, $success = true) { // TODO: 其它地方有字数限制吗
        $toolong = "（消息过长）";
        $wordlimit = $this->config->get("wordlimit");
        if(mb_strlen($msg) > $wordlimit) {
            $msg = mb_substr($msg, 0, $wordlimit - mb_strlen($toolong)) . $toolong;
        }
        $this->eventHandleTask->sendMessage($success ? "success" : "error", ["msg" => $msg], $uuid);
    }
    public function onCommand(CommandSender $sender, Command $command, $label, array $args) {
        if($command->getName() !== "huhobot") {
            return true;
        }
        if(!$command->testPermission($sender)) {
            return true;
        }
        switch(isset($args[0]) ? $args[0] : "") { // TODO: reload
            case "help":
                $sender->sendMessage(implode("\n", [
                    "命令                                    说明",
                    "/huhobot bind <验证码>                  绑定QQ群",
                    "/huhobot <disconnect|q>                 断开连接，停止互通",
                    "/huhobot <reconnect|connect|c> [地址]   连接服务器",
                    "/huhobot reload                         重新启动整个插件"
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
                if(isset($this->bindrequests[$args[1]])) {
                    $this->eventHandleTask->sendMessage("bindConfirm", [], $this->bindrequests[$args[1]]);
                    $sender->sendMessage("已确认绑定服务器，等待下发绑定密钥");
                    unset($this->bindrequests[$args[1]]);
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
                $host = $this->config->get("huhobotwsserver");
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
            default:
                return false;
        }
        return true;
    }
    public function newBindRequest(string $code, string $uuid) {
        $this->getLogger()->notice("有新的绑定请求！使用 /huhobot bind $code 确认绑定");
        $this->bindrequests[$code] = $uuid;
    }
    public function getConfig() {
        return $this->config;
    }
    public function getEventHandleTask() {
        return $this->eventHandleTask;
    }
    public function getTaskHandler() {
        return $this->taskhandler;
    }
    public function shutdown() {
        if(!$this->isConnected()) {
            return false;
        }
        $this->getServer()->getScheduler()->cancelTask($this->eventHandleTask->getTaskId());
        $this->eventHandleTask = null;
        $this->taskhandler = null;
        return true;
    }
    public function onDisable() {
        $this->shutdown();
    }
    public function getHandshakeConfig() {
        return $this->handshakeConfig;
    }
}