<?php

namespace App\admin\lib;

/**
 * 注释行解析
 * Class ParseLine
 */
class ParseLine {
    /**
     * 解析标题行
     */
    public function parseLineTitle(array $line): array {
        return [
            'type' => $line[0] ?? '',
            'content' => implode(' ', array_slice($line, 1))
        ];
    }

    /**
     * 解析参数行
     */
    public function parseLineParam(array $line): array {
        return [
            'type' => 'param',
            'var_type' => $line[1] ?? '',
            'var_name' => $line[2] ?? '',
            'description' => implode(' ', array_slice($line, 3))
        ];
    }

    /**
     * 解析返回值行
     */
    public function parseLineReturn(array $line): array {
        return [
            'type' => 'return',
            'var_type' => $line[1] ?? '',
            'description' => implode(' ', array_slice($line, 2))
        ];
    }

    /**
     * 解析代码行
     */
    public function parseLineCode(array $line): array {
        return [
            'type' => 'code',
            'code' => $line[1] ?? '',
            'description' => implode(' ', array_slice($line, 2))
        ];
    }

    /**
     * 解析头部行
     */
    public function parseLineHeader(array $line): array {
        return [
            'type' => 'header',
            'name' => $line[1] ?? '',
            'value' => implode(' ', array_slice($line, 2))
        ];
    }

    /**
     * 解析日志标记
     */
    public function parseLineLog(array $line): array {
        return [
            'type' => 'log',
            'content' => implode(' ', array_slice($line, 1))
        ];
    }

    /**
     * 解析作者
     */
    public function parseLineAuthor(array $line): array {
        return [
            'type' => 'author',
            'content' => implode(' ', array_slice($line, 1))
        ];
    }

    /**
     * 解析版本
     */
    public function parseLineVersion(array $line): array {
        return [
            'type' => 'version',
            'content' => implode(' ', array_slice($line, 1))
        ];
    }

    /**
     * 解析时间
     */
    public function parseLineSince(array $line): array {
        return [
            'type' => 'since',
            'content' => implode(' ', array_slice($line, 1))
        ];
    }
}
