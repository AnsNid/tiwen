-- 给积分流水加上有效期支持(存量表手工迁移, 新建表已含在 install.sql / schema.json 中)
--
-- 到期不是靠改余额公式实现的: 余额仍然是 SUM(amount), 30 多处读取方一行都不用动。
-- app/consume/Crontab/Expire.php 每 10 分钟扫一次, 给到期的赠送补一条等额负向流水,
-- 冲销额取 min(该笔赠送额, 当前余额), 因此不会把账本冲成负数。
--
-- 1. expired_at: NULL 即永久有效, 存量流水保持 NULL, 不受本次变更影响
-- 2. expired_settled: 该笔到期是否已冲销。冲销流水先写、标记后写且同处一个事务;
--    流水 uniqid 取自原流水 id, 落在既有唯一索引上, 任务重跑不会重复入账
-- 3. idx_expired(expired_settled, expired_at): 扫描条件是 expired_settled = 0
--    AND expired_at IS NOT NULL AND expired_at <= NOW() ORDER BY expired_at ASC。
--    前导等值列把已处理与永久有效的行都排除在外, 扫描量不随历史增长, 且索引序天然
--    满足排序无需 filesort; 带上 IS NOT NULL 是为了让范围优化器跳过 InnoDB 排在
--    索引最前的 NULL 区
--
-- 两列追加在表尾而不指定 AFTER: MySQL 8.0.12~8.0.28 只有"加在最后一列"才走 INSTANT,
-- 带 AFTER 会退化成重建整表。member_consume 是高频写入的账本表, 不能为列的顺序美观
-- 付一次全表重建的代价。install.sql 里同样按此顺序排列, 保证新建表与迁移表列序一致。
--
-- ADD COLUMN 与 ADD INDEX 拆成两条: MySQL 8 下带默认值的 ADD COLUMN 是 INSTANT(秒级,
-- 不重建表), ADD INDEX 是 INPLACE; 混在同一条 ALTER 里会整体降级为 INPLACE。
-- ADD INDEX 在大表上仍会持续较久, 且开始与结束时各短暂持有元数据锁, 建议低峰期执行。
-- 执行前确认两列尚未存在: SHOW COLUMNS FROM `#@_member_consume`;

ALTER TABLE `#@_member_consume`
    ADD COLUMN `expired_at` datetime DEFAULT NULL COMMENT '到期时间, NULL 为永久有效',
    ADD COLUMN `expired_settled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '到期是否已冲销';

ALTER TABLE `#@_member_consume`
    ADD INDEX `idx_expired` (`expired_settled`, `expired_at`);
