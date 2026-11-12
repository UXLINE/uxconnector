CREATE TABLE `glpi_plugin_uxconnector_webhookrequests` (
    `fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `expires_at` bigint unsigned NOT NULL,
    PRIMARY KEY (`fingerprint`),
    KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
