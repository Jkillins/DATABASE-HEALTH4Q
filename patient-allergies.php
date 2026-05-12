<?php
/**
 * patient-allergies.php
 * Patient Allergy Management
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
$message = '';
$msg_type = '';

try {
    // Get Patient ID
    $stmt = $pdo->prepare('SELECT patient_id FROM patient WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $patient_id = $stmt->fetchColumn();

    // Handle Add Allergy
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_allergy') {
        $allergen_type = htmlspecialchars($_POST['allergen_type']);
        $allergen_name = htmlspecialchars($_POST['allergen_name']);
        $reaction = htmlspecialchars($_POST['reaction'] ?? '');
        $severity = htmlspecialchars($_POST['severity']);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO patient_allergy (patient_id, allergen_type, allergen_name, reaction, severity)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$patient_id, $allergen_type, $allergen_name, $reaction, $severity]);
            $message = 'Allergy added successfully!';
            $msg_type = 'success';
        } catch (Exception $e) {
            $message = 'Error adding allergy: ' . $e->getMessage();
            $msg_type = 'error';
        }
    }

    // Handle Delete Allergy
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_allergy') {
        $allergy_id = (int)$_POST['allergy_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM patient_allergy WHERE allergy_id = ? AND patient_id = ?");
            $stmt->execute([$allergy_id, $patient_id]);
            $message = 'Allergy removed successfully!';
            $msg_type = 'success';
        } catch (Exception $e) {
            $message = 'Error removing allergy: ' . $e->getMessage();
            $msg_type = 'error';
        }
    }

    // Fetch Allergies
    $stmt = $pdo->prepare("
        SELECT * FROM patient_allergy
        WHERE patient_id = ?
        ORDER BY severity DESC, allergen_type ASC
    ");
    $stmt->execute([$patient_id]);
    $allergies = $stmt->fetchAll();

} catch (Exception $e) {
    $error = $e->getMessage();
    $allergies = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Allergies | Health4Q</title>
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

        .message {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border-color: #28a745;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border-color: #dc3545;
        }

        .form-card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--primary-green);
        }

        .form-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-family: 'Quicksand', sans-serif;
            font-size: 14px;
            transition: 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(45, 122, 106, 0.1);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .btn-submit {
            background: var(--primary-green);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            font-size: 14px;
        }

        .btn-submit:hover {
            background: var(--accent-green);
        }

        .allergies-list {
            display: grid;
            gap: 15px;
        }

        .allergy-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            border-left: 4px solid;
            display: flex;
            justify-content: space-between;
            align-items: start;
        }

        .allergy-card.medication {
            border-color: #dc3545;
        }

        .allergy-card.food {
            border-color: #ffc107;
        }

        .allergy-card.environmental {
            border-color: #17a2b8;
        }

        .allergy-card.other {
            border-color: #6c757d;
        }

        .allergy-info {
            flex: 1;
        }

        .allergy-type {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-light);
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .allergy-name {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 10px;
        }

        .allergy-details {
            font-size: 13px;
            color: var(--text-light);
            line-height: 1.6;
        }

        .severity-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            margin-top: 10px;
        }

        .severity-mild {
            background: #d1ecf1;
            color: #0c5460;
        }

        .severity-moderate {
            background: #fff3cd;
            color: #856404;
        }

        .severity-severe {
            background: #f8d7da;
            color: #721c24;
        }

        .allergy-actions {
            display: flex;
            gap: 10px;
            flex-direction: column;
            min-width: 100px;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: 0.3s;
        }

        .btn-delete:hover {
            background: #c82333;
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
        }

        .alert-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            color: #856404;
        }

        .alert-title {
            font-weight: 600;
            margin-bottom: 5px;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .allergy-card {
                flex-direction: column;
            }

            .allergy-actions {
                flex-direction: row;
                min-width: auto;
                margin-top: 15px;
            }

            .header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand">
            <span>⚠️</span> Allergies
        </div>
        <div class="nav-links">
            <a href="patient-dashboard.php">Dashboard</a>
            <a href="patientprofile.php">Profile</a>
            <a href="patientappoint.php">Appointments</a>
            <a href="patient-prescriptions.php">Prescriptions</a>
            <a href="patient-vitals.php">Vital Signs</a>
            <a href="patient-lab-results.php">Lab Results</a>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="header">
            <h1>My Allergies</h1>
            <a href="patient-dashboard.php" class="back-btn">← Back to Dashboard</a>
        </div>

        <?php if (!empty($message)): ?>
            <div class="message <?php echo $msg_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="message error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if (count($allergies) > 0): ?>
            <div class="alert-box">
                <div class="alert-title">⚠️ Important for Healthcare Providers</div>
                Your allergy information is critical for safe medical treatment. Please ensure this information is accurate and up-to-date.
            </div>
        <?php endif; ?>

        <div class="form-card">
            <div class="form-title">➕ Add New Allergy</div>
            <form method="POST">
                <input type="hidden" name="action" value="add_allergy">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="allergen_type">Allergy Type *</label>
                        <select name="allergen_type" id="allergen_type" required>
                            <option value="">Select Type</option>
                            <option value="medication">Medication</option>
                            <option value="food">Food</option>
                            <option value="environmental">Environmental</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="severity">Severity Level *</label>
                        <select name="severity" id="severity" required>
                            <option value="">Select Severity</option>
                            <option value="mild">Mild</option>
                            <option value="moderate">Moderate</option>
                            <option value="severe">Severe</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="allergen_name">Allergen Name *</label>
                    <input type="text" name="allergen_name" id="allergen_name" placeholder="e.g., Penicillin, Peanuts, Pollen" required>
                </div>

                <div class="form-group">
                    <label for="reaction">Reaction/Symptoms</label>
                    <textarea name="reaction" id="reaction" placeholder="Describe your reaction symptoms..." rows="4"></textarea>
                </div>

                <button type="submit" class="btn-submit">+ Add Allergy</button>
            </form>
        </div>

        <div>
            <div class="form-title" style="margin-bottom: 20px;">📋 Your Allergies</div>

            <?php if (count($allergies) > 0): ?>
                <div class="allergies-list">
                    <?php foreach ($allergies as $allergy): ?>
                        <div class="allergy-card <?php echo htmlspecialchars($allergy['allergen_type']); ?>">
                            <div class="allergy-info">
                                <div class="allergy-type">
                                    📌 <?php echo ucfirst(htmlspecialchars($allergy['allergen_type'])); ?>
                                </div>
                                <div class="allergy-name">
                                    <?php echo htmlspecialchars($allergy['allergen_name']); ?>
                                </div>
                                <div class="allergy-details">
                                    <?php if ($allergy['reaction']): ?>
                                        <strong>Reaction:</strong> <?php echo htmlspecialchars($allergy['reaction']); ?><br>
                                    <?php endif; ?>
                                    <strong>Recorded:</strong> <?php echo date('M d, Y', strtotime($allergy['created_at'])); ?>
                                </div>
                                <span class="severity-badge severity-<?php echo htmlspecialchars($allergy['severity']); ?>">
                                    <?php echo htmlspecialchars($allergy['severity']); ?>
                                </span>
                            </div>
                            <div class="allergy-actions">
                                <form method="POST" style="width: 100%;" onsubmit="return confirm('Are you sure you want to remove this allergy?');">
                                    <input type="hidden" name="action" value="delete_allergy">
                                    <input type="hidden" name="allergy_id" value="<?php echo $allergy['allergy_id']; ?>">
                                    <button type="submit" class="btn-delete">🗑️ Remove</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">⚠️</div>
                    <h3>No Allergies Recorded</h3>
                    <p>You haven't added any allergies yet. Please add your known allergies above for your safety.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>
