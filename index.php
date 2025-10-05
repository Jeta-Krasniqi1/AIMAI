<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AimAl - Your Smart Career Assistant</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 50%, #16213e 100%);
            color: #ffffff;
            overflow-x: hidden;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Header */
        header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: rgba(15, 15, 35, 0.9);
            backdrop-filter: blur(20px);
            z-index: 1000;
            padding: 1rem 0;
            transition: all 0.3s ease;
        }

        nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(45deg, #00d4ff, #7c3aed);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .nav-links {
            display: flex;
            list-style: none;
            gap: 2rem;
        }

        .nav-links a {
            color: #ffffff;
            text-decoration: none;
            transition: all 0.3s ease;
            position: relative;
        }

        .nav-links a:hover {
            color: #00d4ff;
            transform: translateY(-2px);
        }

        .cta-btn {
            background: linear-gradient(45deg, #00d4ff, #7c3aed);
            color: white;
            padding: 0.8rem 2rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 212, 255, 0.3);
        }

        .cta-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 212, 255, 0.5);
        }

        /* Hero Section */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
        }

        .hero-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
            padding-top: 120px;
        }

        .hero-text h1 {
            font-size: 3.5rem;
            font-weight: 900;
            line-height: 1.2;
            margin-bottom: 1.5rem;
            background: linear-gradient(45deg, #ffffff, #00d4ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-text p {
            font-size: 1.2rem;
            line-height: 1.6;
            margin-bottom: 2rem;
            color: #b0b0b0;
        }

        .hero-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .btn-primary {
            background: linear-gradient(45deg, #00d4ff, #7c3aed);
            color: white;
            padding: 1rem 2.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 212, 255, 0.3);
        }

        .btn-secondary {
            background: transparent;
            color: #00d4ff;
            padding: 1rem 2.5rem;
            border: 2px solid #00d4ff;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover, .btn-secondary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 212, 255, 0.5);
        }

        .hero-visual {
            position: relative;
            height: 500px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .ai-circle {
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: linear-gradient(45deg, #00d4ff, #7c3aed);
            position: relative;
            animation: pulse 2s infinite;
            box-shadow: 0 0 50px rgba(0, 212, 255, 0.3);
        }

        .ai-circle::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 80%;
            height: 80%;
            border-radius: 50%;
            background: #0f0f23;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .ai-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 2rem;
            font-weight: 800;
            z-index: 2;
        }

        .floating-card {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: float 3s ease-in-out infinite;
        }

        .card-1 { top: 20%; left: 10%; animation-delay: 0s; }
        .card-2 { top: 60%; right: 10%; animation-delay: 1s; }
        .card-3 { bottom: 20%; left: 20%; animation-delay: 2s; }

        /* Features Section */
        .features {
            padding: 100px 0;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        }

        .features h2 {
            text-align: center;
            font-size: 3rem;
            margin-bottom: 3rem;
            background: linear-gradient(45deg, #ffffff, #00d4ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
            margin-top: 3rem;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(45deg, #00d4ff, #7c3aed);
        }

        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 212, 255, 0.2);
        }

        .feature-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #00d4ff;
        }

        .feature-card h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: #ffffff;
        }

        .feature-card p {
            color: #b0b0b0;
            line-height: 1.6;
        }

        /* For Companies Section */
        .companies {
            padding: 100px 0;
            background: linear-gradient(135deg, #16213e 0%, #0f0f23 100%);
        }

        .companies-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
        }

        .companies-text h2 {
            font-size: 2.5rem;
            margin-bottom: 2rem;
            background: linear-gradient(45deg, #ffffff, #00d4ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .companies-visual {
            position: relative;
            height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .company-bubbles {
            position: relative;
            width: 100%;
            height: 100%;
        }

        .bubble {
            position: absolute;
            background: linear-gradient(45deg, #00d4ff, #7c3aed);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            animation: float 4s ease-in-out infinite;
        }

        .bubble:nth-child(1) { width: 80px; height: 80px; top: 20%; left: 20%; }
        .bubble:nth-child(2) { width: 60px; height: 60px; top: 40%; right: 30%; animation-delay: 1s; }
        .bubble:nth-child(3) { width: 70px; height: 70px; bottom: 30%; left: 40%; animation-delay: 2s; }
        .bubble:nth-child(4) { width: 50px; height: 50px; top: 60%; right: 20%; animation-delay: 3s; }

        /* CTA Section */
        .cta-section {
            padding: 100px 0;
            background: linear-gradient(45deg, #00d4ff, #7c3aed);
            text-align: center;
        }

        .cta-section h2 {
            font-size: 3rem;
            margin-bottom: 2rem;
            color: white;
        }

        .cta-section p {
            font-size: 1.3rem;
            margin-bottom: 3rem;
            color: rgba(255, 255, 255, 0.9);
        }

        .cta-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-white {
            background: white;
            color: #7c3aed;
            padding: 1rem 2.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .btn-white:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        /* Footer */
        footer {
            background: #0f0f23;
            padding: 50px 0;
            text-align: center;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .footer-section h3 {
            color: #00d4ff;
            margin-bottom: 1rem;
        }

        .footer-section a {
            color: #b0b0b0;
            text-decoration: none;
            display: block;
            margin-bottom: 0.5rem;
            transition: color 0.3s ease;
        }

        .footer-section a:hover {
            color: #00d4ff;
        }

        /* Animations */
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .hero-content {
                grid-template-columns: 1fr;
                text-align: center;
            }
            
            .hero-text h1 {
                font-size: 2.5rem;
            }
            
            .nav-links {
                display: none;
            }
            
            .companies-content {
                grid-template-columns: 1fr;
            }
            
            .cta-buttons {
                flex-direction: column;
                align-items: center;
            }
        }
    </style>
</head>
<body>
    <header>
        <nav class="container">
            <div class="logo">AimAl</div>
            <ul class="nav-links">
                <li><a href="#features">Features</a></li>
                <li><a href="#companies">For Companies</a></li>
                <li><a href="#about">About</a></li>
                <li><a href="#contact">Contact</a></li>
            </ul>
            <a href="login.php" class="cta-btn">Log In</a>
        </nav>
    </header>

    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <div class="hero-text">
                    <h1>Your AI-Powered Career Compass</h1>
                    <p>Navigate your career journey with confidence. AimAl connects students and young professionals with personalized career insights, virtual mentorship, and direct access to opportunities.</p>
                    <div class="hero-buttons">
                        <a href="#" class="btn-primary">Start Your Journey</a>
                        <a href="#" class="btn-secondary">Watch Demo</a>
                    </div>
                </div>
                <div class="hero-visual">
                    <div class="ai-circle">
                        <div class="ai-text">AI</div>
                    </div>
                    <div class="floating-card card-1">
                        <div>💼 Job Matches</div>
                        <div style="font-size: 0.8rem; color: #00d4ff;">95% compatibility</div>
                    </div>
                    <div class="floating-card card-2">
                        <div>🎯 Career Path</div>
                        <div style="font-size: 0.8rem; color: #00d4ff;">Personalized</div>
                    </div>
                    <div class="floating-card card-3">
                        <div>💬 AI Mentor</div>
                        <div style="font-size: 0.8rem; color: #00d4ff;">24/7 Support</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="features" id="features">
        <div class="container">
            <h2>Revolutionizing Career Discovery</h2>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">🎯</div>
                    <h3>Smart Job Matching</h3>
                    <p>Get real-time insights on in-demand jobs based on your region, including salary ranges and required skills. Our AI analyzes market trends to keep you ahead of the curve.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">👥</div>
                    <h3>Virtual Professional Network</h3>
                    <p>Simulate conversations with professionals from any field - from firefighters to CEOs. Even chat with famous experts and company insiders to gain authentic industry insights.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🚀</div>
                    <h3>Personal Motivation Coach</h3>
                    <p>Your customizable AI coach adapts to your personality, remembers your progress, and keeps you motivated throughout your career journey.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">📄</div>
                    <h3>AI-Powered CV Builder</h3>
                    <p>Generate tailored resumes for specific jobs using our intelligent CV creator. Stand out with professionally optimized applications.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🤝</div>
                    <h3>Real Mentor Network</h3>
                    <p>Connect with experienced professionals who want to give back. Get guidance from real mentors in your field of interest.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🎓</div>
                    <h3>Accessible Career Planning</h3>
                    <p>Perfect for students and young professionals without access to traditional networks or internship opportunities. Level the playing field.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="companies" id="companies">
        <div class="container">
            <div class="companies-content">
                <div class="companies-text">
                    <h2>Partner with Tomorrow's Talent</h2>
                    <p>Companies can connect directly with students and emerging professionals, showcasing opportunities and sharing authentic company insights. Attract the right candidates early and build meaningful relationships with future talent.</p>
                    <div style="margin-top: 2rem;">
                        <a href="#" class="btn-primary">Partner with Us</a>
                    </div>
                </div>
                <div class="companies-visual">
                    <div class="company-bubbles">
                        <div class="bubble">Tech</div>
                        <div class="bubble">Finance</div>
                        <div class="bubble">Healthcare</div>
                        <div class="bubble">Design</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="cta-section">
        <div class="container">
            <h2>Ready to Shape Your Future?</h2>
            <p>Join thousands of students and professionals who are already using AimAl to navigate their career paths with confidence.</p>
            <div class="cta-buttons">
                <a href="#" class="btn-white">Get Started Free</a>
                <a href="#" class="btn-secondary">Book a Demo</a>
            </div>
        </div>
    </section>

    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>Product</h3>
                    <a href="#">Features</a>
                    <a href="#">Pricing</a>
                    <a href="#">Demo</a>
                </div>
                <div class="footer-section">
                    <h3>For Companies</h3>
                    <a href="#">Partner Program</a>
                    <a href="#">Talent Access</a>
                    <a href="#">Analytics</a>
                </div>
                <div class="footer-section">
                    <h3>Support</h3>
                    <a href="#">Help Center</a>
                    <a href="#">Contact Us</a>
                    <a href="#">Community</a>
                </div>
                <div class="footer-section">
                    <h3>Company</h3>
                    <a href="#">About</a>
                    <a href="#">Careers</a>
                    <a href="#">Privacy</a>
                </div>
            </div>
            <div style="border-top: 1px solid #333; padding-top: 2rem; color: #666;">
                <p>&copy; 2025 AimAl. All rights reserved. Empowering careers through AI.</p>
            </div>
        </div>
    </footer>

    <script>
        // Smooth scrolling for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Header scroll effect
        window.addEventListener('scroll', () => {
            const header = document.querySelector('header');
            if (window.scrollY > 100) {
                header.style.background = 'rgba(15, 15, 35, 0.95)';
            } else {
                header.style.background = 'rgba(15, 15, 35, 0.9)';
            }
        });

        // Add interactive hover effects
        document.querySelectorAll('.feature-card').forEach(card => {
            card.addEventListener('mouseenter', () => {
                card.style.transform = 'translateY(-10px) scale(1.02)';
            });
            
            card.addEventListener('mouseleave', () => {
                card.style.transform = 'translateY(0) scale(1)';
            });
        });

        // Animate elements on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe all feature cards
        document.querySelectorAll('.feature-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(30px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(card);
        });
    </script>
</body>
</html>