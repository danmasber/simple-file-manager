<?php

include_once("../includes/ctrlacc.php");
include_once("./configuracion/configuracion_fichero.php");


/********************************
Simple PHP File Manager
Copyright John Campbell (jcampbell1)

https://github.com/jcampbell1/simple-file-manager
Liscense: MIT
********************************/

//Disable error report for undefined superglobals
error_reporting( error_reporting() & ~E_NOTICE );

//Security options
$allow_delete = true; // Set to false to disable delete button and delete POST request.
$allow_upload = true; // Set to true to allow upload files
$allow_create_folder = true; // Set to false to disable folder creation
$allow_direct_link = true; // Set to false to only allow downloads and not direct link
$allow_show_folders = true; // Set to false to hide all subdirectories

$disallowed_patterns = ['*.php'];  // must be an array.  Matching files not allowed to be uploaded
$hidden_patterns = ['*.php','.*']; // Matching files hidden in directory index
$files_to_skip = array(
    '.',
    '..',
    'index.php'
);


// must be in UTF-8 or `basename` doesn't work
setlocale(LC_ALL,'es_ES.UTF-8');

$tmp_dir = dirname($_SERVER['SCRIPT_FILENAME']);
if(DIRECTORY_SEPARATOR==='\\') $tmp_dir = str_replace('/',DIRECTORY_SEPARATOR,$tmp_dir);
$tmp = get_absolute_path($tmp_dir . '/' .$_REQUEST['file']);

if($tmp === false)
	err(404,'File or Directory Not Found');
if(substr($tmp, 0,strlen($tmp_dir)) !== $tmp_dir)
	err(403,"Forbidden");
if(strpos($_REQUEST['file'], DIRECTORY_SEPARATOR) === 0)
	err(403,"Forbidden");
if(preg_match('@^.+://@',$_REQUEST['file'])) {
	err(403,"Forbidden");
}
if(strpos($_REQUEST['file'], '..') !== false){
    err(403,"Forbidden");
}

if(!$_COOKIE['_sfm_xsrf'])
	setcookie('_sfm_xsrf',bin2hex(openssl_random_pseudo_bytes(16)));
if($_POST) {
	if($_COOKIE['_sfm_xsrf'] !== $_POST['xsrf'] || !$_POST['xsrf'])
		err(403,"XSRF Failure");
}

$file = $_REQUEST['file'] ?: $directorio_relativo_ficheros;

if($_GET['do'] == 'list') {
	if (is_dir($file)) {
		$directory = $file;
		$result = [];
        $files = array_diff(scandir($directory), $files_to_skip);
		foreach ($files as $entry) if (!is_entry_ignored($entry, $allow_show_folders, $hidden_patterns)) {
			$i = $directory . '/' . $entry;
			$stat = stat($i);
			$result[] = [
				'mtime' => $stat['mtime'],
				'size' => $stat['size'],
				'name' => basename($i),
				'path' => preg_replace('@^\./@', '', $i),
				'is_dir' => is_dir($i),
				'is_deleteable' => $allow_delete && ((!is_dir($i) && is_writable($directory)) ||
														(is_dir($i) && is_writable($directory) && is_recursively_deleteable($i))),
				'is_readable' => is_readable($i),
				'is_writable' => is_writable($i),
				'is_executable' => is_executable($i),
			];
		}
		usort($result,function($f1,$f2){
			$f1_key = ($f1['is_dir']?:2) . $f1['name'];
			$f2_key = ($f2['is_dir']?:2) . $f2['name'];
			return $f1_key > $f2_key;
		});
	} else {
		err(412,"Not a Directory");
	}
	echo json_encode(['success' => true, 'is_writable' => is_writable($file), 'results' =>$result]);
	exit;
} elseif ($_POST['do'] == 'delete') {
	if($allow_delete) {
		rmrf($file);
	}
	exit;
} elseif ($_POST['do'] == 'mkdir' && $allow_create_folder) {
	// don't allow actions outside root. we also filter out slashes to catch args like './../outside'
	$dir = $_POST['name'];
	$dir = str_replace('/', '', $dir);
	if(substr($dir, 0, 2) === '..')
	    exit;
	chdir($file);
	@mkdir($_POST['name']);
	exit;
} elseif ($_POST['do'] == 'upload' && $allow_upload) {
	foreach($disallowed_patterns as $pattern)
		if(fnmatch($pattern, $_FILES['file_data']['name']))
			err(403,"Files of this type are not allowed.");

	$res = move_uploaded_file($_FILES['file_data']['tmp_name'], $file.'/'.$_FILES['file_data']['name']);
	exit;
} elseif ($_GET['do'] == 'download') {
	foreach($disallowed_patterns as $pattern)
		if(fnmatch($pattern, $file))
			err(403,"Files of this type are not allowed.");

	$filename = basename($file);
	$finfo = finfo_open(FILEINFO_MIME_TYPE);
	header('Content-Type: ' . finfo_file($finfo, $file));
	header('Content-Length: '. filesize($file));
	header(sprintf('Content-Disposition: attachment; filename=%s',
		strpos('MSIE',$_SERVER['HTTP_REFERER']) ? rawurlencode($filename) : "\"$filename\"" ));
	ob_flush();
	readfile($file);
	exit;
}

