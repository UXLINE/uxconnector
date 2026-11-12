CREATE TABLE `glpi_plugin_uxconnector_sync_checkpoints` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `plugin_uxconnector_syncjobs_id` int unsigned NOT NULL,
    `cursor_value` text NOT NULL,
    `date_mod` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `syncjob` (`plugin_uxconnector_syncjobs_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
