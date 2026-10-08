# consume · 会员积分明细与多币种账本

追加式(append-only)账本:每次增减只插一行流水,**余额永远是 `SUM(amount)`**。上层配一套规则引擎做发放,配一个定时任务做到期冲销。全站数十处读余额的代码都只认两个方法,口径统一。

## 功能总览

| 能力 | 说明 |
|------|------|
| 多币种账本 | `currency` 区分资产(金币/钻石/积分/金额…),同一用户各币种独立记账、独立余额 |
| 规则化发放 | 事件标识 → 规则(金额/币种/频次限制/有效期),后台可视化配置,业务侧一行调用 |
| 随机金额 | `'1-10'` 区间随机、`'10,20,30'` 列表随机、数字固定,做签到/抽奖类浮动奖励 |
| 频次与额度限制 | 按 日/周/月 或自定义日期段,限次数、限累计额度,并发下不会超发 |
| 幂等 | `uniqid` 派生自 `uid+uin+type+currency+业务键`,重放返回原流水而不二次入账 |
| 双身份 | `uid`(本站会员)与 `uin`(UC/外部用户)可只用其一,账本按身份分别加锁 |
| 发放有效期 | 规则配 `expire`(天数或 `strtotime` 表达式),到期由定时任务补等额负向流水冲销 |
| 事件钩子 | 记账前后、读余额、按规则发放均可被 `event/` 拦截或改写 |
| 后台管理 | 流水多维检索、单条详情、批量余额调整(最多 1000 人)、规则引擎编辑器、运营统计看板 |

## 核心口径:余额 = SUM(amount)

流水表里的 `balance` 列**只是写入当刻的快照**,用于在明细页展示"这一笔之后的余额",不是余额的数据来源。真正的余额只有两个读取口:

| 方法 | 用途 |
|------|------|
| `___count___($currency, $uid, $uin)` | 单币种余额;`$currency` 可用 `; , @ \| &` 分隔多个币种求合计 |
| `___balance___($uid \| ['uid'=>..,'uin'=>..])` | 该用户全部币种余额,返回 `[currency => amount]` |

改余额口径(比如引入冻结额、只算未过期部分)必须改这两个方法,不要另起查询——否则会和写入侧的 `balance` 快照以及全站数十处既有调用方分叉。

## 安装

```bash
php index.php app consume       # 安装(建表 / 注册 / 装依赖)
php index.php app consume -r    # 安装并重启服务
php index.php app consume up    # 升级
```

依赖 `@crontab >= 0.0.1`(到期冲销任务需要)。`config/apps.php` 是由 `application` 数据表生成的缓存,**不要手改**。

## SDK

入口:`app('consume')` → `App\consume\Api`,规则:`app('consume.rule')` → `App\consume\Rule`。

> consume 未安装时 `app('consume')` 返回一个 `ClassNotFound` 空记录对象,调用**静默失效**而不报错。跨模块调用前先守卫:
> ```php
> if (app('?consume')) { /* ... */ }
> ```

### 1. 按规则发放 `rule()`

```php
rule(array|string $type = '', ?int $uid = 0, array $data = [], $uniqid = ''): array
```

| 参数 | 说明 |
|------|------|
| `$type` | 规则标识(如 `login`)。也可传数组做一次性规则:数组的 `type` 键既选中要合并的配置项,也是落库类型,**必须给**——缺了它 `rule()` 会把整个数组当成 type 写进流水。仓库里目前没有数组形式的调用 |
| `$uid` | 会员 UID;为 0 时必须在 `$data['uin']` 里给 UIN |
| `$data` | 附加数据,原样进 `data` 列。`$data['uin']` 是身份,`$data['expire']` 覆盖规则有效期 |
| `$uniqid` | 幂等键;留空则取规则里的 `uniqid`,再空就随机(等于放弃幂等) |

返回:成功为流水数组;规则不存在、金额/币种为空、或触发频次限制时返回 `[]`。**调用方统一用 `empty()` 判断**,不要判 `false`。

