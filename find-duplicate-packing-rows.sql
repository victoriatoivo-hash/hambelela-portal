SELECT
  item_name,
  received_weight,
  quantity_planned,
  COUNT(*) AS duplicate_count,
  GROUP_CONCAT(id ORDER BY id) AS row_ids,
  GROUP_CONCAT(COALESCE(monday_item_id, '') ORDER BY id) AS monday_item_ids,
  MIN(created_at) AS first_created,
  MAX(created_at) AS last_created
FROM ops_packing_tasks
GROUP BY item_name, received_weight, quantity_planned
HAVING COUNT(*) > 1
ORDER BY duplicate_count DESC, last_created DESC;

