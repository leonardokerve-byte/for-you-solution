-- Migração 002 — rode uma única vez no phpMyAdmin do banco JÁ EM PRODUÇÃO
-- (não use isto num banco novo — para banco novo use schema.sql, que já vem atualizado)
--
-- Adiciona: distribuidores (com saldo próprio), vínculo técnico -> distribuidor,
-- motivo/distribuidor nos movimentos de estoque, e custo de mão de obra na tela de Faturamento.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS distributors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO distributors (name) VALUES ('Distribuidor 1'), ('Distribuidor 2');

ALTER TABLE technicians
  ADD COLUMN distributor_id INT UNSIGNED NULL AFTER city,
  ADD CONSTRAINT fk_technicians_distributor FOREIGN KEY (distributor_id) REFERENCES distributors(id);

ALTER TABLE stock_movements
  ADD COLUMN distributor_id INT UNSIGNED NULL AFTER type,
  ADD COLUMN reason VARCHAR(60) NULL AFTER distributor_id,
  ADD CONSTRAINT fk_stock_movements_distributor FOREIGN KEY (distributor_id) REFERENCES distributors(id);

-- Movimentos antigos (antes de existir "distribuidor") ficam associados ao Distribuidor 1 por padrão.
UPDATE stock_movements SET distributor_id = 1 WHERE distributor_id IS NULL;

ALTER TABLE tax_calculations
  ADD COLUMN labor_cost DECIMAL(12,2) NULL AFTER net_amount,
  ADD COLUMN labor_technician_id INT UNSIGNED NULL AFTER labor_cost,
  ADD COLUMN labor_finance_entry_id INT UNSIGNED NULL AFTER finance_entry_id,
  ADD CONSTRAINT fk_tax_labor_technician FOREIGN KEY (labor_technician_id) REFERENCES technicians(id),
  ADD CONSTRAINT fk_tax_labor_finance_entry FOREIGN KEY (labor_finance_entry_id) REFERENCES finance_entries(id) ON DELETE SET NULL;
