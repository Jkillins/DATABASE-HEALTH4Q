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
            $notif_id = $_POST['notif_id'] ?? null;
            if ($notif_id) {
                // Fetch the notification to check its subject and status before marking as read
                $stmtFetch = $pdo->prepare("SELECT subject, status FROM notification WHERE user_id = ? AND notif_id = ?");
                $stmtFetch->execute([$user_id, $notif_id]);
                $notif = $stmtFetch->fetch(PDO::FETCH_ASSOC);

                if ($notif && $notif['status'] === 'sent') {
                    $subject = $notif['subject'];
                    // If it is a broadcast message notification
                    if (strpos($subject, "📢 Clinic Alert: ") === 0) {
                        $title = str_replace("📢 Clinic Alert: ", "", $subject);
                        // Increment view count of the broadcast message with this title
                        $stmtView = $pdo->prepare("UPDATE broadcast_message SET view_count = view_count + 1 WHERE title = ?");
                        $stmtView->execute([$title]);
                    }
                }

                $stmt = $pdo->prepare("UPDATE notification SET status = 'read', read_at = NOW() WHERE user_id = ? AND notif_id = ?");
                $stmt->execute([$user_id, $notif_id]);
                echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
            } else {
                // If marking all as read, check all unread notifications that are broadcast alerts
                $stmtUnread = $pdo->prepare("SELECT subject FROM notification WHERE user_id = ? AND status = 'sent'");
                $stmtUnread->execute([$user_id]);
                $unreads = $stmtUnread->fetchAll(PDO::FETCH_ASSOC);
                foreach ($unreads as $u) {
                    $subject = $u['subject'];
                    if (strpos($subject, "📢 Clinic Alert: ") === 0) {
                        $title = str_replace("📢 Clinic Alert: ", "", $subject);
                        $stmtView = $pdo->prepare("UPDATE broadcast_message SET view_count = view_count + 1 WHERE title = ?");
                        $stmtView->execute([$title]);
                    }
                }

                $stmt = $pdo->prepare("UPDATE notification SET status = 'read', read_at = NOW() WHERE user_id = ? AND status = 'sent'");
                $stmt->execute([$user_id]);
                echo json_encode(['success' => true, 'message' => 'All marked as read']);
            }
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
