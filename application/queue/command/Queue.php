<?php

namespace app\queue\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\Db;

class Queue extends Command
{
    protected $server;

    protected $debug = true;

    protected $table = null;
    protected $types = [
        'queue',
    ];

    // 命令行配置函数
    protected function configure()
    {
        // setName 设置命令行名称
        $this->setName('queue');
    }

    // 设置命令返回信息
    protected function execute(Input $input, Output $output)
    {
        $this->server = new \swoole_server(config('swoole.host'), 9502);
        // server 运行前配置
        $this->server->set([
            'worker_num'      => config('swoole.worker_num'),
            'daemonize'       => config('swoole.daemonize'),
            'task_worker_num' => config('swoole.task_worker_num')
        ]);

        $this->table = new \swoole_table(1024);
        $this->table->column('time', \swoole_table::TYPE_INT, 11);
        $this->table->create();

        // 注册回调函数
        $this->server->on('Start', [$this, 'onStart']);
        $this->server->on('WorkerStart', [$this, 'onWorkerStart']);
        $this->server->on('Connect', [$this, 'onConnect']);
        $this->server->on('Receive', [$this, 'onReceive']);
        $this->server->on('Task', [$this, 'onTask']);
        $this->server->on('Finish', [$this, 'onFinish']);
        $this->server->on('Close', [$this, 'onClose']);
        $this->server->start();
    }
    // 主进程启动时回调函数
    public function onStart(\swoole_server $server)
    {
        echo "[ " . date('Y-m-d H:i:s') . " ] 启动queue" . PHP_EOL;
    }

    public function onWorkerStart(\swoole_server $server, $worker_id)
    {
        var_dump(\Db::connect('db2')->name('so_test')->count());die;
        if (!isset($this->types[$worker_id])) {
            return false;
        }

        $worker = $this->types[$worker_id];

        swoole_timer_tick(1000, function () use ($worker) {
            //判断锁
            $val = $this->table->get($worker);

            if (isset($val['time'])) {
                //如果有值 而且 时间小于60秒 就返回 否则就删除锁
                if (time() - $val['time'] < 1) {
                    echo "所存在 被忽略";
                    return false;
                } else {
                    $this->table->del($worker);
                }
            }

            $items = Db::name('trigger')->where('status','=',0)->select();
            //加锁
            $this->table->set($worker, array('time' => time()));
        
            echo "[ " . date('Y-m-d H:i:s') . " ][ 执行任务 ]；共有 " . count($items) . " 条记录满足！" . PHP_EOL;

            foreach ($items as $item) {
                $this->server->task($item);
            }

            //删锁
            $this->table->del($worker);

        });
    }

    // 异步任务处理函数
    public function onTask(\swoole_server $server, int $task_id, int $worker_id, $item)
    {
        echo "[ " . date('Y-m-d H:i:s') . " ][ 执行任务 ] 任务ID：" . $task_id . "" . PHP_EOL;

        //处理数据
        $item['table'] = explode('_', $item['table']);

        $new_string = '';
        foreach ($item['table'] as $k => $v) {
            $new_string .= ucfirst($v);
        }
        $class_name = "\\app\\queue\\asynchronous\\" . $new_string;
        if (!class_exists($class_name)) {
            return false;
        }
        $class = new $class_name;

        if (empty($item['event'])) {
            $result = $class->run($item['pk']);
        } else {
            $function_name = $item['event'];
            $result = $class->$function_name($item['pk']);
        }

        if ($result == true){
            Db::name('trigger')->where('id','=',$item['id'])->delete();
        }else{
            Db::name('trigger')->where('id','=',$item['id'])->update(['status' => -1]);
        }

         $this->server->finish($result);
    }

    // 异步任务完成通知 Worker 进程函数
    public function onFinish(\swoole_server $server, int $task_id, $data)
    {
        if ($data == false){
            echo PHP_EOL . "[任务失败] 任务ID：" . $task_id . "；" . PHP_EOL;
        }else{
            echo PHP_EOL . "[任务成功] 任务ID：" . $task_id . "；" . PHP_EOL;
        }

    }

    // 建立连接时回调函数
    public function onConnect(\swoole_server $server, $fd, $from_id)
    {
    }

    // 收到信息时回调函数
    public function onReceive(\swoole_server $server, $fd, $from_id, $data)
    {
    }

    // 关闭连时回调函数
    public function onClose(\swoole_server $server, $fd, $from_id)
    {
    }  
}
