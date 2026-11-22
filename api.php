<?php
/**
 * Lara AI Playground – self-hosted PHP 7.4+ backend.
 * No dependency on the former website, database, account system or services.
 * Content is saved in storage/content.json, uploads in uploads/.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
$config = require __DIR__ . '/config.php';
$storage = __DIR__ . '/storage';
$uploads = __DIR__ . '/uploads';
if (!is_dir($storage)) @mkdir($storage, 0750, true);
if (!is_dir($uploads)) @mkdir($uploads, 0755, true);
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params(array('lifetime'=>0, 'path'=>'/', 'secure'=>$secure, 'httponly'=>true, 'samesite'=>'Lax'));
}
session_start();
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function respond($obj, int $status=200) : void { http_response_code($status); echo json_encode($obj, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function fail(string $msg, int $status=400) : void { respond(array('ok'=>false,'message'=>$msg),$status); }
function guard(bool $condition, string $msg, int $status=400) : void { if (!$condition) fail($msg,$status); }
function adminOn() : bool { return !empty($_SESSION['lara_admin']); }
function authRequired() : void { guard(adminOn(),'교사 로그인이 필요합니다.',403); }
function csrfRequired() : void { guard(isset($_POST['csrf']) && is_string($_POST['csrf']) && hash_equals($_SESSION['csrf'],$_POST['csrf']),'세션이 만료됐습니다. 새로고침 후 다시 시도해 주세요.',403); }
function arr($data) : array { return is_array($data) ? $data : array(); }
function str($v, int $max=3000) : string { $v = is_scalar($v) ? trim((string)$v) : ''; return function_exists('mb_substr') ? mb_substr($v,0,$max) : substr($v,0,$max); }
function dbPath() : string { return __DIR__ . '/storage/content.json'; }
function authPath() : string { return __DIR__ . '/storage/admin.json'; }
function dbRead() : array {
    if (!file_exists(dbPath())) {
        $s = json_decode((string)file_get_contents(__DIR__.'/seed.json'),true);
        return array('apps'=>arr($s['apps']??array()), 'materials'=>arr($s['materials']??array()));
    }
    $f = @fopen(dbPath(),'rb');
    if (!$f) fail('서버의 데이터 파일을 읽을 수 없습니다.',500);
    flock($f,LOCK_SH); $contents=stream_get_contents($f); flock($f,LOCK_UN); fclose($f);
    $v=json_decode((string)$contents,true);
    return array('apps'=>arr($v['apps']??array()),'materials'=>arr($v['materials']??array()));
}
function dbWrite(array $db) : void {
    $data=json_encode($db,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PRETTY_PRINT);
    guard($data!==false,'데이터 인코딩 오류',500);
    $f=@fopen(dbPath(),'c+b'); guard(!!$f,'서버 데이터 저장 폴더의 쓰기 권한을 확인해 주세요.',500);
    guard(flock($f,LOCK_EX),'서버 저장 잠금 실패',500);
    ftruncate($f,0); rewind($f); $len=fwrite($f,$data); fflush($f); flock($f,LOCK_UN); fclose($f);
    guard($len!==false && $len===strlen($data),'서버 데이터 저장 실패',500);
}
function publicDb(array $db, bool $admin) : array {
    if($admin) return $db;
    return array('apps'=>array_values(array_filter($db['apps'],function($x){return ($x['status']??'draft')==='published';})),
        'materials'=>array_values(array_filter($db['materials'],function($x){return ($x['status']??'draft')==='published';})));
}
function setupDone(): bool { return true; }
function postFile(string $key) : ?array {
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) return null;
    $f=$_FILES[$key];
    if (($f['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;
    if ($f['error']!==UPLOAD_ERR_OK) {
        if (in_array($f['error'],array(UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE),true))fail('서버 업로드 용량을 초과했습니다. PHP upload_max_filesize 설정을 확인해 주세요.');
        fail('업로드에 실패했습니다. 오류 번호: '.(int)$f['error']);
    }
    guard(is_uploaded_file($f['tmp_name']),'유효하지 않은 업로드 파일입니다.');
    return $f;
}
function fileExt(string $name): string { return strtolower(pathinfo($name,PATHINFO_EXTENSION)); }
function safePath(string $p): bool { return preg_match('~^uploads/[a-zA-Z0-9_./-]+$~D',$p)===1 && strpos($p,'..')===false; }
function saveUploaded(array $f, string $role, string $type, string $id, array $config): array {
    $ext=fileExt((string)$f['name']); $bytes=(int)$f['size'];
    $limit=($role==='thumbnail')?5*1024*1024:(int)($config['max_upload_bytes']??83886080);
    guard($bytes>0 && $bytes<=$limit, $role==='thumbnail'?'썸네일 크기는 5MB 이내로 선택해 주세요.':'파일 용량이 서버 설정을 초과했습니다.');
    $allowThumb=array('jpg','jpeg','png','webp','gif');
    $allowMaterials=array('pdf','hwp','hwpx','doc','docx','ppt','pptx','xls','xlsx','csv','txt','jpg','jpeg','png','webp','gif','mp3','wav','mp4','webm','zip');
    $allowed=$role==='thumbnail'?$allowThumb:($type==='html'?array('html','htm'):($type==='zip'?array('zip'):$allowMaterials));
    guard(in_array($ext,$allowed,true),'허용되지 않은 파일 형식입니다.');
    $mime='';if(class_exists('finfo')){$fi=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$fi->file($f['tmp_name']);}
    if ($role==='thumbnail') guard(strpos($mime,'image/')===0 || $mime==='application/octet-stream', '이미지 파일만 썸네일로 등록할 수 있습니다.');
    if ($type==='zip' && $role==='asset') return saveZip($f,$id,$limit);
    $folder=$role==='thumbnail'?'thumbs':($type==='html'?'apps':'files');
    $dir=__DIR__.'/uploads/'.$folder;
    if(!is_dir($dir)) guard(@mkdir($dir,0755,true),'서버에 업로드 폴더를 만들 수 없습니다.',500);
    $name=bin2hex(random_bytes(12)).'.'.$ext;
    $dest=$dir.'/'.$name;
    guard(move_uploaded_file($f['tmp_name'],$dest),'업로드 파일을 저장할 수 없습니다.',500);
    @chmod($dest,0644);
    return array('path'=>'uploads/'.$folder.'/'.$name,'filename'=>str((string)$f['name'],230));
}
function saveZip(array $f, string $id, int $limit) : array {
    guard(class_exists('ZipArchive'),'서버에 PHP ZipArchive 확장 모듈이 필요합니다. ZIP 대신 단일 HTML 파일로 등록해 주세요.');
    $zip=new ZipArchive();guard($zip->open($f['tmp_name'])===true,'ZIP 파일을 열 수 없습니다.');
    $allowed=array('html','htm','js','mjs','css','json','png','jpg','jpeg','webp','gif','ico','woff','woff2','ttf','otf','mp3','wav','mp4','webm','pdf','txt','glb','gltf','bin');
    guard($zip->numFiles<=300,'ZIP 안의 파일이 너무 많습니다. 300개 이하로 정리해 주세요.');
    $size=0;$names=array();$indexCandidates=array();
    for($i=0;$i<$zip->numFiles;$i++){
        $stat=$zip->statIndex($i);$name=str_replace('\\','/',(string)($stat['name']??''));
        if(substr($name,-1)==='/')continue;
        guard($name!=='' && strpos($name,'..')===false && substr($name,0,1)!=='/' && strpos($name,':')===false && strpos($name,"\0")===false,'ZIP 안에 위험한 파일 경로가 있습니다.');
        guard(substr_count($name,'/')<=8,'ZIP 폴더 깊이가 너무 깊습니다.');
        guard(in_array(fileExt($name),$allowed,true),'ZIP 안에 지원하지 않는 파일이 있습니다: '.$name);
        $size+=(int)($stat['size']??0);guard($size<=120*1024*1024,'ZIP 압축 해제 용량이 120MB를 초과합니다.');
        $names[$i]=$name;
        if(strtolower(basename($name))==='index.html')$indexCandidates[]=$name;
    }
    guard(count($indexCandidates)>0,'ZIP 안에 index.html 파일이 없습니다.');
    $folder=bin2hex(random_bytes(12));$dir=__DIR__.'/uploads/webapps/'.$folder;
    guard(@mkdir($dir,0755,true),'ZIP 업로드 폴더를 만들 수 없습니다.',500);
    foreach($names as $i=>$name){
        $target=$dir.'/'.$name; $parent=dirname($target);
        if(!is_dir($parent)) guard(@mkdir($parent,0755,true),'ZIP 내부 폴더 생성에 실패했습니다.',500);
        $in=$zip->getStream($zip->getNameIndex($i));guard(!!$in,'ZIP 내부 파일을 읽을 수 없습니다.');
        $out=@fopen($target,'wb');guard(!!$out,'ZIP 내부 파일을 저장할 수 없습니다.',500);
        stream_copy_to_stream($in,$out);fclose($in);fclose($out);@chmod($target,0644);
    }
    $zip->close();
    usort($indexCandidates,function($a,$b){return substr_count($a,'/')-substr_count($b,'/');});
    return array('path'=>'uploads/webapps/'.$folder.'/'.$indexCandidates[0],'filename'=>str((string)$f['name'],230));
}
function deleteUpload(string $path) : void {
    if(!safePath($path))return;
    $full=__DIR__.'/'.$path;
    if(strpos($path,'uploads/webapps/')===0){$parts=explode('/',$path);if(count($parts)>=4){$dir=__DIR__.'/uploads/webapps/'.$parts[2];if(is_dir($dir)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file){if($file->isDir())@rmdir($file->getPathname());else @unlink($file->getPathname());}@rmdir($dir);}}}
    else if(is_file($full))@unlink($full);
}
try {
    $action=$_SERVER['REQUEST_METHOD']==='GET'?str((string)($_GET['action']??'init'),30):str((string)($_POST['action']??''),30);
    if($_SERVER['REQUEST_METHOD']==='GET') {
        if($action==='init')respond(array('ok'=>true,'mode'=>'server','admin'=>adminOn(),'configured'=>setupDone(),'csrf'=>$_SESSION['csrf'],'items'=>publicDb(dbRead(),adminOn()),'max_file_bytes'=>$config['max_upload_bytes']??0));
        if($action==='export'){
            authRequired(); header('Content-Disposition: attachment; filename="lara-playground-backup.json"');
            respond(array('format'=>'lara-playground-server-backup-v1','created'=>date('c'),'items'=>dbRead()));
        }
        fail('지원하지 않는 요청입니다.',404);
    }
    guard($_SERVER['REQUEST_METHOD']==='POST','지원하지 않는 요청입니다.',405);
    csrfRequired();
    if($action==='setup'){
        guard(!setupDone(),'관리자 설정이 이미 완료됐습니다.',409);
        $key=str($_POST['install_key']??'',120);guard(hash_equals((string)$config['install_key'],$key),'설치키가 올바르지 않습니다.',403);
        $pw=str($_POST['password']??'',500);guard(strlen($pw)>=8,'비밀번호는 8자 이상이어야 합니다.');
        $hash=password_hash($pw,PASSWORD_DEFAULT);guard(!!$hash,'비밀번호를 저장하지 못했습니다.',500);
        guard(file_put_contents(authPath(),json_encode(array('password_hash'=>$hash,'created'=>date('c'))),LOCK_EX)!==false,'관리자 설정 저장 실패',500);
        @chmod(authPath(),0600);session_regenerate_id(true);$_SESSION['lara_admin']=true;$_SESSION['csrf']=bin2hex(random_bytes(24));
        respond(array('ok'=>true,'admin'=>true,'configured'=>true,'csrf'=>$_SESSION['csrf']));
    }
    if($action==='login'){
        $pw=str($_POST['password']??'',500);
        $expected=(string)($config['admin_password']??'');
        guard($expected!=='','config.php에 admin_password가 설정되지 않았습니다.',500);
        $attempt=(int)($_SESSION['failed_logins']??0);
        if($attempt>=8 && time()-((int)($_SESSION['last_failed']??0))<300)fail('로그인 시도가 많습니다. 5분 후 다시 시도해 주세요.',429);
        if(!hash_equals($expected,$pw)){$_SESSION['failed_logins']=$attempt+1;$_SESSION['last_failed']=time();fail('비밀번호가 일치하지 않습니다.',403);}
        $_SESSION['failed_logins']=0;session_regenerate_id(true);$_SESSION['lara_admin']=true;$_SESSION['csrf']=bin2hex(random_bytes(24));
        respond(array('ok'=>true,'admin'=>true,'csrf'=>$_SESSION['csrf']));
    }
    if($action==='logout'){$_SESSION['lara_admin']=false;session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(24));respond(array('ok'=>true));}
    authRequired();
    if($action==='save'){
        $input=json_decode((string)($_POST['record']??''),true);guard(is_array($input),'입력 데이터가 올바르지 않습니다.');
        $type=str($input['type']??'',20);$zone=str($input['zone']??'',20);
        guard(in_array($type,array('link','html','zip','material'),true),'등록 유형을 선택해 주세요.');
        guard(in_array($zone,array('reading','art','sport','lara'),true),'등록 영역이 잘못됐습니다.');
        $id=str($input['id']??'',90);guard(preg_match('/^[a-zA-Z0-9_-]{4,90}$/D',$id)===1,'콘텐츠 ID가 올바르지 않습니다.');
        $title=str($input['title']??'',150);guard($title!=='','제목을 입력해 주세요.');
        $status=str($input['status']??'',20);guard(in_array($status,array('published','draft'),true),'게시 상태가 올바르지 않습니다.');
        $url=$type==='link'?str($input['url']??'',2000):'';
        if($type==='link' && $status==='published')guard(filter_var($url,FILTER_VALIDATE_URL)!==false && preg_match('/^https?:\/\//i',$url),'올바른 http/https 링크를 입력해 주세요.');
        $db=dbRead();$old=null;
        foreach(array('apps','materials') as $group)foreach($db[$group] as $x)if(($x['id']??'')===$id)$old=$x;
        $record=array('id'=>$id,'zone'=>$zone,'type'=>$type,'title'=>$title,'description'=>str($input['description']??'',3000),'author'=>str($input['author']??'',150),'tags'=>str($input['tags']??'',400),'emoji'=>str($input['emoji']??'✨',50),'url'=>$url,'status'=>$status,'created'=>str($old['created']??date('Y-m-d'),30),'order'=>isset($old['order'])?(int)$old['order']:count($db['apps'])+count($db['materials']),'filename'=>'','asset'=>'','thumbnail'=>str($old['thumbnail']??'',300));
        if($old && ($old['type']??'')===$type && $type!=='link'){$record['asset']=str($old['asset']??'',300);$record['filename']=str($old['filename']??'',230);}
        $file=postFile('asset');$thumb=postFile('thumbnail');
        if($file && $type!=='link'){$r=saveUploaded($file,'asset',$type,$id,$config);$record['asset']=$r['path'];$record['filename']=$r['filename'];}
        if(($_POST['remove_thumbnail']??'')==='1')$record['thumbnail']='';
        if($thumb){$r=saveUploaded($thumb,'thumbnail',$type,$id,$config);$record['thumbnail']=$r['path'];}
        if($type!=='link' && $status==='published')guard($record['asset']!=='','공개할 파일을 업로드해 주세요.');
        foreach(array('apps','materials') as $g)$db[$g]=array_values(array_filter($db[$g],function($x)use($id){return ($x['id']??'')!==$id;}));
        $db[$type==='material'?'materials':'apps'][]=$record;
        dbWrite($db);
        if($old){if(($old['asset']??'') && $old['asset']!==$record['asset'])deleteUpload($old['asset']);if(($old['thumbnail']??'') && $old['thumbnail']!==$record['thumbnail'])deleteUpload($old['thumbnail']);}
        respond(array('ok'=>true,'items'=>$db));
    }
    if($action==='delete'){
        $id=str($_POST['id']??'',90);guard(preg_match('/^[a-zA-Z0-9_-]{4,90}$/D',$id)===1,'삭제 대상이 올바르지 않습니다.');
        $db=dbRead();$found=null;
        foreach(array('apps','materials') as $g){foreach($db[$g] as $x)if(($x['id']??'')===$id)$found=$x;$db[$g]=array_values(array_filter($db[$g],function($x)use($id){return ($x['id']??'')!==$id;}));}
        guard(!!$found,'삭제할 항목이 없습니다.',404);dbWrite($db);
        if(!empty($found['asset']))deleteUpload($found['asset']);if(!empty($found['thumbnail']))deleteUpload($found['thumbnail']);
        respond(array('ok'=>true,'items'=>$db));
    }
    fail('지원하지 않는 요청입니다.',404);
}catch(Throwable $e){error_log('Lara API error: '.$e->getMessage());fail('서버 처리 중 오류가 발생했습니다. 서버 설정과 폴더 쓰기 권한을 확인해 주세요.',500);}
