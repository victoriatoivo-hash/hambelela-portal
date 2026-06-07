ALTER TABLE ops_orders
  MODIFY order_type ENUM('collection', 'delivery', 'courier', 'western_courier', 'coastal_courier', 'easy_parcel', 'hardap_courier', 'seven_seaters', 'yango', 'jet_x', 'formula_courier', 'express_courier') NOT NULL DEFAULT 'collection';
