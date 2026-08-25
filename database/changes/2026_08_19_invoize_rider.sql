SET NAMES utf8mb4;

-- =====================================================================
-- INVOIZE RIDER APP - DATABASE CHANGES (2026-08-19)
-- Apply against the `invoizdb` database. See docs/DATABASE_CHANGES.md
-- =====================================================================

-- =========================================================
-- TABLE: riders
-- =========================================================
CREATE TABLE `riders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `vehicle_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `license_plate` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('available','delivering','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'available',
  `is_online` tinyint(1) NOT NULL DEFAULT '0',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `riders_email_unique` (`email`),
  KEY `riders_user_id_foreign` (`user_id`),
  CONSTRAINT `riders_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TABLE: deliveries
-- =========================================================
CREATE TABLE `deliveries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tracking_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_id` bigint unsigned DEFAULT NULL,
  `rider_id` bigint unsigned DEFAULT NULL,
  `status` enum('waiting_for_rider','assigned','accepted','going_to_pickup','arrived_at_shop','picked_up','out_for_delivery','arrived_at_customer','delivered','delivery_failed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'waiting_for_rider',
  `assigned_at` timestamp NULL DEFAULT NULL,
  `picked_up_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `failure_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivery_notes` text COLLATE utf8mb4_unicode_ci,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount_to_collect` decimal(12,2) DEFAULT NULL,
  `delivery_fee` decimal(12,2) DEFAULT NULL,
  `pickup_pin` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sender_lat` decimal(10,7) DEFAULT NULL,
  `sender_lng` decimal(10,7) DEFAULT NULL,
  `recipient_lat` decimal(10,7) DEFAULT NULL,
  `recipient_lng` decimal(10,7) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sender_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sender_phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sender_address` text COLLATE utf8mb4_unicode_ci,
  `recipient_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_address` text COLLATE utf8mb4_unicode_ci,
  `weight` decimal(8,2) DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_id` (`order_id`),
  UNIQUE KEY `deliveries_tracking_number_unique` (`tracking_number`),
  KEY `deliveries_rider_id_index` (`rider_id`),
  CONSTRAINT `fk_deliveries_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TABLE: delivery_items
-- =========================================================
CREATE TABLE `delivery_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `delivery_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `variant_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `delivery_items_delivery_id_foreign` (`delivery_id`),
  CONSTRAINT `delivery_items_delivery_id_foreign` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TABLE: delivery_proofs
-- =========================================================
CREATE TABLE `delivery_proofs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `delivery_id` bigint unsigned NOT NULL,
  `rider_id` bigint unsigned NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'photo',
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signature_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `otp` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `longitude` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `delivery_proofs_delivery_id_foreign` (`delivery_id`),
  KEY `delivery_proofs_rider_id_foreign` (`rider_id`),
  CONSTRAINT `delivery_proofs_delivery_id_foreign` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `delivery_proofs_rider_id_foreign` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TABLE: delivery_failures
-- =========================================================
CREATE TABLE `delivery_failures` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `delivery_id` bigint unsigned NOT NULL,
  `rider_id` bigint unsigned NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `reported_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `delivery_failures_delivery_id_foreign` (`delivery_id`),
  KEY `delivery_failures_rider_id_foreign` (`rider_id`),
  CONSTRAINT `delivery_failures_delivery_id_foreign` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `delivery_failures_rider_id_foreign` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TABLE: rider_earnings
-- =========================================================
CREATE TABLE `rider_earnings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rider_id` bigint unsigned NOT NULL,
  `delivery_id` bigint unsigned DEFAULT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'delivery',
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `earned_on` date NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rider_earnings_delivery_id_foreign` (`delivery_id`),
  KEY `rider_earnings_rider_id_earned_on_index` (`rider_id`,`earned_on`),
  CONSTRAINT `rider_earnings_delivery_id_foreign` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rider_earnings_rider_id_foreign` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TABLE: rider_locations
-- =========================================================
CREATE TABLE `rider_locations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rider_id` bigint unsigned NOT NULL,
  `delivery_id` bigint unsigned DEFAULT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `rider_locations_delivery_id_foreign` (`delivery_id`),
  KEY `rider_locations_rider_id_recorded_at_index` (`rider_id`,`recorded_at`),
  CONSTRAINT `rider_locations_delivery_id_foreign` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rider_locations_rider_id_foreign` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TABLE: rider_notifications
-- =========================================================
CREATE TABLE `rider_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rider_id` bigint unsigned NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci,
  `data` json DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rider_notifications_rider_id_is_read_index` (`rider_id`,`is_read`),
  CONSTRAINT `rider_notifications_rider_id_foreign` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

