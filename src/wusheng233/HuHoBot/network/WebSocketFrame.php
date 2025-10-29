<?php
namespace wusheng233\HuHoBot\network;
/**
 * 客户端
 */
class WebSocketFrame implements Message {
    const OPCODE_CONTINUATION = 0x0;
    const OPCODE_TEXT = 0x1;
    const OPCODE_BINARY = 0x2;
    const OPCODE_CONNECTION_CLOSE = 0x8;
    const OPCODE_PING = 0x9;
    const OPCODE_PONG = 0xA;
    protected $fin;
    protected $rsv1 = 0;
    protected $rsv2 = 0;
    protected $rsv3 = 0;
    protected $opcode;
    protected $mask = 1;
    protected $maskingKey;
    protected $payloadLength = 0;
    protected $payload = "";
    protected $maxFrameSize = 4096;
    public function __construct(int $opcode = null, $payload = null) {
        $this->setOpCode($opcode);
        $this->setPayload($payload);
    }
    public function getOpCode() {
        return $this->opcode;
    }
    public function setOpCode($opcode) {
        $this->opcode = $opcode;
    }
    public function getPayload() {
        return $this->payload;
    }
    public function setPayload($payload) {
        $this->payload = $payload;
    }
    public function getFin() {
        return $this->fin;
    }
    public function setFin(bool $fin) {
        $this->fin = $fin;
    }
    public function read(&$buffer) {
        if(strlen($buffer) < 2) {
            return false;
        }
        $b1 = ord($buffer[0]);
        $b2 = ord($buffer[1]);
        $this->fin = ($b1 & 0b10000000) >> 7;
        $this->rsv1 = ($b1 & 0b01000000) >> 6;
        $this->rsv2 = ($b1 & 0b00100000) >> 5;
        $this->rsv3 = ($b1 & 0b00010000) >> 4;
        if($this->rsv1 != 0 || $this->rsv2 != 0 || $this->rsv3 != 0) {
            throw new ProtocolException("RSV bits must be clear");
        }
        $this->opcode = $b1 & 0b00001111;
        $this->mask = ($b2 & 0b10000000) >> 7;
        if($this->mask) {
            throw new ProtocolException("服务器帧设置了掩码位");
        }
        $this->payloadLength = $b2 & 0b01111111;
        $offset = 2;
        if($this->payloadLength === 126) {
            if(strlen($buffer) < $offset + 2) {
                return false;
            }
            $this->payloadLength = unpack("n", substr($buffer, $offset, 2))[1];
            $offset += 2;
        } else if($this->payloadLength === 127) {
            if(strlen($buffer) < $offset + 8) {
                return false;
            }
            $this->payloadLength = unpack("J", substr($buffer, $offset, 8))[1];
            $offset += 8;
        }
        /*if($this->mask) {
            if(strlen($buffer) < $offset + 4) {
                return false;
            }
            $this->maskingKey = substr($buffer, $offset, 4);
            $offset += 4;
        }*/
        if(strlen($buffer) < $offset + $this->payloadLength) {
            return false;
        }
        $this->payload = substr($buffer, $offset, $this->payloadLength);
        /*if($this->mask) { // 错错错，不符合RFC6455
            $payloadLength = strlen($this->payload);
            $unmasked = "";
            for($i = 0;$i < $payloadLength;$i++) {
                $unmasked .= $this->payload[$i] ^ $this->maskingKey[$i % 4];
            }
            $this->payload = $unmasked;
        }*/
        $buffer = substr($buffer, $offset + $this->payloadLength);
        return true;
    }
    public function encode() {
        if($this->getOpCode() === null) {
            throw new \Exception("缺opcode");
        }
        //$offset = 0;
        // TODO: 分片
        return $this->encodeFragment(true, $this->opcode, $this->payload);
    }
    public function encodeFragment($fin, $opcode, $payload) {
        $b1 = ($fin ? 0b10000000 : 0) | $opcode;
        $payloadLength = strlen($payload);
        $b2 = $this->mask ? 0b10000000 : 0;
        if($payloadLength < 126) {
            $b2 |= $payloadLength;
            $header = pack("CC", $b1, $b2);
        } else if($payloadLength < 65536) {
            $b2 |= 126;
            $header = pack("CCn", $b1, $b2, $payloadLength);
        } else {
            $b2 |= 127;
            $header = pack("CCJ", $b1, $b2, $payloadLength);
        }
        if($this->mask) {
            $this->maskingKey = random_bytes(4);
            $header .= $this->maskingKey;
            $masked = ""; // Fatal error: Uncaught Error: Cannot use assign-op operators with string offsets
            $payloadLength = strlen($payload);
            for($i = 0;$i < $payloadLength;$i++) {
                $masked .= $payload[$i] ^ $this->maskingKey[$i % 4];
            }
            $payload = $masked;
        }
        return $header . $payload;
    }
    public function __toString() {
        return "WebSocketFrame: $this->opcode: $this->payload";
    }
}