<?php
namespace app\test\asynchronous;

use app\test\library\TaskAbstract;

class Product extends TaskAbstract
{

	public function __construct() {

    }

	public function run($pk,$args){
		
	}

	public function insert($id = 0,$args = ''){
		$product = \Db::name('product')->where('id','=',$id)->find();
		if(empty($product)){
			$this->error = '记录不存在';
			return false;
		}
		try {
			\Db::connect('db2')->name('so_test')->insert(['content' => $product['content']]);
		} catch (\Exception $e) {
			\Log::write('写入商品失败：'.$e);
			return false;
		}
		
		return true;
	}

}