```php
// 每日登录奖励(规则 restrict: daily + count 1),幂等键用当天日期
app('consume')->rule('login', $uid, ['uin' => $uin], date('Y-m-d'));

// 注册奖励(restrict: count 1,不限日期 → 终身一次),幂等键用 uid
app('consume')->rule('register', $uid, ['uin' => $uin], $uid);

// 发帖奖励,带上业务上下文便于对账
app('consume')->rule('post', $topic->uid, [
    'uin' => $topic->uin,
    'topicid' => $topic->id,
], $topic->id);

// 临时给某笔发放单独指定 7 天有效期(覆盖规则配置)
app('consume')->rule('invitation', $uid, ['expire' => 7], $orderId);
```

`rule()` 全程在一个事务里:先锁余额 → 再查频次限制 → 最后写流水。锁在前是关键,否则并发的两个请求会同时通过 `restrict` 校验造成超发。

### 2. 直接记账 `record()`

规则之外的场景(充值、退款、消费、后台调账)直接记账:

```php
record(?string $currency = 'money', ?int $uid = 0, ?float $amount = 0, ?string $type = 'system',
       $uniqid = '', array $data = [], $callback = null, ?string $expiredAt = null): array|false
```

```php
// 扣减:金额传负数。这是仓库里最常见的写法(circle/Coin、forum/UserOperation 都是这样),
// 但"读余额 → 判断 → 记一笔"在并发下有透支窗口,record() 不会拦住变负的那一笔。
// 详见文末「开发注意事项」。
$balance = app('consume')->___count___($currency, $uid, $uin);
if ($balance < $num) {
    throw new \Exception('余额不足');
}
app('consume')->record($currency, $uid, 0 - $num, 'topiccoin', '', [
    'uin' => $uin,
    'topicid' => $topicId,
]);

// 只有外部身份(uin)时,uid 传 0,uin 放进 data
app('consume')->record('gter', 0, $diff, 'system', '', ['uin' => $uin]);

// 带到期时间的发放
app('consume')->record('credit', $uid, 100, 'gift', $orderId, [], null,
    date('Y-m-d H:i:s', strtotime('+30 days')));
```

注意:

- `$amount` 会被 `round(..., 2)`;**传 0 直接返回 `false`**,账本不记零额流水
- `$uid` 与 `$data['uin']` 至少有一个非 0,`$currency`/`$type` 不能为空串,否则返回 `false`
- `data` 列写数组即可,查询构造器的 `castBinding()` 会自动 `json_encode`;**读取时需要显式声明** `->json(['data'])` 才会解码回数组
- `data` 里不要复制整份规则配置——`rule()` 只存 `['rule' => 标识]`(+ 可选 `remark`),完整规则可由标识反查。这是高频账本表的主要存储开销

### 3. 动态调用

`Api::__call()` 把币种名当方法名,省掉第一个参数:

```php
app('consume')->money($uid, $amount, 'recharge', $orderId, $data, $callback);
// 等价于 record('money', $uid, $amount, 'recharge', $orderId, $data, $callback)

app('consume')->getMoney($uid);        // 等价于 ___count___('money', $uid)
app('consume@?money', $uid, $uin);     // ? 前缀走 ___count___,同样等价
```

`expiredAt` 是 `record()` 的第 8 个参数,动态调用时按位置补在最后即可。

### 4. 回调

`record()` / 动态调用的 `$callback` 会在流水落库后执行,入参是流水数组,返回值替代原本的返回值:

```php
$consume = app('consume')->money($uid, $money, 'recharge', $orderId, $order, function ($data) use ($order) {
    // 入账成功后再改订单状态,失败就不会走到这里
    m('order')->where('id', $order['id'])->update(['status' => 1]);
    return $data;
});
```

## 规则配置

优先级:后台保存的 `consumerule` > 文件 `app/consume/Config/config.php` 的 `rule`。后台改过就以后台为准,整份替换(不逐项合并)。

