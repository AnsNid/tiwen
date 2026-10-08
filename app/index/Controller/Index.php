<?php

declare(strict_types=1);

namespace App\index\Controller;

use xphp\HttpServer\Annotation\AutoController;
use xphp\HttpServer\Annotation\RequestMapping;

#[AutoController('index')]

/**
 * @doc 这是一个示例控制器，展示如何使用@doc注释
 */

class Index extends Authorization {
    /**
     * 首页
     * @doc(path: '/index', method: 'post,get')
     * @tags index
     * @description 根据用户ID获取用户的详细信息
     * @message 根据用户ID获取用户的详细信息
     * @param string $tpl 模板名称
     */
    #[RequestMapping(methods: "post,get")]
    public function index() {
        $tpl = input('tpl', 'index');
        return view($tpl, [
            'url' => $this->request->getUri(),
            'adminhostpath' => $this->container->get('config')->sysget('config.adminhostpath', 'admin'),
        ]);
    }
}
