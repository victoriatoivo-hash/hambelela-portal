<?php
declare(strict_types=1);

// Read-only category choices: existing workbook labels plus the successful website catalogue.
function cw_supplier_category_choices(?PDO $pdo=null): array
{
    $values=['Plant Butters','Carrier Oils','Essential Oils','Hydrosols','Dried Herbs','Powders','Clays','Active Ingredients','Preservatives','Emulsifiers','Surfactants','Proteins','Solvents','Waxes','Packaging','Equipment','Accessories','Other'];
    if ($pdo) {
        $batch=(int)$pdo->query("SELECT id FROM cw_sync_batches WHERE is_successful_snapshot=1 AND status='completed' ORDER BY completed_at DESC,id DESC LIMIT 1")->fetchColumn();
        if ($batch) {
            $query=$pdo->prepare("SELECT DISTINCT category FROM cw_product_snapshots WHERE sync_batch_id=? AND category IS NOT NULL AND category<>''");
            $query->execute([$batch]);
            // Matches the existing website category filter's snapshot format.
            foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $stored) {
                foreach (preg_split('/\s*,\s*/',(string)$stored) as $category) $values[]=trim(html_entity_decode($category,ENT_QUOTES|ENT_HTML5,'UTF-8'));
            }
        }
        foreach (['SELECT DISTINCT product_category FROM cw_cost_products', 'SELECT DISTINCT product_category FROM cw_supplier_invoice_lines'] as $sql) {
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $category) $values[]=trim((string)$category);
        }
    }
    $values=array_values(array_unique(array_filter($values,static function($value){return $value!=='';})));
    natcasesort($values);
    return array_values($values);
}
