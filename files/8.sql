SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `tools_ps_trends` ADD `popularity` DECIMAL(9,2) NOT NULL AFTER `keywords`;