function is_entry_ignored($entry, $allow_show_folders, $hidden_patterns) {
	if ($entry === basename(__FILE__)) {
		return true;
	}

	if (is_dir($entry) && !$allow_show_folders) {
		return true;
	}
	foreach($hidden_patterns as $pattern) {
		if(fnmatch($pattern,$entry)) {
			return true;
		}
	}
	return false;
}

function rmrf($dir) {
	if(is_dir($dir)) {
        $files = array_diff(scandir($dir), [".",".."]);
		foreach ($files as $file)
			rmrf("$dir/$file");
		rmdir($dir);
	} else {
		unlink($dir);
	}
}
function is_recursively_deleteable($d) {
	$stack = [$d];
	while($dir = array_pop($stack)) {
		if(!is_readable($dir) || !is_writable($dir))
			return false;
        $files = array_diff(scandir($dir), [".",".."]);
		foreach($files as $file) if(is_dir($file)) {
			$stack[] = "$dir/$file";
		}
	}
	return true;
}

// from: http://php.net/manual/en/function.realpath.php#84012
function get_absolute_path($path) {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $absolutes = [];
        foreach ($parts as $part) {
            if ('.' == $part) continue;
            if ('..' == $part) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }
        return implode(DIRECTORY_SEPARATOR, $absolutes);
    }

function err($code,$msg) {
	http_response_code($code);
	header("Content-Type: application/json");
	echo json_encode(['error' => ['code'=>intval($code), 'msg' => $msg]]);
	exit;
}

function asBytes($ini_v) {
	$ini_v = trim($ini_v);
	$s = ['g'=> 1<<30, 'm' => 1<<20, 'k' => 1<<10];
	return intval($ini_v) * ($s[strtolower(substr($ini_v,-1))] ?: 1);
}
$MAX_UPLOAD_SIZE = min(asBytes(ini_get('post_max_size')), asBytes(ini_get('upload_max_filesize')));
?>
<!DOCTYPE html>
<html><head>
<meta http-equiv="content-type" content="text/html; charset=utf-8">

<style>
body {
    font-family: "lucida grande","Segoe UI",Arial, sans-serif;
    font-size: 14px;
    width:1024;
    padding:1em;
    margin:0;
}
th {
    font-weight: normal;
    color: #ffffff;
    background-color: #63676B;
    padding:.5em 1em .5em .2em;
    text-align: left;
    cursor:pointer;
    user-select: none;
}
th .indicator {
    margin-left: 6px 
}

