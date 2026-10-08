<?php

namespace App\tiwen\Controller\Api;

use App\tiwen\Service\AttachmentService;
use App\tiwen\Service\ChatService;
use App\tiwen\Service\MemberService;
use App\tiwen\Service\SessionService;
use App\tiwen\Service\SettingService;
use App\tiwen\Service\StripeService;
use App\tiwen\Service\SynthesisService;
use App\tiwen\Service\WebSearchService;
use xphp\HttpServer\Annotation\Middleware;
use App\tiwen\Middleware\AuthTokenMiddleware;

/**
 * 用户接口（需鉴权）
 * 会话/轮次/卡片、多模型提问、分析综合、用量统计
 */
#[Middleware(AuthTokenMiddleware::class)]
trait UserTrait {

    use Responses;

    /**
     * 当前用户档案(套餐 + 配额 + 订阅状态)
     */
    public function me() {
        return $this->ok((new MemberService())->profile($this->uid()));
    }

    /**
     * 会话列表(含轮次与卡片)
     */
    public function sessions() {
        return $this->ok((new SessionService())->list($this->uid()));
    }

    /**
     * 创建会话
     * @param string $title 标题(选填,默认取首个问题)
     * @param string $summary 摘要(选填)
     */
    public function sessionCreate() {
        $result = (new SessionService())->create($this->uid(), (string) input('title', ''), (string) input('summary', ''));
        return $this->ok($result, '会话已创建');
    }

    /**
     * 会话详情
     * @param int $sessionId 会话ID
     */
    public function sessionDetail() {
        $detail = (new SessionService())->detail($this->uid(), (int) input('sessionId', 0));
        if (!$detail) {
            return $this->fail('会话不存在');
        }
        return $this->ok($detail);
    }

    /**
     * 重命名会话
     * @param int $sessionId 会话ID
     * @param string $title 新标题
     */
    public function sessionRename() {
        $ok = (new SessionService())->rename($this->uid(), (int) input('sessionId', 0), (string) input('title', ''));
        return $ok ? $this->ok(null, '已重命名') : $this->fail('会话不存在或标题为空');
    }

    /**
     * 删除会话(级联删除轮次与卡片)
     * @param int $sessionId 会话ID
     */
    public function sessionDelete() {
        $ok = (new SessionService())->remove($this->uid(), (int) input('sessionId', 0));
        return $ok ? $this->ok(null, '会话已删除') : $this->fail('会话不存在');
    }

    /**
     * 保存研报编辑区草稿
     * @param int $sessionId 会话ID
     * @param string $draft 草稿内容
     */
    public function sessionDraft() {
        $ok = (new SessionService())->saveDraft($this->uid(), (int) input('sessionId', 0), (string) input('draft', ''));
        return $ok ? $this->ok(null) : $this->fail('会话不存在');
    }

    /**
     * 设置激活轮次
     * @param int $sessionId 会话ID
     * @param int $roundIndex 轮次序号
     */
    public function sessionRound() {
        $ok = (new SessionService())->setActiveRound($this->uid(), (int) input('sessionId', 0), (int) input('roundIndex', 0));
        return $ok ? $this->ok(null) : $this->fail('会话不存在');
    }

    /**
     * 创建提问轮次(配额校验:免费版每日限次,按并发模型数计,如 4 模型一次 = 4 次)
     * @param int $sessionId 会话ID
     * @param string $question 本轮问题
     * @param array|string $models 本次并发的模型 id 列表(逗号分隔或数组)
     * @param bool $webSearch 是否启用联网搜索(勾选后对本轮问题预检索实时网页)
     */
    public function roundCreate() {
        $uid = $this->uid();
        $members = new MemberService();

        // 解析并发模型数(至少 1,至多 6)
        $rawModels = $this->request->input('models', '');
        $modelList = is_array($rawModels) ? $rawModels : explode(',', (string) $rawModels);
        $modelCount = count(array_filter(array_map('trim', $modelList), fn($m) => $m !== ''));
        $modelCount = max(1, min($modelCount, 6));

        $quota = $members->checkQuota($uid, $modelCount);
        if (!$quota['ok']) {
            // data 携带 plan/remaining 等真实额度,前端弹窗据此给出"减少并发模型 (N → M)"的精确建议
            return $this->fail($quota['message'], 402, $quota['data'] ?? null);
        }
        $result = (new SessionService())->createRound($uid, (int) input('sessionId', 0), (string) input('question', ''));
        if (!$result['ok']) {
            return $this->fail($result['message']);
        }

        // 附件文本暂存 Redis(不落库),供本轮各模型流式对话注入参考
        $attachContent = trim((string) input('attachmentContent', ''));
        if ($attachContent !== '') {
            (new AttachmentService())->store(
                (int) $result['round_id'],
                trim((string) input('attachmentName', '')),
                $attachContent
            );
        }

        // 联网搜索:勾选「智能搜索」且后台配置就绪时,对本轮问题预检索一次实时网页,
        // 结果按轮次暂存 Redis,供该轮全部并发模型复用(避免每模型各查一次);检索失败静默降级
        if (filter_var(input('webSearch', false), FILTER_VALIDATE_BOOLEAN)) {
            (new WebSearchService())->searchAndStore((int) $result['round_id'], (string) input('question', ''));
        }

        $members->recordAsk($uid, $modelCount);
        // 登记本轮预扣信息,供该轮全部模型失败时返还免费额度
        $members->stashRoundCharge((int) $result['round_id'], $uid, $modelCount);
        return $this->ok([
            'roundId' => $result['round_id'],
            'roundIndex' => $result['round_index'],
            'chargedAsks' => $modelCount,
        ]);
    }

