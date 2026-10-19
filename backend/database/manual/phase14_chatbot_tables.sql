-- PiziDesk — Phase 14 chatbot tables (MySQL). Run ONCE on the live database
-- ONLY if you cannot run `php artisan migrate --force` on the server.
-- Safe to run twice (IF NOT EXISTS + idempotent migration rows).

CREATE TABLE IF NOT EXISTS `chatbots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `whatsapp_phone_number_id` BIGINT UNSIGNED NULL,
  `name` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `welcome_message` TEXT NULL,
  `fallback_message` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `updated_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chatbots_uuid_unique` (`uuid`),
  KEY `chatbots_tenant_id_is_active_index` (`tenant_id`,`is_active`),
  KEY `chatbots_wpn_foreign` (`whatsapp_phone_number_id`),
  CONSTRAINT `chatbots_tenant_id_foreign` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chatbots_wpn_foreign` FOREIGN KEY (`whatsapp_phone_number_id`)
    REFERENCES `whatsapp_phone_numbers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chatbot_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `chatbot_id` BIGINT UNSIGNED NOT NULL,
  `keyword` VARCHAR(255) NOT NULL,
  `match_type` VARCHAR(16) NOT NULL DEFAULT 'contains',
  `response_text` TEXT NULL,
  `response_type` VARCHAR(16) NOT NULL DEFAULT 'text',
  `template_name` VARCHAR(255) NULL,
  `priority` INT UNSIGNED NOT NULL DEFAULT 100,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chatbot_rules_uuid_unique` (`uuid`),
  KEY `chatbot_rules_chatbot_id_priority_index` (`chatbot_id`,`priority`),
  KEY `chatbot_rules_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `chatbot_rules_tenant_id_foreign` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chatbot_rules_chatbot_id_foreign` FOREIGN KEY (`chatbot_id`)
    REFERENCES `chatbots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Record the migrations so a future `php artisan migrate` won't try to re-create them.
SET @b = (SELECT COALESCE(MAX(batch),0)+1 FROM migrations);
INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_01_01_000080_create_chatbots_table' AS m, @b AS b) t
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_01_01_000080_create_chatbots_table');
INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_01_01_000081_create_chatbot_rules_table' AS m, @b AS b) t
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_01_01_000081_create_chatbot_rules_table');
