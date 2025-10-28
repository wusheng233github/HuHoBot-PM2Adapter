<?php
namespace wusheng233\HuHoBot;

use pocketmine\command\ConsoleCommandSender;
use pocketmine\event\TextContainer;

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