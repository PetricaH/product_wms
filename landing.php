<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOTSOWMS - Enterprise Warehouse Management System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,300,0,0" rel="stylesheet" />
    <style>
        :root {
            --black: #0F1013;
            --dark-gray: #1A1A1D;
            --darker-gray: #16161A;
            --light-gray: #94A1B2;
            --lighter-gray: #AAAAAA;
            --white: #FEFFFF;
            --transition: all 0.35s ease;
        }

        *, *::before, *::after {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            background: radial-gradient(140% 140% at 80% 8%, rgba(148, 161, 178, 0.18) 0%, rgba(148, 161, 178, 0.08) 38%, rgba(148, 161, 178, 0.02) 62%, rgba(15, 16, 19, 0) 85%),
                        radial-gradient(120% 120% at 18% 42%, rgba(148, 161, 178, 0.14) 0%, rgba(148, 161, 178, 0.06) 40%, rgba(148, 161, 178, 0.02) 65%, rgba(15, 16, 19, 0) 85%),
                        linear-gradient(180deg, var(--black) 0%, var(--darker-gray) 55%, var(--black) 100%);
            color: var(--white);
            min-height: 100vh;
        }

        a {
            color: inherit;
        }

        img {
            max-width: 100%;
            display: block;
        }

        header {
            position: fixed;
            inset: 0 0 auto 0;
            z-index: 1000;
            background: rgba(15, 16, 19, 0.92);
            backdrop-filter: blur(18px);
            border-bottom: 1px solid rgba(148, 161, 178, 0.08);
        }

        .nav-container {
            margin: 0 auto;
            max-width: 1180px;
            padding: 0.9rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-decoration: none;
        }

        .brand-icon {
            display: grid;
            place-items: center;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(148, 161, 178, 0.16), rgba(148, 161, 178, 0.05));
            border: 1px solid rgba(148, 161, 178, 0.22);
            box-shadow: 0 18px 36px rgba(15, 16, 19, 0.45);
            color: var(--white);
        }

        nav {
            display: flex;
            align-items: center;
            gap: 2.5rem;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 1.75rem;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .nav-links a {
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 400;
            color: var(--lighter-gray);
            transition: var(--transition);
        }

        .nav-links a:hover,
        .nav-links a:focus {
            color: var(--white);
        }

        .language-toggle {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 999px;
            padding: 0.25rem;
            border: 1px solid rgba(148, 161, 178, 0.22);
            background: linear-gradient(135deg, rgba(148, 161, 178, 0.1), rgba(26, 26, 29, 0.7));
            box-shadow: inset 0 0 0 1px rgba(254, 255, 255, 0.05);
        }

        .language-toggle button {
            border: none;
            background: transparent;
            color: var(--lighter-gray);
            font-size: 0.82rem;
            font-weight: 500;
            padding: 0.35rem 0.85rem;
            border-radius: 999px;
            cursor: pointer;
            transition: var(--transition);
        }

        .language-toggle button.active {
            color: var(--black);
            background: var(--white);
        }

        .menu-toggle {
            display: none;
            border: 1px solid rgba(148, 161, 178, 0.22);
            background: rgba(26, 26, 29, 0.8);
            color: var(--white);
            padding: 0.45rem 0.75rem;
            border-radius: 10px;
            cursor: pointer;
        }

        main {
            padding-top: 88px;
        }

        section {
            padding: 5rem 1.5rem;
        }

        .section-inner {
            max-width: 1180px;
            margin: 0 auto;
        }

        .section-header {
            max-width: 720px;
            margin-bottom: 2.5rem;
        }

        .section-title {
            font-size: clamp(1.8rem, 3vw, 2.6rem);
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .section-subtitle {
            margin-top: 0.85rem;
            color: var(--light-gray);
            font-weight: 400;
        }

        .hero {
            position: relative;
            overflow: hidden;
            background: linear-gradient(155deg, rgba(26, 26, 29, 0.96) 0%, rgba(15, 16, 19, 0.94) 45%, rgba(26, 26, 29, 0.98) 100%);
        }

        .hero::before {
            content: "";
            position: absolute;
            inset: -32% 35% 28% -28%;
            background: radial-gradient(120% 120% at 28% 32%, rgba(148, 161, 178, 0.22) 0%, rgba(148, 161, 178, 0.1) 42%, rgba(148, 161, 178, 0.04) 68%, rgba(15, 16, 19, 0) 88%);
            filter: blur(48px);
            opacity: 0.8;
            pointer-events: none;
        }

        .hero::after {
            content: "";
            position: absolute;
            inset: 26% -32% -36% 42%;
            background: radial-gradient(130% 130% at 72% 36%, rgba(148, 161, 178, 0.18) 0%, rgba(148, 161, 178, 0.08) 46%, rgba(148, 161, 178, 0.03) 72%, rgba(15, 16, 19, 0) 92%);
            filter: blur(60px);
            opacity: 0.75;
            pointer-events: none;
        }

        .hero-grid {
            position: relative;
            display: grid;
            gap: 3rem;
        }

        .hero-text {
            display: grid;
            gap: 1.5rem;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.55rem 1rem;
            border-radius: 999px;
            background: rgba(148, 161, 178, 0.1);
            border: 1px solid rgba(148, 161, 178, 0.2);
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--light-gray);
        }

        .hero-title {
            font-size: clamp(2.2rem, 4vw, 3.4rem);
            font-weight: 600;
            max-width: 720px;
            line-height: 1.1;
        }

        .hero-subtitle {
            color: var(--lighter-gray);
            max-width: 640px;
            font-weight: 400;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.95rem 1.9rem;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            border: 1px solid transparent;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--white), #d8dce2);
            color: var(--black);
            box-shadow: 0 20px 48px rgba(15, 16, 19, 0.45);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 26px 60px rgba(15, 16, 19, 0.55);
        }

        .btn-secondary {
            background: transparent;
            color: var(--white);
            border: 1px solid rgba(148, 161, 178, 0.35);
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            border-color: rgba(254, 255, 255, 0.6);
        }

        .hero-right {
            position: relative;
            border-radius: 28px;
            padding: 2.5rem;
            background: linear-gradient(145deg, rgba(26, 26, 29, 0.95), rgba(15, 16, 19, 0.9));
            border: 1px solid rgba(148, 161, 178, 0.18);
            box-shadow: 0 38px 72px rgba(15, 16, 19, 0.5);
            display: grid;
            gap: 1.5rem;
        }

        .hero-right::after {
            content: "";
            position: absolute;
            inset: 18px;
            border-radius: 24px;
            border: 1px dashed rgba(148, 161, 178, 0.18);
            pointer-events: none;
        }

        .hero-right-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .hero-right-title {
            font-weight: 500;
            color: var(--lighter-gray);
        }

        .integrations-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
        }

        .integration-pill {
            padding: 0.6rem 1rem;
            border-radius: 12px;
            background: rgba(148, 161, 178, 0.08);
            border: 1px solid rgba(148, 161, 178, 0.18);
            font-weight: 500;
            color: var(--light-gray);
        }

        .dashboard-preview {
            display: grid;
            gap: 1.1rem;
        }

        .dashboard-card {
            padding: 1.1rem;
            border-radius: 14px;
            border: 1px solid rgba(148, 161, 178, 0.2);
            background: rgba(148, 161, 178, 0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: var(--lighter-gray);
        }

        .dashboard-card strong {
            display: block;
            color: var(--white);
            font-size: 1.05rem;
        }

        .hero-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.25rem;
            margin-top: 1rem;
        }

        .stat-card {
            padding: 1.4rem;
            border-radius: 16px;
            background: linear-gradient(140deg, rgba(148, 161, 178, 0.12), rgba(26, 26, 29, 0.75));
            border: 1px solid rgba(148, 161, 178, 0.18);
            box-shadow: 0 20px 44px rgba(15, 16, 19, 0.4);
            display: grid;
            gap: 0.35rem;
        }

        .stat-value {
            font-size: 1.85rem;
            font-weight: 600;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--lighter-gray);
        }

        .trusted-bar {
            margin-top: 2.75rem;
            padding: 1.1rem 1.5rem;
            border-radius: 14px;
            border: 1px solid rgba(148, 161, 178, 0.18);

            background: rgba(148, 161, 178, 0.05);
            display: flex;
            flex-wrap: wrap;
            gap: 1.25rem;
            align-items: center;
            color: var(--light-gray);
            font-size: 0.9rem;
        }

        .trusted-brands {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            font-weight: 500;
            letter-spacing: 0.05em;
        }

        .cards-grid {
            display: grid;
            gap: 1.5rem;
        }

        .feature-card,
        .integration-card {
            position: relative;
            padding: 2rem;
            border-radius: 20px;
            background: linear-gradient(140deg, rgba(148, 161, 178, 0.08), rgba(26, 26, 29, 0.85));
            border: 1px solid rgba(148, 161, 178, 0.18);
            box-shadow: 0 26px 52px rgba(15, 16, 19, 0.42);
            transition: var(--transition);
        }

        .feature-card:hover,
        .integration-card:hover {
            transform: translateY(-6px);
            border-color: rgba(254, 255, 255, 0.22);
        }

        .feature-card::after,
        .integration-card::after {
            content: "";
            position: absolute;
            inset: 1.2rem;
            border-radius: 16px;
            border: 1px dashed rgba(148, 161, 178, 0.16);
            pointer-events: none;
        }

        .card-icon {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(254, 255, 255, 0.12), rgba(148, 161, 178, 0.08));
            border: 1px solid rgba(148, 161, 178, 0.2);
            display: grid;
            place-items: center;
            color: var(--white);
            margin-bottom: 1.25rem;
        }

        .card-icon .material-symbols-outlined {
            font-size: 28px;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.85rem;
        }

        .card-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 0.55rem;
            color: var(--lighter-gray);
            font-size: 0.95rem;
        }

        .card-list li {
            display: flex;
            gap: 0.6rem;
            align-items: flex-start;
        }

        .card-list li::before {
            content: '•';
            color: var(--light-gray);
            font-weight: 600;
            margin-top: -0.05rem;
        }

        .statistics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 2.5rem;
        }

        .statistic-card {
            padding: 1.9rem;
            border-radius: 20px;
            border: 1px solid rgba(148, 161, 178, 0.18);
            background: linear-gradient(140deg, rgba(148, 161, 178, 0.06), rgba(26, 26, 29, 0.85));
            box-shadow: 0 22px 44px rgba(15, 16, 19, 0.42);
        }

        .statistic-value {
            font-size: 2.1rem;
            font-weight: 600;
            margin-bottom: 0.45rem;
        }

        .statistic-label {
            color: var(--lighter-gray);
        }

        .cta-section {
            position: relative;
            overflow: hidden;
        }

        .cta-section::before {
            content: "";
            position: absolute;
            inset: -20% -30% 0 -30%;
            background: radial-gradient(circle at top, rgba(148, 161, 178, 0.16), transparent 60%);
            pointer-events: none;
        }

        .cta-card {
            position: relative;
            padding: 3.2rem;
            border-radius: 28px;
            background: linear-gradient(145deg, rgba(148, 161, 178, 0.12), rgba(26, 26, 29, 0.92));
            border: 1px solid rgba(148, 161, 178, 0.2);
            box-shadow: 0 38px 72px rgba(15, 16, 19, 0.5);
            overflow: hidden;
        }

        .cta-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1.75rem;
        }

        .cal-embed {
            margin-top: 2.5rem;
            background: linear-gradient(150deg, rgba(26, 26, 29, 0.94), rgba(15, 16, 19, 0.92));
            border: 1px solid rgba(148, 161, 178, 0.16);
            border-radius: 24px;
            padding: 1rem;
            box-shadow: inset 0 1px 0 rgba(254, 255, 255, 0.03);
        }

        .cal-embed .cal-inline {
            width: 100%;
            min-height: 560px;
            border-radius: 18px;
            overflow: hidden;
        }

        .cta-card h2 {
            font-size: clamp(1.9rem, 3vw, 2.8rem);
            margin-bottom: 1.5rem;
            font-weight: 600;
        }

        .cta-card p {
            color: var(--lighter-gray);
            max-width: 640px;
            margin-bottom: 2.25rem;
        }

        footer {
            padding: 2.75rem 1.5rem;
            border-top: 1px solid rgba(148, 161, 178, 0.12);
            background: var(--black);
            color: var(--lighter-gray);
            font-size: 0.9rem;
        }

        footer .footer-inner {
            max-width: 1180px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .reveal {
            opacity: 0;
            transform: translateY(40px);
            transition: opacity 0.6s ease, transform 0.6s ease;
        }

        .reveal.visible {
            opacity: 1;
            transform: translateY(0);
        }

        @media (min-width: 960px) {
            .hero-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                align-items: center;
            }

            .cards-grid.features-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .cards-grid.integrations-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 960px) {
            nav {
                position: fixed;
                inset: 72px 1.5rem auto 1.5rem;
                border-radius: 18px;
                background: rgba(15, 16, 19, 0.95);
                border: 1px solid rgba(148, 161, 178, 0.16);
                padding: 1.75rem;
                flex-direction: column;
                align-items: stretch;
                gap: 1.75rem;
                transform-origin: top right;
                transform: scale(0.9);
                opacity: 0;
                pointer-events: none;
                transition: var(--transition);
            }

            nav.open {
                opacity: 1;
                pointer-events: auto;
                transform: scale(1);
            }

            .nav-links {
                flex-direction: column;
                align-items: stretch;
                gap: 1.25rem;
            }

            .language-toggle {
                align-self: flex-end;
            }

            .menu-toggle {
                display: inline-flex;
                align-items: center;
                gap: 0.35rem;
            }
        }

        @media (max-width: 720px) {
            .hero-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .btn {
                width: 100%;
            }

            .hero-right {
                padding: 2rem;
            }

            .cta-card {
                padding: 2.5rem;
            }

            .cta-actions {
                flex-direction: column;
            }

            .cal-embed .cal-inline {
                min-height: 520px;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="nav-container">
            <a class="brand" href="#hero">
                <span class="brand-icon material-symbols-outlined">inventory</span>
                <span>NOTSOWMS</span>
            </a>
            <button class="menu-toggle" aria-expanded="false" aria-controls="primary-navigation">
                <span class="material-symbols-outlined">menu</span>
                <span class="translate" data-lang-ro="Meniu" data-lang-en="Menu">Meniu</span>
            </button>
            <nav id="primary-navigation" aria-label="Primary">
                <ul class="nav-links">
                    <li><a class="translate" data-lang-ro="Caracteristici" data-lang-en="Features" href="#features">Caracteristici</a></li>
                    <li><a class="translate" data-lang-ro="Integrări" data-lang-en="Integrations" href="#integrations">Integrări</a></li>
                    <li><a class="translate" data-lang-ro="Statistici" data-lang-en="Statistics" href="#statistics">Statistici</a></li>
                    <li><a class="translate" data-lang-ro="Contact" data-lang-en="Contact" href="#contact">Contact</a></li>
                </ul>
                <div class="language-toggle" role="group" aria-label="Language toggle">
                    <button type="button" class="lang-option active" data-language="ro">RO</button>
                    <button type="button" class="lang-option" data-language="en">EN</button>
                </div>
            </nav>
        </div>
    </header>

    <main>
        <section id="hero" class="hero reveal">
            <div class="section-inner hero-grid">
                <div class="hero-text">
                    <span class="badge">
                        <span class="material-symbols-outlined">workspace_premium</span>
                        <span class="translate" data-lang-ro="Soluție enterprise pentru distribuție națională" data-lang-en="Enterprise solution for national distribution">Soluție enterprise pentru distribuție națională</span>
                    </span>
                    <h1 class="hero-title translate" data-lang-ro="Sistem enterprise de management al depozitului pentru România" data-lang-en="Enterprise Warehouse Management System for Romania">Sistem enterprise de management al depozitului pentru România</h1>
                    <p class="hero-subtitle translate" data-lang-ro="Integrare nativă cu SmartBill și Cargus, aliniată la legislația ANAF pentru operațiuni fără blocaje." data-lang-en="Native SmartBill and Cargus integrations, aligned with ANAF regulations for frictionless operations.">Integrare nativă cu SmartBill și Cargus, aliniată la legislația ANAF pentru operațiuni fără blocaje.</p>
                    <div class="hero-actions">
                        <a class="btn btn-primary translate" href="#contact" data-lang-ro="Solicită Demo Enterprise" data-lang-en="Request Enterprise Demo">Solicită Demo Enterprise</a>
                        <a class="btn btn-secondary translate" href="#features" data-lang-ro="Explorează Funcționalitățile" data-lang-en="Explore Features">Explorează Funcționalitățile</a>
                    </div>
                    <div class="trusted-bar">
                        <span class="material-symbols-outlined">verified_user</span>
                        <span class="translate" data-lang-ro="Ales de lideri din e-commerce, distribuție și 3PL" data-lang-en="Chosen by leaders in e-commerce, distribution, and 3PL">Ales de lideri din e-commerce, distribuție și 3PL</span>
                        <div class="trusted-brands">
                            <span>SmartBill</span>
                            <span>Cargus</span>
                            <span>ANAF</span>
                            <span>ERP</span>
                        </div>
                    </div>
                </div>
                <div class="hero-right">
                    <div class="hero-right-header">
                        <span class="hero-right-title translate" data-lang-ro="Turn de control operațional" data-lang-en="Operations control tower">Turn de control operațional</span>
                        <span class="material-symbols-outlined">monitoring</span>
                    </div>
                    <div class="integrations-row">
                        <span class="integration-pill">SmartBill</span>
                        <span class="integration-pill">Cargus</span>
                        <span class="integration-pill">ANAF</span>
                        <span class="integration-pill">ERP</span>
                    </div>
                    <div class="dashboard-preview">
                        <div class="dashboard-card">
                            <div>
                                <strong>SmartBill Sync</strong>
                                <span class="translate" data-lang-ro="Documente fiscale generate instant" data-lang-en="Fiscal documents generated instantly">Documente fiscale generate instant</span>
                            </div>
                            <span class="material-symbols-outlined">cloud_sync</span>
                        </div>
                        <div class="dashboard-card">
                            <div>
                                <strong>Cargus AWB</strong>
                                <span class="translate" data-lang-ro="AWB automat pentru fiecare comandă" data-lang-en="Automatic AWB for every order">AWB automat pentru fiecare comandă</span>
                            </div>
                            <span class="material-symbols-outlined">local_shipping</span>
                        </div>
                        <div class="dashboard-card">
                            <div>
                                <strong>ROI Monitor</strong>
                                <span class="translate" data-lang-ro="Economie medie de 40 ore/lună" data-lang-en="Average saving of 40 hours/month">Economie medie de 40 ore/lună</span>
                            </div>
                            <span class="material-symbols-outlined">trending_up</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="section-inner hero-stats">
                <div class="stat-card">
                    <span class="stat-value">95%</span>
                    <span class="stat-label translate" data-lang-ro="Reducere a erorilor operaționale" data-lang-en="Reduction in operational errors">Reducere a erorilor operaționale</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value">60%</span>
                    <span class="stat-label translate" data-lang-ro="Creștere a eficienței de picking" data-lang-en="Increase in picking efficiency">Creștere a eficienței de picking</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value">24/7</span>
                    <span class="stat-label translate" data-lang-ro="Suport enterprise dedicat" data-lang-en="Dedicated enterprise support">Suport enterprise dedicat</span>
                </div>
            </div>
        </section>

        <section id="features" class="reveal">
            <div class="section-inner">
                <div class="section-header">
                    <h2 class="section-title translate" data-lang-ro="Capabilități enterprise care scală cu depozitul tău" data-lang-en="Enterprise capabilities engineered for Romanian logistics">Capabilități enterprise care scală cu depozitul tău</h2>
                    <p class="section-subtitle translate" data-lang-ro="Automatizăm procesele complexe ale depozitului și eliminăm blocajele operaționale cu fluxuri end-to-end." data-lang-en="Automate complex warehouse processes and eliminate operational bottlenecks with end-to-end flows.">Automatizăm procesele complexe ale depozitului și eliminăm blocajele operaționale cu fluxuri end-to-end.</p>
                </div>
                <div class="cards-grid features-grid">
                    <article class="feature-card">
                        <div class="card-icon"><span class="material-symbols-outlined">inventory_2</span></div>
                        <h3 class="card-title translate" data-lang-ro="Management complet al stocului" data-lang-en="Complete inventory management">Management complet al stocului</h3>
                        <ul class="card-list">
                            <li class="translate" data-lang-ro="Trasabilitate lot/serie pentru fiecare mișcare" data-lang-en="Lot and serial traceability for every movement">Trasabilitate lot/serie pentru fiecare mișcare</li>
                            <li class="translate" data-lang-ro="Reguli de slotting inteligente pentru zone rapide" data-lang-en="Intelligent slotting rules for fast lanes">Reguli de slotting inteligente pentru zone rapide</li>
                            <li class="translate" data-lang-ro="Control automat al stocurilor de siguranță" data-lang-en="Automated safety stock enforcement">Control automat al stocurilor de siguranță</li>
                        </ul>
                    </article>
                    <article class="feature-card">
                        <div class="card-icon"><span class="material-symbols-outlined">smartphone</span></div>
                        <h3 class="card-title translate" data-lang-ro="Aplicații mobile enterprise" data-lang-en="Enterprise mobile applications">Aplicații mobile enterprise</h3>
                        <ul class="card-list">
                            <li class="translate" data-lang-ro="Suport Android/iOS cu funcționare offline" data-lang-en="Android/iOS support with offline continuity">Suport Android/iOS cu funcționare offline</li>
                            <li class="translate" data-lang-ro="Scanare coduri 1D/2D și validări în timp real" data-lang-en="1D/2D scanning with real-time validations">Scanare coduri 1D/2D și validări în timp real</li>
                            <li class="translate" data-lang-ro="Fluxuri personalizate pentru picking și cross-docking" data-lang-en="Custom flows for picking and cross-docking">Fluxuri personalizate pentru picking și cross-docking</li>
                        </ul>
                    </article>
                    <article class="feature-card">
                        <div class="card-icon"><span class="material-symbols-outlined">assignment_return</span></div>
                        <h3 class="card-title translate" data-lang-ro="Management avansat al retururilor" data-lang-en="Advanced returns management">Management avansat al retururilor</h3>
                        <ul class="card-list">
                            <li class="translate" data-lang-ro="Procesare automată a retururilor Cargus" data-lang-en="Automated processing for Cargus returns">Procesare automată a retururilor Cargus</li>
                            <li class="translate" data-lang-ro="Decizii rapide prin workflows aprobate" data-lang-en="Rapid decisions through approved workflows">Decizii rapide prin workflows aprobate</li>
                            <li class="translate" data-lang-ro="Reconcilieri SmartBill fără erori" data-lang-en="Error-free SmartBill reconciliations">Reconcilieri SmartBill fără erori</li>
                        </ul>
                    </article>
                    <article class="feature-card">
                        <div class="card-icon"><span class="material-symbols-outlined">verified</span></div>
                        <h3 class="card-title translate" data-lang-ro="Control al calității integrat" data-lang-en="Integrated quality control">Control al calității integrat</h3>
                        <ul class="card-list">
                            <li class="translate" data-lang-ro="Check-list-uri digitale și rapoarte ANAF" data-lang-en="Digital checklists with ANAF-ready reports">Check-list-uri digitale și rapoarte ANAF</li>
                            <li class="translate" data-lang-ro="Alertare instant pentru deviații critice" data-lang-en="Instant alerts for critical deviations">Alertare instant pentru deviații critice</li>
                            <li class="translate" data-lang-ro="Audit trail complet și semnătură electronică" data-lang-en="Complete audit trail with electronic signature">Audit trail complet și semnătură electronică</li>
                        </ul>
                    </article>
                    <article class="feature-card">
                        <div class="card-icon"><span class="material-symbols-outlined">analytics</span></div>
                        <h3 class="card-title translate" data-lang-ro="Analytics & raportare enterprise" data-lang-en="Enterprise analytics & reporting">Analytics & raportare enterprise</h3>
                        <ul class="card-list">
                            <li class="translate" data-lang-ro="KPIs operaționali și financiari în timp real" data-lang-en="Operational and financial KPIs in real time">KPIs operaționali și financiari în timp real</li>
                            <li class="translate" data-lang-ro="Previziuni AI pentru stocuri sezoniere" data-lang-en="AI forecasting for seasonal stock">Previziuni AI pentru stocuri sezoniere</li>
                            <li class="translate" data-lang-ro="Exporturi automate către board și ANAF" data-lang-en="Automated exports for board and ANAF">Exporturi automate către board și ANAF</li>
                        </ul>
                    </article>
                    <article class="feature-card">
                        <div class="card-icon"><span class="material-symbols-outlined">groups</span></div>
                        <h3 class="card-title translate" data-lang-ro="Management enterprise al utilizatorilor" data-lang-en="Enterprise user management">Management enterprise al utilizatorilor</h3>
                        <ul class="card-list">
                            <li class="translate" data-lang-ro="Roluri granulare și politici Zero Trust" data-lang-en="Granular roles with Zero Trust policies">Roluri granulare și politici Zero Trust</li>
                            <li class="translate" data-lang-ro="SSO și autentificare multi-factor" data-lang-en="SSO and multi-factor authentication">SSO și autentificare multi-factor</li>
                            <li class="translate" data-lang-ro="Monitorizare a performanței echipelor" data-lang-en="Team performance monitoring">Monitorizare a performanței echipelor</li>
                        </ul>
                    </article>
                </div>
            </div>
        </section>

        <section id="integrations" class="reveal">
            <div class="section-inner">
                <div class="section-header">
                    <h2 class="section-title translate" data-lang-ro="Integrări validate pe piața din România" data-lang-en="Integrations built for the Romanian market">Integrări validate pe piața din România</h2>
                    <p class="section-subtitle translate" data-lang-ro="Operăm cu platformele critice pentru business-urile locale, cu sincronizări sigure și monitorizare permanentă." data-lang-en="Operate with the critical Romanian platforms through secure, continuously monitored integrations.">Operăm cu platformele critice pentru business-urile locale, cu sincronizări sigure și monitorizare permanentă.</p>
                </div>
                <div class="cards-grid integrations-grid">
                    <article class="integration-card">
                        <div class="card-icon"><span class="material-symbols-outlined">receipt_long</span></div>
                        <h3 class="card-title">SmartBill</h3>
                        <ul class="card-list">
                            <li class="translate" data-lang-ro="Sincronizare automată a facturilor și stocurilor" data-lang-en="Automatic sync of invoices and stock">Sincronizare automată a facturilor și stocurilor</li>
                            <li class="translate" data-lang-ro="Generare instant de documente fiscale" data-lang-en="Instant fiscal document generation">Generare instant de documente fiscale</li>
                            <li class="translate" data-lang-ro="Aliniere completă la cerințele ANAF" data-lang-en="Full alignment with ANAF compliance">Aliniere completă la cerințele ANAF</li>
                        </ul>
                    </article>
                    <article class="integration-card">
                        <div class="card-icon"><span class="material-symbols-outlined">local_shipping</span></div>
                        <h3 class="card-title">Cargus</h3>
                        <ul class="card-list">
                            <li class="translate" data-lang-ro="Generare automată a AWB-urilor" data-lang-en="Automatic AWB generation">Generare automată a AWB-urilor</li>
                            <li class="translate" data-lang-ro="Tracking în timp real pentru fiecare expediție" data-lang-en="Real-time tracking for every shipment">Tracking în timp real pentru fiecare expediție</li>
                            <li class="translate" data-lang-ro="Gestionare completă a retururilor" data-lang-en="Complete returns management">Gestionare completă a retururilor</li>
                        </ul>
                    </article>
                    <article class="integration-card">
                        <div class="card-icon"><span class="material-symbols-outlined">api</span></div>
                        <h3 class="card-title">Enterprise REST API</h3>
                        <ul class="card-list">
                            <li class="translate" data-lang-ro="Autentificare securizată și token management" data-lang-en="Secure authentication and token management">Autentificare securizată și token management</li>
                            <li class="translate" data-lang-ro="Limitare de trafic și SLA monitorizat" data-lang-en="Rate limiting with monitored SLAs">Limitare de trafic și SLA monitorizat</li>
                            <li class="translate" data-lang-ro="Documentație completă pentru parteneri" data-lang-en="Comprehensive partner documentation">Documentație completă pentru parteneri</li>
                        </ul>
                    </article>
                </div>
            </div>
        </section>

        <section id="statistics" class="reveal">
            <div class="section-inner">
                <div class="section-header">
                    <h2 class="section-title translate" data-lang-ro="Indicatori de performanță obținuți de clienții NOTSOWMS" data-lang-en="Performance indicators delivered by NOTSOWMS">Indicatori de performanță obținuți de clienții NOTSOWMS</h2>
                    <p class="section-subtitle translate" data-lang-ro="Date validate în proiecte enterprise din e-commerce, distribuție și producție." data-lang-en="Validated data across enterprise projects in e-commerce, distribution, and manufacturing.">Date validate în proiecte enterprise din e-commerce, distribuție și producție.</p>
                </div>
                <div class="statistics-grid">
                    <div class="statistic-card">
                        <div class="statistic-value">95%</div>
                        <div class="statistic-label translate" data-lang-ro="Reducere a erorilor de inventar" data-lang-en="Inventory error reduction">Reducere a erorilor de inventar</div>
                    </div>
                    <div class="statistic-card">
                        <div class="statistic-value">60%</div>
                        <div class="statistic-label translate" data-lang-ro="Creștere a eficienței de picking" data-lang-en="Increase in picking efficiency">Creștere a eficienței de picking</div>
                    </div>
                    <div class="statistic-card">
                        <div class="statistic-value">80%</div>
                        <div class="statistic-label translate" data-lang-ro="Reducere a timpului de procesare" data-lang-en="Processing time reduction">Reducere a timpului de procesare</div>
                    </div>
                    <div class="statistic-card">
                        <div class="statistic-value">99.9%</div>
                        <div class="statistic-label translate" data-lang-ro="Disponibilitate a platformei" data-lang-en="Platform uptime">Disponibilitate a platformei</div>
                    </div>
                    <div class="statistic-card">
                        <div class="statistic-value">15</div>
                        <div class="statistic-label translate" data-lang-ro="Zile medii de implementare" data-lang-en="Average implementation days">Zile medii de implementare</div>
                    </div>
                    <div class="statistic-card">
                        <div class="statistic-value">24/7</div>
                        <div class="statistic-label translate" data-lang-ro="Suport enterprise permanent" data-lang-en="Permanent enterprise support">Suport enterprise permanent</div>
                    </div>
                </div>
            </div>
        </section>

        <section id="contact" class="cta-section reveal">
            <div class="section-inner">
                <div class="cta-card">
                    <h2 class="translate" data-lang-ro="Transformă-ți operațiunile de depozit în doar câteva săptămâni" data-lang-en="Transform your warehouse operations in weeks">Transformă-ți operațiunile de depozit în doar câteva săptămâni</h2>
                    <p class="translate" data-lang-ro="Programează un demo personalizat pentru a vedea cum NOTSOWMS conectează SmartBill, Cargus și procesele interne într-o singură platformă enterprise." data-lang-en="Schedule a personalized demo to see how NOTSOWMS connects SmartBill, Cargus, and internal processes into one enterprise platform.">Programează un demo personalizat pentru a vedea cum NOTSOWMS conectează SmartBill, Cargus și procesele interne într-o singură platformă enterprise.</p>
                    <div class="cta-actions">
                        <a class="btn btn-primary translate" href="https://cal.com/notso/15min" target="_blank" rel="noreferrer" data-lang-ro="Rezervă un demo" data-lang-en="Book a demo">Rezervă un demo</a>
                        <a class="btn btn-secondary translate" href="https://cal.com/notso/15min?utm=landing" target="_blank" rel="noreferrer" data-lang-ro="Planifică o consultanță" data-lang-en="Schedule a consultation">Planifică o consultanță</a>
                    </div>
                    <div class="cal-embed">
                        <div class="cal-inline" id="my-cal-inline-15min"></div>
                    </div>
                </div>
            </div>
        </section>
        <script type="text/javascript">
            (function (C, A, L) {
                let p = function (a, ar) { a.q.push(ar); };
                let d = C.document;
                C.Cal = C.Cal || function () {
                    let cal = C.Cal;
                    let ar = arguments;
                    if (!cal.loaded) {
                        cal.ns = {};
                        cal.q = cal.q || [];
                        d.head.appendChild(d.createElement("script")).src = A;
                        cal.loaded = true;
                    }
                    if (ar[0] === L) {
                        const api = function () { p(api, arguments); };
                        const namespace = ar[1];
                        api.q = api.q || [];
                        if (typeof namespace === "string") {
                            cal.ns[namespace] = cal.ns[namespace] || api;
                            p(cal.ns[namespace], ar);
                            p(cal, ["initNamespace", namespace]);
                        } else p(cal, ar);
                        return;
                    }
                    p(cal, ar);
                };
            })(window, "https://app.cal.com/embed/embed.js", "init");

            Cal("init", "15min", { origin: "https://app.cal.com" });

            Cal.ns["15min"]("inline", {
                elementOrSelector: "#my-cal-inline-15min",
                config: { "layout": "month_view", "theme": "dark" },
                calLink: "notso/15min",
            });

            Cal.ns["15min"]("ui", { "theme": "dark", "hideEventTypeDetails": false, "layout": "month_view" });
        </script>
    </main>

    <footer>
        <div class="footer-inner">
            <span>© 2025 NOTSOWMS. All rights reserved.</span>
            <span class="translate" data-lang-ro="Soluție enterprise de management al depozitelor construită în România." data-lang-en="Enterprise warehouse management solution built in Romania.">Soluție enterprise de management al depozitelor construită în România.</span>
        </div>
    </footer>

    <script>
        (function () {
            const nav = document.getElementById('primary-navigation');
            const menuToggle = document.querySelector('.menu-toggle');
            const langButtons = document.querySelectorAll('.lang-option');
            const translatable = document.querySelectorAll('.translate');
            const STORAGE_KEY = 'notsowms-language';

            function setLanguage(lang) {
                document.documentElement.setAttribute('lang', lang);
                localStorage.setItem(STORAGE_KEY, lang);
                translatable.forEach(element => {
                    const ro = element.dataset.langRo;
                    const en = element.dataset.langEn;
                    if (lang === 'en' && en) {
                        element.textContent = en;
                    } else if (lang === 'ro' && ro) {
                        element.textContent = ro;
                    }
                });
                langButtons.forEach(button => {
                    button.classList.toggle('active', button.dataset.language === lang);
                });
            }

            function initLanguage() {
                const saved = localStorage.getItem(STORAGE_KEY);
                const initial = saved === 'en' ? 'en' : 'ro';
                setLanguage(initial);
            }

            function toggleMenu(forceState) {
                const shouldOpen = typeof forceState === 'boolean' ? forceState : !nav.classList.contains('open');
                nav.classList.toggle('open', shouldOpen);
                menuToggle.setAttribute('aria-expanded', shouldOpen);
            }

            langButtons.forEach(button => {
                button.addEventListener('click', () => {
                    const lang = button.dataset.language;
                    setLanguage(lang);
                    if (nav.classList.contains('open')) {
                        toggleMenu(false);
                    }
                });
            });

            menuToggle.addEventListener('click', () => toggleMenu());

            document.addEventListener('click', event => {
                if (!nav.contains(event.target) && !menuToggle.contains(event.target)) {
                    toggleMenu(false);
                }
            });

            const observer = new IntersectionObserver((entries, obs) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                        obs.unobserve(entry.target);
                    }
                });
            }, {
                threshold: 0.15
            });

            document.querySelectorAll('.reveal').forEach(section => observer.observe(section));

            initLanguage();
        })();
    </script>
</body>
</html>
