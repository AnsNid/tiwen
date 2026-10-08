<?php

declare(strict_types=1);

namespace App\tiwen\Service;

/**
 * 后台设置服务
 * 套餐价格与提示词等运营配置存 runtime/config(sysget/sysset 通道),
 * 读取时与 app 配置文件默认值合并:后台未改过的项回落文件值
 */
class SettingService {

    /** runtime config 键:套餐覆盖 */
    private const KEY_PLANS = 'tiwen_plans';
    /** runtime config 键:结算货币(ISO 4217 三位码,后台可配,决定 Stripe 扣款币种与前台展示) */
    private const KEY_CURRENCY = 'tiwen_currency';
    /** runtime config 键:对话系统提示词 */
    private const KEY_PROMPT_CHAT = 'tiwen_prompt_chat';
    /** runtime config 键:综合分析系统提示词 */
    private const KEY_PROMPT_SYNTHESIS = 'tiwen_prompt_synthesis';
    /** runtime config 键:联网搜索(Tavily)配置覆盖 */
    private const KEY_WEB_SEARCH = 'tiwen_web_search';

    /** 常用货币符号映射(未收录的币种展示为 "CODE ") */
    private const CURRENCY_SYMBOLS = [
        'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CNY' => '¥', 'JPY' => '¥',
        'HKD' => 'HK$', 'TWD' => 'NT$', 'SGD' => 'S$', 'AUD' => 'A$', 'CAD' => 'C$',
        'NZD' => 'NZ$', 'CHF' => 'CHF ', 'KRW' => '₩', 'INR' => '₹', 'THB' => '฿',
    ];

    /** 协议文档白名单:注册协议 / 隐私条款 / 关于我们 / 支付条款 */
    public const LEGAL_DOCS = ['register', 'privacy', 'about', 'payment'];
    /** 协议文档语言白名单(与前台 locale 一致:简体 / 繁体 / 英文) */
    public const LEGAL_LANGS = ['zh', 'zh-TW', 'en'];
    /** 套餐名称/权益多语言白名单(与前台 locale 一致) */
    public const PLAN_LANGS = ['zh', 'zh-TW', 'en'];
    /** 单篇协议文档内容上限(字),#@_config.value 为 text 列,按 3 字节/汉字预留安全余量 */
    private const LEGAL_MAX_CHARS = 20000;

    /**
     * 综合分析默认系统提示词(后台未自定义时的回落值)
     */
    private const DEFAULT_PROMPT_SYNTHESIS = '你是一位顶级行业研究员与决策顾问,擅长把多个顶尖 AI 模型的观点交叉验证、去重存异,综合成一份严谨、可执行的结构化研报。'
        . '输出使用简体中文 Markdown,结构包含:多模型核心共识、差异化与互补视角、风险与分歧点、决策执行清单(Action Items)。'
        . '观点必须忠于原始材料,标注来源模型,不编造数据。';

    /**
     * 套餐定义(文件默认 + 后台覆盖逐项合并)
     * free 恒为开启;pro/team/flagship 后台可下架(enabled=false)
     * type: subscription 订阅(月/年付) | one_time 一次性包(积分制)
     * 多语言:返回 name_i18n/features_i18n(zh/zh-TW/en),缺失语言回落 name/features
     */
    public function plans(): array {
        $plans = (array) config('tiwen@config.plans', []);
        $saved = (array) $this->get(self::KEY_PLANS, []);
        foreach ($saved as $id => $conf) {
            $id = (string) $id;
            if ($id === '' || !is_array($conf)) {
                continue;
            }
            $plans[$id] = array_merge($plans[$id] ?? [], $conf);
        }
        foreach ($plans as $id => &$conf) {
            // 兜底:未配置过开关/权益时给默认形态,前台始终拿到完整字段
            $conf['enabled'] = $id === 'free' ? true : (bool) ($conf['enabled'] ?? true);
            if (!isset($conf['features']) || !is_array($conf['features'])) {
                $conf['features'] = [];
            }
            $conf['type'] = ($conf['type'] ?? '') === 'one_time' ? 'one_time' : 'subscription';
            if (!isset($conf['name_i18n']) || !is_array($conf['name_i18n'])) {
                $conf['name_i18n'] = [];
            }
            if (!isset($conf['features_i18n']) || !is_array($conf['features_i18n'])) {
                $conf['features_i18n'] = [];
            }
            // 缺失语言回落单语字段,保证前台任一 locale 都有完整展示
            foreach (self::PLAN_LANGS as $lang) {
                $conf['name_i18n'][$lang] = trim((string) ($conf['name_i18n'][$lang] ?? '')) ?: (string) ($conf['name'] ?? '');
                $langFeatures = $conf['features_i18n'][$lang] ?? [];
                $conf['features_i18n'][$lang] = (is_array($langFeatures) && $langFeatures) ? array_values($langFeatures) : array_values((array) $conf['features']);
            }
        }
        unset($conf);
        return $plans;
    }

