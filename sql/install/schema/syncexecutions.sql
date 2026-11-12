CREATE TABLE `glpi_plugin_uxconnector_syncexecutions` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `plugin_uxconnector_syncjobs_id` int unsigned NOT NULL,
    `status` varchar(32) NOT NULL,
    `started_at` timestamp NULL DEFAULT NULL,
    `finished_at` timestamp NULL DEFAULT NULL,
    `items_processed` int unsigned NOT NULL DEFAULT 0,
    `items_failed` int unsigned NOT NULL DEFAULT 0,
    `error_message` text NULL,
    PRIMARY KEY (`id`),
    KEY `plugin_uxconnector_syncjobs_id` (`plugin_uxconnector_syncjobs_id`),
    KEY `status` (`status`),
    KEY `started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
