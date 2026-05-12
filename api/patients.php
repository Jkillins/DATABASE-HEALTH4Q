<?php
require_once __DIR__ . '/../db.php';
$pdo = getPDO();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($action === 'list') {
    // Subquery example: latest appointment per patient
    $stmt = $pdo->query(
        "SELECT u.id,u.name,u.email,
           (SELECT MAX(scheduled_at) FROM appointments a WHERE a.patient_id = u.id) AS last_appointment
         FROM users u
         WHERE u.role = 'patient'"
    );
    jsonResponse(['data' => $stmt->fetchAll()]);
    exit;
}

if ($action === 'create') {
    $name = $_POST['name'] ?? null;
    $email = $_POST['email'] ?? null;
    if (!$name) { http_response_code(400); jsonResponse(['error'=>'name required']); exit; }

    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO users (role,name,email) VALUES (?,?,?)');
        $ins->execute(['patient',$name,$email]);
        $id = $pdo->lastInsertId();
        $ins2 = $pdo->prepare('INSERT INTO patients (id) VALUES (?)');
        $ins2->execute([$id]);
        $pdo->commit();
        jsonResponse(['success'=>true,'id'=>$id]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        jsonResponse(['error'=>'create failed','message'=>$e->getMessage()]);
    }
    exit;
}

if ($action === 'read') {
    $id = $_GET['id'] ?? null; if (!$id) { http_response_code(400); jsonResponse(['error'=>'id required']); exit; }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id=? AND role="patient"');
    $stmt->execute([$id]);
    jsonResponse($stmt->fetch());
    exit;
}

if ($action === 'delete') {
    $id = $_POST['id'] ?? null; if (!$id) { http_response_code(400); jsonResponse(['error'=>'id required']); exit; }
    $stmt = $pdo->prepare('DELETE FROM users WHERE id=?');
    $stmt->execute([$id]);
    jsonResponse(['success'=>true]);
    exit;
}

http_response_code(400); jsonResponse(['error'=>'Unknown action']);

function jsonResponse($data){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($data); }

?>
