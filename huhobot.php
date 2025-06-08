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
设置了WebSocket\Base类为抽象类
补充了抽象方法connect
取消opcodes属性静态
*/

/*
MIT License

Copyright (c) 2025 wusheng233

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

*/

namespace wusheng233\HuHoBot;

use Exception;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\command\PluginCommand;
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
 * @version 0.0.1
 * @main wusheng233\HuHoBot\Main
 * @api 2.0.0
 * @license https://opensource.org/license/MIT MIT
 */
class Main extends PluginBase {
    public static $pluginversion = 'dev'; // TODO
    const DEFAULT_CONFIG = [
        'huhobotwsserver' => '119.91.100.129:8888', // TODO: 格式验证、清理
        'hashkey' => '',
        'servername' => '',
        'removeemoji' => true
    ];
    /** @var Config */
    private $config;
    /** @var NetworkThread */
    private $networkthread;
    /** @var \pocketmine\scheduler\TaskHandler */
    private $queuereadtaskhandler;
    private $bindrequests = [];
    public function onEnable() {
        //self::$pluginversion = $this->getDescription()->getVersion();
        $datafolder = rtrim($this->getDataFolder(), '/');
        if(!file_exists($datafolder)) {
            mkdir($datafolder);
        }
        if(!is_dir($datafolder) || is_dir($datafolder . '/config.json')) {
            $this->getLogger()->error('数据文件夹错误');
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return false;
        }
        $this->config = new Config($this->getDataFolder() . '/config.json', Config::JSON, self::DEFAULT_CONFIG);
        if(!$this->config->exists('serverid')) {
            $this->config->set('serverid', bin2hex(random_bytes(16)));
            $this->config->save();
        }
        $root = new Permission('huhobot', '允许控制HuHoBot插件', Permission::DEFAULT_OP);
        DefaultPermissions::registerPermission($root);
        DefaultPermissions::registerPermission(new Permission('huhobot.bind', '允许通过命令让服务器绑定QQ群', Permission::DEFAULT_OP), $root);
        $command = new PluginCommand('huhobot', $this);
        $command->setDescription('HuHoBot控制命令'); // TODO: 多语言
        $command->setUsage('详情请在 /huhobot help 查看');
        $command->setPermission('huhobot');
        $command->setExecutor($this);
        $this->getServer()->getCommandMap()->register($this->getName(), $command);
        $this->networkthread = new NetworkThread($this->config->get('huhobotwsserver', self::DEFAULT_CONFIG['huhobotwsserver']), $this->getServer()->getLogger(), $this->config->get('serverid', str_repeat('0', 32)), $this->config->get('hashkey', self::DEFAULT_CONFIG['hashkey']), $this->config->get('servername', self::DEFAULT_CONFIG['servername']));
        $this->networkthread->start();
        $this->queuereadtaskhandler = $this->getServer()->getScheduler()->scheduleRepeatingTask(new class($this) extends Task {
            private $owner;
            public function __construct(Main $owner) {
                $this->owner = $owner;
            }
            public function onRun($currentTick) {
                $data = $this->owner->getNetworkThread()->queueo->shift();
                if($data === null) {
                    return;
                }
                $data = unserialize($data);
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
                        $this->owner->getNetworkThread()->queuei[] = HuHoBotClient::constructDataPacket('internal', ['pong', time()], $data['header']['id']); // TODO: 查看延迟
                        break;
                    case 'shaked':
                        switch($data['body']['code']) {
                            case 1:
                                $this->owner->getLogger()->info('握手成功');
                                break;
                            case 2:
                                $this->owner->getLogger()->notice('握手成功，附带一条消息:');
                                $this->owner->getLogger()->notice($data['body']['msg']);
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
                            case 7: // TODO: 处理握手失败
                                $this->owner->getLogger()->warning('IP被封');
                                break;
                            case 8:
                                $this->owner->getLogger()->warning('服务器被封');
                                break;
                            default:
                                $this->owner->getLogger()->warning('Code: ' . $data['body']['code'] . ' Message: ' . $data['body']['msg']);
                                break;
                        }
                        break;
                    case 'chat':
                        $lines = explode("\n", $data['body']['msg']);
                        $res = [];
                        foreach($lines as $msg) {
                            if($msg === '') {
                                $msg = '（空白消息）';
                            }
                            $msg = '[群内消息] ' . $this->owner->getServer()->getLanguage()->translateString('%chat.type.text', [$data['body']['nick'], $msg]);
                            if($this->owner->getConfig()->get('removeemoji', Main::DEFAULT_CONFIG['removeemoji'])) {
                                $msg = Main::removeEmoji($msg);
                            }
                            $this->owner->getServer()->broadcastMessage($msg);
                            $res[] = $msg;
                        }
                        $this->owner->getNetworkThread()->queuei[] = HuHoBotClient::constructDataPacket('chat', ['msg' => implode("\n", $res), 'serverId' => $this->owner->getNetworkThread()->getServerId()], $data['header']['id']);
                        break;
                    case 'queryOnline':
                        $server = $this->owner->getServer();
                        $onlineplayers = $server->getOnlinePlayers();
                        $str = count($onlineplayers) . '/' . $server->getMaxPlayers() . ' 在线';
                        $num = 1;
                        foreach($onlineplayers as $player) {
                            $str .= "\n{$num}. {$player->getName()}: {$player->getNameTag()}";
                            $num++; // ?
                        }
                        // TODO: 配置配图
                        $this->owner->getNetworkThread()->queuei[] = HuHoBotClient::constructDataPacket('queryOnline', ['list' => ['msg' => $str, 'url' => '1.14.51.4:19198', 'imgUrl' => 'https://tu.ltyuanfang.cn/api/fengjing.php', 'post_img' => true, 'serverType' => 'bedrock']], $data['header']['id']);
                        break;
                    case 'cmd':
                        $sender = new QQCommandSender();
                        $this->owner->getServer()->dispatchCommand($sender, $data['body']['cmd']); // TODO: 防恶意命令？
                        $this->owner->getNetworkThread()->queuei[] = HuHoBotClient::constructDataPacket('success', ['msg' => implode("\n", $sender->getAllMessages())], $data['header']['id']);
                        break;
                    case 'run':
                    case 'runAdmin':
                        $this->owner->getNetworkThread()->queuei[] = HuHoBotClient::constructDataPacket('success', ['msg' => '未实现'], $data['header']['id']);
                        break;
                    case 'add':
                        $this->owner->getServer()->addWhitelist($data['body']['xboxid']); // TODO: 验证
                        $this->owner->getNetworkThread()->queuei[] = HuHoBotClient::constructDataPacket('success', ['msg' => '已尝试添加白名单: ' . $data['body']['xboxid']], $data['header']['id']);
                        break;
                    case 'delete':
                        $this->owner->getServer()->removeWhitelist($data['body']['xboxid']); // TODO: 验证
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
                        $res = array_chunk($res, 10, true); // TODO: 配置
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
                    default:
                        $this->owner->getLogger()->debug('未实现: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
                        break;
                }
            }
            public function onCancel() {
                $this->owner->getNetworkThread()->queuei[] = HuHoBotClient::constructDataPacket('internal', ['shutdown']);
            }
        }, 10);
        if($this->config->get('hashkey', '') === '') {
            $this->getLogger()->notice('该服务器未绑定QQ群，要想绑定，请让HuHoBot机器人执行 /绑定 ' . $this->config->get('serverid', '服务器ID'));
        }
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
                $sender->sendMessage('未完成');
                break;
            case 'bind':
                if(!$sender->hasPermission('huhobot.bind')) {
                    $sender->sendMessage('你无权使用该功能，缺少huhobot.bind权限');
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
            default:
                return false;
        }
        return true;
    }
    public function newBindRequest(string $code, string $uuid) {
        $this->getLogger()->notice('有新的绑定请求！使用 /huhobot bind ' . $code . ' 确认');
        $this->bindrequests[$code] = $uuid;
    }
    public function getConfig() {
        return $this->config;
    }
    public function getNetworkThread() {
        return $this->networkthread;
    }
    public function onDisable() {
        $this->queuereadtaskhandler->cancel();
    }
    public static function removeEmoji(string $string) {
        // https://jamesqi.com/%E5%8D%9A%E5%AE%A2/%E5%8C%B9%E9%85%8DEmoji%E7%9A%84%E6%AD%A3%E5%88%99%E8%A1%A8%E8%BE%BE%E5%BC%8F
        $regex_regional_indicator = '\x{1F1E6}-\x{1F1FF}';
        $regex_emoji_character = '\x{0023}|\x{002A}|\x{0030}-\x{0039}|\x{00A9}|\x{00AE}|\x{203C}|\x{2049}|\x{2122}|\x{2139}|\x{2194}-\x{2199}|\x{21A9}-\x{21AA}|\x{231A}-\x{231B}|\x{2328}|\x{23CF}|\x{23E9}-\x{23EC}|\x{23ED}-\x{23EE}|\x{23EF}|\x{23F0}|\x{23F1}-\x{23F2}|\x{23F3}|\x{23F8}-\x{23FA}|\x{24C2}|\x{25AA}-\x{25AB}|\x{25B6}|\x{25C0}|\x{25FB}-\x{25FE}|\x{2600}-\x{2601}|\x{2602}-\x{2603}|\x{2604}|\x{260E}|\x{2611}|\x{2614}-\x{2615}|\x{2618}|\x{261D}|\x{2620}|\x{2622}-\x{2623}|\x{2626}|\x{262A}|\x{262E}|\x{262F}|\x{2638}-\x{2639}|\x{263A}|\x{2640}|\x{2642}|\x{2648}-\x{2653}|\x{265F}|\x{2660}|\x{2663}|\x{2665}-\x{2666}|\x{2668}|\x{267B}|\x{267E}|\x{267F}|\x{2692}|\x{2693}|\x{2694}|\x{2695}|\x{2696}-\x{2697}|\x{2699}|\x{269B}-\x{269C}|\x{26A0}-\x{26A1}|\x{26A7}|\x{26AA}-\x{26AB}|\x{26B0}-\x{26B1}|\x{26BD}-\x{26BE}|\x{26C4}-\x{26C5}|\x{26C8}|\x{26CE}|\x{26CF}|\x{26D1}|\x{26D3}|\x{26D4}|\x{26E9}|\x{26EA}|\x{26F0}-\x{26F1}|\x{26F2}-\x{26F3}|\x{26F4}|\x{26F5}|\x{26F7}-\x{26F9}|\x{26FA}|\x{26FD}|\x{2702}|\x{2705}|\x{2708}-\x{270C}|\x{270D}|\x{270F}|\x{2712}|\x{2714}|\x{2716}|\x{271D}|\x{2721}|\x{2728}|\x{2733}-\x{2734}|\x{2744}|\x{2747}|\x{274C}|\x{274E}|\x{2753}-\x{2755}|\x{2757}|\x{2763}|\x{2764}|\x{2795}-\x{2797}|\x{27A1}|\x{27B0}|\x{27BF}|\x{2934}-\x{2935}|\x{2B05}-\x{2B07}|\x{2B1B}-\x{2B1C}|\x{2B50}|\x{2B55}|\x{3030}|\x{303D}|\x{3297}|\x{3299}|\x{1F004}|\x{1F0CF}|\x{1F170}-\x{1F171}|\x{1F17E}-\x{1F17F}|\x{1F18E}|\x{1F191}-\x{1F19A}|\x{1F1E6}-\x{1F1FF}|\x{1F201}-\x{1F202}|\x{1F21A}|\x{1F22F}|\x{1F232}-\x{1F23A}|\x{1F250}-\x{1F251}|\x{1F300}-\x{1F30C}|\x{1F30D}-\x{1F30E}|\x{1F30F}|\x{1F310}|\x{1F311}|\x{1F312}|\x{1F313}-\x{1F315}|\x{1F316}-\x{1F318}|\x{1F319}|\x{1F31A}|\x{1F31B}|\x{1F31C}|\x{1F31D}-\x{1F31E}|\x{1F31F}-\x{1F320}|\x{1F321}|\x{1F324}-\x{1F32C}|\x{1F32D}-\x{1F32F}|\x{1F330}-\x{1F331}|\x{1F332}-\x{1F333}|\x{1F334}-\x{1F335}|\x{1F336}|\x{1F337}-\x{1F34A}|\x{1F34B}|\x{1F34C}-\x{1F34F}|\x{1F350}|\x{1F351}-\x{1F37B}|\x{1F37C}|\x{1F37D}|\x{1F37E}-\x{1F37F}|\x{1F380}-\x{1F393}|\x{1F396}-\x{1F397}|\x{1F399}-\x{1F39B}|\x{1F39E}-\x{1F39F}|\x{1F3A0}-\x{1F3C4}|\x{1F3C5}|\x{1F3C6}|\x{1F3C7}|\x{1F3C8}|\x{1F3C9}|\x{1F3CA}|\x{1F3CB}-\x{1F3CE}|\x{1F3CF}-\x{1F3D3}|\x{1F3D4}-\x{1F3DF}|\x{1F3E0}-\x{1F3E3}|\x{1F3E4}|\x{1F3E5}-\x{1F3F0}|\x{1F3F3}|\x{1F3F4}|\x{1F3F5}|\x{1F3F7}|\x{1F3F8}-\x{1F407}|\x{1F408}|\x{1F409}-\x{1F40B}|\x{1F40C}-\x{1F40E}|\x{1F40F}-\x{1F410}|\x{1F411}-\x{1F412}|\x{1F413}|\x{1F414}|\x{1F415}|\x{1F416}|\x{1F417}-\x{1F429}|\x{1F42A}|\x{1F42B}-\x{1F43E}|\x{1F43F}|\x{1F440}|\x{1F441}|\x{1F442}-\x{1F464}|\x{1F465}|\x{1F466}-\x{1F46B}|\x{1F46C}-\x{1F46D}|\x{1F46E}-\x{1F4AC}|\x{1F4AD}|\x{1F4AE}-\x{1F4B5}|\x{1F4B6}-\x{1F4B7}|\x{1F4B8}-\x{1F4EB}|\x{1F4EC}-\x{1F4ED}|\x{1F4EE}|\x{1F4EF}|\x{1F4F0}-\x{1F4F4}|\x{1F4F5}|\x{1F4F6}-\x{1F4F7}|\x{1F4F8}|\x{1F4F9}-\x{1F4FC}|\x{1F4FD}|\x{1F4FF}-\x{1F502}|\x{1F503}|\x{1F504}-\x{1F507}|\x{1F508}|\x{1F509}|\x{1F50A}-\x{1F514}|\x{1F515}|\x{1F516}-\x{1F52B}|\x{1F52C}-\x{1F52D}|\x{1F52E}-\x{1F53D}|\x{1F549}-\x{1F54A}|\x{1F54B}-\x{1F54E}|\x{1F550}-\x{1F55B}|\x{1F55C}-\x{1F567}|\x{1F56F}-\x{1F570}|\x{1F573}-\x{1F579}|\x{1F57A}|\x{1F587}|\x{1F58A}-\x{1F58D}|\x{1F590}|\x{1F595}-\x{1F596}|\x{1F5A4}|\x{1F5A5}|\x{1F5A8}|\x{1F5B1}-\x{1F5B2}|\x{1F5BC}|\x{1F5C2}-\x{1F5C4}|\x{1F5D1}-\x{1F5D3}|\x{1F5DC}-\x{1F5DE}|\x{1F5E1}|\x{1F5E3}|\x{1F5E8}|\x{1F5EF}|\x{1F5F3}|\x{1F5FA}|\x{1F5FB}-\x{1F5FF}|\x{1F600}|\x{1F601}-\x{1F606}|\x{1F607}-\x{1F608}|\x{1F609}-\x{1F60D}|\x{1F60E}|\x{1F60F}|\x{1F610}|\x{1F611}|\x{1F612}-\x{1F614}|\x{1F615}|\x{1F616}|\x{1F617}|\x{1F618}|\x{1F619}|\x{1F61A}|\x{1F61B}|\x{1F61C}-\x{1F61E}|\x{1F61F}|\x{1F620}-\x{1F625}|\x{1F626}-\x{1F627}|\x{1F628}-\x{1F62B}|\x{1F62C}|\x{1F62D}|\x{1F62E}-\x{1F62F}|\x{1F630}-\x{1F633}|\x{1F634}|\x{1F635}|\x{1F636}|\x{1F637}-\x{1F640}|\x{1F641}-\x{1F644}|\x{1F645}-\x{1F64F}|\x{1F680}|\x{1F681}-\x{1F682}|\x{1F683}-\x{1F685}|\x{1F686}|\x{1F687}|\x{1F688}|\x{1F689}|\x{1F68A}-\x{1F68B}|\x{1F68C}|\x{1F68D}|\x{1F68E}|\x{1F68F}|\x{1F690}|\x{1F691}-\x{1F693}|\x{1F694}|\x{1F695}|\x{1F696}|\x{1F697}|\x{1F698}|\x{1F699}-\x{1F69A}|\x{1F69B}-\x{1F6A1}|\x{1F6A2}|\x{1F6A3}|\x{1F6A4}-\x{1F6A5}|\x{1F6A6}|\x{1F6A7}-\x{1F6AD}|\x{1F6AE}-\x{1F6B1}|\x{1F6B2}|\x{1F6B3}-\x{1F6B5}|\x{1F6B6}|\x{1F6B7}-\x{1F6B8}|\x{1F6B9}-\x{1F6BE}|\x{1F6BF}|\x{1F6C0}|\x{1F6C1}-\x{1F6C5}|\x{1F6CB}|\x{1F6CC}|\x{1F6CD}-\x{1F6CF}|\x{1F6D0}|\x{1F6D1}-\x{1F6D2}|\x{1F6D5}|\x{1F6D6}-\x{1F6D7}|\x{1F6DD}-\x{1F6DF}|\x{1F6E0}-\x{1F6E5}|\x{1F6E9}|\x{1F6EB}-\x{1F6EC}|\x{1F6F0}|\x{1F6F3}|\x{1F6F4}-\x{1F6F6}|\x{1F6F7}-\x{1F6F8}|\x{1F6F9}|\x{1F6FA}|\x{1F6FB}-\x{1F6FC}|\x{1F7E0}-\x{1F7EB}|\x{1F7F0}|\x{1F90C}|\x{1F90D}-\x{1F90F}|\x{1F910}-\x{1F918}|\x{1F919}-\x{1F91E}|\x{1F91F}|\x{1F920}-\x{1F927}|\x{1F928}-\x{1F92F}|\x{1F930}|\x{1F931}-\x{1F932}|\x{1F933}-\x{1F93A}|\x{1F93C}-\x{1F93E}|\x{1F93F}|\x{1F940}-\x{1F945}|\x{1F947}-\x{1F94B}|\x{1F94C}|\x{1F94D}-\x{1F94F}|\x{1F950}-\x{1F95E}|\x{1F95F}-\x{1F96B}|\x{1F96C}-\x{1F970}|\x{1F971}|\x{1F972}|\x{1F973}-\x{1F976}|\x{1F977}-\x{1F978}|\x{1F979}|\x{1F97A}|\x{1F97B}|\x{1F97C}-\x{1F97F}|\x{1F980}-\x{1F984}|\x{1F985}-\x{1F991}|\x{1F992}-\x{1F997}|\x{1F998}-\x{1F9A2}|\x{1F9A3}-\x{1F9A4}|\x{1F9A5}-\x{1F9AA}|\x{1F9AB}-\x{1F9AD}|\x{1F9AE}-\x{1F9AF}|\x{1F9B0}-\x{1F9B9}|\x{1F9BA}-\x{1F9BF}|\x{1F9C0}|\x{1F9C1}-\x{1F9C2}|\x{1F9C3}-\x{1F9CA}|\x{1F9CB}|\x{1F9CC}|\x{1F9CD}-\x{1F9CF}|\x{1F9D0}-\x{1F9E6}|\x{1F9E7}-\x{1F9FF}|\x{1FA70}-\x{1FA73}|\x{1FA74}|\x{1FA78}-\x{1FA7A}|\x{1FA7B}-\x{1FA7C}|\x{1FA80}-\x{1FA82}|\x{1FA83}-\x{1FA86}|\x{1FA90}-\x{1FA95}|\x{1FA96}-\x{1FAA8}|\x{1FAA9}-\x{1FAAC}|\x{1FAB0}-\x{1FAB6}|\x{1FAB7}-\x{1FABA}|\x{1FAC0}-\x{1FAC2}|\x{1FAC3}-\x{1FAC5}|\x{1FAD0}-\x{1FAD6}|\x{1FAD7}-\x{1FAD9}|\x{1FAE0}-\x{1FAE7}|\x{1FAF0}-\x{1FAF6}';
        $regex_emoji_modifier = '\x{1F3FB}-\x{1F3FF}';

        $regex_emoji_presentation_selector = '\x{FE0F}';
        $regex_keycap_symbol = '\x{20E3}';
        $regex_tag_symbol = '\x{E0020}-\x{E007E}';
        $regex_term_tag_symbol = '\x{E007F}';
        $regex_zero_width_joiner = '\x{200D}';

        $regex_emoji_flag_sequence = "[{$regex_regional_indicator}][{$regex_regional_indicator}]";
        $regex_emoji_zwj_element = "[{$regex_emoji_character}]([{$regex_emoji_modifier}]|{$regex_emoji_presentation_selector}{$regex_keycap_symbol}?|[{$regex_tag_symbol}]+{$regex_term_tag_symbol}?)?";

        $regex_emoji = "/{$regex_emoji_flag_sequence}|{$regex_emoji_zwj_element}({$regex_zero_width_joiner}{$regex_emoji_zwj_element})*/u";
        return preg_replace($regex_emoji, '', $string);
    }
}
class NetworkThread extends Thread {
    public $queuei; // TODO
    public $queueo; // TODO
    private $huhobotwsserver;
    private $logger;
    private $serverid;
    private $hashkey; // hashkey是验证qq群绑定，绑定后自动创建hashkey.txt
    private $servername;
    public function __construct(string $huhobotwsserver, ThreadedLogger $logger, string $serverid, string $hashkey, string $servername) {
        $this->queueo = new Threaded();
        $this->queuei = new Threaded();
        $this->huhobotwsserver = $huhobotwsserver;
        $this->serverid = $serverid;
        $this->logger = $logger;
        $this->hashkey = $hashkey;
        $this->servername = $servername;
    }
    public function getServerId() {
        return $this->serverid;
    }
    public function run() {
        $wsclient = new HuHoBotClient('ws://' . $this->huhobotwsserver, [], $this->logger, $this->serverid, $this->hashkey, $this->servername);
        $wsclient->setTimeout(1);
        $shutdown = false; // TODO
        $lastping = 0;
        $lastpong = PHP_INT_MAX;
        while(!$shutdown) {
            try {
                $input = $this->queuei->shift();
                if($input !== null) {
                    $decoded = json_decode($input, true);
                    if($decoded === null) {
                        $this->logger->warning('JSON解码错误: ' . json_last_error() . ' ' . json_last_error_msg() . ' ' . $input);
                        continue;
                    }
                    if($decoded['header']['type'] === 'internal') {
                        switch($decoded['body'][0]) {
                            case 'pong':
                                $lastpong = $decoded['body'][1]; // TODO: 乱序
                                break;
                            // TODO: 断开
                            case 'shutdown':
                                $shutdown = true;
                                break 2;
                        }
                    } else {
                        $wsclient->send($input);
                    }
                }
                $data = json_decode($wsclient->receive(), true);
                if($data === null) {
                    $this->logger->warning('JSON解码错误: ' . json_last_error() . ' ' . json_last_error_msg() . ' ' . $data);
                    continue;
                }
                $this->queueo[] = serialize($data);
            } catch(ConnectionException $e) {
                // TODO: 这没有正确实现心跳
                $time = time();
                if($lastpong < $time - 15) {
                    $this->logger->warning('连接断开？pong已超时');
                    $shutdown = true;
                    break;
                } else if($time - 10 > $lastping) { // TODO: 配置
                    $wsclient->send(HuHoBotClient::constructDataPacket('heart', []));
                    $lastping = $time;
                }
            } catch(Exception $e) {
                $this->logger->logException($e);
                $shutdown = true; // ?
                break;
            }
        }
        $this->logger->notice('已退出循环！');
    }
}
class HuHoBotClient extends Client {
    const PLATFORM_NAME = ''; // TODO: 发issue注册平台
    protected $logger;
    protected $serverid;
    protected $hashkey;
    protected $servername;
    public function __construct($uri, array $options = array(), ThreadedLogger $logger, string $serverid, string $hashkey, string $servername) {
        parent::__construct($uri, $options);
        $this->logger = $logger;
        $this->serverid = $serverid;
        $this->hashkey = $hashkey;
        $this->servername = $servername;
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
            'name' => $this->servername, // TODO
            'version' => Main::$pluginversion, // 文档说可以设置dev版本
            'platform' => self::PLATFORM_NAME
        ]));
        $this->logger->notice('已建立连接');
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
}
class QQCommandSender extends ConsoleCommandSender {
    private $msg = [];
    public function getName() {
        return 'QQ Console';
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
