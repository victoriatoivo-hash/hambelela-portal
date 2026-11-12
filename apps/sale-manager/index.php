<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/auth.php';
require_login();
require_role('owner_admin');
require_once BASE_PATH.'/shared/woocommerce.php';
header('Cache-Control: private, no-store');
if (empty($_SESSION['sale_manager_csrf'])) $_SESSION['sale_manager_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['sale_manager_csrf'];
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        switch ($_GET['action']) {
            case 'catalog':
                $result = wc_get('hambelela-sales/catalog',['after'=>max(0,(int)($_GET['after'] ?? 0))],35);
                break;
            case 'list': $result = wc_get('hambelela-sales/campaigns'); break;
            case 'save':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($csrf,(string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
                    http_response_code(403); throw new RuntimeException('Refresh the page before saving.');
                }
                $input = json_decode(file_get_contents('php://input'),true);
                if (!is_array($input) || !preg_match('/^[0-9a-f]{32}$/D',$input['id'] ?? '')) throw new RuntimeException('Invalid campaign.');
                $result = wc_put('hambelela-sales/campaigns/'.$input['id'],$input);
                break;
            default: throw new RuntimeException('Unknown action.');
        }
        echo json_encode($result);
    } catch (Throwable $e) {
        if (http_response_code()<400) http_response_code(400);
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf" content="<?=htmlspecialchars($csrf,ENT_QUOTES)?>"><title>Sale Manager · Hambelela Organic</title><link rel="stylesheet" href="manager.css"></head>
<body><main><a class="back" href="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/">← Business portal</a><header><h1>Sale Manager</h1><button id="new" type="button">New sale</button></header>
<p id="message" role="status" aria-live="polite">Connecting to the shop…</p><div class="layout"><aside><h2>Campaigns</h2><div id="campaigns"></div></aside>
<form id="editor"><fieldset id="fields" disabled><legend>Campaign details</legend>
<label>Sale name<input id="name" required maxlength="90" placeholder="Pre-Black Friday"></label>
<div class="pair"><label>Starts · Windhoek<input id="start" type="datetime-local"></label><label>Ends · Windhoek<input id="end" type="datetime-local"></label></div>
<small>Leave the start blank to begin at publication. Leave the end blank to run until paused.</small>
<div class="pair"><label>Offer<select id="kind"><option value="percent">Percentage off regular price</option><option value="fixed">Fixed sale price</option><option value="bundle">Buy a quantity for one price</option></select></label><label><span id="unit">Percentage off</span><input id="amount" required type="number" min=".01" step=".01"></label><label id="bundle" hidden>Units per bundle<input id="quantity" type="number" min="2" max="999" value="2"></label></div>
<label class="check"><input type="checkbox" id="stack"> Allow coupons on orders using this campaign</label>
<h2>Products and sizes</h2><label>Apply sale to<select id="scope"><option value="selected">Selected products or sizes</option><option value="category">Categories with exceptions</option><option value="all">Whole store with exceptions</option></select></label>
<div id="categoryBox" hidden><label>Categories<select id="categories" multiple size="6"></select></label></div>
<label>Find products or sizes<input id="search" type="search" placeholder="Search by name or product ID"></label>
<div id="choices"></div><div id="picks"></div><h2>Price preview</h2><p id="summary"></p><div class="scroll"><table><thead><tr><th>Product or size</th><th>Current</th><th>With this offer</th></tr></thead><tbody id="preview"></tbody></table></div>
<footer><button type="submit" id="draft" value="draft">Save draft</button><button type="submit" id="publish" value="publish">Publish sale</button></footer>
</fieldset></form></div></main><script src="manager.js?v=1.1.0" defer></script></body></html>
