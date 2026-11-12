CREATE TABLE `glpi_plugin_uxconnector_authconfigs` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(128) NOT NULL,
    `endpoint` varchar(255) NOT NULL,
    `test_path` varchar(255) NOT NULL DEFAULT '/',
    `connector_id` varchar(64) NOT NULL DEFAULT 'custom',
    `auth_method` varchar(64) NOT NULL,
    `status` varchar(32) NOT NULL DEFAULT 'draft',
    `date_creation` timestamp NULL DEFAULT NULL,
    `date_mod` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `name` (`name`),
    KEY `connector_id` (`connector_id`),
    KEY `status` (`status`),
    KEY `date_creation` (`date_creation`),
    KEY `date_mod` (`date_mod`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
