<?php

namespace App\tiwen\Controller;

use xphp\HttpServer\Annotation\AutoController;
use xphp\HttpServer\Annotation\Middleware;
use xphp\Session\Middleware\SessionMiddleware;
use App\tiwen\Middleware\CorsMiddleware;

/**
 * 提问后台管理控制器 —— /sysAdmin 前台页面调用的 API
 *
 * 接口分组(均通过 trait 组合):
 *  - LoginTrait    登录(免鉴权):login / info
 *  - StatsTrait    消耗统计(需 tiwen:admin):stats
 *  - BillingTrait  账单明细(需 tiwen:admin):billing
 *  - PlansTrait    订阅价格(需 tiwen:admin):plans / plansSave
 *  - MembersTrait  会员管理(需 tiwen:admin):members / memberSave / memberRecords
 *  - PromptsTrait  提示词(需 tiwen:admin):prompts / promptsSave
 *  - WebSearchTrait 联网搜索(需 tiwen:admin):webSearch / webSearchSave
 *  - LegalTrait    协议条款(需 tiwen:admin):legal / legalSave
 */
#[AutoController('/tiwen/admin')]
#[Middleware(SessionMiddleware::class, priority: 100)]
#[Middleware(CorsMiddleware::class, 99)]
class AdminApi extends Authorization {
    // 后台登录（无需鉴权,登录内校验 tiwen:admin 权限）
    use Admin\LoginTrait;
    // 消耗统计（AdminAuthMiddleware 鉴权）
    use Admin\StatsTrait;
    // 账单明细（AdminAuthMiddleware 鉴权）
    use Admin\BillingTrait;
    // 订阅价格管理（AdminAuthMiddleware 鉴权）
    use Admin\PlansTrait;
    // 会员管理（AdminAuthMiddleware 鉴权）
    use Admin\MembersTrait;
    // 提示词管理（AdminAuthMiddleware 鉴权）
    use Admin\PromptsTrait;
    // 联网搜索(Tavily)配置（AdminAuthMiddleware 鉴权）
    use Admin\WebSearchTrait;
    // 协议条款管理（AdminAuthMiddleware 鉴权）
    use Admin\LegalTrait;
}
