<?php
/**
 * doctor-patient-list.php
 * View all patients with search and filtering
 */
require_once 'config.php';
requireRole('doctor');

$pdo = getPDO();
$doctor_id = getCurrentRoleId();

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
                   p.sex, p.blood_type, p.date_of_birth,
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
                   p.sex, p.blood_type, p.date_of_birth,
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
            --primary-green: #2d7a6a;
            --accent-green: #3ba89f;
            --light-bg: #f0f4f3;
            --white: #ffffff;
            --text-dark: #1a2332;
            --text-light: #666666;
            --border-color: #e0e6e5;
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
            flex: 1;
            margin-left: 40px;
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
            background: var(--danger);
            color: white;
            padding: 8px 20px;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 500;
        }

        .logout-btn:hover {
            background: #c82333;
        }

        .container {
            max-width: 1200px;
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

        .search-box {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            display: flex;
            gap: 10px;
        }

        .search-box input {
            flex: 1;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 14px;
            font-family: 'Quicksand', sans-serif;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(45, 122, 106, 0.1);
        }

        .search-btn {
            background: var(--primary-green);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: 0.3s;
        }

        .search-btn:hover {
            background: var(--accent-green);
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

        .patient-name {
            font-weight: 600;
            color: var(--primary-green);
        }

        .patient-contact {
            font-size: 12px;
            color: var(--text-light);
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-male {
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-female {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-other {
            background: #e2e3e5;
            color: #383d41;
        }

        .action-btn {
            padding: 8px 16px;
            background: var(--primary-green);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
            transition: 0.3s;
        }

        .action-btn:hover {
            background: var(--accent-green);
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

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border-left: 4px solid var(--danger);
        }

        .patient-count {
            color: var(--text-light);
            font-size: 13px;
            margin-top: 20px;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }

            .search-box {
                flex-direction: column;
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
            <span>👥</span> Patient List
        </div>
        <div class="nav-links">
            <a href="doctor-dashboard.php">Dashboard</a>
            <a href="doctor-profile.php">Profile</a>
            <a href="doctor-appointment.php">Appointments</a>
            <a href="doctor-patient-list.php" class="active">Patients</a>
            <a href="doctor-medical-records.php">Medical Records</a>
            <a href="doctor-prescriptions.php">Prescriptions</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </nav>

    <div class="container">
        <div class="header">
            <h1>Patient Directory</h1>
            <a href="doctor-dashboard.php" class="back-btn">← Back to Dashboard</a>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="search-box">
            <form method="GET" style="display: flex; gap: 10px; width: 100%;">
                <input type="text" name="search" placeholder="Search by name, email, or phone..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="search-btn">🔍 Search</button>
                <?php if ($search): ?>
                    <a href="doctor-patient-list.php" class="search-btn" style="text-decoration: none; background: var(--text-light);">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (count($patients) > 0): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
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
                                    <div class="patient-name">
                                        <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?>
                                    </div>
                                    <div class="patient-contact">
                                        <?php echo htmlspecialchars($patient['email']); ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($patient['contact_no'] ?? '--'); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo strtolower($patient['sex'] ?? 'other'); ?>">
                                        <?php echo ucfirst($patient['sex'] ?? 'Other'); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($patient['blood_type'] ?? '--'); ?></td>
                                <td>
                                    <strong><?php echo $patient['total_appointments']; ?></strong>
                                </td>
                                <td>
                                    <strong><?php echo $patient['total_records']; ?></strong>
                                </td>
                                <td>
                                    <a href="doctor-patient-profile.php?patient_id=<?php echo $patient['patient_id']; ?>" class="action-btn">
                                        View Profile →
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="patient-count">
                📊 Showing <?php echo count($patients); ?> patient<?php echo count($patients) !== 1 ? 's' : ''; ?>
                <?php if ($search): ?>
                    matching "<?php echo htmlspecialchars($search); ?>"
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">👥</div>
                <h3><?php echo $search ? 'No Patients Found' : 'No Patients'; ?></h3>
                <p>
                    <?php echo $search ? 'Try a different search term.' : 'Search for patients to view their profiles and medical information.'; ?>
                </p>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>
