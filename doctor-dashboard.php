<?php
/**
 * doctor-dashboard.php
 * Enhanced UI version with Forest Green theme
 */

require_once 'config.php';

// Ensure the user is logged in and is a doctor
// requireRole is a custom function from your config.php
requireRole(ROLE_DOCTOR); 

$pdo = getPDO();
$doctor_id = getCurrentRoleId(); // Gets the specific doctor_id
$doctor = getDoctorProfile($pdo, $doctor_id); // Fetches the 'last_name' from DB

// Date Logic
$today = date('Y-m-d');
$appointments = getDoctorAppointments($pdo, $doctor_id, 50);

// Filter logic for the UI cards
$today_appointments = array_filter($appointments, function($apt) use ($today) {
    return substr($apt['schedule_start'], 0, 10) === $today;
});

$pending_count = count(array_filter($appointments, function($apt) { 
    return $apt['status'] === 'scheduled'; 
}));

$completed_count = count(array_filter($appointments, function($apt) { 
    return $apt['status'] === 'completed'; 
}));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Dashboard | Health4Q</title>
    <link rel="icon" type="image/png" href="images/Logo_only.png">
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #1a4d34; /* Dark Forest Green */
            --light-bg: #c5e6e1;    /* Pale Mint Background */
            --white: #ffffff;
            --accent-green: #2d6a4f;
            --text-dark: #1b4332;
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
            gap: 5px;
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
        .logout-btn:hover { background: #b80322; }

        /* --- DASHBOARD LAYOUT --- */
        .container { max-width: 1100px; margin: 30px auto; padding: 0 20px; flex: 1; }

        /* Welcome Card with Arvin-style UI */
        .welcome-card {
            background: var(--white);
            padding: 35px;
            border-radius: 20px;
            margin-bottom: 30px;
            border-bottom: 6px solid #84ccb1;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        }

        .welcome-card h1 { font-size: 2rem; color: var(--accent-green); font-weight: 800; margin-bottom: 10px; }
        .welcome-card p { color: #555; font-size: 14px; }
        
        .date-badge {
            display: inline-block;
            background: #e9f5f2;
            padding: 6px 16px;
            border-radius: 12px;
            color: #4361ee;
            font-size: 12px;
            font-weight: 600;
            margin-top: 20px;
            border: 1px solid #d1e9e3;
        }

        /* --- THREE COLUMN GRID --- */
        .main-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
            margin-bottom: 30px;
        }

        .card {
            background: var(--white);
            border-radius: 20px;
            padding: 30px;
            min-height: 380px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.04);
            text-align: center;
        }

        .card-icon {
            width: 55px;
            height: 55px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 22px;
        }

        .card h3 { font-size: 18px; margin-bottom: 25px; color: var(--text-dark); }

        /* Schedule Table Style */
        .schedule-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .schedule-table th { background: #f0f7f4; color: var(--accent-green); padding: 10px; text-align: left; border-radius: 5px 0 0 5px; }
        .schedule-table td { padding: 10px; border-bottom: 1px solid #f5f5f5; text-align: left; color: #444; }

        /* Stats Display */
        .stat-big { font-size: 56px; font-weight: 800; color: var(--accent-green); margin: 15px 0; }
        .stat-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700; }

        .appointment-stat-row {
            display: flex;
            justify-content: space-around;
            margin-top: 40px;
            border-top: 1px solid #eee;
            padding-top: 25px;
        }
        .apt-box span { display: block; font-weight: 800; font-size: 24px; }
        .apt-box p { font-size: 10px; color: #777; font-weight: 700; margin-top: 5px; }

        /* --- QUICK ACTIONS --- */
        .quick-actions {
            background: var(--white);
            padding: 20px 30px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.03);
        }
        .quick-actions h4 { color: #e67e22; font-size: 14px; white-space: nowrap; display: flex; align-items: center; gap: 8px; }
        
        .action-btn {
            flex: 1;
            padding: 12px;
            background: var(--primary-green);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            transition: all 0.3s ease;
        }
        .action-btn:hover { background: var(--accent-green); transform: translateY(-3px); box-shadow: 0 5px 12px rgba(26, 77, 52, 0.2); }

        @media (max-width: 992px) { 
            .main-grid { grid-template-columns: 1fr; } 
            .quick-actions { flex-wrap: wrap; }
        }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>
        <div class="nav-links">
            <a href="doctor-dashboard.php" class="active">🏠 Home</a>
            <a href="doctor-profile.php">👤 Profile</a>
            <a href="doctor-medical-data.php">📝 Patient's List</a>
            <a href="#">💊 Inventory</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </nav>

    <div class="container">
        <div class="welcome-card">
            <h1>Welcome Back, Dr. 👤</h1>
            <p>Here's your dashboard overview for today. Manage your schedule, track appointments, and monitor inventory.</p>
            <div class="date-badge">
                📅 <?php echo date('l, F d, Y'); ?>
            </div>
        </div>

        <div class="main-grid">
            
            <div class="card">
                <div class="card-icon" style="background: #4361ee;">📅</div>
                <h3>Weekly Schedule</h3>
                <table class="schedule-table">
                    <tr><th>DAY</th><th>HOURS</th></tr>
                    <tr><td>Monday</td><td>8:00 AM - 5:00 PM</td></tr>
                    <tr><td>Tuesday</td><td>8:00 AM - 5:00 PM</td></tr>
                    <tr><td>Wednesday</td><td>8:00 AM - 5:00 PM</td></tr>
                    <tr><td>Thursday</td><td>8:00 AM - 5:00 PM</td></tr>
                    <tr><td>Friday</td><td>8:00 AM - 5:00 PM</td></tr>
                    <tr><td>Saturday</td><td>8:00 AM - 12:00 PM</td></tr>
                </table>
            </div>

            <div class="card">
                <div class="card-icon" style="background: #f77f00;">💊</div>
                <h3>Medicine Inventory</h3>
                <div class="stat-big">2</div>
                <p class="stat-label">Total Medicines</p>
                <p style="font-size: 12px; color: #999; margin-top: 30px; line-height: 1.6;">
                    Keep your inventory stocked and organized for patient care.
                </p>
            </div>

            <div class="card">
                <div class="card-icon" style="background: #7209b7;">📋</div>
                <h3>Appointments</h3>
                <div class="appointment-stat-row">
                    <div class="apt-box">
                        <span style="color: #f77f00;"><?php echo $pending_count; ?></span>
                        <p>⌛ PENDING</p>
                    </div>
                    <div class="apt-box">
                        <span style="color: #2d6a4f;"><?php echo $completed_count; ?></span>
                        <p>✅ COMPLETED</p>
                    </div>
                </div>
                <p style="font-size: 11px; color: #aaa; margin-top: 40px;">
                    Updated as of <?php echo date('g:i A'); ?>
                </p>
            </div>

        </div>

        <div class="quick-actions">
            <h4>⚡ Quick Actions</h4>
            <a href="doctor-medical-data.php" class="action-btn">View All Patients</a>
            <a href="#" class="action-btn">Manage Inventory</a>
            <a href="doctor-profile.php" class="action-btn">Update Profile</a>
            <a href="#" class="action-btn">View Reports</a>
        </div>
    </div>

</body>
</html>