```php
'login' => [
    'amount'   => 1,          // 见下方四种写法
    'currency' => 'credit',   // 必填,缺则规则不生效
    'type'     => 'login',    // 可选,落库的 type;缺省用规则标识
    'remark'   => '登录奖励积分',
    'restrict' => [
        'date'   => 'daily',  // daily | weekly | monthly | [起, 止]
        'count'  => 1,        // 该日期段内最多几笔
        'amount' => 10,       // 该日期段内累计额度上限
    ],
    'expire'   => 30,         // 可选,见「有效期」
],
```

**amount 的四种写法**(`Rule::handleRuleAmount()`):

| 写法 | 结果 |
|------|------|
| `10`(数字) | 固定 10 |
| `'1-100'`(字符串区间) | `mt_rand(1, 100)`,下界钳到 ≥1、上界钳到 ≤10000 |
| `'10,20,30'`(字符串列表) | 随机取一个 |
| `[10, 20, 30]`(数组) | 随机取一个(去重、去零) |

> ⚠️ **负数不要写成字符串。** `'-5'` 会被当成区间解析(`explode('-')` → `['', '5']` → `mt_rand(1, 5)`),得到一个**正数**。扣减请用数字 `-5`,或走 `record()` 直接传负金额。

**restrict 的语义**:`count` 与 `amount` 至少要配一个,否则 `date` 会被清空、整个限制不生效。`date` 为空时统计的是**全部历史**,所以 `['count' => 1]` 不带 date 就是"终身一次"(注册奖励正是这么配的)。

## 有效期与到期冲销

### 配置

规则里加 `expire`,或调用时用 `$data['expire']` 临时覆盖:

| 值 | 含义 |
|----|------|
| `30`(数字) | 发放后 30 天到期 |
| `'72 hours'` | 交给 `strtotime`,相对式 |
| `'2026-12-31 23:59:59'` | 交给 `strtotime`,绝对式 |
| 不配 / `null` / `''` | 永久有效 |

解析在 `Rule::convertExpire()`,**必须在发放当刻求值**——Swoole 常驻进程下配置只在启动时加载一次,若在 `convertConfig()` 里预算成绝对时间,会被后续所有请求复用同一个过期时刻。解析失败会记 warning 并按永久有效处理,不会让发放失败。

### 冲销机制

到期**不改余额公式**,余额仍然是 `SUM(amount)`——这是为了让数十处读取方一行都不用动。`Api::settleExpired()` 给每笔到期的赠送补一条等额负向流水(`type = 'expire'`),让求和自然把它减掉:

- 冲销额取 `min(该笔赠送额, 当前余额)`,**用户已经花掉的部分不追讨**,账本也不会被冲成负数
- 余额已归零的同样打 `expired_settled = 1` 标记,否则每轮都会重新扫到它
- 冲销流水与标记同处一个事务,且**流水先写、标记后写**;反过来的话写入失败会让这笔额度静默消失
- 冲销流水的 `uniqid` 取自原流水 id(`expire:{id}`),落在既有唯一索引上,**任务重跑不会重复入账**
- 单笔失败只回滚该笔、不标记、不阻断整批,下轮自动重试(否则按 `expired_at` 升序消费时,卡住的那笔每轮都排在最前,后面的永远轮不到)

定时任务 `app/consume/Crontab/Expire.php`:

```php
#[Crontab(name: "ConsumeExpire", rule: "*/10 * * * *", callback: "execute",
          memo: "积分到期冲销(把过期的赠送从余额中扣除)")]
```

手工触发 / 调批量:

```php
app('consume')->settleExpired();        // 默认单轮最多 500 笔
app('consume')->settleExpired(2000);    // 积压时加大批量
// 返回 ['scanned'=>.., 'settled'=>.., 'skipped'=>.., 'failed'=>.., 'amount'=>..]
```

`scanned > 0` 时任务会记一条 info 日志(扫到却一笔没冲同样是异常信号,所以按 scanned 而非 settled 记)。

