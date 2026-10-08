DROP TABLE IF EXISTS `#@_members`;

CREATE TABLE `#@_members` (
  `uid` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '会员ID',
  `uniqid` varchar(32) DEFAULT NULL COMMENT '唯一 ID',
  `uin` int(11) unsigned DEFAULT '0' COMMENT 'uin 绑定会员ID 或其它系统 uid',
  `username` varchar(100) DEFAULT NULL COMMENT '用户名,用于登录',
  `nickname` varchar(150) DEFAULT NULL COMMENT '用户昵称',
  `password` char(32) DEFAULT NULL COMMENT '密码',
  `gender` tinyint(1) DEFAULT '0' COMMENT '性别(1:男,2女,0未设置)',
  `mobile` varchar(20) DEFAULT NULL COMMENT '登录手机号',
  `email` varchar(60) DEFAULT NULL COMMENT '登录邮箱',
  `groupid` smallint(6) DEFAULT '-1' COMMENT '用户组 ID',
  `groupexpiry` int(10) unsigned DEFAULT '0' COMMENT '用户组有效期',
  `experience` int(10) unsigned DEFAULT '0' COMMENT '经验值',
  `regtype` varchar(20) DEFAULT NULL COMMENT '注册类型,(email,mobile,weixin)',
  `regip` char(50) DEFAULT NULL COMMENT '注册 IP',
  `regdate` int(10) unsigned DEFAULT NULL COMMENT '注册时间',
  `invisible` tinyint(1) DEFAULT '0' COMMENT '是否隐身(1:是,0:否)',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态',
  PRIMARY KEY (`uid`),
  UNIQUE KEY `uniqid` (`uniqid`),
  KEY `username` (`username`),
  KEY `email` (`email`),
  KEY `uin` (`uin`),
  KEY `mobile` (`mobile`)
) ENGINE = InnoDB AUTO_INCREMENT = 10000 CHARACTER SET = utf8mb4 COMMENT = '会员数据' ROW_FORMAT = Compact;

DROP TABLE IF EXISTS `#@_member_data`;

CREATE TABLE `#@_member_data` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uid` int unsigned NOT NULL DEFAULT '0',
  `uin` int unsigned DEFAULT '0' COMMENT '论坛UID',
  `field` varchar(50) CHARACTER SET utf8mb4 DEFAULT NULL COMMENT '字段',
  `value` varchar(500) CHARACTER SET utf8mb4 DEFAULT NULL COMMENT '内容值',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建的时间',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近更新的时间',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  KEY `uin` (`uin`),
  KEY `field_value` (`field`, `value`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COMMENT = '会员扩展资料';

DROP TABLE IF EXISTS `#@_member_authority`;

CREATE TABLE `#@_member_authority` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) unsigned DEFAULT '0' COMMENT 'uid',
  `expiration` int(11) unsigned DEFAULT NULL COMMENT '用户组有效期',
  `groupid` tinyint(6) unsigned DEFAULT '0' COMMENT '用户组 ID',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uid` (`uid`, `groupid`),
  KEY `groupid` (`groupid`),
  KEY `expiration` (`expiration`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 ROW_FORMAT = COMPACT COMMENT = '会员管理组权限';

DROP TABLE IF EXISTS `#@_member_trace`;

CREATE TABLE `#@_member_trace` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uniqid` varchar(32) NOT NULL DEFAULT '' COMMENT '唯一判断',
  `uid` int(11) unsigned DEFAULT '0' COMMENT 'UID',
  `uin` int(11) unsigned DEFAULT '0' COMMENT 'UIN(会员卡号,部分应用需要用UIN来比对)',
  `type` varchar(100) DEFAULT NULL COMMENT '类型(login, publish)',
  `relatedid` varchar(50) DEFAULT NULL COMMENT '类型对应ID',
  `num` int(10) DEFAULT '1' COMMENT '次数',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建的时间',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '最后更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniqid` (`uniqid`),
  KEY `uid_uin` (`uid`, `uin`),
  KEY `type_relatedid` (`type`, `relatedid`),
  KEY `created_at` (`created_at`),
  KEY `updated_at` (`updated_at`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 ROW_FORMAT = COMPACT COMMENT = '用户行为轨迹';

DROP TABLE IF EXISTS `#@_member_groups`;

CREATE TABLE `#@_member_groups` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('default', 'member', 'system') NOT NULL DEFAULT 'member' COMMENT '类型',
  `title` varchar(100) DEFAULT '' COMMENT '名称',
  `alias` varchar(100) DEFAULT '' COMMENT '别名',
  `anicount` int(10) DEFAULT '0' COMMENT '升级点数',
  `authority` text COMMENT '权限',
  `message` varchar(255) DEFAULT '' COMMENT '简单介绍',
  `aid` int(11) unsigned DEFAULT '0' COMMENT '会员组图片 ID',
  `sort` int(11) unsigned DEFAULT '0' COMMENT '排序',
  `options` text COMMENT '配置',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态(0:禁用,1:启用)',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `type` (`type`) USING BTREE,
  KEY `index_member_groups_status` (`status`) USING BTREE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 ROW_FORMAT = COMPACT COMMENT = '会员组及权限';

REPLACE INTO `#@_member_groups` VALUES(1, 'system', '管理员', 'admin', 0, '{"immission":1}', null, 0, 0, '[]', 1);
REPLACE INTO `#@_member_groups` VALUES(2, 'system', '运营', 'Operations', 0, '[]', null, 0, 0, '[]', 1);
REPLACE INTO `#@_member_groups` VALUES(3, 'member', '新手', '', 0, '[]', null, 0, 0, '[]', 1);
REPLACE INTO `#@_member_groups` VALUES(4, 'member', '初级', '', 100, '[]', null, 0, 0, '[]', 1);