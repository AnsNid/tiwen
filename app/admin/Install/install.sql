DROP TABLE IF EXISTS `#@_application`;

CREATE TABLE `#@_application` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `app` varchar(20) DEFAULT NULL COMMENT '应用目录',
  `description` text DEFAULT NULL COMMENT '介绍',
  `tables` varchar(60) DEFAULT NULL COMMENT '数据表',
  `author` varchar(60) DEFAULT NULL COMMENT '开发者',
  `name` varchar(60) DEFAULT NULL COMMENT '名字',
  `seo` text COMMENT 'SEO配置',
  `allowdomain` tinytext COMMENT '允许访问的域名',
  `version` varchar(20) DEFAULT NULL COMMENT '版本号',
  `site` varchar(200) DEFAULT NULL COMMENT '网址',
  `menu` text COMMENT '菜单',
  `timestamp` int(10) unsigned DEFAULT '0' COMMENT '安装时间',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态',
  `admin_enable` tinyint(1) DEFAULT '1' COMMENT '是否进入后台管理',
  `admin_config` text COMMENT '后台管理配置JSON(路径/菜单/表格等)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `app` (`app`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 ROW_FORMAT = COMPACT COMMENT = '模块,应用,组件';

DROP TABLE IF EXISTS `#@_config`;

CREATE TABLE `#@_config` (
  `name` varchar(38) NOT NULL DEFAULT '' COMMENT '配置名',
  `value` text NOT NULL COMMENT '参数',
  PRIMARY KEY (`name`),
  UNIQUE KEY `name` (`name`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 ROW_FORMAT = COMPACT COMMENT = '核心设置相关';