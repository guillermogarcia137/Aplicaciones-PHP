<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Store.php'; require dirname(__DIR__) . '/src/domain.php';
use Taller\Store;
use function Taller\{apply,cents,money,csrf_valid,csv_cell};
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); echo "OK: $message\n"; }
$dir=sys_get_temp_dir().'/taller-php-'.bin2hex(random_bytes(8));
try {
    check(cents('0,10')+cents('0.20')===30,'Decimales exactos');
    check(cents('12,5')===1250 && money(1250)==='12,50 €','Formato monetario');
    foreach (['-1','1.234','NaN','1e4'] as $input) { $bad=false;try{cents($input);}catch(InvalidArgumentException){$bad=true;}check($bad,'Rechazo de importe inválido: '.$input); }
    check(csrf_valid('abc','abc')&&!csrf_valid('abc','bad')&&!csrf_valid('abc',[]),'Comparación CSRF');
    check(csv_cell('=1+1')==="'=1+1" && csv_cell('normal')==='normal','CSV neutraliza fórmulas de texto');
    $store=new Store($dir);$input=['action'=>'add','type'=>'tarea','title'=>'Diseñar','category'=>'Proyecto','date'=>'2026-11-10'];
    $data=$store->update(fn($data)=>apply($data,$input));check(count($store->read()['records'])===1,'Guardado y lectura');
    $id=$data['records'][0]['id'];$store->update(fn($data)=>apply($data,['action'=>'done','id'=>$id]));check($store->read()['records'][0]['done']===true,'Completar tarea');
    $store->update(fn($data)=>apply($data,['action'=>'archive','id'=>$id]));check($store->read()['records'][0]['archived']===true,'Archivo reversible');
    $store->update(fn($data)=>apply($data,['action'=>'archive','id'=>$id]));check($store->read()['records'][0]['archived']===false,'Restaurar registro');
    $before=file_get_contents($dir.'/datos.json');try{$store->update(fn($d)=>apply($d,['action'=>'add','type'=>'gasto','title'=>'mal','category'=>'x','amount'=>'-1']));}catch(InvalidArgumentException){}
    check(file_get_contents($dir.'/datos.json')===$before,'Una operación inválida no altera datos');
    file_put_contents($dir.'/datos.json','corrupto');$bad=false;try{$store->read();}catch(JsonException){$bad=true;}check($bad&&file_get_contents($dir.'/datos.json')==='corrupto','Conserva datos dañados para recuperación');
} finally { if (is_dir($dir)) { foreach(glob($dir.'/*') as $p) unlink($p);rmdir($dir); } }
