<?php

namespace X2nx\WebmanMcp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('make:mcp-resource', 'Create a new MCP resource file')]
class MakeResource extends Command
{
    protected static string $defaultName = 'make:mcp-resource';

    protected static string $defaultDescription = 'Create a new MCP resource file';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setAliases(['mcp:resource', 'make:resource']);
        
        $this->addArgument('class', InputArgument::REQUIRED, 'MCP resource class name');
        $this->addOption('name', 'r', InputOption::VALUE_REQUIRED, 'Resource name', 'example');
        $this->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Resource description', 'Example resource');
        $this->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Resource type (resource or template)', 'resource');
        $this->addOption('uri', 'u', InputOption::VALUE_REQUIRED, 'Resource URI', 'config://example');
        $this->addOption('mime', 'm', InputOption::VALUE_REQUIRED, 'MIME type', 'application/json');
        $this->addOption('return', 'rt', InputOption::VALUE_REQUIRED, 'Return type (array or text)', 'array');
        $this->addOption('params', 'p', InputOption::VALUE_REQUIRED, 'Template parameters (comma-separated, only for template type)', 'id');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force overwrite existing file');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        // 获取参数
        $class = $input->getArgument('class');
        $name = $input->getOption('name');
        $description = $input->getOption('description');
        $type = $input->getOption('type');
        $uri = $input->getOption('uri');
        $mime = $input->getOption('mime');
        $returnType = $input->getOption('return');
        $params = $input->getOption('params');
        $force = $input->getOption('force');
        
        // 验证类名
        if (!$this->isValidClassName($class)) {
            $io->error('类名无效。类名必须以大写字母开头，只能包含字母、数字和下划线。');
            return self::FAILURE;
        }
        
        // 验证资源名
        if (!$this->isValidResourceName($name)) {
            $io->error('资源名无效。资源名只能包含小写字母、数字和下划线，且不能以下划线开头。');
            return self::FAILURE;
        }
        
        // 验证资源类型
        if (!in_array($type, ['resource', 'template'])) {
            $io->error('资源类型无效。必须是 "resource" 或 "template"。');
            return self::FAILURE;
        }
        
        // 验证返回类型
        if (!in_array($returnType, ['array', 'text'])) {
            $io->error('返回类型无效。必须是 "array" 或 "text"。');
            return self::FAILURE;
        }
        
        // 验证URI格式
        if (!$this->isValidUri($uri)) {
            $io->error('URI格式无效。URI应该以协议开头（如：config://, file://, http://）。');
            return self::FAILURE;
        }
        
