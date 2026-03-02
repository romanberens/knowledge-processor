-- CMS integrations control plane (Strapi v1): editable runtime config stored in DB.

CREATE TABLE IF NOT EXISTS cms_integrations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type ENUM('strapi') NOT NULL UNIQUE,
  base_url VARCHAR(255) NOT NULL,
  content_type VARCHAR(64) NOT NULL,
  api_token_enc TEXT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

