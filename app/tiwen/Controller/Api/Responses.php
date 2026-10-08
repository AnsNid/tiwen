<?php

namespace App\tiwen\Controller\Api;

/**
 * 公共响应工具
 * 供所有 API trait 复用(ok / fail / uid)
 */
trait Responses {

    /**
     * 成功响应
     */
    protected function ok(mixed $data, string $message = 'success') {
        return $this->response->json([
            'code' => 200,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * 失败响应(data 可携带配额等补充信息,供前端按 402 等场景精确提示)
     */
    protected function fail(string $message, int $code = 201, ?array $data = null) {
        return $this->response->json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * 当前登录用户 uid(由 AuthTokenMiddleware 注入请求属性)
     */
    protected function uid(): int {
        return (int) ($this->request->getAttribute('uid', 0));
    }
}
