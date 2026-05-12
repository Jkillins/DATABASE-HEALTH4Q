<?php
/**
 * doctor-availability.php
 * Manage doctor's availability schedule
 */

require_once 'config.php';
requireRole(ROLE_DOCTOR);

$pdo = getPDO();
$doctor_id = getCurrentRoleId();

$message = '';

// Get current availability
$stmt = $pdo->prepare('SELECT * FROM doctor_availability WHERE doctor_id = ? ORDER BY FIELD(day_of_week, "monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday")');
$stmt->execute([$doctor_id]);
$availability = $stmt->fetchAll();

// Create availability array by day
$schedule = [];
$days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
foreach ($days as $day) {
    $schedule[$day] = null;
}
foreach ($availability as $slot) {
    $schedule[$slot['day_of_week']] = $slot;
}

// Handle availability update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_availability'])) {
    try {
        // Clear existing availability
        $stmt = $pdo->prepare('DELETE FROM doctor_availability WHERE doctor_id = ?');
        $stmt->execute([$doctor_id]);

        // Insert new availability for each day
        $insert_stmt = $pdo->prepare('
            INSERT INTO doctor_availability (doctor_id, day_of_week, start_time, end_time, is_available)
            VALUES (?, ?, ?, ?, ?)
        ');

        foreach ($days as $day) {
            $is_available = isset($_POST["{$day}_enabled"]) ? 1 : 0;
            if ($is_available) {
                $start_time = sanitize($_POST["{$day}_start"] ?? '08:00');
                $end_time = sanitize($_POST["{$day}_end"] ?? '17:00');
                $insert_stmt->execute([$doctor_id, $day, $start_time, $end_time, 1]);
            }
        }

        $message = '✓ Schedule updated successfully!';
    } catch (Exception $e) {
        $message = '✗ Error: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Availability | Health4Q</title>
    <link rel="icon" type="image/png" href="images/Logo_only.png">
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #1a4d34;
            --accent-green: #2d6a4f;
            --light-bg: #c5e6e1;
            --white: #ffffff;
            --text-dark: #1b4332;
            --text-light: #555;
            --border-color: #d0e8e0;
            --danger: #d90429;
            --success: #52b788;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Quicksand', sans-serif;
            background: radial-gradient(circle at center, #d8f3dc 0%, var(--light-bg) 100%);
            min-height: 100vh;
            color: var(--text-dark);
        }

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
        }
        .nav-links a:hover, .nav-links a.active { background: var(--accent-green); }

        .logout-btn {
            background: var(--danger);
            color: white;
            padding: 8px 18px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            font-size: 12px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        h1 {
            font-size: 28px;
            color: var(--primary-green);
            margin-bottom: 10px;
        }

        .subtitle {
            color: var(--text-light);
            margin-bottom: 25px;
        }

        .message {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
            border-left: 4px solid;
        }

        .message.success {
            background: #d4edda;
            border-color: var(--success);
            color: #155724;
        }

        .message.error {
            background: #f8d7da;
            border-color: var(--danger);
            color: #721c24;
        }

        .card {
            background: var(--white);
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .schedule-grid {
            display: grid;
            gap: 15px;
        }

        .day-slot {
            padding: 15px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: var(--light-bg);
            transition: 0.3s;
        }

        .day-slot:hover {
            border-color: var(--accent-green);
            background: rgba(45, 106, 79, 0.05);
        }

        .day-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .day-name {
            font-weight: 700;
            color: var(--primary-green);
            font-size: 15px;
            min-width: 100px;
        }

        .toggle-switch {
            position: relative;
            width: 50px;
            height: 28px;
            background-color: #ccc;
            border-radius: 14px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .toggle-switch input {
            display: none;
        }

        .toggle-switch input:checked + .slider {
            background-color: var(--accent-green);
        }

        .toggle-switch .slider {
            position: absolute;
            top: 3px;
            left: 3px;
            width: 22px;
            height: 22px;
            background-color: white;
            border-radius: 50%;
            transition: left 0.3s;
        }

        .toggle-switch input:checked + .slider {
            left: 25px;
        }

        .time-inputs {
            display: flex;
            gap: 10px;
            margin-top: 12px;
            opacity: 1;
            transition: opacity 0.3s;
        }

        .day-slot.disabled .time-inputs {
            opacity: 0.5;
            pointer-events: none;
        }

        .time-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .time-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--primary-green);
        }

        .time-group input {
            padding: 8px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-family: 'Quicksand', sans-serif;
            font-size: 13px;
        }

        .time-group input:focus {
            outline: none;
            border-color: var(--accent-green);
            background: var(--white);
        }

        .button-group {
            margin-top: 30px;
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: 0.3s;
            font-family: 'Quicksand', sans-serif;
        }

        .btn-primary {
            background: var(--accent-green);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-green);
        }

        .btn-secondary {
            background: var(--text-light);
            color: white;
        }

        .btn-secondary:hover {
            background: #444;
        }

        .info-box {
            background: linear-gradient(135deg, rgba(45,106,79,0.1), rgba(45,106,79,0.05));
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid var(--accent-green);
            margin-bottom: 25px;
            font-size: 13px;
            color: var(--text-dark);
            line-height: 1.6;
        }

        @media (max-width: 768px) {
            .day-header { flex-wrap: wrap; }
            .button-group { flex-direction: column; }
            .btn { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="top-nav">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>
        <div class="nav-links">
            <a href="doctor-dashboard.php">Dashboard</a>
            <a href="doctor-patient-list.php">Patients</a>
            <a href="doctor-availability.php" class="active">Availability</a>
            <a href="doctor-profile.php">Profile</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>

    <div class="container">
        <h1>📅 Schedule & Availability</h1>
        <p class="subtitle">Set your working hours for each day of the week</p>

        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, '✓') !== false ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="info-box">
                💡 <strong>Note:</strong> Enable the days you work and set your start and end times. Patients will only be able to schedule appointments during your available hours.
            </div>

            <form method="POST" id="availabilityForm">
                <div class="schedule-grid">
                    <?php 
                    $day_display = ['monday' => '📍 Monday', 'tuesday' => '📍 Tuesday', 'wednesday' => '📍 Wednesday', 'thursday' => '📍 Thursday', 'friday' => '📍 Friday', 'saturday' => '📍 Saturday', 'sunday' => '📍 Sunday'];
                    
                    foreach ($days as $day): 
                        $slot = $schedule[$day];
                        $is_available = $slot && $slot['is_available'] ? true : false;
                    ?>
                        <div class="day-slot <?php echo !$is_available ? 'disabled' : ''; ?>" id="slot_<?php echo $day; ?>">
                            <div class="day-header">
                                <span class="day-name"><?php echo $day_display[$day]; ?></span>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="<?php echo $day; ?>_enabled" value="1" 
                                        <?php echo $is_available ? 'checked' : ''; ?> 
                                        onchange="toggleDay('<?php echo $day; ?>')">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="time-inputs">
                                <div class="time-group">
                                    <label>Start Time</label>
                                    <input type="time" name="<?php echo $day; ?>_start" 
                                        value="<?php echo $slot ? htmlspecialchars($slot['start_time']) : '08:00'; ?>">
                                </div>
                                <div class="time-group">
                                    <label>End Time</label>
                                    <input type="time" name="<?php echo $day; ?>_end" 
                                        value="<?php echo $slot ? htmlspecialchars($slot['end_time']) : '17:00'; ?>">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="button-group">
                    <button type="submit" name="update_availability" class="btn btn-primary">💾 Save Schedule</button>
                    <button type="reset" class="btn btn-secondary">Reset Form</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleDay(day) {
            const slot = document.getElementById('slot_' + day);
            const checkbox = slot.querySelector('input[type="checkbox"]');
            
            if (checkbox.checked) {
                slot.classList.remove('disabled');
            } else {
                slot.classList.add('disabled');
            }
        }

        // Initialize on page load
        document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            const day = checkbox.name.replace('_enabled', '');
            if (!checkbox.checked) {
                document.getElementById('slot_' + day).classList.add('disabled');
            }
        });
    </script>
</body>
</html>