#top {
    height:52px;






}
#mkdir {
    display:inline-block;
    float:right;
    padding-top:16px;
}
label { 
    display:block;
    font-size:11px;
    color:#555;
}
#file_drop_target {
    width:550px;
    padding:12px 0;
    border: 4px dashed #ccc;
    font-size:12px;
    color:#ccc;
    text-align: center;
    float:right;
    margin-right:20px;
}
#file_drop_target.drag_over {
    border: 4px dashed #96C4EA;
    color: #96C4EA;
}
#upload_progress {
    padding: 4px 0;
}
#upload_progress .error

{
    color:#a00;
}
#upload_progress > div
{ 
    padding:3px 0;
}
.no_write #mkdir, .no_write #file_drop_target







{
    display: none
}
.progress_track
{
    display:inline-block;
    width:200px;
    height:10px;
    border:1px solid #333;
    margin: 0 4px 0 10px;
}
.progress
{
    background-color: #82CFFA;
    height:10px;
 }

#breadcrumb
{ 
    padding-top:34px;
    font-size:15px;
    color:#aaa;
    display:inline-block;
    float:left;
}
#folder_actions
{
    width: 50%;
        float:right;
}
a, a:visited
{ 
    color:#003300; 
    text-decoration: none
}
a:hover
{
    text-decoration: underline

}
.sort_hide{ 
    display:none;
}
table
{
    border-collapse: collapse;width:100%;
}
thead
{
    max-width: 1024px



}
td
{ 
    padding:.2em 1em .2em .2em; 
    border-bottom:1px solid #def;
    height:30px; 
    font-size:12px;
    white-space: nowrap;
}
td.first
{
    font-size:14px;
    white-space: normal;
}
td.empty
{ 
    color:#003300; 
    font-style: italic;
    text-align: center;padding:3em 0;
}

.is_dir .size
{
    color:transparent;font-size:0;
}
.is_dir .size:before
{content: "--"; font-size:14px;color:#333;




}
.is_dir .download
{
    visibility: hidden
}
a.delete
{
    background: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAACnSURBVFhH7ZYxDsIwEARd8Sn4AzwKyEuBZ8Ae0jWO4TQ+UiDdSFNsJE+cLq0oJjnJu3x22rOj3JyH7F/u3uTm+Mt6Pj2fxoO/EjOKZJwidVhkz9cFVoHsxkRBujFRkG5MFKQbEwXpxkRBujFRkG5MFKQbEwXpxkRBujFRkG5MNvD/F/Cfz/17MQ7Szqb+ExfpXzHrWU6zk1dpXzGKf9POXKQ1imJAay8UYd+QLebMNgAAAABJRU5ErkJggg==) no-repeat scroll 0 5px;
        color:#d00; 
        margin-left: 15px;
        font-size:11px;
        padding:10px 0 0px 25px;
        background-size: 20px 20px;
}
.name

{
    background: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADIAAAAyCAYAAAAeP4ixAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAE2SURBVGhD7ZehbsJQFIarCCgcQSEIySzJkMi9AhPYvQoOMZB7gGkQOCRye4WZualhEKjxH8JNGnLTtb3nvy3kfMmXtKnpl9xz0ibGDdGGf0TXsAHpsEPEKDEuZH++02MMo8awQ77h7+WaGsMO2cERpMfECBHoMbFCBGpMzBCBFhM7RKDEVBEipGNWMDimqhBBNYYd8gH7GT7DIwyOYYcUdQ5LwQqRY/NVwB8o7/EOS8EKKcoUWohgIcpYiOO/ENnrj4oOoA96SA/Kcy030Ac9pAs/FV1AHzYjDgtRhh4SurUeYB7oIaFbK+t/JA09pAO3Ab7CPNiMOCxEGXpIEz4pOoQ+6CF3860VurWunUEfNiMOC1HGQhwWooyFOCxEGbWQA5xU6BKqhNTF0iEt+FYjX6BxIyTJCQgBu1XLf9sJAAAAAElFTkSuQmCC) no-repeat scroll 0 20px;
    padding: 20px 0 10px 30px;
    background-size: 25px 25px;
}
.is_dir .name

