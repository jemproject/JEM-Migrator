CREATE TABLE IF NOT EXISTS `#__jem_import_runs` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `source` varchar(50) NOT NULL DEFAULT '',
    `label` varchar(100) NOT NULL DEFAULT '',
    `format_version` smallint(5) unsigned NOT NULL DEFAULT '1',
    `options` text NOT NULL,
    `state` varchar(20) NOT NULL DEFAULT 'running',
    `counts` text NOT NULL,
    `message` varchar(1024) NOT NULL DEFAULT '',
    `created` datetime NOT NULL,
    `created_by` int(11) unsigned NOT NULL DEFAULT '0',
    `finished` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_source` (`source`),
    KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__jem_import_images` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `run_id` int(11) unsigned NOT NULL DEFAULT '0',
    `batch` int(11) unsigned NOT NULL DEFAULT '0',
    `entity` varchar(20) NOT NULL DEFAULT '',
    `jem_id` int(11) unsigned NOT NULL DEFAULT '0',
    `relative_path` varchar(512) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `idx_run` (`run_id`, `batch`),
    KEY `idx_owner` (`entity`, `jem_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__jem_import_records` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `run_id` int(11) unsigned NOT NULL DEFAULT '0',
    `batch` int(11) unsigned NOT NULL DEFAULT '0',
    `source` varchar(50) NOT NULL DEFAULT '',
    `entity` varchar(20) NOT NULL DEFAULT '',
    `source_ref` char(64) NOT NULL DEFAULT '',
    `jem_id` int(11) unsigned NOT NULL DEFAULT '0',
    `created` tinyint(1) NOT NULL DEFAULT '1',
    PRIMARY KEY (`id`),
    KEY `idx_lookup` (`source`, `entity`, `source_ref`),
    KEY `idx_run` (`run_id`, `batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__jem_import_log` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `run_id` int(11) unsigned NOT NULL DEFAULT '0',
    `batch` int(11) unsigned NOT NULL DEFAULT '0',
    `level` varchar(10) NOT NULL DEFAULT 'info',
    `entity` varchar(20) NOT NULL DEFAULT '',
    `jem_id` int(11) unsigned NOT NULL DEFAULT '0',
    `message` varchar(1024) NOT NULL DEFAULT '',
    `created` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_run` (`run_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
