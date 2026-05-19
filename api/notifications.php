<?php
/**
 * api/notifications.php - Real-Time AJAX Endpoint for Clinic Notifications
 */

require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$pdo = getPDO();
$user_id = getCurrentUserId();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'mark_read') {
        try {
            $stmt = $pdo->prepare("UPDATE notification SET status = 'read', read_at = NOW() WHERE user_id = ? AND status = 'sent'");
            $stmt->execute([$user_id]);
            echo json_encode(['success' => true, 'message' => 'All marked as read']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// Default: Fetch notifications
try {
    // Get unread count
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE user_id = ? AND status = 'sent'");
    $stmtCount->execute([$user_id]);
    $unread_count = (int)$stmtCount->fetchColumn();

    // Get latest 10 notifications
    $stmtList = $pdo->prepare("SELECT notif_id, subject, body, sent_at, status FROM notification WHERE user_id = ? ORDER BY sent_at DESC LIMIT 10");
    $stmtList->execute([$user_id]);
    $list = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'unread_count' => $unread_count,
        'notifications' => $list
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
