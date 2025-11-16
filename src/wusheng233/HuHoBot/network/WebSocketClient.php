<?php
namespace wusheng233\HuHoBot\network;

class WebSocketClient {
    const STATUS_CLOSED = 0;
    const STATUS_CONNECTING = 1;
    const STATUS_CONNECTED = 2;
    const STATUS_HANDSHAKEING = 3;
    const STATUS_HANDSHAKED = 4;
    const STATUS_CLOSING = 5;
    //const STATUS_HANDSHAKE_FAILED = 3;errcode
    protected $host;
    protected $path;
    protected $context;
    protected $socket;
    protected $lastErrorCode;
    protected $lastErrorMessage;
    protected $status = self::STATUS_CONNECTING;
    protected $websocketKey;
    protected $reader;
    protected $buffer = "";

    protected $eventListener;

    protected $opcode; // ?
    protected $payload = "";

    public function __construct(string $url, EventListener $eventListener) {
        $url = parse_url($url);

        if($url === false || !isset($url["scheme"]) || !isset($url["host"])) {
            throw new \InvalidArgumentException("?");
        }

        $scheme = strtolower($url["scheme"]);
        $host = $url["host"];
        $port = $url["port"] ?? ($url["scheme"] == "wss" ? 443 : 80);
        /*$user = $url["user"] ?? ""; TODO
        $pass = $url["pass"] ?? "";*/
        $path = $url["path"] ?? "/";
        if(isset($url["query"])) {
            $path .= "?{$url["query"]}";
        }
        if(isset($url["fragment"])) {
            throw new \InvalidArgumentException("错误");
            //$path .= "#{$url["fragment"]}";
        }

        if($scheme === "wss") {
            $scheme = "ssl";
        } else if($scheme === "ws") {
            $scheme = "tcp";
        } else {
            throw new \InvalidArgumentException("不支持方案: $scheme");
        }

        $this->path = $path;
        $this->host = $host . (isset($url["port"]) ? ":{$url["port"]}" : "");
        $this->context = stream_context_create([
            "ssl" => [
                "verify_peer" => false,
                "verify_peer_name" => false,
            ]
        ]);
        $this->socket = stream_socket_client("$scheme://$host:$port", $this->lastErrorCode, $this->lastErrorMessage, null, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT, $this->context);
        if(!$this->socket) {
            throw new SocketException("无法创建客户端");
        }
        stream_set_blocking($this->socket, false);
        $this->reader = new HttpMessage();
        $this->eventListener = $eventListener;
    }
    public function onRun() {
        if($this->isClosed()) {
            return;
        }
        $read = [$this->socket];
        $write = [$this->socket];
        $except = [$this->socket];
        if(!@stream_select($read, $write, $except, 0, 200000)) {
            return;
        }
        if(count($except) > 0) {
            throw new SocketException("连接失败");
        }
        if(count($write) > 0) {
            if(!$this->isConnected()) {
                $this->status = self::STATUS_CONNECTED;
                $this->eventListener->onConnected();
                $this->handshake();
            }
        }
        if(count($read) > 0) {
            $this->receive();
        }
        while($this->buffer !== "") {
            try {
                if(!$this->reader->read($this->buffer)) {
                    return;
                }
                $this->eventListener->接收到($this->reader);
                if($this->isHandshakeing()) {
                    $this->handleHandshakeResponse($this->reader);
                } else if($this->reader instanceof WebSocketFrame) {
                    $this->handleWebSocketFrame($this->reader);
                }
            } catch(\InvalidArgumentException $e) {
                $this->close();
                throw $e;
            }
        }
        //var_dump([$read, $write, $except]);
    }
    public function isConnected() {
        return $this->status === self::STATUS_CONNECTED || $this->isHandshakeing() || $this->isHandshaked() || $this->isClosing();
    }
    public function isConnecting() {
        return $this->status === self::STATUS_CONNECTING;
    }
    public function isClosed() {
        return $this->status === self::STATUS_CLOSED;
    }
    public function isHandshakeing() {
        return $this->status === self::STATUS_HANDSHAKEING;
    }
    public function isHandshaked() {
        return $this->status === self::STATUS_HANDSHAKED;
    }
    public function isClosing() {
        return $this->status === self::STATUS_CLOSING;
    }
    protected function handshake() {
        $this->send(new HttpMessage([
            ":status:" => "GET $this->path HTTP/1.1",
            "Host" => "$this->host",
            "Upgrade" => "websocket",
            "Connection" => "Upgrade",
            "Sec-WebSocket-Version" => "13",
            "Sec-WebSocket-Key" => $this->websocketKey = base64_encode(random_bytes(16))
        ]));
        $this->status = self::STATUS_HANDSHAKEING;
    }
    protected function handleHandshakeResponse(HttpMessage $response) {
        if(strpos($response[":status:"], "HTTP/1.1 101") !== 0 || // HTTP/1.1 101 Switching Protocols
           strtolower($response["upgrade"]) != "websocket" ||
           strtolower($response["connection"]) != "upgrade" || // Upgrade
           $response["sec-websocket-accept"] != base64_encode(sha1($this->websocketKey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true))) {
            throw new ProtocolException("握手失败");
        }
        $this->status = self::STATUS_HANDSHAKED;
        $this->reader = new WebSocketFrame();
        $this->eventListener->onHandShaked();
    }
    protected function handleWebSocketFrame(WebSocketFrame $frame) {
        if(!$frame->getFin()) { // TODO: 测试
            if($frame->getOpCode() == WebSocketFrame::OPCODE_CONTINUATION) {
                if($this->opcode === null) {
                    throw new ProtocolException("?");
                }
                $this->payload .= $frame->getPayload();
            } else {
                if($this->opcode !== null) {
                    throw new ProtocolException("?");
                }
                $this->opcode = $frame->getOpCode();
                $this->payload = $frame->getPayload();
            }
            return;
        }
        if($frame->getOpCode() == WebSocketFrame::OPCODE_CONTINUATION) {
            if($this->opcode === null) {
                throw new ProtocolException("?");
            }
            $frame = new WebSocketFrame($this->opcode, $this->payload);
            $this->opcode = null;
            $this->payload = null;
        }
        switch($frame->getOpCode()) {
            case WebSocketFrame::OPCODE_TEXT:
                $this->eventListener->onMessage($frame->getPayload());
                break;
            case WebSocketFrame::OPCODE_BINARY:
                $this->eventListener->onBinaryMessage($frame->getPayload());
                break;
            case WebSocketFrame::OPCODE_CONNECTION_CLOSE:
                if($this->isClosing()) {
                    break;
                }
                $this->status = self::STATUS_CLOSING;
                $this->close($frame->getPayload(), null);
                break;
            case WebSocketFrame::OPCODE_PING:
                $this->send(new WebSocketFrame(WebSocketFrame::OPCODE_PONG, $frame->getPayload()));
                break;
            case WebSocketFrame::OPCODE_PONG:
                return;
            default:
                throw new ProtocolException("未知opcode: " . $frame->getOpCode());
        }
    }
    protected function sendRaw($data) {
        if(!isset($this->socket)) {
            return;
        }
        if(feof($this->socket)) {
            $this->close();
        }
        $length = strlen($data);
        $fwrite = @fwrite($this->socket, $data);
        if($fwrite === false) {
            throw new SocketException("fwrite失败");
        } else if($fwrite !== $length) {
            throw new SocketException("$length 的数据写了 $fwrite 字节");
        }
    }
    public function send(Message $message) {
        $this->eventListener->将发送($message);
        $encoded = $message->encode();
        if(is_string($encoded)) {
            $this->sendRaw($encoded);
        } else if(is_array($encoded)) {
            foreach($encoded as $data) {
                $this->sendRaw($data);
            }
        } else {
            return false;
        }
        return true;
    }
    protected function receive() {
        if(feof($this->socket)) {
            $this->close();
            return;
        }
        $data = fread($this->socket, 2048); // ...
        if($data === false) {
            $this->close();
            throw new SocketException("fread失败");
        }
        $this->buffer .= $data;
    }
    public function close($payload = "", $status = 1000) {
        if($this->isConnected()) {
            if($this->isHandshaked()) {
                if($this->socket && !feof($this->socket)) {
                    if($status !== null) {
                        $payload = pack("n", $status) . $payload; // ?
                    }
                    try {
                        $this->send(new WebSocketFrame(WebSocketFrame::OPCODE_CONNECTION_CLOSE, $payload));
                    } catch(SocketException $e) {

                    }
                    fclose($this->socket);
                }
            }
            $this->socket = null;
            $this->status = self::STATUS_CLOSED;
            $this->eventListener->onClosed(); // 只能调用一次
        }
        $this->context = null;
        $this->lastErrorCode = null;
        $this->lastErrorMessage = null;
        $this->websocketKey = null;
        $this->buffer = "";
        $this->reader = null;
    }
}