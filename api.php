<?php
declare(strict_types=1);
// Direct download endpoint: /api.php?download_id=123
// Streams uploaded files as real downloads and redirects external download links.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download_id'])) {
  $DB_HOST='sql313.infinityfree.com';
  $DB_NAME='if0_42896510_Craftland';
  $DB_USER='if0_42896510';
  $DB_PASS='3Jd4WALQKgVa';
  try {
    $pdoDownload = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $id=(int)$_GET['download_id'];
    $st=$pdoDownload->prepare('SELECT * FROM assets WHERE id=?');
    $st->execute([$id]);
    $a=$st->fetch();
    if(!$a){ http_response_code(404); exit('Asset not found.'); }

    $pdoDownload->prepare('UPDATE assets SET downloads=downloads+1 WHERE id=?')->execute([$id]);

    if(($a['download_type'] ?? '') === 'link') {
      $url=trim((string)($a['download_link'] ?? ''));
      if($url===''){ http_response_code(404); exit('Download link unavailable.'); }
      header('Location: '.$url, true, 302);
      exit;
    }

    $fileName=basename((string)($a['file_name'] ?? ''));
    $filePath=__DIR__.'/uploads/files/'.$fileName;
    if($fileName==='' || !is_file($filePath)){ http_response_code(404); exit('Uploaded asset file not found on server.'); }

    $mime='application/octet-stream';
    if(function_exists('finfo_open')) {
      $fi=finfo_open(FILEINFO_MIME_TYPE);
      if($fi){ $detected=finfo_file($fi,$filePath); if($detected) $mime=$detected; finfo_close($fi); }
    }
    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($filePath));
    header('Content-Disposition: attachment; filename="'.addslashes($fileName).'"');
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;
  } catch(Throwable $e) {
    http_response_code(500);
    exit('Download server error.');
  }
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Vary: Origin');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}
if(session_status() !== PHP_SESSION_ACTIVE){
  session_set_cookie_params([
    'lifetime'=>0,
    'path'=>'/',
    'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off',
    'httponly'=>true,
    'samesite'=>'Lax'
  ]);
  session_start();
}

$DB_HOST='sql313.infinityfree.com';
$DB_NAME='if0_42896510_Craftland';
$DB_USER='if0_42896510';
$DB_PASS='3Jd4WALQKgVa';
$GOOGLE_CLIENT_ID='966235464248-25i2mkk8j36aasecpbv0m54bnao6iqho.apps.googleusercontent.com';
$ADMIN_SECRET='67678';

