<?php

declare(strict_types=1);

namespace App\crontab\Controller;

use xphp\HttpServer\Annotation\AutoController;
use xphp\HttpServer\Annotation\RequestMapping;

#[AutoController('crontab')]

class Index {

    /**
     * 首页
     */
    #[RequestMapping(path: "admin", methods: "get")]
    public function index() {
        return xphp(\xphp\View\RenderInterface::class)->render(app_path('crontab', 'View/dist') . 'index.html');
    }

    /**
     * assets 资产, assets/{filename}
     */
    #[RequestMapping(path: "assets/{filename}", methods: "get")]
    public function assets(string $filename) {
        // 剥离路径成分并限制后缀白名单,防止路径穿越读取任意文件
        $filename = basename($filename);
        if (!preg_match('/^[a-zA-Z0-9._-]+\.(js|mjs|css|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot|map|json)$/i', $filename)) {
            showmsg('文件不存在!', 404);
        }
        $path = app_path('crontab', 'View/dist/assets') . $filename;
        if (!is_file($path)) {
            showmsg('文件不存在!', 404);
        }
        return response()->download($path, $filename, 'inline');
    }
}
