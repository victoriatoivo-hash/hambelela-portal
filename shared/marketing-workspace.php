<?php
declare(strict_types=1);
function marketing_employee_application_cards(array $apps,array $badges): void {
    $descriptions=['tasks'=>'Open assigned content, start work and submit for review.','calendar'=>'View your assigned deadlines and publication schedule.','social'=>'Open your assigned social media content.','reels'=>'Work on assigned reels and videos.','whatsapp'=>'Prepare your assigned WhatsApp content.','blog'=>'Open assigned blog and SEO work.','newsletter'=>'Work on assigned newsletters.','website'=>'Prepare your assigned website and product changes.','media'=>'Access images, videos, audio and shared media.','library'=>'View completed content and its saved files.'];
    echo '<div class="mk-home-apps">';
    foreach($apps as $key=>$app){
        if(!marketing_employee_view_allowed($key))continue;
        $href=$key==='media'?'media.php':'?view='.rawurlencode($key);
        echo '<a class="mk-home-app" href="'.marketing_e($href).'"><span class="mk-home-app-icon"><i data-lucide="'.marketing_e($app[1]).'"></i></span><h2 class="mk-home-app-title">'.marketing_e($key==='media'?'Media Library':$app[0]).'</h2><p class="mk-home-app-description">'.marketing_e($descriptions[$key]??'Open your authorised Marketing work.').'</p><span class="mk-home-app-action">Open application <i data-lucide="arrow-right"></i></span></a>';
    }
    echo '</div>';
}

