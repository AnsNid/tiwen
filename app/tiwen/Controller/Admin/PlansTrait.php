<?php

namespace App\tiwen\Controller\Admin;

use App\tiwen\Controller\Api\Responses;
use App\tiwen\Service\SettingService;
use xphp\HttpServer\Annotation\Middleware;
use App\tiwen\Middleware\AdminAuthMiddleware;

/**
 * 后台订阅价格接口（需 tiwen:admin 权限）
 * 套餐定义查看与编辑(订阅:名称/月价/年价/每日限次/Token 配额;一次性包:售价/积分/加赠/有效期/赠送会员),
 * 名称与权益支持 zh/zh-TW/en 多语言,保存后即时生效于计费与售卖页
 */
#[Middleware(AdminAuthMiddleware::class)]
trait PlansTrait {

    use Responses;

    /**
     * 套餐定义查看
     * @doc(method: 'get', description: '获取当前生效的套餐定义(文件默认与后台覆盖合并后的结果)与结算货币,含一次性包与多语言名称/权益', tag: "后台-订阅价格")
     * @return array {code: 200, data: {currency: string, plans: {free: {...}, pro: {...}, team: {...}, flagship: {...}}}}
     */
    public function plans() {
        $setting = new SettingService();
        return $this->ok([
            'currency' => $setting->currency(),
            'plans' => $setting->plans(),
        ]);
    }

    /**
     * 套餐定义保存
     * @doc(method: 'post', description: '保存套餐价格、配额与结算货币(订阅与一次性包),名称/权益支持 zh/zh-TW/en 三语,即时生效(进行中的订阅金额不变,新 Checkout 按新价)', tag: "后台-订阅价格")
     * @param array $plans 套餐定义(订阅形如 {pro: {name, name_i18n, price_monthly, price_yearly, daily_asks, tokens_quota, features, features_i18n}},一次性包形如 {flagship: {name_i18n, price, credits, bonus_percent, validity_months, gift_pro_months, gift_monthly_credits, tokens_quota, features_i18n}})
     * @param string $currency 结算货币(ISO 4217 三位码,如 USD/EUR/CNY),决定 Stripe 扣款币种与前台展示
     * @return array {code: 200, message: '套餐已保存'}
     */
    public function plansSave() {
        $raw = input('plans', []);
        if (!is_array($raw) || !$raw) {
            return $this->fail('套餐数据不能为空');
        }
        $setting = new SettingService();
        $currency = trim((string) input('currency', ''));
        if ($currency !== '' && !$setting->saveCurrency($currency)) {
            return $this->fail('货币代码不正确,请填写三位币种码(如 USD)');
        }
        $setting->savePlans($raw);
        return $this->ok(null, '套餐已保存,新下单即刻按新价格与币种计费');
    }
}
