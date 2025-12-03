# webman-mcp

<div align="center">

![webman-mcp](https://img.shields.io/badge/webman-mcp-blue?style=for-the-badge&logo=php)
![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)
![Version](https://img.shields.io/badge/Version-0.1.3-orange?style=for-the-badge)

**基于 MCP (Model Context Protocol) SDK 的 webman 插件，快速创建高性能 MCP 服务器**

[快速开始](#-快速开始) • [创建组件](#️-创建组件) • [消息处理](#-消息处理) • [配置说明](#️-配置说明) • [常见问题](#-常见问题)

</div>

## ✨ 核心特性

- 🚀 **快速启动** - 支持多种传输协议，开箱即用
- 🛠️ **命令行工具** - 快速生成 MCP 组件，提升开发效率
- 📡 **多协议支持** - 支持 stdio、HTTP、SSE 传输模式
- 💬 **消息处理** - 提供便捷的消息传输处理和全局辅助函数
- 🔧 **组件管理** - 内置工具、提示和资源管理系统
- ⚡ **高性能** - 基于 webman 框架，支持高并发处理
- 🎯 **易于扩展** - 灵活的配置系统，支持自定义扩展

## 📦 安装

### 环境要求

- PHP >= 8.1
- Composer
- webman 框架

### 安装步骤

```bash
# 安装插件
composer require x2nx/webman-mcp
```

## 🚀 快速开始

### 1. 启动服务

```bash
# 开发模式 - stdio
php mcp-stdio.php

# 生产模式 - HTTP/SSE
php webman start
```

### 2. 测试连接

#### HTTP 流式传输测试

```bash
# 测试 MCP 连接
curl -vvv -X POST http://127.0.0.1:7190/mcp \
  -H "Content-Type: application/json" \
  -H "Accept: application/json,text/event-stream" \
  -d '{
    "jsonrpc": "2.0",
    "id": "test-001",
    "method": "initialize",
    "params": {
      "protocolVersion": "2025-06-18",
      "capabilities": {
        "elicitation": {}
      },
      "clientInfo": {
        "name": "example-client",
        "version": "1.0.0"
      }
    }
  }'
```

#### SSE 模式测试

```bash
# 1. 建立 SSE 连接（获取 sessionId）
curl -N -H "Accept: text/event-stream" http://127.0.0.1:7190/sse

# 2. 发送消息（使用上一步获取的 sessionId）
curl -X POST "http://127.0.0.1:7190/message?sessionId=YOUR_SESSION_ID" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":"1","method":"tools/list"}'
```

## 🛠️ 创建组件

webman-mcp 提供了强大的命令行工具，帮助您快速创建各种 MCP 组件。

### 创建工具 (Tools)

```bash
# 创建用户管理工具
php webman make:mcp-tool UserManager \
  --name=get_user_info \
  --description="获取用户详细信息"

# 创建文件操作工具
php webman make:mcp-tool FileManager \
  --name=read_file \
  --description="读取文件内容"
```

### 创建提示 (Prompts)

```bash
# 创建代码审查提示
php webman make:mcp-prompt CodeReview \
  --name=code_review \
  --description="代码质量审查和优化建议"

# 创建文档生成提示
php webman make:mcp-prompt DocGenerator \
  --name=generate_docs \
  --description="自动生成API文档"
```

### 创建资源 (Resources)

```bash
# 创建配置资源
php webman make:mcp-resource ConfigResource \
  --name=get_config \
  --description="获取系统配置信息"

# 创建数据库资源
php webman make:mcp-resource DatabaseResource \
  --name=query_db \
  --description="执行数据库查询"
```

### 创建资源模板 (Resource Templates)

```bash
# 创建配置模板
php webman make:mcp-template ConfigResourceTemplate \
  --name=get_config \
  --description="获取配置信息模板"

# 创建API模板
php webman make:mcp-template ApiResourceTemplate \
  --name=api_docs \
  --description="API文档模板"
```

> 💡 **提示**: 创建组件后，重启服务即可自动发现新组件

## 💬 消息处理

webman-mcp 提供了灵活的消息处理机制，支持在代码中直接处理 MCP 消息。

### 全局辅助函数

插件提供了两个全局辅助函数，方便在项目任何地方使用：

#### `mcp_server_handle_message()` - 处理消息

```php
<?php
// 处理 MCP 消息
$message = '{"jsonrpc":"2.0","id":"1","method":"tools/list"}';
$sessionId = '550e8400-e29b-41d4-a716-446655440000';

$result = mcp_server_handle_message($message, $sessionId);

// 返回格式化的响应消息数组
if ($result) {
    foreach ($result as $response) {
        echo "Session ID: " . $response['session_id'] . "\n";
        echo "Message: " . $response['mcp_message'] . "\n";
    }
}
```

**函数签名：**
```php
function mcp_server_handle_message(string $message = '', string $sessionId = ''): mixed
```

**参数说明：**
- `$message`: MCP 消息 JSON 字符串（必需）
- `$sessionId`: 会话 ID（可选，用于会话管理）

**返回值：**
- 成功：返回响应消息数组，格式为 `[['session_id' => '...', 'mcp_message' => '...'], ...]`
- 失败：返回 `false`

#### `mcp_server()` - 获取服务器实例

```php
<?php
// 获取 MCP 服务器实例
$server = mcp_server();

// 或者设置自定义服务器实例
$customServer = McpServer::builder()->build();
$server = mcp_server($customServer);
```

**函数签名：**
```php
function mcp_server(?McpServer $server = null): McpServer
```

### 使用 Server 类

如果需要更多控制，可以直接使用 `Server` 类：

```php
<?php
use X2nx\WebmanMcp\Process\Server;

// 使用单例实例
$server = Server::instance();
$result = $server->handleMessage($message, $sessionId);

// 或创建新实例
$server = new Server();
$result = $server->handleMessage($message, $sessionId);
```

### 使用场景

1. **API 接口处理** - 在 webman 路由中处理 MCP 消息
   ```php
   Route::post('/api/mcp', function (Request $request) {
       $message = $request->post('message');
       $sessionId = $request->post('session_id', '');
       return json(mcp_server_handle_message($message, $sessionId));
   });
   ```

2. **队列任务** - 异步处理 MCP 消息
   ```php
   class ProcessMcpMessageJob {
       public function handle($message, $sessionId) {
           return mcp_server_handle_message($message, $sessionId);
       }
   }
   ```

3. **命令行工具** - 在 CLI 中处理 MCP 消息
   ```php
   php artisan mcp:process "{\"jsonrpc\":\"2.0\",\"id\":\"1\",\"method\":\"tools/list\"}"
   ```

4. **测试用例** - 单元测试和集成测试
   ```php
   public function testMcpMessage() {
       $result = mcp_server_handle_message($testMessage);
       $this->assertIsArray($result);
   }
   ```

## ⚙️ 配置说明

### 配置文件位置

编辑 `config/plugin/x2nx/webman-mcp/mcp.php` 文件：

```php
<?php

use Mcp\Schema\Enum\ProtocolVersion;

return [
    // 服务器配置
    'server' => [
        'name' => 'MCP Server',
        'version' => '1.0.0',
        'description' => 'MCP Server with Multi-Transport Support for Webman',
        
        // 协议版本
        'protocol_version' => ProtocolVersion::V2025_06_18,
        
        // 分页配置
        'pagination' => 50,
        
        // 服务器指令描述
        'instructions' => '',
        
        // 服务器能力配置
        'capabilities' => [],
        
        // 组件发现配置
        'discover' => [
            'base_path' => base_path(),
            'scan_dirs' => [
                'app/mcp',  // 扫描 MCP 组件目录
            ],
            'exclude_dirs' => [
                'vendor',   // 排除第三方包
                'runtime',  // 排除运行时文件
                'database', // 排除数据库文件
                'docker',   // 排除 Docker 文件
                'public',   // 排除公共文件
                'config',   // 排除配置文件
                'support',  // 排除支持文件
            ],
            'cache' => [
                'enable' => false,  // 是否启用发现缓存
                'ttl' => 3600,     // 缓存过期时间（秒）
                'store' => '',     // 缓存存储名称，空则使用默认
            ],
        ],
        
        // 传输协议配置
        'transport' => [
            'sse' => [
                'enable' => true,  // 启用 SSE 传输
                'route' => [
                    '/sse',     // SSE 连接端点
                    '/message'  // SSE 消息发送端点
                ],
            ],
            'stream' => [
                'enable' => true,  // 启用流式传输
                'route' => [
                    '/mcp',     // 流式传输端点
                ],
            ],
        ],
        
        // 会话缓存配置
        'session' => [
            'ttl' => 3600,     // 会话过期时间（秒）
            'store' => '',     // 会话存储名称，空则使用默认
        ]
    ]
];
```

### 配置项说明

| 配置项 | 说明 | 类型 | 默认值 |
|--------|------|------|--------|
| `server.name` | 服务器名称 | string | `MCP Server` |
| `server.version` | 服务器版本 | string | `1.0.0` |
| `server.description` | 服务器描述 | string | - |
| `server.protocol_version` | MCP 协议版本 | ProtocolVersion | `V2025_06_18` |
| `server.pagination` | 分页大小 | int | `50` |
| `server.instructions` | 服务器指令描述 | string | `''` |
| `server.capabilities` | 服务器能力配置 | array | `[]` |
| `discover.scan_dirs` | 组件扫描目录 | array | `['app/mcp']` |
| `discover.exclude_dirs` | 排除扫描目录 | array | 见配置示例 |
| `discover.cache.enable` | 启用发现缓存 | bool | `false` |
| `discover.cache.ttl` | 缓存过期时间（秒） | int | `3600` |
| `discover.cache.store` | 缓存存储名称 | string | `''` |
| `transport.sse.enable` | 启用 SSE 传输 | bool | `true` |
| `transport.sse.route` | SSE 路由端点 | array | `['/sse', '/message']` |
| `transport.stream.enable` | 启用流式传输 | bool | `true` |
| `transport.stream.route` | 流式传输路由端点 | array | `['/mcp']` |
| `session.ttl` | 会话过期时间（秒） | int | `3600` |
| `session.store` | 会话存储名称 | string | `''` |

## 🚀 部署

### 生产环境部署

```bash
# 启动生产服务（后台运行）
php webman start -d

# 查看服务状态
php webman status

# 停止服务
php webman stop

# 重启服务
php webman restart

# 查看日志
tail -f runtime/logs/webman.log
```

### 性能优化建议

1. **启用组件发现缓存** - 减少文件扫描开销
   ```php
   'discover' => [
       'cache' => [
           'enable' => true,
           'ttl' => 3600,
       ],
   ],
   ```

2. **使用 Redis 缓存** - 提升会话和发现缓存性能
   ```php
   'session' => [
       'store' => 'redis',  // 使用 Redis 存储
   ],
   ```

3. **调整 Worker 进程数** - 根据服务器配置调整
   ```php
   // config/process.php
   'mcp' => [
       'handler' => ...,
       'count' => 4,  // 根据 CPU 核心数调整
   ],
   ```

## 📋 常见问题

### Q: 插件安装后没有发现任何组件？

**A:** 这是正常现象。插件安装后 `app/mcp` 目录为空，需要使用命令行工具创建组件：

```bash
# 创建示例工具
php webman make:mcp-tool ExampleTool --name=example --description="示例工具"

# 重启服务
php webman restart
```

### Q: 如何自定义传输协议端口？

**A:** 修改 webman 配置文件 `config/server.php`：

```php
return [
    'http' => [
        'listen' => 'http://0.0.0.0:7190',  // 修改端口
        'context' => [],
    ],
];
```

### Q: 如何启用发现缓存？

**A:** 在配置文件中设置：

```php
'discover' => [
    'cache' => [
        'enable' => true,  // 启用缓存
        'ttl' => 3600,    // 缓存时间（秒）
    ],
],
```

### Q: 如何使用消息处理功能？

**A:** 可以使用全局辅助函数或 Server 类：

```php
// 方式 1: 使用全局辅助函数（推荐）
$result = mcp_server_handle_message($message, $sessionId);

// 方式 2: 使用 Server 类
$server = Server::instance();
$result = $server->handleMessage($message, $sessionId);
```

### Q: 消息处理的响应格式是什么？

**A:** 返回一个数组，每个元素包含 `session_id` 和 `mcp_message`：

```php
[
    [
        'session_id' => '550e8400-e29b-41d4-a716-446655440000',
        'mcp_message' => '{"jsonrpc":"2.0","id":"1","result":{...}}'
    ],
    // ... 更多响应消息
]
```

### Q: 如何处理 SSE 会话管理？

**A:** SSE 模式会自动管理会话，您只需要：

1. 通过 GET `/sse` 建立连接，获取 `sessionId`
2. 使用该 `sessionId` 通过 POST `/message?sessionId=xxx` 发送消息
3. 服务器会自动维护会话状态

### Q: 如何查看日志？

**A:** 日志文件位于 `runtime/logs/` 目录：

```bash
# 查看 MCP 日志
tail -f runtime/logs/plugin.x2nx.webman-mcp.mcp.log

# 查看所有日志
tail -f runtime/logs/webman.log
```

## 🤝 贡献

我们欢迎所有形式的贡献！

1. Fork 本仓库
2. 创建特性分支 (`git checkout -b feature/AmazingFeature`)
3. 提交更改 (`git commit -m 'Add some AmazingFeature'`)
4. 推送到分支 (`git push origin feature/AmazingFeature`)
5. 开启 Pull Request

## 📄 许可证

本项目采用 [MIT 许可证](LICENSE) - 查看 [LICENSE](LICENSE) 文件了解详情。

## 🔗 相关链接

- 📖 [MCP 官方文档](https://modelcontextprotocol.io/)
- 🚀 [webman 框架](https://www.workerman.net/doc/webman/)
- 💻 [GitHub 仓库](https://github.com/x2nx/webman-mcp)
- 🐛 [问题反馈](https://github.com/x2nx/webman-mcp/issues)
- 💬 [讨论区](https://github.com/x2nx/webman-mcp/discussions)

---

<div align="center">

**如果这个项目对您有帮助，请给我们一个 ⭐️**

Made with ❤️ by [x2nx](https://github.com/x2nx)

</div>