{
    background: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADIAAAAyCAYAAAAeP4ixAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAK9SURBVGhD7Zk7aBRRFIYXLIKCEQuxExEhYhcFCxEFUSsxCJFgAqJ2goWIdiJaiIrgI50kjRoLEdJpumApsfIBFpKg4AvxBYqF7/87zFnuzowxu8p41P3gY+bc7C7nz+7cO49amzZtfspsOShfy29N+FSul2E4LWnsiyTMTHwrec87uVSG4LmkqeVWzZwLkvfdlLMY+NPQDDbLPPlQ8t6jcn4Fdsof0moQWCc/S/+MKnwk+2QBf0Gr9MoHsuxY+t36sck/b5Vs4FeDVM0xSb+nrEr424L0S/q9bFXCfxFkjlwSzH2Sfq/KBsqCMM1dkZ+k/z2iL+QOafhgynnJGCv3ZFCZhr/K+gyWD8Iq/VLybSxkIDDHJb0zkxWCLJPU96yKzRFJr4co8kFYNakLs0JARiW9bqHIB/Gv66BVsZmS9LqIIh/kuqTeZFVcOGnlYH9llcgHeSKpox/oayV9jlsl0iALJPvPrIrNXkmvZ6wSaZCNkv0xq2IzLOl1p1UiDXJAsn/CqtjckvTabZVIg1yS7G+3Ki4s2h/kR9nBAKRB7kj2m71+rxr6o8/bVmV4EJJxWkLSEDcTpoFfDD1ftCrDg6zIthMyOiclve63KsOD7Mq2QzI6zKr0usGqDA9yNtsyP0eHdY5eWffqeJAb2XaNjAxnHPT52KoED/JGcu4yV0bGF+1rViV4EOTKKzqcldOrXUylpEE4v4/OiKTXwt3GNMhhBoLDlSu9dlmVkAbpYSAwvmi/l4VFOw2ymIHArJT0yaOMAh6CWSs6uyW9cruqgAdhHYmOL9p7rMrhQc5ZFRtftFdblcOD8LVFh58/zzpLF20PwoEUGSYi+uShUikk5AU8po7MVkmfhbvwzl3JC7gbsS2oA5LrJPrksUIpm2XVDzRb9b6c9skudyK46mJ+jip3eKKfmbf516jVvgPWjL2OHf8X/wAAAABJRU5ErkJggg==) no-repeat scroll 0 20px;
    padding: 20px 0 10px 30px;
    background-size: 25px 25px;
}
.download

