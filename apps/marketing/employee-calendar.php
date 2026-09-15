<?php
declare(strict_types=1);
if (!defined('BASE_PATH') || !isset($myWork)) { http_response_code(403); exit; }
$month=(string)($_GET['month']??date('Y-m'));
if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month))$month=date('Y-m');
$first=new DateTimeImmutable($month.'-01');$start=$first->modify('-'.((int)$first->format('N')-1).' days');
$events=[];foreach($myWork as$work){if(!marketing_work_matches_app($work,'calendar'))continue;$day=substr((string)($work['publish_at']?:$work['due_at']),0,10);if($day)$events[$day][]=$work;}
?>
<section class="marketing-panel" aria-label="My Marketing calendar"><nav class="marketing-calendar-navigation" aria-label="Calendar month"><a class="marketing-btn-secondary" href="?view=calendar&amp;month=<?=$first->modify('-1 month')->format('Y-m')?>" aria-label="Previous month">Previous</a><strong><?=$first->format('F Y')?></strong><a class="marketing-btn-secondary" href="?view=calendar&amp;month=<?=$first->modify('+1 month')->format('Y-m')?>" aria-label="Next month">Next</a></nav>
<div class="marketing-month-grid"><?php foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun']as$day):?><div class="marketing-weekday"><?=$day?></div><?php endforeach;?>
<?php for($offset=0;$offset<42;$offset++):$day=$start->modify('+'.$offset.' days');$key=$day->format('Y-m-d');?><div class="marketing-calendar-day<?=$day->format('Y-m')!==$month?' is-outside':''?>"><time datetime="<?=$key?>"><?=$day->format('j')?></time><?php foreach($events[$key]??[]as$work):?><a class="marketing-calendar-event" title="<?=marketing_e($work['title'])?>" href="execution.php?id=<?=(int)$work['id']?>"><?=marketing_e($work['title'])?></a><?php endforeach;?></div><?php endfor;?></div>
</section>
