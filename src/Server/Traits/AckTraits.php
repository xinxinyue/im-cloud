<?php
namespace ImCloud\Server\Traits;

use Co;
use Swoole\Coroutine\Http\Client;
use Swoole\Table;

/**
 *
 */
trait AckTraits
{
    protected $table;

    public function createTable()
    {
        $this->table = new Table(1024);

        $this->table->column('ack', Table::TYPE_INT, 2);
        $this->table->column('num', Table::TYPE_INT, 2);

        $this->table->create();
    }

    public function confirmGo($unipid, $data,Client $client)
    {
        go(function() use ($unipid, $data, $client){
            while (true) {
                Co::sleep(1);
                // 获取im-server回复的确认的信息
                $ackData = $client->recv(0.2);
                $ackInfo = \json_decode($ackData->data, true);
                // 1. 判断类型 - 是否为确认
                if (isset($ackInfo['method']) && $ackInfo['method'] == 'ack') {
                    // 确认信息
//                    dd('接收到确认信息', $ackInfo['msg_id']);

                    $this->table->incr($ackInfo['msg_id'], 'ack');
                }

                // 2. 判断是否任务确认
                // 2.1. 获取任务对应的状态
                $task = $this->table->get($unipid);

                // 2.2. a. 是否被确认，
                if ($task['ack'] > 0 || $task['num'] >= 3) {
                    echo "清空任务".PHP_EOL;
                    $this->table->del($unipid);
                    $client->close();
                    break;
                } else {
                    // 重试发送信息

                    $client->push(\json_encode($data));
                }

                $this->table->incr($unipid, 'num');
                echo $unipid."任务重试加一".PHP_EOL;
            }
        });
    }
}
