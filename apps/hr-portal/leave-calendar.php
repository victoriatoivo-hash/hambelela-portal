<?php
require_once __DIR__ . '/config.php';
requireAdmin();
$user = currentUser();
$db   = db();
$year  = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
if ($month<1){$month=12;$year--;}if($month>12){$month=1;$year++;}
$monthName=date('F',mktime(0,0,0,$month,1,$year));
$daysInMonth=(int)date('t',mktime(0,0,0,$month,1,$year));
$firstDay=(int)date('N',mktime(0,0,0,$month,1,$year));
$holidays=[];
$hq=$db->prepare("SELECT hdate,hname FROM public_holidays WHERE year=? AND MONTH(hdate)=?");
$hq->execute([$year,$month]);
foreach($hq->fetchAll() as $h)$holidays[(int)date('j',strtotime($h['hdate']))]=$h['hname'];
$leave=[];
$firstDate=sprintf('%04d-%02d-01',$year,$month);
$lastDate=sprintf('%04d-%02d-%02d',$year,$month,$daysInMonth);
$lq=$db->prepare("SELECT lr.start_date,lr.end_date,lr.leave_type,CONCAT(e.first_name,' ',e.last_name) as name FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id WHERE lr.status='approved' AND lr.start_date<=? AND lr.end_date>=?");
$lq->execute([$lastDate,$firstDate]);
foreach($lq->fetchAll() as $l){
  $s=max((int)date('j',strtotime($l['start_date'])),1);
  $e=min((int)date('j',strtotime($l['end_date'])),$daysInMonth);
  if(date('Y-m',strtotime($l['start_date']))<sprintf('%04d-%02d',$year,$month))$s=1;
  if(date('Y-m',strtotime($l['end_date']))>sprintf('%04d-%02d',$year,$month))$e=$daysInMonth;
  for($d=$s;$d<=$e;$d++)$leave[$d][]=['name'=>$l['name'],'type'=>$l['leave_type']];
}
$prevM=$month-1;$prevY=$year;if($prevM<1){$prevM=12;$prevY--;}
$nextM=$month+1;$nextY=$year;if($nextM>12){$nextM=1;$nextY++;}
$currentPage='leave-calendar.php';
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Leave Calendar — Hambelela HR</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="includes/styles.css">
<style>
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:var(--border);border:1px solid var(--border);border-radius:10px;overflow:hidden}
.cal-head{background:var(--green);color:#fff;padding:10px;text-align:center;font-size:12px;font-weight:700;text-transform:uppercase}
.cal-day{background:#fff;min-height:90px;padding:6px 8px;cursor:pointer;transition:background .15s}
.cal-day:hover{background:#f0f9f4}
.cal-day.empty{background:#fafafa;cursor:default}
.cal-day.today{box-shadow:inset 0 0 0 2px var(--green)}
.cal-day.sunday{background:#fef2f2}
.cal-num{font-size:13px;font-weight:700}
.cal-num.sun{color:var(--red)}
.cal-hol{display:block;font-size:9px;background:var(--red);color:#fff;padding:1px 4px;border-radius:3px;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cal-lv{display:block;font-size:9px;background:var(--green);color:#fff;padding:1px 4px;border-radius:3px;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cal-lv.sick{background:var(--amber)}.cal-lv.comp{background:var(--blue)}.cal-lv.mat{background:#0d9488}
.cal-nav{display:flex;align-items:center;gap:16px}
.cal-nav a{text-decoration:none;color:var(--green);font-size:20px;padding:4px 10px;border-radius:6px}
.cal-nav a:hover{background:var(--green-pale)}
.legend{display:flex;gap:16px;flex-wrap:wrap;margin-top:16px;font-size:12px}
.legend span{display:flex;align-items:center;gap:4px}
.legend i{width:12px;height:12px;border-radius:3px;display:inline-block}
.popup-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.3);z-index:200;align-items:center;justify-content:center}
.popup-bg.open{display:flex}
.popup{background:#fff;border-radius:12px;padding:24px;max-width:380px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.2);position:relative}
.popup h3{margin:0 0 12px}
.popup-item{padding:8px 0;border-bottom:1px solid var(--border);font-size:13px}
.popup-item:last-child{border-bottom:none}
</style></head><body>
<?php include __DIR__.'/includes/sidebar.php'; ?>
<div class="main">
<div class="topbar"><div class="topbar-title">Leave Calendar</div>
<div class="cal-nav">
<a href="?year=<?=$prevY?>&month=<?=$prevM?>"><i class="fa-solid fa-chevron-left"></i></a>
<span style="font-size:18px;font-weight:800;min-width:180px;text-align:center"><?=$monthName?> <?=$year?></span>
<a href="?year=<?=$nextY?>&month=<?=$nextM?>"><i class="fa-solid fa-chevron-right"></i></a>
</div></div>
<div class="content">
<div class="cal-grid">
<div class="cal-head">Mon</div><div class="cal-head">Tue</div><div class="cal-head">Wed</div><div class="cal-head">Thu</div><div class="cal-head">Fri</div><div class="cal-head">Sat</div><div class="cal-head" style="background:var(--red)">Sun</div>
<?php for($i=1;$i<$firstDay;$i++) echo '<div class="cal-day empty"></div>';
$td=(int)date('j');$tm=(int)date('n');$ty=(int)date('Y');
for($d=1;$d<=$daysInMonth;$d++):
$dow=(int)date('N',mktime(0,0,0,$month,$d,$year));
$cls='cal-day';
if($d===$td&&$month===$tm&&$year===$ty)$cls.=' today';
if($dow===7)$cls.=' sunday';
?><div class="<?=$cls?>" onclick="showDay(<?=$d?>)">
<div class="cal-num <?=$dow===7?'sun':''?>"><?=$d?></div>
<?php if(isset($holidays[$d])):?><span class="cal-hol"><?=htmlspecialchars($holidays[$d])?></span><?php endif?>
<?php if(isset($leave[$d])):foreach($leave[$d] as $lv):
$lc='cal-lv';if(stripos($lv['type'],'Sick')!==false)$lc.=' sick';
elseif(stripos($lv['type'],'Compassionate')!==false)$lc.=' comp';
elseif(stripos($lv['type'],'Maternity')!==false)$lc.=' mat';
?><span class="<?=$lc?>"><?=htmlspecialchars($lv['name'])?></span>
<?php endforeach;endif?></div>
<?php endfor;
$last=(int)date('N',mktime(0,0,0,$month,$daysInMonth,$year));
for($i=$last+1;$i<=7;$i++)echo'<div class="cal-day empty"></div>';?>
</div>
<div class="legend">
<span><i style="background:var(--green)"></i> Annual Leave</span>
<span><i style="background:var(--amber)"></i> Sick Leave</span>
<span><i style="background:var(--blue)"></i> Compassionate</span>
<span><i style="background:#0d9488"></i> Maternity</span>
<span><i style="background:var(--red)"></i> Public Holiday</span>
</div>
</div></div>
<div class="popup-bg" id="dayPopup" onclick="if(event.target===this)this.style.display='none'">
<div class="popup">
<button onclick="document.getElementById('dayPopup').style.display='none'" style="position:absolute;top:12px;right:12px;background:none;border:none;font-size:18px;cursor:pointer">&times;</button>
<h3 id="popupTitle"></h3><div id="popupContent"></div>
</div></div>
<script>
var hols=<?=json_encode($holidays)?>,lvs=<?=json_encode($leave)?>,mn="<?=$monthName?>",yr=<?=$year?>;
function showDay(d){
var h="";
if(hols[d])h+="<div class='popup-item'><i class='fa-solid fa-flag' style='color:var(--red);margin-right:6px'></i><strong>"+hols[d]+"</strong> <span style='font-size:11px;color:#888'>Public Holiday</span></div>";
if(lvs[d])for(var i=0;i<lvs[d].length;i++){var l=lvs[d][i],c="var(--green)";if(l.type.indexOf("Sick")>=0)c="var(--amber)";else if(l.type.indexOf("Compassionate")>=0)c="var(--blue)";h+="<div class='popup-item'><i class='fa-solid fa-user' style='color:"+c+";margin-right:6px'></i><strong>"+l.name+"</strong> — "+l.type+"</div>";}
if(!h)h="<div class='popup-item' style='color:#888'>No events on this date.</div>";
document.getElementById("popupTitle").textContent=d+" "+mn+" "+yr;
document.getElementById("popupContent").innerHTML=h;
document.getElementById("dayPopup").style.display="flex";
}
</script></body></html>