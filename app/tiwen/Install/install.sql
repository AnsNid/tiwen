DROP TABLE IF EXISTS `#@_tiwen_cards`;
DROP TABLE IF EXISTS `#@_tiwen_rounds`;
DROP TABLE IF EXISTS `#@_tiwen_sessions`;
DROP TABLE IF EXISTS `#@_tiwen_members`;

CREATE TABLE `#@_tiwen_members` (
  `uid` int unsigned NOT NULL COMMENT '用户UID(关联 user 应用)',
  `plan` varchar(16) NOT NULL DEFAULT 'free' COMMENT '当前套餐: free/pro/team',
  `plan_interval` varchar(8) NOT NULL DEFAULT '' COMMENT '订阅周期: month/year',
  `tokens_quota` bigint NOT NULL DEFAULT 1000000 COMMENT '月度 Token 配额',
  `tokens_used` bigint NOT NULL DEFAULT 0 COMMENT '本月已用 Token',
  `quota_reset_date` date DEFAULT NULL COMMENT '配额重置日期(次日重置)',
  `daily_asks` int unsigned NOT NULL DEFAULT 0 COMMENT '今日已提问次数(免费版限制)',
  `daily_ask_date` date DEFAULT NULL COMMENT '提问计数日期',
  `stripe_customer_id` varchar(100) NOT NULL DEFAULT '' COMMENT 'Stripe 客户ID',
  `stripe_subscription_id` varchar(100) NOT NULL DEFAULT '' COMMENT 'Stripe 订阅ID',
  `subscription_status` varchar(32) NOT NULL DEFAULT '' COMMENT '订阅状态: active/past_due/canceled等',
  `current_period_end` int unsigned NOT NULL DEFAULT 0 COMMENT '当前订阅周期结束时间戳',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`uid`),
  KEY `stripe_customer_id` (`stripe_customer_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 ROW_FORMAT = COMPACT COMMENT = '提问会员套餐与订阅';

CREATE TABLE `#@_tiwen_sessions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '会话ID',
  `uid` int unsigned NOT NULL DEFAULT 0 COMMENT '所属用户',
  `title` varchar(200) NOT NULL DEFAULT '' COMMENT '会话标题',
  `summary` varchar(500) NOT NULL DEFAULT '' COMMENT '会话摘要',
  `editor_draft` mediumtext COMMENT '研报编辑区草稿',
  `total_tokens` bigint NOT NULL DEFAULT 0 COMMENT '会话累计 Token',
  `active_round_index` int unsigned NOT NULL DEFAULT 0 COMMENT '当前激活轮次',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 ROW_FORMAT = COMPACT COMMENT = '提问研报会话';

CREATE TABLE `#@_tiwen_rounds` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '轮次ID',
  `session_id` int unsigned NOT NULL DEFAULT 0 COMMENT '所属会话',
  `round_index` int unsigned NOT NULL DEFAULT 0 COMMENT '轮次序号(从0起)',
  `question` text COMMENT '本轮问题',
  `tag` varchar(16) NOT NULL DEFAULT '主问题' COMMENT '轮次标签: 主问题/追问',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `session_id` (`session_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 ROW_FORMAT = COMPACT COMMENT = '提问会话轮次';

CREATE TABLE `#@_tiwen_cards` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '卡片ID',
  `round_id` int unsigned NOT NULL DEFAULT 0 COMMENT '所属轮次',
  `session_id` int unsigned NOT NULL DEFAULT 0 COMMENT '所属会话(冗余便于统计)',
  `uid` int unsigned NOT NULL DEFAULT 0 COMMENT '所属用户',
  `model_id` varchar(64) NOT NULL DEFAULT '' COMMENT '模型ID',
  `model_name` varchar(64) NOT NULL DEFAULT '' COMMENT '模型名称',
  `body` longtext COMMENT '回答正文',
  `token_count` int unsigned NOT NULL DEFAULT 0 COMMENT 'Token 用量',
  `duration_sec` decimal(6,1) NOT NULL DEFAULT 0.0 COMMENT '耗时(秒)',
  `checked` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否勾选采纳',
  `status` varchar(16) NOT NULL DEFAULT 'done' COMMENT '状态: done/failed',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `round_id` (`round_id`),
  KEY `session_id` (`session_id`),
  KEY `uid` (`uid`),
  KEY `model_id` (`model_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 ROW_FORMAT = COMPACT COMMENT = '提问模型回答卡片';
