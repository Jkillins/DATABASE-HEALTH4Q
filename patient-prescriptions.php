<?php
/**
 * patient-prescriptions.php
 * Patient Prescription Management & Viewing
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
$prescriptions = [];

/** 
 * DEBUG TOOL: If you still get an error, uncomment the lines below, 
 * refresh the page, and tell me what the output says.
 */
// $q = $pdo->query("DESCRIBE prescription_item");
// echo "<pre>"; print_r($q->fetchAll(PDO::FETCH_COLUMN)); echo "</pre>"; die();

try {
    // 1. Get Patient ID
    $stmt = $pdo->prepare('SELECT patient_id FROM patient WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $patient_id = $stmt->fetchColumn();

    if ($patient_id) {
        /**
         * 2. Fetch prescriptions and items.
         * FIX: Changed pi.med_id to pi.medicine_id
         * Note: Removed line_no and dose as they were previously reported missing.
         */
        $query = "
            SELECT 
                p.prescription_id, p.issued_at, p.notes, p.status,
                u.first_name, u.last_name,
                pi.frequency, pi.duration, pi.instructions,
                m.name as med_name, m.strength, m.form
            FROM prescription p
            JOIN medical_record mr ON p.record_id = mr.record_id
            JOIN doctor d ON p.doctor_id = d.doctor_id
            JOIN users u ON d.user_id = u.user_id
            LEFT JOIN prescription_item pi ON p.prescription_id = pi.prescription_id
            LEFT JOIN medicine m ON pi.medicine_id = m.med_id
            WHERE mr.patient_id = ?
            ORDER BY p.issued_at DESC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$patient_id]);
        $rows = $stmt->fetchAll();

        // 3. Group data by Prescription ID
        foreach ($rows as $row) {
            $rx_id = $row['prescription_id'];
            if (!isset($prescriptions[$rx_id])) {
                $prescriptions[$rx_id] = [
                    'prescription_id' => $row['prescription_id'],
                    'issued_at' => $row['issued_at'],
                    'notes' => $row['notes'],
                    'status' => $row['status'],
                    'doctor_name' => $row['first_name'] . ' ' . $row['last_name'],
                    'items' => []
                ];
            }
            if ($row['med_name']) {
                $prescriptions[$rx_id]['items'][] = [
                    'name' => $row['med_name'],
                    'strength' => $row['strength'],
                    'form' => $row['form'],
                    'frequency' => $row['frequency'],
                    'duration' => $row['duration'],
                    'instructions' => $row['instructions']
                ];
            }
        }
    }
} catch (Exception $e) {
    $error = "Database Error: " . $e->getMessage();
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
            --text-dark: #1a2332;
            --text-light: #666666;
            --border-color: #e0e6e5;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Quicksand', sans-serif; }
        body { background: var(--light-bg); color: var(--text-dark); padding-bottom: 50px; }

        .top-nav {
            background: var(--primary-green);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .container { max-width: 900px; margin: 30px auto; padding: 0 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .header h1 { color: var(--primary-green); font-size: 24px; }
        
        .back-btn { background: var(--primary-green); color: white; text-decoration: none; padding: 8px 15px; border-radius: 4px; font-size: 14px; }

        .prescription-card { background: white; border-radius: 12px; padding: 25px; margin-bottom: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-top: 5px solid var(--primary-green); }

        .rx-header { display: flex; justify-content: space-between; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .doctor-name { font-weight: 700; font-size: 18px; color: var(--text-dark); }
        .issued-date { font-size: 13px; color: var(--text-light); }

        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .status-active { background: #e8f5e9; color: #2e7d32; }

        .med-item { background: #f9fbfb; border: 1px solid #edf2f2; padding: 15px; border-radius: 8px; margin-bottom: 12px; }
        .med-title { font-weight: 700; color: var(--primary-green); margin-bottom: 5px; }
        .med-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; font-size: 13px; }
        .label { font-weight: 600; color: #777; }

        .notes-box { background: #fffde7; padding: 15px; border-radius: 6px; font-size: 13px; margin-top: 15px; border-left: 4px solid #fbc02d; }
        .error-message { background: #fee; color: #b71c1c; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ffcdd2; }

        @media (max-width: 600px) {
            .rx-header { flex-direction: column; gap: 10px; }
        }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div style="font-weight:700;">Health4Q</div>
        <div><a href="logout.php" style="color:white; font-size:14px;">Logout</a></div>
    </nav>

    <div class="container">
        <div class="header">
            <h1>My Prescriptions</h1>
            <a href="patient-dashboard.php" class="back-btn">← Back</a>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-message">
                <strong>Database Sync Error:</strong><br>
                <?php echo htmlspecialchars($error); ?>
                <hr style="margin: 10px 0; opacity: 0.2;">
                <p style="font-size: 12px;">This usually means the column <strong>medicine_id</strong> in your <code>prescription_item</code> table is actually named something else (like <code>med_id</code> or <code>item_id</code>).</p>
            </div>
        <?php endif; ?>

        <?php if (!empty($prescriptions)): ?>
            <?php foreach ($prescriptions as $rx): ?>
                <div class="prescription-card">
                    <div class="rx-header">
                        <div>
                            <div class="doctor-name">Dr. <?php echo htmlspecialchars($rx['doctor_name']); ?></div>
                            <div class="issued-date">Issued: <?php echo date('M d, Y', strtotime($rx['issued_at'])); ?></div>
                        </div>
                        <div>
                            <span class="status-badge status-<?php echo strtolower($rx['status']); ?>">
                                <?php echo htmlspecialchars($rx['status']); ?>
                            </span>
                        </div>
                    </div>

                    <?php foreach ($rx['items'] as $item): ?>
                        <div class="med-item">
                            <div class="med-title"><?php echo htmlspecialchars($item['name']); ?> <?php echo htmlspecialchars($item['strength']); ?></div>
                            <div class="med-grid">
                                <div><span class="label">Frequency:</span> <?php echo htmlspecialchars($item['frequency']); ?></div>
                                <div><span class="label">Duration:</span> <?php echo htmlspecialchars($item['duration']); ?></div>
                                <div style="grid-column: 1/-1;"><span class="label">Instructions:</span> <?php echo htmlspecialchars($item['instructions'] ?: 'Use as directed'); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($rx['notes']): ?>
                        <div class="notes-box">
                            <strong>Doctor's Notes:</strong><br>
                            <?php echo nl2br(htmlspecialchars($rx['notes'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <?php if (!isset($error)): ?>
                <div style="text-align: center; padding: 40px; background: white; border-radius: 12px; color: #888;">
                    No prescriptions found.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</body>
</html>