<?php

declare(strict_types=1);

function cw_history_require_read_only_request(): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        return;
    }

    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => false,
        'error' => 'historical_records_read_only',
        'message' => 'Historical Cost Records are read-only.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

function cw_history_redirect(): void
{
    header('Location: ' . BASE_URL . '/apps/cost-manager/historical-cost-records.php', true, 302);
    exit;
}

function cw_history_fetch(PDO $pdo, array $datasets, string $requestedDataset, string $search, string $requestedDirection, int $requestedPage, int $perPage = 50): array
{
    $dataset = isset($datasets[$requestedDataset]) ? $requestedDataset : array_key_first($datasets);
    if (!is_string($dataset) || $dataset === '') throw new RuntimeException('No historical datasets are configured.');
    $search = substr(trim($search), 0, 100);
    $direction = strtolower($requestedDirection) === 'asc' ? 'ASC' : 'DESC';
    $page = max(1, min(1000000, $requestedPage));
    $tableCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $tableCheck->execute([$dataset]);
    if ((int) $tableCheck->fetchColumn() !== 1) return compact('dataset', 'search', 'direction', 'page') + ['rows'=>[], 'columns'=>[], 'total'=>0];
    $schemaRows = $pdo->query('SHOW COLUMNS FROM `' . $dataset . '`')->fetchAll(PDO::FETCH_ASSOC);
    $actualColumns = array_column($schemaRows, 'Field');
    $columns = array_values(array_filter($actualColumns, static fn(string $column): bool => !in_array(strtolower($column), ['pdf_path','file_path','stored_file','consumer_key','consumer_secret','access_token','refresh_token'], true) && !preg_match('/password|secret|token|credential/', strtolower($column))));
    $searchColumns = array_values(array_intersect($datasets[$dataset]['search'], $actualColumns));
    $where = ''; $params = [];
    if ($search !== '' && $searchColumns) {$where=' WHERE '.implode(' OR ',array_map(static fn(string $column):string=>'`'.$column.'` LIKE ?',$searchColumns));$params=array_fill(0,count($searchColumns),'%'.$search.'%');}
    $count=$pdo->prepare('SELECT COUNT(*) FROM `'.$dataset.'`'.$where);$count->execute($params);$total=(int)$count->fetchColumn();
    $page=min($page,max(1,(int)ceil($total/$perPage)));$offset=($page-1)*$perPage;
    $statement=$pdo->prepare('SELECT * FROM `'.$dataset.'`'.$where.' ORDER BY `'.$datasets[$dataset]['order'].'` '.$direction.' LIMIT '.$perPage.' OFFSET '.$offset);$statement->execute($params);
    return compact('dataset','search','direction','page','columns','total')+['rows'=>$statement->fetchAll(PDO::FETCH_ASSOC)];
}
