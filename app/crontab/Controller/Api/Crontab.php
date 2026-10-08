<?php

namespace App\crontab\Controller\Api;

// 中间件
use xphp\HttpServer\Annotation\Middleware;
use App\crontab\Middleware\UserCorsMiddleware;
use App\crontab\Parser;
use App\crontab\Service\AnnotationTaskService;
use App\crontab\Service\CrontabTaskService;

/**
 * 定时任务控制器
 */
#[Middleware(UserCorsMiddleware::class)]

trait Crontab {
    /**
     * 查看日志 /getLog
     * @param string $name 任务名称
     * @param int $lines 读取行数, 默认100
     * @param int $offset 跳过行数, 默认0
     */
    public function getLog() {

        $name = input('name', '', 'trim');
        $lines = (int) input('lines', 100);
        $offset = (int) input('offset', 0);

        if (empty($name)) {
            showmsg('请输入任务名称', 201);
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $name);
        $logFile = runtime_path('logs/crontab') . $safeName . '.log';

        if (!file_exists($logFile)) {
            show_json([
                'code' => 200,
                'data' => [
                    'name' => $name,
                    'lines' => [],
                    'total' => 0,
                ],
            ]);
        }

        // 用 SplFileObject 从文件末尾倒序读取，避免大文件撑爆内存
        $file = new \SplFileObject($logFile, 'r');
        $file->seek(PHP_INT_MAX);
        $total = $file->key();
        $file = null;

        $skip = $offset + $lines;
        $start = max(0, $total - $skip);
        $count = min($lines, $total - $offset);

        // 空行原样保留,保证返回行数与 total/分页一致
        $selected = [];
        $file = new \SplFileObject($logFile, 'r');
        $file->seek($start);
        for ($i = 0; $i < $count; $i++) {
            $line = $file->current();
            if ($line === false) {
                break;
            }
            $selected[] = rtrim($line, "\r\n");
            $file->next();
        }
        $file = null;

        $selected = array_reverse($selected);

        show_json([
            'code' => 200,
            'data' => [
                'name' => $name,
                'lines' => array_values($selected),
                'total' => $total,
            ],
        ]);
    }


    /**
     * 获取任务列表 /getTaskList
     * @param int $status 状态筛选 1: 启用, 0: 暂停, 不传查全部
     * @param string $keyword 任务名称关键词
     * @param int $page 页码, 默认1
     * @param int $limit 每页条数, 默认20, 最大100
     */
    public function getTaskList() {

        $limit = min(max((int) input('limit', 20), 1), 100);
        $page = max((int) input('page', 1), 1);
        $status = input('status', null);
        $keyword = input('keyword', '', 'trim');

        $model = m('crontab_task')->where('status', 'in', [0, 1]);
        if ($status !== null && $status !== '') {
            $status = (int) $status;
            if (!in_array($status, [0, 1], true)) {
                showmsg('状态参数错误', 201);
            }
            $model->where('status', $status);
        }
        if ($keyword !== '') {
            $model->where('name', 'like', '%' . $keyword . '%');
        }

        $total = $model->count();
        $list = $model->order('id desc')->page($page, $limit)->get()->toArray();

        // 注解任务:未被同名数据库行接管的随列表展示,已接管的以数据库行形式展示(带 is_annotation 标记)
        $annotationService = $this->container->get(AnnotationTaskService::class);
        $databaseNames = $this->container->get(CrontabTaskService::class)->getAllNames();
        $annotationNames = $annotationService->getNames();

        foreach ($list as &$row) {
            $row['is_annotation'] = isset($annotationNames[$row['name']]) ? 1 : 0;
        }
        unset($row);

        $annotations = [];
        $visibleAnnotations = [];
        foreach ($annotationService->list($databaseNames) as $item) {
            if ($item['overridden']) {
                continue;
            }
            $visibleAnnotations[] = $item;
            if ($status !== null && $status !== '' && (int) $item['status'] !== (int) $status) {
                continue;
            }
            if ($keyword !== '' && stripos((string) $item['name'], $keyword) === false) {
                continue;
            }
            $annotations[] = $item;
        }

        // 汇总统计不受筛选影响, 供前端 KPI 卡片使用(口径含注解任务)
        $stats = [
            'total' => m('crontab_task')->where('status', 'in', [0, 1])->count() + count($visibleAnnotations),
            'active' => m('crontab_task')->where('status', 1)->count() + count(array_filter($visibleAnnotations, fn ($item) => (int) $item['status'] === 1)),
            'paused' => m('crontab_task')->where('status', 0)->count(),
            'annotation' => count($visibleAnnotations),
        ];

        show_json([
            'code' => 200,
            'data' => [
                'list' => $list,
                'annotations' => $annotations,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'stats' => $stats,
            ],
        ]);
    }

