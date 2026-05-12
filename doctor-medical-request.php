<?php
require_once 'config.php';
requireRole('doctor');

$pdo = getPDO();
$doctor_id = $_SESSION['user_id'];

// Get medical data requests from patients
$stmt = $pdo->prepare(
    "SELECT md.id, md.patient_id, md.record_date, md.data, u.name as patient_name 
     FROM medical_data md 
     JOIN users u ON md.patient_id = u.id 
     WHERE md.record_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
     ORDER BY md.record_date DESC"
);
$stmt->execute();
$requests = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="icon" type="image/png" href="assets/Logo-only.png" />
  <link rel="stylesheet" href="doctor.css" />
  <title>Medical Data Requests - Health4Q</title>
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
    <h2 class="h4q-request-content__title">Medical Data Requests</h2>

    <?php if (count($requests) > 0): ?>
      <table class="h4q-request-table" style="width: 100%; border-collapse: collapse; margin-top: 20px;">
        <thead>
          <tr style="background-color: #f5f5f5;">
            <th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Patient Name</th>
            <th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Date Submitted</th>
            <th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Data Summary</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $request): ?>
            <tr>
              <td style="padding: 10px; border: 1px solid #ddd;"><?php echo htmlspecialchars($request['patient_name']); ?></td>
              <td style="padding: 10px; border: 1px solid #ddd;"><?php echo htmlspecialchars(date('M d, Y', strtotime($request['record_date']))); ?></td>
              <td style="padding: 10px; border: 1px solid #ddd;"><?php echo htmlspecialchars(substr($request['data'], 0, 100)); ?>...</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p style="text-align: center; padding: 20px; color: #999;">No medical data requests found.</p>
    <?php endif; ?>
  </section>
</body>
</html>
