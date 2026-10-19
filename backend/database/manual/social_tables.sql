-- PiziDesk — Social tables (MySQL). Run ONCE on the live database if you can't
-- run `php artisan migrate --force`. Safe to run twice (IF NOT EXISTS + guarded
-- column add + idempotent migration rows).

CREATE TABLE IF NOT EXISTS `social_connections` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `page_id` VARCHAR(255) NOT NULL,
  `page_name` VARCHAR(255) NULL,
  `page_access_token` TEXT NOT NULL,
  `ig_user_id` VARCHAR(255) NULL,
  `ig_username` VARCHAR(255) NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'connected',
  `meta` JSON NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `updated_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `social_connections_uuid_unique` (`uuid`),
  UNIQUE KEY `social_connections_tenant_id_unique` (`tenant_id`),
  CONSTRAINT `social_connections_tenant_id_foreign` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `social_posts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `caption` TEXT NULL,
  `image_url` TEXT NULL,
  `media_type` VARCHAR(8) NOT NULL DEFAULT 'image',
  `image_path` VARCHAR(255) NULL,
  `targets` JSON NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'draft',
  `scheduled_at` TIMESTAMP NULL DEFAULT NULL,
  `published_at` TIMESTAMP NULL DEFAULT NULL,
  `results` JSON NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `social_posts_uuid_unique` (`uuid`),
  KEY `social_posts_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `social_posts_status_scheduled_at_index` (`status`,`scheduled_at`),
  CONSTRAINT `social_posts_tenant_id_foreign` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- If social_posts already existed WITHOUT media_type, add it (ignore error if present):
-- ALTER TABLE `social_posts` ADD COLUMN `media_type` VARCHAR(8) NOT NULL DEFAULT 'image' AFTER `image_url`;

-- Record the migrations so `php artisan migrate` won't try to re-create them.
SET @b = (SELECT COALESCE(MAX(batch),0)+1 FROM migrations);
INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_01_01_000100_create_social_connections_table' AS m, @b AS b) t
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_01_01_000100_create_social_connections_table');
INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_01_01_000101_create_social_posts_table' AS m, @b AS b) t
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_01_01_000101_create_social_posts_table');
INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_01_01_000102_add_media_type_to_social_posts' AS m, @b AS b) t
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_01_01_000102_add_media_type_to_social_posts');
