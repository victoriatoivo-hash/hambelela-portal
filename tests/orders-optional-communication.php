<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/shared/epi/OrderCommunicationCapture.php';
// No database connection: any attempted read/write fails. Completion must not
// depend on communication evidence or create fictional evidence as a workaround.
final class NoCommunicationDatabase extends PDO { public function __construct() {} }
$db=new NoCommunicationDatabase();
foreach([[],[10901],[10902],[10903],[10901,10902,10903]] as $ids) {
    \Hambelela\EPI\OrderCommunicationCapture::requireCompletion($db,$ids);
}
ob_start();require dirname(__DIR__).'/shared/front-coverage-prompt.php';$output=ob_get_clean();
if(trim($output)!=='')throw new RuntimeException('Coverage launcher must not render');
echo "PASS: single/bulk completion needs no communication query or evidence; coverage loader renders nothing\n";
