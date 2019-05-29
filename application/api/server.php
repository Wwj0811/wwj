<?php

class Ws {
    public $ws = null;

    public function __construct()
    {
        $this->ws = new Swoole\websocket\server('0.0.0.0',8811);

        $this->ws->on('open',[$this, 'onOpen']);
        $this->ws->on('message',[$this, 'onMessage']);
        $this->ws->on('close',[$this, 'onClose']);

        $this->ws->set([
            'daemonize' =>  0,
            'heartbeat_check_interval' => 5,
            'heartbeat_idle_time' => 10
        ]);

        $this->ws->start();
    }


    /**
     * 监听连接事件
     * @param $wx
     * @param $request
     */
    public function onOpen($ws, $request)
    {
        $data = [];
        $data['type'] = 'register';
        $data['data'] = $request->fd;
        $ws->push($request->fd,json_encode($data));
        echo '注册：'.$request->fd.PHP_EOL;
    }

    /**
     * 监听消息事件
     * @param $ws
     * @param $frame
     */
    public function onMessage($ws, $frame)
    {
        global $redis;

        echo "消息：".json_encode($frame).PHP_EOL;

        $d = [];

        foreach($ws->connections as $fd)
        {
            $d[] = $fd;
        }

        echo '连接:'.json_encode($d).PHP_EOL;

        $ws->push($frame->fd,json_encode(['type'=>'boom']));

        return;

        // 解析消息
        $data = json_decode($frame->data,true);

        $uid = $data['uid'];
        $swoole_id = $frame->fd;

        if($data['type'] == 'login')
        {

            $redis->set('swoole:'.$swoole_id,$uid);
            $redis->set('swoole-user:'.$uid,$swoole_id);

            // 通知登录成功
            $data = [];
            $data['type'] = 'login:ok';
            $data['time'] = time();

            $ws->push($swoole_id,json_encode($data));

            echo "登录成功：uid=".$uid." swoole_id=".$swoole_id.PHP_EOL;
        }
        else if($data['type'] == 'text')
        {
            $touid = $data['touid'];

            global $redis;

            // 聊天信息存入列表
            $newdata = [];
            $newdata['type'] = 'text';
            $newdata['content'] = $data['content'];
            $newdata['time'] = date('Y-m-d H:i:s',time());
            $newdata['uid'] = $uid;
            $newdata['touid'] = $touid;
            $redis->rPush('chat_history:'.rank($uid,$touid),json_encode($newdata));

            // 最后一句话存入hash
            $redis->hSet('chat:'.$uid,$touid,json_encode(['info'=>$data['content'],'time'=>time()]));
            $redis->hSet('chat:'.$touid,$uid,json_encode(['info'=>$data['content'],'time'=>time()]));

            // 给接收方加未读消息数量
            $redis->hIncrBy('chat_number:'.$touid,$uid);

            // 判断对方是否在线
            $on = $redis->exists('swoole-user:'.$touid);
            if($on)
            {
                echo '对方在线'.PHP_EOL;
                // 取对方头像
                $userinfo = $redis->get('member:'.$uid);
                $userinfo = json_decode($userinfo,true);
                $newdata['avatar'] = $userinfo['avatar'];

                $tofd = $redis->get('swoole-user:'.$touid);

                $ws->push($tofd,json_encode($newdata));

                // 推送到对方的聊天列表
                $newdata = [];
                $newdata['type'] = 'chat_list';
                $newdata['uid'] = $uid;
                $newdata['content'] = $data['content'];
                $newdata['avatar'] = $userinfo['avatar'];
                $newdata['nickName'] = $userinfo['nickName'];
                $newdata['time'] = date('Y-m-d',time());
                $ws->push($tofd,json_encode($newdata));

                // 推送全局未读数量
                $number = $redis->hGetAll('chat_number:'.$touid);

                if($number)
                {
                    $i = 0;
                    foreach ($number as $val)
                    {
                        $i += $val;
                    }
                    $newdata = [];
                    $newdata['type'] = 'chat_number';
                    $newdata['number'] = $i;
                    $ws->push($tofd,json_encode($newdata));
                }


            }
            else
            {
                echo 'uid:'.$touid.'  对方不在线'.PHP_EOL;
            }
        }
        else if($data['type'] == 'group_text')
        {
            global $redis;
            global $url;

            // 获取群信息
            $group_id = $data['group_id'];
            $group = json_decode($redis->get('group:'.$group_id),true);
            $uids = $group['uids'];
            $group_uid = $group['uid'];
            echo json_encode($uids).PHP_EOL;

            // 群主昵称
            $group_uinfo = json_decode($redis->get('member:'.$group_uid), true);
            echo json_encode($group_uinfo).PHP_EOL;

            // 存群聊天记录
            $newdata = [];
            $newdata['type'] = 'group_text';
            $newdata['content'] = $data['content'];
            $newdata['time'] = date('Y-m-d H:i:s',time());
            $newdata['uid'] = $uid;
            $redis->rPush('group_history:'.$group_id,json_encode($newdata));

            foreach ($uids as $v)
            {
                // 最后一句话存入hash
                $redis->hSet('chat:'.$v,$group_id,json_encode(['info'=>$data['content'],'time'=>time()]));

                // 给群里其他用户发消息
                if($v != $uid)
                {
                    // 判断用户是否在线
                    $on = $redis->exists('swoole-user:'.$v);
                    if($on)
                    {
                        $tofd = $redis->get('swoole-user:'.$v);
                        // 取对方头像
                        $userinfo = $redis->get('member:'.$uid);
                        $userinfo = json_decode($userinfo,true);

                        // 推送到对方的聊天页面
                        $newdata = [];
                        $newdata['type'] = 'group_text';
                        $newdata['group_id'] = $group_id;
                        $newdata['content'] = $data['content'];
                        $newdata['avatar'] = $userinfo['avatar'];
                        $newdata['time'] = date('Y-m-d',time());
                        $newdata['uid'] = $uid;
                        $ws->push($tofd,json_encode($newdata));

                        // 推送到对方的聊天列表
                        $newdata = [];
                        $newdata['type'] = 'chat_list';
                        $newdata['uid'] = $group_id;
                        $newdata['content'] = $data['content'];
                        $newdata['avatar'] = $url.$group['avatar'];
                        $newdata['nickName'] = $group['title'];
                        $newdata['time'] = date('Y-m-d',time());
                        $ws->push($tofd,json_encode($newdata));

                        // 给接收方加未读消息数量
                        $redis->hIncrBy('chat_number:'.$v,$group_id);

                        // 推送全局未读数量
                        $number = $redis->hGetAll('chat_number:'.$v);

                        if($number)
                        {
                            $i = 0;
                            foreach ($number as $val)
                            {
                                $i += $val;
                            }
                            $newdata = [];
                            $newdata['type'] = 'chat_number';
                            $newdata['number'] = $i;
                            $ws->push($tofd,json_encode($newdata));
                        }
                    }
                }

            }
        }
        else if($data['type'] == 'group_create')
        {
            global $redis;
            global $url;

            // 获取群信息
            $group_id = $data['group_id'];
            $group = json_decode($redis->get('group:'.$group_id),true);
            $uids = $group['uids'];
            $group_uid = $group['uid'];
            echo json_encode($uids).PHP_EOL;

            // 群主昵称
            $group_uinfo = json_decode($redis->get('member:'.$group_uid), true);
            echo json_encode($group_uinfo).PHP_EOL;

            // 存群聊天记录
            $newdata = [];
            $newdata['type'] = 'group_create';
            $newdata['content'] = $group_uinfo['nickName'].'创建了群聊';
            $newdata['time'] = date('Y-m-d H:i:s',time());
            $newdata['uid'] = $uid;
            $redis->rPush('group_history:'.$group_id,json_encode($newdata));

            foreach ($uids as $v)
            {
                // 最后一句话存入hash
                $redis->hSet('chat:'.$v,$group_id,json_encode(['info'=>$group_uinfo['nickName'].'创建了群聊','time'=>time()]));

                // 给群里其他用户发消息
                if($v != $group_uid)
                {
                    // 判断用户是否在线
                    $on = $redis->exists('swoole-user:'.$v);
                    if($on)
                    {
                        $tofd = $redis->get('swoole-user:'.$v);

                        // 推送到对方的聊天列表
                        $newdata = [];
                        $newdata['type'] = 'chat_group_create';
                        $newdata['group_id'] = $group_id;
                        $newdata['content'] = $group_uinfo['nickName'].'创建了群聊';
                        $newdata['avatar'] = $url.$group['avatar'];
                        $newdata['title'] = $group['title'];
                        $newdata['time'] = date('Y-m-d',time());
                        $ws->push($tofd,json_encode($newdata));
                    }
                }

            }

        }
        else if($data['type'] == 'boom')
        {
            echo '客户端维持心跳'.PHP_EOL;
        }
        else
        {
            foreach($ws->connections as $fd)
            {
                $ws->push($fd, $frame->data);
            }
        }

    }

    public function onClose($ws, $fd)
    {
        global $redis;
        $uid = $redis->get('swoole:'.$fd);
        // 将用户id与服务id解绑
        $redis->del('swoole-user:'.$uid);
        $redis->del('swoole:'.$fd);

        echo 'uid:'.$uid.'  退出：'.$fd.PHP_EOL;

        $ws->push($fd,json_encode($data));
    }
}

$ws = new Ws();

?>