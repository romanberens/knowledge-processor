-- Editorial workflow (Etap 7): selection queue + writing drafts.

CREATE TABLE IF NOT EXISTS editorial_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_item_id BIGINT UNSIGNED NOT NULL,
  editorial_status ENUM('selected','draft','in_progress','ready','published','archived') NOT NULL DEFAULT 'selected',
  portal_topic ENUM('ai','oss','programming','fundamentals','other') NOT NULL DEFAULT 'other',
  priority TINYINT UNSIGNED NOT NULL DEFAULT 3,
  selected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  published_url VARCHAR(1024) NULL,
  cms_external_id VARCHAR(255) NULL,
  UNIQUE KEY uq_editorial_items_source_item (source_item_id),
  INDEX idx_editorial_items_status_topic (editorial_status, portal_topic, priority, updated_at),
  CONSTRAINT fk_editorial_items_source_item FOREIGN KEY (source_item_id) REFERENCES items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS editorial_drafts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  editorial_item_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  lead_text TEXT NULL,
  body LONGTEXT NULL,
  format ENUM('curation','article','tools','weekly_digest') NOT NULL DEFAULT 'curation',
  seo_title VARCHAR(255) NULL,
  seo_description TEXT NULL,
  cms_status ENUM('local_draft','sent_to_cms','published') NOT NULL DEFAULT 'local_draft',
  cms_external_id VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_editorial_drafts_editorial_item (editorial_item_id),
  INDEX idx_editorial_drafts_updated_at (updated_at),
  CONSTRAINT fk_editorial_drafts_editorial_item FOREIGN KEY (editorial_item_id) REFERENCES editorial_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
