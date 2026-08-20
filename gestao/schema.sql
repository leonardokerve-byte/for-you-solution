-- Schema do Sistema de Gestão - For You Solution
-- Rode este arquivo uma vez no phpMyAdmin (hPanel da Hostinger), no banco criado para o sistema.
-- Se o banco já existe com dados, use migration_002_distribuidores_e_faturamento.sql em vez deste.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS finance_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  kind ENUM('receita','despesa') NOT NULL DEFAULT 'despesa'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO finance_categories (name, kind) VALUES
  ('Mão de obra', 'despesa'),
  ('Aluguel', 'despesa'),
  ('Frete', 'despesa'),
  ('Taxa de gestão', 'despesa'),
  ('Fornecedor', 'despesa'),
  ('Salário', 'despesa'),
  ('Adiantamento para técnico', 'despesa'),
  ('Outros', 'despesa'),
  ('Serviço prestado', 'receita'),
  ('Outras receitas', 'receita')
ON DUPLICATE KEY UPDATE name = name;

CREATE TABLE IF NOT EXISTS finance_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type ENUM('receita','despesa') NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  due_date DATE NULL,
  paid_date DATE NULL,
  status ENUM('pendente','pago') NOT NULL DEFAULT 'pendente',
  pix_ref VARCHAR(190) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES finance_categories(id),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS distributors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO distributors (name) VALUES ('Distribuidor 1'), ('Distribuidor 2');

CREATE TABLE IF NOT EXISTS technicians (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NULL,
  city VARCHAR(120) NULL,
  distributor_id INT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (distributor_id) REFERENCES distributors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_movements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type ENUM('entrada_distribuidora','saida_para_tecnico') NOT NULL,
  distributor_id INT UNSIGNED NULL,
  reason VARCHAR(60) NULL,
  technician_id INT UNSIGNED NULL,
  quantity INT NOT NULL,
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (distributor_id) REFERENCES distributors(id),
  FOREIGN KEY (technician_id) REFERENCES technicians(id),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS import_batches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  filename VARCHAR(255) NOT NULL,
  imported_by INT UNSIGNED NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS work_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  os_number VARCHAR(60) NOT NULL UNIQUE,
  install_date DATE NULL,
  technician_id INT UNSIGNED NOT NULL,
  city VARCHAR(120) NULL,
  uf VARCHAR(2) NULL,
  import_batch_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (technician_id) REFERENCES technicians(id),
  FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tax_calculations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  description VARCHAR(255) NOT NULL,
  gross_amount DECIMAL(12,2) NOT NULL,
  tax_percent DECIMAL(5,2) NOT NULL DEFAULT 15.00,
  tax_amount DECIMAL(12,2) NOT NULL,
  net_amount DECIMAL(12,2) NOT NULL,
  labor_cost DECIMAL(12,2) NULL,
  labor_technician_id INT UNSIGNED NULL,
  finance_entry_id INT UNSIGNED NULL,
  labor_finance_entry_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (labor_technician_id) REFERENCES technicians(id),
  FOREIGN KEY (finance_entry_id) REFERENCES finance_entries(id) ON DELETE SET NULL,
  FOREIGN KEY (labor_finance_entry_id) REFERENCES finance_entries(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS import_column_mappings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  field_key VARCHAR(40) NOT NULL UNIQUE,
  source_header VARCHAR(190) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
