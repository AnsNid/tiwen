<?php

declare(strict_types=1);

namespace App\admin\Service;

use Exception;
use Psr\Container\ContainerInterface;

/**
 * 通用表格引擎
 * 基于 Config/admin.php 中 tables 声明(或 admin_config 覆盖)提供安全的列表查询与行级编辑,
 * 列/搜索/编辑范围全部走声明白名单
 */
class TableService {

    /**
     * @var array 归一化后的表格定义缓存(请求内)
     */
    private array $normalized = [];

    public function __construct(private ContainerInterface $container) {
    }

    /**
     * 查询表格数据
     */
    public function rows(array $definition, array $params): array {

        $meta = $this->normalize($definition);
        $builder = db($this->resolveTable($meta['table']));

        // 列白名单
        if ($meta['columnNames']) {
            $builder->select($meta['columnNames']);
        }

        // 关键词搜索(searchable 白名单内做 OR LIKE)
        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '' && $meta['searchable']) {
            $builder->where(function ($query) use ($meta, $keyword) {
                foreach ($meta['searchable'] as $column) {
                    $query->orWhere($column, 'LIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%');
                }
            });
        }

        // 精确过滤(仅限已声明列)
        foreach ((array) ($params['filter'] ?? []) as $column => $value) {
            if (in_array((string) $column, $meta['columnNames'], true)) {
                $builder->where((string) $column, $value);
            }
        }

        $total = (clone $builder)->count();

        // 排序(仅限已声明列, 默认主键倒序)
        $orderby = (string) ($params['orderby'] ?? '');
        $direction = strtolower((string) ($params['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        if ($orderby !== '' && in_array($orderby, $meta['columnNames'], true)) {
            $builder->orderBy($orderby, $direction);
        } elseif (in_array($meta['primary'], $this->realColumns($meta['table']), true)) {
            $builder->orderBy($meta['primary'], 'desc');
        }

        $limit = min(max((int) ($params['limit'] ?? 20), 1), 200);
        $page = max((int) ($params['page'] ?? 1), 1);
        $list = $builder->forPage($page, $limit)->get()->toArray();

        return [
            'list' => (array) $list,
            'total' => (int) $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * 新增一行(仅接受 editable 白名单内的字段)
     */
    public function create(array $definition, array $data): int {

        $meta = $this->normalize($definition);
        if (!$meta['editable']) {
            throw new Exception('该表格未开放新增/编辑');
        }

        $insert = $this->filterEditable($meta, $data);
        if (!$insert) {
            throw new Exception('没有可写入的字段');
        }

        return (int) db($this->resolveTable($meta['table']))->insertGetId($insert);
    }

    /**
     * 更新一行(仅接受 editable 白名单内的字段)
     */
    public function update(array $definition, int|string $id, array $data): void {

        $meta = $this->normalize($definition);
        if (!$meta['editable']) {
            throw new Exception('该表格未开放编辑');
        }

        $values = $this->filterEditable($meta, $data);
        if (!$values) {
            throw new Exception('没有可更新的字段');
        }

        db($this->resolveTable($meta['table']))
            ->where($meta['primary'], $id)
            ->update($values);
    }

    /**
     * 删除一行
     */
    public function delete(array $definition, int|string $id): void {

        $meta = $this->normalize($definition);
        db($this->resolveTable($meta['table']))
            ->where($meta['primary'], $id)
            ->delete();
    }

    /**
     * 数据表真实表名(#@_ 前缀替换), 名称严格校验防注入
     */
    public function resolveTable(string $raw): string {

        $raw = trim($raw);
        if (!preg_match('/^(?:#@_)?[A-Za-z0-9_]+$/', $raw)) {
            throw new Exception("数据表名非法: {$raw}");
        }

        $prefix = (string) $this->container->get('config')->get('databases.default.prefix', '');
        return str_replace('#@_', $prefix, $raw);
    }

    /**
     * 定义归一化: 列/可搜索/可编辑统一成简单结构
     */
    private function normalize(array $definition): array {

        $cacheKey = md5(json_encode($definition));
        if (isset($this->normalized[$cacheKey])) {
            return $this->normalized[$cacheKey];
        }

        $columns = [];
        foreach ((array) ($definition['columns'] ?? []) as $key => $column) {
            if (is_string($column)) {
                $columns[$column] = ['name' => $column, 'label' => $column];
            } elseif (is_array($column)) {
                $name = (string) ($column['name'] ?? (is_string($key) ? $key : ''));
                if ($name === '') {
                    continue;
                }
                $columns[$name] = [
                    'name' => $name,
                    'label' => (string) ($column['label'] ?? $name),
                ];
            }
        }
        $names = array_keys($columns);

        $intersect = function (array $declared) use ($names): array {
            return array_values(array_intersect(
                array_map('strval', $declared),
                $names
            ));
        };

        $meta = [
            'name' => (string) ($definition['name'] ?? ''),
            'app' => (string) ($definition['app'] ?? ''),
            'label' => (string) ($definition['label'] ?? ''),
            'table' => (string) ($definition['table'] ?? ''),
            'primary' => (string) ($definition['key'] ?? 'id'),
            'columns' => array_values($columns),
            'columnNames' => $names,
            'searchable' => $intersect((array) ($definition['searchable'] ?? [])),
            'editable' => $intersect((array) ($definition['editable'] ?? [])),
        ];

        if ($meta['table'] === '') {
            throw new Exception('表格未声明数据表');
        }

        $this->normalized[$cacheKey] = $meta;
        return $meta;
    }

    /**
     * 过滤出允许写入的字段并排除主键
     */
    private function filterEditable(array $meta, array $data): array {

        $values = [];
        foreach ($meta['editable'] as $column) {
            if (array_key_exists($column, $data) && $column !== $meta['primary']) {
                $values[$column] = $data[$column];
            }
        }
        return $values;
    }

    /**
     * 数据表真实存在的列(用于排序兜底判断)
     */
    private function realColumns(string $table): array {
        try {
            $rows = $this->container->get(\xphp\DbConnection\Db::class)
                ->connection('default')
                ->select("SHOW COLUMNS FROM `{$this->resolveTable($table)}`");
        } catch (\Throwable) {
            return [];
        }

        $columns = [];
        foreach ((array) $rows as $row) {
            $value = is_array($row) ? ($row['field'] ?? reset($row)) : ($row->field ?? null);
            if ($value) {
                $columns[] = (string) $value;
            }
        }
        return $columns;
    }
}
