CREATE TABLE `glpi_plugin_uxconnector_authconfig_secrets` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `plugin_uxconnector_authconfigs_id` int unsigned NOT NULL,
    `protected_secret` text NOT NULL,
    `date_mod` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unicity` (`plugin_uxconnector_authconfigs_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
