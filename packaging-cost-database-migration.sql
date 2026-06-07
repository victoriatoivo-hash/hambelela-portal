ALTER TABLE packaging
  ADD COLUMN category ENUM('Bottles', 'Jars', 'Pumps', 'Caps', 'Labels', 'Boxes', 'Courier Packaging', 'Shrink Wrap', 'Pouches', 'Tubes', 'Accessories') NOT NULL DEFAULT 'Accessories' AFTER name,
  ADD COLUMN stock_left DECIMAL(12,3) NULL AFTER quantity,
  ADD COLUMN notes TEXT NULL AFTER total_cost;