    /**
     * 上传对话附件文档(仅提取纯文本,不存储原文件)
     * 支持 txt/md/csv/json/代码文本与 docx(≤2MB),暂不支持 pdf/doc
     * @doc(method: 'post', description: '上传文档并提取纯文本,提问时随轮次暂存,供各模型参考', tag: "对话")
     * @param file $file 文档文件
     * @return array {code: 200, data: {name: string, content: string, truncated: bool}}
     */
    public function upload() {
        $file = $this->request->file('file');
        if (!is_object($file)) {
            return $this->fail('请选择要上传的文档');
        }
        $name = (string) (method_exists($file, 'getClientFilename') ? $file->getClientFilename() : '');
        if (method_exists($file, 'getError') && (int) $file->getError() !== UPLOAD_ERR_OK) {
            return $this->fail('文件上传失败,请重试');
        }
        $result = (new AttachmentService())->extract($file, $name);
        if (!$result['ok']) {
            return $this->fail($result['message']);
        }
        return $this->ok($result['data'], '附件解析成功');
    }

    /**
     * SSE 流式对话（单模型单连接，前端多模型各开一条连接并行比对）
     * 服务端自动携带会话多轮上下文,流完成落库卡片并核算 Token 用量
     * @param int $sessionId 会话ID
     * @param int $roundId 轮次ID
     * @param string $model 模型 id
     * @param string $provider 供应商（选填，默认按模型自动解析）
     * @param float $temperature 采样温度（选填）
     * @return string
     */
    public function chat() {
        $uid = $this->uid();
        $sessionId = (int) input('sessionId', 0);
        $roundId = (int) input('roundId', 0);

        // 归属校验:轮次必须属于当前用户
        $round = m('tiwen_rounds')->where('id', $roundId)->one();
        $session = $round ? m('tiwen_sessions')->where('id', $sessionId)->where('uid', $uid)->one() : null;
        if (!$round || !$session || (int) $round['session_id'] !== $sessionId) {
            $this->response->setHeader('Content-Type', 'text/event-stream;charset=utf-8');
            $this->response->write("event: fail\ndata: " . json_encode(['message' => '会话或轮次不存在'], JSON_UNESCAPED_UNICODE) . "\n\n");
            return '';
        }

        $config = config('tiwen@config', []);
        $context = (new SessionService())->buildContext(
            $uid,
            $sessionId,
            $roundId,
            (int) ($config['context_rounds'] ?? 2),
            (int) ($config['context_card_max_chars'] ?? 2000)
        );
        // 历史 + 附件 + 联网检索 + 本轮问题合并为单条 user 消息,避免模型把上下文误判为待答问题
        $question = (string) ($round['question'] ?? '');
        $attachment = (new AttachmentService())->load($roundId);
        $attachBlock = $attachment !== null
            ? "【用户上传的参考资料(附件 {$attachment['name']},节选)】\n{$attachment['content']}"
            : '';
        // 联网搜索上下文(roundCreate 阶段预检索并暂存,本轮全部模型复用)
        $webRaw = (new WebSearchService())->load($roundId);
        $webBlock = $webRaw !== ''
            ? "【联网搜索结果(实时网页信息,作答时请优先采信并在文末标注来源链接)】\n{$webRaw}"
            : '';

        $prefixBlocks = array_values(array_filter([
            $context !== '' ? "【会话历史记录,仅供理解上下文】\n{$context}" : '',
            $attachBlock,
            $webBlock,
        ], fn($b) => $b !== ''));
        if ($prefixBlocks) {
            $question = implode("\n\n", $prefixBlocks) . "\n\n【请结合以上信息回答本轮新问题】\n{$question}";
        }

        (new ChatService())->stream($this->response, [
            'model' => (string) input('model', ''),
            'prompt' => $question,
            'system' => (new SettingService())->chatSystemPrompt(),
            'temperature' => input('temperature', null),
            'provider' => (string) input('provider', ''),
            'uid' => $uid,
            'session_id' => $sessionId,
            'round_id' => $roundId,
        ]);
        return '';
    }

    /**
     * 勾选/取消勾选卡片
     * @param int $cardId 卡片ID
     * @param bool $checked 目标状态(选填,默认取反)
     */
    public function cardCheck() {
        $checked = input('checked', null);
        $ok = (new SessionService())->toggleCard($this->uid(), (int) input('cardId', 0), $checked === null ? null : (bool) $checked);
        return $ok ? $this->ok(null) : $this->fail('卡片不存在');
    }

    /**
     * 删除卡片
     * @param int $cardId 卡片ID
     */
    public function cardDelete() {
        $ok = (new SessionService())->deleteCard($this->uid(), (int) input('cardId', 0));
        return $ok ? $this->ok(null, '卡片已删除') : $this->fail('卡片不存在');
    }

    /**
     * SSE 流式分析综合
     * 取会话内勾选卡片交叉比对,生成结构化研报(结果由前端写入编辑区草稿)
     * @param int $sessionId 会话ID
     * @return string
     */
    public function synthesize() {
        (new SynthesisService())->stream($this->response, $this->uid(), (int) input('sessionId', 0));
        return '';
    }

    /**
     * 用量统计(模型用量占比、近 14 天趋势、会话数)
     */
    public function usage() {
        $usage = (new SessionService())->usage($this->uid());
        $usage['invoices'] = app('?stripe') ? (new StripeService())->invoices($this->uid()) : [];
        return $this->ok($usage);
    }
}
