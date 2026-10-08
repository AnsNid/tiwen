DROP TABLE IF EXISTS `#@_crontab_task`;
CREATE TABLE `#@_crontab_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '任务名称',
  `type` varchar(20) NOT NULL DEFAULT 'callback' COMMENT '类型: callback/command',
  `rule` varchar(100) NOT NULL COMMENT 'Cron表达式',
  `callback` varchar(500) NOT NULL DEFAULT '' COMMENT '回调: callback类型为JSON数组[class,method], command类型为命令字符串',
  `singleton` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否单例执行',
  `mutex_pool` varchar(50) NOT NULL DEFAULT 'default' COMMENT 'Mutex Redis连接池',
  `mutex_expires` int unsigned NOT NULL DEFAULT 60 COMMENT 'Mutex过期时间(秒)',
  `on_one_server` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否单服务器执行',
  `memo` varchar(500) DEFAULT NULL COMMENT '备注',
  `timezone` varchar(50) DEFAULT NULL COMMENT '时区',
  `environments` varchar(255) DEFAULT NULL COMMENT '环境限制(逗号分隔)',
  `options` text DEFAULT NULL COMMENT '扩展选项(JSON)',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态: 1启用 0禁用',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='计划任务配置表';
