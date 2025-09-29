<?php

namespace app\mcp;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Schema\Content\TextContent;

class Prompts
{
    /**
     * 示例提示
     */
    #[McpPrompt(name: 'example')]
    public function getExamplePrompt(): TextContent
    {
        $prompt = <<<'PROMPT'
你是一个资深的示例专家。请对以下示例进行全面的审查，包括但不限于：

代码：
```php
{code}
```
PROMPT;

        return new TextContent($prompt);
    }
}
