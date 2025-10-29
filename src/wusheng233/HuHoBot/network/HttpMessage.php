<?php
namespace wusheng233\HuHoBot\network;
/**
 * from wusheng233 WebAPI
 */
class HttpMessage implements \ArrayAccess, Message {
    protected $header;
    protected $body;
    public function __construct(array $header = [], string $body = "") {
        $this->header = $header;
        $this->body = $body;
    }
    public function getAll() {
        return $this->header;
    }
    public function getBody() {
        return $this->body;
    }
    public function setBody(string $body) {
        $this->body = $body;
    }
    public function offsetExists($offset) {
        return isset($this->header[$offset]);
    }
    public function offsetGet($offset) {
        return $this->header[$offset] ?? null;
    }
    public function offsetSet($offset, $value) {
        if(is_null($offset)) {
            $this->header[] = $value;
        } else {
            $this->header[$offset] = $value;
        }
    }
    public function offsetUnset($offset) {
        unset($this->header[$offset]);
    }
    /**
     * @deprecated
     * @see public function read
     * @throws \InvalidArgumentException
     */
    public static function decode(string $string) {
        $httpMessage = new self();
        if($httpMessage->read($string) === false) {
            return null;
        }
        return $httpMessage;
    }
    public function encode() {
        if(!isset($this->header[":status:"])) {
            return false;
        }
        $diff = array_diff_key($this->header, array_flip([":status:"]));
        $lines = array_map(function($key, $value) {
            return "$key: $value";
        }, array_keys($diff), array_values($diff));
        array_unshift($lines, $this->header[":status:"]);
        return implode("\r\n", $lines) . "\r\n\r\n$this->body";
    }
    /**
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function read(&$buffer) {
        $separator = strrpos($buffer, "\r\n\r\n");
        if($separator === false) {
            //throw new \InvalidArgumentException("Invalid HTTP message");
            return false;
        }
        $parts = explode("\r\n", substr($buffer, 0, $separator), 2);

        $status = explode(" ", $parts[0], 3);
        $header = [
            ":status:" => $parts[0]
        ];
        if($status[0] != "HTTP/1.0" && $status[0] != "HTTP/1.1") { // TODO: 请求
            throw new \InvalidArgumentException("Invalid HTTP header");
        }
        // Transfer-Encoding: chunked用不到
        $contentLength = null; // 不对
        if(isset($status[1])) {
            if(strlen($status[1]) < 3 || !filter_var($status[1], FILTER_VALIDATE_INT)) {
                throw new \InvalidArgumentException("Invalid status code: {$status[1]}");
            }
            if(in_array($status[1], [100, 101, 102, 103, 204, 304])) { // TODO
                $contentLength = 0;
            }
        }
        $headerLength = $separator + 4;
        if(isset($parts[1])) {
            foreach(explode("\r\n", $parts[1]) as $i => $item) {
                $parts = explode(":", $item, 2);
                if(count($parts) < 2) {
                    throw new \InvalidArgumentException("Invalid header: index $i");
                }
                if($parts[0] === "") {
                    throw new \InvalidArgumentException("Missing key: index $i");
                }
                $header[strtolower($parts[0])] = trim($parts[1]);
                if(strtolower($parts[0]) == "content-length") { // 遇到过404 NOTOK
                    if(!filter_var($parts[1], FILTER_VALIDATE_INT)) {
                        throw new \InvalidArgumentException("Invalid integer: {$parts[1]}");
                    }
                    $contentLength = $parts[1];
                }
            }
        }
        $receivedPacketLength = strlen($buffer);
        if($contentLength !== null) {
            $packetLength = $headerLength + $contentLength;
            if($packetLength > $receivedPacketLength) {
                return false;
            }
            $packet = substr($buffer, 0, $packetLength);
            $buffer = substr($buffer, $packetLength);
        } else {
            $packet = $buffer;
        }
        $this->header = $header;
        $this->body = self::substr($packet, $headerLength, $contentLength);
        return true;
    }
    /**
     * @param string $string
     * @param int $offset
     * @param int|null $length
     * @return string
     */
    public static function substr(string $string, int $offset, int $length = null) {
        if($length === null) {
            return substr($string, $offset);
        }
        return substr($string, $offset, $length);
    }
    public function __toString() {
        return $this->encode();
    }
}