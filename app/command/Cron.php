<?php
declare (strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use Workerman\Worker;


class Cron extends Command
{
    protected function configure()
    {
        $this->setName('workerman')
            ->addArgument('action', Argument::OPTIONAL, "start|stop|reload|status", 'start')
            ->addOption('daemon', 'd', Option::VALUE_NONE, 'Run in daemon mode')
            ->setDescription('Start Workerman server');
    }

    protected function execute(Input $input, Output $output)
    {
        // 获取命令行参数
        $action = $input->getArgument('action');
        $daemon = $input->getOption('daemon');

        $config = Config::get('cron');
        if (empty($config)) {
            $output->writeln('Workerman config not found');
            return;
        }
        $class = null; // 类对象
        // 1. 如果有类对象，直接调用
        // 2. 如果没有类对象，则根据配置文件创建
        if (isset($config['class'])) {
            // 这个class还需要判断是否是继承了worker的类
            if (!is_subclass_of($config['class'], Worker::class)) {
                $output->writeln('Workerman config class not found');
                return;
            }
            $class = $config['class'];
        }
        if (isset($config['host']) && isset($config['port']) && isset($config['protocol'])) {
            $socket_name = $config['protocol'] . '://' . $config['host'] . ':' . $config['port'];
            $worker = $class ? new $class($socket_name) : new Worker($socket_name);
        } else {
            $worker = $class ? new $class('') : new Worker();
        }

        // 启动 4 个进程
        $worker->count = $config['count'] ?? 2;
        // 设置进程的名称
        $worker->name = $config['name'] ?? 'think-worker';

        // 判断是否使用守护进程模式
        if ($daemon) {
            Worker::$daemonize = true;
        }

        // 根据命令行参数执行相应操作
        global $argv;
        $argv[0] = 'think';  // 这里模拟命令行输入
        $argv[1] = $action;

        Worker::runAll();
    }
}