function out(bool $ok,array $data=[]):never{echo json_encode(array_merge(['ok'=>$ok],$data),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function db():PDO{global $DB_HOST,$DB_NAME,$DB_USER,$DB_PASS;try{return new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}catch(Throwable $e){out(false,['error'=>'Database connection failed. Check MySQL Host, Database Name, Username and Password.']);}}
function googleInfo(string $cred):?array{
  global $GOOGLE_CLIENT_ID;
  if($cred==='') return null;

  $endpoints=[
    'https://oauth2.googleapis.com/tokeninfo?id_token='.rawurlencode($cred),
    'https://www.googleapis.com/oauth2/v3/tokeninfo?id_token='.rawurlencode($cred)
  ];
  $raw=false;

  foreach($endpoints as $url){
    if(function_exists('curl_init')){
      $ch=curl_init($url);
      curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_TIMEOUT=>15,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_USERAGENT=>'FF-ASSETS-STORE/1.0'
      ]);
      $try=curl_exec($ch);
      $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
      curl_close($ch);
      if($try!==false && $code>=200 && $code<300){$raw=$try;break;}
    }

    $ctx=stream_context_create(['http'=>[
      'method'=>'GET',
      'timeout'=>15,
      'header'=>"User-Agent: FF-ASSETS-STORE/1.0\r\n"
    ]]);
    $try=@file_get_contents($url,false,$ctx);
    if($try!==false){$raw=$try;break;}
  }

  if($raw===false || $raw==='') return null;
  $g=json_decode($raw,true);
  if(!is_array($g)) return null;

  // The ID token must be issued for THIS exact Web OAuth client.
  $aud=trim((string)($g['aud']??''));
  if($aud!==trim($GOOGLE_CLIENT_ID)) return null;

  $verified=$g['email_verified']??false;
  if(!($verified===true || strtolower((string)$verified)==='true')) return null;

  $iss=(string)($g['iss']??'');
  if($iss!=='https://accounts.google.com' && $iss!=='accounts.google.com') return null;

  if(empty($g['sub'])) return null;

  // Reject expired ID tokens when tokeninfo provides exp.
  if(isset($g['exp']) && (int)$g['exp'] < time()) return null;

  return $g;
}
function auth_user(PDO $pdo):?array{
  // Prefer an existing server session. This prevents uploads/likes from failing
  // when a previously issued Google ID token has expired.
  if(!empty($_SESSION['user_id'])){
    $st=$pdo->prepare('SELECT * FROM users WHERE id=?');$st->execute([(int)$_SESSION['user_id']]);$u=$st->fetch();
    if($u) return $u;
    unset($_SESSION['user_id']);
  }
  $cred=(string)($_POST['google_credential']??$_POST['credential']??'');
  $g=googleInfo($cred);
  if(!$g){
    error_log('FF ASSETS STORE: Google ID token verification failed. Client ID expected: '.$GLOBALS['GOOGLE_CLIENT_ID']);
    return null;
  }
  $sub=$g['sub'];$name=$g['name']??$g['email']??'Google User';$email=$g['email']??'';
  $st=$pdo->prepare('SELECT * FROM users WHERE google_sub=?');$st->execute([$sub]);$u=$st->fetch();
  if(!$u){$st=$pdo->prepare('INSERT INTO users(google_sub,name,email) VALUES(?,?,?)');$st->execute([$sub,$name,$email]);$u=['id'=>(int)$pdo->lastInsertId(),'google_sub'=>$sub,'name'=>$name,'email'=>$email];}
  else{$pdo->prepare('UPDATE users SET name=?,email=? WHERE id=?')->execute([$name,$email,$u['id']]);$u['name']=$name;$u['email']=$email;}
  $_SESSION['user_id']=(int)$u['id'];
  return $u;
}
function require_user(PDO $pdo):array{$u=auth_user($pdo);if(!$u)out(false,['error'=>'Google credential verification failed. Make sure the Index and api.php use the same Google Client ID.']);return $u;}
function admin_unlock():never{global $ADMIN_SECRET;$s=(string)($_POST['secret']??'');if(!hash_equals($ADMIN_SECRET,$s))out(false,['error'=>'Invalid Admin Secret']);out(true,['token'=>hash_hmac('sha256','vip-admin',$ADMIN_SECRET)]);}
function admin_check():void{global $ADMIN_SECRET;$t=(string)($_POST['admin_token']??'');if(!hash_equals(hash_hmac('sha256','vip-admin',$ADMIN_SECRET),$t))out(false,['error'=>'Admin authentication required.']);}
function base_url():string{$scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';return $scheme.'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['SCRIPT_NAME']),'/\\');}
function image_url(?string $n):string{return $n?base_url().'/uploads/images/'.rawurlencode($n):'';}
function file_url(?string $n):string{return $n?base_url().'/uploads/files/'.rawurlencode($n):'';}
function safe_name(string $n):string{$n=preg_replace('/[^A-Za-z0-9._-]/','_',basename($n));return time().'_'.bin2hex(random_bytes(5)).'_'.$n;}
function delete_files(array $a):void{if(!empty($a['image']))@unlink(__DIR__.'/uploads/images/'.$a['image']);if(!empty($a['file_name']))@unlink(__DIR__.'/uploads/files/'.$a['file_name']);}
function get_asset(PDO $pdo,int $id):?array{$s=$pdo->prepare('SELECT * FROM assets WHERE id=?');$s->execute([$id]);return $s->fetch()?:null;}

