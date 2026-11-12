CREATE TABLE `glpi_plugin_uxconnector_objectlinks` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `auth_config_reference` int unsigned NOT NULL,
    `plugin_uxconnector_syncjobs_id` int unsigned NOT NULL DEFAULT 0,
    `external_id` varchar(191) NOT NULL,
    `external_object_type` varchar(64) NOT NULL DEFAULT 'record',
    `itemtype` varchar(32) NOT NULL,
    `item_id` int unsigned NOT NULL,
    `last_seen_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_scope_external` (
        `auth_config_reference`, `itemtype`, `plugin_uxconnector_syncjobs_id`, `external_id`
    ),
    KEY `plugin_uxconnector_syncjobs_id` (`plugin_uxconnector_syncjobs_id`),
    KEY `connection_external_lookup` (`auth_config_reference`, `external_object_type`, `external_id`),
    KEY `connection_item_lookup` (`auth_config_reference`, `itemtype`, `item_id`),
    KEY `item_lookup` (`itemtype`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
