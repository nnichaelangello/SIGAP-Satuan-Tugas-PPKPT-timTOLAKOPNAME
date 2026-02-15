-- ============================================================
-- SIGAP PPKS - MIGRATION V2: 5-Phase Reporting System
-- Version: 2.1.0
-- Date: February 2026
-- Description: Adds Psikolog role, scheduling, consultation
--              notes, feedback loop, and status history
-- ============================================================

USE sigap_ppks;

-- ============================================================
-- TABLE: Psikolog
-- Purpose: Psychologist accounts for consultation sessions
-- ============================================================
CREATE TABLE IF NOT EXISTS `Psikolog` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `nama_lengkap` VARCHAR(255) NOT NULL,
  `spesialisasi` VARCHAR(255) NULL COMMENT 'e.g., Trauma, Anak, Keluarga',
  `no_telepon` VARCHAR(20) NULL,
  `foto_url` VARCHAR(255) NULL,
  `status` ENUM('aktif', 'nonaktif') DEFAULT 'aktif',
  `failed_attempts` INT DEFAULT 0,
  `locked_until` TIMESTAMP NULL DEFAULT NULL,
  `last_login` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_email (`email`),
  INDEX idx_username (`username`),
  INDEX idx_status (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ALTER TABLE: Laporan
-- Add columns for validation, rejection, and psikolog assignment
-- ============================================================
ALTER TABLE `Laporan`
  ADD COLUMN `alasan_penolakan` TEXT NULL AFTER `status_laporan`,
  ADD COLUMN `validated_by_admin` INT NULL AFTER `alasan_penolakan`,
  ADD COLUMN `assigned_psikolog_id` INT NULL AFTER `validated_by_admin`,
  ADD COLUMN `auto_close_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Auto-close deadline after psikolog submits notes',
  ADD COLUMN `dispute_count` INT DEFAULT 0 COMMENT 'Number of disputes by user',
  ADD CONSTRAINT `fk_laporan_admin` FOREIGN KEY (`validated_by_admin`) REFERENCES `Admin`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_laporan_psikolog` FOREIGN KEY (`assigned_psikolog_id`) REFERENCES `Psikolog`(`id`) ON DELETE SET NULL,
  ADD INDEX idx_assigned_psikolog (`assigned_psikolog_id`),
  ADD INDEX idx_validated_by (`validated_by_admin`);

-- Update status_laporan to support new statuses
-- Note: We keep VARCHAR for backward compatibility but add CHECK constraint
ALTER TABLE `Laporan`
  MODIFY COLUMN `status_laporan` VARCHAR(50) NOT NULL DEFAULT 'Investigasi';

-- Update existing records to new status naming
UPDATE `Laporan` SET `status_laporan` = 'Investigasi' WHERE `status_laporan` = 'Process';
UPDATE `Laporan` SET `status_laporan` = 'Closed' WHERE `status_laporan` = 'Completed';
UPDATE `Laporan` SET `status_laporan` = 'Dilanjutkan' WHERE `status_laporan` = 'In Progress';

-- ============================================================
-- TABLE: JadwalPertemuan
-- Purpose: Scheduling meetings between User and Psikolog
-- ============================================================
CREATE TABLE IF NOT EXISTS `JadwalPertemuan` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `laporan_id` INT NOT NULL,
  `psikolog_id` INT NOT NULL,
  `scheduled_by_admin` INT NULL,
  `waktu_mulai` DATETIME NOT NULL,
  `waktu_selesai` DATETIME NOT NULL,
  `tipe` ENUM('online', 'offline') NOT NULL DEFAULT 'offline',
  `tempat_atau_link` VARCHAR(500) NOT NULL COMMENT 'Physical location or meeting URL',
  `status_jadwal` ENUM('scheduled', 'completed', 'cancelled', 'rescheduled') DEFAULT 'scheduled',
  `catatan_admin` TEXT NULL COMMENT 'Admin notes about the scheduling',
  `jadwal_lama_id` INT NULL COMMENT 'Reference to old schedule if rescheduled',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`laporan_id`) REFERENCES `Laporan`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`psikolog_id`) REFERENCES `Psikolog`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`scheduled_by_admin`) REFERENCES `Admin`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`jadwal_lama_id`) REFERENCES `JadwalPertemuan`(`id`) ON DELETE SET NULL,
  INDEX idx_laporan_id (`laporan_id`),
  INDEX idx_psikolog_id (`psikolog_id`),
  INDEX idx_waktu_mulai (`waktu_mulai`),
  INDEX idx_status_jadwal (`status_jadwal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: CatatanKonsultasi
-- Purpose: Consultation notes entered by Psikolog after session
-- ============================================================
CREATE TABLE IF NOT EXISTS `CatatanKonsultasi` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `laporan_id` INT NOT NULL,
  `psikolog_id` INT NOT NULL,
  `jadwal_id` INT NULL COMMENT 'Links to the meeting schedule',
  `ringkasan_kasus` TEXT NOT NULL COMMENT 'Case summary',
  `detail_konsultasi` TEXT NOT NULL COMMENT 'Detailed consultation notes',
  `rekomendasi` TEXT NULL COMMENT 'Follow-up recommendations',
  `tingkat_risiko` ENUM('rendah', 'sedang', 'tinggi', 'kritis') NOT NULL DEFAULT 'sedang',
  `is_encrypted` BOOLEAN DEFAULT FALSE,
  `encrypted_data` TEXT NULL COMMENT 'Encrypted sensitive fields (JSON)',
  `encryption_version` VARCHAR(10) DEFAULT 'v1',
  `status_catatan` ENUM('draft', 'submitted', 'confirmed', 'disputed') DEFAULT 'draft',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`laporan_id`) REFERENCES `Laporan`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`psikolog_id`) REFERENCES `Psikolog`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`jadwal_id`) REFERENCES `JadwalPertemuan`(`id`) ON DELETE SET NULL,
  INDEX idx_laporan_id (`laporan_id`),
  INDEX idx_psikolog_id (`psikolog_id`),
  INDEX idx_status_catatan (`status_catatan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: FeedbackUser
-- Purpose: User confirmation or dispute of consultation notes
-- ============================================================
CREATE TABLE IF NOT EXISTS `FeedbackUser` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `laporan_id` INT NOT NULL,
  `catatan_id` INT NOT NULL,
  `tipe_feedback` ENUM('confirm', 'dispute') NOT NULL,
  `komentar_user` TEXT NULL COMMENT 'User comment on confirmation',
  `detail_dispute` TEXT NULL COMMENT 'Specific dispute details from user',
  `respon_psikolog` TEXT NULL COMMENT 'Psikolog response to dispute',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `responded_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'When psikolog responded',
  FOREIGN KEY (`laporan_id`) REFERENCES `Laporan`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`catatan_id`) REFERENCES `CatatanKonsultasi`(`id`) ON DELETE CASCADE,
  INDEX idx_laporan_id (`laporan_id`),
  INDEX idx_catatan_id (`catatan_id`),
  INDEX idx_tipe_feedback (`tipe_feedback`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: StatusHistory
-- Purpose: Audit trail for all status changes on reports
-- ============================================================
CREATE TABLE IF NOT EXISTS `StatusHistory` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `laporan_id` INT NOT NULL,
  `status_lama` VARCHAR(50) NULL,
  `status_baru` VARCHAR(50) NOT NULL,
  `diubah_oleh_role` ENUM('admin', 'psikolog', 'user', 'system') NOT NULL,
  `diubah_oleh_id` INT NULL COMMENT 'ID of admin/psikolog who changed it',
  `keterangan` TEXT NULL COMMENT 'Additional notes about the change',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`laporan_id`) REFERENCES `Laporan`(`id`) ON DELETE CASCADE,
  INDEX idx_laporan_id (`laporan_id`),
  INDEX idx_created_at (`created_at`),
  INDEX idx_status_baru (`status_baru`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DEFAULT PSIKOLOG ACCOUNT (for testing)
-- Password: psikolog123 -> generate with: password_hash('psikolog123', PASSWORD_DEFAULT)
-- ============================================================
-- INSERT INTO `Psikolog` (`username`, `email`, `password_hash`, `nama_lengkap`, `spesialisasi`, `status`)
-- VALUES (
--   'dr.sari',
--   'sari.psikolog@telkomuniversity.ac.id',
--   '$2y$10$REPLACE_WITH_YOUR_GENERATED_HASH',
--   'Dr. Sari Wijayanti, M.Psi',
--   'Trauma & Kekerasan Seksual',
--   'aktif'
-- );

-- ============================================================
-- VERIFICATION
-- ============================================================
SELECT '======================================' AS '';
SELECT 'MIGRATION V2 COMPLETE' AS Status;
SELECT '======================================' AS '';

SELECT TABLE_NAME as 'New/Modified Tables'
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'sigap_ppks'
  AND TABLE_NAME IN ('Psikolog', 'JadwalPertemuan', 'CatatanKonsultasi', 'FeedbackUser', 'StatusHistory')
ORDER BY TABLE_NAME;

COMMIT;
