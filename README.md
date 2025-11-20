# HuHoBot-PM2Adapter

[![前往GitHub Actions页面](https://img.shields.io/static/v1?label=%E4%B8%8B%E8%BD%BD&message=%E6%9C%80%E6%96%B0%E7%89%88%E6%9C%AC&color=blue&logo=github&style=for-the-badge)](https://github.com/wusheng233github/HuHoBot-PM2Adapter/actions/workflows/main.yml)

前往 **GitHub Actions** 页面，进入 **最新的成功构建**，在 **Artifacts** 下载打包好的Phar

## 运行环境

### 测试过的服务器核心
- PocketMine-MP (API 2.0.0)
- Genisys (GeniAPI 1.7.3) - PocketMine-MP 的分支
- Genisys (API 2.0.1) - 向下兼容
- Scaxe (version 2.9b3) - Genisys 的分支
- InCore - Scaxe 的分支

### 测试过的PHP版本
- 7.0.X
- 8.0.X

### PHP扩展
- OpenSSL - 用于WebSocket Secure连接

## 配置说明
```yaml
# 网络配置
network:
  # HuHoBot服务器地址（ws://或wss://）
  botserver: ""
  # 心跳包发送间隔（秒）
  heart-period: 10
  # 网络数据读取间隔（游戏刻）
  read-period: 10

# 服务器身份标识
id:
  # 服务器ID（32位十六进制字符串）
  serverid: ""
  # 绑定密钥，绑定群聊后由bot服务器下发
  hashkey: ""
  # 在线服务器列表显示的名称
  name: ""

# 群聊配置
group-chat:
  # 消息过滤
  filter:
    # 是否启用过滤器
    enable: true
    # 是否过滤无效Unicode字符
    invalid-chars: true
    # 正则表达式过滤模式
    pattern: /(?!)/
    # 替换文本
    replacement: ""
  # 命令执行者的名称
  command-sender: "QQ Console"
  # 命令执行结果长度限制（字符数）
  word-limit: 7000
  # 群消息显示格式
  format: "[群内消息] <%s> %s"

# 游戏聊天配置
game-chat:
  # 是否转发游戏内聊天到QQ群
  post: true
  # 聊天转发时间限制（秒）
  time-limit: 300
  # 是否将命令执行结果转换为彩色图片
  color-image-reply: 0

# 平台信息
platform:
  # 平台名称，用来检查官方适配器版本需要更新
  name: ""
  # 平台版本
  # 设置为dev会提示去对应适配器的仓库开Issues
  version: "dev"

# 服务器信息显示
motd:
  # 查询玩家列表是否指定图片
  post-image: true
  # 图片服务的URL
  image: "https://picsum.photos/500/100"
  # 如果不指定图片将使用默认图片服务展示服务器的MOTD
  # 服务器类型（bedrock/java）
  type: "bedrock"
  # 服务器地址
  address: "127.0.0.1:19132"

# 白名单配置
whitelist:
  # 每页显示的白名单项目数
  items-per-page: 10

# 是否在玩家列表显示玩家头顶名称
show-player-nametag: true
# 配置版本，别改
version: 1
```

## 命令说明
### 主命令：`/huhobot`
| 命令                                                          | 权限                 | 描述                          |
|---------------------------------------------------------------|----------------------|-------------------------------|
| `/huhobot help`                                               | `huhobot`            | 显示帮助信息                  |
| `/huhobot bind <验证码>`                                      | `huhobot.bind`       | 使用验证码绑定QQ群            |
| `/huhobot disconnect`或`/huhobot q`                           | `huhobot.disconnect` | 断开与bot服务器的连接         |
| `/huhobot reconnect`或`/huhobot connect`或`/huhobot c [地址]` | `huhobot.connect`    | 重新连接bot服务器，可指定地址 |
| `/huhobot reload`                                             | `huhobot`            | 重新启动整个插件              |
| `/huhobot rtt`                                                | `huhobot`            | 查询网络连接往返时间          |

## 开发扩展插件
可以通过监听`DataPacketReceiveEvent`事件拦截和处理来自bot服务器的数据包
```php
<?php
use wusheng233\HuHoBot\event\DataPacketReceiveEvent;
use pocketmine\event\Listener;

class Main implements Listener {
    public function onDataPacketReceive(DataPacketReceiveEvent $event) {
        $packet = $event->getPacket();
        $packetType = $packet["header"]["type"];

        // 获取插件实例
        $plugin = $this->getServer()->getPluginManager()->getPlugin("HuHoBot");


        if ($packetType === "run") {
            // 取消执行默认处理
            $event->setCancelled(true);
            // 做些事情...
            $response = "执行成功";
            // 返回执行结果
            // response: 消息，包ID，是否转换为图片（默认false），是否成功（默认true）
            $plugin->response($response, $packet["header"]["id"], true);
        }
    }
}
```
### 数据包结构
见 https://huhobot.txssb.cn/Protocol/
```json
{
    "header": {
        "type": "", // 消息类型
        "id": "" // 包id，回调包应该用相同的id
    },
    "body": {
        // 看官方文档
    }
}
```