    /**
     * 保存套餐定义(仅覆盖已知套餐的可编辑字段)
     * @param array $plans 形如 ['pro' => ['name' => ..., 'enabled' => ..., 'features' => [...]], 'flagship' => ['price' => ..., 'name_i18n' => [...], ...]]
     */
    public function savePlans(array $plans): void {
        $fields = ['name', 'price_monthly', 'price_yearly', 'daily_asks', 'tokens_quota', 'enabled', 'features', 'type', 'price', 'credits', 'bonus_percent', 'validity_months', 'gift_pro_months', 'gift_monthly_credits'];
        // 数值字段 => [下限, 上限]
        $intBounds = [
            'daily_asks' => [0, 100000],
            'tokens_quota' => [0, 10000000000],
            'credits' => [0, 10000000],
            'bonus_percent' => [0, 100],
            'validity_months' => [0, 120],
            'gift_pro_months' => [0, 120],
            'gift_monthly_credits' => [0, 10000000],
        ];
        $clean = [];
        foreach ($plans as $id => $conf) {
            $id = trim((string) $id);
            if ($id === '' || !is_array($conf)) {
                continue;
            }
            $row = [];
            foreach ($fields as $field) {
                if (!array_key_exists($field, $conf)) {
                    continue;
                }
                if ($field === 'name') {
                    $row[$field] = mb_substr(trim((string) $conf[$field]), 0, 50);
                } elseif ($field === 'enabled') {
                    // free 恒开启,存原值也无害,但统一规整避免脏数据
                    $row[$field] = $id === 'free' ? true : (bool) $conf[$field];
                } elseif ($field === 'features') {
                    $row[$field] = $this->normalizeFeatureLines($conf[$field]);
                } elseif ($field === 'type') {
                    $row[$field] = ($conf[$field] === 'one_time') ? 'one_time' : 'subscription';
                } elseif (isset($intBounds[$field])) {
                    $value = (int) round((float) $conf[$field]);
                    $row[$field] = max($intBounds[$field][0], min($intBounds[$field][1], $value));
                } elseif ($field === 'price_monthly' || $field === 'price_yearly' || $field === 'price') {
                    $row[$field] = max(0, round((float) $conf[$field], 2));
                }
            }
            // 多语言名称/权益:提交了的语言逐语言规整后整体保存,
            // 空串/空数组也入库(plans() 读取时空值自动回落 name/features)
            $nameMap = is_array($conf['name_i18n'] ?? null) ? $conf['name_i18n'] : [];
            $featuresMap = is_array($conf['features_i18n'] ?? null) ? $conf['features_i18n'] : [];
            $nameI18n = [];
            foreach (self::PLAN_LANGS as $lang) {
                if (array_key_exists($lang, $nameMap)) {
                    $nameI18n[$lang] = mb_substr(trim((string) $nameMap[$lang]), 0, 50);
                }
            }
            if ($nameI18n) {
                $row['name_i18n'] = $nameI18n;
            }
            $featuresI18n = [];
            foreach (self::PLAN_LANGS as $lang) {
                if (array_key_exists($lang, $featuresMap)) {
                    $featuresI18n[$lang] = $this->normalizeFeatureLines($featuresMap[$lang]);
                }
            }
            if ($featuresI18n) {
                $row['features_i18n'] = $featuresI18n;
            }
            if ($row) {
                $clean[$id] = $row;
            }
        }
        $this->set(self::KEY_PLANS, $clean);
    }

    /**
     * 权益行规整:去空行、trim、限长限量
     * @param array|string $lines 一行一条文本或行数组
     * @return array
     */
    private function normalizeFeatureLines(array|string $lines): array {
        $lines = is_array($lines) ? $lines : explode("\n", $lines);
        return array_values(array_slice(array_filter(array_map(
            fn($line) => mb_substr(trim((string) $line), 0, 100),
            $lines
        ), fn($line) => $line !== ''), 0, 30));
    }

