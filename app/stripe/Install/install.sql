DROP TABLE IF EXISTS `#@_stripe_payments`;

CREATE TABLE `#@_stripe_payments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `type` varchar(64) NOT NULL DEFAULT '' COMMENT '事件/操作类型',
  `object_id` varchar(100) NOT NULL DEFAULT '' COMMENT 'Stripe对象ID',
  `status` varchar(32) NOT NULL DEFAULT '' COMMENT '状态',
  `amount` bigint NOT NULL DEFAULT 0 COMMENT '金额(最小货币单位,如美分)',
  `currency` varchar(8) NOT NULL DEFAULT '' COMMENT '货币',
  `customer` varchar(100) NOT NULL DEFAULT '' COMMENT '客户ID',
  `metadata` text COMMENT '业务元数据',
  `raw` text COMMENT '原始数据',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `object_id` (`object_id`),
  KEY `type` (`type`),
  KEY `customer` (`customer`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 ROW_FORMAT = COMPACT COMMENT = 'Stripe支付记录';
