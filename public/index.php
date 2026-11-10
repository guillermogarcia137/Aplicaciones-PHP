<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Store.php';
require dirname(__DIR__) . '/src/domain.php';
use Taller\Store;
use function Taller\{apply,csrf_valid,csv_cell,money};
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$types = ['tarea'=>'Tareas','gasto'=>'Gastos','inventario'=>'Inventario'];
$tab = is_string($_GET['tab'] ?? null) && isset($types[$_GET['tab']]) ? $_GET['tab'] : 'tarea';
$error = null; $data = ['version'=>1,'records'=>[]];
try {
    $directory = getenv('TALLER_DATA') ?: dirname(__DIR__) . '/data';
    $realPublic = realpath(__DIR__);
    $store = new Store($directory);
    $realData = realpath($directory);
    if ($realData === $realPublic || str_starts_with($realData . DIRECTORY_SEPARATOR, $realPublic . DIRECTORY_SEPARATOR)) throw new RuntimeException('Los datos deben guardarse fuera de public.');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_valid($_SESSION['csrf'], $_POST['csrf'] ?? null)) { http_response_code(403); throw new RuntimeException('La sesión ha cambiado. Recarga la página antes de guardar.'); }
        $data = $store->update(fn($value) => apply($value, $_POST));
        header('Location: /?tab=' . rawurlencode($tab) . (isset($_GET['archivados']) ? '&archivados=1' : ''), true, 303); exit;
    }
    $data = $store->read();
    if (($_GET['export'] ?? '') === 'json') {
        header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="taller-backup.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
    }
    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="taller-registros.csv"');
        $output = fopen('php://output','w'); fwrite($output,"\xEF\xBB\xBF");
        fputcsv($output,['tipo','titulo','categoria','fecha','importe','cantidad','completado','archivado'], ',', '"', '');
        foreach ($data['records'] as $row) fputcsv($output,[$row['type'],csv_cell($row['title']),csv_cell($row['category']),$row['date'],number_format($row['cents']/100,2,'.',''),$row['quantity'],$row['done']?'si':'no',$row['archived']?'si':'no'], ',', '"', '');
        fclose($output); exit;
    }
} catch (Throwable $exception) { if (http_response_code() < 400) http_response_code(400); $error = $exception->getMessage(); }
$active = array_values(array_filter($data['records'], fn($r)=>!$r['archived']));
$pending = count(array_filter($active,fn($r)=>$r['type']==='tarea'&&!$r['done']));
$total = array_sum(array_column(array_filter($active,fn($r)=>$r['type']==='gasto'),'cents'));
$units = array_sum(array_column(array_filter($active,fn($r)=>$r['type']==='inventario'),'quantity'));
$showArchived = isset($_GET['archivados']);
$rows = array_reverse(array_values(array_filter($data['records'],fn($r)=>$r['type']===$tab&&($showArchived||!$r['archived']))));
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Taller Personal · PHP</title><link rel="stylesheet" href="/style.css"></head><body>
<aside><a href="/" class="brand">T.<span>TALLER PERSONAL</span></a><p>ORGANIZA TU DÍA A DÍA</p><nav><?php foreach ($types as $type=>$name): ?><a class="<?= $type===$tab?'selected':'' ?>" href="/?tab=<?=e($type)?>"><?=e($name)?></a><?php endforeach ?></nav><div class="private">PHP · Almacenamiento local<br>Sin cuentas ni servicios externos.</div></aside>
<main><header><span>UN POCO DE ORDEN, CADA DÍA</span><div><a href="/?export=json">Copia JSON ↓</a><a href="/?export=csv">Exportar CSV ↓</a></div></header><section class="intro"><p class="eyebrow">TU ESPACIO DE TRABAJO</p><h1>Todo tiene su sitio.</h1><p>Tareas, gastos y materiales. Una vista sencilla para avanzar con calma.</p></section>
<?php if ($error): ?><div role="alert" class="error"><?=e($error)?></div><?php endif ?>
<section class="metrics"><article><span>TAREAS PENDIENTES</span><strong><?=e($pending)?></strong><small>Un paso cada vez.</small></article><article><span>GASTOS ACTIVOS</span><strong><?=e(money((int)$total))?></strong><small>Sin registros archivados.</small></article><article><span>UNIDADES EN INVENTARIO</span><strong><?=e($units)?></strong><small>Materiales bajo control.</small></article></section>
<section class="workspace"><article class="panel"><p class="eyebrow">AÑADIR UN REGISTRO</p><h2><?=e($types[$tab])?></h2><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="add"><input type="hidden" name="type" value="<?=e($tab)?>"><label for="title"><?= $tab==='tarea'?'¿Qué necesitas hacer?':($tab==='gasto'?'Concepto del gasto':'Nombre del material') ?></label><input id="title" name="title" maxlength="240" required placeholder="Un nombre claro y breve"><label for="category"><?= $tab==='tarea'?'Proyecto':'Categoría' ?></label><input id="category" name="category" maxlength="100" required placeholder="Personal, trabajo, diseño…"><label for="date">Fecha</label><input id="date" name="date" type="date" value="<?=e(date('Y-m-d'))?>" required>
<?php if ($tab==='gasto'): ?><label for="amount">Importe en euros</label><input id="amount" name="amount" inputmode="decimal" placeholder="12,50" required><?php endif ?>
<?php if ($tab==='inventario'): ?><label for="quantity">Unidades</label><input id="quantity" name="quantity" type="number" min="0" max="1000000" value="1" required><?php endif ?><button class="primary" type="submit">Guardar registro <span>+</span></button></form></article>
<article class="panel listing"><div class="list-heading"><h2><?=e($types[$tab])?> <span class="count"><?=count($rows)?></span></h2><a href="/?tab=<?=e($tab)?><?=$showArchived?'':'&amp;archivados=1'?>"><?=$showArchived?'Ocultar archivados':'Ver archivados'?></a></div>
<?php if (!$rows): ?><div class="empty"><span>↗</span><h3>Espacio para tu próxima idea.</h3><p>Añade el primer registro desde el formulario.</p></div><?php endif ?>
<?php foreach ($rows as $row): ?><div class="record <?= $row['archived']?'archived':'' ?>"><div class="record-top"><strong><?=e($row['title'])?></strong><span class="value"><?=e($tab==='gasto'?money($row['cents']):($tab==='inventario'?$row['quantity'].' uds.':($row['done']?'Hecha':'Pendiente')))?></span></div><p><?=e($row['category'])?> · <?=e($row['date'])?><?=$row['archived']?' · Archivado':''?></p><div class="record-actions">
<?php if ($tab==='tarea'): ?><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=e($row['id'])?>"><input type="hidden" name="action" value="done"><button><?=$row['done']?'Marcar pendiente':'Completar tarea'?></button></form><?php endif ?>
<form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=e($row['id'])?>"><input type="hidden" name="action" value="archive"><button><?=$row['archived']?'Restaurar':'Archivar'?></button></form></div></div><?php endforeach ?></article></section><footer>Los registros se guardan en un archivo privado del servidor. Haz copias periódicas.</footer></main></body></html>
