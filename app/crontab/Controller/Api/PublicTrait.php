<?php

namespace App\crontab\Controller\Api;


/**
 * 公共控制器
 */
trait PublicTrait {

    /**
     * 获取网站配置
     */
    public function getConfig() {
        show_json([
            'code' => 200,
            'data' => [
                'site_name' => '定时任务管理后台',
                'site_url' => 'https://v.x-php.com',
                'site_logo' => '',
                'site_description' => '高效管理定时任务。',
            ],
        ]);
    }
}
