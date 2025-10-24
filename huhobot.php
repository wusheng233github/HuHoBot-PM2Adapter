<?php

/*
The following code includes the Websocket PHP library, which is licensed under the ISC License.

License text: https://raw.githubusercontent.com/Textalk/websocket-php/master/COPYING

Copyright (C) 2014-2020 Textalk/Abicart and contributors.

ISC License

Permission to use, copy, modify, and/or distribute this software for any purpose with or without
fee is hereby granted, provided that the above copyright notice and this permission notice appear
in all copies.

THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES WITH REGARD TO THIS
SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE
AUTHOR BE LIABLE FOR ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN ACTION OF CONTRACT,
NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF OR IN CONNECTION WITH THE USE OR PERFORMANCE OF
THIS SOFTWARE.

*/

/*
MIT License

Copyright (c) 2025 wusheng233

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

*/

namespace wusheng233\HuHoBot;

use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\command\PluginCommand;
use pocketmine\event\Cancellable;
use pocketmine\event\Event;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\TextContainer;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\Permission;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\Thread;
use pocketmine\utils\Config;
use pocketmine\utils\UUID;
use Threaded;
use ThreadedLogger;
use WebSocket\Client;
use WebSocket\ConnectionException;

/**
 * @name HuHoBot
 * @description HuHoBot PM2适配器
 * @author wusheng233
 * @version 0.2.2
 * @main wusheng233\HuHoBot\Main
 * @api 2.0.0
 * @geniapi 1.7.3
 * @ScaxeAPI 1.7.3
 * @license https://opensource.org/license/MIT MIT
 * @website https://github.com/wusheng233github/HuHoBot-PM2Adapter
 */
