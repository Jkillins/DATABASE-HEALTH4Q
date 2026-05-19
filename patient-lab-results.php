<?php
/**
 * patient-lab-results.php - Premium Forest Green Patient Lab Results Hub
 */
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$user_id = $_SESSION['user_id'];
$error = '';

try {
    // 1. Get Patient ID
    $stmt = $pdo->prepare('SELECT patient_id FROM patient WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $patient_id = $stmt->fetchColumn();

    if ($patient_id) {
        // 2. Fetch Lab Test Orders 
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
        $test_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Fetch Test Results
        $test_results = [];
        foreach ($test_orders as $order) {
            $stmt = $pdo->prepare("
                SELECT * FROM test_result
                WHERE test_order_id = ?
            ");
            $stmt->execute([$order['test_order_id']]);
            $test_results[$order['test_order_id']] = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } else {
        $test_orders = [];
    }
} catch (Exception $e) {
    $error = "Database Error: " . $e->getMessage();
    $test_orders = [];
}

// Calculate status counts
$count_all = count($test_orders);
$count_pending = 0;
$count_completed = 0;
$count_canceled = 0;
foreach ($test_orders as $o) {
    if ($o['status'] === 'ordered') $count_pending++;
    elseif ($o['status'] === 'completed') $count_completed++;
    elseif ($o['status'] === 'canceled') $count_canceled++;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Results | Health4Q</title>
    <link rel="icon" type="image/png" href="images/Logo_only.png">
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-green: #1a4d34; 
            --light-bg: #c5e6e1;    
            --white: #ffffff;
            --accent-green: #2d6a4f;
            --text-dark: #1b4332;
            --border-color: #e2e8f0;
            --primary-blue: #0288d1;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Quicksand', sans-serif; }

        body {
            background: radial-gradient(circle at center, #d8f3dc 0%, var(--light-bg) 100%);
            min-height: 100vh;
            color: var(--text-dark);
            display: flex;
            flex-direction: column;
        }

        /* --- NAVIGATION --- */
        .top-nav {
            background: var(--primary-green);
            padding: 12px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }

        .nav-links { display: flex; gap: 15px; }
        .nav-links a {
            color: white;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            padding: 8px 15px;
            border-radius: 8px;
            background: rgba(255,255,255,0.1);
            transition: 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .nav-links a:hover, .nav-links a.active { background: var(--accent-green); }

        .logout-btn {
            background: #d90429;
            color: white;
            padding: 8px 18px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            font-size: 12px;
            transition: 0.3s;
        }
        .logout-btn:hover { background: #b00220; }

        /* --- CONTAINER --- */
        .container { max-width: 1000px; margin: 40px auto; padding: 0 20px; flex: 1; }

        /* --- HEADER --- */
        .header-section {
            background: var(--white);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .header-title-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-title-row h1 {
            color: var(--primary-green);
            font-size: 1.8rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* --- FILTERS --- */
        .filters-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            border-top: 1.5px solid var(--border-color);
            padding-top: 20px;
        }

        .filter-chip {
            padding: 8px 18px;
            border: 1.5px solid var(--border-color);
            background: white;
            border-radius: 30px;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.85rem;
            color: var(--text-dark);
            transition: 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-chip span.badge {
            background: #f1f5f9;
            color: #64748b;
            padding: 2px 7px;
            border-radius: 20px;
            font-size: 10px;
        }

        .filter-chip.active {
            background: var(--primary-green);
            color: white;
            border-color: var(--primary-green);
        }

        .filter-chip.active span.badge {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        /* --- LAB CARDS --- */
        .lab-card {
            background: var(--white);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            border-left: 6px solid var(--primary-green);
            transition: 0.3s transform;
        }
        .lab-card:hover {
            transform: translateY(-2px);
        }

        .lab-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1.5px solid var(--border-color);
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .test-badge {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .test-badge .avatar-icon {
            width: 48px;
            height: 48px;
            background: #e6f7f4;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: var(--accent-green);
        }

        .test-info h3 {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--primary-green);
        }

        .test-info p {
            font-size: 0.8rem;
            color: #64748b;
            font-weight: 600;
            margin-top: 2px;
        }

        .status-pill {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-ordered { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .status-completed { background: #e6f9f0; color: #16a34a; border: 1px solid #bbf7d0; }
        .status-canceled { background: #fef2f2; color: #b91c1c; border: 1px solid #fee2e2; }

        /* --- DETAILS BLOCK --- */
        .detail-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            background: #f8fafc;
            padding: 15px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .detail-item span.label {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
        }

        .detail-item span.val {
            color: var(--text-dark);
        }

        /* --- RESULTS VIEWER --- */
        .results-block {
            background: #fafaf9;
            border: 1px solid #e7e5e4;
            border-left: 5px solid var(--accent-green);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
        }

        .results-block h4 {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--primary-green);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .results-console {
            background: #ffffff;
            border: 1px solid #e7e5e4;
            border-radius: 8px;
            padding: 15px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.9rem;
            font-weight: 700;
            color: #1c1917;
            white-space: pre-wrap;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);
            line-height: 1.5;
        }

        /* --- FINDINGS VIEWER --- */
        .findings-block {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-left: 5px solid var(--primary-blue);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
        }

        .findings-block h4 {
            font-size: 0.95rem;
            font-weight: 800;
            color: #0369a1;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .findings-text {
            font-size: 0.85rem;
            line-height: 1.6;
            color: #0c4a6e;
            font-weight: 600;
        }

        /* --- ACTIONS --- */
        .action-row {
            display: flex;
            justify-content: flex-end;
            margin-top: 15px;
        }

        .print-btn {
            background: var(--primary-green);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: 0.3s;
        }
        .print-btn:hover {
            background: var(--accent-green);
        }

        .pending-box {
            background: #fffbeb;
            border: 1px solid #fef08a;
            border-left: 5px solid #eab308;
            color: #713f12;
            padding: 15px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .canceled-box {
            background: #fef2f2;
            border: 1px solid #fee2e2;
            border-left: 5px solid #ef4444;
            color: #991b1b;
            padding: 15px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fee2e2;
            color: #b91c1c;
            padding: 15px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 25px;
        }

        /* --- RESPONSIVE --- */
        @media (max-width: 768px) {
            .header-title-row { flex-direction: column; align-items: flex-start; gap: 15px; }
            .filters-row { flex-direction: column; }
            .filter-chip { width: 100%; justify-content: space-between; }
        }

        /* --- PRINT STYLES --- */
        @media print {
            body { background: white; color: black; }
            .top-nav, .header-section, .print-btn, .action-row { display: none !important; }
            .container { max-width: 100%; margin: 0; padding: 0; }
            .lab-card { border: 1px solid #000; box-shadow: none; page-break-inside: avoid; }
        }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>
        <div class="nav-links">
            <a href="patient-dashboard.php">🏠 Dashboard</a>
            <a href="patientprofile.php">👤 Profile</a>
            <a href="patientappoint.php">📅 Appointments</a>
            <a href="patient-prescriptions.php">💊 Prescriptions</a>
            <a href="patient-lab-results.php" class="active">🧪 Lab Results</a>
            <a href="patientmedhist.php">📜 History</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </nav>

    <div class="container">
        
        <div class="header-section">
            <div class="header-title-row">
                <h1>🧪 My Laboratory Records</h1>
                <button onclick="window.print()" class="print-btn">
                    <i class="fa-solid fa-print"></i> Print All Logs
                </button>
            </div>
            
            <div class="filters-row">
                <button class="filter-chip active" id="btn-all" onclick="filterLabCards('all')">
                    <i class="fa-solid fa-layer-group"></i> All Test Orders
                    <span class="badge"><?php echo $count_all; ?></span>
                </button>
                <button class="filter-chip" id="btn-ordered" onclick="filterLabCards('ordered')">
                    <i class="fa-regular fa-clock"></i> Pending Results
                    <span class="badge"><?php echo $count_pending; ?></span>
                </button>
                <button class="filter-chip" id="btn-completed" onclick="filterLabCards('completed')">
                    <i class="fa-regular fa-circle-check"></i> Completed
                    <span class="badge"><?php echo $count_completed; ?></span>
                </button>
                <button class="filter-chip" id="btn-canceled" onclick="filterLabCards('canceled')">
                    <i class="fa-solid fa-ban"></i> Canceled
                    <span class="badge"><?php echo $count_canceled; ?></span>
                </button>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert-error">
                <i class="fa-solid fa-circle-exclamation"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <div id="labCardsContainer">
            <?php if (!empty($test_orders)): ?>
                <?php foreach ($test_orders as $order): ?>
                    <div class="lab-card" data-status="<?php echo htmlspecialchars(strtolower($order['status'])); ?>">
                        
                        <div class="lab-header">
                            <div class="test-badge">
                                <div class="avatar-icon">
                                    <i class="fa-solid fa-flask-vial"></i>
                                </div>
                                <div class="test-info">
                                    <h3><?php echo htmlspecialchars($order['test_name']); ?></h3>
                                    <p><i class="fa-solid fa-user-doctor"></i> Prescribing MD: Dr. <?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></p>
                                </div>
                            </div>
                            
                            <span class="status-pill status-<?php echo strtolower($order['status']); ?>">
                                <?php echo htmlspecialchars($order['status'] === 'ordered' ? 'Pending' : $order['status']); ?>
                            </span>
                        </div>

                        <div class="detail-row">
                            <div class="detail-item">
                                <span class="label">Date Ordered</span>
                                <span class="val">📅 <?php echo date('F d, Y', strtotime($order['ordered_at'])); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="label">Requisition ID</span>
                                <span class="val">🔢 REQ-<?php echo str_pad($order['test_order_id'], 6, '0', STR_PAD_LEFT); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="label">Test Description</span>
                                <span class="val">📝 <?php echo htmlspecialchars($order['description'] ?: 'Diagnostic laboratory examination panel.'); ?></span>
                            </div>
                        </div>

                        <?php if (isset($test_results[$order['test_order_id']]) && $test_results[$order['test_order_id']]): 
                            $res = $test_results[$order['test_order_id']]; ?>
                            
                            <div class="results-block">
                                <h4><i class="fa-solid fa-clipboard-list"></i> Laboratory Test Metrics & Values:</h4>
                                <div class="results-console"><?php echo htmlspecialchars($res['result']); ?></div>
                            </div>

                            <?php if (!empty($res['findings'])): ?>
                                <div class="findings-block">
                                    <h4><i class="fa-solid fa-stethoscope"></i> Physician Clinical Diagnosis:</h4>
                                    <p class="findings-text"><?php echo nl2br(htmlspecialchars($res['findings'])); ?></p>
                                </div>
                            <?php endif; ?>

                            <div class="action-row">
                                <button onclick="window.print()" class="print-btn">
                                    <i class="fa-solid fa-print"></i> Print Report
                                </button>
                            </div>

                        <?php elseif ($order['status'] === 'canceled'): ?>
                            <div class="canceled-box">
                                <i class="fa-solid fa-ban"></i>
                                <span><strong>Requisition Terminated:</strong> This laboratory test order was formally canceled by the laboratory administrator or the prescribing physician.</span>
                            </div>

                        <?php else: ?>
                            <div class="pending-box">
                                <i class="fa-solid fa-hourglass-half"></i>
                                <span><strong>Pending Lab Processing:</strong> This test specimen is currently undergoing active clinical processing in our diagnostics laboratory. Results will update here instantly once cleared.</span>
                            </div>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="text-align: center; padding: 50px 20px; background: white; border-radius: 20px; border: 1.5px dashed var(--border-color); box-shadow: 0 10px 25px rgba(0,0,0,0.05);">
                    <span style="font-size: 3rem; display: block; margin-bottom: 12px;">🧪</span>
                    <strong style="font-size: 1.1rem; color: var(--primary-green); display: block;">No Laboratory Logs Found</strong>
                    <p style="color: #64748b; font-size: 0.85rem; margin-top: 5px; font-weight: 500;">You currently do not have any laboratory test requisitions registered.</p>
                </div>
            <?php endif; ?>
        </div>

        <div id="noResults" style="display: none; text-align: center; padding: 50px 20px; background: white; border-radius: 20px; border: 1.5px dashed var(--border-color); box-shadow: 0 10px 25px rgba(0,0,0,0.05);">
            <span style="font-size: 3rem; display: block; margin-bottom: 12px;">🔍</span>
            <strong style="font-size: 1.1rem; color: var(--primary-green); display: block;">No Matching Records Found</strong>
            <p style="color: #64748b; font-size: 0.85rem; margin-top: 5px; font-weight: 500;">We couldn't find any lab results matching this category.</p>
        </div>

    </div>

    <script>
        function filterLabCards(status) {
            // Manage Active Class
            document.querySelectorAll('.filter-chip').forEach(chip => chip.classList.remove('active'));
            document.getElementById('btn-' + status).classList.add('active');

            const cards = document.querySelectorAll('.lab-card');
            const noResults = document.getElementById('noResults');
            let visibleCount = 0;

            cards.forEach(card => {
                const cardStatus = card.getAttribute('data-status');
                const matches = (status === 'all') || (cardStatus === status);

                if (matches) {
                    card.style.display = 'block';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            if (visibleCount === 0 && cards.length > 0) {
                noResults.style.display = 'block';
            } else {
                noResults.style.display = 'none';
            }
        }
    </script>
</body>
</html>