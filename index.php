<?php
require_once 'config.php';
$is_logged_in = isLoggedIn();
$user_role = getCurrentRole();
$user_name = $_SESSION['first_name'] ?? null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health4Q | Professional Campus Healthcare</title>
    <link rel="icon" type="image/png" href="images/Logo_only.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0f172a;
            --clinical-blue: #0ea5e9;
            --slate-light: #f1f5f9;
            --slate-text: #475569;
            --border: #e2e8f0;
            --white: #ffffff;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-md: 0 10px 25px -5px rgba(0,0,0,0.04);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        
        body { 
            background-color: var(--white); 
            color: var(--navy); 
            line-height: 1.6; 
            display: flex; 
            flex-direction: column; 
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* --- NAVIGATION --- */
        nav {
            display: flex; justify-content: space-between; align-items: center;
            padding: 0 10%;
            height: 80px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            position: sticky; top: 0; z-index: 1000;
            border-bottom: 1px solid var(--border);
        }

        .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; cursor: pointer; }
        .brand img { height: 32px; }
        .brand span { font-size: 20px; font-weight: 800; letter-spacing: -0.5px; }
        .brand b { color: var(--clinical-blue); }

        .nav-links { display: flex; gap: 32px; }
        .nav-links a { 
            text-decoration: none; color: var(--slate-text); 
            font-weight: 500; font-size: 14px; transition: var(--transition);
            cursor: pointer; position: relative;
        }
        .nav-links a:hover, .nav-links a.active { color: var(--clinical-blue); }
        .nav-links a.active::after {
            content: ''; position: absolute; bottom: -6px; left: 0; width: 100%; height: 2px;
            background: var(--clinical-blue); border-radius: 2px;
        }

        /* --- BUTTONS --- */
        .btn { padding: 10px 22px; border-radius: 8px; font-size: 14px; font-weight: 600; text-decoration: none; transition: var(--transition); border: none; cursor: pointer; display: inline-flex; align-items: center; }
        .btn-outline { background: var(--white); border: 1px solid var(--border); color: var(--navy); }
        .btn-outline:hover { background: var(--slate-light); border-color: var(--navy); }
        .btn-primary { background: var(--navy); color: white; }
        .btn-primary:hover { background: var(--clinical-blue); box-shadow: 0 8px 20px rgba(14, 165, 233, 0.2); transform: translateY(-2px); }

        /* --- SECTIONS --- */
        .content-section {
            display: none; opacity: 0; transform: translateY(20px);
            transition: var(--transition); padding: 100px 10%;
        }
        .content-section.active { display: block; opacity: 1; transform: translateY(0); }

        /* --- HERO --- */
        .hero-content { text-align: center; max-width: 900px; margin: 0 auto; }
        .hero-tag { background: #e0f2fe; color: #0369a1; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
        .hero-content h1 { font-size: clamp(32px, 5vw, 64px); font-weight: 800; line-height: 1.1; margin: 20px 0; letter-spacing: -2px; }
        .hero-content p { font-size: 18px; color: var(--slate-text); margin-bottom: 35px; }

        /* --- CLINICAL CARDS --- */
        .ui-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; margin-top: 40px; }
        .ui-card { 
            background: var(--white); border: 1px solid var(--border); 
            padding: 24px; border-radius: 20px; transition: var(--transition);
        }
        .ui-card:hover { border-color: var(--clinical-blue); box-shadow: var(--shadow-md); transform: translateY(-5px); }
        
        .card-image-container {
            width: 100%; height: 200px; background: var(--slate-light);
            border-radius: 14px; margin-bottom: 20px; overflow: hidden;
        }
        .card-image-container img {
            width: 100%; height: 100%; object-fit: cover; transition: var(--transition);
        }
        .ui-card:hover .card-image-container img { transform: scale(1.08); }

        .ui-card h3 { font-size: 20px; margin-bottom: 12px; font-weight: 700; color: var(--navy); }
        .ui-card p { color: var(--slate-text); font-size: 14px; line-height: 1.6; }

        /* --- CONTACT --- */
        .contact-wrapper { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; background: #fff; border-radius: 24px; border: 1px solid var(--border); overflow: hidden; box-shadow: var(--shadow-md); }
        .contact-info { padding: 60px; background: var(--navy); color: white; }
        .contact-form { padding: 60px; background: white; }
        .form-group { margin-bottom: 20px; }
        .form-group input, .form-group textarea { width: 100%; padding: 14px; border-radius: 8px; border: 1px solid var(--border); background: #f8fafc; outline: none; transition: var(--transition); }
        .form-group input:focus, .form-group textarea:focus { border-color: var(--clinical-blue); background: white; box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.1); }

        /* --- FOOTER --- */
        footer { 
            background: #0f172a; color: #94a3b8; padding: 80px 10% 40px; margin-top: auto;
        }
        .footer-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1.5fr; gap: 40px; margin-bottom: 60px; }
        .footer-logo { color: white; font-size: 24px; font-weight: 800; margin-bottom: 20px; display: block; }
        .footer-heading { color: white; font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 25px; }
        .footer-links { list-style: none; }
        .footer-links li { margin-bottom: 12px; }
        .footer-links a { color: #94a3b8; text-decoration: none; font-size: 14px; transition: var(--transition); cursor: pointer; }
        .footer-links a:hover { color: var(--clinical-blue); padding-left: 8px; }
        .footer-bottom { border-top: 1px solid #1e293b; padding-top: 30px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; }

        @media (max-width: 968px) {
            .footer-grid { grid-template-columns: 1fr 1fr; }
            .contact-wrapper { grid-template-columns: 1fr; }
            .nav-links { display: none; }
        }
    </style>
</head>
<body>

    <nav>
        <div class="brand" onclick="showSection('home')">
            <img src="images/Logo_only.png" alt="Health4Q">
            <span>Health<b>4Q</b></span>
        </div>
        <div class="nav-links">
            <a onclick="showSection('home')" id="nav-home" class="active">Home</a>
            <a onclick="showSection('about')" id="nav-about">Mission</a>
            <a onclick="showSection('services')" id="nav-services">Services</a>
            <a onclick="showSection('contact')" id="nav-contact">Support</a>
        </div>
        <div style="display: flex; gap: 12px;">
            <?php if ($is_logged_in): ?>
                <a href="dashboard.php" class="btn btn-primary">Portal</a>
            <?php else: ?>
                <a href="login.php" class="btn btn-outline">Sign In</a>
                <a href="register.php" class="btn btn-primary">Sign Up</a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="content-wrapper">
        <!-- HOME -->
        <section id="home" class="content-section active">
            <div class="hero-content">
                <span class="hero-tag">Unified Health Ecosystem</span>
                <h1>Health4Q Where Efficiency Meets Compassion.</b></h1>
                <p>Health4Q bridges the gap between clinical precision and academic convenience, providing a seamless healthcare experience for the UC community.</p>
                <div style="display: flex; gap: 15px; justify-content: center;">
                    <button onclick="showSection('services')" class="btn btn-primary" style="padding: 14px 32px;">View Modules</button>
                    <button onclick="showSection('contact')" class="btn btn-outline" style="padding: 14px 32px;">Contact Us</button>
                </div>
            </div>
        </section>

        <!-- MISSION -->
        <section id="about" class="content-section">
            <div style="text-align:center; max-width:700px; margin: 0 auto 60px;">
                <span class="hero-tag">About Health4Q</span>
                <h2 style="font-size: 38px; margin-top:15px; letter-spacing: -1px;">Compassion Through Innovation</h2>
                <p style="color: var(--slate-text); margin-top:15px;">We are dedicated to providing the University of Cebu with a digital health infrastructure that prioritizes patient well-being and data integrity.</p>
            </div>
            <div class="ui-grid">
                <div class="ui-card">
                    <h3 style="display:flex; align-items:center; gap:10px;">🎯 Our Mission</h3>
                    <p>To promote a healthier campus community by providing accessible, efficient, and compassionate digital health solutions tailored for students and faculty.</p>
                </div>
                <div class="ui-card">
                    <h3 style="display:flex; align-items:center; gap:10px;">👁️ Our Vision</h3>
                    <p>To become a trusted leader in digital healthcare innovation, empowering individuals to take control of their health through secure technology.</p>
                </div>
            </div>
        </section>

        <!-- SERVICES -->
        <section id="services" class="content-section" style="background: #f8fafc; border-top:1px solid var(--border); border-bottom:1px solid var(--border);">
            <div style="text-align:center; margin-bottom: 50px;">
                <span class="hero-tag">Clinical Modules</span>
                <h2 style="font-size: 38px; margin-top:10px; letter-spacing: -1px;">Specialized Services</h2>
            </div>
            
            <div class="ui-grid">
                <!-- Module 1 -->
                <div class="ui-card">
                    <div class="card-image-container">
                        <img src="images/medical_checkup.jpg" alt="Medical Checkup">
                    </div>
                    <h3>Medical Checkup</h3>
                    <p>Monitor your physical health with automated check-up logs, vital sign tracking, and direct physician feedback loops.</p>
                </div>

                <!-- Module 2 -->
                <div class="ui-card">
                    <div class="card-image-container">
                        <img src="images/referral_prescription.png" alt="Referral Prescription">
                    </div>
                    <h3>Referral Prescription</h3>
                    <p>Receive and manage your medical referrals and digital prescriptions securely. Access them anytime for pharmacy or specialist visits.</p>
                </div>

                <!-- Module 3 -->
                <div class="ui-card">
                    <div class="card-image-container">
                        <img src="images/lab_results.jpg" alt="Laboratory Results">
                    </div>
                    <h3>Laboratory Results</h3>
                    <p>Access your laboratory diagnostic reports and imaging results instantly. Keep a permanent digital history of your medical data.</p>
                </div>
            </div>
        </section>

        <!-- CONTACT -->
        <section id="contact" class="content-section">
            <div class="contact-wrapper">
                <div class="contact-info">
                    <h2 style="font-size: 32px; margin-bottom: 20px;">Get In Touch</h2>
                    <p style="opacity: 0.8; margin-bottom: 40px; font-size: 15px;">Our support team is available during campus hours to assist with technical or medical inquiries.</p>
                    <div style="font-size: 14px; line-height: 2.8;">
                        <p>📍 UC Main Campus, Sanciangko St, Cebu City</p>
                        <p>📞 +63 (32) 255-7777</p>
                        <p>✉️ support@health4q.edu.ph</p>
                    </div>
                </div>
                <div class="contact-form">
                    <div class="form-group"><input type="text" placeholder="Full Name"></div>
                    <div class="form-group"><input type="email" placeholder="Email Address"></div>
                    <div class="form-group"><textarea rows="4" placeholder="How can we assist you today?"></textarea></div>
                    <button class="btn btn-primary" style="width: 100%; justify-content: center; padding: 15px;">Send Message</button>
                </div>
            </div>
        </section>
    </div>

    <footer>
        <div class="footer-grid">
            <div>
                <span class="footer-logo">Health<b>4Q</b></span>
                <p style="font-size: 14px; line-height: 1.8;">The primary health management system of the University of Cebu. We merge technology with care to serve our community better.</p>
            </div>
            <div>
                <h4 class="footer-heading">Sitemap</h4>
                <ul class="footer-links">
                    <li><a onclick="showSection('home')">Home Page</a></li>
                    <li><a onclick="showSection('about')">Mission & Vision</a></li>
                    <li><a onclick="showSection('services')">Our Services</a></li>
                    <li><a onclick="showSection('contact')">Contact Support</a></li>
                </ul>
            </div>
            <div>
                <h4 class="footer-heading">Access</h4>
                <ul class="footer-links">
                    <li><a href="login.php">User Login</a></li>
                    <li><a href="register.php">Create Account</a></li>
                    <li><a href="#">Privacy Policy</a></li>
                    <li><a href="#">Terms of Service</a></li>
                </ul>
            </div>
            <div>
                <h4 class="footer-heading">Clinic Hours</h4>
                <p style="font-size: 14px; color: #f8fafc;">Mon - Fri: 8:00 AM - 5:00 PM</p>
                <p style="font-size: 14px; margin-top: 8px;">Sat: 8:00 AM - 12:00 PM</p>
                <p style="font-size: 12px; margin-top: 20px; font-style: italic;">Closed on Sundays & Holidays</p>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; 2026 Health4Q Systems. All Rights Reserved.</p>
            <p>Designed for University of Cebu</p>
        </div>
    </footer>

    <script>
        function showSection(sectionId) {
            // Hide all sections
            document.querySelectorAll('.content-section').forEach(s => {
                s.classList.remove('active');
                s.style.display = 'none';
            });

            // Show target section
            const target = document.getElementById(sectionId);
            if(target) {
                target.style.display = 'block';
                setTimeout(() => target.classList.add('active'), 10);
            }

            // Update Nav Links
            document.querySelectorAll('.nav-links a').forEach(a => a.classList.remove('active'));
            const navLink = document.getElementById('nav-' + sectionId);
            if(navLink) navLink.classList.add('active');

            // Scroll to top
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    </script>
</body>
</html>