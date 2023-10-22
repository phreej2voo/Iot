/*
 Iot Database Relation Tables
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for oppay_device
-- ----------------------------
DROP TABLE IF EXISTS `oppay_device`;
CREATE TABLE `oppay_device`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `deviceId` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '设备型号',
  `clientId` tinyint(4) UNSIGNED NOT NULL COMMENT '设备客户端TCP唯一标识ID',
  `online` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '在线状态  0: 断线  1: 在线',
  `createdAt` datetime(0) NOT NULL COMMENT '创建时间',
  `updatedAt` datetime(0) NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `deviceId`(`deviceId`) USING BTREE COMMENT '设备型号'
) ENGINE = MyISAM AUTO_INCREMENT = 3 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = 'TCP建立连接设备参数记录表' ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for oppay_device_log
-- ----------------------------
DROP TABLE IF EXISTS `oppay_device_log`;
CREATE TABLE `oppay_device_log`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `clientId` tinyint(3) UNSIGNED NOT NULL COMMENT '设备客户端TCP唯一标识ID',
  `data` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '接收客户端数据',
  `createdAt` datetime(0) NOT NULL COMMENT '创建时间',
  `updatedAt` datetime(0) NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `clientId`(`clientId`) USING BTREE COMMENT '设备客户端TCP唯一标识ID'
) ENGINE = InnoDB AUTO_INCREMENT = 345 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = 'TCP服务接收设备数据记录表' ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for oppay_dispatch_log
-- ----------------------------
DROP TABLE IF EXISTS `oppay_dispatch_log`;
CREATE TABLE `oppay_dispatch_log`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `outTradeNo` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '商户交易单号，唯一',
  `deviceId` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '设备型号',
  `clientId` tinyint(4) NOT NULL COMMENT '设备客户端TCP唯一标识ID',
  `status` tinyint(3) NOT NULL DEFAULT 0 COMMENT '分发结果状态  0: 失败   1: 成功',
  `createdAt` datetime(0) NOT NULL COMMENT '创建时间',
  `updatedAt` datetime(0) NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `outTradeNo`(`outTradeNo`) USING BTREE COMMENT '商户交易单号',
  INDEX `deviceId`(`deviceId`) USING BTREE COMMENT '设备型号',
  INDEX `clientId`(`deviceId`) USING BTREE COMMENT '设备客户端TCP唯一标识ID',
  INDEX `status`(`status`) USING BTREE COMMENT '分发结果状态'
) ENGINE = InnoDB AUTO_INCREMENT = 31 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = 'TCP建立连接设备分发日志记录表' ROW_FORMAT = Dynamic;

SET FOREIGN_KEY_CHECKS = 1;
