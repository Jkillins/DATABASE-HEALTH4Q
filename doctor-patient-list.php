<?php
/**
 * doctor-patient-list.php
 */
require_once 'config.php';
requireRole('doctor');

$pdo = getPDO();
$doctor_id = getCurrentRoleId();
if (!$doctor_id && isset($_SESSION['user_id'])) {
    $stmtRole = $pdo->prepare("SELECT doctor_id FROM doctor WHERE user_id = ?");
    $stmtRole->execute([$_SESSION['user_id']]);
    $doctor_id = $stmtRole->fetchColumn();
    if ($doctor_id) {
        $_SESSION['role_id'] = (int)$doctor_id;
    }
}

$search = '';
$patients = [];

// Handle search
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search'])) {
    $search = trim($_GET['search']);
}

try {
    if ($search) {
        $stmt = $pdo->prepare("
            SELECT p.patient_id, u.user_id, u.first_name, u.last_name, u.email, u.contact_no,
                   p.sex, p.date_of_birth, p.blood_type,
                   COUNT(DISTINCT a.appointment_id) as total_appointments,
                   COUNT(DISTINCT mr.record_id) as total_records
            FROM patient p
            JOIN users u ON p.user_id = u.user_id
            LEFT JOIN appointment a ON p.patient_id = a.patient_id
            LEFT JOIN medical_record mr ON p.patient_id = mr.patient_id
            WHERE u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.contact_no LIKE ?
            GROUP BY p.patient_id
            ORDER BY u.last_name ASC, u.first_name ASC
        ");
        $search_term = '%' . $search . '%';
        $stmt->execute([$search_term, $search_term, $search_term, $search_term]);
    } else {
        $stmt = $pdo->prepare("
            SELECT p.patient_id, u.user_id, u.first_name, u.last_name, u.email, u.contact_no,
                   p.sex, p.date_of_birth, p.blood_type,
                   COUNT(DISTINCT a.appointment_id) as total_appointments,
                   COUNT(DISTINCT mr.record_id) as total_records
            FROM patient p
            JOIN users u ON p.user_id = u.user_id
            LEFT JOIN appointment a ON p.patient_id = a.patient_id
            LEFT JOIN medical_record mr ON p.patient_id = mr.patient_id
            GROUP BY p.patient_id
            ORDER BY u.last_name ASC, u.first_name ASC
            LIMIT 100
        ");
        $stmt->execute();
    }
    $patients = $stmt->fetchAll();

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient List | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --nav-bg: #1a3c34;
            --page-bg: #e0f2f1;
            --card-bg: #ffffff;
            --primary-green: #2d7a6a;
            --accent-green: #3ba89f;
            --text-dark: #333333;
            --text-muted: #666666;
            --danger: #dc3545;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Quicksand', sans-serif;
        }

        body {
            background-color: var(--page-bg);
            color: var(--text-dark);
            min-height: 100vh;
        }

        /* Top Navigation following image_d35913.jpg */
        .top-nav {
            background: var(--nav-bg);
            padding: 10px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
        }
        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }

        .nav-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 1.2rem;
        }

        .nav-center-links {
            display: flex;
            gap: 10px;
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
        }

        .nav-pill {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            text-decoration: none;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: 0.3s;
        }

        .nav-pill:hover, .nav-pill.active {
            background: var(--accent-green);
        }

        .logout-btn {
            background: var(--danger);
            color: white;
            padding: 6px 18px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
        }

        /* Container & Content */
        .container {
            max-width: 1100px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .page-header {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            text-align: center;
        }

        .page-header h1 {
            color: var(--primary-green);
            font-size: 1.8rem;
            margin-bottom: 10px;
        }

        /* Search Section */
        .search-container {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
        }

        .search-input {
            flex: 1;
            padding: 12px 20px;
            border: 1px solid #ddd;
            border-radius: 30px;
            outline: none;
            font-size: 1rem;
        }

        .btn-search {
            background: var(--primary-green);
            color: white;
            border: none;
            padding: 0 25px;
            border-radius: 30px;
            cursor: pointer;
            font-weight: 600;
        }

        /* Table Design */
        .table-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f8f9fa;
            border-bottom: 2px solid var(--page-bg);
        }

        th {
            padding: 15px;
            text-align: left;
            color: var(--text-muted);
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        .patient-name {
            font-weight: 700;
            color: var(--primary-green);
            display: block;
        }

        .patient-subtext {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .badge {
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .badge-male { background: #e3f2fd; color: #1976d2; }
        .badge-female { background: #fce4ec; color: #c2185b; }
        .badge-other { background: #f5f5f5; color: #616161; }

        .view-btn {
            background: #1a3c34;
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.8rem;
            transition: 0.3s;
        }

        .view-btn:hover {
            background: var(--accent-green);
        }

        .empty-state {
            text-align: center;
            padding: 50px;
            background: white;
            border-radius: 15px;
        }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>
        
        <div class="nav-center-links">
            <a href="doctor-dashboard.php" class="nav-pill">🏠 Home</a>
            <a href="doctor-patient-list.php" class="nav-pill active">👥 Patients</a>
            <a href="doctor-appointment.php" class="nav-pill">📅 Appointments</a>
            <a href="doctor-medical-request.php" class="nav-pill">📁 Requests</a>
            <a href="doctor-prescriptions.php" class="nav-pill">💊 Medicine</a>
            <a href="doctor-profile.php" class="nav-pill">⚙️ Profile</a>
        </div>

        <a href="logout.php" class="logout-btn">Logout</a>
    </nav>

    <div class="container">
        <div class="page-header">
            <h1>Patient Directory</h1>
            <p style="color: var(--text-muted);">Manage and view records for all registered patients.</p>
        </div>

        <?php if (isset($error)): ?>
            <div style="background: #fee; color: #c00; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                ⚠️ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="GET" class="search-container">
            <input type="text" name="search" class="search-input" 
                   placeholder="Search patients by name, email or phone..." 
                   value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn-search">Search</button>
            <?php if ($search): ?>
                <a href="doctor-patient-list.php" class="btn-search" style="text-decoration: none; background: #666; display: flex; align-items: center;">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (count($patients) > 0): ?>
            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <th>Patient Info</th>
                            <th>Contact No.</th>
                            <th>Sex</th>
                            <th>Blood Type</th>
                            <th>Appointments</th>
                            <th>Records</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $patient): ?>
                            <tr>
                                <td>
                                    <span class="patient-name"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></span>
                                    <span class="patient-subtext"><?php echo htmlspecialchars($patient['email']); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($patient['contact_no'] ?? '--'); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo strtolower($patient['sex'] ?? 'other'); ?>">
                                        <?php echo ucfirst($patient['sex'] ?? 'Other'); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: #d90429; font-size: 13px;"><?php echo htmlspecialchars(($patient['blood_type'] ?? '') ?: '--'); ?></strong>
                                </td>
                                <td style="text-align: center;"><strong><?php echo $patient['total_appointments']; ?></strong></td>
                                <td style="text-align: center;"><strong><?php echo $patient['total_records']; ?></strong></td>
                                <td>
                                    <a href="doctor-patient-profile.php?patient_id=<?php echo $patient['patient_id']; ?>" class="view-btn">
                                        View Profile
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="margin-top: 15px; font-size: 0.85rem; color: var(--text-muted);">
                📊 Showing <?php echo count($patients); ?> total results.
            </p>
        <?php else: ?>
            <div class="empty-state">
                <div style="font-size: 3rem; margin-bottom: 10px;">🔍</div>
                <h3>No Patients Found</h3>
                <p>We couldn't find any patients matching "<?php echo htmlspecialchars($search); ?>".</p>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>