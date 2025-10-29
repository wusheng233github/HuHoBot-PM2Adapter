<?php
namespace wusheng233\HuHoBot\network;
interface Message {
    public function read(&$buffer);
    /**
     * @return string|string[]|false
     */
    public function encode();
    public function __toString();
}