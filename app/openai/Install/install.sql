DROP TABLE IF EXISTS `#@_openai_tasks`;

CREATE TABLE `#@_openai_tasks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `uniqid` varchar(20) CHARACTER SET utf8 DEFAULT NULL COMMENT '唯一ID',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '任务标识(小写字母开头,小写字母/数字/下划线)',
  `title` varchar(128) DEFAULT '' COMMENT '任务标题',
  `remark` varchar(500) DEFAULT '' COMMENT '任务备注(仅后台展示,不参与请求)',
  `provider` varchar(32) DEFAULT NULL COMMENT 'AI供应商(空=使用系统默认供应商)',
  `model` varchar(500) DEFAULT NULL COMMENT '模型(多个以JSON数组存储,执行时随机选一个)',
  `temperature` decimal(3,2) DEFAULT NULL COMMENT '采样温度(NULL=不传,用上游默认)',
  `max_tokens` int(10) unsigned DEFAULT NULL COMMENT '最大生成token数(NULL=不传,用上游默认)',
  `config` text COMMENT '扩展参数(JSON,如response_format,合并进chat请求,显式列优先)',
  `system_template` mediumtext COMMENT 'system模板(Jinja2)',
  `user_template` mediumtext COMMENT 'user模板(Jinja2)',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近更新时间',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态(1:正常,0:禁用)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `uniqid` (`uniqid`),
  KEY `status` (`status`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = 'OpenAI任务表';

DROP TABLE IF EXISTS `#@_openai_task_usages`;

CREATE TABLE `#@_openai_task_usages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `task` varchar(64) NOT NULL DEFAULT '' COMMENT '任务标识',
  `provider` varchar(32) DEFAULT '' COMMENT 'AI供应商',
  `model` varchar(255) DEFAULT '' COMMENT '模型',
  `prompt_tokens` int(10) unsigned DEFAULT '0' COMMENT '输入token数',
  `completion_tokens` int(10) unsigned DEFAULT '0' COMMENT '输出token数',
  `total_tokens` int(10) unsigned DEFAULT '0' COMMENT '总token数',
  `duration_ms` int(10) unsigned DEFAULT '0' COMMENT '耗时(毫秒)',
  `error` varchar(1000) DEFAULT '' COMMENT '错误信息(空=成功)',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近更新时间',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态(1:正常,0:禁用)',
  PRIMARY KEY (`id`),
  KEY `task` (`task`),
  KEY `created_at` (`created_at`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = 'OpenAI任务用量审计表';

DROP TABLE IF EXISTS `#@_openai_providers`;

CREATE TABLE IF NOT EXISTS `#@_openai_providers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `uniqid` varchar(20) CHARACTER SET utf8 DEFAULT NULL COMMENT '唯一ID',
  `provider` varchar(32) NOT NULL DEFAULT '' COMMENT '供应商分组标识(如openai/deepseek,同名多行即为账号池)',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '账号名称(如主账号/备用1,用于区分与统计)',
  `base_uri` varchar(255) NOT NULL DEFAULT '' COMMENT '接口地址',
  `api_key` varchar(512) DEFAULT '' COMMENT 'API Key',
  `api_key_header` varchar(64) DEFAULT '' COMMENT '自定义密钥请求头(如Azure的api-key,空=标准Bearer)',
  `organization` varchar(64) DEFAULT '' COMMENT '组织ID(可选)',
  `headers` text COMMENT '额外请求头(JSON)',
  `query_params` text COMMENT '额外查询参数(JSON)',
  `request_timeout` int(10) unsigned DEFAULT '300' COMMENT '请求超时(秒)',
  `priority` int(10) DEFAULT '100' COMMENT '优先级(数字越小越优先)',
  `remark` varchar(500) DEFAULT '' COMMENT '备注(仅后台展示,不参与请求)',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近更新时间',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态(1:正常,0:禁用)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniqid` (`uniqid`),
  KEY `provider` (`provider`, `status`, `priority`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = 'OpenAI供应商账号池';

DROP TABLE IF EXISTS `#@_openai_models`;

CREATE TABLE IF NOT EXISTS `#@_openai_models` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `uniqid` varchar(20) CHARACTER SET utf8 DEFAULT NULL COMMENT '唯一ID',
  `provider` varchar(32) NOT NULL DEFAULT '' COMMENT '供应商分组标识(对应openai_providers.provider)',
  `model` varchar(128) NOT NULL DEFAULT '' COMMENT '模型名(以*结尾表示前缀匹配)',
  `priority` int(10) DEFAULT '100' COMMENT '优先级(数字越小越优先,同一模型多供应商时取最高)',
  `remark` varchar(500) DEFAULT '' COMMENT '备注(仅后台展示,不参与请求)',
  `source` varchar(16) DEFAULT 'manual' COMMENT '来源(manual:自填,pulled:接口拉取)',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近更新时间',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态(1:正常,0:禁用)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniqid` (`uniqid`),
  UNIQUE KEY `provider_model` (`provider`, `model`),
  KEY `model` (`model`, `status`, `priority`),
  KEY `status` (`status`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = 'OpenAI模型配置';

DROP TABLE IF EXISTS `#@_openai_stats_daily`;

CREATE TABLE IF NOT EXISTS `#@_openai_stats_daily` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `stats_date` date NOT NULL COMMENT '统计日期(Y-m-d)',
  `provider` varchar(32) NOT NULL DEFAULT '' COMMENT '供应商分组标识',
  `account` varchar(64) NOT NULL DEFAULT '' COMMENT '账号名称(空=未区分账号)',
  `model` varchar(128) NOT NULL DEFAULT '' COMMENT '模型名',
  `total` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '调用次数',
  `success` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '成功次数',
  `failed` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '失败次数',
  `prompt_tokens` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '输入token累计(流式调用无usage时为0)',
  `completion_tokens` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '输出token累计',
  `total_tokens` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '总token累计',
  `duration_ms` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '耗时累计(毫秒,除以total得均值)',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dimension` (`stats_date`, `provider`, `account`, `model`),
  KEY `idx_stats_date` (`stats_date`),
  KEY `idx_provider` (`provider`),
  KEY `idx_model` (`model`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = 'OpenAI调用看板日聚合表';
