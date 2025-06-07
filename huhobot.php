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
    private static $default_config = [
        'huhobotwsserver' => '119.91.100.129:8888', // TODO: 格式验证、清理
        'hashkey' => '',
        'servername' => ''
    ];
    /** @var Config */
    private $config;
    /** @var NetworkThread */
    private $networkthread;
    /** @var \pocketmine\scheduler\TaskHandler */
    private $queuereadtaskhandler;
    public function onEnable() {
        $datafolder = rtrim($this->getDataFolder(), '/');
        if(!file_exists($datafolder)) {
            mkdir($datafolder);
        }
        if(!is_dir($datafolder) || is_dir($datafolder . '/config.json')) {
            $this->getLogger()->error('数据文件夹错误');
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return false;
        }
        $this->config = new Config($this->getDataFolder() . '/config.json', Config::JSON, self::$default_config);
        if(!$this->config->exists('serverId')) {
            $this->config->set('serverId', bin2hex(random_bytes(16)));
            $this->config->save();
        }
        $this->networkthread = new NetworkThread($this->config->get('huhobotwsserver', self::$default_config['huhobotwsserver']), $this->config->get('serverId', str_repeat('0', 32)), $this->getServer()->getLogger(), $this->config->get('hashkey', self::$default_config['hashkey']), $this->config->get('servername', self::$default_config['servername']));
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
                if($data['type'] === 'queryOnline') {
                    $server = $this->owner->getServer();
                    $onlineplayers = $server->getOnlinePlayers();
                    $str = count($onlineplayers) . '/' . $server->getMaxPlayers() . ' 在线';
                    $num = 1;
                    foreach($onlineplayers as $i => $player) {
                        $str .= "\n{$num}. {$player->getName()}: {$player->getNameTag()}";
                        $num++; // ?
                    }
                    $this->owner->getNetworkThread()->queuei[] = serialize(['type' => 'queryOnline', 'id' => $data['id'], 'msg' => $str]); // TODO
                } else if($data['type'] === 'sendConfig') {
                    $this->owner->getConfig()->set('hashkey', $data['hashkey']);
                    $this->owner->getConfig()->save();
                } else if($data['type'] === 'chat') {
                    $msg = '[群内消息] ' . $this->owner->getServer()->getLanguage()->translateString('%chat.type.text', [$data['nick'], $data['msg']]);
                    $this->owner->getServer()->broadcastMessage($msg);
                    $this->owner->getNetworkThread()->queuei[] = serialize(['type' => 'chat', 'msg' => $msg, 'id' => $data['id']]);
                }
            }
            public function onCancel() {
                $this->owner->getNetworkThread()->queuei[] = serialize(['type' => 'shutdown']);
            }
        }, 10);
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
}
class NetworkThread extends Thread {
    public $queuei; // TODO
    public $queueo; // TODO
    private $huhobotwsserver;
    private $serverid;
    private $logger;
    private $hashkey; // hashkey是验证qq群绑定，绑定后自动创建hashkey.txt
    private $servername;
    public function __construct(string $huhobotwsserver, $serverid, ThreadedLogger $logger, string $hashkey, string $servername) {
        $this->queueo = new Threaded();
        $this->queuei = new Threaded();
        $this->huhobotwsserver = $huhobotwsserver;
        $this->serverid = $serverid;
        $this->logger = $logger;
        $this->hashkey = $hashkey;
        $this->servername = $servername;
    }
    public function construct(string $type, array $body, $uuid = null) {
        $data = json_encode([
            'header' => [
                'type' => (string) $type,
                'id' => $uuid === null ? bin2hex(UUID::fromRandom()->toBinary()) : ($uuid instanceof UUID ? bin2hex($uuid->toBinary()) : $uuid)
            ],
            'body' => (array) $body
        ]);
        $this->logger->info('[想发送] ' . $data);
        return $data; // TODO: false
    }
    public function run() {
        $wsclient = new Client('ws://' . $this->huhobotwsserver);
        $wsclient->setTimeout(1);
        // 这个是huhobot握手包，不要乱改
        $wsclient->send($this->construct('shakeHand', [
            'serverId' => $this->serverid,
            'hashKey' => $this->hashkey, // bin2hex(random_bytes(32))
            'name' => $this->servername, // TODO
            'version' => 'dev', // 文档说可以设置dev版本
            'platform' => ''
        ]));
        $shutdown = false; // TODO
        $lastheart = 0;
        while(!$shutdown) {
            try {
                $input = $this->queuei->shift();
                if($input !== null) {
                    $input = unserialize($input);
                    if($input['type'] === 'queryOnline') {
                        $wsclient->send($this->construct('queryOnline', ['list' => ['msg' => $input['msg'], 'url' => '1.14.51.4:19198', 'imgUrl' => 'https://www.gov.cn/shouye/datu/202506/W020250606312922700680_ORIGIN.jpg', 'post_img' => true, 'serverType' => 'bedrock']], $input['id'])); // TODO
                    } else if($input['type'] === 'shutdown') {
                        $shutdown = true;
                        break;
                    } else if($input['type'] === 'chat') {
                        $wsclient->send($this->construct('chat', ['serverId' => $this->serverid, 'msg' => $input['msg']], $input['id']));
                    }
                }
                $data = $wsclient->receive();
                $this->logger->notice('[接收到] ' . $data);
                $data = json_decode($data, true);
                $pktype = $data['header']['type'];
                if($pktype === 'bindRequest') {
                    $wsclient->send($this->construct('bindConfirm', [], $data['header']['id']));
                } else if($pktype === 'sendConfig') {
                    $this->queueo[] = serialize(['type' => 'sendConfig', 'hashkey' => $data['body']['hashKey']]); //
                } else if($pktype === 'chat') { // TODO
                    $this->queueo[] = serialize(['type' => 'chat', 'msg' => $data['body']['msg'], 'nick' => $data['body']['nick'], 'id' => $data['header']['id']]);
                } else if($pktype === 'cmd') { // TODO
                    $wsclient->send($this->construct('success', ['msg' => '我是服务端我不会执行你说的什么' . $data['body']['cmd'] . '命令'], $data['header']['id']));
                } else if($pktype === 'run') { // TODO
                    $wsclient->send($this->construct('success', ['msg' => '我是服务端我不执行你说的什么' . $data['body']['key']], $data['header']['id']));
                } else if($pktype === 'queryOnline') {
                    $this->queueo[] = serialize(['type' => 'queryOnline', 'id' => $data['header']['id']]);
                }
            } catch(ConnectionException $e) {
                // TODO: 没有正确实现心跳
                $time = time();
                if($time > $lastheart + 10) {
                    $wsclient->send($this->construct('heart', []));
                    $lastheart = $time;
                }
            }
        }
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
    protected static $default_options = [
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
        $this->options = array_merge(self::$default_options, $options);
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
