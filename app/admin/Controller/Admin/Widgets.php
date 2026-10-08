<?php

namespace App\admin\Controller\Admin;

use App\admin\Authorization;
use xphp\Response;

/**
 * 后台小部件
 */

class Widgets extends Authorization {

	/**
	 * 小部件数据更新与查询
	 */
	public function submit() {

		$data = input('.data');
		$user = $this->request->getAttribute('admin');
		app('user.data')->insert($user['uid'], ['widgets' => $data]);
		show_json([
			'code' => 200,
			'data' => $data,
		]);
	}

	/**
	 * 组件数据提交
	 */
	public function componentSubmit() {

		$data = input('.data');
		$name = input('.name', '', 'trim');
		$user = $this->request->getAttribute('admin');
		$eventdata = $this->container->get('event')->dispatch('admin.widgets.' . $name . '@submit', $user, $data);
		if ($eventdata instanceof \Psr\Http\Message\ResponseInterface) {
			return $eventdata;
		}

		show_json([
			'code' => 200,
			'data' => $eventdata ?? [],
		]);
	}

	/**
	 * 小部件数据
	 */
	public function data() {

		$name = input('.name', '', 'trim');
		$user = $this->request->getAttribute('admin');
		$eventdata = $this->container->get('event')->dispatch('admin.widgets.' . $name . '@data', $user);
		if ($eventdata instanceof \Psr\Http\Message\ResponseInterface) {
			return $eventdata;
		}

		show_json([
			'code' => 200,
			'data' => $eventdata ?? [],
		]);
	}
}
