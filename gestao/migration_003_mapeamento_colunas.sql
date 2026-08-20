-- Migração 003 — rode uma única vez no phpMyAdmin do banco JÁ EM PRODUÇÃO
-- Guarda o mapeamento "de-para" de colunas usado na importação de execuções.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS import_column_mappings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  field_key VARCHAR(40) NOT NULL UNIQUE,
  source_header VARCHAR(190) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
