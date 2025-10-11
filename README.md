# webman-mcp

基于 MCP (Model Context Protocol) SDK 的 webman 插件，快速创建 MCP 服务器。

## ✨ 特性

- 🚀 快速启动，支持多种传输协议
- 🛠️ 命令行工具快速生成组件  
- 📡 支持 stdio、HTTP、SSE 传输
- 🔧 内置工具、提示和资源管理

## 📦 安装

```bash
# 安装
composer require x2nx/webman-mcp
```

## 🚀 快速开始

```bash
# 启动stdio模式
php mcp-stdio.php

# 生产模式（HTTP）
php webman start

# 测试连接
curl -vvv -X POST http://127.0.0.1:7190/mcp \
  -H "Content-Type: application/json" \
  -H "Accept: application/json,text/event-stream" \
  -d '{"jsonrpc":"2.0","id":"","method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{"elicitation":{}},"clientInfo":{"name":"example-client","version":"1.0.0"}}}'
```

## 🛠️ 创建组件

```bash
# 创建工具
php webman make:mcp-tool UserManager --name=get_user_info --description="获取用户信息"

# 创建提示
php webman make:mcp-prompt CodeReview --name=code_review --description="代码审查提示"

# 创建资源
php webman make:mcp-resource ConfigResource --name=get_config --description="获取配置信息"

# 创建资源模板
php webman make:mcp-template ConfigResourceTemplate --name=get_config --description="获取配置信息"
```
## ⚙️ 配置

编辑 `config/plugin/x2nx/webman-mcp/mcp.php`：

```php
<?php
return [
    'server' => [
        'name' => 'My MCP Server',
        'version' => '1.0.0',
        'description' => '自定义 MCP 服务器',
        'discover' => [
            'base_path' => base_path(),
            'scan_dirs' => ['app/mcp'],
            'exclude_dirs' => ['vendor', 'runtime'],
        ],
    ],
];
```

## 🚀 部署

```bash
# 生产环境
php webman start -d
```

## 📄 许可证

本项目采用 [MIT 许可证](LICENSE)。

## 🔗 相关链接

- [MCP 官方文档](https://modelcontextprotocol.io/)
- [webman 框架](https://www.workerman.net/doc/webman/)
- [GitHub 仓库](https://github.com/x2nx/webman-mcp)
