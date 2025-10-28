<?php
namespace wusheng233\HuHoBot;
class HandshakeConfig {
    protected $serverid;
    protected $hashkey; // hashkey是验证qq群绑定，绑定后自动创建hashkey.txt
    protected $servername; // 显示在 /在线服务器
    protected $platformname; // 用来检查更新
    protected $platformversion;
    public function __construct(string $serverid, string $hashkey, string $servername, string $platformname, string $platformversion) {
        $this->serverid = $serverid;
        $this->hashkey = $hashkey;
        $this->servername = $servername;
        $this->platformname = $platformname;
        $this->platformversion = $platformversion;
    }
    public function getServerId() {
        return $this->serverid;
    }
    public function getHashKey() {
        return $this->hashkey;
    }
    public function getServerName() {
        return $this->servername;
    }
    public function getPlatformName() {
        return $this->platformname;
    }
    public function getPlatformVersion() {
        return $this->platformversion;
    }
}