### 已知取舍

1. **不是严格 FIFO。** 账本不记录用户花掉的是哪一笔钱。同时持有永久积分与到期积分时(赠送 A=100 一月后过期 + 充值 B=100 永久,花掉 150),冲销会把剩余余额都算作到期那笔的钱——这里会冲掉 50,而严格 FIFO 应该冲 0。要精确 FIFO 就得改成按笔核销的额度表,读写两侧全要动。
2. **最多 10 分钟的宽限窗口。** 到期时刻与冲销之间存在间隔,这段时间内余额仍偏高。对"过期即失效"要求严格的场景(如活动额度),需要在消费入口自己比对 `expired_at`。

## 事件钩子

事件名 → 监听类的映射规则:点号转目录、`@` 后是方法名、**最后一段首字母大写作为类名**,根命名空间 `\Event\`。监听类不存在时 `dispatch()` 返回 `null`,即钩子全部可选。

| 事件 | 监听位置 | 触发时机与作用 |
|------|----------|----------------|
| `consume.{type}@rule` | `Event\consume\{Type}::rule($rule, $uid, $uin)` | `rule()` 取到规则后、记账前 |
| `consume.{currency}@before` | `Event\consume\{Currency}::before($record)` | 流水插入前;**返回数组会 merge 进 `data` 列** |
| `consume.{currency}@after` | `Event\consume\{Currency}::after($record)` | 流水插入成功后(事务外) |
| `consume@balance` | `Event\Consume::balance($uid, $uin)` | `___balance___()` 入口;**返回真值则直接作为结果**,可整体接管 |
| `consume.{currency}@count` | `Event\consume\{Currency}::count($uid, $uin)` | `___count___()` 入口;**返回数值则直接作为余额**,可整体接管 |

现成例子 `event/consume/Money.php`:

```php
namespace Event\consume;

class Money {
    public function after(array $data = []) {
        // money 币种变动后推 websocket 通知
        app('?agent') && app('agent.WebSocket')->send($data['uid'], ['type' => 'points']);
    }
}
```

## 数据表

`#@_member_consume`(前缀见 `.env`),建表语句在 `Install/install.sql`,结构声明在 `Install/schema.json`。

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | int AI | 主键 |
| `uid` / `uin` | int unsigned | 会员 UID / 外部 UIN,至少一个非 0 |
| `uniqid` | varchar(18) **UNIQUE** | 幂等键,`id()` 生成 12 位;重复插入直接命中唯一索引 |
| `amount` | decimal(10,2) | 本次变动,正数发放、负数扣减 |
| `balance` | decimal(10,2) | 写入当刻的余额快照(仅展示用) |
| `currency` | varchar(18) | 币种标识 |
| `type` | varchar(50) | 操作类型标识 |
| `data` | text | JSON 附加数据 |
| `created_at` | timestamp | 记账时间 |
| `expired_at` | datetime NULL | 到期时间,`NULL` 为永久有效 |
| `expired_settled` | tinyint(1) | 该笔到期是否已冲销 |

索引:

| 索引 | 支撑的查询 |
|------|-----------|
| `PRIMARY(id)` | 加锁只取 `ORDER BY id DESC LIMIT 1`,锁足迹 O(1) |
| `uniqid` UNIQUE | 幂等 |
| `idx_uid_currency(uid, currency)` / `idx_uin_currency(uin, currency)` | 余额求和、按身份加锁(单索引等值区间,间隙锁才可靠) |
| `idx_expired(expired_settled, expired_at)` | 冲销扫描:前导等值列把已处理与永久有效的行排除在外,扫描量不随历史增长,且索引序天然满足 `ORDER BY expired_at ASC` 无需 filesort |
| `created_at` / `currency` / `type` | 后台流水检索与看板聚合 |

