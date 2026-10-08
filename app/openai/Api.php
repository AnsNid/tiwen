<?php

declare(strict_types=1);

namespace App\openai;


use xphp\Di\Manager;
use OpenAI\Client;

/**
 * @method Client getClient()
 */
class Api extends Manager {
    /**
     * 获取默认驱动
     */
    public function getDefaultDriver() {
        return Client::class;
    }
}
