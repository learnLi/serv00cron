<?php

namespace app\service;

use think\Exception;
use Workerman\Timer;
use \Workerman\Worker;
use think\Config;

class Cron extends Worker
{
    private string $tg_bot_token;

    private int $tg_chat_id;

    public function __construct($socket_name = '', array $context_option = array())
    {
        parent::__construct($socket_name, $context_option);
        // 定义 log 文件路径
        $logFilePath = root_path() . 'logs';
        if (!file_exists($logFilePath)) {
            mkdir($logFilePath, 0777, true);
        }
        $this->onWorkerStart = array($this, 'onWorkerStart');
        $this->onWorkerStop = array($this, 'onWorkerStop');
    }

    public function onWorkerStart(Worker $worker)
    {
        $this->tg_chat_id = env('TG_CHAT_ID', null);
        $this->tg_bot_token = env('TG_BOT_TOKEN', null);
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
                    $this->writeLog("账号: " . $account['username'] . " 执行命令: " . $account['command'] . " 输出: \n" . $outputStr);
                }
                // 关闭ssh连接
                $connection = null;
            });
        }
    }

    private function getLogPath(): string
    {
        return root_path() . 'logs' . DIRECTORY_SEPARATOR . 'cron_' . date('Ymd') . '.log';
    }

    private function writeLog($message)
    {
        // 获取当前的时间，并格式化为 "YYYY-MM-DD HH:MM:SS"
        // 将时间戳和消息合并
        $fullMessage = "[ {${date('Y-m-d H:i:s')}} ] - $message";
        # 判断是否配置了tg_bot_token和chat_id ，如果配置了，则发送到tg ，否则写入到日志文件
        if (!empty($this->tg_bot_token && $this->tg_chat_id)) {
            $response = $this->botSend($fullMessage);
            if ($response === false) {
                $response = "TG机器人发送失败";
            }
            file_put_contents($this->getLogPath(), $response . PHP_EOL, FILE_APPEND | LOCK_EX);
            return;
        }
        file_put_contents($this->getLogPath(), $fullMessage . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function botSend($msg): bool|string
    {
        $payload = [
            'chat_id' => $this->chat_id,
            'text' => $msg,
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        "text" => "问题反馈？",
                        "url" => "https://t.me/xxxx"
                    ]
                ]
            ]
        ];
        try {
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => "https://api.telegram.org/bot{$this->tg_bot_token}/sendMessage",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json'
                ),
            ));
            $response = curl_exec($curl);
            $err = curl_error($curl);
            if ($err) {
                throw new Exception($err);
            }
            $response_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($response_code != 200) {
                throw new Exception("HTTP response code: $response_code");
            }
            return $response;
        } catch (\Exception $e) {
            return $e->getMessage();
        } finally {
            if (!isset($curl)) {
                curl_close($curl);
            }
        }
    }

    public function onWorkerStop(Worker $worker)
    {
        foreach ($worker->timers as $timer_id) {
            $this->writeLog("删除定时任务");
            Timer::del($timer_id);
        }
    }
}