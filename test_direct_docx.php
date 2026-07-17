<?php
session_start();
$_SESSION['user_id'] = 1;
require_once __DIR__ . '/app/bootstrap.php';
global $pdo;
$st = $pdo->query("SELECT id FROM rfq_testata LIMIT 1");
$id = $st->fetchColumn();

if ($id) {
    $_GET['id'] = $id;
    require_once __DIR__ . '/app/Controllers/BaseController.php';
    require_once __DIR__ . '/app/Controllers/RfqController.php';
    
    $controller = new RfqController($pdo);
    $controller->docx();
} else {
    echo "No RFQ found in DB.\n";
}
