<?php
declare(strict_types=1);
if (!defined('BASE_PATH') || !isset($myWork)) { http_response_code(403); exit; }
$agenda=array_values(array_filter($myWork,fn($i)=>marketing_work_matches_app($i,'calendar')));
?>
<section class="marketing-board"><header><div><p>CONTENT CALENDAR</p><h2>Production and publishing agenda</h2></div><span><?=count($agenda)?> records</span></header><div class="marketing-agenda">
<?php foreach($agenda as$i):$date=$i['publish_at']?:$i['due_at'];?><article><time><?=marketing_e($date?date('D, d M',strtotime($date)):'Unscheduled')?><small><?=marketing_e($date?date('H:i',strtotime($date)):'')?></small></time><div><strong><?=marketing_e($i['title'])?></strong><span><?=marketing_e($i['platform']?:str_replace('_',' ',$i['content_type']))?></span></div><span class="marketing-status <?=marketing_e($i['status'])?>"><?=marketing_e(str_replace('_',' ',$i['status']))?></span><a class="marketing-link" href="execution.php?id=<?=(int)$i['id']?>">Open</a></article><?php endforeach;?><?php if(!$agenda):?><p class="marketing-empty">No scheduled content yet.</p><?php endif;?></div></section>
