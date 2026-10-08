<?php

declare(strict_types=1);

namespace App\tiwen\Service;

/**
 * 联网搜索服务(Tavily)
 * 前台勾选「智能搜索」时,对本轮问题预检索实时网页,格式化为上下文块注入各模型 prompt。
 * 每轮仅检索一次(roundCreate 阶段),结果按 roundId 暂存 Redis,供该轮全部并发模型复用,
 * 避免 N 个模型各查一次造成的重复计费与延迟。
 */
class WebSearchService {

    /** Tavily Search API 端点 */
    private const ENDPOINT = 'https://api.tavily.com/search';
    /** 检索结果暂存有效期(7 天,覆盖追问与重试场景) */
    private const TTL = 604800;
    /** 单条网页正文注入上限(与附件同量级,防 prompt 过长) */
    private const MAX_BLOCK_CHARS = 12000;

    private const KEY_ROUND = 'tiwen:round:websearch:';

    public function __construct(private SettingService $settings = new SettingService()) {
    }

    /**
     * 联网搜索是否可用(总开关开启且已配置 api_key)
     */
    public function available(): bool {
        $conf = $this->settings->webSearch();
        return $conf['enabled'] && $conf['api_key'] !== '';
    }

    /**
     * 对问题执行 Tavily 检索并暂存到轮次(检索失败/无结果则不暂存)
     * @return bool 是否成功检索到内容并暂存
     */
    public function searchAndStore(int $roundId, string $query): bool {
        $query = trim($query);
        if ($roundId <= 0 || $query === '' || !$this->available()) {
            return false;
        }
        $block = $this->search($query);
        if ($block === '') {
            return false;
        }
        xphp('redis')->setex(self::KEY_ROUND . $roundId, self::TTL, $block);
        return true;
    }

    /**
     * 读取轮次联网搜索上下文(不存在/已过期返回空串)
     */
    public function load(int $roundId): string {
        if ($roundId <= 0) {
            return '';
        }
        return (string) xphp('redis')->get(self::KEY_ROUND . $roundId);
    }

    /**
     * 调用 Tavily Search API,把结果格式化为可注入 prompt 的上下文块
     * @return string 上下文块(检索失败或无结果返回空串,调用方据此降级为普通对话)
     */
    public function search(string $query): string {
        $conf = $this->settings->webSearch();
        $query = trim($query);
        if ($conf['api_key'] === '' || $query === '') {
            return '';
        }
        try {
            $client = guzzle([
                'timeout' => $conf['timeout'],
                'connect_timeout' => 5,
                'http_errors' => false,
                'verify' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $conf['api_key'],
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ]);
            $resp = $client->post(self::ENDPOINT, ['json' => [
                'query' => mb_substr($query, 0, 400),
                'search_depth' => $conf['search_depth'],
                'max_results' => $conf['max_results'],
                'include_answer' => $conf['include_answer'],
                'topic' => 'general',
            ]]);
            if ($resp->getStatusCode() !== 200) {
                return '';
            }
            $data = json_decode((string) $resp->getBody(), true);
            return is_array($data) ? $this->format($data, $conf) : '';
        } catch (\Throwable $e) {
            // 检索失败静默降级:不阻断正常对话,由调用方按空结果处理
            return '';
        }
    }

    /**
     * 把 Tavily 响应格式化为带来源链接的上下文块
     */
    private function format(array $data, array $conf): string {
        $lines = [];
        $answer = trim((string) ($data['answer'] ?? ''));
        if ($conf['include_answer'] && $answer !== '') {
            $lines[] = '检索摘要:' . $answer;
        }
        $maxChars = (int) $conf['max_content_chars'];
        $idx = 0;
        foreach ((array) ($data['results'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $title = trim((string) ($r['title'] ?? ''));
            $url = trim((string) ($r['url'] ?? ''));
            $content = trim((string) ($r['content'] ?? ''));
            if ($url === '' && $content === '') {
                continue;
            }
            $idx++;
            $head = '[' . $idx . '] ' . ($title !== '' ? $title : $url);
            $source = $url !== '' ? "\n来源: " . $url : '';
            $body = $content !== '' ? "\n" . mb_substr($content, 0, $maxChars) : '';
            $lines[] = $head . $source . $body;
        }
        if ($lines === []) {
            return '';
        }
        return mb_substr(implode("\n\n", $lines), 0, self::MAX_BLOCK_CHARS);
    }
}
