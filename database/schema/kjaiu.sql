
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_accounts` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `uid` int(10) NOT NULL DEFAULT '0',
  `currency` varchar(3) NOT NULL DEFAULT '' COMMENT '货币',
  `gateway` varchar(80) NOT NULL DEFAULT '' COMMENT '网关',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  `pay_time` int(11) NOT NULL DEFAULT '0' COMMENT '支付生效时间',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '描述',
  `amount_in` decimal(10,2) NOT NULL DEFAULT '0.00',
  `fees` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '费用',
  `amount_out` decimal(10,2) NOT NULL DEFAULT '0.00',
  `rate` decimal(10,5) NOT NULL DEFAULT '1.00000',
  `trans_id` text COMMENT '交易订单id',
  `invoice_id` int(10) NOT NULL DEFAULT '0',
  `refund` int(10) NOT NULL DEFAULT '0' COMMENT '退款至余额',
  `delete_time` int(11) NOT NULL DEFAULT '0',
  `aff_refund` int(2) NOT NULL DEFAULT '0' COMMENT '推介计划是否处理了退款',
  `refund_credit` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否退至余额:1是',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `invoiceid` (`invoice_id`) USING BTREE,
  KEY `userid` (`uid`) USING BTREE,
  KEY `date` (`create_time`) USING BTREE,
  KEY `transid` (`trans_id`(32)) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_activity_log` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `create_time` int(10) NOT NULL DEFAULT '0',
  `description` text,
  `user` varchar(255) NOT NULL DEFAULT '' COMMENT '操作人',
  `uid` int(10) NOT NULL DEFAULT '0',
  `ipaddr` text,
  `type` int(2) DEFAULT NULL COMMENT '日志类型1前台登录注册2',
  `activeid` int(11) DEFAULT NULL COMMENT '操作人',
  `usertype` varchar(255) DEFAULT NULL COMMENT '操作类型',
  `port` varchar(32) NOT NULL DEFAULT '' COMMENT '端口号',
  `type_data_id` int(10) DEFAULT '0' COMMENT '操作类型数据对应id',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `userid` (`uid`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='系统活动日志';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_activity_log_home` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `create_time` int(10) NOT NULL DEFAULT '0',
  `description` text,
  `user` varchar(255) NOT NULL DEFAULT '' COMMENT '操作人',
  `uid` int(10) NOT NULL DEFAULT '0',
  `ipaddr` text,
  `type` int(2) DEFAULT NULL COMMENT '日志类型1前台登录注册2',
  `activeid` int(11) DEFAULT NULL COMMENT '操作人',
  `usertype` varchar(255) DEFAULT NULL COMMENT '操作类型',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `userid` (`uid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='系统活动日志';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_admin_log` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `admin_username` text,
  `logintime` int(10) NOT NULL DEFAULT '0',
  `logouttime` int(10) NOT NULL DEFAULT '0',
  `ipaddress` text,
  `sessionid` text,
  `lastvisit` int(10) NOT NULL DEFAULT '0' COMMENT '最后一次操作',
  `port` varchar(32) DEFAULT '' COMMENT '端口号',
  PRIMARY KEY (`id`),
  KEY `logouttime` (`logouttime`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COMMENT='管理员访问日志';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_affiliate_ladder` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `turnover` decimal(12,2) DEFAULT '0.00' COMMENT '营业额',
  `bates` decimal(12,2) DEFAULT '0.00' COMMENT '提成比例',
  `is_flag` int(2) DEFAULT '0' COMMENT '是否开启  1开启  0  不开启',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_affiliates` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `date` int(11) NOT NULL DEFAULT '0',
  `uid` int(10) NOT NULL DEFAULT '0',
  `visitors` int(10) NOT NULL DEFAULT '0' COMMENT '访问数量',
  `registcount` int(10) NOT NULL DEFAULT '0' COMMENT '注册数量',
  `payamount` int(10) NOT NULL DEFAULT '0' COMMENT '订购数量',
  `onetime` int(1) NOT NULL DEFAULT '0',
  `audited_balance` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '待确认佣金',
  `balance` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '可提现佣金',
  `withdrawn` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '已提现佣金',
  `created_time` int(11) NOT NULL DEFAULT '0',
  `updated_time` int(11) NOT NULL DEFAULT '0',
  `withdraw_ing` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '提现中',
  `url_identy` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT '推介连接标识',
  `sum` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '总佣金',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `affiliateid` (`id`) USING BTREE,
  KEY `clientid` (`uid`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_affiliates_product_setting` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pid` int(11) NOT NULL DEFAULT '0' COMMENT '用户id',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `affiliate_enabled` tinyint(2) NOT NULL DEFAULT '0' COMMENT '是否启用推介',
  `affiliate_is_reorder` tinyint(2) NOT NULL DEFAULT '0',
  `affiliate_reorder` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '二次订购比例',
  `affiliate_is_renew` tinyint(2) NOT NULL DEFAULT '0',
  `affiliate_renew` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '续费比例',
  `affiliate_bates` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '推荐比率',
  `affiliate_type` tinyint(2) NOT NULL DEFAULT '0' COMMENT '推荐类型',
  `affiliate_renew_type` tinyint(2) NOT NULL DEFAULT '0' COMMENT '续费方式',
  `affiliate_reorder_type` tinyint(2) NOT NULL DEFAULT '0' COMMENT '二次订购方式',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_affiliates_products_setting` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pid` int(11) NOT NULL DEFAULT '0' COMMENT '用户id',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `affiliate_enabled` tinyint(2) NOT NULL DEFAULT '0' COMMENT '是否启用推介',
  `affiliate_is_reorder` tinyint(2) NOT NULL DEFAULT '0',
  `affiliate_reorder` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '二次订购比例',
  `affiliate_is_renew` tinyint(2) NOT NULL DEFAULT '0',
  `affiliate_renew` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '续费比例',
  `affiliate_bates` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '推荐比率',
  `affiliate_type` tinyint(2) NOT NULL DEFAULT '1' COMMENT '推荐类型',
  `affiliate_renew_type` tinyint(2) NOT NULL DEFAULT '1' COMMENT '续费方式',
  `affiliate_reorder_type` tinyint(2) NOT NULL DEFAULT '1' COMMENT '二次订购方式',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `pid` (`pid`)
) ENGINE=InnoDB AUTO_INCREMENT=60 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_affiliates_user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户id',
  `affid` int(11) NOT NULL DEFAULT '0' COMMENT '推荐id',
  `create_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  KEY `affid` (`affid`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_affiliates_user_setting` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户id',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `affiliate_enabled` tinyint(2) NOT NULL DEFAULT '0' COMMENT '是否启用推介',
  `affiliate_is_reorder` tinyint(2) NOT NULL DEFAULT '0',
  `affiliate_reorder` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '二次订购比例',
  `affiliate_is_renew` tinyint(2) NOT NULL DEFAULT '0',
  `affiliate_renew` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '续费比例',
  `affiliate_bates` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '推荐比率',
  `affiliate_type` tinyint(2) NOT NULL DEFAULT '0' COMMENT '推荐类型',
  `affiliate_renew_type` tinyint(2) NOT NULL DEFAULT '1' COMMENT '续费方式',
  `affiliate_reorder_type` tinyint(2) NOT NULL DEFAULT '1' COMMENT '二次订购方式',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_affiliates_user_temp` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '临时表',
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户id',
  `affid_uid` int(11) NOT NULL DEFAULT '0' COMMENT '推荐id临时记录用uid代替',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  KEY `affid` (`affid_uid`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_affiliates_withdraw` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户id',
  `num` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '提现金额',
  `type` int(3) NOT NULL DEFAULT '0' COMMENT '提现方式  余额1 仅记录2 流水支持3',
  `admin_id` int(11) NOT NULL DEFAULT '0' COMMENT '操作人id',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  `status` int(2) NOT NULL DEFAULT '0' COMMENT '1待审核  2审核通过  3拒绝',
  `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '原因',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_api` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL DEFAULT '',
  `password` varchar(255) NOT NULL DEFAULT '',
  `ip` text,
  `create_time` int(11) NOT NULL DEFAULT '0',
  `is_auto` tinyint(3) NOT NULL DEFAULT '0' COMMENT '是否是自动创建的',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='对外API验证表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_api_resource_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'api资源日志id',
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户ID',
  `pid` int(11) NOT NULL DEFAULT '0' COMMENT '产品ID',
  `version` varchar(25) NOT NULL DEFAULT '' COMMENT '版本',
  `description` varchar(2048) NOT NULL DEFAULT '' COMMENT '描述',
  `ip` varchar(256) NOT NULL DEFAULT '' COMMENT 'ip地址',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '修改时间',
  `port` varchar(32) DEFAULT '' COMMENT '端口号',
  `source` varchar(255) NOT NULL DEFAULT 'API' COMMENT '来源：API,WEB',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_api_user_product` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '客户ID',
  `pid` int(11) NOT NULL DEFAULT '0' COMMENT '产品ID',
  `ontrial` int(11) NOT NULL DEFAULT '0' COMMENT '试用数量',
  `qty` int(11) NOT NULL DEFAULT '0' COMMENT '最大购买数量',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_app_favorite` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '应用收藏',
  `pid` int(11) NOT NULL DEFAULT '0' COMMENT '应用id',
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户id',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_app_version` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '应用版本',
  `pid` int(11) NOT NULL DEFAULT '0' COMMENT '应用id',
  `version` varchar(20) NOT NULL DEFAULT '' COMMENT '版本',
  `new_function` text COMMENT '新增功能',
  `remove_function` text COMMENT '移除功能',
  `optimize_function` text COMMENT '优化功能',
  `replace_function` text COMMENT '替换功能',
  `bug_fix` text COMMENT 'BUG修复',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_areas` (
  `area_id` int(11) NOT NULL AUTO_INCREMENT,
  `pid` int(11) NOT NULL DEFAULT '0',
  `name` varchar(100) NOT NULL DEFAULT '',
  `show` tinyint(4) NOT NULL DEFAULT '1' COMMENT '是否展示',
  `sort` int(11) NOT NULL DEFAULT '0',
  `key` char(10) DEFAULT NULL,
  `type` tinyint(4) NOT NULL DEFAULT '1',
  `data_flag` tinyint(4) NOT NULL DEFAULT '1',
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`area_id`) USING BTREE,
  KEY `isShow` (`show`,`data_flag`) USING BTREE,
  KEY `areaType` (`type`) USING BTREE,
  KEY `parentId` (`pid`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=820304 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_auth_access` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '角色',
  `rule_name` varchar(100) NOT NULL DEFAULT '' COMMENT '规则唯一英文标识,全小写',
  `type` varchar(30) NOT NULL DEFAULT '' COMMENT '权限规则分类,请加应用前缀,如admin_',
  `rule_id` int(11) DEFAULT NULL COMMENT '权限id',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `role_id` (`role_id`) USING BTREE,
  KEY `rule_name` (`rule_name`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 ROW_FORMAT=COMPACT COMMENT='权限授权表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_auth_rule` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '规则id,自增主键',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '是否有效(0:无效,1:有效)',
  `app` varchar(40) NOT NULL DEFAULT '' COMMENT '规则所属app',
  `type` varchar(30) NOT NULL DEFAULT '' COMMENT '权限规则分类，请加应用前缀,如admin_',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '规则唯一英文标识,全小写',
  `param` varchar(100) NOT NULL DEFAULT '' COMMENT '额外url参数',
  `title` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '规则描述',
  `condition` varchar(200) NOT NULL DEFAULT '' COMMENT '规则附加条件',
  `pid` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '父级权限id',
  `is_display` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '是否显示1=是 0=否',
  `url` varchar(100) DEFAULT NULL COMMENT '跳转地址',
  `order` int(10) NOT NULL DEFAULT '1' COMMENT '排序',
  `language_map` varchar(200) NOT NULL DEFAULT '' COMMENT '多语言列表',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `module` (`app`,`status`,`type`) USING BTREE,
  KEY `name` (`name`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2203 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='权限规则表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_base_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) CHARACTER SET utf8 NOT NULL DEFAULT '' COMMENT '模块名称，英文字符',
  `desc` varchar(255) CHARACTER SET utf8 NOT NULL DEFAULT '' COMMENT '模块描述信息',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序值，越小越在前面',
  `delete_time` int(10) NOT NULL DEFAULT '0' COMMENT '删除时间戳，未删除0',
  `enable` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用，0-否，1-是',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COMMENT='首页基本信息';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_blacklist` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip` int(10) unsigned NOT NULL DEFAULT '0' COMMENT 'ip地址转为int',
  `create_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
  `type` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '原因类型1=后台登录',
  `username` varchar(255) NOT NULL DEFAULT '' COMMENT '用户名',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='黑名单';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_cancel_reason` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '原因',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_cancel_requests` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `relid` int(10) NOT NULL DEFAULT '0' COMMENT '主机id',
  `type` varchar(255) NOT NULL DEFAULT '' COMMENT 'submit提交,adopt通过',
  `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '取消原因',
  `create_time` int(10) NOT NULL DEFAULT '0',
  `update_time` int(10) NOT NULL DEFAULT '0',
  `delete_time` int(11) NOT NULL DEFAULT '0' COMMENT '删除时间',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '执行状态:0未执行,1成功,2失败',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `relid` (`relid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_cart_session` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(255) DEFAULT NULL,
  `sessionid` varchar(255) DEFAULT NULL,
  `cart_data` text,
  `status` varchar(10) DEFAULT NULL,
  `create_time` int(10) DEFAULT NULL,
  `expire_time` int(10) DEFAULT NULL,
  `update_time` int(10) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `uid` (`uid`(100)),
  KEY `sessionid` (`sessionid`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_certifi_company` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `auth_user_id` int(11) NOT NULL DEFAULT '0' COMMENT '认证用户uid',
  `auth_real_name` varchar(30) NOT NULL DEFAULT '' COMMENT '真实姓名',
  `auth_card_type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '卡类型',
  `auth_card_number` varchar(30) NOT NULL DEFAULT '' COMMENT '卡号',
  `company_name` varchar(30) NOT NULL DEFAULT '' COMMENT '公司名称',
  `company_organ_code` varchar(30) NOT NULL DEFAULT '' COMMENT '公司代码',
  `img_one` varchar(255) NOT NULL DEFAULT '',
  `img_two` varchar(255) NOT NULL DEFAULT '',
  `img_three` varchar(255) NOT NULL DEFAULT '',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1已认证，2未通过，3待审核，4已提交资料',
  `certify_id` varchar(50) NOT NULL DEFAULT '',
  `auth_fail` varchar(255) NOT NULL DEFAULT '' COMMENT '失败原因',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  `bank` varchar(255) DEFAULT NULL COMMENT '银行卡号',
  `phone` varchar(255) NOT NULL DEFAULT '' COMMENT '手机',
  `custom_fields1` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段1-10',
  `custom_fields2` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  `custom_fields3` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  `custom_fields4` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  `custom_fields5` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  `custom_fields6` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  `custom_fields7` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  `custom_fields8` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  `custom_fields9` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  `custom_fields10` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  `img_four` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `uid` (`auth_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='公司认证';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_certifi_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(10) unsigned NOT NULL DEFAULT '0',
  `certifi_name` varchar(50) NOT NULL DEFAULT '' COMMENT '认证名称',
  `company_name` varchar(255) DEFAULT '' COMMENT '公司名称',
  `card_type` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '卡类型1=大陆0=非大陆',
  `idcard` varchar(80) DEFAULT '' COMMENT '身份证号',
  `company_organ_code` varchar(150) DEFAULT '' COMMENT '执照号',
  `error` varchar(255) DEFAULT '' COMMENT '失败原因',
  `pic` text COMMENT '图片集合用逗号分割',
  `create_time` int(11) DEFAULT NULL COMMENT '提交时间',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '状态1=未通过2=通过',
  `type` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '认证类型1=个人2=企业3=个人转企业',
  `bank` varchar(255) NOT NULL DEFAULT '' COMMENT '银行卡',
  `phone` varchar(255) NOT NULL DEFAULT '' COMMENT '手机',
  `certifi_type` varchar(255) NOT NULL DEFAULT '',
  `custom_fields_log` varchar(255) DEFAULT '' COMMENT '自定义字段json',
  `notes` varchar(5000) NOT NULL DEFAULT '' COMMENT '其他信息',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='实名认证记录';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_certifi_person` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `auth_user_id` int(11) NOT NULL DEFAULT '0',
  `auth_real_name` varchar(30) NOT NULL DEFAULT '' COMMENT '认证真实姓名',
  `auth_card_type` tinyint(4) NOT NULL DEFAULT '0' COMMENT '认证卡号类型1大陆(默认)；0其他地区',
  `auth_card_number` varchar(50) NOT NULL DEFAULT '' COMMENT '认证卡号',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1已认证，2未通过，3待审核，4已提交资料',
  `img_one` varchar(255) NOT NULL DEFAULT '',
  `img_two` varchar(255) NOT NULL DEFAULT '',
  `img_three` varchar(255) NOT NULL DEFAULT '',
  `certify_id` varchar(32) NOT NULL DEFAULT '' COMMENT '认证证书',
  `auth_fail` varchar(255) NOT NULL DEFAULT '' COMMENT '失败原因',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  `phone` varchar(255) NOT NULL DEFAULT '' COMMENT '手机',
  `bank` varchar(255) DEFAULT NULL COMMENT '银行卡号',
  `custom_fields1` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段1-10',
  `custom_fields2` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  `custom_fields3` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  `custom_fields4` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  `custom_fields5` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  `custom_fields6` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  `custom_fields7` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  `custom_fields8` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  `custom_fields9` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  `custom_fields10` varchar(200) NOT NULL DEFAULT '' COMMENT '自定义字段',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `uid` (`auth_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='个人认证';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_certifi_result` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `auth_user_id` int(11) NOT NULL DEFAULT '0',
  `description` varchar(255) NOT NULL DEFAULT '',
  `create_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_certification` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL DEFAULT '0' COMMENT '客户ID',
  `idcard` varchar(50) NOT NULL DEFAULT '' COMMENT '身份证号码',
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '姓名',
  `id_image_address` varchar(500) NOT NULL DEFAULT '' COMMENT '身份证图片地址',
  `type` tinyint(4) NOT NULL DEFAULT '0' COMMENT '认证类型：1个人，0企业',
  `companyname` varchar(50) NOT NULL DEFAULT '' COMMENT '公司名称',
  `businesslicense` varchar(500) NOT NULL DEFAULT '' COMMENT '公司营业执照',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '客户认证状态：0未认证，1已认证，2未通过，3，待审核',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `client_id` (`client_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_client_care` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT '关怀名称',
  `trigger` varchar(100) NOT NULL COMMENT '触发条件；product_after_order:产品订购后XX天，选择产品或者产品组;product_before_order_due:产品到期前XX天，选择产品或者产品组;register_no_order:注册用户但未下单XX天;register_order_no_pay:注册用户下单未支付XX天;register_surpass:注册用户超过XX天未登录',
  `time` int(11) NOT NULL COMMENT '天数',
  `method` varchar(100) NOT NULL COMMENT '关怀方式(邮件email、短信message、微信wechat(暂不考虑))',
  `email_template_id` int(11) NOT NULL COMMENT '邮件通知模板ID',
  `message_template_id` int(11) NOT NULL COMMENT '短信通知模板ID',
  `wechat_template_id` int(11) NOT NULL COMMENT '微信通知模板ID',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1可用，0不可用',
  `range_type` tinyint(1) NOT NULL COMMENT '1大陆，0非大陆',
  `create_time` int(11) NOT NULL,
  `update_time` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_client_care_product_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `care_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_client_care_trigger` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `toggle_condition` varchar(100) NOT NULL,
  `type` varchar(50) NOT NULL,
  `create_time` int(11) NOT NULL,
  `update_time` int(11) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_client_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_name` varchar(45) NOT NULL DEFAULT '',
  `group_colour` varchar(45) DEFAULT NULL COMMENT '组颜色',
  `discount_percent` int(10) DEFAULT '0' COMMENT '折扣百分比',
  `susptermexempt` tinyint(1) DEFAULT NULL COMMENT '暂停/删除豁免权(1是0否)',
  `separateinvoices` tinyint(1) DEFAULT NULL COMMENT '拆分服务账单',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_clients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  `uuid` varchar(100) NOT NULL DEFAULT '',
  `username` varchar(50) NOT NULL DEFAULT '' COMMENT '客户名',
  `usertype` tinyint(4) NOT NULL DEFAULT '1' COMMENT '用户类型：1普通用户，2会员',
  `sex` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0未知，1男，2女',
  `avatar` varchar(255) NOT NULL DEFAULT '' COMMENT '客户头像',
  `profession` varchar(50) NOT NULL DEFAULT '' COMMENT '职业',
  `signature` varchar(255) NOT NULL DEFAULT '' COMMENT '个性签名',
  `companyname` varchar(80) NOT NULL DEFAULT '' COMMENT '所在公司',
  `email` varchar(100) NOT NULL DEFAULT '' COMMENT '邮件',
  `wechat_id` int(10) DEFAULT NULL COMMENT '微信id',
  `qq` varchar(50) NOT NULL DEFAULT '' COMMENT 'qq',
  `country` varchar(100) NOT NULL DEFAULT '' COMMENT '国家',
  `province` varchar(100) NOT NULL DEFAULT '' COMMENT '省份',
  `city` varchar(100) NOT NULL DEFAULT '' COMMENT '城市',
  `region` varchar(100) NOT NULL DEFAULT '' COMMENT '区',
  `address1` varchar(100) NOT NULL DEFAULT '' COMMENT '具体地址1',
  `postcode` varchar(100) NOT NULL DEFAULT '' COMMENT '邮编',
  `phone_code` int(11) NOT NULL DEFAULT '44' COMMENT '国际电话区号  默认44中国为+86',
  `phonenumber` varchar(100) NOT NULL DEFAULT '' COMMENT '电话',
  `tax_id` varchar(100) NOT NULL DEFAULT '' COMMENT '税号ID',
  `password` varchar(200) NOT NULL DEFAULT '' COMMENT '密码',
  `authmodule` varchar(200) NOT NULL DEFAULT '' COMMENT '授权模块',
  `authdata` varchar(200) NOT NULL DEFAULT '' COMMENT '授权数据',
  `currency` int(10) NOT NULL DEFAULT '0' COMMENT '使用货币ID',
  `defaultgateway` varchar(200) NOT NULL DEFAULT '' COMMENT '选择默认支付接口',
  `credit` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '余额',
  `taxexempt` tinyint(1) NOT NULL DEFAULT '0' COMMENT '免税（1：是:0：否）',
  `latefeeoveride` tinyint(1) NOT NULL DEFAULT '0' COMMENT '滞纳金覆盖（1：是；0：否）',
  `overideduenotices` tinyint(1) NOT NULL DEFAULT '0' COMMENT '覆盖过期notices（是，否）',
  `separateinvoices` tinyint(1) NOT NULL DEFAULT '0' COMMENT '单独发票（1：是；0：否）',
  `disableautocc` tinyint(1) NOT NULL DEFAULT '0' COMMENT '禁用自动CC处理（是，否）',
  `notes` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `billingcid` int(10) NOT NULL DEFAULT '0' COMMENT '付款联系人（子账户）ID',
  `securityqid` int(10) NOT NULL DEFAULT '0',
  `securityqans` varchar(100) NOT NULL DEFAULT '',
  `groupid` int(11) NOT NULL DEFAULT '0' COMMENT '用户组ID',
  `cardtype` varchar(765) NOT NULL DEFAULT '',
  `cardlastfour` char(4) NOT NULL DEFAULT '' COMMENT '信用卡后四位',
  `cardnum` blob COMMENT '信用卡号',
  `startdate` blob,
  `expdate` blob,
  `issuenumber` blob,
  `bankname` varchar(100) NOT NULL DEFAULT '',
  `banktype` varchar(100) NOT NULL DEFAULT '',
  `bankcode` blob,
  `bankacct` blob,
  `gatewayid` int(11) NOT NULL DEFAULT '0',
  `lastlogin` int(11) NOT NULL DEFAULT '0' COMMENT '最后登录时间',
  `lastloginip` varchar(50) NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `ip` varchar(50) NOT NULL DEFAULT '' COMMENT 'IP地址',
  `host` varchar(50) NOT NULL DEFAULT '' COMMENT '主机',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态（1激活，0未激活，2关闭）',
  `language` varchar(100) NOT NULL DEFAULT '' COMMENT '语言',
  `pwresetkey` varchar(100) NOT NULL DEFAULT '' COMMENT '密码重置key',
  `emailoptout` int(11) NOT NULL DEFAULT '0',
  `marketing_emails_opt_in` tinyint(1) NOT NULL DEFAULT '0' COMMENT '发送客户营销邮件（1：是；0：否）',
  `overrideautoclose` int(11) NOT NULL DEFAULT '0' COMMENT '覆盖自动状态更新（1：是；0：否）',
  `allow_sso` tinyint(4) NOT NULL DEFAULT '0',
  `email_verified` tinyint(4) NOT NULL DEFAULT '0',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) unsigned zerofill NOT NULL DEFAULT '0000000000' COMMENT '更新时间',
  `pwresetexpiry` int(11) NOT NULL DEFAULT '0' COMMENT '密码重置过期时间',
  `know_us` varchar(255) DEFAULT NULL COMMENT '了解途径',
  `initiative_renew` tinyint(1) NOT NULL DEFAULT '0' COMMENT '余额自动续费(1自动,0手动)',
  `is_login_sms_reminder` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '是否开启登录短信提醒 0=默认，不开启  1= 开启',
  `sale_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '销售id，后台销售表销售id',
  `activation` tinyint(2) NOT NULL DEFAULT '0' COMMENT '激活',
  `api_password` varchar(50) NOT NULL DEFAULT '' COMMENT '魔方财务api密码',
  `second_verify` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否开启二次验证:0默认不开启,1开启',
  `track_status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '销售跟踪状态:1待开始，2跟进中，3已完成',
  `email_remind` tinyint(1) NOT NULL DEFAULT '1' COMMENT '邮箱提醒',
  `is_open_credit_limit` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1:开启信用额',
  `credit_limit` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '信用额',
  `credit_limit_balance` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '信用额余额',
  `repayment_date` int(11) NOT NULL DEFAULT '0' COMMENT '还款日',
  `bill_generation_date` int(11) NOT NULL DEFAULT '0' COMMENT '账单生成日',
  `bill_repayment_period` int(11) NOT NULL DEFAULT '0' COMMENT '账单还款期限',
  `credit_limit_create_time` int(11) NOT NULL DEFAULT '0' COMMENT '信用额启用时间',
  `api_open` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否开启API',
  `api_create_time` int(11) NOT NULL DEFAULT '0' COMMENT 'api开通时间',
  `lock_reason` text COMMENT 'api锁定原因',
  `api_lock_time` int(11) NOT NULL DEFAULT '0' COMMENT 'api锁定时间',
  `send_close` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否关闭发送邮件短信:0否默认，1是',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `wechat_id` (`wechat_id`) USING BTREE,
  KEY `sale_id` (`sale_id`),
  KEY `phonenumber` (`phonenumber`),
  KEY `currency` (`currency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_clients_level_rule` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `level_name` varchar(50) NOT NULL DEFAULT '' COMMENT '客户等级名称',
  `expense` varchar(50) NOT NULL DEFAULT '' COMMENT '支出',
  `buy_num` varchar(50) NOT NULL DEFAULT '' COMMENT '购买商品数量',
  `login_times` varchar(50) NOT NULL DEFAULT '' COMMENT '累计登陆次数',
  `last_login_times` varchar(100) NOT NULL DEFAULT '' COMMENT '最近x天登陆次数',
  `renew_times` varchar(50) NOT NULL DEFAULT '' COMMENT '续费次数',
  `last_renew_times` varchar(100) NOT NULL DEFAULT '' COMMENT '最近x天续费次数',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_clients_oauth` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `type` varchar(32) NOT NULL COMMENT '类型',
  `uid` int(10) NOT NULL COMMENT '用户ID',
  `openid` varchar(64) NOT NULL COMMENT '三方登录的id',
  `oauth` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  KEY `openid` (`openid`),
  KEY `type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_clients_track_record` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '客户跟踪记录表',
  `uid` int(11) NOT NULL COMMENT '客户ID',
  `des` text COMMENT '记录',
  `create_time` int(11) NOT NULL COMMENT '创建时间',
  `update_time` int(11) NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_clients_track_remark` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '补充说明ID',
  `track_id` int(11) NOT NULL COMMENT '记录ID',
  `remark` text COMMENT '补充说明',
  `create_time` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_configuration` (
  `setting` text NOT NULL,
  `value` text NOT NULL,
  `create_time` int(10) DEFAULT NULL,
  `update_time` int(10) DEFAULT NULL,
  KEY `setting` (`setting`(32)) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_contacts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  `uid` int(10) NOT NULL DEFAULT '0' COMMENT '主账户id',
  `username` varchar(50) NOT NULL DEFAULT '' COMMENT '客户名',
  `sex` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0未知，1男，2女',
  `avatar` varchar(255) NOT NULL DEFAULT '' COMMENT '客户头像',
  `companyname` varchar(80) NOT NULL DEFAULT '' COMMENT '所在公司',
  `email` varchar(100) NOT NULL DEFAULT '' COMMENT '邮件',
  `wechat_id` int(10) DEFAULT NULL COMMENT '微信id',
  `country` varchar(100) NOT NULL DEFAULT '' COMMENT '国家',
  `province` varchar(100) NOT NULL DEFAULT '' COMMENT '省份',
  `city` varchar(100) NOT NULL DEFAULT '' COMMENT '城市',
  `region` varchar(100) NOT NULL DEFAULT '' COMMENT '区',
  `address1` varchar(100) NOT NULL DEFAULT '' COMMENT '具体地址1',
  `address2` varchar(100) NOT NULL DEFAULT '' COMMENT '具体地址2',
  `postcode` varchar(100) NOT NULL DEFAULT '' COMMENT '邮编',
  `phonenumber` varchar(100) NOT NULL DEFAULT '' COMMENT '电话',
  `password` varchar(200) NOT NULL DEFAULT '' COMMENT '密码',
  `permissions` text COMMENT '权限',
  `generalemails` int(1) NOT NULL DEFAULT '0' COMMENT '普通邮件通知',
  `invoiceemails` int(1) NOT NULL DEFAULT '0' COMMENT '账单邮件通知',
  `productemails` int(1) NOT NULL DEFAULT '0' COMMENT '产品邮件通知',
  `supportemails` int(1) NOT NULL DEFAULT '0' COMMENT '工单邮件通知',
  `authmodule` varchar(200) NOT NULL DEFAULT '' COMMENT '授权模块',
  `authdata` varchar(200) NOT NULL DEFAULT '' COMMENT '授权数据',
  `lastlogin` int(11) NOT NULL DEFAULT '0' COMMENT '最后登录时间',
  `lastloginip` varchar(255) NOT NULL DEFAULT '' COMMENT '登录ip',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态（1激活，0未激活，2关闭）',
  `pwresetkey` varchar(100) NOT NULL DEFAULT '' COMMENT '密码重置key',
  `pwresetexpiry` int(11) NOT NULL DEFAULT '0' COMMENT '密码重置过期时间',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) unsigned zerofill NOT NULL DEFAULT '0000000000' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `wechat_id` (`wechat_id`) USING BTREE,
  KEY `uid` (`uid`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_contract` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '名称',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态：0关闭(默认)，1显示',
  `force` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否强制：0否(默认)，1是',
  `product_id` varchar(100) NOT NULL DEFAULT '' COMMENT '产品ID：,隔开',
  `notes` tinyint(1) NOT NULL DEFAULT '0' COMMENT '提示：0不提示(默认)，1全局提示，2产品页提示',
  `represent` varchar(50) NOT NULL DEFAULT '' COMMENT '授权代表',
  `phonenumber` varchar(50) NOT NULL DEFAULT '' COMMENT '代表电话',
  `inscribe_custom` tinyint(1) NOT NULL DEFAULT '0' COMMENT '落款信息是否自定义：1自定义，0默认',
  `nocheck` tinyint(1) NOT NULL DEFAULT '0' COMMENT '无需审核:1是，0否',
  `suspended` int(11) NOT NULL DEFAULT '0' COMMENT '未签订xx天后进行动作',
  `suspended_type` varchar(25) NOT NULL DEFAULT '' COMMENT '未签订合同：类型：suspended暂停，noaccess产品内容页无法访问',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `is_post` tinyint(1) NOT NULL DEFAULT '0' COMMENT '支持邮寄：1是，0否',
  `content` text COMMENT '合同内容',
  `base` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否基础合同：1是，0否',
  `email` varchar(255) NOT NULL DEFAULT '' COMMENT '邮箱',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_contract_pdf` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pdf_num` varchar(50) NOT NULL DEFAULT '' COMMENT '合同编号',
  `contract_id` int(11) NOT NULL DEFAULT '0' COMMENT '合同模板ID',
  `uid` int(11) NOT NULL DEFAULT '0',
  `host_id` int(11) NOT NULL DEFAULT '0',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0已作废，1已签订，2待签订，3待邮寄，4已邮寄',
  `pdf_address` varchar(500) NOT NULL DEFAULT '' COMMENT 'PDF地址',
  `information` varchar(500) NOT NULL DEFAULT '' COMMENT '主机信息',
  `ip` varchar(20) NOT NULL DEFAULT '' COMMENT '用户IP',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `express_company` varchar(255) NOT NULL DEFAULT '' COMMENT '快递公司',
  `express_order` varchar(255) NOT NULL DEFAULT '' COMMENT '快递单号',
  `remark` varchar(500) NOT NULL DEFAULT '',
  `is_post` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否邮寄：1是，0否',
  `sign_addr` varchar(5000) NOT NULL DEFAULT '' COMMENT '签名图片地址',
  `cancel_post` tinyint(1) NOT NULL DEFAULT '0' COMMENT '已取消过邮寄',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `PDF` (`pdf_num`) USING BTREE,
  KEY `uid` (`uid`) USING BTREE,
  KEY `host_id` (`host_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=154 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_credit` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `uid` int(10) NOT NULL DEFAULT '0',
  `create_time` int(10) NOT NULL DEFAULT '0',
  `description` text,
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `relid` int(10) NOT NULL DEFAULT '0',
  `aff_refund` int(2) NOT NULL DEFAULT '0' COMMENT '推介计划是否处理了退款',
  `notes` text COMMENT '备注',
  `balance` decimal(10,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `uid` (`uid`),
  KEY `relid` (`relid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_credit_limit` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `uid` int(10) NOT NULL DEFAULT '0',
  `create_time` int(10) NOT NULL DEFAULT '0',
  `description` text NOT NULL COMMENT '''',
  `type` varchar(50) NOT NULL DEFAULT '',
  `notes` text NOT NULL COMMENT '备注',
  `handle_id` int(10) NOT NULL DEFAULT '0' COMMENT '操作人id',
  `ip` varchar(20) DEFAULT '' COMMENT '用户ip',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_cron_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `method` varchar(255) NOT NULL DEFAULT '',
  `value` text,
  `create_time` int(10) NOT NULL DEFAULT '0',
  `update_time` int(10) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_currencies` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL DEFAULT '',
  `prefix` varchar(10) NOT NULL DEFAULT '',
  `suffix` varchar(10) NOT NULL DEFAULT '',
  `format` varchar(10) NOT NULL DEFAULT '',
  `rate` decimal(10,5) NOT NULL DEFAULT '1.00000',
  `default` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1默认货币',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY ```code``` (`code`),
  KEY `default` (`default`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_customfields` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `type` text COMMENT 'product(产品层自定义字段),client(用户自定义字段),ticket(工单自定义字段)',
  `relid` int(10) NOT NULL DEFAULT '0' COMMENT '父ID',
  `fieldname` varchar(255) NOT NULL DEFAULT '',
  `fieldtype` varchar(255) NOT NULL DEFAULT '',
  `description` varchar(255) NOT NULL DEFAULT '',
  `fieldoptions` varchar(255) NOT NULL DEFAULT '',
  `regexpr` varchar(255) NOT NULL DEFAULT '',
  `adminonly` int(10) NOT NULL DEFAULT '0',
  `required` tinyint(1) NOT NULL DEFAULT '0',
  `showorder` tinyint(1) NOT NULL DEFAULT '0',
  `showinvoice` tinyint(1) NOT NULL DEFAULT '0',
  `sortorder` int(10) NOT NULL DEFAULT '0' COMMENT '排序',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  `upstream_id` int(11) NOT NULL DEFAULT '0' COMMENT '对应上游自定义字段ID',
  `showdetail` tinyint(1) NOT NULL DEFAULT '0' COMMENT '产品内页显示',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `serviceid` (`relid`) USING BTREE,
  KEY `type` (`type`(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_customfieldsvalues` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fieldid` int(10) NOT NULL DEFAULT '0',
  `relid` int(10) NOT NULL DEFAULT '0' COMMENT 'hostid或者客户ID或者工单id',
  `value` text,
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `fieldid_relid` (`fieldid`,`relid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_dcim_buy_record` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户ID',
  `relid` int(11) NOT NULL DEFAULT '0' COMMENT '流量包id',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '产品名称',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '价格',
  `status` tinyint(3) NOT NULL DEFAULT '0' COMMENT '0未支付 1已付款',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `pay_time` int(11) NOT NULL DEFAULT '0' COMMENT '支付时间',
  `capacity` int(10) NOT NULL DEFAULT '0' COMMENT '流量包大小',
  `show_status` tinyint(10) NOT NULL DEFAULT '0' COMMENT '前台显示状态 0显示 1不显示',
  `invoiceid` int(11) NOT NULL DEFAULT '0' COMMENT '账单ID',
  `type` varchar(255) NOT NULL DEFAULT '' COMMENT '类型(flow_packet流量包reinstall_times重装次数)',
  `hostid` int(11) NOT NULL DEFAULT '0' COMMENT 'hostid',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  KEY `relid` (`relid`),
  KEY `invoiceid` (`invoiceid`),
  KEY `name` (`name`(100)),
  KEY `hostid` (`hostid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_dcim_flow_packet` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '流量包名称',
  `capacity` int(10) NOT NULL DEFAULT '0' COMMENT '流量包容量(G)',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '价格',
  `allow_products` text COMMENT '允许使用的产品',
  `status` tinyint(3) NOT NULL DEFAULT '0' COMMENT '0禁用 1启用',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '修改时间',
  `sale_times` int(11) NOT NULL DEFAULT '0' COMMENT '销售次数',
  `stock` int(10) NOT NULL DEFAULT '0' COMMENT '库存',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='dcim流量包表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_dcim_percent` (
  `id` int(11) NOT NULL DEFAULT '0',
  `hostid` int(11) NOT NULL DEFAULT '0',
  `percent` varchar(255) NOT NULL DEFAULT '',
  `send_email` tinyint(3) NOT NULL DEFAULT '0',
  `create_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `hostid` (`hostid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用于流量超过百分比记录邮件是否发送';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_dcim_servers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `serverid` int(11) NOT NULL DEFAULT '0' COMMENT '服务器ID',
  `reinstall_times` int(11) NOT NULL DEFAULT '0' COMMENT '每周重装次数',
  `buy_times` tinyint(4) NOT NULL DEFAULT '0' COMMENT '是否启用付费重装 0禁用 1启用',
  `reinstall_price` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '重装单次价格',
  `auth` text COMMENT '功能设置',
  `api_status` tinyint(3) NOT NULL DEFAULT '0' COMMENT 'api状态 0失败1成功',
  `area` text COMMENT '存储区域',
  `os` text COMMENT '所有镜像数据',
  `bill_type` varchar(255) NOT NULL DEFAULT '' COMMENT '流量计费方式',
  `flow_remind` text COMMENT '流量提醒设置',
  `ip_customid` int(11) NOT NULL DEFAULT '0',
  `is_certifi` varchar(5000) NOT NULL DEFAULT '' COMMENT '操作是否需要实名认证，json格式字符串',
  PRIMARY KEY (`id`),
  KEY `serverid` (`serverid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='dcim接口附加功能配置表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_developer` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0',
  `name` varchar(50) CHARACTER SET utf8 NOT NULL DEFAULT '',
  `desc` varchar(255) CHARACTER SET utf8 NOT NULL DEFAULT '',
  `status` varchar(25) NOT NULL DEFAULT 'Pending',
  `sort` int(11) NOT NULL DEFAULT '0',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  `suspended_reason` varchar(255) NOT NULL DEFAULT '',
  `cancelled_reason` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_downloadcats` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `parentid` int(10) NOT NULL DEFAULT '0' COMMENT '父ID',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '分类名',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '分类描述',
  `hidden` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否隐藏分类',
  `create_time` int(10) NOT NULL DEFAULT '0',
  `update_time` int(10) NOT NULL DEFAULT '0',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `parentid` (`parentid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_downloads` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `category` int(10) NOT NULL DEFAULT '0',
  `type` text,
  `title` text,
  `description` text,
  `downloads` int(10) NOT NULL DEFAULT '0',
  `location` text,
  `clientsonly` tinyint(1) NOT NULL DEFAULT '0',
  `hidden` tinyint(1) NOT NULL DEFAULT '0',
  `productdownload` tinyint(1) NOT NULL DEFAULT '0',
  `create_time` int(10) NOT NULL DEFAULT '0',
  `update_time` int(10) NOT NULL DEFAULT '0',
  `locationname` varchar(255) CHARACTER SET utf8 NOT NULL DEFAULT '' COMMENT '原始名字',
  `filetype` varchar(255) CHARACTER SET utf8 NOT NULL DEFAULT '' COMMENT 'upload  ',
  `url` varchar(255) DEFAULT '' COMMENT 'url',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `title` (`title`(32)) USING BTREE,
  KEY `downloads` (`downloads`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_email_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0',
  `subject` varchar(100) NOT NULL DEFAULT '',
  `message` text,
  `create_time` int(11) NOT NULL DEFAULT '0',
  `to` varchar(100) NOT NULL DEFAULT '',
  `cc` varchar(500) NOT NULL DEFAULT '',
  `bcc` varchar(500) NOT NULL DEFAULT '',
  `status` tinyint(4) NOT NULL DEFAULT '0',
  `fail_reason` varchar(500) NOT NULL DEFAULT '' COMMENT '失败原因',
  `is_admin` tinyint(4) NOT NULL DEFAULT '0' COMMENT '是否是管理员',
  `attachments` text COMMENT '邮件附件地址，相对路径地址',
  `ip` varchar(255) NOT NULL DEFAULT '' COMMENT 'ip',
  `port` varchar(32) DEFAULT '' COMMENT '端口号',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_email_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(100) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `name` varchar(100) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `name_en` varchar(100) COLLATE utf8_unicode_ci NOT NULL DEFAULT '' COMMENT '邮件名称英文',
  `subject` varchar(100) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `message` text COLLATE utf8_unicode_ci,
  `attachments` text COLLATE utf8_unicode_ci,
  `fromname` varchar(100) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `fromemail` varchar(100) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `disabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态:0默认显示，1隐藏',
  `custom` tinyint(1) NOT NULL DEFAULT '0',
  `language` varchar(100) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `copyto` text COLLATE utf8_unicode_ci,
  `blind_copy_to` text COLLATE utf8_unicode_ci,
  `plaintext` tinyint(1) NOT NULL DEFAULT '0',
  `create_time` int(10) NOT NULL DEFAULT '0',
  `update_time` int(10) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `type` (`type`(32)) USING BTREE,
  KEY `name` (`name`(64)) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=117 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_evaluation` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '评论',
  `type` varchar(20) NOT NULL DEFAULT '' COMMENT '关联类型(products:产品)',
  `rid` int(11) NOT NULL DEFAULT '0' COMMENT '关联id',
  `eid` int(11) NOT NULL DEFAULT '0' COMMENT '上级评论id',
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '回复评论id',
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户id',
  `content` text COMMENT '内容',
  `score` float NOT NULL DEFAULT '0' COMMENT '评分',
  `like_num` int(11) NOT NULL DEFAULT '0' COMMENT '点赞数',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0:未审核1:审核通过2:审核未通过3:已删除',
  `reason` varchar(100) NOT NULL DEFAULT '' COMMENT '驳回原因',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_evaluation_like` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '评论点赞',
  `eid` int(11) NOT NULL DEFAULT '0' COMMENT '评论id',
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户id',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_express` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '快递ID',
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '快递名称',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '价格',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_friendly_links` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '',
  `domain` varchar(128) NOT NULL DEFAULT '',
  `link_tag` varchar(255) NOT NULL DEFAULT '',
  `is_open` tinyint(1) NOT NULL DEFAULT '0',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_hook` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '钩子类型(1:系统钩子;2:应用钩子;3:模板钩子;4:后台模板钩子)',
  `once` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '是否只允许一个插件运行(0:多个;1:一个)',
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '钩子名称',
  `hook` varchar(50) NOT NULL DEFAULT '' COMMENT '钩子',
  `app` varchar(15) NOT NULL DEFAULT '' COMMENT '应用名(只有应用钩子才用)',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '描述',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `type` (`type`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='系统钩子表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_hook_plugin` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `list_order` float NOT NULL DEFAULT '10000' COMMENT '排序',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态(0:禁用,1:启用)',
  `hook` varchar(50) NOT NULL DEFAULT '' COMMENT '钩子名',
  `plugin` varchar(50) NOT NULL DEFAULT '' COMMENT '插件',
  `module` varchar(25) NOT NULL DEFAULT 'addons' COMMENT '插件钩子所属模块',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='系统钩子插件表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_host` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `uid` int(10) NOT NULL DEFAULT '0' COMMENT '用户id',
  `orderid` int(10) NOT NULL DEFAULT '0' COMMENT '订单id',
  `productid` int(10) NOT NULL DEFAULT '0' COMMENT '产品id',
  `serverid` int(10) NOT NULL DEFAULT '0' COMMENT '服务器id',
  `regdate` int(10) NOT NULL DEFAULT '0' COMMENT '开通时间',
  `domain` varchar(255) NOT NULL DEFAULT '' COMMENT '主机名',
  `payment` varchar(50) NOT NULL DEFAULT '' COMMENT '支付方式',
  `firstpaymentamount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '首付金额',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '续费金额',
  `billingcycle` text COMMENT '付款周期',
  `last_settle` int(10) NOT NULL DEFAULT '0' COMMENT '上次结算时间，用于周期为小时/天',
  `nextduedate` int(10) NOT NULL DEFAULT '0' COMMENT '到期时间',
  `nextinvoicedate` int(10) NOT NULL DEFAULT '0' COMMENT '下次生成账单时间',
  `termination_date` int(10) NOT NULL DEFAULT '0' COMMENT '终止日期',
  `completed_date` int(10) NOT NULL DEFAULT '0' COMMENT '完成时间',
  `domainstatus` enum('Pending','Active','Suspended','Cancelled','Fraud','Completed','Deleted','Verifiy_Active','Overdue_Active','Issue_Active') NOT NULL DEFAULT 'Pending' COMMENT '状态',
  `username` varchar(255) NOT NULL DEFAULT '' COMMENT '用户名',
  `password` varchar(255) NOT NULL DEFAULT '' COMMENT '密码',
  `notes` text COMMENT '备注',
  `subscriptionid` text COMMENT '订阅编号',
  `promoid` int(10) NOT NULL DEFAULT '0' COMMENT '优惠码id',
  `suspendreason` text COMMENT '暂停原因',
  `overideautosuspend` tinyint(1) NOT NULL DEFAULT '0' COMMENT '修改暂停时间',
  `overidesuspenduntil` int(10) NOT NULL DEFAULT '0' COMMENT '不要暂停直至',
  `dedicatedip` text COMMENT '独立ip地址',
  `assignedips` text COMMENT '分配的ip地址',
  `ns1` varchar(255) NOT NULL DEFAULT '' COMMENT '域名服务器1',
  `ns2` varchar(255) NOT NULL DEFAULT '' COMMENT '域名服务器2',
  `diskusage` int(10) NOT NULL DEFAULT '0',
  `disklimit` int(10) NOT NULL DEFAULT '0',
  `bwusage` decimal(10,2) NOT NULL DEFAULT '0.00',
  `bwlimit` int(10) NOT NULL DEFAULT '0',
  `user_cate_id` int(10) NOT NULL DEFAULT '0' COMMENT '用户分类显示使用id',
  `lastupdate` int(10) NOT NULL DEFAULT '0',
  `create_time` int(10) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) NOT NULL DEFAULT '0',
  `suspend_time` int(10) NOT NULL DEFAULT '0' COMMENT '暂停时间',
  `auto_terminate_end_cycle` tinyint(1) NOT NULL DEFAULT '0' COMMENT '到期后自动删除',
  `auto_terminate_reason` text COMMENT '自动删除原因',
  `dcimid` int(11) NOT NULL DEFAULT '0' COMMENT 'dcimID',
  `dcim_os` int(11) NOT NULL DEFAULT '0' COMMENT 'dcim操作系统ID,用于自动开通',
  `os` varchar(255) NOT NULL DEFAULT '' COMMENT '显示的操作系统',
  `os_url` varchar(255) NOT NULL DEFAULT '' COMMENT '操作系统图标地址',
  `reinstall_info` varchar(255) NOT NULL DEFAULT '' COMMENT '重装次数记录',
  `remark` text,
  `show_last_act_message` tinyint(3) NOT NULL DEFAULT '1' COMMENT '是否显示上次执行结果',
  `port` int(11) NOT NULL DEFAULT '0' COMMENT '端口',
  `dcim_area` int(10) NOT NULL DEFAULT '0' COMMENT 'DCIM机房ID',
  `flag` tinyint(2) NOT NULL DEFAULT '0' COMMENT '是否有折扣',
  `flag_cycle` varchar(255) NOT NULL DEFAULT '' COMMENT '是否有折扣',
  `stream_info` varchar(255) NOT NULL DEFAULT '' COMMENT '代理信息',
  `initiative_renew` tinyint(2) NOT NULL DEFAULT '0' COMMENT '是否自动续费',
  `percent_value` decimal(10,2) NOT NULL DEFAULT '120.00' COMMENT '购买该商品时的上游价格百分比',
  `agent_client` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否代理商客户购买',
  `upstream_cost` varchar(25) NOT NULL DEFAULT '' COMMENT '上游成本,存文本',
  `upstream_configoption` text COMMENT '上游配置：比如v10,json格式',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `serviceid` (`id`) USING BTREE,
  KEY `userid` (`uid`) USING BTREE,
  KEY `orderid` (`orderid`) USING BTREE,
  KEY `productid` (`productid`) USING BTREE,
  KEY `serverid` (`serverid`) USING BTREE,
  KEY `domain` (`domain`(64)) USING BTREE,
  KEY `domainstatus` (`domainstatus`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_host_category` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `uid` int(10) NOT NULL DEFAULT '0',
  `name` varchar(255) NOT NULL DEFAULT '',
  `default` int(1) NOT NULL DEFAULT '0' COMMENT '是否为默认分类',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_host_config_options` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `relid` int(10) NOT NULL DEFAULT '0' COMMENT '产品id',
  `configid` int(10) NOT NULL DEFAULT '0' COMMENT '配置id',
  `optionid` int(10) NOT NULL DEFAULT '0' COMMENT 'sub配置id',
  `qty` int(10) NOT NULL DEFAULT '0' COMMENT '滑条的值',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `relid_configid` (`relid`,`configid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_info_notice` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `relid` int(11) NOT NULL DEFAULT '0' COMMENT '关联ID,若type=product,relid就是产品ID',
  `type` varchar(20) DEFAULT 'product' COMMENT '通知类型:product产品相关通知,',
  `info` text COMMENT '通知消息',
  `create_time` int(11) DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) DEFAULT '0' COMMENT '更新时间',
  `admin` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否后台通知消息,1默认后台，0前台',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `relid` (`relid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_invoice_items` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(10) NOT NULL DEFAULT '0' COMMENT '发票id',
  `uid` int(10) NOT NULL DEFAULT '0',
  `type` varchar(30) NOT NULL DEFAULT '',
  `rel_id` int(10) NOT NULL DEFAULT '0' COMMENT 'host_id',
  `description` text,
  `description2` varchar(5000) NOT NULL DEFAULT '' COMMENT '前台账单内页描述',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '金额',
  `taxed` int(1) NOT NULL DEFAULT '0' COMMENT '税金',
  `due_time` int(11) NOT NULL DEFAULT '0' COMMENT '到期时间',
  `payment` varchar(20) NOT NULL DEFAULT '' COMMENT '支付类型',
  `notes` text COMMENT '票据说明',
  `delete_time` int(10) NOT NULL DEFAULT '0',
  `aff_sure_time` int(11) DEFAULT NULL COMMENT '确认时间',
  `aff_commission` decimal(12,2) DEFAULT NULL COMMENT '佣金',
  `aff_commmission_bates` decimal(12,2) DEFAULT NULL COMMENT '佣金比例',
  `aff_commmission_bates_type` int(2) DEFAULT NULL COMMENT '1金额  2比例',
  `is_aff` int(255) DEFAULT '0' COMMENT '是否确认  1已确定',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `invoiceid` (`invoice_id`) USING BTREE,
  KEY `userid` (`uid`,`type`,`rel_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_invoice_user_hide` (
  `uid` int(11) NOT NULL DEFAULT '0',
  `hide` varchar(255) DEFAULT NULL COMMENT '展示字段',
  PRIMARY KEY (`uid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_invoices` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `uid` int(10) NOT NULL DEFAULT '0',
  `invoice_num` text,
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '(date)',
  `update_time` int(11) NOT NULL DEFAULT '0',
  `due_time` int(11) NOT NULL DEFAULT '0' COMMENT '到期时间',
  `paid_time` int(11) NOT NULL DEFAULT '0' COMMENT '支付时间(datepaid)',
  `last_capture_attempt` int(11) NOT NULL DEFAULT '0',
  `subtotal` decimal(10,2) unsigned NOT NULL DEFAULT '0.00',
  `credit` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tax` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tax2` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `taxrate` decimal(10,2) NOT NULL DEFAULT '0.00',
  `taxrate2` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` text COMMENT 'Paid:已支付,Unpaid:未支付,Draft:已草稿,Overdue:已逾期,Cancelled:被取消,Refunded:已退款,Collections:已收藏',
  `payment` varchar(20) NOT NULL DEFAULT '' COMMENT '1微信支付,2微信国际支付,3支付宝支付,4支付宝国际支付',
  `notes` text,
  `delete_time` int(11) NOT NULL DEFAULT '0',
  `due_email_times` tinyint(3) NOT NULL DEFAULT '0' COMMENT '已发送逾期提醒邮件次数',
  `type` varchar(50) NOT NULL DEFAULT '' COMMENT '支付的产品类型:(recharge,product,renew)',
  `payment_status` varchar(50) NOT NULL DEFAULT '' COMMENT '支付接口回调支付状态',
  `aff_sure_time` int(11) DEFAULT NULL COMMENT '确认时间',
  `aff_commission` decimal(12,2) DEFAULT NULL COMMENT '佣金',
  `aff_commmission_bates` decimal(12,2) DEFAULT NULL COMMENT '佣金比例',
  `aff_commmission_bates_type` int(2) DEFAULT NULL COMMENT '1金额  2比例',
  `is_aff` int(255) DEFAULT '0' COMMENT '是否确认  1已确定',
  `is_cron` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否自动任务：0否默认，1是',
  `suffix` int(11) NOT NULL DEFAULT '0' COMMENT '账单后缀',
  `use_credit_limit` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1:使用信用额',
  `invoice_id` int(11) NOT NULL DEFAULT '0' COMMENT '信用额关联账单',
  `url` varchar(200) NOT NULL DEFAULT '' COMMENT '账单支付后回跳地址',
  `paymt` varchar(16) NOT NULL DEFAULT '' COMMENT '优先弹窗的支付模式 | 信用额：credit_limit  其他方式都为余额弹框',
  `is_delete` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1:标记删除',
  `credit_limit_prepayment` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1:信用额提前还款',
  `credit_limit_prepayment_invoices` text COMMENT '提前还款的账单ID',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `userid` (`uid`) USING BTREE,
  KEY `status` (`status`(3)) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=800000 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_invoicesid_tmp` (
  `original_invoicesid` int(10) NOT NULL DEFAULT '0',
  `old_invoicesid` int(10) NOT NULL DEFAULT '0',
  `new_invoicesid` int(10) NOT NULL DEFAULT '0',
  `total` decimal(10,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL DEFAULT '',
  `payload` longtext,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `reserved` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_knowledge_base` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID：1篇文章可以在多个种类里',
  `title` varchar(100) NOT NULL DEFAULT '' COMMENT '文章标题',
  `article` text COMMENT '内容',
  `views` int(11) NOT NULL DEFAULT '0' COMMENT '查看次数',
  `useful` int(11) NOT NULL DEFAULT '0' COMMENT '点赞次数',
  `hidden` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否隐藏:0默认显示,1隐藏',
  `login_view` tinyint(1) NOT NULL DEFAULT '0' COMMENT '登录查看:0默认所有人都可以看，1登录才能查看',
  `host_view` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否有激活产品才能查看:0默认所有人可看，1有激活产品才能查看',
  `order` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `create_by` int(11) NOT NULL DEFAULT '0' COMMENT '创建人',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `public_by` varchar(50) NOT NULL DEFAULT '' COMMENT '发布人',
  `public_time` varchar(100) NOT NULL DEFAULT '' COMMENT '发布时间',
  `image` varchar(10000) NOT NULL DEFAULT '' COMMENT '富文本图片地址',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_knowledge_base_cats` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '',
  `description` varchar(1000) NOT NULL DEFAULT '',
  `hidden` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0显示默认，1隐藏',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `name` (`name`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_knowledge_base_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL DEFAULT '0',
  `article_id` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `category_id` (`category_id`),
  KEY `article_id` (`article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_knowledge_base_tags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `article_id` int(11) NOT NULL DEFAULT '0',
  `tag` varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `article_id` (`article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_menu` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `type` varchar(50) NOT NULL DEFAULT '' COMMENT '菜单类型 client会员中心,www官网',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_menu_active` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(100) NOT NULL DEFAULT '' COMMENT 'client会员中心,www_top官网顶部,www_bottom官网底部',
  `menuid` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_menus` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `type` int(1) NOT NULL DEFAULT '1' COMMENT '菜单类型',
  `nav_list` text NOT NULL COMMENT '菜单json串',
  `active` int(1) NOT NULL DEFAULT '1' COMMENT '激活状态',
  `sort` int(1) NOT NULL DEFAULT '0' COMMENT '排序',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_message_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0',
  `params` varchar(255) NOT NULL DEFAULT '',
  `phone_code` varchar(10) NOT NULL DEFAULT '',
  `phone` varchar(20) NOT NULL DEFAULT '',
  `template_code` varchar(25) NOT NULL DEFAULT '',
  `content` varchar(255) NOT NULL DEFAULT '',
  `status` tinyint(4) NOT NULL DEFAULT '0',
  `fail_reason` varchar(500) NOT NULL DEFAULT '' COMMENT '失败原因',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `is_admin` tinyint(4) NOT NULL DEFAULT '0' COMMENT '是否管理员：1是，0否默认',
  `is_wx` tinyint(4) NOT NULL DEFAULT '0' COMMENT '是否微信消息：1是，0否默认',
  `ip` varchar(255) NOT NULL DEFAULT '' COMMENT 'ip',
  `port` varchar(32) DEFAULT '' COMMENT '端口号',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_message_template` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `template_id` varchar(64) NOT NULL DEFAULT '',
  `range_type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0大陆，1非大陆',
  `title` varchar(100) NOT NULL DEFAULT '',
  `content` text COMMENT '阿里云的参数形式:${subject};赛邮的参数形式@var{subject}',
  `remark` varchar(200) NOT NULL DEFAULT '' COMMENT '备注',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0未提交审核，1正在审核，2审核通过，3未通过审核',
  `sms_operator` varchar(50) NOT NULL DEFAULT '' COMMENT 'submail赛邮，aliyun阿里大鱼',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `template_id` (`template_id`)
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_message_template_link` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sms_temp_id` int(11) NOT NULL DEFAULT '0' COMMENT '短信模板ID',
  `type` tinyint(4) NOT NULL DEFAULT '0' COMMENT '选中模板类型:1generated_invoice生成账单,2invoice_pay账单支付,3invoice_overdue_pay账单支付逾期,4submit_ticket提交工单,5ticket_reply工单回复,6host_suspend产品暂停提醒,7unpay_invoice未支付账单,8send_code发送验证码',
  `range_type` tinyint(4) NOT NULL DEFAULT '0' COMMENT '0大陆，1非大陆',
  `sms_operator` varchar(20) NOT NULL DEFAULT '' COMMENT '短信运营商aliyun,submail',
  `is_use` tinyint(2) NOT NULL DEFAULT '0' COMMENT '是否启用',
  PRIMARY KEY (`id`),
  KEY ```type_range``` (`type`,`range_type`,`sms_operator`),
  KEY `sms_temp_id` (`sms_temp_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1237 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_module_queue` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `service_type` varchar(20) NOT NULL DEFAULT '',
  `service_id` int(10) unsigned NOT NULL DEFAULT '0',
  `module_name` varchar(64) NOT NULL DEFAULT '',
  `module_action` varchar(64) NOT NULL DEFAULT '',
  `last_attempt` int(11) NOT NULL DEFAULT '0',
  `last_attempt_error` text,
  `num_retries` smallint(5) unsigned NOT NULL DEFAULT '0',
  `completed` tinyint(1) NOT NULL DEFAULT '0',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_nav` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '名称',
  `url` varchar(255) NOT NULL DEFAULT '' COMMENT '地址',
  `pid` int(11) NOT NULL DEFAULT '0' COMMENT '上级id',
  `order` int(11) NOT NULL DEFAULT '0',
  `fa_icon` varchar(255) NOT NULL DEFAULT '',
  `nav_type` tinyint(7) NOT NULL DEFAULT '0' COMMENT '导航类型 0系统类型,1自定义页面,2产品中心',
  `relid` text NOT NULL COMMENT '关联的商品ID',
  `menuid` int(11) NOT NULL DEFAULT '0' COMMENT '菜单ID',
  `lang` text NOT NULL COMMENT '多语言',
  `plugin` varchar(50) NOT NULL DEFAULT '' COMMENT '插件名称:插件菜单,此值用于卸载时删除菜单',
  `menu_type` tinyint(7) NOT NULL DEFAULT '1' COMMENT '类型（这里的类型指的是哪里的菜单（会员和中心菜单，www头部菜单，底部菜单））',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `pid` (`pid`) USING BTREE,
  KEY `menuid` (`menuid`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=328 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_nav_group` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `groupname` varchar(255) NOT NULL DEFAULT '' COMMENT '分组名',
  `fa_icon` varchar(255) NOT NULL DEFAULT '' COMMENT '图标',
  `order` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_nav_group_user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户id',
  `groupid` int(11) DEFAULT '0' COMMENT '分组id',
  `is_show` tinyint(11) DEFAULT '0' COMMENT '是否显示',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `uid` (`uid`),
  KEY `groupid` (`groupid`)
) ENGINE=InnoDB AUTO_INCREMENT=336 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_news` (
  `relid` int(10) NOT NULL DEFAULT '0' COMMENT '关联的新闻id',
  `content` text COMMENT '内容',
  PRIMARY KEY (`relid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='新闻内容表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_news_menu` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `admin_id` int(10) NOT NULL DEFAULT '0' COMMENT '编辑管理员id',
  `parent_id` int(10) NOT NULL DEFAULT '0' COMMENT '父id',
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '标题',
  `keywords` varchar(255) NOT NULL DEFAULT '' COMMENT '关键字',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '描述',
  `head_img` varchar(255) NOT NULL DEFAULT '' COMMENT '展示图片',
  `read` int(10) NOT NULL DEFAULT '0' COMMENT '阅读量',
  `hidden` varchar(255) NOT NULL DEFAULT '' COMMENT '是否隐藏',
  `sort` int(10) NOT NULL DEFAULT '0' COMMENT '排序',
  `update_time` int(10) unsigned NOT NULL DEFAULT '0',
  `create_time` int(10) unsigned NOT NULL DEFAULT '0',
  `push_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '发布时间',
  `label` text COMMENT '标签',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `push_time` (`push_time`),
  KEY `parent_id_hidden` (`hidden`,`parent_id`) USING BTREE,
  KEY `admin_id` (`admin_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_news_type` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '父级id',
  `title` varchar(50) NOT NULL DEFAULT '0' COMMENT '父级名称',
  `sort` varchar(50) NOT NULL DEFAULT '0' COMMENT '排序',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '0=禁用1=启用',
  `hidden` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '是否隐藏1=是0=否',
  `alias` varchar(100) NOT NULL DEFAULT '' COMMENT '别名',
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_option` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `autoload` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '是否自动加载;1:自动加载;0:不自动加载',
  `option_name` varchar(64) NOT NULL DEFAULT '' COMMENT '配置名',
  `option_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '配置值',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `option_name` (`option_name`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='全站配置表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_orders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '客户ID',
  `ordernum` varchar(100) NOT NULL DEFAULT '' COMMENT '订单号',
  `status` varchar(30) NOT NULL DEFAULT 'Pending' COMMENT '订单状态：Pending待审核，Active已激活，Completed已完成,Suspend已暂停,Terminated被删除,Cancelled被取消,Fraud有欺诈',
  `pay_time` int(11) NOT NULL DEFAULT '0' COMMENT '支付时间',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '订单总额',
  `payment` varchar(50) NOT NULL DEFAULT '' COMMENT '支付方式',
  `promo_code` varchar(255) NOT NULL DEFAULT '' COMMENT '优惠码',
  `promo_type` varchar(30) NOT NULL DEFAULT '' COMMENT '优惠码类型',
  `promo_value` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '优惠值',
  `invoiceid` int(10) NOT NULL DEFAULT '0' COMMENT '账单id',
  `delete_time` int(11) NOT NULL DEFAULT '0',
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_pay_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL DEFAULT '0',
  `trans_id` int(11) NOT NULL DEFAULT '0' COMMENT '交易id',
  `payment` varchar(255) NOT NULL DEFAULT '' COMMENT '支付方式',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '金额',
  `currency` varchar(255) NOT NULL DEFAULT '' COMMENT '币种',
  `status` varchar(255) NOT NULL DEFAULT '' COMMENT '状态',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '描述',
  `create_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `invoice_id` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_payment_gateways` (
  `gateway` text,
  `setting` text,
  `value` text,
  `order` int(1) NOT NULL DEFAULT '0',
  KEY `gateway_setting` (`gateway`(32),`setting`(32)) USING BTREE,
  KEY `setting_value` (`setting`(32),`value`(32)) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_plugin` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增id',
  `type` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '插件类型;1:网站;8:微信',
  `has_admin` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '是否有后台管理,0:没有;1:有',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '状态;1:开启;0:禁用',
  `create_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '插件安装时间',
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '插件标识名,英文字母(惟一)',
  `title` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '插件名称',
  `url` text COMMENT '图标地址',
  `hooks` varchar(255) NOT NULL DEFAULT '' COMMENT '实现的钩子;以“,”分隔',
  `author` text COMMENT '作者',
  `author_url` text COMMENT '作者链接',
  `version` varchar(20) NOT NULL DEFAULT '' COMMENT '插件版本号',
  `description` text COMMENT '插件描述',
  `config` text COMMENT '插件配置',
  `module` varchar(255) NOT NULL DEFAULT '' COMMENT '插件模块路径',
  `order` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `help_url` varchar(2000) NOT NULL DEFAULT '' COMMENT '帮助文档',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='插件表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_pricing` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `type` enum('product','addon','configoptions','domainregister','domaintransfer','domainrenew','domainaddons') NOT NULL COMMENT '产品价格类型',
  `currency` int(10) NOT NULL DEFAULT '0' COMMENT '货币ID',
  `relid` int(10) NOT NULL DEFAULT '0' COMMENT '父ID',
  `osetupfee` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '一次性初装费',
  `hsetupfee` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '周期小时初装费',
  `dsetupfee` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '周期天初装费',
  `ontrialfee` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '试用初装费',
  `msetupfee` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '周期月初装费',
  `qsetupfee` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '周期季度初装费',
  `ssetupfee` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '周期半年初装费',
  `asetupfee` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '周期年初装费',
  `bsetupfee` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '周期两年初装费',
  `tsetupfee` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '周期三年初装费',
  `foursetupfee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `fivesetupfee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `sixsetupfee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `sevensetupfee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `eightsetupfee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `ninesetupfee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tensetupfee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `onetime` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '一次性费用',
  `hour` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '小时',
  `day` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '天',
  `ontrial` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '试用',
  `monthly` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '月',
  `quarterly` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '季度',
  `semiannually` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '半年',
  `annually` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '年',
  `biennially` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '两年',
  `triennially` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '三年',
  `fourly` decimal(10,2) NOT NULL DEFAULT '0.00',
  `fively` decimal(10,2) NOT NULL DEFAULT '0.00',
  `sixly` decimal(10,2) NOT NULL DEFAULT '0.00',
  `sevenly` decimal(10,2) NOT NULL DEFAULT '0.00',
  `eightly` decimal(10,2) NOT NULL DEFAULT '0.00',
  `ninely` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tenly` decimal(10,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `typerelid` (`type`,`relid`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_product_config_groups` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL DEFAULT '',
  `description` varchar(50) NOT NULL DEFAULT '',
  `upstream_id` int(11) NOT NULL DEFAULT '0' COMMENT '对应上游配置项组ID',
  `global` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否全局配置项组：1是，0否',
  PRIMARY KEY (`id`),
  KEY `upstream_id` (`upstream_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_product_config_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `gid` int(11) NOT NULL DEFAULT '0' COMMENT '配置组ID',
  `pid` int(11) NOT NULL DEFAULT '0' COMMENT '产品ID',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `gpid` (`gid`,`pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_product_config_options` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `gid` int(11) NOT NULL DEFAULT '0',
  `option_name` varchar(500) NOT NULL DEFAULT '' COMMENT '可配置选项名称',
  `option_type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '配置类型：1Dropdown(默认)，2radio，3Yes/No，4quantity',
  `qty_minimum` int(11) NOT NULL DEFAULT '0' COMMENT 'quantity滑动拉条的最小值',
  `qty_maximum` int(11) NOT NULL DEFAULT '0' COMMENT 'quantity滑动拉条的最大值',
  `order` int(11) NOT NULL DEFAULT '0',
  `hidden` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0显示默认，1隐藏',
  `upgrade` int(11) NOT NULL DEFAULT '1' COMMENT '是否支持升降级',
  `upstream_id` int(11) NOT NULL DEFAULT '0' COMMENT '对应上游配置项ID',
  `auto` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否自动创建:1是(表示拉取上游数据)，0否默认(表示客户自定义)',
  `is_discount` tinyint(1) NOT NULL DEFAULT '1' COMMENT '折扣',
  `notes` varchar(5000) NOT NULL DEFAULT '' COMMENT '备注',
  `is_rebate` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否折扣',
  `qty_stage` int(11) NOT NULL DEFAULT '0' COMMENT '数量阶梯',
  `unit` varchar(255) NOT NULL DEFAULT '' COMMENT '单位',
  `senior` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否开启高级设置:1是，0否',
  `linkage_pid` int(11) NOT NULL DEFAULT '0',
  `linkage_top_pid` int(11) NOT NULL DEFAULT '0',
  `linkage_level` varchar(255) NOT NULL DEFAULT '0',
  `copy_id` int(11) NOT NULL DEFAULT '0' COMMENT '复制ID',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `productid` (`gid`,`hidden`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_product_config_options_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '高级配置项关联表',
  `config_id` int(11) NOT NULL DEFAULT '0' COMMENT '配置项ID',
  `sub_id` varchar(1024) NOT NULL DEFAULT '' COMMENT '子项ID,逗号分隔',
  `relation` varchar(25) NOT NULL DEFAULT '' COMMENT '条件:seq相等,sneq不等,用eq会出问题,关键字',
  `type` varchar(25) NOT NULL DEFAULT '' COMMENT '类型:condition条件,result结果',
  `relation_id` int(11) NOT NULL DEFAULT '0' COMMENT '条件ID',
  `upstream_id` int(11) NOT NULL DEFAULT '0' COMMENT '上游ID',
  PRIMARY KEY (`id`),
  KEY `config_id` (`config_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_product_config_options_sub` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `config_id` int(11) NOT NULL DEFAULT '0',
  `qty_minimum` int(11) NOT NULL DEFAULT '0',
  `qty_maximum` int(11) NOT NULL DEFAULT '0',
  `option_name` varchar(500) NOT NULL DEFAULT '',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `hidden` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0显示默认，1隐藏',
  `upstream_id` int(11) NOT NULL DEFAULT '0' COMMENT '对应上游配置项ID',
  `linkage_pid` int(11) NOT NULL DEFAULT '0',
  `linkage_top_pid` int(11) NOT NULL DEFAULT '0',
  `linkage_level` varchar(255) NOT NULL DEFAULT '0',
  `copy_id` int(11) NOT NULL DEFAULT '0' COMMENT '复制ID',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `configid` (`config_id`,`hidden`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_product_downloads` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL DEFAULT '0',
  `download_id` int(11) NOT NULL DEFAULT '0',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `tblproduct_downloads_product_id_index` (`product_id`) USING BTREE,
  KEY `tblproduct_downloads_download_id_index` (`download_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_product_first_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '一级组表ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '一级组名称',
  `hidden` tinyint(1) NOT NULL DEFAULT '0' COMMENT '默认0，1：隐藏',
  `order` int(11) NOT NULL DEFAULT '0' COMMENT '排序，默认处理为添加自增长',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '修改时间',
  `is_upstream` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否上游资源分组',
  `zjmf_api_id` int(11) NOT NULL DEFAULT '0' COMMENT '上游资源ID',
  PRIMARY KEY (`id`),
  KEY `order` (`order`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_product_first_groups_customfields` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `relid` int(11) NOT NULL DEFAULT '0' COMMENT '关联一级分组',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '字段名称',
  `value` varchar(255) NOT NULL DEFAULT '' COMMENT '值',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `relid` (`relid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_product_group_features` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_group_id` int(11) NOT NULL DEFAULT '0',
  `feature` varchar(100) NOT NULL DEFAULT '',
  `order` int(11) NOT NULL DEFAULT '0',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `tblproduct_group_features_product_group_id_index` (`product_group_id`) USING BTREE,
  KEY `tblproduct_group_features_id_product_group_id_index` (`id`,`product_group_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_product_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '产品组表ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '产品组名称',
  `headline` varchar(100) NOT NULL DEFAULT '' COMMENT '产品组标题',
  `tagline` varchar(100) NOT NULL DEFAULT '' COMMENT '产品组标语',
  `order_frm_tpl` varchar(100) NOT NULL DEFAULT '' COMMENT 'uuid表示开发者应用，订购模板：为空时：使用系统默认，其他为订购模板名称',
  `disabled_gateways` varchar(255) NOT NULL DEFAULT '' COMMENT '隐藏的付款接口，以逗号分隔',
  `hidden` tinyint(1) NOT NULL DEFAULT '0' COMMENT '默认0，1：隐藏',
  `order` int(11) NOT NULL DEFAULT '0' COMMENT '排序，默认处理为添加自增长',
  `type` tinyint(4) NOT NULL DEFAULT '1' COMMENT '分类方式1=通用2=裸金属',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '修改时间',
  `gid` int(10) NOT NULL DEFAULT '0' COMMENT '一级组ID',
  `tpl_type` varchar(50) NOT NULL DEFAULT 'default' COMMENT '模板类型:default默认，custom自定义',
  `alias` varchar(100) NOT NULL DEFAULT '' COMMENT '别名',
  `is_upstream` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否上游资源',
  `zjfm_api_id` int(11) NOT NULL DEFAULT '0' COMMENT '接口id',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `order` (`order`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_product_groups_customfields` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `relid` int(11) NOT NULL DEFAULT '0' COMMENT '关联商品分组',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '字段名称',
  `value` varchar(255) NOT NULL DEFAULT '' COMMENT '值',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `relid` (`relid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_product_upgrade_products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL DEFAULT '0',
  `upgrade_product_id` int(11) NOT NULL DEFAULT '0',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `tblproduct_upgrade_products_product_id_index` (`product_id`) USING BTREE,
  KEY `tblproduct_upgrade_products_upgrade_product_id_index` (`upgrade_product_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '产品表ID',
  `type` varchar(25) NOT NULL DEFAULT '' COMMENT '产品类型(shared hosting,reseller hosting,server/VPNS,other)',
  `gid` int(11) NOT NULL DEFAULT '0' COMMENT '所属产品组ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '产品名称',
  `description` text COMMENT '产品描述',
  `host` varchar(1024) NOT NULL DEFAULT '' COMMENT '主机设置：host主机名,show是否显示主机名，modify是否允许修改主机名，rule规则',
  `is_domain` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否输入域名:1是，0否默认',
  `hidden` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0显示默认，1隐藏',
  `password` varchar(1024) NOT NULL DEFAULT '' COMMENT '密码设置：password密码，show是否显示密码，modify是否允许修改密码，rule规则',
  `show_domain_options` tinyint(1) NOT NULL DEFAULT '0' COMMENT '显示域名配置(1:显示；0隐藏)',
  `welcome_email` int(11) NOT NULL DEFAULT '0' COMMENT '欢迎邮件(涉及邮件模板配置)默认为0',
  `stock_control` tinyint(1) NOT NULL DEFAULT '0' COMMENT '库存控制(1:启用)默认0',
  `qty` int(11) NOT NULL DEFAULT '0' COMMENT '库存数量(与stock_control有关)',
  `prorata_billing` tinyint(1) NOT NULL DEFAULT '0' COMMENT '自定义结算日期(1:启用)',
  `prorata_date` int(11) NOT NULL DEFAULT '0' COMMENT '结算日期(输入您希望从每月的几号开始结算费用)',
  `prorata_charge_next_month` int(11) NOT NULL DEFAULT '0' COMMENT '下月结算(输入从每月几号后订购的产品，将安排在下个月的账单中一起收费)',
  `pay_type` text COMMENT '付款类型(免费free，一次onetime，周期recurring，天:day，小时:hour，试用ontrial',
  `pay_method` enum('prepayment','postpaid') CHARACTER SET utf8 NOT NULL COMMENT '付费方式(预付费''prepayment'',''后付费：postpaid''，)',
  `allow_qty` int(1) NOT NULL DEFAULT '0' COMMENT '允许购买多个(1:选中复选框，如果客户在购买时，订购产品超过 1 个时，则允许客户自行指定（不需要单独配置）),默认0',
  `auto_setup` varchar(100) NOT NULL DEFAULT '' COMMENT '购买后动作设置(无：手动开通，on：手动审核通过后自动开通，payment：当收到客户首付款时自动开通，order：当客户下单之后（未付款）立即自动开通)，续费不考虑此情况？',
  `server_type` varchar(50) NOT NULL DEFAULT '' COMMENT '服务器模块类型',
  `server_group` int(11) NOT NULL DEFAULT '0' COMMENT '服务器组ID',
  `config_option1` varchar(500) NOT NULL DEFAULT '',
  `config_option2` varchar(500) NOT NULL DEFAULT '',
  `config_option3` varchar(500) NOT NULL DEFAULT '',
  `config_option4` varchar(500) NOT NULL DEFAULT '',
  `config_option5` varchar(500) NOT NULL DEFAULT '',
  `config_option6` varchar(500) NOT NULL DEFAULT '',
  `config_option7` varchar(500) NOT NULL DEFAULT '',
  `config_option8` varchar(500) NOT NULL DEFAULT '',
  `config_option9` varchar(500) NOT NULL DEFAULT '',
  `config_option10` varchar(500) NOT NULL DEFAULT '',
  `config_option11` varchar(500) NOT NULL DEFAULT '',
  `config_option12` varchar(500) NOT NULL DEFAULT '',
  `config_option13` varchar(500) NOT NULL DEFAULT '',
  `config_option14` varchar(500) NOT NULL DEFAULT '',
  `config_option15` varchar(500) NOT NULL DEFAULT '',
  `config_option16` varchar(500) NOT NULL DEFAULT '',
  `config_option17` varchar(500) NOT NULL DEFAULT '',
  `config_option18` varchar(500) NOT NULL DEFAULT '',
  `config_option19` varchar(500) NOT NULL DEFAULT '',
  `config_option20` varchar(500) NOT NULL DEFAULT '',
  `config_option21` varchar(500) NOT NULL DEFAULT '',
  `config_option22` varchar(500) NOT NULL DEFAULT '',
  `config_option23` varchar(500) NOT NULL DEFAULT '',
  `config_option24` varchar(500) NOT NULL DEFAULT '',
  `recurring_cycles` int(11) NOT NULL DEFAULT '0' COMMENT '循环周期限制(限制此产品仅循环固定的次数，输入账单的总数（0 为不限制）。)',
  `auto_terminate_days` int(11) NOT NULL DEFAULT '0' COMMENT '自动删除/固定周期(入您希望设置的天数 N，在产品开通后 N 天自动到期并删除相应产品账号。（可以作为产品试用时间、限时产品等等…）)',
  `auto_terminate_email` int(11) NOT NULL DEFAULT '0' COMMENT '自动终止邮件ID',
  `config_options_upgrade` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否升级可配置选项（）',
  `billing_cycle_upgrade` varchar(100) NOT NULL DEFAULT '' COMMENT '计费周期升级',
  `upgrade_email` int(11) NOT NULL DEFAULT '0' COMMENT '升降级邮件ID',
  `down_configoption_refund` tinyint(4) NOT NULL DEFAULT '0' COMMENT '降级是否可退款(1是，0否默认)',
  `overages_enabled` varchar(100) NOT NULL DEFAULT '' COMMENT '(格式1,MB,GB。对应启用超支账单，软限制磁盘使用单位，带宽单位)默认无',
  `overages_disk_limit` int(11) NOT NULL DEFAULT '0' COMMENT '磁盘使用限制（对应overages_enabled字段中的第二个参数）',
  `overages_bw_limit` int(11) NOT NULL DEFAULT '0' COMMENT '带宽使用限制(对应overages_enabled字段中的第三个参数)',
  `overages_disk_price` decimal(6,4) NOT NULL DEFAULT '0.0000' COMMENT '磁盘超支费用单价',
  `overages_bw_price` decimal(6,4) NOT NULL DEFAULT '0.0000' COMMENT '带宽超支费用单价',
  `tax` tinyint(1) NOT NULL DEFAULT '0' COMMENT '税(为1时需要收税)默认无',
  `affiliateonetime` tinyint(1) NOT NULL DEFAULT '0' COMMENT '一次性支付（1：一次性支付）默认为循环支付',
  `affiliate_pay_type` varchar(255) NOT NULL DEFAULT '' COMMENT '自定义佣金设置（percentage：百分比，fixed：固定数额，none：无）默认根据推广设置。为空',
  `affiliate_pay_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '金额/百分比',
  `order` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `retired` tinyint(1) NOT NULL DEFAULT '0' COMMENT '下架（选中复选框从管理区产品下拉菜单中隐藏（不适用于已用于此产品的服务））',
  `is_featured` tinyint(1) NOT NULL DEFAULT '0' COMMENT '特性（在支持的订单上更加突出的显示此产品）',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  `auto_create_config_options` tinyint(3) NOT NULL DEFAULT '0' COMMENT '0未创建 1已创建',
  `groupid` int(11) NOT NULL DEFAULT '0' COMMENT '分组id',
  `api_type` varchar(50) NOT NULL DEFAULT '' COMMENT '接口类型:zjmf_api(魔方财务api),manual(手动)，normal(通用),resource(资源池)',
  `location_version` int(11) NOT NULL DEFAULT '1' COMMENT '本地版本号',
  `upstream_version` int(11) NOT NULL DEFAULT '0' COMMENT '上游版本号',
  `upstream_price_type` varchar(20) NOT NULL DEFAULT 'percent' COMMENT '上游产品的价格类型：percent百分比,custom自定义金额',
  `upstream_price_value` decimal(10,2) NOT NULL DEFAULT '120.00' COMMENT 'percent百分比时的值',
  `zjmf_api_id` int(11) NOT NULL DEFAULT '0' COMMENT '魔方财务api的ID',
  `upstream_pid` int(11) NOT NULL DEFAULT '0' COMMENT '上游产品ID',
  `is_truename` tinyint(2) NOT NULL DEFAULT '0' COMMENT '是否需要实名',
  `uuid` text COMMENT '应用标识',
  `p_uid` int(11) NOT NULL DEFAULT '0' COMMENT '应用开发者',
  `rate` decimal(10,2) NOT NULL DEFAULT '1.00' COMMENT '相对上游产品汇率',
  `clientscount` int(11) NOT NULL DEFAULT '0' COMMENT '单个用户购买数量-1不限制',
  `app_type` varchar(10) NOT NULL DEFAULT '' COMMENT '应用类型:addons插件，gateways支付接口，servers模块,systems官方应用',
  `product_shopping_url` text COMMENT '快速订购连接',
  `product_group_url` text COMMENT '产品组连接',
  `upper_reaches_id` int(11) NOT NULL DEFAULT '0' COMMENT '手动资源上游ID',
  `version_description` text COMMENT '版本描述',
  `app_version` text COMMENT '应用版本',
  `is_bind_phone` tinyint(4) NOT NULL DEFAULT '0' COMMENT '绑定手机',
  `app_hot_order` int(11) NOT NULL DEFAULT '0' COMMENT '热门应用排序',
  `app_hot_lock` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1:锁定热门应用排序',
  `app_hot_heat` int(11) NOT NULL DEFAULT '0' COMMENT '热度',
  `app_recommend_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1:强烈推荐',
  `app_recommend_order` int(11) NOT NULL DEFAULT '0' COMMENT '强烈推荐排序',
  `app_recommend_lock` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1:锁定强烈推荐排序',
  `app_pay_type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1:免费应用',
  `app_score` float NOT NULL DEFAULT '0' COMMENT '应用评分',
  `app_images` text COMMENT '应用图片',
  `app_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '应用审核状态:2已驳回,1审核通过，0审核中',
  `upstream_qty` int(11) NOT NULL DEFAULT '0' COMMENT '上游库存',
  `upstream_product_shopping_url` text COMMENT '上游购物链接',
  `cancel_control` tinyint(1) NOT NULL DEFAULT '0' COMMENT '取消控制(1:启用)默认0',
  `unretired_time` int(11) NOT NULL DEFAULT '0' COMMENT '上架时间',
  `upstream_stock_control` tinyint(1) NOT NULL DEFAULT '0' COMMENT '上游是否开启库存控制:1是，0否',
  `upstream_auto_setup` varchar(100) NOT NULL DEFAULT '' COMMENT '上游开通方式',
  `upstream_ontrial_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '上游试用状态:1开启,0未开启',
  `upstream_price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'v10价格',
  `upstream_cycle` varchar(25) NOT NULL DEFAULT '' COMMENT 'v10周期',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `gid` (`gid`) USING BTREE,
  KEY `type` (`type`),
  KEY `groupid` (`groupid`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_promo_code` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL DEFAULT '' COMMENT '优惠码',
  `type` varchar(30) NOT NULL DEFAULT '' COMMENT '类型 percent,fixed,override,free',
  `recurring` tinyint(3) NOT NULL DEFAULT '0' COMMENT '是否循环优惠',
  `value` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '价值',
  `cycles` text COMMENT '结算周期',
  `appliesto` text COMMENT '适用的产品id',
  `requires` text COMMENT '需要的产品id',
  `requires_exist` tinyint(3) NOT NULL DEFAULT '0' COMMENT '是否用于账户中的产品',
  `start_time` int(10) NOT NULL DEFAULT '0' COMMENT '开始时间',
  `expiration_time` int(10) NOT NULL DEFAULT '0' COMMENT '失效时间',
  `max_times` int(10) NOT NULL DEFAULT '0' COMMENT '最大使用次数',
  `used` int(10) NOT NULL DEFAULT '0' COMMENT '已使用次数',
  `lifelong` tinyint(3) NOT NULL DEFAULT '0' COMMENT '是否终身优惠',
  `one_time` tinyint(3) NOT NULL DEFAULT '0' COMMENT '是否一次性, 只能用于一个订单',
  `only_new_client` tinyint(3) NOT NULL DEFAULT '0' COMMENT '仅适用于新用户',
  `only_old_client` tinyint(3) NOT NULL DEFAULT '0' COMMENT '仅用于老客户',
  `once_per_client` tinyint(3) NOT NULL DEFAULT '0' COMMENT '每个用户只能用一次',
  `recurfor` int(3) NOT NULL DEFAULT '0' COMMENT '循环优惠执行次数',
  `upgrades` tinyint(3) NOT NULL DEFAULT '0' COMMENT '启用产品升级优惠',
  `upgrade_config` text COMMENT '升级降级配置',
  `notes` text COMMENT '备注',
  `is_discount` int(2) NOT NULL DEFAULT '1' COMMENT '是否与客户折扣一起使用',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='优惠码表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_renew_cycle` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `uid` int(10) NOT NULL DEFAULT '0',
  `type` enum('normal','cycle_to_mon_year') NOT NULL DEFAULT 'normal' COMMENT '类型',
  `relid` int(10) NOT NULL DEFAULT '0' COMMENT '子帐单id',
  `new_cycle` varchar(255) NOT NULL DEFAULT '' COMMENT '新周期',
  `new_recurring_amount` float(20,2) NOT NULL DEFAULT '0.00' COMMENT '新续费金额',
  `recurringchange` float(20,2) NOT NULL DEFAULT '0.00' COMMENT '周期切换的金额',
  `status` enum('Completed','Pending') NOT NULL DEFAULT 'Pending' COMMENT '状态完成或等待',
  `paid` enum('Y','N') NOT NULL DEFAULT 'N',
  `create_time` int(10) NOT NULL DEFAULT '0',
  `expire_time` int(10) NOT NULL DEFAULT '0',
  `delete_time` int(10) NOT NULL DEFAULT '0',
  `duration` int(11) NOT NULL DEFAULT '0' COMMENT 'v10周期：多少秒',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `uid` (`uid`),
  KEY `relid` (`relid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_role` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '父角色ID',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '状态;0:禁用;1:正常',
  `create_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '更新时间',
  `list_order` float NOT NULL DEFAULT '0' COMMENT '排序',
  `name` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '角色名称',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `auth_role` text COMMENT '权限id',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `parent_id` (`parent_id`) USING BTREE,
  KEY `status` (`status`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='角色表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_role_user` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '角色 id',
  `user_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '用户id',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `role_id` (`role_id`) USING BTREE,
  KEY `user_id` (`user_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 ROW_FORMAT=COMPACT COMMENT='用户角色对应表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_run_croning` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cron_type` int(11) NOT NULL DEFAULT '0' COMMENT '定时任务类型',
  `active_type` int(11) NOT NULL DEFAULT '0' COMMENT '对应队列操作类型id',
  `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '执行状态',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `datetime` int(11) NOT NULL DEFAULT '0' COMMENT '时间范围YYYYMMDD',
  `unique_tab` varchar(64) CHARACTER SET utf8 NOT NULL DEFAULT '0' COMMENT '任务唯一标识',
  PRIMARY KEY (`id`),
  KEY `sts_dat_unt_idx` (`status`,`datetime`,`unique_tab`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_run_maping` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT '客户（产品拥有人）',
  `user` varchar(36) DEFAULT '' COMMENT '客户（产品拥有人）',
  `host_id` int(11) NOT NULL COMMENT '产品id',
  `description` varchar(320) DEFAULT '' COMMENT '描述（动作详情）',
  `from_type` int(11) DEFAULT NULL COMMENT '来源类型 （100定时任务、200手动任务、300异步触发、400对接上游、500下游发起）',
  `active_user` varchar(36) DEFAULT '' COMMENT '操作人 （Systeam、系统管理员、用户）',
  `active_type` int(11) NOT NULL COMMENT '操作类型（保存用于，发起重试时对应到操作方法 1开通、2暂停、3解除暂停、4删除、5续费、6升降级）',
  `active_type_param` text COMMENT '操作类型对应参数（保持重试，与首次发起的，请求情景一致性）',
  `status` tinyint(2) DEFAULT '0' COMMENT '状态 0 失败 1成功',
  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
  `last_execute_time` int(11) DEFAULT NULL COMMENT '最后执行时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_sale_ladder` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `turnover` int(10) DEFAULT '0' COMMENT '营业额',
  `bates` decimal(12,2) DEFAULT '0.00' COMMENT '提成比例',
  `is_flag` int(2) DEFAULT '0' COMMENT '是否开启  1开启  0  不开启',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_sale_products` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `pid` int(10) DEFAULT '0' COMMENT '产品id',
  `gid` int(10) DEFAULT '0' COMMENT '分组id',
  PRIMARY KEY (`id`),
  KEY `gpid` (`gid`,`pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_sales_product_groups` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `group_name` varchar(255) DEFAULT NULL COMMENT '分组名',
  `bates` decimal(10,2) DEFAULT NULL COMMENT '提成比例',
  `is_renew` int(4) DEFAULT NULL COMMENT '是否包含续费计算',
  `updategrade` int(4) DEFAULT NULL COMMENT '是否计算升降级',
  `pids` text COMMENT '产品组列表',
  `renew_bates` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '续费比例',
  `upgrade_bates` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '升降级比例',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_sendmsglimit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(255) NOT NULL DEFAULT '' COMMENT '手机',
  `ip` varchar(255) NOT NULL DEFAULT '' COMMENT 'ip',
  `time` varchar(255) NOT NULL DEFAULT '' COMMENT '时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_server_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '服务器组ID',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '组名称',
  `type` varchar(255) NOT NULL DEFAULT '' COMMENT '服务器模块类型',
  `system_type` varchar(255) NOT NULL DEFAULT 'normal' COMMENT '组类型',
  `capacity` int(10) NOT NULL DEFAULT '0' COMMENT '最大接口容量',
  `mode` int(1) NOT NULL DEFAULT '1' COMMENT '分配方式（1：平均分配  2 满一个算一个）',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_server_groups_rel` (
  `group_id` int(11) DEFAULT NULL COMMENT '组ID',
  `server_id` int(11) DEFAULT NULL COMMENT '服务器ID'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_servers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '服务器配置ID',
  `gid` int(11) NOT NULL DEFAULT '0' COMMENT '服务器组ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '名称',
  `ip_address` varchar(100) NOT NULL DEFAULT '' COMMENT 'ip地址',
  `assigned_ips` varchar(100) NOT NULL DEFAULT '' COMMENT '其他IP地址',
  `hostname` varchar(100) NOT NULL DEFAULT '' COMMENT '主机名',
  `monthly_cost` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '每月成本',
  `noc` varchar(100) NOT NULL DEFAULT '' COMMENT '数据中心',
  `status_address` varchar(100) NOT NULL DEFAULT '' COMMENT '服务器状态地址',
  `name_server1` varchar(100) NOT NULL DEFAULT '' COMMENT '主域名服务器',
  `name_server1_ip` varchar(100) NOT NULL DEFAULT '',
  `name_server2` varchar(100) NOT NULL DEFAULT '' COMMENT '次域名服务器',
  `name_server2_ip` varchar(100) NOT NULL DEFAULT '',
  `name_server3` varchar(100) NOT NULL DEFAULT '' COMMENT '第三域名服务器',
  `name_server3_ip` varchar(100) NOT NULL DEFAULT '',
  `name_server4` varchar(100) NOT NULL DEFAULT '' COMMENT '第四域名服务器',
  `name_server4_ip` varchar(100) NOT NULL DEFAULT '',
  `name_server5` varchar(100) NOT NULL DEFAULT '' COMMENT '第五域名服务器',
  `name_server5_ip` varchar(100) NOT NULL DEFAULT '',
  `max_accounts` int(11) NOT NULL DEFAULT '0' COMMENT '最大账号数量（默认为0）',
  `username` varchar(256) NOT NULL DEFAULT '' COMMENT '用户名',
  `password` varchar(256) NOT NULL DEFAULT '' COMMENT '密码',
  `accesshash` varchar(256) NOT NULL DEFAULT '' COMMENT '访问散列值',
  `secure` tinyint(1) NOT NULL DEFAULT '0' COMMENT '安全，1:选中复选框使用 SSL 连接模式,0不选(默认)',
  `port` int(11) NOT NULL DEFAULT '0' COMMENT '访问端口',
  `active` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1.当前模块类型激活的服务器(或默认服务器),0非默认',
  `disabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1勾选禁用，0使用(默认)(单选框)',
  `server_type` varchar(255) NOT NULL DEFAULT 'normal' COMMENT '服务器类型',
  `link_status` tinyint(3) NOT NULL DEFAULT '1' COMMENT '服务器连接状态 0失败 1成功',
  `type` varchar(100) NOT NULL DEFAULT '' COMMENT '服务器模块类型',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `gid` (`gid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_sms_country` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `iso` varchar(80) NOT NULL DEFAULT '',
  `iso3` varchar(80) NOT NULL DEFAULT '',
  `name` varchar(80) NOT NULL DEFAULT '',
  `name_zh` varchar(80) NOT NULL DEFAULT '',
  `nicename` varchar(80) NOT NULL DEFAULT '',
  `num_code` smallint(6) NOT NULL DEFAULT '0',
  `phone_code` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=240 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_system_log` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `create_time` int(10) NOT NULL DEFAULT '0' COMMENT '时间',
  `description` text COMMENT '日志内容',
  `user` varchar(255) NOT NULL DEFAULT '' COMMENT '用户名',
  `uid` int(10) NOT NULL DEFAULT '0' COMMENT '对应id',
  `user_type` tinyint(3) NOT NULL DEFAULT '0' COMMENT '用户类型 0管理员 1前台用户',
  `ip` varchar(30) NOT NULL DEFAULT '' COMMENT 'ip',
  `log_type` varchar(30) NOT NULL DEFAULT 'system' COMMENT '日志类型',
  `relid` int(10) NOT NULL DEFAULT '0' COMMENT '关联的id',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `userid` (`uid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_system_message` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '客户id',
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '标题',
  `content` text COMMENT '内容',
  `obj` text COMMENT '消息对象相关信息，json存储',
  `attachment` text COMMENT '附件名称',
  `type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '类型：1-工单消息，2-产品消息，3-站内信，4-活动消息',
  `is_market` tinyint(1) NOT NULL DEFAULT '0' COMMENT '营销信息：0-否，1-是',
  `delete_time` int(10) NOT NULL DEFAULT '0' COMMENT '删除时间',
  `create_time` int(10) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `read_time` int(10) NOT NULL DEFAULT '0' COMMENT '阅读时间',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统消息';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_theme` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `create_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '安装时间',
  `update_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '最后升级时间',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '模板状态,1:正在使用;0:未使用',
  `is_compiled` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '是否为已编译模板',
  `theme` varchar(20) NOT NULL DEFAULT '' COMMENT '主题目录名，用于主题的维一标识',
  `name` varchar(20) NOT NULL DEFAULT '' COMMENT '主题名称',
  `version` varchar(20) NOT NULL DEFAULT '' COMMENT '主题版本号',
  `demo_url` varchar(50) NOT NULL DEFAULT '' COMMENT '演示地址，带协议',
  `thumbnail` varchar(100) NOT NULL DEFAULT '' COMMENT '缩略图',
  `author` varchar(20) NOT NULL DEFAULT '' COMMENT '主题作者',
  `author_url` varchar(50) NOT NULL DEFAULT '' COMMENT '作者网站链接',
  `lang` varchar(10) NOT NULL DEFAULT '' COMMENT '支持语言',
  `keywords` varchar(50) NOT NULL DEFAULT '' COMMENT '主题关键字',
  `description` varchar(100) NOT NULL DEFAULT '' COMMENT '主题描述',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_theme_file` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `is_public` tinyint(4) NOT NULL DEFAULT '0' COMMENT '是否公共的模板文件',
  `list_order` float NOT NULL DEFAULT '10000' COMMENT '排序',
  `theme` varchar(20) NOT NULL DEFAULT '' COMMENT '模板名称',
  `name` varchar(20) NOT NULL DEFAULT '' COMMENT '模板文件名',
  `action` varchar(50) NOT NULL DEFAULT '' COMMENT '操作',
  `file` varchar(50) NOT NULL DEFAULT '' COMMENT '模板文件，相对于模板根目录，如Portal/index.html',
  `description` varchar(100) NOT NULL DEFAULT '' COMMENT '模板文件描述',
  `more` text COMMENT '模板更多配置,用户自己后台设置的',
  `config_more` text COMMENT '模板更多配置,来源模板的配置文件',
  `draft_more` text COMMENT '模板更多配置,用户临时保存的配置',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_ticket` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `tid` varchar(128) NOT NULL DEFAULT '' COMMENT '工单号',
  `dptid` int(10) NOT NULL DEFAULT '0' COMMENT '部门id',
  `uid` int(10) NOT NULL DEFAULT '0' COMMENT '用户id',
  `host_id` int(11) NOT NULL DEFAULT '0' COMMENT '客户购买产品ID',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '姓名',
  `email` varchar(255) NOT NULL DEFAULT '' COMMENT '邮箱',
  `create_time` int(10) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '标题',
  `content` text COMMENT '正文',
  `status` int(11) NOT NULL DEFAULT '1' COMMENT '状态',
  `priority` varchar(30) NOT NULL DEFAULT '' COMMENT '优先级',
  `admin` varchar(255) NOT NULL DEFAULT '' COMMENT '管理员名称',
  `admin_id` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `attachment` text COMMENT '附件',
  `last_reply_time` int(11) NOT NULL DEFAULT '0' COMMENT '上次回复时间',
  `client_unread` tinyint(1) NOT NULL DEFAULT '0' COMMENT '用户未读',
  `admin_unread` tinyint(1) NOT NULL DEFAULT '0' COMMENT '客户未读',
  `service` text,
  `merged_ticket_id` int(11) NOT NULL DEFAULT '0' COMMENT '合并工单id',
  `update_time` int(10) NOT NULL DEFAULT '0' COMMENT '更新时间',
  `c` varchar(20) NOT NULL DEFAULT '' COMMENT '凭据',
  `cc` text COMMENT '收件人',
  `flag` int(10) NOT NULL DEFAULT '0' COMMENT '标记的管理员',
  `star` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '评价星1-5',
  `is_auto_reply` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已经自动回复',
  `is_deliver` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已经推送',
  `upstream_tid` varchar(128) NOT NULL DEFAULT '' COMMENT '上游工单id',
  `is_receive` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否接收',
  `handle` int(11) NOT NULL DEFAULT '0' COMMENT '处理人',
  `handle_time` int(11) NOT NULL DEFAULT '0' COMMENT '处理时间',
  `token` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `tid_c` (`tid`) USING BTREE,
  KEY `userid` (`uid`) USING BTREE,
  KEY `date` (`create_time`) USING BTREE,
  KEY `did` (`dptid`) USING BTREE,
  KEY `merged_ticket_id` (`merged_ticket_id`,`id`) USING BTREE,
  KEY `status` (`status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='工单表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_ticket_deliver` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `is_open_auto_reply` tinyint(1) NOT NULL DEFAULT '0' COMMENT '开启自动回复',
  `bz` varchar(100) NOT NULL DEFAULT '' COMMENT '自动回复内容',
  `mask_keywords` text NOT NULL COMMENT '屏蔽关键字',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_ticket_deliver_department` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tdid` int(11) NOT NULL DEFAULT '0',
  `dptid` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_ticket_deliver_products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tdid` int(11) NOT NULL DEFAULT '0',
  `pid` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_ticket_department` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '部门名称',
  `description` text COMMENT '描述',
  `email` varchar(255) NOT NULL DEFAULT '' COMMENT '邮件地址',
  `only_reg_client` tinyint(1) NOT NULL DEFAULT '0' COMMENT '仅客户',
  `only_client_open` tinyint(1) NOT NULL DEFAULT '0' COMMENT '仅管道回复',
  `no_auto_reply` tinyint(1) NOT NULL DEFAULT '0' COMMENT '无自动回复',
  `hidden` tinyint(1) NOT NULL DEFAULT '0' COMMENT '隐藏',
  `order` int(1) NOT NULL DEFAULT '0' COMMENT '排序',
  `host` varchar(255) NOT NULL DEFAULT '' COMMENT '主机名',
  `port` varchar(5) NOT NULL DEFAULT '' COMMENT 'POP3端口',
  `login` varchar(255) NOT NULL DEFAULT '' COMMENT '邮件地址',
  `password` varchar(255) NOT NULL DEFAULT '' COMMENT '邮箱密码',
  `feedback_request` tinyint(1) NOT NULL DEFAULT '0' COMMENT '反馈请求',
  `is_product_order` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否有产品',
  `is_open_auto_reply` tinyint(1) NOT NULL DEFAULT '0' COMMENT '开启自动回复',
  `minutes` int(10) NOT NULL DEFAULT '0' COMMENT '分钟',
  `bz` text COMMENT '自动回复内容',
  `time_type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0是秒1分钟',
  `is_related_upstream` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否关联上游',
  `is_certifi` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否实名认证,1是,0否',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `name` (`name`(64)) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='工单部门表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_ticket_department_admin` (
  `admin_id` int(10) NOT NULL DEFAULT '0',
  `dptid` int(10) NOT NULL DEFAULT '0',
  KEY `index_admin` (`admin_id`) USING BTREE,
  KEY `index_d` (`dptid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='工单部门管理员表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_ticket_department_upstream` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dptid` int(10) NOT NULL DEFAULT '0',
  `api_id` int(10) NOT NULL DEFAULT '0',
  `upstream_dptid` int(10) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_ticket_note` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `tid` int(10) NOT NULL DEFAULT '0' COMMENT '工单id',
  `admin` varchar(255) NOT NULL DEFAULT '' COMMENT '管理员名称',
  `create_time` int(10) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `content` text COMMENT '备注内容',
  `attachment` text COMMENT '附件',
  `editor` enum('plain','markdown') NOT NULL DEFAULT 'plain',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `ticketid_date` (`tid`,`create_time`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_ticket_prereply` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `cid` int(10) NOT NULL DEFAULT '0' COMMENT '预设回复分类id',
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '回复名称',
  `content` text COMMENT '回复内容',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `cid` (`cid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='预设回复内容表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_ticket_prereply_category` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `parentid` int(10) NOT NULL DEFAULT '0' COMMENT '父id',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '分类',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `parentid_name` (`parentid`,`name`(64)) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='预设回复分类表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_ticket_reply` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `tid` int(10) NOT NULL DEFAULT '0' COMMENT '工单id',
  `uid` int(10) NOT NULL DEFAULT '0' COMMENT '用户id',
  `contactid` int(10) NOT NULL DEFAULT '0',
  `create_time` int(10) NOT NULL DEFAULT '0' COMMENT '回复时间',
  `content` text COMMENT '回复信息',
  `admin` varchar(255) NOT NULL DEFAULT '' COMMENT '管理员名称',
  `admin_id` int(10) unsigned DEFAULT NULL COMMENT '管理员id',
  `attachment` text COMMENT '附件',
  `star` tinyint(7) NOT NULL DEFAULT '0' COMMENT '星级',
  `editor` enum('plain','markdown') NOT NULL DEFAULT 'plain',
  `is_deliver` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否推送',
  `is_receive` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否接收',
  `source` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0:下游1:上游',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `tid_date` (`tid`,`create_time`) USING BTREE,
  KEY `uid` (`uid`),
  KEY `admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_ticket_status` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `title` varchar(64) NOT NULL DEFAULT '' COMMENT '标题',
  `color` varchar(20) NOT NULL DEFAULT '' COMMENT 'css颜色代码',
  `order` int(2) NOT NULL DEFAULT '1' COMMENT '排序',
  `show_active` tinyint(1) NOT NULL DEFAULT '0' COMMENT '包括打开的工单',
  `show_await` tinyint(1) NOT NULL DEFAULT '0' COMMENT '包括等待回复',
  `auto_close` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否自动关闭',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='工单状态表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_ticket_transfer_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tid` int(11) NOT NULL DEFAULT '0' COMMENT '工单ID',
  `desc` varchar(1000) CHARACTER SET utf8 NOT NULL DEFAULT '' COMMENT '描述',
  `remarks` varchar(255) CHARACTER SET utf8 NOT NULL DEFAULT '' COMMENT '备注',
  `mode` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0:指定处理人1:移动部门',
  `old_handle` int(11) NOT NULL DEFAULT '0' COMMENT '原处理人ID',
  `handle` int(11) NOT NULL DEFAULT '0' COMMENT '处理人ID',
  `old_dptid` int(11) NOT NULL DEFAULT '0' COMMENT '原部门ID',
  `dptid` int(11) NOT NULL DEFAULT '0' COMMENT '部门ID',
  `admin` int(11) NOT NULL DEFAULT '0' COMMENT '操作人ID',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `admin` (`admin`),
  KEY `tid` (`tid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工单转移日志';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_transfer` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `uid` int(10) DEFAULT NULL,
  `host_id` int(10) DEFAULT NULL COMMENT '机器id',
  `transfer_uid` int(10) DEFAULT NULL COMMENT '接受人uid',
  `remarks` varchar(255) DEFAULT NULL COMMENT '备注',
  `status` int(1) DEFAULT NULL COMMENT '0:提交转移,1:转移成功,2:拒绝,3.过期未接收',
  `create_time` int(10) DEFAULT NULL,
  `update_time` int(10) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `uid` (`uid`),
  KEY `host_id` (`host_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_upgrades` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0',
  `order_id` int(11) NOT NULL DEFAULT '0',
  `type` enum('service','addon','product','configoptions') NOT NULL,
  `date` int(11) NOT NULL DEFAULT '0',
  `relid` int(11) NOT NULL DEFAULT '0',
  `original_value` varchar(1000) NOT NULL DEFAULT '',
  `new_value` varchar(1000) NOT NULL DEFAULT '',
  `new_cycle` varchar(30) NOT NULL DEFAULT '',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `credit_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `days_remaining` int(11) NOT NULL DEFAULT '0',
  `total_days_in_cycle` int(11) NOT NULL DEFAULT '0',
  `new_recurring_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `recurring_change` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('Pending','Completed') NOT NULL,
  `paid` enum('Y','N') NOT NULL,
  `description` varchar(1024) NOT NULL DEFAULT '' COMMENT '描述',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `orderid` (`order_id`) USING BTREE,
  KEY `relid` (`relid`) USING BTREE,
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_upper_manual_info` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `hid` int(11) NOT NULL DEFAULT '0' COMMENT '产品ID,host表id',
  `regate` text COMMENT '到期时间',
  `amount` text COMMENT '金额',
  `billingcycle` text COMMENT '周期',
  `dedicatedip` text COMMENT 'ip',
  `assignedips` text COMMENT '分配ip',
  `create_time` text COMMENT '开通时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `hid` (`hid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_upper_reaches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '上游名称',
  `phone` varchar(12) NOT NULL DEFAULT '' COMMENT '联系方式',
  `bz` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_upper_reaches_ip` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(255) NOT NULL DEFAULT '' COMMENT '上游名称',
  `resid` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_upper_reaches_res` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(255) NOT NULL DEFAULT '' COMMENT '上游名称',
  `pid` int(11) NOT NULL DEFAULT '0' COMMENT '上游id',
  `pz` varchar(255) NOT NULL DEFAULT '' COMMENT '配置',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `ipmi` varchar(255) NOT NULL DEFAULT '',
  `root` varchar(255) NOT NULL DEFAULT '',
  `pwd` varchar(255) NOT NULL DEFAULT '',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '成本',
  `hid` int(11) NOT NULL DEFAULT '0' COMMENT '云主机id',
  `paid_time` int(11) NOT NULL DEFAULT '0' COMMENT '到期时间',
  `update_time` int(11) NOT NULL DEFAULT '0',
  `in_ip` varchar(255) NOT NULL DEFAULT '' COMMENT '主ip',
  `ipmi_version` varchar(10) NOT NULL DEFAULT '1.5' COMMENT 'ipmi版本',
  `power_status` varchar(10) NOT NULL DEFAULT '' COMMENT '电源状态',
  `control_mode` varchar(255) NOT NULL DEFAULT 'ipmi' COMMENT '控制方式',
  `dcim_client_url` varchar(255) NOT NULL DEFAULT '' COMMENT 'DCIM客户端地址',
  `dcim_client_id` int(11) NOT NULL DEFAULT '0' COMMENT 'DCIM客户端服务器ID',
  `username` varchar(255) NOT NULL DEFAULT '' COMMENT '用户名',
  `password` varchar(255) NOT NULL DEFAULT '' COMMENT '密码',
  `mark` varchar(5000) NOT NULL DEFAULT '' COMMENT '备注',
  PRIMARY KEY (`id`),
  KEY `pid` (`pid`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_user` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_type` tinyint(4) DEFAULT NULL COMMENT '用户类型',
  `sex` tinyint(2) NOT NULL DEFAULT '0' COMMENT '性别;0:保密,1:男,2:女',
  `birthday` int(11) NOT NULL DEFAULT '0' COMMENT '生日',
  `last_login_time` int(11) NOT NULL DEFAULT '0' COMMENT '最后登录时间',
  `score` int(11) NOT NULL DEFAULT '0' COMMENT '用户积分',
  `coin` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '金币',
  `balance` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '余额',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '注册时间',
  `user_status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '用户状态;0:禁用,1:正常,2:未验证',
  `user_login` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '用户名',
  `user_pass` varchar(64) NOT NULL DEFAULT '' COMMENT '登录密码;cmf_password加密',
  `user_nickname` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '用户昵称',
  `user_email` varchar(100) NOT NULL DEFAULT '' COMMENT '用户登录邮箱',
  `user_url` varchar(100) NOT NULL DEFAULT '' COMMENT '用户个人网址',
  `avatar` varchar(255) NOT NULL DEFAULT '' COMMENT '用户头像',
  `signature` varchar(255) NOT NULL DEFAULT '' COMMENT '个性签名',
  `last_login_ip` varchar(255) NOT NULL DEFAULT '' COMMENT '最后登录ip',
  `user_activation_key` varchar(60) NOT NULL DEFAULT '' COMMENT '激活码',
  `mobile` varchar(20) NOT NULL DEFAULT '' COMMENT '中国手机不带国家代码，国际手机号格式为：国家代码-手机号',
  `more` text COMMENT '扩展属性',
  `language` varchar(20) NOT NULL DEFAULT '' COMMENT '语言',
  `is_sale` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '是否销售0=默认 1=是',
  `sale_is_use` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '是否启用0=默认 1=启用',
  `last_act_time` int(11) NOT NULL DEFAULT '0' COMMENT '上次操作时间',
  `only_mine` tinyint(4) NOT NULL DEFAULT '0' COMMENT '只能查看自己的销售人员0关闭 1开启',
  `all_sale` tinyint(4) NOT NULL DEFAULT '0' COMMENT '查看所有销售',
  `is_receive` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否接受业务类邮件',
  `only_oneself_notice` tinyint(4) NOT NULL DEFAULT '0' COMMENT '仅自己客户的工单提醒邮件',
  `cat_ownerless` tinyint(4) NOT NULL DEFAULT '1' COMMENT '未分配客户所有人可见0关闭 1开启',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `user_login` (`user_login`),
  KEY `user_nickname` (`user_nickname`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='用户表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_user_action_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '用户id',
  `username` varchar(80) NOT NULL DEFAULT '',
  `method` varchar(50) NOT NULL DEFAULT '' COMMENT '请求方式',
  `action` varchar(50) NOT NULL DEFAULT '' COMMENT '请求路径',
  `param` varchar(255) DEFAULT NULL COMMENT '请求参数',
  `url` varchar(50) NOT NULL DEFAULT '' COMMENT '操作名称;格式:应用名+控制器+操作名,也可自己定义格式只要不发生冲突且惟一;',
  `agent` varchar(50) NOT NULL DEFAULT '' COMMENT '访问端',
  `ip` varchar(15) NOT NULL DEFAULT '' COMMENT '用户ip',
  `create_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '最后访问时间',
  `type` varchar(50) DEFAULT NULL COMMENT '类型(操作的主表名)',
  `mid` int(11) DEFAULT NULL COMMENT '变动的表id',
  `handle_id` int(11) DEFAULT NULL COMMENT '执行操作的管理员id',
  `delete_time` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `user_object_action` (`uid`,`url`,`action`) USING BTREE,
  KEY `user_object_action_ip` (`uid`,`url`,`action`,`ip`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='用户行为记录表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_user_product_bates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` tinyint(2) NOT NULL DEFAULT '0' COMMENT '1 比例  2固定金额  3 优惠',
  `bates` decimal(12,2) DEFAULT '0.00' COMMENT '数值',
  `products` int(11) DEFAULT '0' COMMENT '产品组id',
  `user` int(11) DEFAULT '0' COMMENT '客户组id',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_user_product_groups` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `group_name` varchar(255) DEFAULT NULL COMMENT '分组名',
  `pids` text COMMENT '产品组列表',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_user_products` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `pid` int(10) DEFAULT '0' COMMENT '产品id',
  `gid` int(10) DEFAULT '0' COMMENT '分组id',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `gpid` (`gid`,`pid`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_user_tastes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL COMMENT '管理员id',
  `ticket_refresh` char(20) NOT NULL DEFAULT 'never' COMMENT '工单自动刷新',
  PRIMARY KEY (`id`),
  UNIQUE KEY `tastes_idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员喜好';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_user_token` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL DEFAULT '0' COMMENT '用户id',
  `expire_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT ' 过期时间',
  `create_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
  `token` varchar(64) NOT NULL DEFAULT '' COMMENT 'token',
  `device_type` varchar(10) NOT NULL DEFAULT '' COMMENT '设备类型;mobile,android,iphone,ipad,web,pc,mac,wxapp',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='用户客户端登录 token 表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_userdownloads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户id',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '附件名',
  `url` varchar(255) NOT NULL DEFAULT '' COMMENT '文件地址',
  `remarks` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '时间',
  `downame` varchar(255) NOT NULL DEFAULT '' COMMENT '文件名',
  `adminid` int(11) NOT NULL DEFAULT '0' COMMENT '管理员id',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_voucher` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '客户ID',
  `invoice_id` int(11) NOT NULL DEFAULT '0' COMMENT '新账单ID',
  `post_id` int(11) NOT NULL DEFAULT '0' COMMENT '邮寄地址ID',
  `type_id` int(11) NOT NULL DEFAULT '0' COMMENT '发票类型ID',
  `express_id` int(11) NOT NULL DEFAULT '0' COMMENT '快递ID',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '金额',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `check_time` int(11) NOT NULL DEFAULT '0' COMMENT '审核时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  `status` varchar(25) NOT NULL DEFAULT 'Pending' COMMENT '状态:Pending,Cancelled取消，Reject驳回，Unpaid待支付，Send已发出',
  `notes` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  KEY `post_id` (`post_id`),
  KEY `type_id` (`type_id`),
  KEY `invoice_id` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_voucher_invoices` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `voucher_id` int(11) NOT NULL DEFAULT '0',
  `invoice_id` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_voucher_post` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '客户ID',
  `username` varchar(50) NOT NULL DEFAULT '' COMMENT '收件人姓名',
  `phone` varchar(50) NOT NULL DEFAULT '' COMMENT '电话',
  `province` varchar(100) NOT NULL DEFAULT '' COMMENT '省',
  `city` varchar(100) NOT NULL DEFAULT '' COMMENT '市',
  `region` varchar(100) NOT NULL DEFAULT '' COMMENT '区',
  `detail` varchar(500) NOT NULL DEFAULT '' COMMENT '详细地址',
  `post` varchar(50) NOT NULL DEFAULT '' COMMENT '邮编',
  `default` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否默认：0否，1是',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_voucher_type` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '客户ID',
  `title` varchar(50) NOT NULL DEFAULT '' COMMENT '抬头信息',
  `issue_type` varchar(50) NOT NULL DEFAULT '' COMMENT '开具类型person个人，company企业',
  `voucher_type` varchar(50) NOT NULL DEFAULT '' COMMENT '发票类型：common普通，dedicated专用',
  `tax_id` varchar(100) NOT NULL DEFAULT '' COMMENT '税务登记号',
  `bank` varchar(100) NOT NULL DEFAULT '' COMMENT '开户行名称',
  `account` varchar(100) NOT NULL DEFAULT '' COMMENT '开户银行账号',
  `address` varchar(100) NOT NULL DEFAULT '' COMMENT '公司地址',
  `phone` varchar(100) NOT NULL DEFAULT '' COMMENT '联系电话',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_wechat_user` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `openid` varchar(50) NOT NULL DEFAULT '',
  `nickname` varchar(30) NOT NULL DEFAULT '' COMMENT '昵称',
  `sex` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '性别 为未知,1 为男性,2 为女性',
  `province` varchar(30) NOT NULL DEFAULT '' COMMENT '省份',
  `city` varchar(30) DEFAULT '' COMMENT '城市',
  `country` varchar(30) NOT NULL DEFAULT '' COMMENT '国家',
  `language` varchar(60) DEFAULT NULL,
  `headimgurl` varchar(255) DEFAULT NULL COMMENT '头像',
  `unionid` varchar(255) NOT NULL DEFAULT '' COMMENT '用户标识',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `openid` (`openid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT COMMENT='微信用户信息表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_withdraw` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户ID',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '金额',
  `relid` int(11) NOT NULL DEFAULT '0' COMMENT '支付方式ID',
  `admin` int(11) NOT NULL DEFAULT '0' COMMENT '操作管理员',
  `status` varchar(25) NOT NULL DEFAULT 'Pending' COMMENT '状态:Pending待审核，Cancelled已驳回，Active已通过',
  `cancelled_reason` varchar(255) NOT NULL DEFAULT '' COMMENT '驳回原因',
  `type` varchar(25) NOT NULL DEFAULT 'credit' COMMENT '类型:credit余额提现,income收益提现',
  `account_id` int(11) NOT NULL DEFAULT '0' COMMENT '交易流水ID',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  KEY `relid` (`relid`),
  KEY `account_id` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提现';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_withdraw_method` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户ID',
  `type` varchar(50) CHARACTER SET utf8 NOT NULL DEFAULT '' COMMENT '收款方式:bank银行卡，alipay支付宝',
  `account_bank` varchar(255) CHARACTER SET utf8 NOT NULL DEFAULT '' COMMENT '开户银行',
  `account_name` varchar(25) NOT NULL DEFAULT '' COMMENT '开户姓名',
  `account_num` varchar(255) NOT NULL DEFAULT '' COMMENT '开户卡号',
  `account_address` varchar(255) NOT NULL DEFAULT '' COMMENT '开户行网点',
  `username` varchar(25) NOT NULL DEFAULT '' COMMENT '姓名',
  `alipay` varchar(255) NOT NULL DEFAULT '' COMMENT '支付宝账号',
  `default` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否默认:1默认，0否',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提现方式';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_zjmf_finance_api` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `upstream_uid` int(11) NOT NULL DEFAULT '0' COMMENT '上游的clients ID',
  `hostname` varchar(255) NOT NULL DEFAULT '' COMMENT '接口地址',
  `username` varchar(255) NOT NULL DEFAULT '' COMMENT '用户名',
  `password` varchar(255) NOT NULL DEFAULT '' COMMENT '密码',
  `status` tinyint(3) NOT NULL DEFAULT '0' COMMENT '连接状态0异常,1正常',
  `product_num` int(11) NOT NULL DEFAULT '0' COMMENT '可售商品总数',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '名称',
  `des` text COMMENT '描述备注',
  `contact_way` varchar(255) NOT NULL DEFAULT '' COMMENT '联系方式',
  `type` varchar(25) NOT NULL DEFAULT 'zjmf_api' COMMENT '上游类型:zjmf_api智简魔方，manual手动',
  `is_resource` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否资源池api:1是，0否默认',
  `ticket_open` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否开启工单传递：1时，0否默认',
  `is_using` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否正在使用的资源池账号',
  `auto_update` tinyint(1) NOT NULL DEFAULT '1' COMMENT '前台订购实时更新库存和商品,1开启默认,0关闭',
  PRIMARY KEY (`id`),
  KEY `upstream_uid` (`upstream_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shd_zjmf_pushhost` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `host_id` int(10) NOT NULL COMMENT '主机ID',
  `status` char(1) NOT NULL DEFAULT '0' COMMENT '1成功,0失败',
  `url` text NOT NULL COMMENT 'url',
  `post_data` text NOT NULL COMMENT '推送给下游信息',
  `time` int(10) NOT NULL COMMENT '上一次推送时间',
  `num` tinyint(2) NOT NULL COMMENT '推送次数',
  PRIMARY KEY (`id`),
  KEY `ststus` (`status`),
  KEY `host_id` (`host_id`),
  KEY `num` (`num`)
) ENGINE=InnoDB AUTO_INCREMENT=131 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

