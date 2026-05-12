<?php
require_once __DIR__ . '/../db.php';

$pdo = getPDO();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// Simple router
if ($action === 'list') {
    // JOIN example: list appointments with patient and doctor names
    $stmt = $pdo->query(
        "SELECT a.id,a.scheduled_at,a.status,a.notes,
                p.name AS patient_name, d.name AS doctor_name
         FROM appointments a
         JOIN users p ON a.patient_id = p.id
         JOIN users d ON a.doctor_id = d.id
         ORDER BY a.scheduled_at DESC"
    );
    $rows = $stmt->fetchAll();
    jsonResponse(['data' => $rows]);
    exit;
}

if ($action === 'create') {
    // create appointment (transaction demo)
    $patient_id = $_POST['patient_id'] ?? null;
    $doctor_id = $_POST['doctor_id'] ?? null;
    $scheduled_at = $_POST['scheduled_at'] ?? null;
    $notes = $_POST['notes'] ?? null;

    if (!$patient_id || !$doctor_id || !$scheduled_at) {
        http_response_code(400);
        jsonResponse(['error' => 'patient_id, doctor_id and scheduled_at are required']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO appointments (patient_id,doctor_id,scheduled_at,notes) VALUES (?,?,?,?)');
        $insert->execute([$patient_id,$doctor_id,$scheduled_at,$notes]);
        $apptId = $pdo->lastInsertId();

        // Log activity as part of transaction
        $log = $pdo->prepare('INSERT INTO activity_log (entity, entity_id, action) VALUES (?,?,?)');
        $log->execute(['appointment', $apptId, 'create']);

        $pdo->commit();
        jsonResponse(['success' => true, 'id' => $apptId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        jsonResponse(['error' => 'Could not create appointment', 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'update') {
    $id = $_POST['id'] ?? null;
    $status = $_POST['status'] ?? null;
    if (!$id || !$status) { http_response_code(400); jsonResponse(['error'=>'id and status required']); exit; }
    $stmt = $pdo->prepare('UPDATE appointments SET status=? WHERE id=?');
    $stmt->execute([$status,$id]);
    jsonResponse(['success'=>true]);
    exit;
}

if ($action === 'delete') {
    $id = $_POST['id'] ?? null;
    if (!$id) { http_response_code(400); jsonResponse(['error'=>'id required']); exit; }
    $stmt = $pdo->prepare('DELETE FROM appointments WHERE id=?');
    $stmt->execute([$id]);
    jsonResponse(['success'=>true]);
    exit;
}

// fallback
http_response_code(400);
jsonResponse(['error'=>'Unknown action']);

?>
