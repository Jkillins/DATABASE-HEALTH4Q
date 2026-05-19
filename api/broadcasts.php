<?php
/**
 * api/broadcasts.php - Endpoint for incrementing broadcast view count
 */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$pdo = getPDO();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'increment_view') {
        $id = $_POST['id'] ?? null;
        if ($id) {
            try {
                $stmt = $pdo->prepare("UPDATE broadcast_message SET view_count = view_count + 1 WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'View count updated']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }
    }
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);
