SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET collation_connection = 'utf8mb4_unicode_ci';

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','cashier') NOT NULL DEFAULT 'cashier',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_tables (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_table_name (name),
  KEY idx_tables_active_sort (is_active, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NULL,
  name VARCHAR(150) NOT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_product_name (name),
  KEY idx_products_active_category_sort (is_active, category_id, sort_order),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS business_days (
  id INT AUTO_INCREMENT PRIMARY KEY,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  opened_by INT NULL,
  closed_by INT NULL,
  opened_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at TIMESTAMP NULL DEFAULT NULL,
  opening_cash DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  closing_cash DECIMAL(10,2) NULL DEFAULT NULL,
  expected_cash DECIMAL(10,2) NULL DEFAULT NULL,
  cash_difference DECIMAL(10,2) NULL DEFAULT NULL,
  note VARCHAR(255) NULL,
  close_note VARCHAR(255) NULL,
  CONSTRAINT fk_days_opened_by FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_days_closed_by FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_business_days_status (status),
  KEY idx_business_days_opened_at (opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_movements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_day_id INT NOT NULL,
  user_id INT NULL,
  type ENUM('add','remove','expense') NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cash_movements_day FOREIGN KEY (business_day_id) REFERENCES business_days(id) ON DELETE CASCADE,
  CONSTRAINT fk_cash_movements_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_cash_type_created (type, created_at),
  KEY idx_cash_day (business_day_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  receipt_number INT UNSIGNED NULL,
  business_day_id INT NOT NULL,
  table_id INT NOT NULL,
  user_id INT NULL,
  status ENUM('open','closed','cancelled') NOT NULL DEFAULT 'open',
  total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  subtotal_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount_type ENUM('none','percent','amount') NOT NULL DEFAULT 'none',
  discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  cancelled_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_type ENUM('cash','card','mixed') NULL,
  cash_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  card_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  cancel_reason VARCHAR(255) NULL,
  cancelled_by INT NULL,
  cancelled_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  closed_at TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT fk_orders_day FOREIGN KEY (business_day_id) REFERENCES business_days(id),
  CONSTRAINT fk_orders_table FOREIGN KEY (table_id) REFERENCES restaurant_tables(id),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_orders_receipt_number (receipt_number),
  KEY idx_orders_status (status),
  KEY idx_orders_day (business_day_id),
  KEY idx_orders_day_table_status (business_day_id, table_id, status, id),
  KEY idx_orders_day_status_table (business_day_id, status, table_id, id),
  KEY idx_orders_status_closed (status, closed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_number_sequence (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB AUTO_INCREMENT=1000 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receipt_templates (
  type VARCHAR(20) NOT NULL PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  top_note TEXT NULL,
  bottom_note TEXT NULL,
  show_restaurant_name TINYINT(1) NOT NULL DEFAULT 1,
  show_english_name TINYINT(1) NOT NULL DEFAULT 1,
  show_address TINYINT(1) NOT NULL DEFAULT 1,
  show_phone TINYINT(1) NOT NULL DEFAULT 1,
  show_table TINYINT(1) NOT NULL DEFAULT 1,
  show_datetime TINYINT(1) NOT NULL DEFAULT 1,
  show_receipt_number TINYINT(1) NOT NULL DEFAULT 1,
  show_prices TINYINT(1) NOT NULL DEFAULT 1,
  show_comments TINYINT(1) NOT NULL DEFAULT 1,
  show_totals TINYINT(1) NOT NULL DEFAULT 0,
  show_payment TINYINT(1) NOT NULL DEFAULT 0,
  font_size TINYINT UNSIGNED NOT NULL DEFAULT 13,
  line_width TINYINT UNSIGNED NOT NULL DEFAULT 32,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NOT NULL,
  product_name VARCHAR(150) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  product_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  comment VARCHAR(255) NULL,
  sent_at TIMESTAMP NULL DEFAULT NULL,
  is_cancelled TINYINT(1) NOT NULL DEFAULT 0,
  cancelled_by INT NULL,
  cancelled_at TIMESTAMP NULL DEFAULT NULL,
  cancel_reason VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_items_cancel_user FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_items_order (order_id),
  KEY idx_items_order_active_sent (order_id, is_cancelled, sent_at),
  KEY idx_items_sent (sent_at),
  KEY idx_items_cancelled (is_cancelled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users (name, username, password_hash, role, is_active) VALUES
('ადმინისტრატორი', 'admin', '$2y$12$N.fnuigeQ.UzkNXzp.ykiucBxG3RG2W1uvIs5FXYT6qD1AaDG78v6', 'admin', 1),
('მოლარე', 'cashier', '$2y$12$prOWizzsWHCWG7AV9sDWUuiaNS99lkl2L/2xqfsUp/FRieQtVovOC', 'cashier', 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), role=VALUES(role), is_active=VALUES(is_active);

INSERT INTO restaurant_tables (name, sort_order) VALUES
('მაგიდა 1', 1), ('მაგიდა 2', 2), ('მაგიდა 3', 3), ('მაგიდა 4', 4), ('მაგიდა 5', 5), ('მაგიდა 6', 6),
('მაგიდა 7', 7), ('მაგიდა 8', 8), ('მაგიდა 9', 9), ('მაგიდა 10', 10), ('მაგიდა 11', 11), ('მაგიდა 12', 12),
('გატანა 1', 1001), ('გატანა 2', 1002), ('გატანა 3', 1003), ('გატანა 4', 1004), ('გატანა 5', 1005)
ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order), is_active=1;

INSERT INTO categories (name, sort_order) VALUES
('ხინკალი', 1), ('სასმელი', 2), ('სხვა', 99)
ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order), is_active=1;

INSERT INTO products (category_id, name, price, cost, sort_order, is_active)
SELECT c.id, 'ქალაქური ხინკალი', 1.70, 0.00, 1, 1 FROM categories c WHERE c.name='ხინკალი'
ON DUPLICATE KEY UPDATE price=VALUES(price), category_id=VALUES(category_id), is_active=1;