> 加锁刻意**不用** `SUM(amount) ... FOR UPDATE`:那会锁住该用户全部历史流水,在追加式账本上是 O(n) 的锁足迹和死锁放大器。也**不用** `uid OR uin` 合并加锁:OR 会走 index_merge,空区间下间隙锁不可靠。实现见 `Api::lockBalance()`。
>
> 依赖 InnoDB `REPEATABLE READ` 的间隙锁来串行化"用户在该币种下还没有任何流水"时的首次写入。上线前确认 `SELECT @@transaction_isolation`。

## 后台管理

前端页面 `app/admin/View/src/pages/ConsumePage.tsx`,四个 tab:积分明细流水 / 会员余额调整 / 事件激励规则 / 运营统计看板。菜单挂在「用户」分组下,路由 `consume`,表格结构由 `consume.table@init` 提供。

系统配置项(`Config/admin.php` 声明,后台「系统设置 → 积分管理」):

| 配置 | 说明 |
|------|------|
| `consume_currency` | 币种字典(标识/名称/标签色),前台展示与后台下拉都用它 |
| `consume_types` | 操作类型字典(充值/提现/退款/系统操作/到期作废…) |
| `consumeallow_negative` | 是否允许后台调账为负数(提现、退款场景),默认关闭 |

接口前缀 `/{adminhostpath}/api/consume/{Controller}/{action}`(`adminhostpath` 取自系统配置 `config.adminhostpath`,默认 `admin`),需管理员登录(`AdminAuthMiddleware`,请求头 `x-token`)。控制器段走 `ucfirst()` 归一化,`Admin` 与 `admin` 等价。

| 接口 | 方法 | 说明 |
|------|------|------|
| `Admin/init` | GET | 一次拿回类型字典(含当日计数)、币种、规则、调账令牌、默认日期区间 |
| `Admin/lists` | GET | 流水分页检索(类型/UID/币种/日期段) |
| `Admin/balances` | GET | 批量用户余额(一次 IN 查询,不逐个查) |
| `Admin/userList` | GET | 调账时的会员检索 |
| `Admin/submit` | POST | 批量调账(单次 ≤1000 人) |
| `Admin/stats` | GET | 统计看板聚合(结果缓存 60 秒) |
| `Rule/submit` | POST | 保存整份规则 |
| `Table/init` | GET | 声明式表格结构。前端不直接请求它,而是调 admin 的两段式描述符接口 `POST /admin/api/table/get`(`name=consume.table@init`)——该路由落到 `Api::dispatch()` → `App\admin\Controller\Admin\Table::get()`,拆出 `@` 前后再由 `DispatchService` 反射到 `App\consume\Controller\Admin\Table::init()` |
| `Autocomplete/currency` / `Autocomplete/types` | GET | 币种 / 类型标识联想,供 `Config/admin.php` 的字典表单使用 |
| `Admin/detail` / `Admin/balance` / `Admin/get` / `Rule/init` | GET | 单条流水详情 / 单个余额 / 调账表单的声明式结构与一次性令牌 / 规则读取。当前 React 页面未调用(详情直接用列表行数据),保留给声明式后台与其他调用方 |

前 7 个是 `ConsumePage.tsx` 实际在用的;其余由 `Config/admin.php` 的声明式配置或历史调用方使用。

批量调账的幂等键由 **payload 派生**(管理员 uid + 币种 + 金额 + 排序后的 uid 列表 + 备注),不以客户端 token 为种子——批次中途失败后重新载入表单会拿到新 token,若以 token 为准,重试会对已入账的用户二次发放。同一个批次键配合 `record()` 内部的 `uid` 参与派生,做到"整批一个键、每人一条幂等"。

统计看板的口径:

- 正向流水记**发放**,负向流水取绝对值记**消耗**,一次聚合同时拿到两者
- `COUNT(DISTINCT uid)` **不能**由每日值相加得到(同一用户跨天活跃会被重复计数),区间级单独查一次
- 环比基准是上一个等长区间;`days` 钳制在 1–90
- 资产占比刻意**不受币种筛选影响**,便于选中某一币种时对照其余资产的体量
- 缓存键带 `days` 与 `currency`,切换口径不会读到上一个口径的数
- 到期额度面板在 `expired_at` 列尚未迁移时,后端探测 `hasColumn` 后返回 `null`,前端整块隐藏——避免"代码先上线、ALTER 还没跑"的窗口里整个看板报错

