-- 收窄 member_consume 索引以匹配账本热查询
-- 热路径(每次写入都跑): WHERE currency=? AND (uid=? OR uin=?) ORDER BY id DESC LIMIT 1 FOR UPDATE
--                        以及同条件下的 SUM(amount)
--
-- 1. KEY uid / KEY uin 分别是两个复合索引的最左前缀, 属纯冗余;
--    member_consume 是只追加的高频写入表, 冗余索引是白交的写放大
-- 2. 原 idx_uid_currency_uin(uid,currency,uin): InnoDB 会在二级索引末尾隐式追加主键,
--    等效 (uid,currency,uin,id); uin 夹在 currency 与 id 之间, 使得未约束 uin 时
--    ORDER BY id DESC 无法用索引序, 必须 filesort。收窄为 (uid,currency) 后等效
--    (uid,currency,id), 天然有序
-- 3. 新增 idx_uin_currency: 存在 uid=0 的 uin-only 记录(如 app/api/Controller/Jobs.php
--    的补账逻辑), 原先只能走裸 uin 索引再回表过滤 currency
--
-- 注: 未改动 uid OR uin 的查询语义。记录可同时带 uid 与 uin, 调用方也常同时传两者,
--     拆成两个分支求和会让同时命中的记录被重复计入, 故保留 OR 交由 index_merge 处理。
--
-- MySQL 8 下 DROP/ADD INDEX 为 INPLACE 在线 DDL, 不重建表; 但大表仍会持续较久,
-- 且开始与结束时各短暂持有元数据锁, 建议低峰期执行。执行前请确认索引名与现网一致:
--   SHOW INDEX FROM `#@_member_consume`;

ALTER TABLE `#@_member_consume`
    DROP INDEX `uid`,
    DROP INDEX `uin`,
    DROP INDEX `idx_uid_currency_uin`,
    ADD INDEX `idx_uid_currency` (`uid`, `currency`),
    ADD INDEX `idx_uin_currency` (`uin`, `currency`);
