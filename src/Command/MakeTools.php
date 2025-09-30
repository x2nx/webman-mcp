<?php

namespace X2nx\WebmanMcp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('make:mcp-tool', 'Create a new MCP tool file')]
class MakeTools extends Command
{
    protected static string $defaultName = 'make:mcp-tool';

    protected static string $defaultDescription = 'Create a new MCP tool file';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setAliases(['mcp:tool', 'make:tool']);
        
        $this->addArgument('class', InputArgument::REQUIRED, 'MCP tool class name');
        $this->addOption('name', 't', InputOption::VALUE_REQUIRED, 'Tool method name', 'get_info');
        $this->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Tool description', 'Get information');
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
        $force = $input->getOption('force');
        
        // 验证类名
        if (!$this->isValidClassName($class)) {
            $io->error('类名无效。类名必须以大写字母开头，只能包含字母、数字和下划线。');
            return self::FAILURE;
        }
        
        // 验证工具名
        if (!$this->isValidToolName($name)) {
            $io->error('工具名无效。工具名只能包含小写字母、数字和下划线，且不能以下划线开头。');
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
            $stub = str_replace('{{McpClass}}', $class, $stub);
            $stub = str_replace('{{McpToolName}}', $name, $stub);
            $stub = str_replace('{{McpToolDescription}}', $description, $stub);
            
            // 写入文件
            if (file_put_contents($filePath, $stub) === false) {
                $io->error("无法写入文件: {$filePath}");
                return self::FAILURE;
            }
            
            $io->success("MCP工具文件已创建: {$filePath}");
            $io->table(
                ['参数', '值'],
                [
                    ['类名', $class],
                    ['工具名', $name],
                    ['描述', $description],
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
        $stubPath = __DIR__ . '/../Stubs/Tool.stub';
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
     * 验证工具名是否有效
     * 
     * @param string $toolName
     * @return bool
     */
    private function isValidToolName(string $toolName): bool
    {
        // 工具名只能包含小写字母、数字和下划线，且不能以下划线开头
        return preg_match('/^[a-z][a-z0-9_]*$/', $toolName) === 1;
    }
}