{
    background: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADIAAAAyCAYAAAAeP4ixAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAN/SURBVGhD7ZlZqJRlGIBPbqUhChYpYnZhotHqjYoLioWZpQgKWhYVheYGIuJ+oRYRiBEZJCriEgjeKIhLoRfugnghLqDiDuJCCFmWWfY8v/PFz5yZ8Th+4/wD88DDmfc7M9+875n51tNQp06dTNAO38ZJOAen4Wh8A5/ATNMMx+Iv+A/eK+Jl/AG7YebojUcwJHsLd+L3uBCX4no8heE5f+EybI+ZYAreQZM7ih/ik1iMl9ACLcTXnMQXsarMQpP5G2diC2wqfrUOoK//FV/BquDgNYnfcYgNZfAU/oT2cx6fxYrQFd9Fk+6FzVE64028iyNseAT8FB1PFrPVhpgMwL1o52nP4Hhck4u/wRh0wOton+V+uo2Yiv6l7fQGbkZnnMP4L4airmIbjIVrjf3uS6JHZCSabBi8rTDNaximWRe6mPheDnrfv4sN5dISz6FJOoUWozUOR58fm3Xo+09OojIZinayK4mqw6doDiuTqEwWoZ18kUTV4U00h7JmL7+bE/AC2sk7WC0cg+bgxPJQvI7H0Bfrfqzm3qcvmoeD3n3YGCy13UkYjL+hL9yBFlVtnkeLCH9YdZr/HAtu/7ujK7NPnI9ZOiO4e+iEb+EqdCkwz43Y6NNxZvKX3yVRtnkV3VGY7wYbAgPRxovomlALuLe7hOb9/xq3HG1wC15LvIfmfRY9jTacyDW8bFABHLBt7z+MznE09/4GHkMNPAvEpiN68tuURPFZguY+2+CPXJC/IYyBpzz73pNE8Qk75G8NTueCFwwiU+lCpqP9J+cg52ODzwwiU+lCXFfsP9kdf5ALDmHshbCShbgYXkH77xEawrnDbXNMKlnIDLTvg0mUYxR6EnOGiXZGhkoV4iJuruY8yIY0X6Nv6l5mHsZY5WMX4g3LRLyN9vslNsLxMRfDPa3fvx/xYxyGHnJK+Qzm86BCPG8U6ivfcfgVhhlWnalKjuk+WOjq50HuxnxKFdIT07cwTdXV3DuCJuMbeXOyAregt+rF9PzyCeZTqpCncTUW6i/tz7gWF2M/TPZVj5tKDfbHTr2QauNlgdv2QLFCvEt+7v7D7OFNvbPQNbQAKVTIArTNO+RM4myyDU0yFJNfSCjCS3H/v5hZ3A1sx1DM+7nHFpIuwkU286SLCf8X/DP3s2aKCKSLCdZcEYF0MTVbRMBi3N58lER16tQaDQ3/Aa7bJvapMpnvAAAAAElFTkSuQmCC) no-repeat scroll 0 5px;
    padding:4px 0 4px 25px;
    background-size: 20px 20px;
}

