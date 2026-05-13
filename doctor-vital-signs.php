<?php
/**
 * doctor-vital-signs.php 
 */
require_once 'config.php';
requireRole(ROLE_DOCTOR);

$pdo = getPDO();
$doctor_id = $_SESSION['user_id'] ?? 0; // Ensure you use the session user_id for the recorded_by field
$message = '';

// --- Logic: BMI Category Helper ---
function getBMICategory($bmi) {
    if (!$bmi || $bmi <= 0) return ['label' => 'N/A', 'color' => '#6c757d'];
    if ($bmi < 18.5) return ['label' => 'Underweight', 'color' => '#17a2b8'];
    if ($bmi < 25) return ['label' => 'Normal', 'color' => '#28a745'];
    if ($bmi < 30) return ['label' => 'Overweight', 'color' => '#ffc107'];
    return ['label' => 'Obese', 'color' => '#dc3545'];
}

// --- POST Handler: Add New Vitals ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_vital'])) {
    $mrid = (int)$_POST['medical_record_id'];
    $temp = $_POST['temperature'] ?: null;
    $sys = $_POST['systolic_bp'] ?: null;
    $dia = $_POST['diastolic_bp'] ?: null;
    $hr = $_POST['heart_rate'] ?: null;
    $rr = $_POST['respiratory_rate'] ?: null;
    $spo2 = $_POST['oxygen_saturation'] ?: null;
    $weight = $_POST['weight'] ?: null;
    $height = $_POST['height'] ?: null;
    $bmi = null;

    // Auto-calculate BMI if weight and height are provided
    if ($weight && $height) {
        $heightInMeters = $height / 100;
        $bmi = round($weight / ($heightInMeters * $heightInMeters), 2);
    }

    try {
        $sql = "INSERT INTO vital_signs (
                    medical_record_id, temperature, systolic_bp, diastolic_bp, 
                    heart_rate, respiratory_rate, oxygen_saturation, 
                    weight, height, bmi, recorded_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$mrid, $temp, $sys, $dia, $hr, $rr, $spo2, $weight, $height, $bmi, $doctor_id]);
        $message = "Vitals recorded successfully!";
    } catch (PDOException $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// --- Data Fetching: History ---
$query = "SELECT vs.*, u.first_name, u.last_name 
          FROM vital_signs vs 
          JOIN medical_record mr ON vs.medical_record_id = mr.record_id 
          JOIN patient p ON mr.patient_id = p.patient_id 
          JOIN users u ON p.user_id = u.user_id 
          ORDER BY vs.recorded_at DESC LIMIT 50";
$vital_records = $pdo->query($query)->fetchAll();

// --- Data Fetching: Patient Dropdown ---
$stmt = $pdo->query("SELECT mr.record_id, u.first_name, u.last_name FROM medical_record mr JOIN patient p ON mr.patient_id = p.patient_id JOIN users u ON p.user_id = u.user_id ORDER BY u.last_name ASC");
$medical_records = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinical Vitals | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2d6a4f;
            --primary-dark: #1b4332;
            --bg-body: #f4f7f6;
            --card-bg: #ffffff;
            --text-main: #2d3436;
            --text-muted: #636e72;
            --accent: #52b788;
            --border: #e9ecef;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            margin: 0;
            min-height: 100vh;
        }

        /* Navigation */
        .top-nav {
            background: var(--primary-dark);
            color: white;
            padding: 0.75rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }
        .nav-links a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            margin-left: 1.5rem;
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Layout */
        .main-content {
            max-width: 1300px;
            margin: 2rem auto;
            width: 95%;
            display: grid;
            grid-template-columns: 400px 1fr;
            gap: 2rem;
        }

        @media (max-width: 1100px) {
            .main-content { grid-template-columns: 1fr; }
        }

        .card {
            background: var(--card-bg);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            padding: 1.5rem;
            border: 1px solid var(--border);
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--primary-dark);
        }

        /* Form Controls */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            margin-bottom: 0.4rem;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .form-control {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.9rem;
            box-sizing: border-box;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            border: none;
            width: 100%;
            padding: 1rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 1.5rem;
            transition: 0.3s;
        }

        .btn-primary:hover { background: var(--primary-dark); }

        /* Vitals Feed */
        .vital-entry {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .entry-header {
            display: flex;
            justify-content: space-between;
            padding-bottom: 0.8rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1rem;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1rem;
        }

        .metric-box {
            background: #f8f9fa;
            padding: 0.8rem;
            border-radius: 10px;
            text-align: center;
        }

        .metric-value {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--primary);
        }

        .metric-label {
            font-size: 0.65rem;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
        }

        .bmi-tag {
            display: inline-block;
            margin-top: 4px;
            font-size: 0.65rem;
            padding: 2px 8px;
            border-radius: 20px;
            color: white;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand"><img src="images/Logo_only.png" alt="Health4Q"></div>
        <div class="nav-links">
            <a href="doctor-dashboard.php">Dashboard</a>
            <a href="doctor-vital-signs.php">Vitals</a>
            <a href="logout.php" style="color: #ff7675;">Logout</a>
        </div>
    </nav>

    <div class="main-content">
        <!-- Input Section -->
        <aside>
            <div class="card">
                <div class="card-title">🩺 New Observation</div>
                
                <?php if ($message): ?>
                    <div class="alert"><?= $message ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group" style="margin-bottom: 1.5rem;">
                        <label>Select Patient</label>
                        <select name="medical_record_id" class="form-control" required>
                            <option value="">-- Choose Patient --</option>
                            <?php foreach ($medical_records as $mr): ?>
                                <option value="<?= $mr['record_id'] ?>">
                                    <?= htmlspecialchars($mr['last_name'] . ', ' . $mr['first_name']) ?> (ID: <?= $mr['record_id'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Temp (°C)</label>
                            <input type="number" step="0.1" name="temperature" class="form-control" placeholder="36.5">
                        </div>
                        <div class="form-group">
                            <label>Oxygen (SpO2%)</label>
                            <input type="number" step="0.1" name="oxygen_saturation" class="form-control" placeholder="98">
                        </div>
                        <div class="form-group">
                            <label>Pulse (BPM)</label>
                            <input type="number" name="heart_rate" class="form-control" placeholder="72">
                        </div>
                        <div class="form-group">
                            <label>Resp. Rate</label>
                            <input type="number" name="respiratory_rate" class="form-control" placeholder="16">
                        </div>
                        <div class="form-group">
                            <label>BP (Systolic)</label>
                            <input type="number" name="systolic_bp" class="form-control" placeholder="120">
                        </div>
                        <div class="form-group">
                            <label>BP (Diastolic)</label>
                            <input type="number" name="diastolic_bp" class="form-control" placeholder="80">
                        </div>
                        <div class="form-group">
                            <label>Weight (kg)</label>
                            <input type="number" step="0.1" name="weight" class="form-control" placeholder="70.5">
                        </div>
                        <div class="form-group">
                            <label>Height (cm)</label>
                            <input type="number" step="0.1" name="height" class="form-control" placeholder="175">
                        </div>
                    </div>

                    <button type="submit" name="add_vital" class="btn-primary">Save Medical Data</button>
                </form>
            </div>
        </aside>

        <!-- History Section -->
        <section>
            <div class="card-title" style="display: flex; justify-content: space-between; align-items: center;">
                <span>🕒 Clinical History</span>
                <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: 400;">Latest 50 Records</span>
            </div>

            <?php if (empty($vital_records)): ?>
                <div class="card" style="text-align: center; padding: 3rem;">
                    <p style="color: var(--text-muted);">No clinical observations recorded yet.</p>
                </div>
            <?php endif; ?>

            <?php foreach ($vital_records as $v): 
                $bmiData = getBMICategory($v['bmi']);
            ?>
                <div class="vital-entry">
                    <div class="entry-header">
                        <div>
                            <span style="font-weight: 700; color: var(--primary-dark);"><?= htmlspecialchars($v['first_name'] . ' ' . $v['last_name']) ?></span>
                            <span style="font-size: 0.75rem; color: #95a5a6; margin-left: 8px;">RECID #<?= $v['medical_record_id'] ?></span>
                        </div>
                        <span style="font-size: 0.8rem; font-weight: 600;"><?= date('M d, Y • h:i A', strtotime($v['recorded_at'])) ?></span>
                    </div>
                    
                    <div class="metrics-grid">
                        <div class="metric-box">
                            <div class="metric-label">Blood Pressure</div>
                            <div class="metric-value"><?= $v['systolic_bp'] ?? '--' ?>/<?= $v['diastolic_bp'] ?? '--' ?></div>
                        </div>
                        <div class="metric-box">
                            <div class="metric-label">Heart / Resp</div>
                            <div class="metric-value"><?= $v['heart_rate'] ?? '--' ?> <span style="font-size: 0.7rem;">/</span> <?= $v['respiratory_rate'] ?? '--' ?></div>
                        </div>
                        <div class="metric-box">
                            <div class="metric-label">SpO2 / Temp</div>
                            <div class="metric-value"><?= $v['oxygen_saturation'] ?? '--' ?>% <span style="font-size: 0.7rem;">/</span> <?= $v['temperature'] ? $v['temperature'].'°C' : '--' ?></div>
                        </div>
                        <div class="metric-box">
                            <div class="metric-label">BMI Assessment</div>
                            <div class="metric-value"><?= $v['bmi'] ?? '--' ?></div>
                            <span class="bmi-tag" style="background: <?= $bmiData['color'] ?>"><?= $bmiData['label'] ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    </div>

</body>
</html>