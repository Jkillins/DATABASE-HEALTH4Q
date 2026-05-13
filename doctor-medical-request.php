<?php
/**
 * doctor-medical-request.php
 */
require_once 'config.php';

// Ensure session is started and role is verified
if (session_status() === PHP_SESSION_NONE) { session_start(); }
requireRole('doctor');

$pdo = getPDO();
$user_id = $_SESSION['user_id'];

try {
    // 1. Get the internal doctor_id linked to this user account
    $stmtDoc = $pdo->prepare("SELECT doctor_id FROM doctor WHERE user_id = ?");
    $stmtDoc->execute([$user_id]);
    $doctor = $stmtDoc->fetch();
    $doctor_id = $doctor['doctor_id'] ?? 0;

    // 2. Fetch data requests from the data_requests table
    // We join with users to get patient details
    $stmt = $pdo->prepare("
        SELECT 
            dr.id, 
            dr.reason, 
            dr.status, 
            dr.created_at, 
            u.first_name, 
            u.last_name, 
            u.email
        FROM data_requests dr
        JOIN users u ON dr.patient_user_id = u.user_id
        WHERE dr.doctor_id = ?
        ORDER BY dr.created_at DESC
    ");
    $stmt->execute([$doctor_id]);
    $requests = $stmt->fetchAll();

} catch (Exception $e) {
    error_log($e->getMessage());
    $requests = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" type="image/png" href="assets/Logo-only.png" />
    <link rel="stylesheet" href="doctor.css" />
    <title>Medical Data Requests - Health4Q</title>
    <style>
        /* Badge styling to enhance UI without breaking layout */
        .status-badge {
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            display: inline-block;
        }
        .status-pending { background-color: #fef9c3; color: #854d0e; border: 1px solid #fde047; }
        .status-approved { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .status-rejected { background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        
        .btn-view {
            background-color: #0891b2;
            color: white;
            padding: 6px 12px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
            transition: background 0.3s;
        }
        .btn-view:hover { background-color: #0e7490; }
        
        .patient-info small { color: #64748b; display: block; font-weight: normal; }
    </style>
</head>
<body>
    <img src="assets/logo.png" alt="Health4Q Logo" width="180" height="80" />

    <header class="h4q-request-header">
        <nav class="h4q-request-header__nav">
            <ul class="h4q-request-header__nav-list">
                <li><a href="doctor-dashboard.php" class="h4q-doctor-header__nav-btn">Dashboard</a></li>
                <li><a href="doctor-profile.php" class="h4q-doctor-header__nav-btn">Profile</a></li>
                <li><a href="doctor-appointment.php" class="h4q-doctor-header__nav-btn">Appointments</a></li>
                <li><a href="doctor-medical-data.php" class="h4q-doctor-header__nav-btn">Medical Data</a></li>
                <li><a href="issuance.php" class="h4q-doctor-header__nav-btn">Referrals</a></li>
            </ul>
        </nav>
        <div class="h4q-request-header__logout">
            <a href="logout.php" class="h4q-request-header__logout-btn">Logout</a>
        </div>
    </header>

    <section class="h4q-request-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 class="h4q-request-content__title" style="margin: 0;">Incoming Data Requests</h2>
            <div style="font-size: 14px; color: #64748b;">
                Active Requests: <strong><?php echo count($requests); ?></strong>
            </div>
        </div>

        <?php if (count($requests) > 0): ?>
            <table class="h4q-request-table" style="width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <thead>
                    <tr style="background-color: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                        <th style="padding: 15px; text-align: left; border: 1px solid #ddd; color: #475569;">Patient</th>
                        <th style="padding: 15px; text-align: left; border: 1px solid #ddd; color: #475569;">Reason for Request</th>
                        <th style="padding: 15px; text-align: left; border: 1px solid #ddd; color: #475569;">Date Received</th>
                        <th style="padding: 15px; text-align: left; border: 1px solid #ddd; color: #475569;">Status</th>
                        <th style="padding: 15px; text-align: center; border: 1px solid #ddd; color: #475569;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request): ?>
                        <tr style="border-bottom: 1px solid #edf2f7;">
                            <td style="padding: 15px; border: 1px solid #ddd;" class="patient-info">
                                <strong><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></strong>
                                <small><?php echo htmlspecialchars($request['email']); ?></small>
                            </td>
                            <td style="padding: 15px; border: 1px solid #ddd; font-style: italic; color: #334155;">
                                "<?php echo htmlspecialchars($request['reason']); ?>"
                            </td>
                            <td style="padding: 15px; border: 1px solid #ddd; color: #64748b; font-size: 13px;">
                                <?php echo htmlspecialchars(date('M d, Y', strtotime($request['created_at']))); ?><br>
                                <span style="font-size: 11px;"><?php echo htmlspecialchars(date('h:i A', strtotime($request['created_at']))); ?></span>
                            </td>
                            <td style="padding: 15px; border: 1px solid #ddd;">
                                <span class="status-badge status-<?php echo htmlspecialchars($request['status']); ?>">
                                    <?php echo htmlspecialchars($request['status']); ?>
                                </span>
                            </td>
                            <td style="padding: 15px; border: 1px solid #ddd; text-align: center;">
                                <a href="doctor-medical-data.php?request_id=<?php echo $request['id']; ?>" class="btn-view">
                                    Process Request
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div style="background: white; padding: 40px; text-align: center; border: 1px solid #e2e8f0; border-radius: 8px;">
                <p style="color: #94a3b8; font-size: 16px;">No medical data requests found.</p>
                <small style="color: #cbd5e1;">Requests from patients will appear here once submitted.</small>
            </div>
        <?php endif; ?>
    </section>
</body>
</html>