/** Additive extensions of the existing Marketing records and version storage. */
function marketing_workspace_schema(): void {
    foreach (['deleted_at'=>'DATETIME NULL','channel_key'=>'VARCHAR(50) NULL'] as $column=>$type) {
        if (!marketing_column_exists('marketing_item_versions',$column)) db()->exec("ALTER TABLE marketing_item_versions ADD COLUMN $column $type");
    }
    db()->exec("CREATE TABLE IF NOT EXISTS marketing_media_links(version_id BIGINT UNSIGNED NOT NULL,item_id BIGINT UNSIGNED NOT NULL,channel_key VARCHAR(50) NOT NULL DEFAULT '',linked_by INT NOT NULL,linked_at DATETIME NOT NULL,PRIMARY KEY(version_id,item_id,channel_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS marketing_notes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id BIGINT UNSIGNED NOT NULL,actor_id INT NOT NULL,actor_name VARCHAR(190) NOT NULL,is_owner TINYINT NOT NULL,body TEXT NOT NULL,request_key VARCHAR(64) NOT NULL,created_at DATETIME NOT NULL,UNIQUE KEY note_retry(item_id,actor_id,request_key),KEY note_thread(item_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function marketing_now(): string { return (new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s'); }
/** Marketing DATETIME values are Namibia wall time, never browser-local or UTC. */
function marketing_local_datetime(?string $value): ?string {
    $value=trim((string)$value);if($value==='')return null;
    $normal=str_replace('T',' ',$value);
    if(strlen($normal)===16)$normal.=':00';
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$normal,new DateTimeZone('Africa/Windhoek'));
    if(!$date||$date->format('Y-m-d H:i:s')!==$normal)throw new RuntimeException('Enter a valid date and time in Namibia time (Africa/Windhoek).');
    return $normal;
}
function marketing_time_iso(?string $value): string {
    if (!$value) return '';
    try { return (new DateTimeImmutable($value,new DateTimeZone('Africa/Windhoek')))->setTimezone(new DateTimeZone('Africa/Windhoek'))->format(DateTimeInterface::ATOM); } catch(Throwable $e) { return ''; }
}
function marketing_display_time(?string $value): string {
    if (!$value) return 'Not set';
    try { return (new DateTimeImmutable(marketing_time_iso($value)))->format('d M Y · H:i'); } catch(Throwable $e) { return 'Not set'; }
}
function marketing_activity(array $item,string $message): void {
    db()->prepare('INSERT INTO marketing_item_history(item_id,old_status,new_status,note,actor_id,actor_name)VALUES(?,?,?,?,?,?)')->execute([$item['id'],$item['status'],$item['status'],$message,marketing_employee_id(),(string)(current_user()['name']??'')]);
}
function marketing_note_send(int $id,string $body,string $key): int {
    $body=trim($body);
    if($body===''||strlen($body)>10000)throw new RuntimeException('Write a note of up to 10,000 characters.');
    if(!preg_match('/^[a-f0-9]{32,64}$/D',$key))throw new RuntimeException('Refresh the conversation and try again.');
    db()->beginTransaction();
    try {
        $item=marketing_execution_item($id,true);
        $noteInsert=db()->prepare('INSERT IGNORE INTO marketing_notes(item_id,actor_id,actor_name,is_owner,body,request_key,created_at)VALUES(?,?,?,?,?,?,?)');$noteInsert->execute([$id,marketing_employee_id(),(string)(current_user()['name']??''),(int)marketing_is_owner(),$body,$key,marketing_now()]);
        $noteId=$noteInsert->rowCount()?(int)db()->lastInsertId():0;$activityId=0;
        if($noteId){
            marketing_activity($item,marketing_is_owner()?'Owner replied in Notes to Owner.':'Employee sent a note to Owner.');$activityId=(int)db()->lastInsertId();
            require_once BASE_PATH.'/shared/notifications.php';
            $recipients=marketing_is_owner()?array_filter([(int)$item['assigned_employee_id']]):notifications_role_recipients(['owner_admin']);
            if($recipients&&!notifications_create(['title'=>marketing_is_owner()?'Owner replied to your Marketing note':'New Marketing note','message'=>$item['title'],'module'=>'marketing','related_type'=>'marketing_note','related_id'=>$id,'action_link'=>BASE_URL.'/apps/marketing/execution.php?id='.$id.'#notes','required_delivery'=>1,'deduplication_key'=>'marketing-note:'.$noteId],array_values($recipients)))throw new RuntimeException('The notification could not be saved. Please try again.');
        }
        db()->commit();if($noteId&&strpos($body,'[Assistance request]')===0)marketing_work_evidence($item,$activityId,'marketing_assistance_requested',['message_id'=>$noteId,'notification_persisted'=>true]);return $noteId;
    } catch(Throwable $e) { if(db()->inTransaction())db()->rollBack();throw $e; }
}
function marketing_media_scope(array &$params,string $alias='v'): string {
    if(marketing_is_owner())return '1=1';
    $params[]=marketing_employee_id();$params[]=marketing_employee_id();$params[]=marketing_employee_id();
    return "(($alias.item_id=0 AND $alias.uploaded_by=?) OR EXISTS(SELECT 1 FROM marketing_work_items mw WHERE mw.id=$alias.item_id AND mw.assigned_employee_id=? AND mw.cancelled_at IS NULL) OR EXISTS(SELECT 1 FROM marketing_media_links ml JOIN marketing_work_items mw ON mw.id=ml.item_id WHERE ml.version_id=$alias.id AND mw.assigned_employee_id=? AND mw.cancelled_at IS NULL))";
}
function marketing_media_get(int $id): array {
    $params=[$id];$scope=marketing_media_scope($params);
    $q=db()->prepare("SELECT v.* FROM marketing_item_versions v WHERE v.id=? AND v.deleted_at IS NULL AND $scope");$q->execute($params);$file=$q->fetch();
    if(!$file)throw new RuntimeException('Media not found.');return $file;
}
function marketing_media_type(string $mime): string {
    foreach(['image/'=>'images','video/'=>'videos','audio/'=>'audio']as$prefix=>$type)if(strpos($mime,$prefix)===0)return $type;
    return 'documents';
}
function marketing_media_validate(string $path,string $name,int $size): string {
    if($size<1||$size>30*1024*1024)throw new RuntimeException('Files must be between 1 byte and 30 MB.');
    $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    $allowed=['jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'webp'=>['image/webp'],'gif'=>['image/gif'],'mp4'=>['video/mp4'],'webm'=>['video/webm','audio/webm'],'mp3'=>['audio/mpeg'],'m4a'=>['audio/mp4','video/mp4','audio/x-m4a'],'wav'=>['audio/wav','audio/x-wav','audio/vnd.wave'],'pdf'=>['application/pdf'],'txt'=>['text/plain']];
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    if(in_array($extension,['docx','xlsx','pptx'],true)){
        if(!class_exists('ZipArchive'))throw new RuntimeException('Office uploads are unavailable on this server. Use PDF instead.');
        $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('Invalid Office document.');
        $root=['docx'=>'word/document.xml','xlsx'=>'xl/workbook.xml','pptx'=>'ppt/presentation.xml'][$extension];
        $valid=$zip->locateName('[Content_Types].xml')!==false&&$zip->locateName($root)!==false&&$zip->numFiles<10000;$bytes=0;
        for($i=0;$i<$zip->numFiles&&$valid;$i++){$entry=$zip->statIndex($i);$bytes+=(int)$entry['size'];if($bytes>100*1024*1024||preg_match('/(vbaProject|\.exe$|\.php$|\.js$)/i',$entry['name']))$valid=false;}
        $zip->close();if(!$valid)throw new RuntimeException('Use an Office document without macros or executable attachments.');
        return ['docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation'][$extension];
    }

    if(!isset($allowed[$extension])||!in_array($mime,$allowed[$extension],true))throw new RuntimeException('Use JPG, PNG, WebP, GIF, MP4, WebM, MP3, M4A, WAV, PDF or plain text. The file contents must match the format.');
    if(strpos($mime,'image/')===0&&!@getimagesize($path))throw new RuntimeException('This image is not valid.');
    return $extension==='m4a'?'audio/mp4':$mime;
}
function marketing_media_channel(array $item,string $key): void {
    if($key!==''&&!in_array($key,array_column(marketing_execution_rows($item),'channel_key'),true))throw new RuntimeException('Choose a required channel for this assignment.');
}
function marketing_media_upload(array $file,int $itemId=0,string $channel=''): int {
    $item=$itemId?marketing_execution_item($itemId):null;
    if($item)marketing_media_channel($item,$channel);elseif($channel!=='')throw new RuntimeException('Choose an assignment first.');
    if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))throw new RuntimeException('Choose a file within the server upload limit.');
    $name=basename(str_replace('\\','/',(string)$file['name']));$size=(int)filesize($file['tmp_name']);$mime=marketing_media_validate($file['tmp_name'],$name,$size);
    $dir=BASE_PATH.'/uploads/marketing';if(!is_dir($dir)&&!mkdir($dir,0750,true))throw new RuntimeException('The upload folder is unavailable.');
    $stored=bin2hex(random_bytes(24)).'.bin';
    if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$stored))throw new RuntimeException('Upload failed.');
    db()->beginTransaction();
    try {
        if($item)$item=marketing_execution_item($itemId,true);
        $count=db()->prepare('SELECT COUNT(*) FROM marketing_item_versions WHERE item_id=?');$count->execute([$itemId]);$n=(int)$count->fetchColumn()+1;
        db()->prepare('INSERT INTO marketing_item_versions(item_id,version_label,file_name,stored_name,mime_type,file_size,uploaded_by,uploaded_by_name,channel_key)VALUES(?,?,?,?,?,?,?,?,?)')->execute([$itemId,$item?($n===1&&marketing_is_owner()?'Original':'Draft '.$n):'Media',$name,$stored,$mime,$size,marketing_employee_id(),(string)(current_user()['name']??''),$channel?:null]);
        $id=(int)db()->lastInsertId();if($item)marketing_activity($item,'Uploaded '.$name.($channel?' for '.$channel:''));db()->commit();return $id;
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();@unlink($dir.'/'.$stored);throw $e;}
}
function marketing_media_attach(int $fileId,int $itemId,string $channel=''): void {
    db()->beginTransaction();try{
        $file=marketing_media_get($fileId);$item=marketing_execution_item($itemId,true);marketing_media_channel($item,$channel);
        if((int)$file['item_id']===$itemId&&($file['channel_key']??'')===$channel){db()->commit();return;}
        $q=db()->prepare('INSERT IGNORE INTO marketing_media_links(version_id,item_id,channel_key,linked_by,linked_at)VALUES(?,?,?,?,?)');$q->execute([$fileId,$itemId,$channel,marketing_employee_id(),marketing_now()]);
        if($q->rowCount())marketing_activity($item,'Attached '.$file['file_name'].($channel?' to '.$channel:''));db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function marketing_media_delete(int $id): void {
    marketing_require_owner();$file=marketing_media_get($id);
    if(marketing_column_exists('marketing_work_items','review_version_ids')) {
        $q=db()->prepare("SELECT review_version_ids FROM marketing_work_items WHERE review_version_ids IS NOT NULL AND (id=? OR id IN (SELECT item_id FROM marketing_media_links WHERE version_id=?))");
        $q->execute([(int)$file['item_id'],$id]);
        foreach($q->fetchAll() as $submission)if(in_array($id,array_map('intval',json_decode($submission['review_version_ids'],true)?:[]),true))throw new RuntimeException('This version is preserved as submitted review evidence. Upload a new version instead.');
    }
    // Reversible library removal: bytes, version history and assignment evidence are retained.
    db()->prepare('UPDATE marketing_item_versions SET deleted_at=? WHERE id=?')->execute([marketing_now(),$id]);
}
function marketing_assignment_media(int $id): array {
    marketing_workspace_item($id);
    $q=db()->prepare("SELECT v.* FROM marketing_item_versions v WHERE v.deleted_at IS NULL AND (v.item_id=? OR EXISTS(SELECT 1 FROM marketing_media_links ml WHERE ml.version_id=v.id AND ml.item_id=?)) ORDER BY v.id DESC");$q->execute([$id,$id]);$files=$q->fetchAll();
    $links=db()->prepare('SELECT version_id,channel_key FROM marketing_media_links WHERE item_id=?');$links->execute([$id]);$channels=[];
    foreach($links->fetchAll() as $link)$channels[(int)$link['version_id']][]=$link['channel_key'];
    foreach($files as &$file){$keys=$channels[(int)$file['id']]??[];if((int)$file['item_id']===$id)$keys[]=$file['channel_key']??'';$file['linked_channels']=array_values(array_unique($keys));}unset($file);
    return $files;
}
function marketing_media_player(array $file,bool $large=false): void {
    $url=BASE_URL.'/apps/marketing/file.php?id='.(int)$file['id'].'&inline=1';$type=marketing_media_type($file['mime_type']);
    if($type==='images')echo '<img loading="lazy" src="'.marketing_e($url).'" alt="'.marketing_e($file['file_name']).'">';
    elseif($type==='videos')echo '<video controls preload="metadata" playsinline src="'.marketing_e($url).'" aria-label="'.marketing_e($file['file_name']).'"></video><span class="mk-duration" data-media-duration>Video · duration loads with preview</span>';
    elseif($type==='audio')echo '<audio controls preload="metadata" src="'.marketing_e($url).'" aria-label="'.marketing_e($file['file_name']).'"></audio><span class="mk-duration" data-media-duration>Audio · duration loads with preview</span>';
    else echo '<span class="mk-document-icon">Document</span>';
}
function marketing_workspace_assets(): void {
    echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600&display=swap">';
    echo '<link rel="stylesheet" href="'.BASE_URL.'/assets/css/marketing-workspace.css?v='.filemtime(BASE_PATH.'/assets/css/marketing-workspace.css').'">';
    echo '<script defer src="'.BASE_URL.'/assets/js/marketing-workspace.js?v='.filemtime(BASE_PATH.'/assets/js/marketing-workspace.js').'"></script>';
}
function marketing_assignment_grid(array $items,string $active='active'): void {marketing_content_table($items,$active);}

function marketing_workspace_item(int $id): array {
    $params=[$id];$scope='';if(!marketing_is_owner()){$scope=' AND assigned_employee_id=?';$params[]=marketing_employee_id();}
    $q=db()->prepare('SELECT *,UNIX_TIMESTAMP(created_at) created_epoch FROM marketing_work_items WHERE id=?'.$scope);$q->execute($params);$item=$q->fetch();if(!$item)throw new RuntimeException('Assigned work not found.');return $item;
}
function marketing_safe_brief(string $text): string {
    if($text===strip_tags($text))return nl2br(marketing_e($text));
    $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="UTF-8"><div>'.$text.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);libxml_clear_errors();libxml_use_internal_errors($previous);
    $clean=function($node)use(&$clean):string{
        if($node instanceof DOMText)return marketing_e($node->textContent);
        if(!($node instanceof DOMElement))return '';
        $tag=strtolower($node->tagName);if(in_array($tag,['script','style','iframe','object','embed','svg','math'],true))return '';
        $body='';foreach($node->childNodes as$child)$body.=$clean($child);
        if($tag==='a'){$href=trim($node->getAttribute('href'));return filter_var($href,FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($href,PHP_URL_SCHEME)),['http','https'],true)&&!parse_url($href,PHP_URL_USER)?'<a href="'.marketing_e($href).'" target="_blank" rel="noopener noreferrer">'.$body.'</a>':$body;}
        if(!in_array($tag,['div','p','br','strong','b','em','i','u','ul','ol','li','blockquote','h2','h3','h4','span','font'],true))return $body;
        if($tag==='font')$tag='span';$styles=[];$style=$node->getAttribute('style');
        if(preg_match('/(?:^|;)\s*text-align\s*:\s*(left|center|right|justify)\s*(?:;|$)/i',$style,$m))$styles[]='text-align:'.strtolower($m[1]);
        if(preg_match('/(?:^|;)\s*font-size\s*:\s*(12|14|16|18|21|24)px\s*(?:;|$)/i',$style,$m))$styles[]='font-size:'.$m[1].'px';
        if(preg_match('/(?:^|;)\s*font-family\s*:\s*(Jost|serif|sans-serif)\s*(?:;|$)/i',$style,$m))$styles[]='font-family:'.$m[1];
        if($node->hasAttribute('face')&&in_array($node->getAttribute('face'),['Jost','serif','sans-serif'],true))$styles[]='font-family:'.$node->getAttribute('face');
        $sizes=['1'=>12,'2'=>14,'3'=>16,'4'=>18,'5'=>21,'6'=>24];if(isset($sizes[$node->getAttribute('size')]))$styles[]='font-size:'.$sizes[$node->getAttribute('size')].'px';
        return $tag==='br'?'<br>':'<'.$tag.($styles?' style="'.marketing_e(implode(';',$styles)).'"':'').'>'.$body.'</'.$tag.'>';
    };$out='';foreach($doc->childNodes as$child)$out.=$clean($child);return $out;
}
function marketing_assignment_history(int $id): array {
    marketing_require_owner();marketing_execution_item($id);
    $q=db()->prepare('SELECT *,UNIX_TIMESTAMP(created_at) created_epoch FROM marketing_item_history WHERE item_id=? ORDER BY id DESC LIMIT 100');$q->execute([$id]);return $q->fetchAll();
}
function marketing_media_sources(): array {return ['portal'=>'Existing Media Library','shared_folder'=>'Shared Folder','adobe_express'=>'Adobe Express','upload'=>'Upload Media','external_file'=>'External Link'];}
function marketing_default_assignee(array $employees,array $item): int {
    if(array_key_exists('assigned_employee_id',$item))return (int)$item['assigned_employee_id'];
    foreach($employees as$e)if(strcasecmp(trim($e['full_name']),'Hope Kahuika')===0)return (int)$e['id'];return 0;
}
function marketing_brief_editor(string $text): void { ?>
<div class="mk4-editor" data-mk-editor><textarea name="brief" data-editor-value hidden><?=marketing_e($text)?></textarea><div data-editor-surface><div class="mk4-editor-toolbar" role="toolbar" aria-label="Brief formatting">
<?php foreach(['bold'=>'Bold','italic'=>'Italic','underline'=>'Underline','insertOrderedList'=>'Numbered List','insertUnorderedList'=>'Bullet List','justifyLeft'=>'Align Left','justifyCenter'=>'Align Center','justifyRight'=>'Align Right','createLink'=>'Link','undo'=>'Undo','redo'=>'Redo','removeFormat'=>'Clear Formatting']as$cmd=>$label):?><button type="button" class="mk4-editor-tool" data-editor-command="<?=$cmd?>" aria-label="<?=$label?>" title="<?=$label?>"><i data-lucide="<?=marketing_editor_icon($cmd)?>" aria-hidden="true"></i></button><?php endforeach;?>
<label>Heading<select data-editor-command="formatBlock"><option value="p">Paragraph</option><option value="h2">Heading 2</option><option value="h3">Heading 3</option></select></label><label>Font size<select data-editor-command="fontSize"><option value="1">12 px</option><option value="2">14 px</option><option value="3">16 px</option><option value="4">18 px</option><option value="5">21 px</option><option value="6">24 px</option></select></label><label>Font style<select data-editor-command="fontName"><option>Jost</option><option>serif</option><option>sans-serif</option></select></label><button type="button" class="mk4-editor-tool" data-editor-expand aria-label="Expand Editor" title="Expand Editor"><i data-lucide="expand" aria-hidden="true"></i></button></div><div class="mk4-editor-body" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="mk-brief-label"><?=marketing_safe_brief($text)?></div></div></div>
<?php }

function marketing_editor_icon(string $command): string {return ['bold'=>'bold','italic'=>'italic','underline'=>'underline','insertOrderedList'=>'list-ordered','insertUnorderedList'=>'list','justifyLeft'=>'align-left','justifyCenter'=>'align-center','justifyRight'=>'align-right','createLink'=>'link','undo'=>'undo-2','redo'=>'redo-2','removeFormat'=>'remove-formatting'][$command]??'type';}