    /**
     * 获取任务详情 /getTaskDetail
     * @param int $id 任务ID
     */
    public function getTaskDetail() {

        $id = (int) input('id', 0);
        if (!$id) {
            showmsg('参数错误', 201);
        }

        $task = m('crontab_task')->where(['id' => $id, 'status' => [0, 1]])->one();
        if (!$task) {
            showmsg('任务不存在', 201);
        }

        showmsg('success', 200, $task);
    }

    /**
     * 创建任务 /createTask
     * @param string $name 任务名称
     * @param string $type 任务类型 callback | command
     * @param string $rule 任务表达式
     * @param string $callback 回调: callback类型为JSON数组[class,method], command类型为命令字符串
     * @param string $memo 备注
     * @param int $singleton 是否单例 0: 否, 1: 是
     * @param string $mutex_pool 锁池名称
     * @param int $mutex_expires 锁过期时间 单位秒
     * @param int $on_one_server 是否仅在一台服务器上运行 0: 否, 1: 是
     * @param string $environments 环境变量
     * @param string $timezone 时区, 如 Asia/Shanghai
     */
    public function createTask() {

        $name = input('name', '', 'trim');
        $type = input('type', 'callback', 'trim');
        $rule = input('rule', '', 'trim');
        $callback = input('callback', '', 'trim');
        $memo = input('memo', '', 'trim');
        $singleton = (int) input('singleton', 0);
        $mutexPool = input('mutex_pool', 'default', 'trim');
        $mutexExpires = (int) input('mutex_expires', 60);
        $onOneServer = (int) input('on_one_server', 0);
        $environments = input('environments', '', 'trim');
        $timezone = input('timezone', '', 'trim');

        $this->validateTask($name, $type, $rule, $callback, 0);

        m('crontab_task')->insert([
            'name' => $name,
            'type' => $type,
            'rule' => $rule,
            'callback' => $callback,
            'memo' => $memo,
            'singleton' => $singleton,
            'mutex_pool' => $mutexPool,
            'mutex_expires' => $mutexExpires,
            'on_one_server' => $onOneServer,
            'environments' => $environments ?: null,
            'timezone' => $timezone ?: null,
            'status' => 1,
        ]);

        $this->container->get(CrontabTaskService::class)->sendReloadSignal();

        showmsg('创建成功', 200);
    }

