<?php
namespace ImCloud\Server;

use Redis;
use ImCloud\Supper\Arithmetic;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Firebase\JWT\JWT;
use Swoole\Server as SwooleServer;

class Dispatcher
{
    public function register(Route $route,SwooleServer $server, $fd, $data)
    {

        $serverKey = $route->getServerKey();
        // 把服务端的信息记录到redis中
        $redis = $route->getRedis();
        $value = \json_encode([
            'ip'   => $data['ip'],
            'port' => $data['port'],
        ]);
        $redis->sadd($serverKey, $value);
        // 这里是通过触发定时判断，不用heartbeat_check_interval 的方式检测
        // 是因为我们还需要主动清空，redis 数据
        //
        // $timer_id 定时器id
        $server->tick(3000, function($timer_id, Redis $redis,SwooleServer $server, $serverKey, $fd, $value){
            // 判断服务器是否正常运行，如果不是就主动清空
            // 并把信息从redis中移除
            if (!$server->exist($fd)) {
                $redis->srem($serverKey, $value);
                $server->clearTimer($timer_id);

                echo "im server 宕机， 主动清空\n";
            }
        }, $redis, $server, $serverKey, $fd, $value);
    }
    /**
     * Route向服务器广播
     * @param  Route        $route  [description]
     * @param  SwooleServer $server [description]
     * @param  [type]       $fd     [description]
     * @param  [type]       $data   [description]
     * @return [type]               [description]
     */
    public function routeBroadcast(Route $route,SwooleServer $server, $fd, $data)
    {
        // dd($data, '接收到 im-server client 的msg');

        // 获取到所有的服务器
        $imServers = $route->getIMServers();

        $token = $this->getToken(0, $route->getHost().":".$route->getPort());
        $header = ['sec-websocket-protocol' => $token];
        foreach ($imServers as $key => $im) {
            $imInfo = json_decode($im, true);
            // 转发给其他的服务器
            $route->send($imInfo['ip'], $imInfo['port'], [
                'method' => 'routeBroadcast',
                'data' => [
                    'msg' => $data['msg'],
                    'type' => 'group',      //消息分类--广播
                    'sourceId' => $data['sourceId'],
                    'sourceName' => $data['sourceName'],
                ]
            ], $header);
        }
    }

    public function ack($value='')
    {
    
    }


    /**
     * 用户登入
     * @param  Route    $route    [description]
     * @param  Request  $request  [description]
     * @param  Response $response [description]
     * @return [type]             [description]
     */
    public function login(Route $route,Request $request,Response $response)
    {
        $imServer = \json_decode($this->getIMServer($route), true);
        // dd($imServer, '这是获取的服务器信息');

        $url = $imServer['ip'].":".$imServer['port'];
        // 去数据库中进行用户密码和账号验证

        $uid = $request->post['id'];
        //不同的类型-admin为客服
        $type = $request->post['type'];
        $token = $this->getToken($uid, $url, $type);
        $response->end(\json_encode(['token' => $token, 'url' => $url]));
    }

    protected function getToken($uid, $url, $type = 'user')
    {
        $key = "swocloud";
        $time = \time();
        $payload = array(
            "iss" => "http://example.org",
            "aud" => "http://example.com",
            "iat" => $time, // 签发时间
            "nbf" => $time,  // 生效时间
            "exp" => $time + (60 * 60 * 24),
            "data" => [
                "uid"       => $uid,
                "name"      => $uid,
                "type"      => $type,
                "serverUrl" => $url
            ],
        );
        return JWT::encode($payload, $key);
        // $decoded = JWT::decode($jwt, $key, array('HS256'));
    }

    protected function getIMServer(Route $route)
    {
        // 所有的服务信息
        $imServers = $route->getIMServers();
        if (!empty($imServers)) {
            return Arithmetic::{$route->getArithmetic()}($imServers);
        }
        return false;
    }
}