class Main extends PluginBase implements Listener {
    const DEFAULT_CONFIG = [ // 提交记录
        'huhobotwsserver' => '', // TODO: 格式验证、清理
        'hashkey' => '',
        'servername' => '',
        'enablefilter' => false,
        'chatforwarding' => true,
        'whitelistitemsperpage' => 10,
        'pingperiod' => 10,
        'chatforwardingtimelimit' => 5 * 60,
        'imgurl' => 'https://picsum.photos/500/100', // TODO: picsum.photos经常出现后端错误
        'postimg' => true,
        'servertype' => 'bedrock', // TODO: 不是Bedrock版，信息图片不正常？
        'serverurl' => '1.14.51.4:19198',
        'showplayernametag' => true,
        'readperiod' => 10,
        'commandsendername' => 'QQ Console',
        'platformname' => '',
        'platformversion' => 'dev',
        'filter' => '/(?!)/',
        'replacement' => '',
        'usedefaultchatformat' => false,
        'qqmessageformat' => '[群内消息] <%s> %s',
        'wordlimit' => 7000, // TODO: 没有解决问题
        'filterinvalidchars' => true
    ];
    /** @var Config */
    private $config;
    /** @var NetworkThread */
    private $networkthread;
    /** @var \pocketmine\scheduler\TaskHandler */
    private $queuereadtaskhandler;
    /** @var QueueReadTask */
    private $queuereadtask;
    private $bindrequests = [];
    public $lastqqchat = 0; // int
    public function onEnable() {
        //self::$pluginversion = $this->getDescription()->getVersion();
        $datafolder = rtrim($this->getDataFolder(), '/');
        if(!file_exists($datafolder)) {
            mkdir($datafolder);
        }
        $error = false; // 重复
        if(!is_dir($datafolder)) {
            $this->getLogger()->error('数据文件夹错误');
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return false;
        }
        $this->config = new Config($this->getDataFolder() . '/config.json', Config::JSON, self::DEFAULT_CONFIG);
        if(!$this->config->exists('serverid')) {
            $this->config->set('serverid', bin2hex(random_bytes(16)));
            $this->config->save();
        }
        if(!is_readable($datafolder . '/config.json')) {
            $this->getLogger()->error('配置文件没有读取权限');
            $error = true;
        }
        if(!is_writable($datafolder . '/config.json')) {
            $this->getLogger()->error('配置文件没有写入权限');
            $error = true;
        }
        if(!is_file($datafolder . '/config.json')) {
            $this->getLogger()->error('配置文件不是文件');
            $error = true;
        }
        if($this->config->get('huhobotwsserver', '') === '') {
            $this->getLogger()->error('未配置后台地址，请配置config.json中huhobotwsserver，带wss://');
            $error = true;
        }
        if($error) {
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return false;
        }
        if(!extension_loaded('openssl')) {
            $this->getLogger()->warning('需要openssl扩展才能使用WebSocket Secure连接');
        }
        $root = new Permission('huhobot', '允许控制HuHoBot插件', Permission::DEFAULT_OP);
        DefaultPermissions::registerPermission($root); // TODO: 不要重复注册权限
        DefaultPermissions::registerPermission(new Permission('huhobot.bind', '允许通过命令让服务器绑定QQ群', Permission::DEFAULT_OP), $root);
        DefaultPermissions::registerPermission(new Permission('huhobot.disconnect', '允许通过命令让插件断开连接', Permission::DEFAULT_OP), $root);
        DefaultPermissions::registerPermission(new Permission('huhobot.connect.host', '允许通过命令让插件向指定主机连接', Permission::DEFAULT_OP), DefaultPermissions::registerPermission(new Permission('huhobot.connect', '允许通过命令让插件启动连接', Permission::DEFAULT_OP), $root));
        $command = new PluginCommand('huhobot', $this);
        $command->setDescription('HuHoBot控制命令'); // TODO: i18n
        $command->setUsage('详情请在 /huhobot help 查看');
        $command->setPermission('huhobot');
        $command->setExecutor($this);
        $this->getServer()->getCommandMap()->register($this->getName(), $command);
        $this->connect($this->config->get('huhobotwsserver', self::DEFAULT_CONFIG['huhobotwsserver']));
        if($this->config->get('hashkey', '') === '') {
            $this->getLogger()->notice('未检测到绑定密钥，要想绑定QQ群，请让HuHoBot机器人执行 /绑定 ' . $this->config->get('serverid', '服务器ID'));
        }
        if($this->config->get('chatforwarding', self::DEFAULT_CONFIG['chatforwarding'])) {
            $this->getServer()->getPluginManager()->registerEvents($this, $this); // TODO: 这个不对
        }
    }
    public function connect(string $host) {
        if($this->isConnected()) {
            return false;
        }
        $this->networkthread = new NetworkThread($host, $this->getServer()->getLogger(), $this->config->get('serverid', str_repeat('0', 32)), $this->config->get('hashkey', self::DEFAULT_CONFIG['hashkey']), $this->config->get('servername', self::DEFAULT_CONFIG['servername']), $this->config->get('platformname', self::DEFAULT_CONFIG['platformname']), $this->config->get('platformversion', self::DEFAULT_CONFIG['platformversion']));
        $this->networkthread->start();
        $this->getLogger()->debug('正常启动');
        $this->queuereadtaskhandler = $this->getServer()->getScheduler()->scheduleRepeatingTask($this->queuereadtask = new QueueReadTask($this), $this->config->get('readperiod', self::DEFAULT_CONFIG['readperiod']));
        return true;
    }
    public function isConnected() {
        return $this->networkthread !== null && $this->queuereadtask !== null && $this->queuereadtaskhandler !== null;
    }
    /**
     * @priority MONITOR
     */
    public function onPlayerChat(PlayerChatEvent $event) { // TODO: 要看到控制台发话
        if($event->isCancelled()) {
            return;
        }
        if(time() - $this->config->get('chatforwardingtimelimit', Main::DEFAULT_CONFIG['chatforwardingtimelimit']) > $this->lastqqchat) {
            return;
        }
        // TODO: 控制不要转发
        $this->networkthread->queuei[] = HuHoBotClient::constructDataPacket('chat', ['msg' => $this->getServer()->getLanguage()->translateString($event->getFormat(), [$event->getPlayer()->getName(), $event->getMessage()]), 'serverId' => $this->networkthread->getServerId()]);
    }
    public function respone(string $msg, $uuid, $success = true) { // TODO: 其它地方有字数限制吗
        $toolong = '（消息过长）';
        $wordlimit = $this->config->get('wordlimit', self::DEFAULT_CONFIG['wordlimit']);
        if(mb_strlen($msg) > $wordlimit) {
            $msg = mb_substr($msg, 0, $wordlimit - mb_strlen($toolong)) . $toolong;
        }
        return HuHoBotClient::constructDataPacket($success ? 'success' : 'error', ['msg' => $msg], $uuid);
    }
    public function onCommand(CommandSender $sender, Command $command, $label, array $args) {
        if($command->getName() !== 'huhobot') {
            return;
        }
        if(!$sender->hasPermission('huhobot')) {
            $sender->sendMessage('你无权使用该命令');
            return;
        }
        switch(isset($args[0]) ? $args[0] : '') { // TODO: reload
            case 'help':
                $sender->sendMessage(implode("\n", [
                    '命令                          作用',
                    '/huhobot bind <验证码>        绑定QQ群',
                    '/huhobot <disconnect|q>       断开连接，停止互通',
                    '/huhobot <connect|c> [地址]   连接服务器',
                    '/huhobot reload               重新启动整个插件'
                ]));
                break;
            case 'bind':
                if(!$sender->hasPermission('huhobot.bind')) {
                    $sender->sendMessage('你缺少huhobot.bind权限，不能使用该功能');
                    break;
                }
                if(!isset($args[1])) {
                    $sender->sendMessage('未设置验证码: /huhobot bind <验证码>');
                    break;
                }
                if(isset($this->bindrequests[$args[1]])) {
                    $this->networkthread->queuei[] = HuHoBotClient::constructDataPacket('bindConfirm', [], $this->bindrequests[$args[1]]);
                    $sender->sendMessage('已确认绑定服务器，等待下发绑定密钥');
                    unset($this->bindrequests[$args[1]]);
                }
                break;
            case 'disconnect':
            case 'q':
                if(!$sender->hasPermission('huhobot.disconnect')) {
                    $sender->sendMessage('你缺少huhobot.disconnect权限，不能使用该功能');
                    break;
                }
                if($this->shutdown()) { // TODO: 别卡住
                    $sender->sendMessage('尝试退出' . $this->getName());
                } else {
                    $sender->sendMessage('已断开连接');
                }
                break;
            case 'connect':
            case 'c':
                if(!$sender->hasPermission('huhobot.connect')) {
                    $sender->sendMessage('你缺少huhobot.connect权限，不能使用该功能');
                    break;
                }
                $host = $this->config->get('huhobotwsserver', self::DEFAULT_CONFIG['huhobotwsserver']);
                if(isset($args[1])) {
                    if(!$sender->hasPermission('huhobot.connect.host')) {
                        $sender->sendMessage('你缺少huhobot.connect.host权限，不能指定目标主机');
                        break;
                    }
                    $host = $args[1];
                }
                if($this->connect($host)) {
                    $sender->sendMessage('尝试连接' . $host);
                } else {
                    $sender->sendMessage('已连接服务器');
                }
                break;
            case 'reload':
                $this->getServer()->getPluginManager()->disablePlugin($this);
                $this->getServer()->getPluginManager()->enablePlugin($this);
                break;
            default: // TODO: 更多命令
                return false;
        }
        return true;
    }
    public function newBindRequest(string $code, string $uuid) {
        $this->getLogger()->notice('有新的绑定请求！使用 /huhobot bind ' . $code . ' 确认绑定');
        $this->bindrequests[$code] = $uuid;
    }
    public function getConfig() {
        return $this->config;
    }
    public function getNetworkThread() {
        return $this->networkthread;
    }
    public function getTaskHandler() {
        return $this->queuereadtaskhandler;
    }
    public function shutdown() {
        if(!$this->isConnected()) {
            return false;
        }
        $this->queuereadtask->quit();
        $this->networkthread->quit(); // $this->networkthread->join()
        $this->networkthread = null;
        $this->queuereadtask = null;
        $this->queuereadtaskhandler = null;
        return true;
    }
    public function onDisable() {
        $this->shutdown();
    }
}
class NetworkThread extends Thread {
    public $queuei; // TODO: 不要毁了队列
    public $queueo;
    private $huhobotwsserver;
    private $logger;
    private $serverid;
    private $hashkey; // hashkey是验证qq群绑定，绑定后自动创建hashkey.txt
    private $servername;
    private $platformname;
    private $platformversion;
    public function __construct(string $huhobotwsserver, ThreadedLogger $logger, string $serverid, string $hashkey, string $servername, string $platformname, string $platformversion) {
        $this->queueo = new Threaded();
        $this->queuei = new Threaded();
        $this->huhobotwsserver = $huhobotwsserver;
        $this->serverid = $serverid;
        $this->logger = $logger;
        $this->hashkey = $hashkey;
        $this->servername = $servername;
        $this->platformname = $platformname;
        $this->platformversion = $platformversion;
    }
    public function getServerId() {
        return $this->serverid;
    }
    public function run() {
        $wsclient = new HuHoBotClient($this->huhobotwsserver, [], $this->logger, $this->serverid, $this->hashkey, $this->servername, $this->platformname, $this->platformversion);
        $wsclient->setTimeout(1);
        while(true) {
            try {
                $input = $this->queuei->shift();
                if($input !== null) {
                    $decoded = json_decode($input, true);
                    if($decoded === null) {
                        $this->logger->warning('JSON解码错误: ' . json_last_error() . ' ' . json_last_error_msg() . ' ' . $input);
                        continue;
                    }
                    if($decoded['header']['type'] === 'NetworkThread.shutdown') {
                        break;
                    } else {
                        $wsclient->send($input);
                    }
                }
                $data = $wsclient->receive();
                if($wsclient->getLastOpcode() != 'text') {
                    continue;
                }
                $data = json_decode($data, true);
                if($data === null) {
                    $this->logger->warning('JSON解码错误: ' . json_last_error() . ' ' . json_last_error_msg() . ' ' . $data);
                    continue;
                }
                $this->queueo[] = serialize($data);
                if($data['header']['type'] === 'shutdown') {
                    $this->logger->warning('远程服务器要求关闭连接，原因如下:');
                    $this->logger->warning($data['body']['msg']);
                    break;
                }
            } catch(ConnectionException $e) {
                $socket = $wsclient->getSocket();
                if($socket !== false && stream_get_meta_data($socket)['timed_out'] == true) {
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
        $this->logger->info('已退出循环');
        $this->queueo[] = serialize(json_decode(HuHoBotClient::constructDataPacket('shutdown', ['msg' => '']), true)); // TODO
    }
}
class QueueReadTask extends Task {
    protected $owner;
    protected $lastping = false;
    protected $lastpong = false;
    protected $handshaked = false; // 这个不行
    public function __construct(Main $owner) {
        $this->owner = $owner;
    }
    public function onRun($currentTick) {
        $networkTherad = $this->owner->getNetworkThread();
        foreach($networkTherad->queueo as $key => $data) {
            unset($networkTherad->queueo[$key]);
            $data = unserialize($data);
            $event = new DataPacketReceiveEvent($data);
            $this->owner->getServer()->getPluginManager()->callEvent($event);
            if($event->isCancelled()) {
                continue;
            }
            $pktype = $data['header']['type'];
            switch($pktype) {
                case 'bindRequest':
                    $this->owner->newBindRequest($data['body']['bindCode'], $data['header']['id']);
                    break;
                case 'sendConfig':
                    $this->owner->getConfig()->set('hashkey', $data['body']['hashKey']);
                    $this->owner->getConfig()->save();
                    $this->owner->getLogger()->notice('下发了新的绑定密钥');
                    break;
                case 'heart':
                    $this->lastpong = time(); // TODO: 查看延迟
                    break;
                case 'shaked':
                    switch($data['body']['code']) {
                        case 1:
                        case 2:
                            $this->owner->getLogger()->info('握手成功');
                            $this->handshaked = true;
                            break;
                        case 3:
                            $this->owner->getLogger()->warning('绑定密钥信息不匹配');
                            break;
                        case 4:
                            $this->owner->getLogger()->warning('客户端版本不匹配');
                            break;
                        case 5:
                            $this->owner->getLogger()->warning('内部错误');
                            break;
                        case 6:
                            $this->owner->getLogger()->notice('等待绑定');
                            break;
                        case 7:
                            $this->owner->getLogger()->warning('IP被封');
                            break;
                        case 8:
                            $this->owner->getLogger()->warning('服务器被封');
                            break;
                        default:
                            $this->owner->getLogger()->warning('Code: ' . $data['body']['code'] . ' Message: ' . $data['body']['msg']);
                            break;
                    }
                    if($data['body']['msg'] != '') {
                        $this->owner->getLogger()->notice($data['body']['msg']);
                    }
                    if(!$this->handshaked) {
                        $this->quit();
                    }
                    break;
                case 'chat':
                    $lines = explode("\n", $data['body']['msg']);
                    $res = [];
                    foreach($lines as $msg) {
                        // Warning: preg_replace(): Compilation failed: disallowed Unicode code point (>= 0xd800 && <= 0xdfff)
                        if($this->owner->getConfig()->get('enablefilter', Main::DEFAULT_CONFIG['enablefilter'])) {
                            $msg = preg_replace($this->owner->getConfig()->get('filter', Main::DEFAULT_CONFIG['filter']), $this->owner->getConfig()->get('replacement', Main::DEFAULT_CONFIG['replacement']), $msg);
                        }
                        if($msg === null) {
                            $msg = '（错误）';
                        }
                        if($this->owner->getConfig()->get('filterinvalidchars', Main::DEFAULT_CONFIG['enablefilter'])) {
                            $msg = self::filterInvalidChars($msg);
                        }
                        if($msg === '') {
                            $msg = '（空白消息）';
                        }
                        if($this->owner->getConfig()->get('usedefaultchatformat', Main::DEFAULT_CONFIG['usedefaultchatformat'])) {
                            $msg = '[群内消息] ' . $this->owner->getServer()->getLanguage()->translateString('%chat.type.text', [$data['body']['nick'], $msg]);
                        } else {
                            $msg = sprintf($this->owner->getConfig()->get('qqmessageformat', Main::DEFAULT_CONFIG['qqmessageformat']), $data['body']['nick'], $msg);
                            if($msg === false) {
                                $msg = '（聊天格式配置有误）';
                            }
                        }
                        $this->owner->getServer()->broadcastMessage($msg);
                        $res[] = $msg;
                    }
                    $this->owner->getNetworkThread()->queuei[] = HuHoBotClient::constructDataPacket('chat', ['msg' => implode("\n", $res), 'serverId' => $this->owner->getNetworkThread()->getServerId()], $data['header']['id']);
                    $this->owner->lastqqchat = time();
                    break;
                case 'queryOnline':
                    $server = $this->owner->getServer();
                    $onlineplayers = $server->getOnlinePlayers();
                    $str = count($onlineplayers) . '/' . $server->getMaxPlayers() . ' 在线';
                    $num = 1;
                    $showplayernametag = $this->owner->getConfig()->get('showplayernametag', Main::DEFAULT_CONFIG['showplayernametag']);
                    foreach($onlineplayers as $player) {
                        $str .= "\n{$num}. {$player->getName()}" . ($showplayernametag ? ': ' . $player->getNameTag() : '');
                        $num++; // ?
                    }
                    $this->owner->getNetworkThread()->queuei[] = HuHoBotClient::constructDataPacket('queryOnline', ['list' => ['msg' => $str, 'url' => $this->owner->getConfig()->get('serverurl', Main::DEFAULT_CONFIG['serverurl']), 'imgUrl' => $this->owner->getConfig()->get('imgurl', Main::DEFAULT_CONFIG['imgurl']), 'post_img' => $this->owner->getConfig()->get('postimg', Main::DEFAULT_CONFIG['postimg']), 'serverType' => $this->owner->getConfig()->get('servertype', Main::DEFAULT_CONFIG['servertype'])]], $data['header']['id']);
                    break;
                case 'cmd':
                    $sender = new QQCommandSender();
                    $sender->setName($this->owner->getConfig()->get('commandsendername', Main::DEFAULT_CONFIG['commandsendername']));
                    $this->owner->getServer()->dispatchCommand($sender, $data['body']['cmd']);
                    $this->owner->getNetworkThread()->queuei[] = $this->owner->respone(implode("\n", $sender->getAllMessages()), $data['header']['id']);
                    break;
                case 'run':
                case 'runAdmin': // TODO: 提供api注册自定义命令
                    $this->owner->getNetworkThread()->queuei[] = HuHoBotClient::constructDataPacket('success', ['msg' => '未实现'], $data['header']['id']);
                    break;
                case 'add':
                    $this->owner->getServer()->addWhitelist($data['body']['xboxid']);
                    $this->owner->getNetworkThread()->queuei[] = HuHoBotClient::constructDataPacket('success', ['msg' => '已尝试添加白名单: ' . $data['body']['xboxid']], $data['header']['id']);
                    break;
                case 'delete':
                    $this->owner->getServer()->removeWhitelist($data['body']['xboxid']);
                    $this->owner->getNetworkThread()->queuei[] = HuHoBotClient::constructDataPacket('success', ['msg' => '已尝试移除白名单: ' . $data['body']['xboxid']], $data['header']['id']);
                    break;
                case 'queryList':
                    $keywords = isset($data['body']['key']) ? explode(' ', $data['body']['key']) : [];
                    $whitelist = $this->owner->getServer()->getWhitelisted();
                    $all = array_keys($whitelist->getAll());
                    $page = 0;
                    if(isset($data['body']['page'])) { // 换个位置？
                        $page = $data['body']['page'] - 1;
                    }
                    $res = [];
                    foreach($all as $playername) {
                        foreach($keywords as $keyword) {
                            if(strpos($playername, $keyword) === false) {
                                $playername = false;
                                break;
                            }
                        }
                        if($playername !== false) {
                            $res[] = $playername;
                        }
                    }
                    $str = '找不到';
                    $res = array_chunk($res, $this->owner->getConfig()->get('whitelistitemsperpage', Main::DEFAULT_CONFIG['whitelistitemsperpage']), true);
                    if(!isset($res[$page])) {
                        $page = 0;
                    }
                    if(isset($res[$page])) {
                        $str = '第' . ($page + 1) . '/' . count($res) . "页\n" . implode("\n", array_map(function($key, $value) {
                            return ($key + 1) . '. ' . $value;
                        }, array_keys($res[$page]), $res[$page]));
                    }
                    $this->owner->getNetworkThread()->queuei[] = HuHoBotClient::constructDataPacket('queryWl', ['list' => $str], $data['header']['id']);
                    break;
                case 'shutdown':
                    $this->owner->shutdown();
                    break;
                default:
                    $this->owner->getLogger()->debug('未实现: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
                    break;
            }
            $this->lastpong = time();
        }
        $time = time();
        if($this->lastping !== false && $this->lastpong < $this->lastping - 15) {
            $this->owner->getLogger()->warning('连接断开？pong已超时');
            $this->owner->getLogger()->debug('lastping: ' . var_export($this->lastping, true));
            $this->owner->getLogger()->debug('lastpong: ' . var_export($this->lastpong, true));
            $this->quit();
            return;
        } else if($time - $this->owner->getConfig()->get('pingperiod', Main::DEFAULT_CONFIG['pingperiod']) > $this->lastping) { // TODO: 这里性能怎么样？
            $this->owner->getNetworkThread()->queuei[] = HuHoBotClient::constructDataPacket('heart', []);
            $this->lastping = $time;
            if($this->lastpong === false) {
                $this->lastpong = $time;
            }
        }
    }
    public function onCancel() {
        $this->owner->getLogger()->info('将停止读取');
    }
    public function quit() {
        $this->owner->getNetworkThread()->queuei[] = HuHoBotClient::constructDataPacket('NetworkThread.shutdown', []);
        $this->owner->getTaskHandler()->cancel(); // TODO: 这个说不要用
        $this->owner->getLogger()->debug('正常退出');
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
            $utf32 = mb_convert_encoding($string, 'UTF-32BE', $encoding);
        } else {
            $utf32 = mb_convert_encoding($string, 'UTF-32BE');
        }
        if($utf32 === false) {
            return false;
        }
        return unpack('N', $utf32)[1];
    }
    public static function filterInvalidChars(string $string) {
        $result = '';
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
}
class HuHoBotClient extends Client {
    protected $platformname = '';
    protected $logger;
    protected $serverid;
    protected $hashkey;
    protected $servername;
    protected $platformversion;
    public function __construct(string $uri, array $options, ThreadedLogger $logger, string $serverid, string $hashkey, string $servername, string $platformname, string $platformversion) {
        parent::__construct($uri, $options);
        $this->logger = $logger;
        $this->serverid = $serverid;
        $this->hashkey = $hashkey;
        $this->servername = $servername;
        $this->platformname = $platformname;
        $this->platformversion = $platformversion;
    }
    public function getLogger() {
        return $this->logger;
    }
    public static function constructDataPacket(string $type, array $body, $uuid = null) {
        $data = json_encode([
            'header' => [
                'type' => (string) $type,
                'id' => $uuid === null ? bin2hex(UUID::fromRandom()->toBinary()) : ($uuid instanceof UUID ? bin2hex($uuid->toBinary()) : $uuid)
            ],
            'body' => (array) $body
        ]);
        return $data; // TODO: false
    }
    public function connect() {
        parent::connect();
        $this->send(self::constructDataPacket('shakeHand', [
            'serverId' => $this->serverid,
            'hashKey' => $this->hashkey, // bin2hex(random_bytes(32))
            'name' => $this->servername,
            'version' => $this->platformversion, // 文档说可以设置dev版本
            'platform' => $this->platformname
        ]));
        $this->logger->info('已建立连接');
    }
    public function send($payload, $opcode = 'text', $masked = true) {
        $this->logger->debug('[将发送] ' . $payload);
        parent::send($payload, $opcode, $masked);
    }
    public function receive() {
        $data = parent::receive();
        $this->logger->debug('[接收到] ' . $data);
        return $data;
    }
    public function getSocket() {
        return $this->socket;
    }
}
class QQCommandSender extends ConsoleCommandSender {
    protected $msg = [];
    protected $name = 'QQ Console';
    public function getName() : string { // scaxe 2.9b3
        return $this->name;
    }
    public function setName(string $name) {
        $this->name = $name;
    }
    public function sendMessage($message) {
        if($message instanceof TextContainer) {
            $message = $this->getServer()->getLanguage()->translate($message);
        } else {
            $message = $this->getServer()->getLanguage()->translateString($message);
        }
        foreach(explode("\n", $message) as $line) {
            $this->msg[] = $line;
        }
    }
    public function getAllMessages() {
        return $this->msg;
    }
}
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

/*
Websocket PHP is free software released under the following license:

ISC License

Permission to use, copy, modify, and/or distribute this software for any purpose with or without
fee is hereby granted, provided that the above copyright notice and this permission notice appear
in all copies.

THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES WITH REGARD TO THIS
SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE
AUTHOR BE LIABLE FOR ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN ACTION OF CONTRACT,
NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF OR IN CONNECTION WITH THE USE OR PERFORMANCE OF
THIS SOFTWARE.

*/

/*
The following part is part of Websocket PHP and is free software under the ISC License.

Copyright (C) 2014-2020 Textalk/Abicart and contributors.
*/

namespace WebSocket;

class Exception extends \Exception
{
}

namespace WebSocket;

class BadOpcodeException extends Exception
{
}

namespace WebSocket;

class BadUriException extends Exception
{
}

namespace WebSocket;

class ConnectionException extends Exception
{
}


/**
 * Copyright (C) 2014-2020 Textalk/Abicart and contributors.
 *
 * This file is part of Websocket PHP and is free software under the ISC License.
 * License text: https://raw.githubusercontent.com/Textalk/websocket-php/master/COPYING
 */

namespace WebSocket;

abstract class Base // 抽象
{
    protected $socket;
    protected $options = [];
    protected $is_closing = false;
    protected $last_opcode = null;
    protected $close_status = null;

    protected $opcodes = array( // 我取消设置静态
        'continuation' => 0,
        'text'         => 1,
        'binary'       => 2,
        'close'        => 8,
        'ping'         => 9,
        'pong'         => 10,
    );

    public function getLastOpcode()
    {
        return $this->last_opcode;
    }

    public function getCloseStatus()
    {
        return $this->close_status;
    }

    public function isConnected()
    {
        return $this->socket && get_resource_type($this->socket) == 'stream';
    }

    public function setTimeout($timeout)
    {
        $this->options['timeout'] = $timeout;

        if ($this->isConnected()) {
            stream_set_timeout($this->socket, $timeout);
        }
    }

    public function setFragmentSize($fragment_size)
    {
        $this->options['fragment_size'] = $fragment_size;
        return $this;
    }

    public function getFragmentSize()
    {
        return $this->options['fragment_size'];
    }

    public function send($payload, $opcode = 'text', $masked = true)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        if (!in_array($opcode, array_keys($this->opcodes))) {
            throw new BadOpcodeException("Bad opcode '$opcode'.  Try 'text' or 'binary'.");
        }

        // record the length of the payload
        $payload_length = strlen($payload);

        $fragment_cursor = 0;
        // while we have data to send
        while ($payload_length > $fragment_cursor) {
            // get a fragment of the payload
            $sub_payload = substr($payload, $fragment_cursor, $this->options['fragment_size']);

            // advance the cursor
            $fragment_cursor += $this->options['fragment_size'];

            // is this the final fragment to send?
            $final = $payload_length <= $fragment_cursor;

            // send the fragment
            $this->sendFragment($final, $sub_payload, $opcode, $masked);

            // all fragments after the first will be marked a continuation
            $opcode = 'continuation';
        }
    }

    protected function sendFragment($final, $payload, $opcode, $masked)
    {
        // Binary string for header.
        $frame_head_binstr = '';

        // Write FIN, final fragment bit.
        $frame_head_binstr .= (bool) $final ? '1' : '0';

        // RSV 1, 2, & 3 false and unused.
        $frame_head_binstr .= '000';

        // Opcode rest of the byte.
        $frame_head_binstr .= sprintf('%04b', $this->opcodes[$opcode]);

        // Use masking?
        $frame_head_binstr .= $masked ? '1' : '0';

        // 7 bits of payload length...
        $payload_length = strlen($payload);
        if ($payload_length > 65535) {
            $frame_head_binstr .= decbin(127);
            $frame_head_binstr .= sprintf('%064b', $payload_length);
        } elseif ($payload_length > 125) {
            $frame_head_binstr .= decbin(126);
            $frame_head_binstr .= sprintf('%016b', $payload_length);
        } else {
            $frame_head_binstr .= sprintf('%07b', $payload_length);
        }

        $frame = '';

        // Write frame head to frame.
        foreach (str_split($frame_head_binstr, 8) as $binstr) {
            $frame .= chr(bindec($binstr));
        }

        // Handle masking
        if ($masked) {
            // generate a random mask:
            $mask = '';
            for ($i = 0; $i < 4; $i++) {
                $mask .= chr(rand(0, 255));
            }
            $frame .= $mask;
        }

        // Append payload to frame:
        for ($i = 0; $i < $payload_length; $i++) {
            $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }

        $this->write($frame);
    }

    abstract protected function connect();

    public function receive()
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $payload = '';
        do {
            $response = $this->receiveFragment();
            $payload .= $response[0];
        } while (!$response[1]);

        return $payload;
    }

    protected function receiveFragment()
    {
        // Just read the main fragment information first.
        $data = $this->read(2);

        // Is this the final fragment?  // Bit 0 in byte 0
        $final = (bool) (ord($data[0]) & 1 << 7);

        // Should be unused, and must be false…  // Bits 1, 2, & 3
        $rsv1  = (bool) (ord($data[0]) & 1 << 6);
        $rsv2  = (bool) (ord($data[0]) & 1 << 5);
        $rsv3  = (bool) (ord($data[0]) & 1 << 4);

        // Parse opcode
        $opcode_int = ord($data[0]) & 31; // Bits 4-7
        $opcode_ints = array_flip($this->opcodes);
        if (!array_key_exists($opcode_int, $opcode_ints)) {
            throw new ConnectionException("Bad opcode in websocket frame: $opcode_int");
        }
        $opcode = $opcode_ints[$opcode_int];

        // Record the opcode if we are not receiving a continutation fragment
        if ($opcode !== 'continuation') {
            $this->last_opcode = $opcode;
        }

        // Masking?
        $mask = (bool) (ord($data[1]) >> 7);  // Bit 0 in byte 1

        $payload = '';

        // Payload length
        $payload_length = (int) ord($data[1]) & 127; // Bits 1-7 in byte 1
        if ($payload_length > 125) {
            if ($payload_length === 126) {
                $data = $this->read(2); // 126: Payload is a 16-bit unsigned int
            } else {
                $data = $this->read(8); // 127: Payload is a 64-bit unsigned int
            }
            $payload_length = bindec(self::sprintB($data));
        }

        // Get masking key.
        if ($mask) {
            $masking_key = $this->read(4);
        }

        // Get the actual payload, if any (might not be for e.g. close frames.
        if ($payload_length > 0) {
            $data = $this->read($payload_length);

            if ($mask) {
                // Unmask payload.
                for ($i = 0; $i < $payload_length; $i++) {
                    $payload .= ($data[$i] ^ $masking_key[$i % 4]);
                }
            } else {
                $payload = $data;
            }
        }

        // if we received a ping, send a pong
        if ($opcode === 'ping') {
            $this->send($payload, 'pong', true);
        }

        if ($opcode === 'close') {
            // Get the close status.
            if ($payload_length > 0) {
                $status_bin = $payload[0] . $payload[1];
                $status = bindec(sprintf("%08b%08b", ord($payload[0]), ord($payload[1])));
                $this->close_status = $status;
            }
            // Get additional close message-
            if ($payload_length >= 2) {
                $payload = substr($payload, 2);
            }

            if ($this->is_closing) {
                $this->is_closing = false; // A close response, all done.
            } else {
                $this->send($status_bin . 'Close acknowledged: ' . $status, 'close', true); // Respond.
            }

            // Close the socket.
            fclose($this->socket);

            // Closing should not return message.
            return [null, true];
        }

        return [$payload, $final];
    }

    /**
     * Tell the socket to close.
     *
     * @param integer $status  http://tools.ietf.org/html/rfc6455#section-7.4
     * @param string  $message A closing message, max 125 bytes.
     */
    public function close($status = 1000, $message = 'ttfn')
    {
        if (!$this->isConnected()) {
            return null;
        }
        $status_binstr = sprintf('%016b', $status);
        $status_str = '';
        foreach (str_split($status_binstr, 8) as $binstr) {
            $status_str .= chr(bindec($binstr));
        }
        $this->send($status_str . $message, 'close', true);

        $this->is_closing = true;
        $this->receive(); // Receiving a close frame will close the socket now.
    }

    protected function write($data)
    {
        $written = fwrite($this->socket, $data);

        if ($written < strlen($data)) {
            throw new ConnectionException(
                "Could only write $written out of " . strlen($data) . " bytes."
            );
        }
    }

    protected function read($length)
    {
        $data = '';
        while (strlen($data) < $length) {
            $buffer = fread($this->socket, $length - strlen($data));
            if ($buffer === false) {
                $metadata = stream_get_meta_data($this->socket);
                throw new ConnectionException(
                    'Broken frame, read ' . strlen($data) . ' of stated '
                    . $length . ' bytes.  Stream state: '
                    . json_encode($metadata)
                );
            }
            if ($buffer === '') {
                $metadata = stream_get_meta_data($this->socket);
                throw new ConnectionException(
                    'Empty read; connection dead?  Stream state: ' . json_encode($metadata)
                );
            }
            $data .= $buffer;
        }
        return $data;
    }


    /**
     * Helper to convert a binary to a string of '0' and '1'.
     */
    protected static function sprintB($string)
    {
        $return = '';
        for ($i = 0; $i < strlen($string); $i++) {
            $return .= sprintf("%08b", ord($string[$i]));
        }
        return $return;
    }
}

/**
 * Copyright (C) 2014-2020 Textalk/Abicart and contributors.
 *
 * This file is part of Websocket PHP and is free software under the ISC License.
 * License text: https://raw.githubusercontent.com/Textalk/websocket-php/master/COPYING
 */

namespace WebSocket;

class Client extends Base
{
    // Default options
    protected $default_options = [ // 取消静态
      'timeout'       => 5,
      'fragment_size' => 4096,
      'context'       => null,
      'headers'       => null,
      'origin'        => null, // @deprecated
    ];

    protected $socket_uri;

    /**
     * @param string $uri     A ws/wss-URI
     * @param array  $options
     *   Associative array containing:
     *   - context:       Set the stream context. Default: empty context
     *   - timeout:       Set the socket timeout in seconds.  Default: 5
     *   - fragment_size: Set framgemnt size.  Default: 4096
     *   - headers:       Associative array of headers to set/override.
     */
    public function __construct($uri, $options = array())
    {
        $this->options = array_merge($this->default_options, $options);
        $this->socket_uri = $uri;
    }

    public function __destruct()
    {
        if ($this->isConnected()) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    /**
     * Perform WebSocket handshake
     */
    protected function connect()
    {
        $url_parts = parse_url($this->socket_uri);
        if (empty($url_parts) || empty($url_parts['scheme']) || empty($url_parts['host'])) {
            throw new BadUriException(
                "Invalid url '$this->socket_uri' provided."
            );
        }
        $scheme    = $url_parts['scheme'];
        $host      = $url_parts['host'];
        $user      = isset($url_parts['user']) ? $url_parts['user'] : '';
        $pass      = isset($url_parts['pass']) ? $url_parts['pass'] : '';
        $port      = isset($url_parts['port']) ? $url_parts['port'] : ($scheme === 'wss' ? 443 : 80);
        $path      = isset($url_parts['path']) ? $url_parts['path'] : '/';
        $query     = isset($url_parts['query'])    ? $url_parts['query'] : '';
        $fragment  = isset($url_parts['fragment']) ? $url_parts['fragment'] : '';

        $path_with_query = $path;
        if (!empty($query)) {
            $path_with_query .= '?' . $query;
        }
        if (!empty($fragment)) {
            $path_with_query .= '#' . $fragment;
        }

        if (!in_array($scheme, array('ws', 'wss'))) {
            throw new BadUriException(
                "Url should have scheme ws or wss, not '$scheme' from URI '$this->socket_uri' ."
            );
        }

        $host_uri = ($scheme === 'wss' ? 'ssl' : 'tcp') . '://' . $host;

        // Set the stream context options if they're already set in the config
        if (isset($this->options['context'])) {
            // Suppress the error since we'll catch it below
            if (@get_resource_type($this->options['context']) === 'stream-context') {
                $context = $this->options['context'];
            } else {
                throw new \InvalidArgumentException(
                    "Stream context in \$options['context'] isn't a valid context"
                );
            }
        } else {
            $context = stream_context_create();
        }

        // Open the socket.  @ is there to supress warning that we will catch in check below instead.
        $this->socket = @stream_socket_client(
            $host_uri . ':' . $port,
            $errno,
            $errstr,
            $this->options['timeout'],
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$this->isConnected()) {
            throw new ConnectionException(
                "Could not open socket to \"$host:$port\": $errstr ($errno)."
            );
        }

        // Set timeout on the stream as well.
        stream_set_timeout($this->socket, $this->options['timeout']);

        // Generate the WebSocket key.
        $key = self::generateKey();

        // Default headers
        $headers = array(
            'Host'                  => $host . ":" . $port,
            'User-Agent'            => 'websocket-client-php',
            'Connection'            => 'Upgrade',
            'Upgrade'               => 'websocket',
            'Sec-WebSocket-Key'     => $key,
            'Sec-WebSocket-Version' => '13',
        );

        // Handle basic authentication.
        if ($user || $pass) {
            $headers['authorization'] = 'Basic ' . base64_encode($user . ':' . $pass) . "\r\n";
        }

        // Deprecated way of adding origin (use headers instead).
        if (isset($this->options['origin'])) {
            $headers['origin'] = $this->options['origin'];
        }

        // Add and override with headers from options.
        if (isset($this->options['headers'])) {
            $headers = array_merge($headers, $this->options['headers']);
        }

        $header = "GET " . $path_with_query . " HTTP/1.1\r\n" . implode(
            "\r\n",
            array_map(
                function ($key, $value) {
                    return "$key: $value";
                },
                array_keys($headers),
                $headers
            )
        ) . "\r\n\r\n";

        // Send headers.
        $this->write($header);

        // Get server response header (terminated with double CR+LF).
        $response = stream_get_line($this->socket, 1024, "\r\n\r\n");

        /// @todo Handle version switching

        // Validate response.
        if (!preg_match('#Sec-WebSocket-Accept:\s(.*)$#mUi', $response, $matches)) {
            $address = $scheme . '://' . $host . $path_with_query;
            throw new ConnectionException(
                "Connection to '{$address}' failed: Server sent invalid upgrade response:\n"
                . $response
            );
        }

        $keyAccept = trim($matches[1]);
        $expectedResonse
            = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

        if ($keyAccept !== $expectedResonse) {
            throw new ConnectionException('Server sent bad upgrade response.');
        }
    }

    /**
     * Generate a random string for WebSocket key.
     *
     * @return string Random string
     */
    protected static function generateKey()
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"$&/()=[]{}0123456789';
        $key = '';
        $chars_length = strlen($chars);
        for ($i = 0; $i < 16; $i++) {
            $key .= $chars[mt_rand(0, $chars_length - 1)];
        }
        return base64_encode($key);
    }
}