    /**
     * 更新任务 /updateTask
     * @param int $id 任务ID
     * @param string $name 任务名称
     * @param string $type 任务类型 callback | command
     * @param string $rule 任务表达式
     * @param string $callback 回调: callback类型为JSON数组[class,method], command类型为命令字符串
     * @param string $memo 备注
     * @param int $singleton 是否单例 0: 否, 1: 是
     * @param string $mutex_pool 锁池名称
     * @param int $mutex_expires 锁过期时间 单位秒
     * @param int $on_one_server 是否仅在一台服务器上运行 0: 否, 1: 是
     * @param string $environments 环境变量
     * @param string $timezone 时区, 如 Asia/Shanghai
     * @param int $status 状态 1: 启用, 0: 暂停
     */
    public function updateTask() {
        $id = (int) input('id', 0);
        if (!$id) {
            showmsg('参数错误', 201);
        }

        $existing = m('crontab_task')->where(['id' => $id])->one();
        if (!$existing) {
            showmsg('任务不存在', 201);
        }

        $name = input('name', '', 'trim');
        $type = input('type', 'callback', 'trim');
        $rule = input('rule', '', 'trim');
        $callback = input('callback', '', 'trim');
        $memo = input('memo', (string) ($existing['memo'] ?? ''), 'trim');
        $singleton = (int) input('singleton', (int) ($existing['singleton'] ?? 0));
        // 可选字段缺省时保留原值,避免部分更新把配置清空
        $mutexPool = input('mutex_pool', (string) ($existing['mutex_pool'] ?? 'default'), 'trim');
        $mutexExpires = (int) input('mutex_expires', (int) ($existing['mutex_expires'] ?? 60));
        $onOneServer = (int) input('on_one_server', (int) ($existing['on_one_server'] ?? 0));
        $timezone = input('timezone', (string) ($existing['timezone'] ?? ''), 'trim');
        $environments = input('environments', (string) ($existing['environments'] ?? ''), 'trim');
        $status = (int) input('status', (int) ($existing['status'] ?? 1));
        if (!in_array($status, [0, 1], true)) {
            showmsg('status 仅支持 0(暂停)或 1(启用)', 201);
        }

        // 接管注解任务的行:仅允许改表达式/状态/备注,锁定回调与执行参数,防止改坏任务
        if (isset($this->container->get(AnnotationTaskService::class)->getNames()[$existing['name']])) {
            $locked = [
                '任务名称' => [$name, $existing['name']],
                '任务类型' => [$type, $existing['type']],
                '回调内容' => [$callback, $existing['callback']],
                '单例执行' => [$singleton, (int) $existing['singleton']],
                '锁池名称' => [$mutexPool, (string) $existing['mutex_pool']],
                '锁过期时间' => [$mutexExpires, (int) $existing['mutex_expires']],
                '单机运行' => [$onOneServer, (int) $existing['on_one_server']],
                '时区' => [$timezone, (string) ($existing['timezone'] ?? '')],
                '环境限制' => [$environments, (string) ($existing['environments'] ?? '')],
            ];
            foreach ($locked as $field => [$new, $old]) {
                if ($new !== $old) {
                    showmsg('注解接管任务仅支持修改表达式、状态和备注(不可改' . $field . ')', 201);
                }
            }
        }

        $this->validateTask($name, $type, $rule, $callback, $id);

        m('crontab_task')->where(['id' => $id])->update([
            'name' => $name,
            'type' => $type,
            'rule' => $rule,
            'callback' => $callback,
            'memo' => $memo,
            'singleton' => $singleton,
            'mutex_pool' => $mutexPool,
            'mutex_expires' => $mutexExpires,
            'on_one_server' => $onOneServer,
            'timezone' => $timezone ?: null,
            'environments' => $environments ?: null,
            'status' => $status,
        ]);

        $this->container->get(CrontabTaskService::class)->sendReloadSignal();

        showmsg('更新成功', 200);
    }

    /**
     * 删除任务 /deleteTask
     * @param int $id 任务ID
     */
    public function deleteTask() {
        $id = (int) input('id', 0);
        if (!$id) {
            showmsg('参数错误', 201);
        }

        $model = m('crontab_task')->where(['id' => $id]);
        if (!$model->one()) {
            showmsg('任务不存在', 201);
        }

        $model->delete();
        $this->container->get(CrontabTaskService::class)->sendReloadSignal();
        showmsg('删除成功', 200);
    }

    /**
     * 切换任务状态 /toggleStatus
     * @param int $id 任务ID
     * @param int $status 状态 1: 启用, 0: 暂停
     */
    public function toggleStatus() {
        $id = (int) input('id', 0);
        $status = (int) input('status', 0);
        if (!$id) {
            showmsg('参数错误', 201);
        }
        if (!in_array($status, [0, 1], true)) {
            showmsg('status 仅支持 0(暂停)或 1(启用)', 201);
        }

        $model = m('crontab_task')->where(['id' => $id, 'status' => [0, 1]]);
        if (!$model->one()) {
            showmsg('任务不存在', 201);
        }

        $model->update(['status' => $status]);
        $this->container->get(CrontabTaskService::class)->sendReloadSignal();

        showmsg('操作成功', 200);
    }

