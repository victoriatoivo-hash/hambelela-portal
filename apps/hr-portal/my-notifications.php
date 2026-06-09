<?php
require_once __DIR__ . '/config.php';
requireLogin();
$user = currentUser();
$db   = db();

// Mark all as read when visiting page
$db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$user['id']]);

$notifs = $db->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
$notifs->execute([$user['id']]); $notifs = $notifs->fetchAll();

$currentPage = 'my-notifications.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications — Hambelela HR</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="includes/styles.css">
</head>
<body>
<?php if ($user['role']==='employee'): ?>
  <?php include __DIR__ . '/includes/emp-sidebar.php'; ?>
<?php else: ?>
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
<?php endif ?>

<div class="main">
  <div class="topbar"><div class="topbar-title">Notifications</div></div>
  <div class="content">
    <div class="section-title" style="margin-bottom:20px">All Notifications</div>

    <?php if (empty($notifs)): ?>
      <div class="empty-state"><i class="fa-regular fa-bell"></i><div>No notifications yet.</div></div>
    <?php else: ?>
    <div class="card" style="overflow:visible">
      <?php foreach ($notifs as $n):
        $icons = ['info'=>'fa-solid fa-circle-info','success'=>'fa-solid fa-circle-check','warning'=>'fa-solid fa-triangle-exclamation','error'=>'fa-solid fa-circle-xmark'];
        $colors = ['info'=>'var(--blue)','success'=>'var(--green)','warning'=>'var(--amber)','error'=>'var(--red)'];
        $icon = $icons[$n['type']] ?? $icons['info'];
        $color = $colors[$n['type']] ?? $colors['info'];
        $ago = '';
        $diff = time() - strtotime($n['created_at']);
        if ($diff < 60) $ago = 'Just now';
        elseif ($diff < 3600) $ago = floor($diff/60).' min ago';
        elseif ($diff < 86400) $ago = floor($diff/3600).' hr ago';
        else $ago = date('d M Y', strtotime($n['created_at']));
      ?>
      <div style="display:flex;align-items:flex-start;gap:14px;padding:14px 20px;border-bottom:1px solid var(--border)">
        <i class="<?=$icon?>" style="font-size:18px;color:<?=$color?>;margin-top:2px;flex-shrink:0"></i>
        <div style="flex:1">
          <div style="font-weight:600;font-size:13px;margin-bottom:2px"><?=htmlspecialchars($n['title'])?></div>
          <div style="font-size:13px;color:var(--text-mid)"><?=htmlspecialchars($n['message'])?></div>
        </div>
        <div style="font-size:11px;color:var(--text-light);white-space:nowrap;flex-shrink:0"><?=$ago?></div>
      </div>
      <?php endforeach ?>
    </div>
    <?php endif ?>
  </div>
</div>
</body>
</html>
