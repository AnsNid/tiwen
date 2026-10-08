<?php

declare(strict_types=1);

namespace App\admin\Controller\Admin;

use App\admin\Authorization;
use xphp\DbConnection\Db;

/**
 * 数据库查询控制器
 * 提供数据库结构获取和AI生成SQL查询功能
 */
class Opendata extends Authorization {

    /**
     * 向量库存储键名
     */
    private const VECTOR_STORE_KEY = 'db_structure_vectors';

    /**
     * 缓存键名
     */
    private const CACHE_KEY_DB_STRUCTURE = 'fetchDatabaseStructure';

    /**
     * 文档保存地址
     */
    private const DOCUMENT_SAVE_PATH = BASE_PATH . '/runtime/opendata/';

    /**
     * 数据库
     */
    private $db = 'tenement';

    /**
     * 首页
     */
    public function index() {
        return xphp(\xphp\View\RenderInterface::class)->render(app_path('admin', 'View') . 'opendata/query.html');
    }

    /**
     * 初始化向量库
     * 收集所有表结构信息，生成向量并存入向量库
     */
    public function initVectorStore() {

        // 生成表结构文档并向量化
        try {
            $result = $this->generateTableVectors();
        } catch (\Throwable $th) {
            show_json([
                'code' => 201,
                'message' => '向量库初始化失败',
            ]);
        }

        show_json([
            'code' => 200,
            'message' => '向量库初始化成功',
            'data' => [
                'vectors_created' => count($result),
            ]
        ]);
    }

