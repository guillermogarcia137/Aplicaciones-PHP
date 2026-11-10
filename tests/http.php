<?php
declare(strict_types=1);
// Prueba HTTP aislada: servidor en loopback y almacén desechable.
$root=dirname(__DIR__);$temp=sys_get_temp_dir().'/taller-http-'.bin2hex(random_bytes(8));mkdir($temp,0700);mkdir($temp.'/sessions',0700);
$listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$listener)throw new RuntimeException($error);
$address=stream_socket_get_name($listener,false);fclose($listener);$url='http://'.$address;
$environment=getenv();$environment['TALLER_DATA']=$temp.'/data';
$process=proc_open([PHP_BINARY,'-d','session.save_path='.$temp.'/sessions','-S',$address,'-t',$root.'/public'],[0=>['pipe','r'],1=>['file',$temp.'/server.log','a'],2=>['file',$temp.'/server.log','a']],$pipes,$root,$environment);
if(!is_resource($process))throw new RuntimeException('No se pudo iniciar PHP.');fclose($pipes[0]);
function request(string $url,string $method='GET',array $form=[],string $cookie=''):array {
    $headers="Connection: close\r\n".($cookie!==''?"Cookie: $cookie\r\n":'');
    if($method==='POST')$headers.="Content-Type: application/x-www-form-urlencoded\r\n";
    $options=['http'=>['method'=>$method,'header'=>$headers,'content'=>$method==='POST'?http_build_query($form):'','timeout'=>3,'ignore_errors'=>true,'follow_location'=>0]];
    $body=@file_get_contents($url,false,stream_context_create($options));$response=$http_response_header??[];
    preg_match('/\s([0-9]{3})\s/',$response[0]??'',$match);
    return [(int)($match[1]??0),$body===false?'':$body,$response];
}
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo "OK: $message\n";}
try {
    $response=[0,'',[]];for($i=0;$i<60;$i++){usleep(50000);$response=request($url);if($response[0]===200)break;}
    [$code,$html,$headers]=$response;check($code===200,'Inicio HTTP');
    preg_match('/PHPSESSID=([^;]+)/',implode("\n",$headers),$cookieMatch);$cookie='PHPSESSID='.($cookieMatch[1]??'');
    preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$tokenMatch);$token=$tokenMatch[1]??'';check($token!=='','Token del formulario');
    $form=['action'=>'add','type'=>'tarea','title'=>'<img src=x onerror=alert(1)>','category'=>'Pruebas','date'=>'2026-11-10','csrf'=>$token];
    check(request($url,'POST',$form,$cookie)[0]===303,'Alta con redirección');
    $page=request($url,'GET',[],$cookie)[1];check(str_contains($page,'&lt;img src=x')&&!str_contains($page,'<img src=x'),'Salida HTML escapada');
    $data=json_decode(request($url.'/?export=json','GET',[],$cookie)[1],true,512,JSON_THROW_ON_ERROR);check(count($data['records'])===1,'Exportación JSON');
    unset($form['csrf']);check(request($url,'POST',$form,$cookie)[0]===403,'Rechazo de POST sin CSRF');
    $data=json_decode(request($url.'/?export=json','GET',[],$cookie)[1],true,512,JSON_THROW_ON_ERROR);check(count($data['records'])===1,'POST inválido no altera almacén');
    $id=$data['records'][0]['id'];check(request($url,'POST',['action'=>'archive','id'=>$id,'csrf'=>$token],$cookie)[0]===303,'Archivo por HTTP');
    check(!str_contains(request($url,'GET',[],$cookie)[1],'&lt;img src=x'),'Vista activa excluye archivados');
    check(str_contains(request($url.'/?archivados=1','GET',[],$cookie)[1],'&lt;img src=x'),'Vista de archivados');
    check(request($url.'/?export=csv','GET',[],$cookie)[0]===200,'Exportación CSV');
} finally {
    proc_terminate($process);proc_close($process);
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($temp);
}
