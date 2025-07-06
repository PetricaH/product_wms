<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOTSOWMS - Soluții Complete de Gestionare Depozit</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ===== CSS VARIABLES - MONOCHROME COLOR SCHEME ===== */
        :root {
            --black: #0F1013;
            --dark-gray: #1A1A1D;
            --darker-gray: #16161A;
            --light-gray: #94A1B2;
            --lighter-gray: #AAAAAA;
            --white: #FEFFFF;
        }

        /* ===== GLOBAL STYLES ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--black);
            color: var(--white);
            overflow-x: hidden;
            line-height: 1.6;
        }

        /* Prevent scrolling when mobile menu is open */
        body.nav-open {
            overflow: hidden;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        section {
            padding: 5rem 0;
        }

        /* ===== HEADER ===== */
        .header {
            position: fixed;
            top: 0;
            width: 100%;
            background: rgba(15, 16, 19, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px); /* Safari support */
            z-index: 1000;
            padding: 1rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }
        
        /* ===== FIX: ADDED THIS NEW RULE ===== */
        /* This disables the filter when the menu is open, fixing the layout bug. */
        .header.nav-open {
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
        }

        .nav {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 2rem;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: 500;
            color: var(--white);
            z-index: 1001; /* Ensure logo is above mobile menu background */
        }

        .nav-links {
            display: flex;
            list-style: none;
            gap: 2rem;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--lighter-gray);
            font-weight: 400;
            font-size: 0.95rem;
            transition: color 0.3s ease;
        }

        .nav-links a:hover {
            color: var(--white);
        }

        .cta-button {
            background-color: var(--white);
            color: var(--black);
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .cta-button:hover {
            background-color: rgba(255, 255, 255, 0.9);
            transform: translateY(-2px);
        }
        
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.8rem;
            color: var(--lighter-gray);
            cursor: pointer;
            z-index: 1001; 
            transition: color 0.3s ease;
        }
        .mobile-menu-toggle:hover {
            color: var(--white);
        }

        /* ===== HERO SECTION ===== */
        .hero {
            min-height: 100vh;
            padding-top: 80px;
            display: flex;
            align-items: center;
            background: linear-gradient(135deg, var(--black) 0%, var(--darker-gray) 100%);
            position: relative;
            overflow: hidden;
        }

        .hero-content {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 4rem;
            align-items: center;
            width: 100%;
        }

        .hero-text-content {
            max-width: 600px;
        }

        .hero-title {
            font-size: 3.5rem;
            font-weight: 500;
            color: var(--white);
            margin-bottom: 1rem;
            line-height: 1.2;
        }

        .hero-divider {
            width: 60px;
            height: 2px;
            background-color: var(--white);
            margin: 2rem 0;
        }

        .hero-description {
            font-size: 1.2rem;
            color: var(--light-gray);
            margin-bottom: 3rem;
            line-height: 1.6;
        }

        .hero-expertise {
            margin-bottom: 3rem;
        }

        .expertise-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 2rem;
            gap: 1.5rem;
        }

        .expertise-number {
            font-size: 1.2rem;
            font-weight: 500;
            color: var(--white);
            background-color: rgba(255, 255, 255, 0.1);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .expertise-text h3 {
            font-size: 1.2rem;
            color: var(--white);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .expertise-text p {
            color: var(--lighter-gray);
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .hero-cta {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .btn-primary {
            padding: 14px 32px;
            background-color: var(--white);
            color: var(--black);
            border: none;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border-radius: 4px;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary:hover {
            background-color: rgba(255, 255, 255, 0.9);
            transform: translateY(-3px);
        }

        .hero-visual {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .dashboard-mockup {
            width: 100%;
            background-color: var(--dark-gray);
            border-radius: 6px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .mockup-header {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .mockup-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
        }

        .mockup-content {
            height: 200px;
            background: linear-gradient(135deg, var(--darker-gray) 0%, var(--black) 100%);
            border-radius: 4px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .system-preview {
            color: var(--lighter-gray);
            font-size: 0.9rem;
            text-align: center;
        }

        .clients-section {
            text-align: center;
        }

        .clients-label {
            font-size: 0.9rem;
            color: var(--lighter-gray);
            margin-bottom: 1rem;
        }

        .tech-logos {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .tech-logo {
            padding: 0.5rem 1rem;
            background-color: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            color: var(--lighter-gray);
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }

        .tech-logo:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--white);
        }

        .section-header {
            text-align: center;
            margin-bottom: 4rem;
        }

        .section-header h2 {
            font-size: 2.5rem;
            font-weight: 500;
            color: var(--white);
            margin-bottom: 1rem;
        }

        .section-divider {
            width: 60px;
            height: 2px;
            background-color: var(--white);
            margin: 0 auto 2rem;
        }

        .section-description {
            font-size: 1.1rem;
            color: var(--lighter-gray);
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.6;
        }

        .features {
            background-color: var(--darker-gray);
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
        }

        .feature-card {
            background-color: var(--dark-gray);
            padding: 2.5rem;
            border-radius: 6px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }

        .feature-number {
            font-size: 1.2rem;
            font-weight: 500;
            color: var(--white);
            background-color: rgba(255, 255, 255, 0.1);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
        }

        .feature-card h3 {
            font-size: 1.25rem;
            font-weight: 500;
            margin-bottom: 1rem;
            color: var(--white);
        }

        .feature-card p {
            color: var(--lighter-gray);
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .benefits {
            background-color: var(--black);
        }

        .benefits-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
        }

        .benefits-text h2 {
            font-size: 2.5rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
            color: var(--white);
        }

        .benefits-text p {
            color: var(--lighter-gray);
            font-size: 1.1rem;
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .benefits-list {
            list-style: none;
            margin: 2rem 0;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .benefit-item {
            display: flex;
            align-items: flex-start;
            gap: 1.5rem;
        }

        .benefit-number {
            width: 40px;
            height: 40px;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-weight: 500;
            color: var(--white);
        }

        .benefit-text h4 {
            color: var(--white);
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .benefit-text p {
            color: var(--lighter-gray);
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2rem;
        }

        .stat-card {
            background-color: var(--dark-gray);
            padding: 2rem;
            border-radius: 6px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 500;
            color: var(--white);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--lighter-gray);
            font-weight: 400;
            font-size: 0.95rem;
        }

        .process {
            background-color: var(--darker-gray);
        }

        .process-timeline {
            position: relative;
            max-width: 800px;
            margin: 0 auto;
        }

        .process-timeline::before {
            content: '';
            position: absolute;
            left: 29px;
            top: 0;
            bottom: 0;
            width: 2px;
            background-color: rgba(255, 255, 255, 0.3);
        }

        .timeline-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 3rem;
            position: relative;
        }

        .timeline-item:last-child {
            margin-bottom: 0;
        }

        .timeline-number {
            width: 60px;
            height: 60px;
            background-color: var(--white);
            color: var(--black);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: 500;
            flex-shrink: 0;
            position: relative;
            z-index: 2;
        }

        .timeline-content {
            margin-left: 2rem;
            padding: 2rem;
            background-color: var(--dark-gray);
            border-radius: 6px;
            flex: 1;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .timeline-content h3 {
            font-size: 1.3rem;
            color: var(--white);
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .timeline-content p {
            color: var(--lighter-gray);
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .cta-section {
            padding: 6rem 0;
            background: linear-gradient(135deg, var(--black) 0%, var(--darker-gray) 100%);
            text-align: center;
        }

        .cta-content h2 {
            font-size: 2.5rem;
            font-weight: 500;
            margin-bottom: 1rem;
            color: var(--white);
        }

        .cta-content p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            color: var(--lighter-gray);
        }

        .footer {
            background: var(--darker-gray);
            color: var(--lighter-gray);
            padding: 3rem 0 1rem;
            text-align: center;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .footer p {
            font-size: 0.9rem;
        }

        @media (max-width: 992px) {
            .hero-content {
                grid-template-columns: 1fr;
                text-align: center;
                gap: 3rem;
                justify-items: center;
            }
            .hero-divider {
                margin: 2rem auto;
            }
            .expertise-item {
                text-align: left;
            }
            .hero-cta {
                justify-content: center;
            }
            .benefits-content {
                grid-template-columns: 1fr;
                gap: 3rem;
                text-align: center;
            }
            .benefit-item {
                text-align: left;
            }
            .benefits-text {
                max-width: 600px;
                margin: 0 auto;
            }
        }

        @media (max-width: 768px) {
            .cta-button {
                display: none;
            }
            .mobile-menu-toggle {
                display: block;
            }
            
            .nav-links {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100vh;
            background-color: var(--black);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            transform: translateX(100%);
            transition: transform 0.3s ease-in-out;
        }

        .nav-links.nav-open {
            transform: translateX(0);
            height: 100vh;
        }
            .nav-links a {
                font-size: 1.5rem;
            }

            .hero-title {
                font-size: 2.5rem;
            }
            .hero-description {
                font-size: 1.1rem;
            }
            .hero-cta {
                flex-direction: column;
                align-items: center;
                gap: 1.5rem;
            }
            .features-grid {
                grid-template-columns: 1fr;
            }
            .feature-card {
                padding: 2rem;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .process-timeline::before {
                left: 24px;
            }
            .timeline-number {
                width: 50px;
                height: 50px;
                font-size: 1rem;
            }
            .timeline-content {
                margin-left: 1.5rem;
                padding: 1.5rem;
            }
        }

        @media (max-width: 576px) {
            .container, .nav {
                padding: 0 1rem;
            }
            .hero-title {
                font-size: 2.2rem;
            }
            .section-header h2, .benefits-text h2, .cta-content h2 {
                font-size: 2rem;
            }
            .dashboard-mockup {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <nav class="nav">
            <div class="logo">NOTSOWMS</div>
            <ul class="nav-links">
                <li><a href="#features">Funcționalități</a></li>
                <li><a href="#benefits">Beneficii</a></li>
                <li><a href="#process">Proces</a></li>
                <li><a href="#demo">Contact</a></li>
            </ul>
            <a href="mailto:hello@notsomarketing.com?subject=Demo%20Gratuit%20NOTSOWMS" class="cta-button">Demo Gratuit</a>
            <button class="mobile-menu-toggle" aria-label="Toggle Menu">☰</button>
        </nav>
    </header>

    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <div class="hero-text-content">
                    <h1 class="hero-title">Soluții tehnice pentru depozite în creștere</h1>
                    <div class="hero-divider"></div>
                    <p class="hero-description">Dezvolt sisteme WMS personalizate și automatizări care ajută afacerile românești să funcționeze mai eficient și mai eficace.</p>
                    <div class="hero-expertise">
                        <div class="expertise-item">
                            <div class="expertise-number">1</div>
                            <div class="expertise-text">
                                <h3>Gestionare Inteligentă Inventar</h3>
                                <p>Sisteme complete de urmărire stoc cu scanare mobilă și integrare SmartBill pentru conformitate fiscală românească.</p>
                            </div>
                        </div>
                        <div class="expertise-item">
                            <div class="expertise-number">2</div>
                            <div class="expertise-text">
                                <h3>Automatizarea Proceselor</h3>
                                <p>Fluxuri de lucru simplificate pentru eliminarea sarcinilor repetitive și optimizarea operațiunilor de depozit.</p>
                            </div>
                        </div>
                    </div>
                    <div class="hero-cta">
                         <a href="#demo" class="btn-primary">Solicită Demo Gratuit</a>
                    </div>
                </div>
                <div class="hero-visual">
                    <div class="dashboard-mockup">
                        <div class="mockup-header">
                            <div class="mockup-dot"></div>
                            <div class="mockup-dot"></div>
                            <div class="mockup-dot"></div>
                        </div>
                        <div class="mockup-content">
                            <div class="system-preview">
                                NOTSOWMS Dashboard<br>
                                <small>Control complet asupra inventarului</small>
                            </div>
                        </div>
                    </div>
                    <div class="clients-section">
                        <p class="clients-label">Integrări și compatibilități</p>
                        <div class="tech-logos">
                            <div class="tech-logo">SmartBill</div>
                            <div class="tech-logo">Mobile Scan</div>
                            <div class="tech-logo">API REST</div>
                            <div class="tech-logo">Cloud</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <section class="features" id="features">
        <div class="container">
            <div class="section-header">
                <h2>Capabilități Complete de Gestionare</h2>
                <div class="section-divider"></div>
                <p class="section-description">Toate instrumentele necesare pentru operarea eficientă a depozitului, adaptate specificului afacerilor românești.</p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-number">1</div>
                    <h3>Gestionare Produse</h3>
                    <p>Administrare completă produse cu SKU-uri, categorii și prețuri. Sincronizare automată cu sisteme de facturare și contabilitate românești.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-number">2</div>
                    <h3>Picking Mobil</h3>
                    <p>Aplicație mobilă pentru echipa de depozit cu scanare coduri QR/barcode și verificare locații în timp real pentru precizie maximă.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-number">3</div>
                    <h3>Control Inventar</h3>
                    <p>Monitorizare stocuri în timp real cu alerte automate pentru stoc scăzut și rapoarte detaliate de mișcări inventar.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-number">4</div>
                    <h3>Management Locații</h3>
                    <p>Organizare optimă depozit pe zone și locații pentru reducerea timpilor de căutare și optimizarea rutelor de picking.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-number">5</div>
                    <h3>Procesare Comenzi</h3>
                    <p>Workflow complet de la primirea comenzii la expediere cu urmărire status și notificări automate pentru clienți.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-number">6</div>
                    <h3>Integrare SmartBill</h3>
                    <p>Sincronizare automată cu SmartBill pentru facturare conformă legislației românești și transfer rapid date produse.</p>
                </div>
            </div>
        </div>
    </section>
    <section class="benefits" id="benefits">
        <div class="container">
            <div class="benefits-content">
                <div class="benefits-text">
                    <h2>De ce NOTSOWMS?</h2>
                    <p>Sistem dezvoltat specific pentru cerințele afacerilor românești cu focus pe eficiență, precizie și conformitate fiscală.</p>
                    <div class="benefits-list">
                        <div class="benefit-item">
                            <div class="benefit-number">1</div>
                            <div class="benefit-text">
                                <h4>Reducerea erorilor cu 95%</h4>
                                <p>Sisteme de verificare automată și scanare mobile elimină aproape complet erorile umane de inventar.</p>
                            </div>
                        </div>
                        <div class="benefit-item">
                            <div class="benefit-number">2</div>
                            <div class="benefit-text">
                                <h4>Creșterea productivității cu 40%</h4>
                                <p>Optimizarea rutelor și automatizarea proceselor permite echipei să lucreze mai eficient.</p>
                            </div>
                        </div>
                        <div class="benefit-item">
                            <div class="benefit-number">3</div>
                            <div class="benefit-text">
                                <h4>Conformitate fiscală completă</h4>
                                <p>Integrare perfectă cu SmartBill și conformitate cu toate cerințele ANAF pentru stocuri și facturare.</p>
                            </div>
                        </div>
                    </div>
                    <a href="#demo" class="btn-primary">Solicită Demonstrație</a>
                </div>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number">95%</div>
                        <div class="stat-label">Reducerea Erorilor</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">40%</div>
                        <div class="stat-label">Creșterea Eficienței</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">30</div>
                        <div class="stat-label">Zile Implementare</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">24/7</div>
                        <div class="stat-label">Suport Tehnic</div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <section class="process" id="process">
        <div class="container">
            <div class="section-header">
                <h2>Procesul de Implementare</h2>
                <div class="section-divider"></div>
                <p class="section-description">Implementare rapidă și eficientă în maxim 30 de zile cu suport complet și training pentru echipa ta.</p>
            </div>
            <div class="process-timeline">
                <div class="timeline-item">
                    <div class="timeline-number">1</div>
                    <div class="timeline-content">
                        <h3>Analiză și Planificare</h3>
                        <p>Analizăm în detaliu operațiunile curente ale depozitului tău și identificăm punctele de optimizare pentru a crea un plan personalizat de implementare.</p>
                    </div>
                </div>
                <div class="timeline-item">
                    <div class="timeline-number">2</div>
                    <div class="timeline-content">
                        <h3>Configurare și Personalizare</h3>
                        <p>Configurăm sistemul conform cerințelor specifice, includem produsele existente și setăm integrările cu SmartBill și alte sisteme utilizate.</p>
                    </div>
                </div>
                <div class="timeline-item">
                    <div class="timeline-number">3</div>
                    <div class="timeline-content">
                        <h3>Training și Lansare</h3>
                        <p>Oferim training complet echipei tale și suport în primele săptămâni de utilizare pentru a asigura o tranziție fără probleme.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <section class="cta-section" id="demo">
        <div class="container">
            <div class="cta-content">
                <h2>Gata să Optimizezi Depozitul?</h2>
                <p>Începe cu o demonstrație personalizată și vezi cum NOTSOWMS poate transforma operațiunile tale.</p>
                <a href="mailto:hello@notsomarketing.com?subject=Demo%20Gratuit%20NOTSOWMS&body=Salut,%0A%0AAs%20dori%20să%20programez%20un%20demo%20gratuit%20pentru%20NOTSOWMS.%0A%0AInformații%20despre%20compania%20noastră:%0A-%20Nume%20companie:%20%0A-%20Persoană%20de%20contact:%20%0A-%20Telefon:%20%0A-%20Tipul%20afacerii:%20%0A-%20Mărimea%20depozitului:%20%0A-%20Numărul%20de%20produse%20gestionate:%20%0A-%20Sistemele%20actuale%20utilizate:%20%0A%0AMultumesc%20pentru%20timpul%20acordat!" class="btn-primary">Solicită Demo Gratuit</a>
            </div>
        </div>
    </section>
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 NOTSOWMS. Toate drepturile rezervate. Soluții tehnice pentru depozite în creștere.</p>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            
            const mobileToggle = document.querySelector('.mobile-menu-toggle');
            const navLinks = document.querySelector('.nav-links');
            const body = document.querySelector('body');
            // ===== FIX: Get the header element =====
            const header = document.querySelector('.header');

            // Toggle menu open/close
            mobileToggle.addEventListener('click', () => {
                navLinks.classList.toggle('nav-open');
                body.classList.toggle('nav-open');
                // ===== FIX: Toggle the class on the header too =====
                header.classList.toggle('nav-open');

                if (navLinks.classList.contains('nav-open')) {
                    mobileToggle.innerHTML = '&times;';
                    mobileToggle.style.fontSize = '2.5rem';
                } else {
                    mobileToggle.innerHTML = '☰';
                    mobileToggle.style.fontSize = '1.8rem';
                }
            });

            // Close menu when a link is clicked
            document.querySelectorAll('.nav-links a').forEach(link => {
                link.addEventListener('click', () => {
                    if (navLinks.classList.contains('nav-open')) {
                        navLinks.classList.remove('nav-open');
                        body.classList.remove('nav-open');
                        // ===== FIX: Make sure to remove the class from the header =====
                        header.classList.remove('nav-open');
                        mobileToggle.innerHTML = '☰';
                        mobileToggle.style.fontSize = '1.8rem';
                    }
                });
            });

            // --- Header Style on Scroll ---
            window.addEventListener('scroll', () => {
                // We only apply this effect if the menu is not open
                if (!header.classList.contains('nav-open')) {
                    if (window.scrollY > 50) {
                        header.style.background = 'rgba(15, 16, 19, 0.98)';
                        header.style.boxShadow = '0 2px 10px rgba(0,0,0,0.2)';
                    } else {
                        header.style.background = 'rgba(15, 16, 19, 0.95)';
                        header.style.boxShadow = 'none';
                    }
                }
            });

        });
    </script>
</body>
</html>