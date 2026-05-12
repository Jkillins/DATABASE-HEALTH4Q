<?php
/**
 * patient-prescriptions.php
 * Patient Prescription Management & Viewing
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

    // Fetch Active Prescriptions
    $stmt = $pdo->prepare("
        SELECT p.prescription_id, p.issued_at, p.notes, p.status,
               d.doctor_id, u.first_name, u.last_name,
               GROUP_CONCAT(CONCAT(m.name, ' - ', pi.dose) SEPARATOR ', ') as medications
        FROM prescription p
        JOIN medical_record mr ON p.record_id = mr.record_id
        JOIN doctor d ON p.doctor_id = d.doctor_id
        JOIN users u ON d.user_id = u.user_id
        LEFT JOIN prescription_item pi ON p.prescription_id = pi.prescription_id
        LEFT JOIN medicine m ON pi.med_id = m.med_id
        WHERE mr.patient_id = ?
        GROUP BY p.prescription_id
        ORDER BY p.issued_at DESC
    ");
    $stmt->execute([$patient_id]);
    $prescriptions = $stmt->fetchAll();

    // Fetch prescription items in detail
    $prescription_items = [];
    foreach ($prescriptions as $rx) {
        $stmt = $pdo->prepare("
            SELECT pi.line_no, m.name, m.strength, m.form, pi.dose, pi.frequency, pi.duration, pi.instructions
            FROM prescription_item pi
            JOIN medicine m ON pi.med_id = m.med_id
            WHERE pi.prescription_id = ?
            ORDER BY pi.line_no
        ");
        $stmt->execute([$rx['prescription_id']]);
        $prescription_items[$rx['prescription_id']] = $stmt->fetchAll();
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    $prescriptions = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Prescriptions | Health4Q</title>
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

        .prescriptions-list {
            display: grid;
            gap: 20px;
        }

        .prescription-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            border-left: 4px solid var(--primary-green);
        }

        .rx-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .rx-doctor {
            display: flex;
            flex-direction: column;
        }

        .rx-doctor-name {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 16px;
        }

        .rx-doctor-date {
            font-size: 13px;
            color: var(--text-light);
            margin-top: 4px;
        }

        .rx-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .status-expired {
            background: #e2e3e5;
            color: #383d41;
        }

        .rx-medications {
            margin-bottom: 15px;
        }

        .rx-medications h4 {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 10px;
        }

        .medication-item {
            background: #f9f9f9;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 10px;
            border-left: 3px solid var(--accent-green);
        }

        .med-name {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 6px;
        }

        .med-details {
            font-size: 13px;
            color: var(--text-light);
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .med-detail {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .med-label {
            font-weight: 500;
            color: var(--primary-green);
        }

        .rx-notes {
            background: #fffbea;
            padding: 12px;
            border-radius: 4px;
            border-left: 3px solid #ffc107;
            margin-bottom: 15px;
            font-size: 13px;
            color: var(--text-dark);
        }

        .rx-actions {
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

        .empty-state a {
            color: var(--primary-green);
            text-decoration: none;
            font-weight: 600;
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

            .rx-header {
                flex-direction: column;
                gap: 10px;
            }

            .med-details {
                grid-template-columns: 1fr;
            }

            .rx-actions {
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
            <span>💊</span> My Prescriptions
        </div>
        <div class="nav-links">
            <a href="patient-dashboard.php">Dashboard</a>
            <a href="patientprofile.php">Profile</a>
            <a href="patientappoint.php">Appointments</a>
            <a href="patient-vitals.php">Vital Signs</a>
            <a href="patient-allergies.php">Allergies</a>
            <a href="patient-lab-results.php">Lab Results</a>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="header">
            <h1>My Prescriptions</h1>
            <a href="patient-dashboard.php" class="back-btn">← Back to Dashboard</a>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="filters">
            <button class="filter-btn active" onclick="filterPrescriptions('all')">All Prescriptions</button>
            <button class="filter-btn" onclick="filterPrescriptions('active')">Active</button>
            <button class="filter-btn" onclick="filterPrescriptions('inactive')">Inactive</button>
            <button class="filter-btn" onclick="filterPrescriptions('expired')">Expired</button>
        </div>

        <?php if (count($prescriptions) > 0): ?>
            <div class="prescriptions-list">
                <?php foreach ($prescriptions as $rx): ?>
                    <div class="prescription-card" data-status="<?php echo htmlspecialchars($rx['status']); ?>">
                        <div class="rx-header">
                            <div class="rx-doctor">
                                <div class="rx-doctor-name">
                                    Dr. <?php echo htmlspecialchars($rx['first_name'] . ' ' . $rx['last_name']); ?>
                                </div>
                                <div class="rx-doctor-date">
                                    Issued: <?php echo date('M d, Y', strtotime($rx['issued_at'])); ?>
                                </div>
                            </div>
                            <div class="rx-status status-<?php echo htmlspecialchars($rx['status']); ?>">
                                <?php echo ucfirst(htmlspecialchars($rx['status'])); ?>
                            </div>
                        </div>

                        <?php if (isset($prescription_items[$rx['prescription_id']]) && count($prescription_items[$rx['prescription_id']]) > 0): ?>
                            <div class="rx-medications">
                                <h4>📋 Medications</h4>
                                <?php foreach ($prescription_items[$rx['prescription_id']] as $item): ?>
                                    <div class="medication-item">
                                        <div class="med-name">
                                            <?php echo htmlspecialchars($item['name']); ?>
                                            <?php if ($item['strength']): ?>
                                                <span style="color: var(--text-light); font-weight: 400;">
                                                    (<?php echo htmlspecialchars($item['strength']); ?> <?php echo htmlspecialchars($item['form']); ?>)
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="med-details">
                                            <div class="med-detail">
                                                <span class="med-label">Dose:</span>
                                                <span><?php echo htmlspecialchars($item['dose'] ?: 'Not specified'); ?></span>
                                            </div>
                                            <div class="med-detail">
                                                <span class="med-label">Frequency:</span>
                                                <span><?php echo htmlspecialchars($item['frequency'] ?: 'Not specified'); ?></span>
                                            </div>
                                            <div class="med-detail">
                                                <span class="med-label">Duration:</span>
                                                <span><?php echo htmlspecialchars($item['duration'] ?: 'Not specified'); ?></span>
                                            </div>
                                            <?php if ($item['instructions']): ?>
                                                <div class="med-detail" style="grid-column: 1/-1;">
                                                    <span class="med-label">Instructions:</span>
                                                    <span><?php echo htmlspecialchars($item['instructions']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($rx['notes']): ?>
                            <div class="rx-notes">
                                <strong>Doctor's Notes:</strong> <?php echo htmlspecialchars($rx['notes']); ?>
                            </div>
                        <?php endif; ?>

                        <div class="rx-actions">
                            <button class="action-btn btn-download" onclick="downloadPrescription(<?php echo $rx['prescription_id']; ?>)">
                                📥 Download PDF
                            </button>
                            <button class="action-btn btn-print" onclick="printPrescription(<?php echo $rx['prescription_id']; ?>)">
                                🖨️ Print
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">💊</div>
                <h3>No Prescriptions</h3>
                <p>You don't have any prescriptions yet. Once your doctor issues a prescription, it will appear here.</p>
                <a href="patientappoint.php">Schedule an Appointment →</a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function filterPrescriptions(status) {
            const cards = document.querySelectorAll('.prescription-card');
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

        function downloadPrescription(prescriptionId) {
            alert('Download functionality will generate PDF for prescription #' + prescriptionId);
            // TODO: Implement PDF generation
            // window.location.href = 'generate-pdf.php?type=prescription&id=' + prescriptionId;
        }

        function printPrescription(prescriptionId) {
            alert('Print functionality for prescription #' + prescriptionId);
            // TODO: Implement print functionality
        }
    </script>

</body>
</html>
