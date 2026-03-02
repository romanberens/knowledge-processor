CREATE TABLE IF NOT EXISTS runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mode ENUM('deep', 'update') NOT NULL,
  status ENUM('running', 'ok', 'partial', 'error') NOT NULL DEFAULT 'running',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  new_posts INT UNSIGNED NOT NULL DEFAULT 0,
  total_seen INT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  details_json JSON NULL,
  INDEX idx_runs_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_url VARCHAR(1024) NOT NULL,
  url_hash CHAR(64) NOT NULL,
  author VARCHAR(255) NULL,
  content MEDIUMTEXT NULL,
  source_page ENUM('all', 'reactions', 'comments') NOT NULL,
  activity_type ENUM('post','share','reaction','comment') NULL,
  activity_label VARCHAR(128) NULL,
  activity_urn VARCHAR(255) NULL,
  published_label VARCHAR(128) NULL,
  content_hash CHAR(64) NOT NULL,
  collected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  run_id BIGINT UNSIGNED NULL,
  INDEX idx_posts_source_collected (source_page, collected_at),
  INDEX idx_posts_activity_type (activity_type),
  INDEX idx_posts_activity_urn (activity_urn),
  INDEX idx_posts_run (run_id),
  INDEX idx_posts_author (author),
  UNIQUE KEY uq_posts_content_hash (content_hash),
  UNIQUE KEY uq_posts_source_url_hash (source_page, url_hash),
  FULLTEXT KEY ft_content_author (content, author),
  CONSTRAINT fk_posts_runs FOREIGN KEY (run_id) REFERENCES runs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tags (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  UNIQUE KEY uq_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_tags (
  post_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, tag_id),
  CONSTRAINT fk_post_tags_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
  CONSTRAINT fk_post_tags_tag FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Normalized model (Etap 1): canonical content items + multiple contexts (activity/saved/etc.)
CREATE TABLE IF NOT EXISTS items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_urn VARCHAR(255) NULL,
  canonical_url VARCHAR(1024) NOT NULL,
  url_hash CHAR(64) NOT NULL,
  author VARCHAR(255) NULL,
  content LONGTEXT NULL,
  content_type ENUM('text','article','video','image','document','unknown') NOT NULL DEFAULT 'unknown',
  published_label VARCHAR(128) NULL,
  collected_first_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  collected_last_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_items_urn (item_urn),
  UNIQUE KEY uq_items_url_hash (url_hash),
  INDEX idx_items_author (author),
  FULLTEXT KEY ft_items_content_author (content, author)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_contexts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id BIGINT UNSIGNED NOT NULL,
  source ENUM(
    'activity_all',
    'activity_reactions',
    'activity_comments',
    'activity_videos',
    'activity_images',
    'saved_posts',
    'saved_articles'
  ) NOT NULL,
  activity_kind ENUM('post','share','reaction','comment','save') NOT NULL,
  activity_label VARCHAR(128) NULL,
  context_text MEDIUMTEXT NULL,
  context_hash CHAR(64) NOT NULL,
  collected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  run_id BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_item_context_hash (context_hash),
  INDEX idx_item_contexts_source_collected (source, collected_at),
  INDEX idx_item_contexts_item_source (item_id, source),
  INDEX idx_item_contexts_run (run_id),
  CONSTRAINT fk_item_contexts_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE,
  CONSTRAINT fk_item_contexts_runs FOREIGN KEY (run_id) REFERENCES runs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_tags (
  item_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (item_id, tag_id),
  CONSTRAINT fk_item_tags_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE,
  CONSTRAINT fk_item_tags_tag FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User-owned knowledge layer (Etap KB v1): notes are not part of ingest; keep them in a separate table.
CREATE TABLE IF NOT EXISTS item_user_data (
  item_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_item_user_data_updated_at (updated_at),
  FULLTEXT KEY ft_item_user_data_notes (notes),
  CONSTRAINT fk_item_user_data_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
