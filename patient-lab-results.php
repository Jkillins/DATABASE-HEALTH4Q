<?php
/**
 * patient-lab-results.php
 * Patient Lab Test Results Tracking
 */
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$user_id = $_SESSION['user_id'];

try {
    // Get Patient ID
    $stmt = $pdo->prepare('SELECT patient_id FROM patient WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $patient_id = $stmt->fetchColumn();

    // Fetch Lab Test Orders 
    $stmt = $pdo->prepare("
        SELECT lab_order.test_order_id, lab_order.status, lab_order.ordered_at,
               tt.test_type_id, tt.name as test_name, tt.description,
               d.doctor_id, u.first_name, u.last_name,
               mr.appointment_id, a.schedule_start
        FROM test_order lab_order
        JOIN medical_record mr ON lab_order.record_id = mr.record_id
        JOIN test_type tt ON lab_order.test_type_id = tt.test_type_id
        JOIN doctor d ON lab_order.ordered_by = d.doctor_id
        JOIN users u ON d.user_id = u.user_id
        LEFT JOIN appointment a ON mr.appointment_id = a.appointment_id
        WHERE mr.patient_id = ?
        ORDER BY lab_order.ordered_at DESC
    ");
    $stmt->execute([$patient_id]);
    $test_orders = $stmt->fetchAll();

    // Fetch Test Results
    $test_results = [];
    foreach ($test_orders as $order) {
        $stmt = $pdo->prepare("
            SELECT * FROM test_result
            WHERE test_order_id = ?
        ");
        $stmt->execute([$order['test_order_id']]);
        $test_results[$order['test_order_id']] = $stmt->fetch();
    }

} catch (Exception $e) {
    $error = "Database Error: " . $e->getMessage();
    $test_orders = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Results | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #2d7a6a;
            --accent-green: #3ba89f;
            --light-bg: #f0f4f3;
            --white: #ffffff;
            --text-dark: #1a2332;
            --text-light: #666666;
            --border-color: #e0e6e5;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Quicksand', sans-serif; }
        body { background: var(--light-bg); color: var(--text-dark); }

        .top-nav {
            background: var(--primary-green);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .nav-brand { font-size: 20px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .nav-links { display: flex; gap: 20px; }
        .nav-links a { color: white; text-decoration: none; padding: 8px 15px; border-radius: 4px; transition: 0.3s; font-size: 14px; font-weight: 500; }
        .nav-links a:hover, .nav-links a.active { background: var(--accent-green); }
        .logout-btn { background: #dc3545 !important; padding: 8px 20px !important; border-radius: 4px; cursor: pointer; }

        .container { max-width: 1000px; margin: 30px auto; padding: 0 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .header h1 { font-size: 28px; color: var(--primary-green); }
        .back-btn { background: var(--primary-green); color: white; padding: 10px 20px; border-radius: 4px; text-decoration: none; font-weight: 500; transition: 0.3s; }

        .filters { display: flex; gap: 15px; margin-bottom: 25px; flex-wrap: wrap; }
        .filter-btn { padding: 8px 16px; border: 1px solid var(--border-color); background: white; border-radius: 4px; cursor: pointer; font-weight: 500; transition: 0.3s; }
        .filter-btn.active { background: var(--primary-green); color: white; border-color: var(--primary-green); }

        .tests-list { display: grid; gap: 20px; }
        .test-card { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08); border-left: 4px solid var(--primary-green); }
        .test-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid var(--border-color); }
        .test-name { font-weight: 600; color: var(--text-dark); font-size: 16px; }
        .test-status { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; }

        .status-ordered { background: #d1ecf1; color: #0c5460; }
        .status-completed { background: #d4edda; color: #155724; }
        /* ADDED CANCELED STYLE */
        .status-canceled { background: #f8d7da; color: #721c24; }

        .detail-row { display: flex; justify-content: space-between; padding: 10px 0; font-size: 14px; border-bottom: 1px solid #f0f0f0; }
        .detail-label { font-weight: 600; color: var(--primary-green); }
        .result-section { background: #fffbea; padding: 15px; border-radius: 4px; border-left: 4px solid #ffc107; margin: 15px 0; }
        .result-value { background: white; padding: 12px; border-radius: 4px; font-family: 'Courier New', monospace; white-space: pre-wrap; }
        .findings { background: #e8f4f8; padding: 15px; border-radius: 4px; border-left: 4px solid #17a2b8; margin-bottom: 15px; }

        .action-btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-weight: 500; font-size: 13px; }
        .btn-download { background: var(--primary-green); color: white; }
        .btn-disabled { background: #e9ecef; color: #6c757d; cursor: not-allowed; }

        @media (max-width: 768px) {
            .header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .detail-row { flex-direction: column; }
        }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand"><span>🧬</span> Lab Results</div>
    <div class="nav-links">
        <a href="patient-dashboard.php">Dashboard</a>
        <a href="patientprofile.php">My Profile</a>
        <a href="patientappoint.php">Appointments</a>
        <a href="patientmedhist.php" class="active">Medical History</a>
        <a href="logout.php" style="color: #ff9999;">Logout</a>
    </div>
    </nav>

    <div class="container">

        <?php if (isset($error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="filters">
            <button class="filter-btn active" onclick="filterTests('all')">All Tests</button>
            <button class="filter-btn" onclick="filterTests('ordered')">Pending</button>
            <button class="filter-btn" onclick="filterTests('completed')">Completed</button>
            <!-- ADDED CANCELED FILTER BUTTON -->
            <button class="filter-btn" onclick="filterTests('canceled')">Canceled</button>
        </div>

        <?php if (count($test_orders) > 0): ?>
            <div class="tests-list">
                <?php foreach ($test_orders as $order): ?>
                    <div class="test-card" data-status="<?php echo htmlspecialchars($order['status']); ?>">
                        <div class="test-header">
                            <div>
                                <div class="test-name">🧪 <?php echo htmlspecialchars($order['test_name']); ?></div>
                                <div style="font-size: 13px; color: #666;">
                                    Ordered by: Dr. <?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?>
                                </div>
                            </div>
                            <div class="test-status status-<?php echo htmlspecialchars($order['status']); ?>">
                                <?php echo ucfirst(htmlspecialchars($order['status'])); ?>
                            </div>
                        </div>

                        <div class="detail-row">
                            <span class="detail-label">Ordered Date:</span>
                            <span><?php echo date('M d, Y', strtotime($order['ordered_at'])); ?></span>
                        </div>

                        <?php if (isset($test_results[$order['test_order_id']]) && $test_results[$order['test_order_id']]): 
                            $result = $test_results[$order['test_order_id']]; ?>
                            
                            <div class="result-section">
                                <div style="font-weight:600; margin-bottom:8px;">📊 Test Results</div>
                                <div class="result-value"><?php echo nl2br(htmlspecialchars($result['result'])); ?></div>
                            </div>

                            <?php if ($result['findings']): ?>
                                <div class="findings">
                                    <div style="font-weight:600; margin-bottom:8px;">🔍 Doctor's Findings</div>
                                    <div style="font-size: 13px;"><?php echo nl2br(htmlspecialchars($result['findings'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <button class="action-btn btn-download" onclick="window.print()">🖨️ Print Results</button>

                        <!-- ADDED CANCELED LOGIC -->
                        <?php elseif ($order['status'] === 'canceled'): ?>
                            <div style="margin-top: 15px; padding: 12px; background: #fff5f5; border: 1px solid #fed7d7; border-radius: 4px; color: #c53030; font-size: 13px;">
                                <strong>Request Canceled:</strong> This test order was canceled by the laboratory or physician.
                            </div>

                        <?php else: ?>
                            <div style="margin-top: 15px;">
                                <button class="action-btn btn-disabled" disabled>⏳ Results Pending</button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="text-align:center; padding: 50px; background:white; border-radius:8px;">
                <h3>No Lab Tests Found</h3>
                <p>Your test history will appear here once orders are placed.</p>
            </div>
         <?php endif; ?>
    </div>

    <script>
        function filterTests(status) {
            document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
            // Use currentTarget or specific selection to avoid breaking the UI event flow
            if(event) event.target.classList.add('active');

            document.querySelectorAll('.test-card').forEach(card => {
                card.style.display = (status === 'all' || card.dataset.status === status) ? 'block' : 'none';
            });
        }
    </script>
</body>
</html>