</style>
<script src="./jquery.min.js"></script>
<script>
(function($){
	$.fn.tablesorter = function() {
		var $table = this;
		this.find('th').click(function() {
			var idx = $(this).index();
			var direction = $(this).hasClass('sort_asc');
			$table.tablesortby(idx,direction);
		});
		return this;
	};
	$.fn.tablesortby = function(idx,direction) {
		var $rows = this.find('tbody tr');
		function elementToVal(a) {
			var $a_elem = $(a).find('td:nth-child('+(idx+1)+')');
			var a_val = $a_elem.attr('data-sort') || $a_elem.text();
			return (a_val == parseInt(a_val) ? parseInt(a_val) : a_val);
		}
		$rows.sort(function(a,b){
			var a_val = elementToVal(a), b_val = elementToVal(b);
			return (a_val > b_val ? 1 : (a_val == b_val ? 0 : -1)) * (direction ? 1 : -1);
		})
		this.find('th').removeClass('sort_asc sort_desc');
		$(this).find('thead th:nth-child('+(idx+1)+')').addClass(direction ? 'sort_desc' : 'sort_asc');
		for(var i =0;i<$rows.length;i++)
			this.append($rows[i]);
		this.settablesortmarkers();
		return this;
	}
	$.fn.retablesort = function() {
		var $e = this.find('thead th.sort_asc, thead th.sort_desc');
		if($e.length)
			this.tablesortby($e.index(), $e.hasClass('sort_desc') );

		return this;
	}
	$.fn.settablesortmarkers = function() {
		this.find('thead th span.indicator').remove();
		this.find('thead th.sort_asc').append('<span class="indicator">&darr;<span>');
		this.find('thead th.sort_desc').append('<span class="indicator">&uarr;<span>');
		return this;
	}
})(jQuery);
$(function(){
	var XSRF = (document.cookie.match('(^|; )_sfm_xsrf=([^;]*)')||0)[2];
	var MAX_UPLOAD_SIZE = <?php echo $MAX_UPLOAD_SIZE ?>;
	var $tbody = $('#list');
	$(window).on('hashchange',list).trigger('hashchange');
	$('#table').tablesorter();

	$('#table').on('click','.delete',function(data) {
		$.post("",{'do':'delete',file:$(this).attr('data-file'),xsrf:XSRF},function(response){
			list();
		},'json');
		return false;
	});

	$('#mkdir').submit(function(e) {
		var hashval = decodeURIComponent(window.location.hash.substr(1)),
			$dir = $(this).find('[name=name]');
		e.preventDefault();
		$dir.val().length && $.post('?',{'do':'mkdir',name:$dir.val(),xsrf:XSRF,file:hashval},function(data){
			list();
		},'json');
		$dir.val('');
		return false;
	});
<?php if($allow_upload): ?>
	// file upload stuff
	$('#file_drop_target').on('dragover',function(){
		$(this).addClass('drag_over');
		return false;
	}).on('dragend',function(){
		$(this).removeClass('drag_over');
		return false;
	}).on('drop',function(e){
		e.preventDefault();
		var files = e.originalEvent.dataTransfer.files;
		$.each(files,function(k,file) {
			uploadFile(file);
		});
		$(this).removeClass('drag_over');
	});
	$('input[type=file]').change(function(e) {
		e.preventDefault();
		$.each(this.files,function(k,file) {
			uploadFile(file);
		});
	});


	function uploadFile(file) {
		var folder = decodeURIComponent(window.location.hash.substr(1));

		if(file.size > MAX_UPLOAD_SIZE) {
			var $error_row = renderFileSizeErrorRow(file,folder);
			$('#upload_progress').append($error_row);
			window.setTimeout(function(){$error_row.fadeOut();},5000);
			return false;
		}

		var $row = renderFileUploadRow(file,folder);
		$('#upload_progress').append($row);
		var fd = new FormData();
		fd.append('file_data',file);
		fd.append('file',folder);
		fd.append('xsrf',XSRF);
		fd.append('do','upload');
		var xhr = new XMLHttpRequest();
		xhr.open('POST', '?');
		xhr.onload = function() {
			$row.remove();
    		list();
  		};
		xhr.upload.onprogress = function(e){
			if(e.lengthComputable) {
				$row.find('.progress').css('width',(e.loaded/e.total*100 | 0)+'%' );
			}
		};
	    xhr.send(fd);
	}
	function renderFileUploadRow(file,folder) {
		return $row = $('<div/>')
			.append( $('<span class="fileuploadname" />').text( (folder ? folder+'/':'')+file.name))
			.append( $('<div class="progress_track"><div class="progress"></div></div>')  )
			.append( $('<span class="size" />').text(formatFileSize(file.size)) )
	};
	function renderFileSizeErrorRow(file,folder) {
		return $row = $('<div class="error" />')
			.append( $('<span class="fileuploadname" />').text( 'Error: ' + (folder ? folder+'/':'')+file.name))
			.append( $('<span/>').html(' file size - <b>' + formatFileSize(file.size) + '</b>'
				+' exceeds max upload size of <b>' + formatFileSize(MAX_UPLOAD_SIZE) + '</b>')  );
	}
<?php endif; ?>
	function list() {
		var hashval = window.location.hash.substr(1);
		$.get('?do=list&file='+ hashval,function(data) {
			$tbody.empty();
			$('#breadcrumb').empty().html(renderBreadcrumbs(hashval));
			if(data.success) {
				$.each(data.results,function(k,v){
					$tbody.append(renderFileRow(v));
				});
                !data.results.length && $tbody.append('<tr><td class="empty" colspan=5>La carpeta esta vacia</td></tr>')
				data.is_writable ? $('body').removeClass('no_write') : $('body').addClass('no_write');
			} else {
				console.warn(data.error.msg);
			}
			$('#table').retablesort();
		},'json');
	}
	function renderFileRow(data) {
		var $link = $('<a class="name" />')
			.attr('href', data.is_dir ? '#' + encodeURIComponent(data.path) : './' + data.path)
			.text(data.name);
		var allow_direct_link = <?php echo $allow_direct_link?'true':'false'; ?>;
        	if (!data.is_dir && !allow_direct_link)  $link.css('pointer-events','none');
		var $dl_link = $('<a/>').attr('href','?do=download&file='+ encodeURIComponent(data.path))
			.addClass('download').text('descargar');
        var $delete_link = $('<a href="#" />').attr('data-file',data.path).addClass('delete').text('eliminar');
        var perms = [];
        if(data.is_readable) perms.push('R');
        if(data.is_writable) perms.push('W');
        if(data.is_executable) perms.push('X');
		var $html = $('<tr />')
			.addClass(data.is_dir ? 'is_dir' : '')
			.append( $('<td class="first" />').append($link) )
			.append( $('<td/>').attr('data-sort',data.is_dir ? -1 : data.size)
				.html($('<span class="size" />').text(formatFileSize(data.size))) )
			.append( $('<td/>').attr('data-sort',data.mtime).text(formatTimestamp(data.mtime)) )
            .append( $('<td/>').text(perms.join('')) )
			.append( $('<td/>').append($dl_link).append( data.is_deleteable ? $delete_link : '') )
		return $html;
	}
	function renderBreadcrumbs(path) {
		var base = encodeURIComponent("<?=$directorio_relativo_ficheros;?>"),
            $html = $('<div/>').append( $('<a href=#>Inicio</a></div>') );
            path = path.replace(encodeURIComponent('<?=$directorio_relativo_ficheros;?>'),'');
        
		$.each(path.split('%2F'),function(k,v){
			if(v) {
				var v_as_text = decodeURIComponent(v);
				$html.append( $('<span/>').text(' ▸ ') )
					.append( $('<a/>').attr('href','#'+base+v).text(v_as_text) );
				base += v + '%2F';
			}
		});
		return $html;
	}
	function formatTimestamp(unix_timestamp) {
        var m = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
		var d = new Date(unix_timestamp*1000);
		return [m[d.getMonth()],' ',d.getDate(),', ',d.getFullYear()," ",
			(d.getHours() % 12 || 12),":",(d.getMinutes() < 10 ? '0' : '')+d.getMinutes(),
			" ",d.getHours() >= 12 ? 'PM' : 'AM'].join('');
	}
	function formatFileSize(bytes) {
		var s = ['bytes', 'KB','MB','GB','TB','PB','EB'];
		for(var pos = 0;bytes >= 1000; pos++,bytes /= 1024);
		var d = Math.round(bytes*10);
		return pos ? [parseInt(d/10),".",d%10," ",s[pos]].join('') : bytes + ' bytes';
	}
})

</script>
</head><body>
<div id="top">
   <?php if($allow_create_folder): ?>
	<form action="?" method="post" id="mkdir" />
		<label for=dirname>Crear nueva carpeta</label><input id=dirname type=text name=name value="" />
        <input type="submit" value="Crear" />
	</form>

   <?php endif; ?>

   <?php if($allow_upload): ?>

	<div id="file_drop_target">
		Arrastre los archivos aquí para cargarlos
        <b>o</b>
		<input type="file" multiple />
	</div>
   <?php endif; ?>
	<div id="breadcrumb">&nbsp;</div>
</div>

<div id="upload_progress"></div>
<table id="table"><thead><tr>
	<<th>Nombre</th>
    <th>Tamaño</th>
    <th>Fecha Modificacion</th>
    <th>Permisos</th>
    <th>Acciones</th>
</tr></thead><tbody id="list">

</tbody></table>
</body></html>