    /**
     * 处理查询请求
     * 接收用户问题，查询相关表结构，生成SQL并执行
     */
    public function query() {
        $question = $this->request->input('question');
        if (empty($question)) {
            show_json([
                'code' => 201,
                'message' => '请输入查询问题',
            ]);
        }

        // 从向量库中查询相关表结构
        try {
            $relevantTables = $this->findRelevantTables($question);
        } catch (\Throwable $th) {
            show_json([
                'code' => 201,
                'message' => $th->getMessage(),
            ]);
        }
        if (empty($relevantTables)) {
            show_json([
                'code' => 201,
                'message' => '未找到相关表结构信息',
            ]);
        }

        // 生成SQL
        $sql = $this->generateSql($question, $relevantTables);
        if (empty($sql)) {
            show_json([
                'code' => 201,
                'message' => '无法生成有效的SQL查询',
            ]);
        }

        // 检查SQL安全性
        if (!$this->isSafeQuery($sql)) {
            show_json([
                'code' => 201,
                'message' => '生成的SQL查询不安全，请重新描述您的问题',
            ]);
        }

        try {
            // 执行SQL
            $results = Db::select($sql);

            // 处理结果
            $response = $this->processResults($question, $sql, $results);

            show_json([
                'code' => 200,
                'message' => '查询成功',
                'data' => [
                    'question' => $question,
                    'sql' => $sql,
                    'response' => $response,
                    'relevantTables' => $relevantTables,
                ],
            ]);
        } catch (\Exception $e) {
            isJsonException($e);
            show_json([
                'code' => 201,
                'message' => '查询执行失败: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 生成表结构向量并存储
     * @param array $dbStructure 数据库结构
     * @return array 生成结果
     */
    private function generateTableVectors() {

        if (!is_dir(self::DOCUMENT_SAVE_PATH)) {
            mkdir(self::DOCUMENT_SAVE_PATH, 0755, true);
        }

        // 目录下的 md 文件, 逐个解析
        $mdFiles = glob(self::DOCUMENT_SAVE_PATH . '*.md');
        if (empty($mdFiles)) {
            throw new \Exception('未找到 md 文档');
        }

        $vectors = [];

        $tablePrefix = config('databases.default.prefix');
        foreach ($mdFiles as $mdFile) {
            $document = file_get_contents($mdFile);
            $document = str_replace('#@_', $tablePrefix, $document);
            // 调用OpenAI生成向量
            $vector = $this->generateEmbedding($document);
            if ($vector) {
                $vectors[] = [
                    'vector' => $vector,
                    'document' => $document,
                ];
                file_put_contents(self::DOCUMENT_SAVE_PATH . basename($mdFile, '.md') . '.vector', $vector);
            }
        }
        file_put_contents(self::DOCUMENT_SAVE_PATH . 'vectors.json', json_encode($vectors));
        return $vectors;
    }

    /**
     * 生成文本嵌入向量
     * @param string $text 文本内容
     * @return array|null 向量数组
     */
    private function generateEmbedding($text) {
        try {
            $response = app('openai')->embeddings()->create([
                'model' => 'text-embedding-ada-002',
                'input' => $text,
            ]);

            return $response->embeddings[0]->embedding;
        } catch (\Exception $e) {
            // 记录错误日志
            echo ('生成嵌入向量失败: ' . $e->getMessage());
            return null;
        }
    }


    /**
     * 查找与问题相关的表结构
     * @param string $question 用户问题
     * @param int $limit 返回的最大表数量
     * @return array 相关表结构信息
     */
    private function findRelevantTables($question, $limit = 5) {

        // 获取所有表的向量
        $vectorsJson = file_get_contents(self::DOCUMENT_SAVE_PATH . 'vectors.json');
        if (!$vectorsJson) {
            // 返回错误 
            throw new \Exception('未找到表结构向量数据');
        }

        // 获取问题的向量表示
        $questionVector = $this->generateEmbedding($question);
        if (!$questionVector) {
            return [];
        }
        $vectors = json_decode($vectorsJson, true);
        // 计算相似度并排序
        $similarities = [];
        foreach ($vectors as $tableData) {
            $similarity = $this->cosineSimilarity($questionVector, $tableData['vector']);
            $similarities[] = [
                'similarity' => $similarity,
                'document' => $tableData['document']
            ];
        }

        // 按相似度降序排序
        uasort($similarities, function ($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        // 取前N个最相关的表
        return array_slice($similarities, 0, $limit, true);
    }

    /**
     * 计算余弦相似度
     * @param array $vec1 向量1
     * @param array $vec2 向量2
     * @return float 相似度
     */
    private function cosineSimilarity($vec1, $vec2) {
        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        foreach ($vec1 as $i => $val1) {
            $dotProduct += $val1 * $vec2[$i];
            $magnitude1 += $val1 * $val1;
            $magnitude2 += $vec2[$i] * $vec2[$i];
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 * $magnitude2 == 0) {
            return 0;
        }

        return $dotProduct / ($magnitude1 * $magnitude2);
    }

    /**
     * 生成SQL查询
     * @param string $question 用户问题
     * @param array $relevantTables 相关表结构
     * @return string 生成的SQL查询
     */
    private function generateSql($question, $relevantTables) {
        // 准备表结构信息
        $tableDocuments = [];
        foreach ($relevantTables as $tableData) {
            $tableDocuments[] = $tableData['document'];
        }

        $tableStructureInfo = implode("\n\n", $tableDocuments);

        // 构建提示
        $prompt = <<<EOT
你是一个SQL专家，请根据以下表结构信息和用户问题生成一个有效的SQL查询。
只返回SQL语句，不要包含任何解释或其他内容。

表结构信息:
$tableStructureInfo

用户问题: $question

请生成一个能够回答上述问题的SQL查询:
EOT;

        try {
            $response = app('openai')->chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => '你是一个SQL专家，只返回SQL语句，不要包含任何解释或其他内容。'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.3,
            ]);

            $sql = trim($response->choices[0]->message->content);

            // 移除可能的代码块标记
            $sql = preg_replace('/^```sql\s*|\s*```$/i', '', $sql);

            return $sql;
        } catch (\Exception $e) {
            echo ('生成SQL失败: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 处理查询结果
     * @param string $question 用户问题
     * @param string $sql 执行的SQL
     * @param array $results 查询结果
     * @return string 处理后的响应
     */
    private function processResults($question, $sql, $results) {
        if (empty($results)) {
            return "没有找到相关数据。";
        }

        // 将结果转换为JSON
        $resultsJson = json_encode($results, JSON_UNESCAPED_UNICODE);

        // 构建提示
        $prompt = <<<EOT
用户问题: $question

执行的SQL查询: $sql

查询结果: $resultsJson

请根据上述查询结果，以自然语言回答用户的问题。可以使用以下格式：
1. 文字描述：简明扼要地总结查询结果
2. 表格：如果数据适合表格展示，请使用Markdown表格格式
3. 图表建议：如果数据适合可视化，请推荐合适的图表类型（如柱状图、折线图、饼图等）并返回echarts配置代码 option = {}
4. 关键发现：突出显示数据中的重要趋势或异常值

请确保回答全面、准确，并根据数据特点选择最合适的展示方式。
EOT;

        try {
            $response = app('openai')->chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => '你是一个数据分析专家，擅长将数据转化为有洞察力的分析结果。'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            return $response->choices[0]->message->content;
        } catch (\Exception $e) {
            echo ('处理结果失败: ' . $e->getMessage());

            // 如果AI处理失败，返回原始数据
            return "查询结果：\n```json\n" . $resultsJson . "\n```";
        }
    }

    /**
     * 检查SQL语句是否安全
     * 只允许SELECT语句，禁止其他可能修改数据的操作
     * @param string $sql SQL语句
     * @return bool
     */
    private function isSafeQuery($sql) {
        // 移除SQL末尾的分号
        $sql = rtrim(trim($sql), ';');

        // 转换为小写进行检查
        $lowerSql = strtolower($sql);

        // 只允许以SELECT开头的语句
        if (!preg_match('/^\s*select\b/i', $lowerSql)) {
            return false;
        }

        // 禁止包含以下危险关键字，但需要更智能地检测
        $dangerousKeywords = [
            'insert ',
            'update ',
            'delete ',
            'drop ',
            'alter ',
            'truncate ',
            'create ',
            'replace ',
            'exec ',
            'execute ',
            'into outfile',
            'load_file',
            'benchmark(',
            'sleep(',
            'information_schema.',
            'sys.',
            'mysql.',
            'performance_schema.'
        ];

        foreach ($dangerousKeywords as $keyword) {
            // 使用更精确的匹配方式，避免误判
            if (strpos($lowerSql, $keyword) !== false) {
                // 排除一些常见的误判情况
                if ($keyword === 'update ' && strpos($lowerSql, 'last_update') !== false) {
                    continue;
                }
                if ($keyword === 'delete ' && strpos($lowerSql, 'deleted') !== false) {
                    continue;
                }
                // 特别处理FROM_UNIXTIME函数
                if ($keyword === 'from_unixtime' || strpos($lowerSql, 'from_unixtime') !== false) {
                    continue;
                }

                return false;
            }
        }

        // 检查是否包含多条SQL语句（除了末尾的分号外）
        // 先移除字符串字面量，避免字符串中的分号干扰判断
        $noStrings = preg_replace('/([\'"`]).*?\\1/s', '', $lowerSql);
        // 检查是否有分号（表示多条语句）
        if (strpos($noStrings, ';') !== false) {
            return false;
        }

        return true;
    }
}
