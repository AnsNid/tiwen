<?php

declare(strict_types=1);

namespace App\tiwen\Service;

/**
 * 对话附件服务
 * 上传文档仅提取纯文本(不存储原文件),按轮次暂存 Redis 供流式对话注入 prompt
 */
class AttachmentService {

    /** 单附件文本上限(字),超出截断 */
    public const MAX_CHARS = 12000;
    /** 上传文件大小上限(2MB) */
    public const MAX_BYTES = 2 * 1024 * 1024;
    /** 附件暂存有效期(7 天,覆盖追问与重试场景) */
    private const TTL = 604800;

    private const KEY_ROUND = 'tiwen:round:attach:';

    private const TEXT_EXTS = [
        'txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'log', 'html', 'htm', 'xml',
        'yml', 'yaml', 'ini', 'sql', 'py', 'js', 'ts', 'jsx', 'tsx', 'php', 'java',
        'c', 'h', 'cpp', 'hpp', 'go', 'rs', 'rb', 'sh', 'css',
    ];

    /**
     * 前端 file input 的 accept 约束(与 TEXT_EXTS + docx 对应)
     */
    public const ACCEPT = '.txt,.md,.markdown,.csv,.tsv,.json,.log,.html,.htm,.xml,.yml,.yaml,.ini,.sql,.py,.js,.ts,.jsx,.tsx,.php,.java,.c,.h,.cpp,.go,.rs,.rb,.sh,.css,.docx';

    /**
     * 从上传文件提取文本
     * @param object $file Swoole UploadedFile
     * @return array{ok: bool, message: string, data?: array{name: string, content: string, truncated: bool}}
     */
    public function extract(object $file, string $name): array {
        $size = (int) (method_exists($file, 'getSize') ? $file->getSize() : 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return ['ok' => false, 'message' => '文件大小不能超过 2MB'];
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'doc' || $ext === 'pdf') {
            return ['ok' => false, 'message' => '暂不支持 ' . strtoupper($ext) . ',请转换为 txt 或 docx 后上传'];
        }
        if (!in_array($ext, self::TEXT_EXTS, true) && $ext !== 'docx') {
            return ['ok' => false, 'message' => '不支持的文件类型,支持 txt/md/csv/json/代码文本与 docx'];
        }

        // 框架 UploadedFile 继承 SplFileInfo,临时文件直接读取即可;
        // 不用 moveTo()(其返回 void、失败抛异常,且会消耗掉 Swoole 的上传临时文件)
        $tmp = method_exists($file, 'getTmpFile') ? $file->getTmpFile() : (method_exists($file, 'getPathname') ? (string) $file->getPathname() : '');
        if (!is_string($tmp) || $tmp === '' || !is_file($tmp)) {
            return ['ok' => false, 'message' => '文件读取失败,请重试'];
        }
        $content = $ext === 'docx' ? $this->extractDocx($tmp) : (string) file_get_contents($tmp);
        $content = trim(str_replace("\r\n", "\n", $content));
        if ($content === '') {
            return ['ok' => false, 'message' => '未能从文件中提取到文本内容'];
        }
        $truncated = mb_strlen($content) > self::MAX_CHARS;
        return ['ok' => true, 'message' => '', 'data' => [
            'name' => mb_substr($name, 0, 100),
            'content' => mb_substr($content, 0, self::MAX_CHARS),
            'truncated' => $truncated,
        ]];
    }

    /**
     * 暂存轮次附件文本(本轮各模型 chat 流式调用时注入)
     */
    public function store(int $roundId, string $name, string $content): void {
        $content = trim($content);
        if ($roundId <= 0 || $content === '') {
            return;
        }
        xphp('redis')->setex(
            self::KEY_ROUND . $roundId,
            self::TTL,
            json_encode([
                'name' => mb_substr($name, 0, 100),
                'content' => mb_substr($content, 0, self::MAX_CHARS),
            ], JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 读取轮次附件文本(不存在/已过期返回 null)
     */
    public function load(int $roundId): ?array {
        if ($roundId <= 0) {
            return null;
        }
        $raw = (string) xphp('redis')->get(self::KEY_ROUND . $roundId);
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['content'])) {
            return null;
        }
        return ['name' => (string) ($data['name'] ?? 'attachment'), 'content' => (string) $data['content']];
    }

    /**
     * 提取 docx 正文(word/document.xml 段落转行后去标签)
     */
    private function extractDocx(string $path): string {
        if (!class_exists('ZipArchive')) {
            return '';
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === '') {
            return '';
        }
        $xml = str_replace(['</w:p>', '<w:br/>', '<w:tab/>'], ["\n", "\n", "\t"], $xml);
        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
