<?php

namespace X2nx\WebmanMcp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('make:mcp-template', 'Create a new MCP resource template file')]
class MakeResourceTemplate extends Command
{
    protected static string $defaultName = 'make:mcp-template';

    protected static string $defaultDescription = 'Create a new MCP resource template file';
    

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setAliases(['mcp:template', 'make:template']);
        
        $this->addArgument('class', InputArgument::REQUIRED, 'MCP resource template class name');
        $this->addOption('name', 'nt', InputOption::VALUE_REQUIRED, 'Resource template name', 'example');
        $this->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Resource template description', 'Example resource template');
        $this->addOption('uri', 'u', InputOption::VALUE_REQUIRED, 'Resource template URI', 'template://{id}');
        $this->addOption('mime', 'm', InputOption::VALUE_REQUIRED, 'MIME type', 'application/json');
        $this->addOption('return', 'r', InputOption::VALUE_REQUIRED, 'Return type (array or text)', 'array');
        $this->addOption('params', 'p', InputOption::VALUE_REQUIRED, 'Template parameters (comma-separated)', 'id');
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
        $uri = $input->getOption('uri');
        $mime = $input->getOption('mime');
        $returnType = $input->getOption('return');
        $params = $input->getOption('params');
        $force = $input->getOption('force');
        
        // 验证类名
        if (!$this->isValidClassName($class)) {
            $io->error('Class name invalid. Class name must start with uppercase letter and contain only letters, numbers and underscores.');
            return self::FAILURE;
        }
        
        // 验证资源模板名
        if (!$this->isValidResourceName($name)) {
            $io->error('Resource template name invalid. Resource template name must contain only lowercase letters, numbers and underscores, and cannot start with underscore.');
            return self::FAILURE;
        }
        
        // 验证返回类型
        if (!in_array($returnType, ['array', 'text'])) {
            $io->error('Return type invalid. Must be "array" or "text".');
            return self::FAILURE;
        }
        
        // 验证URI格式
        if (!$this->isValidUri($uri)) {
            $io->error('URI format invalid. URI should start with protocol (e.g., template://, user://, api://).');
            return self::FAILURE;
        }
        
        // 检查目标目录是否存在
        $targetDir = app_path('mcp');
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                $io->error("Cannot create directory: {$targetDir}");
                return self::FAILURE;
            }
            $io->info("Created directory: {$targetDir}");
        }
        
        // 生成文件路径
        $filePath = $targetDir . '/' . $class . '.php';
        
        // 检查文件是否已存在
        if (file_exists($filePath) && !$force) {
            $io->error("File already exists: {$filePath}");
            $io->note('Use --force or -f option to force overwrite existing file.');
            return self::FAILURE;
        }
        
        try {
            // 生成文件内容
            $stub = $this->getStub();
            $stub = str_replace('{{McpResourceTemplateClass}}', $class, $stub);
            $stub = str_replace('{{McpResourceTemplateDescription}}', $description, $stub);
            $stub = str_replace('{{McpResourceTemplateUri}}', $uri, $stub);
            $stub = str_replace('{{McpResourceTemplateMimeType}}', $mime, $stub);
            $stub = str_replace('{{McpResourceTemplateMethodName}}', $this->convertToMethodName($name), $stub);
            $stub = str_replace('{{McpResourceTemplateReturnType}}', $this->getReturnType($returnType), $stub);
            $stub = str_replace('{{McpResourceTemplateParams}}', $this->formatParams($params), $stub);
            $stub = str_replace('{{McpResourceTemplateMethodParams}}', $this->generateMethodParams($params), $stub);
            $stub = str_replace('{{McpResourceTemplateReturnValue}}', $this->getReturnValue($returnType), $stub);
            
            // 写入文件
            if (file_put_contents($filePath, $stub) === false) {
                $io->error("Cannot write file: {$filePath}");
                return self::FAILURE;
            }
            
            $io->success("MCP resource template file created: {$filePath}");
            $io->table(
                ['Parameter', 'Value'],
                [
                    ['Class name', $class],
                    ['Resource template name', $name],
                    ['Description', $description],
                    ['URI template', $uri],
                    ['MIME type', $mime],
                    ['Return type', $returnType],
                    ['Parameters', $params],
                    ['Method name', $this->convertToMethodName($name)],
                    ['File path', $filePath]
                ]
            );
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error("Error creating file: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Get template file content
     * 
     * @return string
     * @throws \Exception
     */
    public function getStub(): string
    {
        $stubPath = __DIR__ . '/../Stubs/ResourceTemplate.stub';
        if (!file_exists($stubPath)) {
            throw new \Exception("Template file does not exist: {$stubPath}");
        }
        
        $content = file_get_contents($stubPath);
        if ($content === false) {
            throw new \Exception("Cannot read template file: {$stubPath}");
        }
        
        return $content;
    }
    
    /**
     * Validate class name
     * 
     * @param string $className
     * @return bool
     */
    private function isValidClassName(string $className): bool
    {
        // Class name must start with uppercase letter and contain only letters, numbers and underscores
        return preg_match('/^[A-Z][a-zA-Z0-9_]*$/', $className) === 1;
    }
    
    /**
     * Validate resource template name
     * 
     * @param string $resourceName
     * @return bool
     */
    private function isValidResourceName(string $resourceName): bool
    {
        // Resource template name must contain only lowercase letters, numbers and underscores, and cannot start with underscore
        return preg_match('/^[a-z][a-z0-9_]*$/', $resourceName) === 1;
    }
    
    /**
     * Validate URI format
     * 
     * @param string $uri
     * @return bool
     */
    private function isValidUri(string $uri): bool
    {
        // URI should start with protocol
        return preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//', $uri) === 1;
    }
    
    /**
     * Convert resource template name to method name
     * 
     * @param string $resourceName
     * @return string
     */
    private function convertToMethodName(string $resourceName): string
    {
        // Convert underscore-separated string to camelCase
        $parts = explode('_', $resourceName);
        $methodName = 'get';
        
        foreach ($parts as $part) {
            $methodName .= ucfirst($part);
        }
        
        return $methodName;
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
    
    /**
     * Get return type
     * 
     * @param string $returnType
     * @return string
     */
    private function getReturnType(string $returnType): string
    {
        return $returnType === 'text' ? 'TextContent' : 'array';
    }
    
    /**
     * Get return value
     * 
     * @param string $returnType
     * @return string
     */
    private function getReturnValue(string $returnType): string
    {
        if ($returnType === 'text') {
            return "return new TextContent('Example template content');";
        }
        return "return ['status' => 'success', 'data' => []];";
    }
}
