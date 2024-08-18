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
        $logFilePath = root_path() . 'logs';
        if (!file_exists($logFilePath)) {
            mkdir($logFilePath, 0777, true);
        }
        $dateToday = date('Ymd');
        $logFile = $logFilePath . DIRECTORY_SEPARATOR . 'cron_' . $dateToday . '.log';
        $this->logFilePath = $logFile;
        $this->onWorkerStart = array($this, 'onWorkerStart');
        $this->onWorkerStop = array($this, 'onWorkerStop');
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
            $worker->timers[$key] = Timer::add($account['exec_timer'], function () use ($account, &$worker, $key) {
                $connection = ssh2_connect($account['host'], $account['port'] ?? 22);
                if (!$connection) {
                    $this->writeLog("账号：" . $account['username'] . " 连接失败");
                    Timer::del($worker->timers[$key]);
                    return;
                }
                if (!ssh2_auth_password($connection, $account['username'], $account['password'])) {
                    $this->writeLog("账号：" . $account['username'] . " 账号密码错误");
                    Timer::del($worker->timers[$key]);
                    return;
                }

                $this->writeLog("账号: " . $account['username'] . " 连接成功");
                if ($account['command'] != "") {
                    $outputStr = "";
                    $stream = ssh2_exec($connection, $account['command']);
                    stream_set_blocking($stream, true);
                    while ($out = fread($stream, 4096)) {
                        $outputStr .= $out;
                    }
                    fclose($stream);
                    $this->writeLog("账号: " . $account['username'] . " 执行命令: " . $account['command'] . " 输出: " . $outputStr);
                }
                // 关闭ssh连接
                $connection = null;
            });
        }
    }

    private function writeLog($message)
    {
        // 获取当前的时间，并格式化为 "YYYY-MM-DD HH:MM:SS"
        $timestamp = date('Y-m-d H:i:s');
        // 将时间戳和消息合并
        $fullMessage = "[ $timestamp ] - $message";
        file_put_contents($this->logFilePath, $fullMessage . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function onWorkerStop(Worker $worker)
    {
        foreach ($worker->timers as $timer_id) {
            $this->writeLog("删除定时任务");
            Timer::del($timer_id);
        }
    }
}