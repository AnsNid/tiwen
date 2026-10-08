<?php

namespace App\admin\lib;

/**
 * 注释解析
 * Class ParseComment
 */
class ParseComment {
    /**
     * 将注释按行解析并以数组格式返回
     *
     * @param string $comment - 原始注释字符串
     * @return bool|array
     */
    public function parseCommentToArray(string $comment): array {
        $comments = [];
        if (empty($comment)) {
            return $comments;
        }
        // 获取注释
        if (preg_match('#^/\*\*(.*)\*/#s', $comment, $matches) === false) {
            return $comments;
        }
        $matches = trim($matches[1]);
        // 按行分割注释
        if (preg_match_all('#^\s*\*(.*)#m', $matches, $lines) === false) {
            return $comments;
        }
        $comments = array_values(array_filter(array_map('trim', $lines[1])));
        $commentParams = [];
        $name = null;
        // 去除无用的注释
        foreach ($comments as $k => $v) {
            if (strpos($v, '@') !== 0) {
                if (is_null($name)) {
                    $name = $v;
                }
                continue;
            }
            $_parse = $this->_parseCommentLine($v);
            if (!$_parse) {
                continue;
            }

            $_type = $_parse['type'];
            unset($_parse['type']);
            if (in_array($_type, ['param', 'code', 'return', 'header'])) {
                $commentParams[$_type][] = $_parse;
            } else {
                $commentParams[$_type] = $_parse['content'] ?? '';
            }
        }
        return array_merge(['name' => $name ?: ($comments[0] ?? '')], $commentParams);
    }

    /**
     * 解析注释中的参数
     *
     * @param string $line - 注释行
     * @return bool|array - 解析后的数组（解析失败返回false）
     */
    private function _parseCommentLine(string $line): array {
        $line = explode(' ', substr($line, 1));
        $class = new ParseLine();
        $action = 'parseLine' . $this->underlineToHump($line[0]);
        if (!method_exists($class, $action)) {
            $action = 'parseLineTitle';
        }
        return $class->$action($line);
    }

    /**
     * 下划线转驼峰
     */
    private function underlineToHump(string $str): string {
        return str_replace('_', '', ucwords($str, '_'));
    }
}
