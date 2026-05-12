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
        SELECT to.test_order_id, to.status, to.ordered_at,
               tt.test_type_id, tt.name as test_name, tt.description,
               d.doctor_id, u.first_name, u.last_name,
               mr.appointment_id, a.schedule_start
        FROM test_order to
        JOIN medical_record mr ON to.record_id = mr.record_id
        JOIN test_type tt ON to.test_type_id = tt.test_type_id
        JOIN doctor d ON to.ordered_by = d.doctor_id
        JOIN users u ON d.user_id = u.user_id
        LEFT JOIN appointment a ON mr.appointment_id = a.appointment_id
        WHERE mr.patient_id = ?
        ORDER BY to.ordered_at DESC
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
    $error = $e->getMessage();
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Quicksand', sans-serif;
        }

        body {
            background: var(--light-bg);
            color: var(--text-dark);
        }

        .top-nav {
            background: var(--primary-green);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .nav-brand {
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-links {
            display: flex;
            gap: 20px;
        }

        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 4px;
            transition: 0.3s;
            font-size: 14px;
            font-weight: 500;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: var(--accent-green);
        }

        .logout-btn {
            background: #dc3545 !important;
            padding: 8px 20px !important;
            border-radius: 4px;
            cursor: pointer;
        }

        .logout-btn:hover {
            background: #c82333 !important;
        }

        .container {
            max-width: 1000px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 28px;
            color: var(--primary-green);
        }

        .back-btn {
            background: var(--primary-green);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 500;
            transition: 0.3s;
        }

        .back-btn:hover {
            background: var(--accent-green);
        }

        .filters {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 8px 16px;
            border: 1px solid var(--border-color);
            background: white;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            transition: 0.3s;
        }

        .filter-btn.active {
            background: var(--primary-green);
            color: white;
            border-color: var(--primary-green);
        }

        .filter-btn:hover {
            border-color: var(--primary-green);
        }

        .tests-list {
            display: grid;
            gap: 20px;
        }

        .test-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            border-left: 4px solid var(--primary-green);
        }

        .test-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .test-name {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 16px;
        }

        .test-type {
            font-size: 13px;
            color: var(--text-light);
            margin-top: 4px;
        }

        .test-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-ordered {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-canceled {
            background: #f8d7da;
            color: #721c24;
        }

        .test-details {
            margin-bottom: 15px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            font-size: 14px;
            border-bottom: 1px solid #f0f0f0;
        }

        .detail-label {
            font-weight: 600;
            color: var(--primary-green);
            min-width: 120px;
        }

        .detail-value {
            color: var(--text-dark);
        }

        .test-description {
            background: #f9f9f9;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-size: 13px;
            color: var(--text-light);
            line-height: 1.5;
        }

        .result-section {
            background: #fffbea;
            padding: 15px;
            border-radius: 4px;
            border-left: 4px solid #ffc107;
            margin-bottom: 15px;
        }

        .result-title {
            font-weight: 600;
            color: #856404;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .result-value {
            background: white;
            padding: 12px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            color: var(--text-dark);
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .findings {
            background: #e8f4f8;
            padding: 15px;
            border-radius: 4px;
            border-left: 4px solid #17a2b8;
            margin-bottom: 15px;
        }

        .findings-title {
            font-weight: 600;
            color: #0c5460;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .findings-text {
            color: #0c5460;
            font-size: 13px;
            line-height: 1.6;
        }

        .test-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            font-size: 13px;
            transition: 0.3s;
        }

        .btn-download {
            background: var(--primary-green);
            color: white;
        }

        .btn-download:hover {
            background: var(--accent-green);
        }

        .btn-print {
            background: var(--border-color);
            color: var(--text-dark);
        }

        .btn-print:hover {
            background: var(--primary-green);
            color: white;
        }

        .btn-disabled {
            background: #e9ecef;
            color: #6c757d;
            cursor: not-allowed;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 8px;
        }

        .empty-icon {
            font-size: 60px;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            color: var(--text-dark);
            margin-bottom: 10px;
            font-size: 20px;
        }

        .empty-state p {
            color: var(--text-light);
            margin-bottom: 20px;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }

            .test-header {
                flex-direction: column;
                gap: 10px;
            }

            .detail-row {
                flex-direction: column;
                gap: 5px;
            }

            .test-actions {
                flex-direction: column;
            }

            .action-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand">
            <span>🧬</span> Lab Results
        </div>
        <div class="nav-links">
            <a href="patient-dashboard.php">Dashboard</a>
            <a href="patientprofile.php">Profile</a>
            <a href="patientappoint.php">Appointments</a>
            <a href="patient-prescriptions.php">Prescriptions</a>
            <a href="patient-vitals.php">Vital Signs</a>
            <a href="patient-allergies.php">Allergies</a>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="header">
            <h1>Lab Test Results</h1>
            <a href="patient-dashboard.php" class="back-btn">← Back to Dashboard</a>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="filters">
            <button class="filter-btn active" onclick="filterTests('all')">All Tests</button>
            <button class="filter-btn" onclick="filterTests('ordered')">Pending</button>
            <button class="filter-btn" onclick="filterTests('completed')">Completed</button>
            <button class="filter-btn" onclick="filterTests('canceled')">Canceled</button>
        </div>

        <?php if (count($test_orders) > 0): ?>
            <div class="tests-list">
                <?php foreach ($test_orders as $order): ?>
                    <div class="test-card" data-status="<?php echo htmlspecialchars($order['status']); ?>">
                        <div class="test-header">
                            <div>
                                <div class="test-name">
                                    🧪 <?php echo htmlspecialchars($order['test_name']); ?>
                                </div>
                                <div class="test-type">
                                    Ordered by: Dr. <?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?>
                                </div>
                            </div>
                            <div class="test-status status-<?php echo htmlspecialchars($order['status']); ?>">
                                <?php echo ucfirst(htmlspecialchars($order['status'])); ?>
                            </div>
                        </div>

                        <div class="test-details">
                            <div class="detail-row">
                                <span class="detail-label">Ordered Date:</span>
                                <span class="detail-value"><?php echo date('M d, Y @ h:i A', strtotime($order['ordered_at'])); ?></span>
                            </div>
                        </div>

                        <?php if ($order['description']): ?>
                            <div class="test-description">
                                <?php echo htmlspecialchars($order['description']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($test_results[$order['test_order_id']]) && $test_results[$order['test_order_id']]): ?>
                            <?php $result = $test_results[$order['test_order_id']]; ?>
                            
                            <div class="detail-row">
                                <span class="detail-label">Result Date:</span>
                                <span class="detail-value"><?php echo date('M d, Y @ h:i A', strtotime($result['date_time'])); ?></span>
                            </div>

                            <?php if ($result['result']): ?>
                                <div class="result-section">
                                    <div class="result-title">📊 Test Results</div>
                                    <div class="result-value">
                                        <?php echo nl2br(htmlspecialchars($result['result'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($result['findings']): ?>
                                <div class="findings">
                                    <div class="findings-title">🔍 Doctor's Findings</div>
                                    <div class="findings-text">
                                        <?php echo nl2br(htmlspecialchars($result['findings'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="test-actions">
                                <button class="action-btn btn-download" onclick="downloadResult(<?php echo $order['test_order_id']; ?>)">
                                    📥 Download PDF
                                </button>
                                <button class="action-btn btn-print" onclick="printResult(<?php echo $order['test_order_id']; ?>)">
                                    🖨️ Print
                                </button>
                            </div>

                        <?php else: ?>
                            <div class="test-actions">
                                <button class="action-btn btn-disabled" disabled>
                                    ⏳ Results Pending
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">🧬</div>
                <h3>No Lab Tests</h3>
                <p>You don't have any lab tests ordered yet. Once your doctor orders a test, it will appear here with results when available.</p>
                <a href="patientappoint.php">Schedule an Appointment →</a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function filterTests(status) {
            const cards = document.querySelectorAll('.test-card');
            const buttons = document.querySelectorAll('.filter-btn');

            // Update active button
            buttons.forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');

            // Filter cards
            cards.forEach(card => {
                if (status === 'all' || card.dataset.status === status) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function downloadResult(testOrderId) {
            alert('Download functionality will generate PDF for test order #' + testOrderId);
            // TODO: Implement PDF generation
            // window.location.href = 'generate-pdf.php?type=lab_result&id=' + testOrderId;
        }

        function printResult(testOrderId) {
            alert('Print functionality for test order #' + testOrderId);
            // TODO: Implement print functionality
        }
    </script>

</body>
</html>
