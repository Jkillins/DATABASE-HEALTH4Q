<?php
/**
 * patient-prescriptions.php - Premium Forest Green Patient Prescription Hub
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
$error = '';

try {
    // 1. Get Patient ID
    $stmt = $pdo->prepare('SELECT patient_id FROM patient WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $patient_id = $stmt->fetchColumn();

    if ($patient_id) {
        // 2. Fetch prescriptions and items
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
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            --gold: #b5835a;
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

        /* --- HEADER & CONTROLS --- */
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

        .search-row {
            display: flex;
            gap: 15px;
        }

        .search-bar {
            flex: 1;
            position: relative;
        }

        .search-bar input {
            width: 100%;
            padding: 12px 20px 12px 45px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            outline: none;
            font-size: 0.95rem;
            font-weight: 600;
            transition: 0.3s;
        }
        .search-bar input:focus {
            border-color: var(--accent-green);
        }

        .search-bar i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
            font-size: 1.1rem;
        }

        .filter-status {
            padding: 0 15px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            outline: none;
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text-dark);
            background: white;
            cursor: pointer;
            transition: 0.3s;
        }
        .filter-status:focus {
            border-color: var(--accent-green);
        }

        /* --- PRESCRIPTION CARDS --- */
        .rx-card {
            background: var(--white);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            border-top: 6px solid var(--primary-green);
            transition: 0.3s transform;
        }
        .rx-card:hover {
            transform: translateY(-2px);
        }

        .rx-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1.5px solid var(--border-color);
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .doc-badge {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .doc-badge .avatar-icon {
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

        .doc-info h3 {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--primary-green);
        }

        .doc-info p {
            font-size: 0.8rem;
            color: var(--text-muted);
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
        .status-active { background: #e6f9f0; color: #16a34a; border: 1px solid #bbf7d0; }
        .status-inactive { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }
        .status-completed { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

        /* --- MEDICINE ITEM --- */
        .med-item {
            background: #f8fafc;
            border: 1.5px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
        }

        .med-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .med-name {
            font-size: 1rem;
            font-weight: 800;
            color: var(--primary-green);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .med-form-pill {
            font-size: 10px;
            font-weight: 700;
            background: #e2e8f0;
            color: #475569;
            padding: 3px 8px;
            border-radius: 6px;
            text-transform: uppercase;
        }

        .med-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .med-grid-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .med-grid-item span.label {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
        }

        .med-grid-item span.val {
            color: var(--text-dark);
        }

        /* --- NOTES --- */
        .notes-box {
            background: #fffbeb;
            border: 1px dashed #fef08a;
            border-left: 4px solid #eab308;
            padding: 15px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 20px;
        }

        /* --- ALERTS --- */
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

        /* --- PRINT ACTION --- */
        .print-btn {
            background: var(--primary-green);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: 0.3s;
        }
        .print-btn:hover {
            background: var(--accent-green);
        }

        /* --- RESPONSIVE --- */
        @media (max-width: 768px) {
            .header-title-row { flex-direction: column; align-items: flex-start; gap: 15px; }
            .search-row { flex-direction: column; }
        }

        /* --- PRINT STYLES --- */
        @media print {
            body { background: white; color: black; }
            .top-nav, .header-section, .print-btn { display: none !important; }
            .container { max-width: 100%; margin: 0; padding: 0; }
            .rx-card { border: 1px solid #000; box-shadow: none; page-break-inside: avoid; }
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
            <a href="patient-prescriptions.php" class="active">💊 Prescriptions</a>
            <a href="patient-lab-results.php">🧪 Lab Results</a>
            <a href="patientmedhist.php">📜 History</a>
            <a href="patientreqmed.php">🔍 Request Records</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </nav>

    <div class="container">
        
        <div class="header-section">
            <div class="header-title-row">
                <h1>💊 My Digital Prescriptions</h1>
                <button onclick="window.print()" class="print-btn">
                    <i class="fa-solid fa-print"></i> Print Records
                </button>
            </div>
            
            <div class="search-row">
                <div class="search-bar">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="rxSearch" placeholder="Search by medicine name or doctor..." onkeyup="filterPrescriptions()">
                </div>
                
                <select id="rxStatusFilter" class="filter-status" onchange="filterPrescriptions()">
                    <option value="all">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="completed">Completed / Archive</option>
                </select>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert-error">
                <i class="fa-solid fa-circle-exclamation"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <div id="rxContainer">
            <?php if (!empty($prescriptions)): ?>
                <?php foreach ($prescriptions as $rx): ?>
                    <div class="rx-card" data-doctor="<?php echo htmlspecialchars(strtolower($rx['doctor_name'])); ?>" data-status="<?php echo htmlspecialchars(strtolower($rx['status'])); ?>" data-meds="<?php 
                        $med_keywords = [];
                        foreach($rx['items'] as $item) {
                            $med_keywords[] = strtolower($item['name']);
                        }
                        echo htmlspecialchars(implode(' ', $med_keywords));
                    ?>">
                        
                        <div class="rx-header">
                            <div class="doc-badge">
                                <div class="avatar-icon">
                                    <i class="fa-solid fa-user-doctor"></i>
                                </div>
                                <div class="doc-info">
                                    <h3>Dr. <?php echo htmlspecialchars($rx['doctor_name']); ?></h3>
                                    <p><i class="fa-regular fa-calendar-check"></i> Issued: <?php echo date('F d, Y | h:i A', strtotime($rx['issued_at'])); ?></p>
                                </div>
                            </div>
                            
                            <span class="status-pill status-<?php echo strtolower($rx['status']); ?>">
                                <?php echo htmlspecialchars($rx['status']); ?>
                            </span>
                        </div>

                        <div class="rx-body">
                            <?php foreach ($rx['items'] as $item): ?>
                                <div class="med-item">
                                    <div class="med-head">
                                        <div class="med-name">
                                            💊 <?php echo htmlspecialchars($item['name']); ?> <?php echo htmlspecialchars($item['strength']); ?>
                                        </div>
                                        <span class="med-form-pill"><?php echo htmlspecialchars($item['form'] ?: 'Tablet'); ?></span>
                                    </div>
                                    
                                    <div class="med-grid">
                                        <div class="med-grid-item">
                                            <span class="label">Frequency / Dosage</span>
                                            <span class="val">🔄 <?php echo htmlspecialchars($item['frequency']); ?></span>
                                        </div>
                                        <div class="med-grid-item">
                                            <span class="label">Treatment Duration</span>
                                            <span class="val">📅 <?php echo htmlspecialchars($item['duration']); ?></span>
                                        </div>
                                        <?php if (!empty($item['instructions'])): ?>
                                            <div class="med-grid-item" style="grid-column: span 2; margin-top: 8px;">
                                                <span class="label">Special Instructions</span>
                                                <span class="val">📝 <?php echo htmlspecialchars($item['instructions']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <?php if (!empty($rx['notes'])): ?>
                                <div class="notes-box">
                                    <strong><i class="fa-solid fa-circle-info"></i> Physician's Medical Remarks:</strong><br>
                                    <p style="margin-top: 5px; line-height: 1.5; color: #5c3e00;"><?php echo nl2br(htmlspecialchars($rx['notes'])); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="text-align: center; padding: 50px 20px; background: white; border-radius: 20px; border: 1.5px dashed var(--border-color); box-shadow: 0 10px 25px rgba(0,0,0,0.05);">
                    <span style="font-size: 3rem; display: block; margin-bottom: 12px;">💊</span>
                    <strong style="font-size: 1.1rem; color: var(--primary-green); display: block;">No Prescriptions Registered</strong>
                    <p style="color: #64748b; font-size: 0.85rem; margin-top: 5px; font-weight: 500;">You currently do not have any electronic prescriptions issued under your profile.</p>
                </div>
            <?php endif; ?>
        </div>

        <div id="noResults" style="display: none; text-align: center; padding: 50px 20px; background: white; border-radius: 20px; border: 1.5px dashed var(--border-color); box-shadow: 0 10px 25px rgba(0,0,0,0.05);">
            <span style="font-size: 3rem; display: block; margin-bottom: 12px;">🔍</span>
            <strong style="font-size: 1.1rem; color: var(--primary-green); display: block;">No Matching Records Found</strong>
            <p style="color: #64748b; font-size: 0.85rem; margin-top: 5px; font-weight: 500;">We couldn't find any prescriptions matching your search terms or filters.</p>
        </div>

    </div>

    <script>
        function filterPrescriptions() {
            const searchQuery = document.getElementById('rxSearch').value.toLowerCase().trim();
            const statusFilter = document.getElementById('rxStatusFilter').value.toLowerCase();
            const cards = document.querySelectorAll('.rx-card');
            const noResults = document.getElementById('noResults');
            let visibleCount = 0;

            cards.forEach(card => {
                const docName = card.getAttribute('data-doctor');
                const status = card.getAttribute('data-status');
                const meds = card.getAttribute('data-meds');

                const matchesSearch = docName.includes(searchQuery) || meds.includes(searchQuery);
                const matchesStatus = (statusFilter === 'all') || (status === statusFilter);

                if (matchesSearch && matchesStatus) {
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