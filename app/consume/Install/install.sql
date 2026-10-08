DROP TABLE IF EXISTS `#@_member_consume`;

CREATE TABLE `#@_member_consume` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) unsigned DEFAULT '0' COMMENT '会员',
  `uin` int(11) unsigned DEFAULT '0' COMMENT 'UIN',
  `uniqid` varchar(18) CHARACTER SET utf8 DEFAULT '' COMMENT '唯一ID,避免重复操作',
  `amount` decimal(10, 2) DEFAULT '0.00' COMMENT '操作金额',
  `balance` decimal(10, 2) DEFAULT '0.00' COMMENT '余额',
  `currency` varchar(18) CHARACTER SET utf8 DEFAULT 'money' COMMENT '货币',
  `type` varchar(50) CHARACTER SET utf8 DEFAULT '' COMMENT '类型',
  `data` text COMMENT '数据包',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建的时间',
  `expired_at` datetime DEFAULT NULL COMMENT '到期时间, NULL 为永久有效',
  `expired_settled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '到期是否已冲销',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniqid` (`uniqid`),
  KEY `created_at` (`created_at`),
  KEY `currency` (`currency`),
  KEY `type` (`type`),
  KEY `idx_uid_currency` (`uid`, `currency`),
  KEY `idx_uin_currency` (`uin`, `currency`),
  KEY `idx_expired` (`expired_settled`, `expired_at`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COMMENT = '会员积分明细';