    /**
     * 手动执行一次任务 /runTask
     * @param int $id 任务ID(数据库任务)
     * @param string $source 任务来源, annotation 时按 name 执行注解任务
     * @param string $name 注解任务名称(source=annotation 时必填)
     */
    public function runTask() {
        $source = input('source', '', 'trim');

        if ($source === 'annotation') {
            $name = input('name', '', 'trim');
            if ($name === '') {
                showmsg('参数错误', 201);
            }

            $crontab = $this->container->get(AnnotationTaskService::class)->findByName($name);
            if (!$crontab) {
                showmsg('注解任务不存在', 201);
            }
            if (!in_array($crontab->getType(), ['callback', 'command'], true)) {
                showmsg('该注解任务类型不支持手动执行', 201);
            }

            $this->container->get(\App\crontab\Strategy\Executor::class)->execute($crontab);
            showmsg('执行成功', 200);
        }

        $id = (int) input('id', 0);
        if (!$id) {
            showmsg('参数错误', 201);
        }

        $task = m('crontab_task')->where(['id' => $id, 'status' => [0, 1]])->one();
        if (!$task) {
            showmsg('任务不存在', 201);
        }

        $service = $this->container->get(CrontabTaskService::class);
        $crontabs = $service->getAllEnabled();
        $found = null;
        foreach ($crontabs as $crontab) {
            if ($crontab->getName() === $task['name']) {
                $found = $crontab;
                break;
            }
        }

        if (!$found) {
            // 复用 Service 的行转换,保留单例/互斥锁/单机锁/时区等配置,避免手动触发绕过锁
            $found = $service->rowToCrontab($task);
            if (!$found) {
                showmsg('任务配置无效, 无法执行', 201);
            }
        }

        $executor = $this->container->get(\App\crontab\Strategy\Executor::class);
        $executor->execute($found);

        showmsg('执行成功', 200);
    }

    /**
     * 接管注解任务 /takeoverTask
     * 按注解定义预填生成一条同名数据库任务,之后可启停/改表达式,删除该行即恢复代码默认
     * @param string $name 注解任务名称
     */
    public function takeoverTask() {
        $name = input('name', '', 'trim');
        if ($name === '') {
            showmsg('参数错误', 201);
        }

        if (m('crontab_task')->where('name', $name)->where('status', 'in', [0, 1])->one()) {
            showmsg('任务名称已存在', 201);
        }

        $annotationService = $this->container->get(AnnotationTaskService::class);
        $crontab = $annotationService->findByName($name);
        if (!$crontab) {
            showmsg('注解任务不存在', 201);
        }
        if (!in_array($crontab->getType(), ['callback', 'command'], true)) {
            showmsg('该注解任务类型不支持接管', 201);
        }

        $callback = $crontab->getCallback();
        $timezone = $crontab->getTimezone();
        $environments = $crontab->getEnvironments();

        m('crontab_task')->insert([
            'name' => $name,
            'type' => $crontab->getType(),
            'rule' => (string) $crontab->getRule(),
            'callback' => is_array($callback) ? json_encode($callback, JSON_UNESCAPED_SLASHES) : (string) $callback,
            'memo' => (string) ($crontab->getMemo() ?? ''),
            'singleton' => $crontab->isSingleton() ? 1 : 0,
            'mutex_pool' => $crontab->getMutexPool() ?: 'default',
            'mutex_expires' => $crontab->getMutexExpires() ?: 60,
            'on_one_server' => $crontab->isOnOneServer() ? 1 : 0,
            'timezone' => $timezone ? ($timezone instanceof \DateTimeZone ? $timezone->getName() : (string) $timezone) : null,
            'environments' => $environments ? implode(',', $environments) : null,
            'status' => 1,
        ]);

        $this->container->get(CrontabTaskService::class)->sendReloadSignal();

        showmsg('接管成功, 可启停或修改表达式', 200);
    }

    /**
     * 校验任务参数
     */
    private function validateTask(string $name, string $type, string $rule, string $callback, int $excludeId = 0): void {
        if (empty($name)) {
            showmsg('请输入任务名称', 201);
        }

        $query = m('crontab_task')->where('name', $name);
        if ($excludeId) {
            $query->where('id', '<>', $excludeId);
        }
        if ($query->one()) {
            showmsg('任务名称已存在', 201);
        }

        if (empty($rule)) {
            showmsg('请输入Cron表达式', 201);
        }

        $parser = $this->container->get(Parser::class);
        if (!$parser->isValid($rule)) {
            showmsg('Cron表达式格式无效', 201);
        }

        if (empty($callback)) {
            showmsg('请输入回调内容', 201);
        }

        if (!in_array($type, ['callback', 'command'])) {
            showmsg('不支持的任务类型', 201);
        }

        if ($type === 'callback') {
            $callbackData = json_decode($callback, true);
            if (!is_array($callbackData) || count($callbackData) < 2) {
                showmsg('callback格式错误, 应为JSON数组 [类名, 方法名]', 201, [
                    'callback' => $callback,
                    'callbackData' => $callbackData,
                ]);
            }
            if (!class_exists($callbackData[0])) {
                showmsg('回调类不存在: ' . $callbackData[0], 201);
            }
        }
    }
}
