<?php
/**
 * patient-vitals.php
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

    // Fetch Vital Signs History
    $stmt = $pdo->prepare("
        SELECT vs.*, mr.appointment_id, a.schedule_start,
               CONCAT(u.first_name, ' ', u.last_name) as recorded_by_name
        FROM vital_signs vs
        JOIN medical_record mr ON vs.medical_record_id = mr.record_id
        LEFT JOIN appointment a ON mr.appointment_id = a.appointment_id
        LEFT JOIN users u ON vs.recorded_by = u.user_id
        WHERE mr.patient_id = ?
        ORDER BY vs.recorded_at DESC
        LIMIT 50
    ");
    $stmt->execute([$patient_id]);
    $vitals_records = $stmt->fetchAll();

    // Get Latest Vitals
    $latest_vitals = null;
    if (count($vitals_records) > 0) {
        $latest_vitals = $vitals_records[0];
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    $vitals_records = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vital Signs | Health4Q</title>
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
            --info: #17a2b8;
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
            max-width: 1100px;
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

        .latest-vitals {
            background: white;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--primary-green);
        }

        .vitals-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 20px;
        }

        .vitals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
        }

        .vital-card {
            background: linear-gradient(135deg, #f5f9f8 0%, #e8f2f1 100%);
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid var(--primary-green);
            text-align: center;
        }

        .vital-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .vital-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary-green);
            margin-bottom: 5px;
        }

        .vital-unit {
            font-size: 12px;
            color: var(--text-light);
        }

        .vital-status {
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 4px;
            margin-top: 10px;
            font-weight: 600;
        }

        .status-normal {
            background: #d4edda;
            color: #155724;
        }

        .status-warning {
            background: #fff3cd;
            color: #856404;
        }

        .status-alert {
            background: #f8d7da;
            color: #721c24;
        }

        .vitals-meta {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 6px;
            margin-top: 20px;
            font-size: 13px;
            color: var(--text-light);
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }

        .vitals-history {
            margin-top: 30px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary-green);
            margin-bottom: 20px;
        }

        .table-container {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table thead {
            background: var(--primary-green);
            color: white;
        }

        table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        table td {
            padding: 14px 15px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }

        table tbody tr:hover {
            background: #f9f9f9;
        }

        table tbody tr:last-child td {
            border-bottom: none;
        }

        .date-cell {
            color: var(--text-light);
            font-size: 13px;
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
            .vitals-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .vitals-meta {
                flex-direction: column;
                gap: 10px;
            }

            table {
                font-size: 12px;
            }

            table th, table td {
                padding: 10px;
            }
        }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand">
            <span>❤️</span> Vital Signs
        </div>
        <div class="nav-links">
            <a href="patient-dashboard.php">Dashboard</a>
            <a href="patientprofile.php">Profile</a>
            <a href="patientappoint.php">Appointments</a>
            <a href="patient-prescriptions.php">Prescriptions</a>
            <a href="patient-allergies.php">Allergies</a>
            <a href="patient-lab-results.php">Lab Results</a>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="header">
            <h1>Vital Signs Tracking</h1>
            <a href="patient-dashboard.php" class="back-btn">← Back to Dashboard</a>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($latest_vitals): ?>
            <div class="latest-vitals">
                <div class="vitals-title">Latest Vital Signs</div>
                <div class="vitals-grid">
                    <?php if ($latest_vitals['temperature']): ?>
                        <div class="vital-card">
                            <div class="vital-label">Temperature</div>
                            <div class="vital-value"><?php echo number_format($latest_vitals['temperature'], 1); ?></div>
                            <div class="vital-unit">°C</div>
                            <div class="vital-status <?php echo ($latest_vitals['temperature'] >= 36.5 && $latest_vitals['temperature'] <= 37.5) ? 'status-normal' : 'status-warning'; ?>">
                                <?php echo ($latest_vitals['temperature'] >= 36.5 && $latest_vitals['temperature'] <= 37.5) ? 'Normal' : 'Abnormal'; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($latest_vitals['systolic_bp'] && $latest_vitals['diastolic_bp']): ?>
                        <div class="vital-card">
                            <div class="vital-label">Blood Pressure</div>
                            <div class="vital-value"><?php echo $latest_vitals['systolic_bp']; ?>/<?php echo $latest_vitals['diastolic_bp']; ?></div>
                            <div class="vital-unit">mmHg</div>
                            <div class="vital-status <?php echo ($latest_vitals['systolic_bp'] < 120 && $latest_vitals['diastolic_bp'] < 80) ? 'status-normal' : 'status-warning'; ?>">
                                <?php echo ($latest_vitals['systolic_bp'] < 120 && $latest_vitals['diastolic_bp'] < 80) ? 'Normal' : 'Elevated'; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($latest_vitals['heart_rate']): ?>
                        <div class="vital-card">
                            <div class="vital-label">Heart Rate</div>
                            <div class="vital-value"><?php echo $latest_vitals['heart_rate']; ?></div>
                            <div class="vital-unit">bpm</div>
                            <div class="vital-status <?php echo ($latest_vitals['heart_rate'] >= 60 && $latest_vitals['heart_rate'] <= 100) ? 'status-normal' : 'status-warning'; ?>">
                                <?php echo ($latest_vitals['heart_rate'] >= 60 && $latest_vitals['heart_rate'] <= 100) ? 'Normal' : 'Abnormal'; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($latest_vitals['respiratory_rate']): ?>
                        <div class="vital-card">
                            <div class="vital-label">Respiratory Rate</div>
                            <div class="vital-value"><?php echo $latest_vitals['respiratory_rate']; ?></div>
                            <div class="vital-unit">breaths/min</div>
                            <div class="vital-status status-normal">Normal</div>
                        </div>
                    <?php endif; ?>

                    <?php if ($latest_vitals['oxygen_saturation']): ?>
                        <div class="vital-card">
                            <div class="vital-label">O₂ Saturation</div>
                            <div class="vital-value"><?php echo number_format($latest_vitals['oxygen_saturation'], 1); ?></div>
                            <div class="vital-unit">%</div>
                            <div class="vital-status <?php echo $latest_vitals['oxygen_saturation'] >= 95 ? 'status-normal' : 'status-alert'; ?>">
                                <?php echo $latest_vitals['oxygen_saturation'] >= 95 ? 'Normal' : 'Low'; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($latest_vitals['weight']): ?>
                        <div class="vital-card">
                            <div class="vital-label">Weight</div>
                            <div class="vital-value"><?php echo number_format($latest_vitals['weight'], 1); ?></div>
                            <div class="vital-unit">kg</div>
                            <div class="vital-status status-normal">Recorded</div>
                        </div>
                    <?php endif; ?>

                    <?php if ($latest_vitals['height']): ?>
                        <div class="vital-card">
                            <div class="vital-label">Height</div>
                            <div class="vital-value"><?php echo number_format($latest_vitals['height'], 2); ?></div>
                            <div class="vital-unit">m</div>
                            <div class="vital-status status-normal">Recorded</div>
                        </div>
                    <?php endif; ?>

                    <?php if ($latest_vitals['bmi']): ?>
                        <div class="vital-card">
                            <div class="vital-label">BMI</div>
                            <div class="vital-value"><?php echo number_format($latest_vitals['bmi'], 1); ?></div>
                            <div class="vital-unit">kg/m²</div>
                            <div class="vital-status <?php echo ($latest_vitals['bmi'] >= 18.5 && $latest_vitals['bmi'] < 25) ? 'status-normal' : 'status-warning'; ?>">
                                <?php 
                                    if ($latest_vitals['bmi'] < 18.5) echo 'Underweight';
                                    elseif ($latest_vitals['bmi'] < 25) echo 'Normal';
                                    elseif ($latest_vitals['bmi'] < 30) echo 'Overweight';
                                    else echo 'Obese';
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="vitals-meta">
                    <div><strong>Recorded:</strong> <?php echo date('M d, Y @ h:i A', strtotime($latest_vitals['recorded_at'])); ?></div>
                    <div><strong>By:</strong> <?php echo $latest_vitals['recorded_by_name'] ? htmlspecialchars($latest_vitals['recorded_by_name']) : 'System'; ?></div>
                </div>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">❤️</div>
                <h3>No Vital Signs Recorded</h3>
                <p>Your vital signs will be recorded during medical visits and will appear here.</p>
                <a href="patientappoint.php">Schedule an Appointment →</a>
            </div>
        <?php endif; ?>

        <?php if (count($vitals_records) > 1): ?>
            <div class="vitals-history">
                <div class="section-title">📊 Vital Signs History</div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Temperature (°C)</th>
                                <th>Blood Pressure</th>
                                <th>Heart Rate (bpm)</th>
                                <th>O₂ Sat (%)</th>
                                <th>Weight (kg)</th>
                                <th>BMI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($vitals_records, 1) as $vital): ?>
                                <tr>
                                    <td class="date-cell">
                                        <?php echo date('M d, Y<br>h:i A', strtotime($vital['recorded_at'])); ?>
                                    </td>
                                    <td><?php echo $vital['temperature'] ? number_format($vital['temperature'], 1) : '-'; ?></td>
                                    <td><?php echo ($vital['systolic_bp'] && $vital['diastolic_bp']) ? $vital['systolic_bp'] . '/' . $vital['diastolic_bp'] : '-'; ?></td>
                                    <td><?php echo $vital['heart_rate'] ?: '-'; ?></td>
                                    <td><?php echo $vital['oxygen_saturation'] ? number_format($vital['oxygen_saturation'], 1) : '-'; ?></td>
                                    <td><?php echo $vital['weight'] ? number_format($vital['weight'], 1) : '-'; ?></td>
                                    <td><?php echo $vital['bmi'] ? number_format($vital['bmi'], 1) : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>
