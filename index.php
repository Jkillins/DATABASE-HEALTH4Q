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
    <title>Health4Q | Efficiency Meets Compassion</title>
    <link rel="icon" type="image/png" href="images/Logo_only.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0f172a;
            --clinical-blue: #0288B4;
            --slate: #64748b;
            --border: #e2e8f0;
            --bg-light: #f8fafc;
            --white: #ffffff;
            --transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: var(--white); color: var(--navy); line-height: 1.6; overflow-x: hidden; display: flex; flex-direction: column; min-height: 100vh; }

        /* --- NAVIGATION --- */
        nav {
            display: flex; justify-content: space-between; align-items: center;
            padding: 15px 10%; background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(12px); position: sticky; top: 0; z-index: 1000;
            border-bottom: 1px solid var(--border);
        }
        .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; cursor: pointer; }
        .brand img { height: 38px; }
        .brand span { font-size: 22px; font-weight: 700; color: var(--navy); letter-spacing: -0.5px; }

        .nav-links { display: flex; gap: 30px; }
        .nav-links a { 
            text-decoration: none; color: var(--slate); 
            font-weight: 500; font-size: 14px; transition: var(--transition);
            cursor: pointer;
        }
        .nav-links a:hover, .nav-links a.active { color: var(--clinical-blue); }

        .btn { padding: 11px 24px; border-radius: 6px; font-size: 14px; font-weight: 600; text-decoration: none; transition: var(--transition); display: inline-block; cursor: pointer; border: none; }
        .btn-outline { border: 1px solid var(--border); color: var(--navy); background: transparent; }
        .btn-primary { background: var(--navy); color: white; }
        .btn-primary:hover { background: var(--clinical-blue); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(2, 136, 180, 0.3); }

        /* --- SPA CONTENT --- */
        .content-wrapper { flex: 1; }
        .content-section {
            display: none; opacity: 0; transform: translateY(20px);
            transition: var(--transition); padding: 80px 10%; min-height: 70vh;
        }
        .content-section.active { display: block; opacity: 1; transform: translateY(0); }

        /* --- UI COMPONENTS --- */
        .section-header { text-align: center; margin-bottom: 60px; }
        .section-header h6 { color: var(--clinical-blue); text-transform: uppercase; letter-spacing: 2px; font-size: 12px; margin-bottom: 10px; }
        .section-header h2 { font-size: 42px; font-weight: 800; letter-spacing: -1px; }

        .ui-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; margin-bottom: 60px; }
        
        .ui-card { 
            background: var(--white); border: 1px solid var(--border); 
            padding: 40px; border-radius: 20px; transition: var(--transition);
        }
        .ui-card:hover { border-color: var(--clinical-blue); transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .ui-card h3 { margin-bottom: 15px; font-size: 20px; display: flex; align-items: center; gap: 10px; }
        .ui-card p { color: var(--slate); font-size: 15px; }

        /* Values List */
        .values-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 40px; }
        .value-box { padding: 25px; background: var(--bg-light); border-radius: 15px; border-left: 4px solid var(--clinical-blue); }
        .value-box strong { display: block; margin-bottom: 5px; color: var(--navy); }
        .value-box span { font-size: 14px; color: var(--slate); }

        /* List Styling */
        .task-list { list-style: none; display: grid; grid-template-columns: 1fr 1fr; gap: 15px; max-width: 800px; margin: 0 auto; }
        .task-list li { background: var(--white); border: 1px solid var(--border); padding: 15px 20px; border-radius: 10px; font-size: 15px; display: flex; align-items: center; gap: 12px; }
        .task-list li::before { content: '✓'; color: var(--clinical-blue); font-weight: 900; }

        /* Contact Layout */
        .contact-container { display: grid; grid-template-columns: 1fr 1.2fr; gap: 80px; align-items: center; }
        .contact-form { background: var(--bg-light); padding: 40px; border-radius: 24px; border: 1px solid var(--border); }
        .form-group { margin-bottom: 20px; }
        .form-group input, .form-group textarea { width: 100%; padding: 12px; border-radius: 8px; border: 1px solid var(--border); outline: none; transition: var(--transition); }

        /* --- FOOTER --- */
        .main-footer { 
            background-color: #f8fafc; color: var(--navy); padding: 80px 10% 50px; 
            display: flex; flex-direction: column; align-items: center; text-align: center;
            border-top: 1px solid #e2e8f0; margin-top: auto;
        }
        .footer-social { display: flex; gap: 60px; justify-content: center; margin-bottom: 40px; }
        .social-item { display: flex; flex-direction: column; align-items: center; gap: 12px; text-decoration: none; color: var(--slate); transition: var(--transition); cursor: pointer; }
        .icon-box { width: 52px; height: 52px; background: var(--white); border: 1px solid #e2e8f0; border-radius: 14px; display: flex; align-items: center; justify-content: center; transition: var(--transition); box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .icon-box img { width: 26px; height: 26px; object-fit: contain; }
        .social-item:hover .icon-box { transform: translateY(-8px); box-shadow: 0 10px 20px rgba(2, 136, 180, 0.15); border-color: var(--clinical-blue); }
        .social-item:hover { color: var(--clinical-blue); }
        .footer-divider { width: 40px; height: 2px; background: #cbd5e1; margin-bottom: 25px; border-radius: 10px; }
        .footer-copy-text { font-size: 12px; color: var(--slate); letter-spacing: 0.3px; line-height: 1.8; }

        @media (max-width: 968px) { .contact-container { grid-template-columns: 1fr; } .task-list { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <nav>
        <div class="brand" onclick="showSection('home')">
            <img src="images/Logo_only.png" alt="Health4Q">
            <span>Health4Q</span>
        </div>
        <div class="nav-links">
            <a onclick="showSection('home')" id="nav-home" class="active">Home</a>
            <a onclick="showSection('about')" id="nav-about">About Us</a>
            <a onclick="showSection('services')" id="nav-services">Services</a>
            <a onclick="showSection('contact')" id="nav-contact">Contact Us</a>
        </div>
        <div>
            <?php if ($is_logged_in): ?>
                <a href="dashboard.php" class="btn btn-primary">Portal</a>
            <?php else: ?>
                <a href="register.php" class="btn btn-primary">Sign Up</a>
              <a href="login.php" class="btn btn-outline">Sign In</a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="content-wrapper">
        <section id="home" class="content-section active" style="background: radial-gradient(circle at 50% 130%, #e0f2fe 0%, #ffffff 60%); text-align: center;">
            <div style="padding-top: 50px;">
                <h1 style="font-size: 64px; font-weight: 800; line-height: 1.1; margin-bottom: 25px;">Health4Q—Where Efficiency <br> Meets Compassion</h1>
                <p style="font-size: 20px; color: var(--slate); max-width: 800px; margin: 0 auto 40px;">Empowering the University of Cebu community with seamless healthcare management tools.</p>
                <div style="display: flex; gap: 15px; justify-content: center;">
                    <a onclick="showSection('contact')" class="btn btn-primary">Deploy Platform</a>
                    <a onclick="showSection('services')" class="btn btn-outline">View Modules</a>
                </div>
            </div>
        </section>

        <section id="about" class="content-section">
            <div class="section-header">
                <h6>Learn More</h6>
                <h2>About Health4Q</h2>
            </div>
            
            <p style="text-align: center; max-width: 850px; margin: 0 auto 60px; color: var(--slate); font-size: 18px;">
                <strong>HEALTH4Q</strong> is a modern health technology platform dedicated to bridging the gap between healthcare providers and patients. By integrating cutting-edge technology with empathy, we simplify medical processes and enhance the quality of life.
            </p>

            <div class="ui-grid">
                <div class="ui-card">
                    <h3>🎯 Our Mission</h3>
                    <p>To promote a healthier community by providing accessible, efficient, and compassionate digital health solutions tailored for the academic environment.</p>
                </div>
                <div class="ui-card">
                    <h3>👁️ Our Vision</h3>
                    <p>To become a trusted leader in digital healthcare innovation, empowering individuals to take control of their health through seamless and secure technology.</p>
                </div>
            </div>

            <div class="section-header" style="margin-top: 80px; margin-bottom: 40px;">
                <h6>Our Principles</h6>
                <h2>Core Values</h2>
            </div>
            <div class="values-grid">
                <div class="value-box"><strong>Innovation</strong><span>We continuously evolve to provide the best digital health tools.</span></div>
                <div class="value-box"><strong>Integrity</strong><span>We uphold transparency and trust in every clinical record.</span></div>
                <div class="value-box"><strong>Compassion</strong><span>We care deeply about the people we serve at UC.</span></div>
                <div class="value-box"><strong>Excellence</strong><span>We strive for the highest standards in every module.</span></div>
            </div>

            <div class="section-header" style="margin-top: 100px; margin-bottom: 40px;">
                <h6>Our Purpose</h6>
                <h2>What We Do</h2>
            </div>
            <ul class="task-list">
                <li>Provide real-time health monitoring</li>
                <li>Offer appointment scheduling</li>
                <li>Ensure secure data management</li>
                <li>Deliver insights to improve health outcomes</li>
            </ul>
        </section>

        <section id="services" class="content-section" style="background: var(--bg-light);">
            <div class="section-header">
                <h6>Our Capabilities</h6>
                <h2>Clinical Modules</h2>
            </div>
            <div class="ui-grid">
                <div class="ui-card"><span style="font-size:40px">🩺</span><h3>Medical Unit</h3><p>Centralized tracking for general check-ups and medical history.</p></div>
                <div class="ui-card"><span style="font-size:40px">🦷</span><h3>Dental Services</h3><p>Automated dental scheduling and oral health logs.</p></div>
                <div class="ui-card"><span style="font-size:40px">📄</span><h3>Health Records</h3><p>Secure digital storage for medical certifications and referrals.</p></div>
            </div>
        </section>

        <section id="contact" class="content-section">
            <div class="contact-container">
                <div class="contact-info">
                    <div class="section-header" style="text-align: left; margin-bottom: 30px;">
                        <h6>Get In Touch</h6>
                        <h2>Ready to assist you</h2>
                    </div>
                    <p>University of Cebu Main Campus | Sanciangko St, Cebu City<br>📞 +63 (32) 255-7777<br>✉️ support@health4q.edu.ph</p>
                </div>
                <div class="contact-form">
                    <div class="form-group"><input type="text" placeholder="Full Name"></div>
                    <div class="form-group"><input type="email" placeholder="Email Address"></div>
                    <div class="form-group"><textarea rows="4" placeholder="Message"></textarea></div>
                    <button class="btn btn-primary" style="width: 100%;">Send Message</button>
                </div>
            </div>
        </section>
    </div>

    <footer class="main-footer">
        <div class="footer-social">
            <div class="social-item" onclick="showSection('home')">
                <div class="icon-box"><img src="images/Facebook_Logo.png" alt="Home"></div>
                <span>Home</span>
            </div>
            <div class="social-item" onclick="showSection('contact')">
                <div class="icon-box"><img src="images/Instagram_Logo.jpg" alt="Contact Us"></div>
                <span>Contact Us</span>
            </div>
            <div class="social-item" onclick="showSection('about')">
                <div class="icon-box"><img src="images/Twitter_Logo.png" alt="Our Team"></div>
                <span>Our Team</span>
            </div>
        </div>
        <div class="footer-divider"></div>
        <p class="footer-copy-text">
            &copy; 2026 <strong>Health4Q Systems</strong><br>
            University of Cebu Main Campus | Efficiency Meets Compassion<br>
            All rights reserved.
        </p>
    </footer>

    <script>
        function showSection(sectionId) {
            document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
            document.querySelectorAll('.nav-links a').forEach(a => a.classList.remove('active'));

            const target = document.getElementById(sectionId);
            if(target) target.classList.add('active');

            const navLink = document.getElementById('nav-' + sectionId);
            if(navLink) navLink.classList.add('active');

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    </script>
</body>
</html>