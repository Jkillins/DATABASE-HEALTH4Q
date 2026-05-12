<?php
require_once __DIR__ . '/../db.php';
$pdo = getPDO();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($action === 'list') {
    // Return list of doctors
    $stmt = $pdo->query("SELECT u.id,u.name,u.email,d.specialty,d.clinic FROM users u JOIN doctors d ON u.id=d.id WHERE u.role='doctor'");
    jsonResponse(['data'=>$stmt->fetchAll()]);
    exit;
}

if ($action === 'search_union') {
    // Union example: combine doctors and patients names in one list
    $stmt = $pdo->query("SELECT id, name, role FROM users WHERE role='doctor' UNION SELECT id, name, role FROM users WHERE role='patient' ORDER BY name");
    jsonResponse(['data'=>$stmt->fetchAll()]);
    exit;
}

if ($action === 'create') {
    $name = $_POST['name'] ?? null; $email = $_POST['email'] ?? null; $specialty = $_POST['specialty'] ?? null;
    if (!$name || !$specialty) { http_response_code(400); jsonResponse(['error'=>'name and specialty required']); exit; }
    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO users (role,name,email) VALUES (?,?,?)');
        $ins->execute(['doctor',$name,$email]);
        $id = $pdo->lastInsertId();
        $ins2 = $pdo->prepare('INSERT INTO doctors (id,specialty,clinic) VALUES (?,?,?)');
        $ins2->execute([$id,$specialty,$_POST['clinic'] ?? null]);
        $pdo->commit();
        jsonResponse(['success'=>true,'id'=>$id]);
    } catch (Exception $e) {
        $pdo->rollBack(); http_response_code(500); jsonResponse(['error'=>'create failed','message'=>$e->getMessage()]);
    }
    exit;
}

http_response_code(400); jsonResponse(['error'=>'Unknown action']);

function jsonResponse($data){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($data); }

?>