$action=(string)($_POST['action']??'');
if($action==='admin_unlock')admin_unlock();
$pdo=db();
try{
 switch($action){
  case 'google_login':$u=auth_user($pdo);if(!$u)out(false,['error'=>'Google credential verification failed.']);out(true,['user'=>['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email']]]);
  case 'list_assets':
    $q=trim((string)($_POST['q']??''));$cat=trim((string)($_POST['category']??''));$sort=(string)($_POST['sort']??'trending');$w=[];$p=[];
    if($q!==''){$w[]='(a.name LIKE ? OR a.code LIKE ?)';$p[]="%$q%";$p[]="%$q%";}if($cat!==''){$w[]='a.category=?';$p[]=$cat;}$where=$w?'WHERE '.implode(' AND ',$w):'';
    $order=$sort==='latest'?'a.id DESC':($sort==='downloads'?'a.downloads DESC, a.id DESC':'(a.likes*3+a.favourites*2+a.downloads) DESC,a.id DESC');
    $s=$pdo->prepare("SELECT a.*,COALESCE(u.name,'Unknown Creator') AS creator_name FROM assets a LEFT JOIN users u ON u.id=a.creator_id $where ORDER BY $order LIMIT 5000");$s->execute($p);$assets=$s->fetchAll();$uid=null;
    if(!empty($_POST['google_credential'])){$x=auth_user($pdo);$uid=$x['id']??null;}
    foreach($assets as &$a){$a['id']=(int)$a['id'];$a['creator_id']=(int)$a['creator_id'];$a['likes']=(int)$a['likes'];$a['favourites']=(int)$a['favourites'];$a['downloads']=(int)$a['downloads'];$a['image_url']=image_url($a['image']);$a['download_url']=$a['download_type']==='link'?(string)$a['download_link']:file_url($a['file_name']);$a['liked']=false;$a['favourited']=false;if($uid){$x=$pdo->prepare('SELECT 1 FROM likes WHERE user_id=? AND asset_id=?');$x->execute([$uid,$a['id']]);$a['liked']=(bool)$x->fetchColumn();$x=$pdo->prepare('SELECT 1 FROM favourites WHERE user_id=? AND asset_id=?');$x->execute([$uid,$a['id']]);$a['favourited']=(bool)$x->fetchColumn();}}
    out(true,['assets'=>$assets]);
  case 'upload_asset':
    $u=require_user($pdo);$name=trim((string)($_POST['name']??''));$code=trim((string)($_POST['code']??''));if($name===''||$code==='')out(false,['error'=>'Asset Name and Asset Code are required.']);
    $type=(string)($_POST['download_type']??'');if(!in_array($type,['file','link'],true))out(false,['error'=>'Select Asset File or Download Link.']);
    foreach([__DIR__.'/uploads/images',__DIR__.'/uploads/files'] as $d){if(!is_dir($d)&&!mkdir($d,0755,true))out(false,['error'=>'Could not create upload folder.']);}
    $img='';$file='';$link='';
    if(isset($_FILES['image'])&&$_FILES['image']['error']===UPLOAD_ERR_OK){$ext=strtolower(pathinfo($_FILES['image']['name'],PATHINFO_EXTENSION));if(!in_array($ext,['jpg','jpeg','png','webp','gif'],true))out(false,['error'=>'Invalid image type.']);$img=safe_name($_FILES['image']['name']);if(!move_uploaded_file($_FILES['image']['tmp_name'],__DIR__.'/uploads/images/'.$img))out(false,['error'=>'Image upload failed.']);}
    if($type==='file'){if(!isset($_FILES['file'])||$_FILES['file']['error']!==UPLOAD_ERR_OK)out(false,['error'=>'Please select an Asset File.']);$file=safe_name($_FILES['file']['name']);if(!move_uploaded_file($_FILES['file']['tmp_name'],__DIR__.'/uploads/files/'.$file))out(false,['error'=>'Asset file upload failed.']);}
    else{$link=trim((string)($_POST['download_link']??''));if(!filter_var($link,FILTER_VALIDATE_URL)||!preg_match('~^https?://~i',$link))out(false,['error'=>'Enter a valid http/https Download Link.']);}
    $s=$pdo->prepare('INSERT INTO assets(name,code,server,category,image,download_type,file_name,download_link,creator_id) VALUES(?,?,?,?,?,?,?,?,?)');$s->execute([$name,$code,trim((string)($_POST['server']??'')),trim((string)($_POST['category']??'')),$img,$type,$file,$link,$u['id']]);out(true,['message'=>'Asset uploaded successfully.']);
  case 'toggle_like':
    $u=require_user($pdo);$id=(int)$_POST['asset_id'];if(!get_asset($pdo,$id))out(false,['error'=>'Asset not found.']);$x=$pdo->prepare('SELECT 1 FROM likes WHERE user_id=? AND asset_id=?');$x->execute([$u['id'],$id]);if($x->fetchColumn()){$pdo->prepare('DELETE FROM likes WHERE user_id=? AND asset_id=?')->execute([$u['id'],$id]);$pdo->prepare('UPDATE assets SET likes=GREATEST(likes-1,0) WHERE id=?')->execute([$id]);out(true,['message'=>'Unlike']);}$pdo->prepare('INSERT INTO likes(user_id,asset_id) VALUES(?,?)')->execute([$u['id'],$id]);$pdo->prepare('UPDATE assets SET likes=likes+1 WHERE id=?')->execute([$id]);out(true,['message'=>'Liked']);
  case 'toggle_favourite':
    $u=require_user($pdo);$id=(int)$_POST['asset_id'];if(!get_asset($pdo,$id))out(false,['error'=>'Asset not found.']);$x=$pdo->prepare('SELECT 1 FROM favourites WHERE user_id=? AND asset_id=?');$x->execute([$u['id'],$id]);if($x->fetchColumn()){$pdo->prepare('DELETE FROM favourites WHERE user_id=? AND asset_id=?')->execute([$u['id'],$id]);$pdo->prepare('UPDATE assets SET favourites=GREATEST(favourites-1,0) WHERE id=?')->execute([$id]);out(true,['message'=>'Removed from Favourite']);}$pdo->prepare('INSERT INTO favourites(user_id,asset_id) VALUES(?,?)')->execute([$u['id'],$id]);$pdo->prepare('UPDATE assets SET favourites=favourites+1 WHERE id=?')->execute([$id]);out(true,['message'=>'Added to Favourite']);
  case 'download_asset':
    $id=(int)$_POST['asset_id'];$a=get_asset($pdo,$id);if(!$a)out(false,['error'=>'Asset not found.']);if($a['download_type']==='link')$url=$a['download_link'];else{$url=file_url($a['file_name']);if(!$a['file_name'])out(false,['error'=>'No downloadable file.']);}$pdo->prepare('UPDATE assets SET downloads=downloads+1 WHERE id=?')->execute([$id]);out(true,['url'=>$url]);
  case 'delete_asset':
    $u=require_user($pdo);$id=(int)$_POST['asset_id'];$a=get_asset($pdo,$id);if(!$a||((int)$a['creator_id']!==(int)$u['id']))out(false,['error'=>'You can delete only your own asset.']);delete_files($a);$pdo->prepare('DELETE FROM assets WHERE id=?')->execute([$id]);out(true,['message'=>'Asset deleted.']);
  case 'admin_delete':admin_check();$id=(int)$_POST['asset_id'];$a=get_asset($pdo,$id);if(!$a)out(false,['error'=>'Asset not found.']);delete_files($a);$pdo->prepare('DELETE FROM assets WHERE id=?')->execute([$id]);out(true,['message'=>'Asset deleted by VIP Admin.']);
  case 'my_uploads':$u=require_user($pdo);$s=$pdo->prepare('SELECT a.*,u.name creator_name FROM assets a JOIN users u ON u.id=a.creator_id WHERE a.creator_id=? ORDER BY a.id DESC');$s->execute([$u['id']]);$a=$s->fetchAll();foreach($a as &$x){$x['image_url']=image_url($x['image']);$x['download_url']=$x['download_type']==='link'?$x['download_link']:file_url($x['file_name']);}out(true,['assets'=>$a]);
  case 'favourites':$u=require_user($pdo);$s=$pdo->prepare('SELECT a.*,u.name creator_name FROM assets a JOIN favourites f ON f.asset_id=a.id JOIN users u ON u.id=a.creator_id WHERE f.user_id=? ORDER BY f.id DESC');$s->execute([$u['id']]);$a=$s->fetchAll();foreach($a as &$x){$x['image_url']=image_url($x['image']);$x['download_url']=$x['download_type']==='link'?$x['download_link']:file_url($x['file_name']);}out(true,['assets'=>$a]);
  case 'comments':$s=$pdo->prepare('SELECT c.id,c.text,c.created_at,u.name FROM comments c JOIN users u ON u.id=c.user_id WHERE c.asset_id=? ORDER BY c.id DESC');$s->execute([(int)$_POST['asset_id']]);out(true,['comments'=>$s->fetchAll()]);
  case 'add_comment':$u=require_user($pdo);$t=trim((string)($_POST['text']??''));if($t==='')out(false,['error'=>'Comment is empty.']);$pdo->prepare('INSERT INTO comments(asset_id,user_id,text) VALUES(?,?,?)')->execute([(int)$_POST['asset_id'],$u['id'],$t]);out(true,['message'=>'Comment posted.']);
  case 'report':$u=require_user($pdo);$reason=trim((string)($_POST['reason']??'Not specified'));$pdo->prepare('INSERT INTO reports(asset_id,user_id,reason) VALUES(?,?,?)')->execute([(int)$_POST['asset_id'],$u['id'],$reason]);out(true,['message'=>'Report submitted.']);
  default:out(false,['error'=>'Invalid action.']);
 }
}catch(PDOException $e){if((int)$e->errorInfo[1]===1062)out(false,['error'=>'You already performed this action.']);out(false,['error'=>'Database error. Check database.sql and MySQL tables.']);}catch(Throwable $e){out(false,['error'=>'Server error. Check api.php configuration.']);}
