<?php

namespace X2nx\WebmanMcp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('make:mcp-prompt', 'Create a new MCP prompt file')]
class MakePrompts extends Command
{
    protected static string $defaultName = 'make:mcp-prompt';

    protected static string $defaultDescription = 'Create a new MCP prompt file';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setAliases(['mcp:prompt', 'make:prompt']);
        
        $this->addArgument('class', InputArgument::REQUIRED, 'MCP prompt class name');
        $this->addOption('name', 'p', InputOption::VALUE_REQUIRED, 'Prompt name', 'example');
        $this->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Prompt description', 'Example prompt');
        $this->addOption('content', 'c', InputOption::VALUE_REQUIRED, 'Prompt content', 'You are a senior expert. Please analyze the following content comprehensively:\n\n{input}');
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
        $content = $input->getOption('content');
        $force = $input->getOption('force');
        
        // 验证类名
        if (!$this->isValidClassName($class)) {
            $io->error('类名无效。类名必须以大写字母开头，只能包含字母、数字和下划线。');
            return self::FAILURE;
        }
        
        // 验证提示名
        if (!$this->isValidPromptName($name)) {
            $io->error('提示名无效。提示名只能包含小写字母、数字和下划线，且不能以下划线开头。');
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
            $stub = str_replace('{{McpPrompts}}', $class, $stub);
            $stub = str_replace('{{McpPromptName}}', $name, $stub);
            $stub = str_replace('{{McpPromptDescription}}', $description, $stub);
            $stub = str_replace('{{McpPromptMethodName}}', $this->convertToMethodName($name), $stub);
            $stub = str_replace('{{McpPromptContent}}', $content, $stub);
            
            // 写入文件
            if (file_put_contents($filePath, $stub) === false) {
                $io->error("无法写入文件: {$filePath}");
                return self::FAILURE;
            }
            
            $io->success("MCP提示文件已创建: {$filePath}");
            $io->table(
                ['参数', '值'],
                [
                    ['类名', $class],
                    ['提示名', $name],
                    ['描述', $description],
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
        $stubPath = __DIR__ . '/../Stubs/Prompt.stub';
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
     * 验证提示名是否有效
     * 
     * @param string $promptName
     * @return bool
     */
    private function isValidPromptName(string $promptName): bool
    {
        // 提示名只能包含小写字母、数字和下划线，且不能以下划线开头
        return preg_match('/^[a-z][a-z0-9_]*$/', $promptName) === 1;
    }
    
    /**
     * 将提示名转换为方法名
     * 
     * @param string $promptName
     * @return string
     */
    private function convertToMethodName(string $promptName): string
    {
        // 将下划线分隔的字符串转换为驼峰命名
        $parts = explode('_', $promptName);
        $methodName = '';
        
        foreach ($parts as $part) {
            $methodName .= ucfirst($part);
        }
        
        return 'get' . $methodName . 'Prompt';
    }
}
