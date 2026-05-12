<?php
require_once 'config.php';
// Using the same session check logic as your dashboards
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Security: Ensure only patients access this directory
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();

try {
    // Enhanced query: Fetching from 'users' and 'doctor' tables 
    // Adjusted table names to match your previous code (doctor vs doctors)
    $stmt = $pdo->query("
        SELECT u.user_id, u.first_name, u.last_name, u.email, d.specialty, d.clinic 
        FROM users u 
        JOIN doctor d ON u.user_id = d.user_id 
        WHERE u.role = 'doctor' 
        ORDER BY d.specialty ASC, u.last_name ASC
    ");
    $doctors = $stmt->fetchAll();

    // Grouping logic
    $doctors_by_specialty = [];
    foreach ($doctors as $doctor) {
        $specialty = $doctor['specialty'] ?: 'General Practice';
        if (!isset($doctors_by_specialty[$specialty])) {
            $doctors_by_specialty[$specialty] = [];
        }
        $doctors_by_specialty[$specialty][] = $doctor;
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find a Specialist | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-dark: #1b4332;
            --primary: #10b981;
            --bg-light: #f8fafc;
            --border: #e2e8f0;
            --text-main: #1e293b;
            --text-muted: #64748b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: var(--bg-light); color: var(--text-main); }

        /* HEADER NAV (Same as other dashboards for consistency) */
        .h4q-nav {
            background: white; padding: 15px 8%; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 1000;
        }
        .nav-links { display: flex; gap: 20px; list-style: none; }
        .nav-links a { 
            text-decoration: none; color: var(--text-muted); font-size: 14px; 
            font-weight: 500; transition: 0.3s; padding: 8px 12px; border-radius: 8px;
        }
        .nav-links a:hover, .nav-links a.active { color: var(--primary-dark); background: #f0fdf4; }

        /* DIRECTORY CONTAINER */
        .container { max-width: 1200px; margin: 40px auto; padding: 0 20px; }
        
        .page-header { margin-bottom: 40px; text-align: center; }
        .page-header h1 { font-size: 32px; color: var(--primary-dark); font-weight: 800; }
        .page-header p { color: var(--text-muted); margin-top: 10px; }

        /* SPECIALTY SECTION */
        .specialty-section { margin-bottom: 50px; }
        .specialty-title { 
            font-size: 14px; font-weight: 700; color: var(--primary); 
            text-transform: uppercase; letter-spacing: 1px; border-left: 4px solid var(--primary);
            padding-left: 15px; margin-bottom: 20px;
        }

        /* DOCTOR CARDS */
        .doctor-grid { 
            display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); 
            gap: 25px; 
        }
        .doctor-card { 
            background: white; border-radius: 20px; padding: 30px; border: 1px solid var(--border);
            text-align: center; transition: all 0.3s ease; position: relative;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }
        .doctor-card:hover { transform: translateY(-8px); border-color: var(--primary); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }

        .doc-avatar { 
            width: 90px; height: 90px; background: #e2e8f0; border-radius: 50%; 
            margin: 0 auto 15px; overflow: hidden; border: 3px solid #f8fafc;
        }
        .doc-name { font-size: 18px; font-weight: 700; color: var(--primary-dark); }
        .doc-spec { font-size: 13px; color: var(--primary); font-weight: 600; margin-bottom: 15px; text-transform: uppercase; }
        
        .doc-info { font-size: 13px; color: var(--text-muted); margin-bottom: 5px; display: flex; align-items: center; justify-content: center; gap: 8px; }

        .btn-book { 
            display: block; margin-top: 25px; padding: 12px; background: var(--primary-dark);
            color: white; text-decoration: none; border-radius: 12px; font-weight: 600; font-size: 14px;
            transition: 0.3s;
        }
        .btn-book:hover { background: var(--primary); }

        .empty-state { text-align: center; padding: 60px; background: white; border-radius: 20px; color: var(--text-muted); }
    </style>
</head>
<body>

    <nav class="h4q-nav">
        <img src="images/Logo_name.png" alt="Health4Q" height="45">
        <ul class="nav-links">
            <li><a href="patient-dashboard.php">Dashboard</a></li>
            <li><a href="patientprofile.php">Profile</a></li>
            <li><a href="patientappoint.php">Appointments</a></li>
            <li><a href="patientmedhist.php">Medical History</a></li>
            <li><a href="listofdoctor.php" class="active">Find Doctors</a></li>
        </ul>
        <a href="logout.php" style="color: #ef4444; font-size: 14px; font-weight: 600; text-decoration: none;">Logout</a>
    </nav>

    <div class="container">
        <header class="page-header">
            <h1>Expert Medical Directory</h1>
            <p>Connect with our verified specialists and book your consultation in seconds.</p>
        </header>

        <?php if (isset($error)): ?>
            <div style="background: #fee2e2; color: #b91c1c; padding: 15px; border-radius: 12px; margin-bottom: 20px; text-align: center;">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($doctors_by_specialty)): ?>
            <div class="empty-state">
                <h3>No doctors found</h3>
                <p>We couldn't find any medical staff matching your criteria right now.</p>
            </div>
        <?php else: ?>
            <?php foreach ($doctors_by_specialty as $specialty => $doctor_group): ?>
                <section class="specialty-section">
                    <h2 class="specialty-title"><?php echo htmlspecialchars($specialty); ?></h2>
                    <div class="doctor-grid">
                        <?php foreach ($doctor_group as $doctor): ?>
                            <div class="doctor-card">
                                <div class="doc-avatar">
                                    <img src="images/doctor_profile.png" alt="Doctor" style="width: 100%; height:100%; object-fit: cover;">
                                </div>
                                <h3 class="doc-name">Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?></h3>
                                <p class="doc-spec"><?php echo htmlspecialchars($doctor['specialty']); ?></p>
                                
                                <div class="doc-info">
                                    <span>📍</span> <?php echo htmlspecialchars($doctor['clinic']); ?>
                                </div>
                                <div class="doc-info">
                                    <span>📧</span> <?php echo htmlspecialchars($doctor['email']); ?>
                                </div>

                                <a href="patientappoint.php?doctor_id=<?php echo $doctor['user_id']; ?>" class="btn-book">
                                    Book Appointment
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</body>
</html>