    /**
     * 结算货币(ISO 4217 三位码大写)
     * 优先后台设置;未设置时回落 Stripe 支付模块配置,保持扣款与展示一致;再回落 USD
     */
    public function currency(): string {
        $saved = strtoupper(trim((string) $this->get(self::KEY_CURRENCY, '')));
        if (preg_match('/^[A-Z]{3}$/', $saved)) {
            return $saved;
        }
        if (function_exists('app') && app('?stripe')) {
            $stripe = strtoupper(trim((string) app('stripe')->config('currency', '')));
            if (preg_match('/^[A-Z]{3}$/', $stripe)) {
                return $stripe;
            }
        }
        return 'USD';
    }

    /**
     * 货币符号(默认取当前结算货币)
     */
    public function currencySymbol(string $code = ''): string {
        $code = strtoupper(trim($code));
        if ($code === '') {
            $code = $this->currency();
        }
        return self::CURRENCY_SYMBOLS[$code] ?? ($code . ' ');
    }

    /**
     * 保存结算货币(三位币种码,非法输入拒绝)
     */
    public function saveCurrency(string $code): bool {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            return false;
        }
        $this->set(self::KEY_CURRENCY, $code);
        return true;
    }

    /**
     * 对话系统提示词(默认空 = 不注入,保持原生对话行为)
     */
    public function chatSystemPrompt(): string {
        return trim((string) $this->get(self::KEY_PROMPT_CHAT, ''));
    }

    /**
     * 综合分析系统提示词
     */
    public function synthesisSystemPrompt(): string {
        $prompt = trim((string) $this->get(self::KEY_PROMPT_SYNTHESIS, ''));
        return $prompt !== '' ? $prompt : self::DEFAULT_PROMPT_SYNTHESIS;
    }

    /**
     * 提示词组(后台展示用,带回落默认值与是否已自定义标记)
     */
    public function prompts(): array {
        $chat = trim((string) $this->get(self::KEY_PROMPT_CHAT, ''));
        return [
            'chat' => [
                'value' => $chat,
                'default' => '',
                'customized' => $chat !== '',
            ],
            'synthesis' => [
                'value' => trim((string) $this->get(self::KEY_PROMPT_SYNTHESIS, '')),
                'default' => self::DEFAULT_PROMPT_SYNTHESIS,
                'customized' => $this->get(self::KEY_PROMPT_SYNTHESIS, null) !== null,
            ],
        ];
    }

    /**
     * 保存提示词(空串即恢复默认)
     * @param string $key chat|synthesis
     */
    public function savePrompt(string $key, string $value): bool {
        $map = ['chat' => self::KEY_PROMPT_CHAT, 'synthesis' => self::KEY_PROMPT_SYNTHESIS];
        if (!isset($map[$key])) {
            return false;
        }
        $this->set($map[$key], mb_substr(trim($value), 0, 8000));
        return true;
    }

    /**
     * 联网搜索(Tavily)配置:文件默认与后台覆盖逐项合并并规整取值范围
     * @return array{enabled: bool, api_key: string, max_results: int, search_depth: string, include_answer: bool, timeout: int, max_content_chars: int}
     */
    public function webSearch(): array {
        $defaults = (array) config('tiwen@config.web_search', []);
        $saved = (array) $this->get(self::KEY_WEB_SEARCH, []);
        $conf = array_merge([
            'enabled' => true,
            'api_key' => '',
            'max_results' => 5,
            'search_depth' => 'basic',
            'include_answer' => true,
            'timeout' => 8,
            'max_content_chars' => 1200,
        ], $defaults, $saved);
        return [
            'enabled' => (bool) $conf['enabled'],
            'api_key' => trim((string) $conf['api_key']),
            'max_results' => max(1, min(10, (int) $conf['max_results'])),
            'search_depth' => in_array($conf['search_depth'], ['basic', 'advanced'], true) ? (string) $conf['search_depth'] : 'basic',
            'include_answer' => (bool) $conf['include_answer'],
            'timeout' => max(3, min(30, (int) $conf['timeout'])),
            'max_content_chars' => max(200, min(4000, (int) $conf['max_content_chars'])),
        ];
    }

    /**
     * 联网搜索配置(后台展示用):api_key 脱敏,仅回吐是否已配置与掩码
     */
    public function webSearchForAdmin(): array {
        $conf = $this->webSearch();
        $key = $conf['api_key'];
        $masked = '';
        if ($key !== '') {
            $tail = strlen($key) > 8 ? substr($key, -4) : '';
            $head = substr($key, 0, 5);
            $masked = $head . str_repeat('*', 8) . $tail;
        }
        return [
            'enabled' => $conf['enabled'],
            'api_key_set' => $key !== '',
            'api_key_masked' => $masked,
            'max_results' => $conf['max_results'],
            'search_depth' => $conf['search_depth'],
            'include_answer' => $conf['include_answer'],
            'timeout' => $conf['timeout'],
            'max_content_chars' => $conf['max_content_chars'],
        ];
    }

    /**
     * 保存联网搜索配置(逐项覆盖;api_key 传空串表示保留原值不修改)
     * 合并到现有完整配置后整体写入,未提交字段不回落文件默认
     */
    public function saveWebSearch(array $input): bool {
        $current = $this->webSearch();
        $row = [];
        if (array_key_exists('enabled', $input)) {
            $row['enabled'] = (bool) $input['enabled'];
        }
        if (array_key_exists('api_key', $input)) {
            $key = trim((string) $input['api_key']);
            if ($key !== '') {
                $row['api_key'] = mb_substr($key, 0, 200);
            }
        }
        if (array_key_exists('max_results', $input)) {
            $row['max_results'] = max(1, min(10, (int) $input['max_results']));
        }
        if (array_key_exists('search_depth', $input)) {
            $row['search_depth'] = in_array($input['search_depth'], ['basic', 'advanced'], true) ? (string) $input['search_depth'] : 'basic';
        }
        if (array_key_exists('include_answer', $input)) {
            $row['include_answer'] = (bool) $input['include_answer'];
        }
        if (array_key_exists('timeout', $input)) {
            $row['timeout'] = max(3, min(30, (int) $input['timeout']));
        }
        if (array_key_exists('max_content_chars', $input)) {
            $row['max_content_chars'] = max(200, min(4000, (int) $input['max_content_chars']));
        }
        $this->set(self::KEY_WEB_SEARCH, array_merge($current, $row));
        return true;
    }

    /**
     * 协议文档组(后台展示用):4 篇文档 × 简体/繁体/英文
     * @return array 形如 ['register' => ['zh' => '...', 'zh-TW' => '', 'en' => '', 'customized' => bool], ...]
     */
    public function legal(): array {
        $docs = [];
        foreach (self::LEGAL_DOCS as $doc) {
            $entry = [];
            $customized = false;
            foreach (self::LEGAL_LANGS as $lang) {
                $content = $this->legalContent($doc, $lang);
                $entry[$lang] = $content;
                $customized = $customized || $content !== '';
            }
            $entry['customized'] = $customized;
            $docs[$doc] = $entry;
        }
        return $docs;
    }

    /**
     * 保存协议文档(单文档单语言,空串即下线该篇内容)
     * @param string $doc register|privacy|about|payment
     * @param string $lang zh|zh-TW|en
     */
    public function saveLegal(string $doc, string $lang, string $value): bool {
        if (!in_array($doc, self::LEGAL_DOCS, true) || !in_array($lang, self::LEGAL_LANGS, true)) {
            return false;
        }
        $this->set('tiwen_legal_' . $doc . '_' . $lang, mb_substr(trim($value), 0, self::LEGAL_MAX_CHARS));
        return true;
    }

    /**
     * 读取单篇协议文档内容(前台展示用,未配置返回空串)
     */
    public function legalContent(string $doc, string $lang): string {
        if (!in_array($doc, self::LEGAL_DOCS, true) || !in_array($lang, self::LEGAL_LANGS, true)) {
            return '';
        }
        return trim((string) $this->get('tiwen_legal_' . $doc . '_' . $lang, ''));
    }

    /**
     * 读取运营配置键(runtime config,缺省回落传入默认)
     */
    private function get(string $key, mixed $default = null): mixed {
        return xphp('config')->sysget('config.' . $key, $default);
    }

    /**
     * 写入运营配置键(同时持久化到数据库与 runtime cache)
     */
    private function set(string $key, mixed $value): void {
        // 持久化到数据库,与 admin\Setting::submit 保持一致
        $dbValue = is_array($value)
            ? json_encode($value, JSON_UNESCAPED_UNICODE)
            : (string) $value;
        db('config')->upsert([['name' => $key, 'value' => $dbValue]], 'name', ['value']);

        // 同步更新 runtime cache
        $config = cache_read('config') ?: [];
        $config[$key] = $value;
        cache_write('config', $config);
    }
}
