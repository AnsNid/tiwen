<?php

declare(strict_types=1);

namespace App\admin\Annotation;

use Attribute;

/**
 * 跳过权限验证注解
 * 用于标记不需要进行登录验证的方法
 */
#[Attribute(Attribute::TARGET_METHOD)]
class SkipAuth {
    public function __construct() {
    }
}
