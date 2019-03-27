<?php
namespace app\queue\library;

abstract class TaskAbstract
{

    /* 执行异步 */
    abstract function run($pk,$args);

    public function getError() {
        return $this->error;
    }
}