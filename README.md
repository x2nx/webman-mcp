# webman-mcp

<div align="center">

![webman-mcp](https://img.shields.io/badge/webman-mcp-blue?style=for-the-badge&logo=php)
![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)
![Version](https://img.shields.io/badge/Version-1.0.0-orange?style=for-the-badge)

**基于 MCP (Model Context Protocol) SDK 的 webman 插件，快速创建高性能 MCP 服务器**

[快速开始](#-快速开始) • [文档](#-配置) • [示例](#-创建组件) • [许可证](#-许可证)

</div>

## ✨ 核心特性

- 🚀 **快速启动** - 支持多种传输协议，开箱即用
- 🛠️ **命令行工具** - 快速生成 MCP 组件，提升开发效率
- 📡 **多协议支持** - 支持 stdio、HTTP、SSE 传输模式
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

### 3. SSE 模式测试

```bash
# 建立 SSE 连接
curl -N -H "Accept: text/event-stream" http://127.0.0.1:7190/sse

# 发送消息
curl -X POST http://127.0.0.1:7190/message?sessionId=YOUR_SESSION_ID \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":"1","method":"tools/list"}'
```

## 🛠️ 创建组件

webman-mcp 提供了强大的命令行工具，帮助您快速创建各种 MCP 组件：

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
## ⚙️ 配置

### 配置文件位置

编辑 `config/plugin/x2nx/webman-mcp/mcp.php` 文件：

```php
<?php
return [
    // 服务器配置
    'server' => [
        'name' => 'MCP Server',
        'version' => '1.0.0',
        'description' => 'MCP Server with Multi-Transport Support for Webman',
        
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

### 配置说明

| 配置项 | 说明 | 默认值 |
|--------|------|--------|
| `server.name` | 服务器名称 | MCP Server |
| `server.version` | 服务器版本 | 1.0.0 |
| `discover.scan_dirs` | 扫描目录 | `['app/mcp']` |
| `discover.cache.enable` | 启用发现缓存 | `false` |
| `transport.sse.enable` | 启用 SSE 传输 | `true` |
| `transport.stream.enable` | 启用流式传输 | `true` |
| `session.ttl` | 会话过期时间 | `3600` |

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
        'ttl' => 3600,    // 缓存时间
    ],
],
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
