<?php

namespace app\service;

use Workerman\Timer;
use \Workerman\Worker;
use think\Config;

class Cron extends Worker
{
    public $logFilePath;

    public function __construct($socket_name = '', array $context_option = array())
    {
        parent::__construct($socket_name, $context_option);
        // 定义 log 文件路径
        $logFilePath = root_path() . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'cron.log';
        $this->logFilePath = $logFilePath;
        $this->onWorkerStart = array($this, 'onWorkerStart');
    }

    public function onWorkerStart(Worker $worker)
    {
        $worker->timers = [];
        // 读取accounts.json
        $accounts = json_decode(file_get_contents(root_path() . DIRECTORY_SEPARATOR . 'accounts.json'), true);
        foreach ($accounts as $key => $account) {
            /**
             * "host": "xxx",
             * "port": 6379,
             * "username": "xxxx",
             * "password" : "xxxx",
             * "command": "",
             * "exec_timer": 2
             */
            // 我需要判断所有字段是否都有
            if (empty($account['username'] && $account['password'] && $account['host'])) {
                break;
            }
            if (empty($account['exec_timer'])) {
                break;
            }
            $worker->timers[$key] = Timer::add($account['exec_timer'], function () use ($account, $worker, $key) {
                $connection = ssh2_connect($account['host'], $account['port'] ?? 22);
                if (!$connection) {
                    $this->writeLog("连接失败");
                    Timer::del($worker->timers[$key]);
                    return;
                }
                if (!ssh2_auth_password($connection, $account['username'], $account['password'])) {
                    $this->writeLog("账号密码错误");
                    Timer::del($worker->timers[$key]);
                    return;
                }

                $this->writeLog("定时任务运行中...");
                if ($account['command'] != "") {
                    $stream = ssh2_exec($connection, $account['command']);
                    stream_set_blocking($stream, true);
                    while ($out = fread($stream, 4096)) {
                        echo $out;
                    }
                    fclose($stream);
                }
                // 关闭ssh连接
                $connection = null;
            });
        }
    }

    private function writeLog($message)
    {
        file_put_contents($this->logFilePath, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function onWorkerStop(Worker $worker)
    {
        foreach ($worker->timers as $timer_id) {
            $this->writeLog("删除定时任务");
            Timer::del($timer_id);
        }
    }

}