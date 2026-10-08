<?php

declare(strict_types=1);

namespace App\stripe\Controller;

use App\stripe\Middleware\CorsMiddleware;
use xphp\HttpServer\Annotation\AutoController;
use xphp\HttpServer\Annotation\Middleware;
use xphp\HttpServer\Contract\RequestInterface;
use xphp\HttpServer\Contract\ResponseInterface;

#[AutoController('stripe')]
#[Middleware(CorsMiddleware::class)]


/**
 * Stripe 支付
 * @tags Stripe支付
 */
class Index {

    public function __construct(protected RequestInterface $request, protected ResponseInterface $response) {
    }


    public function index() {
        $session = app('stripe')->createCheckout([
            'amount' => 150000,                        // 最小货币单位（1999 = $19.99）
            'name' => '会员套餐',
            'currency' => 'HKD',
            'success_url' => 'http://127.0.0.1:9551/stripe/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => 'http://127.0.0.1:9551/stripe/cancel',
            'metadata' => [
                'uid' => 123,
                'order_no' => 'NO' . date('YmdHis'),
            ],
        ]);
        return redirect($session['url']);  // 跳转 Stripe 托管收银台
    }


    /**
     * @doc
     * Stripe Webhook 回调(在 Stripe 后台将 Webhook 端点配置为 https://域名/stripe/webhook)
     * @return mixed
     */
    public function webhook() {
        // 必须读取原始请求体, 验签依赖未解析的原文
        $body = $this->request->getBody();
        $body->rewind();
        $payload = $body->getContents();
        $sigHeader = $this->request->getHeaderLine('Stripe-Signature');

        try {
            $result = app('stripe')->handleWebhook($payload, $sigHeader);
        } catch (\Throwable $th) {
            // 验签失败或处理异常返回非 2xx, Stripe 会按策略重试
            logger()->error('stripe webhook: ' . $th->getMessage());
            return $this->response->raw($th->getMessage())->withStatus(400);
        }

        return $this->response->raw(json_encode($result, JSON_UNESCAPED_UNICODE))->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * @doc
     * 主动查单: 实时查询支付状态(前端可每 3 秒轮询本接口, 无需等待 Webhook)
     * 查到已支付时自动落库并派发支付成功事件, 与 Webhook 幂等不重复
     * @return mixed
     * @param string $session_id 支付会话ID
     */
    public function query() {
        $sessionId = input('.session_id', '', 'trim');
        if (empty($sessionId) || !str_starts_with($sessionId, 'cs_')) {
            show_json([
                'code' => 201,
                'message' => 'session_id 参数错误',
            ]);
        }

        try {
            $result = app('stripe')->queryCheckout($sessionId);
        } catch (\Throwable $th) {
            show_json([
                'code' => 201,
                'message' => $th->getMessage(),
            ]);
        }

        show_json([
            'code' => 200,
            'data' => $result,
        ]);
    }

    /**
     * @doc
     * Checkout 支付完成回跳页(作为 success_url, 需带 {CHECKOUT_SESSION_ID} 占位符)
     * @return mixed
     * @param string $session_id 支付会话ID
     */
    public function success() {
        $sessionId = input('.session_id', '', 'trim');
        if (empty($sessionId) || !str_starts_with($sessionId, 'cs_')) {
            showmsg('参数错误');
        }

        try {
            $session = app('stripe')->retrieveCheckout($sessionId);
        } catch (\Throwable $th) {
            showmsg('查询支付会话失败: ' . $th->getMessage());
        }

        print_r($session);

        $paid = ($session['payment_status'] ?? '') === 'paid';
        $detail = number_format(($session['amount_total'] ?? 0) / 100, 2) . ' ' . strtoupper($session['currency'] ?? 'USD');
        return $this->response->raw($this->page($paid ? '支付成功' : '支付处理中', $paid, $detail));
    }

    /**
     * @doc
     * Checkout 支付取消回跳页(作为 cancel_url)
     * @return mixed
     */
    public function cancel() {
        return $this->response->raw($this->page('支付已取消', false, '可返回重新发起支付'));
    }

    /**
     * 渲染回跳结果页
     * @param string $title 标题
     * @param bool $ok 是否成功
     * @param string $detail 明细说明
     * @return string
     */
    private function page(string $title, bool $ok, string $detail): string {
        $icon = $ok ? '✓' : '⏳';
        $color = $ok ? '#16a34a' : '#f59e0b';
        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . $title . '</title><style>body{font-family:-apple-system,"PingFang SC",sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:#f7f8fa}.box{text-align:center;padding:40px 48px;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.06)}.icon{font-size:48px;color:' . $color . '}h2{margin:12px 0 8px;color:#333}p{color:#999;margin:0}</style></head><body><div class="box"><div class="icon">' . $icon . '</div><h2>' . $title . '</h2><p>' . $detail . '</p></div></body></html>';
    }
}