## 升级与迁移

**框架没有迁移器。** `AppInstall::createTableWithSchema()` 遇到已存在的表直接返回,而 `install.sql` 以 `DROP TABLE IF EXISTS` 开头——所以 `php index.php app consume up` 对存量表**不做任何事**。改表结构必须三件套齐全:

1. `Install/install.sql` —— 新装环境
2. `Install/schema.json` —— 结构声明
3. `Install/{日期}_{说明}.sql` —— 存量环境手工执行

现有的两份迁移脚本:

| 文件 | 内容 |
|------|------|
| `Install/20260916_optimize_consume_indexes.sql` | 补 `idx_uid_currency` / `idx_uin_currency` 等复合索引 |
| `Install/20260916_add_consume_expiry.sql` | 加 `expired_at` / `expired_settled` 两列 + `idx_expired` |

到期列的迁移脚本里有几条刻意的取舍,执行前值得读一遍注释:

- 两列**追加在表尾、不指定 `AFTER`**:MySQL 8.0.12~8.0.28 只有"加在最后一列"才走 INSTANT,带 `AFTER` 会退化成重建整表。`member_consume` 是高频写入的账本表,不能为列序美观付一次全表重建
- `ADD COLUMN` 与 `ADD INDEX` **拆成两条**:MySQL 8 下带默认值的 `ADD COLUMN` 是 INSTANT(秒级),`ADD INDEX` 是 INPLACE;混在同一条 ALTER 里会整体降级为 INPLACE
- `ADD INDEX` 在大表上仍会持续较久,开始与结束时各短暂持有元数据锁,**建议低峰期执行**;执行前先 `SHOW COLUMNS FROM` 确认两列尚未存在

### 有效期功能的部署清单

```bash
# 1. 低峰期执行迁移(表前缀按 .env 替换 #@_)
mysql -u<user> -p <db> < app/consume/Install/20260916_add_consume_expiry.sql

# 2. 清 DI 缓存(后端代码改动的硬性要求)
rm -rf runtime/container

# 3. 重新打包 + 部署 + 重启(phar 含前端 dist,需手工打包)
```

之后在后台「系统配置 → 操作类型」里补一条 `expire` / 「到期作废」,冲销流水才有可读的类型标签;并确认 `SELECT @@transaction_isolation` 为 `REPEATABLE-READ`。

## 开发注意事项

- 后端代码改动后必须 `rm -rf runtime/container` 清 DI 缓存并重启服务;前端改动只需重新 build
- `build/consume.phar` 只含 PHP(consume 没有 `View/` 目录),需手工重打包 + 部署 + 重启才生效;后台页面在 `app/admin/View`,改完要 `npm run build` 后打进 `admin.phar`
- 常驻进程下 PHP 配置文件只在启动时求值一次,**任何与"现在几点"有关的计算都不能放在配置层**(有效期解析就是这个原因被单独拆出 `convertExpire()`)
- **`record()` 不拦透支。** 它内部用 `lockBalance()` 把同一用户同一币种的并发写入串行化,但只负责算出正确的 `balance` 快照,不会拒绝让余额变负的那一笔;`lockBalance()` 是 private,调用方也拿不到"加锁读余额"的能力。所以"读余额 → 判断 → 记一笔"这个常见写法在并发下有真实的透支窗口(两个请求可能都读到同一余额、都通过校验),防线在业务侧:要么接受偶发负余额,要么用自己的事务 + 业务锁把判断和扣减包在一起
- 新增币种或操作类型只需在后台字典里加一条,不用改代码;`type` 是自由字符串,但**建议同步登记到字典**,否则前台与看板会显示原始标识