        // 检查目标目录是否存在
        $targetDir = app_path('mcp');
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                $io->error("无法创建目录: {$targetDir}");
                return self::FAILURE;
            }
            $io->info("已创建目录: {$targetDir}");
        }
        
        // 生成文件路径
        $filePath = $targetDir . '/' . $class . '.php';
        
        // 检查文件是否已存在
        if (file_exists($filePath) && !$force) {
            $io->error("文件已存在: {$filePath}");
            $io->note('使用 --force 或 -f 选项强制覆盖现有文件。');
            return self::FAILURE;
        }
        
        try {
            // 生成文件内容
            $stub = $this->getStub();
            $stub = str_replace('{{McpResourceClass}}', $class, $stub);
            $stub = str_replace('{{McpResourceDescription}}', $description, $stub);
            $stub = str_replace('{{McpResourceType}}', $type === 'template' ? 'ResourceTemplate' : 'Resource', $stub);
            $stub = str_replace('{{McpResourceUri}}', $this->getUriAttribute($type, $uri), $stub);
            $stub = str_replace('{{McpResourceMimeType}}', $mime, $stub);
            $stub = str_replace('{{McpResourceMethodName}}', $this->convertToMethodName($name), $stub);
            $stub = str_replace('{{McpResourceReturnType}}', $this->getReturnType($returnType), $stub);
            $stub = str_replace('{{McpResourceReturnValue}}', $this->getReturnValue($returnType), $stub);
            
            // Add template-specific replacements
            if ($type === 'template') {
                $stub = str_replace('{{McpResourceTemplateParams}}', $this->formatParams($params), $stub);
                $stub = str_replace('{{McpResourceTemplateMethodParams}}', $this->generateMethodParams($params), $stub);
            } else {
                // For non-template resources, remove parameter placeholders
                $stub = str_replace('{{McpResourceTemplateParams}}', '', $stub);
                $stub = str_replace('{{McpResourceTemplateMethodParams}}', '', $stub);
            }
            
            // 写入文件
            if (file_put_contents($filePath, $stub) === false) {
                $io->error("无法写入文件: {$filePath}");
                return self::FAILURE;
            }
            
            $io->success("MCP资源文件已创建: {$filePath}");
            $io->table(
                ['参数', '值'],
                [
                    ['类名', $class],
                    ['资源名', $name],
                    ['描述', $description],
                    ['类型', $type],
                    ['URI', $uri],
                    ['MIME类型', $mime],
                    ['返回类型', $returnType],
                    ['参数', $type === 'template' ? $params : 'N/A'],
                    ['方法名', $this->convertToMethodName($name)],
                    ['文件路径', $filePath]
                ]
            );
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error("创建文件时发生错误: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * 获取模板文件内容
     * 
     * @return string
     * @throws \Exception
     */
    public function getStub(): string
    {
        $stubPath = __DIR__ . '/../Stubs/Resource.stub';
        if (!file_exists($stubPath)) {
            throw new \Exception("模板文件不存在: {$stubPath}");
        }
        
        $content = file_get_contents($stubPath);
        if ($content === false) {
            throw new \Exception("无法读取模板文件: {$stubPath}");
        }
        
        return $content;
    }
    
    /**
     * 验证类名是否有效
     * 
     * @param string $className
     * @return bool
     */
    private function isValidClassName(string $className): bool
    {
        // 类名必须以大写字母开头，只能包含字母、数字和下划线
        return preg_match('/^[A-Z][a-zA-Z0-9_]*$/', $className) === 1;
    }
    
    /**
     * 验证资源名是否有效
     * 
     * @param string $resourceName
     * @return bool
     */
    private function isValidResourceName(string $resourceName): bool
    {
        // 资源名只能包含小写字母、数字和下划线，且不能以下划线开头
        return preg_match('/^[a-z][a-z0-9_]*$/', $resourceName) === 1;
    }
    
    /**
     * 验证URI格式是否有效
     * 
     * @param string $uri
     * @return bool
     */
    private function isValidUri(string $uri): bool
    {
        // URI应该以协议开头
        return preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//', $uri) === 1;
    }
    
    /**
     * 将资源名转换为方法名
     * 
     * @param string $resourceName
     * @return string
     */
    private function convertToMethodName(string $resourceName): string
    {
        // 将下划线分隔的字符串转换为驼峰命名
        $parts = explode('_', $resourceName);
        $methodName = 'get';
        
        foreach ($parts as $part) {
            $methodName .= ucfirst($part);
        }
        
        return $methodName;
    }
    
    /**
     * 获取URI属性字符串
     * 
     * @param string $type
     * @param string $uri
     * @return string
     */
    private function getUriAttribute(string $type, string $uri): string
    {
        if ($type === 'template') {
            return "uriTemplate: '{$uri}'";
        }
        return "uri: '{$uri}'";
    }
    
    /**
     * 获取返回类型
     * 
     * @param string $returnType
     * @return string
     */
    private function getReturnType(string $returnType): string
    {
        return $returnType === 'text' ? 'TextContent' : 'array';
    }
    
    /**
     * 获取返回值
     * 
     * @param string $returnType
     * @return string
     */
    private function getReturnValue(string $returnType): string
    {
        if ($returnType === 'text') {
            return "return new TextContent('示例文本内容');";
        }
        return "return ['status' => 'success', 'data' => []];";
    }
    
    /**
     * Format parameters for documentation
     * 
     * @param string $params
     * @return string
     */
    private function formatParams(string $params): string
    {
        $paramList = array_map('trim', explode(',', $params));
        return implode(', ', array_map(function($param) {
            return "string \${$param}";
        }, $paramList));
    }
    
    /**
     * Generate method parameters
     * 
     * @param string $params
     * @return string
     */
    private function generateMethodParams(string $params): string
    {
        $paramList = array_map('trim', explode(',', $params));
        return implode(', ', array_map(function($param) {
            return "string \${$param}";
        }, $paramList));
    }
}
