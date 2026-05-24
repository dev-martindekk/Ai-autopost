<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/plan_manager.php';

// Load paid plans for pricing section — sorted by price ASC
$plans = array_filter(planManager()->getAllActivePlans(), fn($p) => empty($p['is_trial']));
usort($plans, fn($a, $b) => $a['price'] <=> $b['price']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI AutoPost SEO — ระบบเขียนบทความ SEO อัตโนมัติด้วย AI</title>
    <meta name="description" content="สร้างบทความ SEO คุณภาพสูงด้วย AI โพสต์อัตโนมัติไปยัง WordPress วางแผนคอนเทนต์ วิจัยคีย์เวิร์ด และเพิ่มอันดับ Google โดยอัตโนมัติ">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,400&family=Prompt:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366F1;
            --primary-dark: #4F46E5;
            --secondary: #8B5CF6;
            --accent: #06B6D4;
            --success: #10B981;
            --warning: #F59E0B;
            --dark: #0F172A;
            --gray: #64748B;
        }
        * { font-family: 'Inter','Prompt', sans-serif; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { background: #fff; color: #1E293B; overflow-x: hidden; }

        /* ── Navbar ── */
        .navbar {
            background: rgba(255,255,255,.92);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(99,102,241,.08);
            padding: 14px 0;
            position: sticky; top: 0; z-index: 100;
            transition: box-shadow .3s;
        }
        .navbar.scrolled { box-shadow: 0 4px 30px rgba(0,0,0,.08); }
        .navbar-brand { font-weight: 800; font-size: 20px; color: var(--primary) !important; letter-spacing: -.5px; }
        .nav-link { font-size: 14px; font-weight: 500; color: #475569 !important; padding: 6px 16px !important; border-radius: 8px; transition: all .2s; }
        .nav-link:hover { color: var(--primary) !important; background: rgba(99,102,241,.07); }
        .btn-nav-cta { background: linear-gradient(135deg,var(--primary),var(--secondary)); color: #fff !important; font-weight: 600; padding: 8px 20px !important; border-radius: 10px; box-shadow: 0 4px 14px rgba(99,102,241,.3); }
        .btn-nav-cta:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(99,102,241,.4); }

        /* ── Hero ── */
        .hero {
            background: linear-gradient(135deg, #0F172A 0%, #1E1B4B 40%, #312E81 70%, #1E293B 100%);
            min-height: 100vh;
            display: flex; align-items: center;
            position: relative; overflow: hidden;
            padding: 80px 0 60px;
        }
        .hero::before {
            content: '';
            position: absolute; inset: 0;
            background: radial-gradient(ellipse 80% 60% at 50% -10%, rgba(99,102,241,.4) 0%, transparent 70%),
                        radial-gradient(ellipse 40% 40% at 85% 70%, rgba(139,92,246,.25) 0%, transparent 60%),
                        radial-gradient(ellipse 30% 30% at 10% 80%, rgba(6,182,212,.15) 0%, transparent 50%);
        }
        .hero-grid {
            position: absolute; inset: 0; overflow: hidden;
            background-image: linear-gradient(rgba(99,102,241,.06) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(99,102,241,.06) 1px, transparent 1px);
            background-size: 50px 50px;
        }
        .hero-badge {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(99,102,241,.2); border: 1px solid rgba(99,102,241,.4);
            border-radius: 50px; padding: 6px 16px; font-size: 13px; color: #A5B4FC;
            margin-bottom: 24px; backdrop-filter: blur(10px);
        }
        .hero-badge span { width:6px;height:6px;background:#6EE7B7;border-radius:50%;animation:pulse 2s infinite; }
        @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.3)} }
        .hero h1 {
            font-size: clamp(36px, 6vw, 72px);
            font-weight: 900; color: #fff;
            line-height: 1.08; letter-spacing: -2px;
            margin-bottom: 24px;
        }
        .hero h1 .gradient-text {
            background: linear-gradient(135deg, #818CF8, #C084FC, #38BDF8);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .hero p { font-size: 18px; color: rgba(255,255,255,.7); line-height: 1.7; margin-bottom: 36px; max-width: 560px; }
        .hero-actions { display: flex; gap: 16px; flex-wrap: wrap; }
        .btn-hero-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff; font-weight: 700; font-size: 16px;
            padding: 16px 36px; border-radius: 14px; border: none;
            box-shadow: 0 8px 30px rgba(99,102,241,.45);
            transition: all .3s; text-decoration: none; display: inline-flex; align-items: center; gap: 10px;
        }
        .btn-hero-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 40px rgba(99,102,241,.55); color: #fff; }
        .btn-hero-secondary {
            background: rgba(255,255,255,.08); color: #fff; font-weight: 600; font-size: 16px;
            padding: 16px 32px; border-radius: 14px; border: 1px solid rgba(255,255,255,.15);
            backdrop-filter: blur(10px); transition: all .3s; text-decoration: none;
            display: inline-flex; align-items: center; gap: 10px;
        }
        .btn-hero-secondary:hover { background: rgba(255,255,255,.14); color: #fff; transform: translateY(-2px); }
        .hero-stats { display: flex; gap: 40px; margin-top: 56px; flex-wrap: wrap; }
        .hero-stat-num { font-size: 32px; font-weight: 800; color: #fff; letter-spacing: -1px; }
        .hero-stat-label { font-size: 13px; color: rgba(255,255,255,.5); margin-top: 2px; }

        /* Terminal mockup */
        .terminal {
            background: #0D1117; border-radius: 16px;
            border: 1px solid rgba(255,255,255,.08);
            box-shadow: 0 40px 80px rgba(0,0,0,.5), 0 0 0 1px rgba(255,255,255,.04);
            overflow: hidden; position: relative;
        }
        .terminal-header {
            background: #161B22; padding: 12px 16px;
            display: flex; align-items: center; gap: 8px;
            border-bottom: 1px solid rgba(255,255,255,.06);
        }
        .dot { width:12px;height:12px;border-radius:50%; }
        .dot-red{background:#FF5F57;} .dot-yellow{background:#FFBD2E;} .dot-green{background:#28C840;}
        .terminal-title { font-size: 12px; color: rgba(255,255,255,.4); margin-left: 8px; }
        .terminal-body { padding: 24px; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.8; }
        .t-green { color: #7EE787; } .t-blue { color: #79C0FF; } .t-purple { color: #D2A8FF; }
        .t-yellow { color: #E3B341; } .t-gray { color: #8B949E; } .t-white { color: #F0F6FC; }
        .t-dim { color: #484F58; }
        .cursor { display: inline-block; width: 8px; height: 14px; background: #6366F1; animation: blink 1s infinite; vertical-align: middle; }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} }

        /* ── Section styles ── */
        section { padding: 100px 0; }
        .section-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(99,102,241,.08); color: var(--primary);
            border: 1px solid rgba(99,102,241,.2); border-radius: 50px;
            padding: 5px 14px; font-size: 12px; font-weight: 600; letter-spacing: .5px;
            text-transform: uppercase; margin-bottom: 16px;
        }
        .section-title { font-size: clamp(28px, 4vw, 44px); font-weight: 800; letter-spacing: -1.5px; color: var(--dark); line-height: 1.15; }
        .section-sub { font-size: 17px; color: var(--gray); line-height: 1.7; max-width: 600px; margin: 16px auto 0; }

        /* ── Features grid ── */
        .features-bg { background: linear-gradient(180deg, #f8faff 0%, #fff 100%); }
        .feature-card {
            background: #fff; border: 1px solid #E8EDF5;
            border-radius: 20px; padding: 32px;
            transition: all .3s; height: 100%;
            position: relative; overflow: hidden;
        }
        .feature-card::before {
            content: ''; position: absolute; inset: 0; border-radius: 20px;
            background: linear-gradient(135deg, rgba(99,102,241,.04), rgba(139,92,246,.04));
            opacity: 0; transition: opacity .3s;
        }
        .feature-card:hover { transform: translateY(-6px); border-color: rgba(99,102,241,.25); box-shadow: 0 20px 60px rgba(99,102,241,.12); }
        .feature-card:hover::before { opacity: 1; }
        .feature-icon {
            width: 56px; height: 56px; border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; margin-bottom: 20px;
        }
        .feature-title { font-size: 17px; font-weight: 700; color: var(--dark); margin-bottom: 10px; }
        .feature-desc { font-size: 14px; color: var(--gray); line-height: 1.7; }

        /* ── How it works ── */
        .hiw-bg { background: var(--dark); }
        .step-card {
            position: relative;
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 24px; padding: 36px 32px;
        }
        .step-num {
            width: 52px; height: 52px; border-radius: 16px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; font-weight: 800; color: #fff;
            margin-bottom: 24px; box-shadow: 0 8px 20px rgba(99,102,241,.4);
        }
        .step-title { font-size: 18px; font-weight: 700; color: #fff; margin-bottom: 10px; }
        .step-desc { font-size: 14px; color: rgba(255,255,255,.55); line-height: 1.7; }
        .step-connector {
            position: absolute; top: 52px; right: -28px;
            width: 56px; height: 2px;
            background: linear-gradient(90deg, rgba(99,102,241,.6), rgba(139,92,246,.2));
            display: none;
        }
        @media(min-width:992px) { .step-connector { display: block; } }

        /* ── Pricing ── */
        .pricing-bg { background: linear-gradient(180deg, #fff 0%, #f8faff 100%); }
        .plan-card {
            background: #fff; border: 1.5px solid #E2E8F0;
            border-radius: 24px; padding: 36px 32px;
            position: relative; transition: all .3s; height: 100%;
        }
        .plan-card.popular {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99,102,241,.1), 0 20px 60px rgba(99,102,241,.15);
        }
        .popular-badge {
            position: absolute; top: -14px; left: 50%; transform: translateX(-50%);
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff; font-size: 11px; font-weight: 700; letter-spacing: .8px;
            padding: 5px 16px; border-radius: 50px; white-space: nowrap;
            text-transform: uppercase;
        }
        .trial-card {
            background: linear-gradient(135deg, #F8FAFF, #F3F4FF);
            border: 1.5px dashed rgba(99,102,241,.3);
            border-radius: 24px; padding: 36px 32px; height: 100%;
        }
        .plan-name { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--gray); margin-bottom: 8px; }
        .plan-price { font-size: 48px; font-weight: 900; color: var(--dark); letter-spacing: -2px; line-height: 1; }
        .plan-price sup { font-size: 20px; font-weight: 700; vertical-align: super; margin-right: 2px; }
        .plan-price sub { font-size: 16px; font-weight: 500; color: var(--gray); letter-spacing: 0; }
        .plan-desc { font-size: 14px; color: var(--gray); margin: 12px 0 28px; line-height: 1.6; }
        .plan-feature { display: flex; align-items: flex-start; gap: 10px; font-size: 14px; color: #374151; margin-bottom: 12px; }
        .plan-feature .check { color: var(--success); font-size: 13px; margin-top: 2px; flex-shrink: 0; }
        .plan-feature .cross { color: #CBD5E1; font-size: 13px; margin-top: 2px; flex-shrink: 0; }
        .plan-feature.dim { color: #94A3B8; }
        .btn-plan {
            display: block; text-align: center; padding: 14px;
            border-radius: 12px; font-weight: 700; font-size: 15px;
            text-decoration: none; transition: all .3s; margin-top: 32px;
        }
        .btn-plan-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff; box-shadow: 0 6px 20px rgba(99,102,241,.35);
        }
        .btn-plan-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(99,102,241,.45); color: #fff; }
        .btn-plan-outline {
            background: transparent; color: var(--primary);
            border: 2px solid rgba(99,102,241,.3);
        }
        .btn-plan-outline:hover { background: rgba(99,102,241,.06); border-color: var(--primary); color: var(--primary); }
        .btn-plan-free {
            background: rgba(99,102,241,.08); color: var(--primary);
            border: 1.5px solid rgba(99,102,241,.2);
        }
        .btn-plan-free:hover { background: rgba(99,102,241,.14); color: var(--primary); }
        .divider { height: 1px; background: #F1F5F9; margin: 24px 0; }

        /* ── Comparison table ── */
        .compare-table { border-radius: 20px; overflow: hidden; border: 1px solid #E2E8F0; }
        .compare-table th { background: var(--dark); color: #fff; font-size: 13px; font-weight: 600; padding: 16px 20px; }
        .compare-table th.highlight { background: linear-gradient(135deg, var(--primary), var(--secondary)); }
        .compare-table td { padding: 14px 20px; font-size: 13px; border-bottom: 1px solid #F1F5F9; }
        .compare-table tr:last-child td { border-bottom: none; }
        .compare-table tr:hover td { background: #F8FAFF; }
        .check-yes { color: var(--success); font-weight: 600; }
        .check-no  { color: #CBD5E1; }

        /* ── Testimonials ── */
        .testi-card {
            background: #fff; border: 1px solid #E8EDF5; border-radius: 20px; padding: 28px;
            position: relative;
        }
        .testi-card::before {
            content: '"'; position: absolute; top: 12px; left: 20px;
            font-size: 80px; line-height: 1; color: rgba(99,102,241,.08);
            font-family: Georgia, serif; font-weight: 700;
        }
        .testi-text { font-size: 15px; color: #475569; line-height: 1.75; margin-bottom: 20px; font-style: italic; }
        .testi-avatar { width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--secondary));
            display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px; }
        .testi-name { font-size: 14px; font-weight: 700; color: var(--dark); }
        .testi-role { font-size: 12px; color: var(--gray); }
        .stars { color: #F59E0B; font-size: 12px; margin-bottom: 16px; }

        /* ── CTA ── */
        .cta-section {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 50%, #06B6D4 100%);
            padding: 100px 0; position: relative; overflow: hidden;
        }
        .cta-section::before {
            content: ''; position: absolute; inset: 0;
            background: radial-gradient(ellipse 60% 80% at 50% 50%, rgba(255,255,255,.1) 0%, transparent 70%);
        }
        .cta-section h2 { font-size: clamp(28px, 4vw, 48px); font-weight: 900; color: #fff; letter-spacing: -1.5px; }
        .cta-section p { font-size: 18px; color: rgba(255,255,255,.8); }

        /* ── Footer ── */
        footer { background: var(--dark); padding: 64px 0 40px; }
        .footer-brand { font-size: 22px; font-weight: 800; color: #fff; letter-spacing: -.5px; }
        .footer-desc { font-size: 14px; color: rgba(255,255,255,.45); margin-top: 12px; line-height: 1.7; max-width: 280px; }
        .footer-heading { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: rgba(255,255,255,.35); margin-bottom: 16px; }
        .footer-link { display: block; font-size: 14px; color: rgba(255,255,255,.55); text-decoration: none; margin-bottom: 10px; transition: color .2s; }
        .footer-link:hover { color: #fff; }
        .footer-bottom { border-top: 1px solid rgba(255,255,255,.08); margin-top: 56px; padding-top: 28px; }
        .footer-copy { font-size: 13px; color: rgba(255,255,255,.3); }

        /* ── Misc ── */
        .tag { display: inline-flex; align-items: center; gap: 4px; background: rgba(99,102,241,.08); color: var(--primary); border-radius: 6px; padding: 3px 10px; font-size: 12px; font-weight: 600; }
        .highlight-bar { height: 4px; border-radius: 2px; background: linear-gradient(90deg, var(--primary), var(--secondary), var(--accent)); margin: 12px 0; width: 48px; }
        .floating-card {
            background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
            border-radius: 16px; padding: 16px 20px; backdrop-filter: blur(10px);
        }
        .ai-chip { display: inline-flex; align-items: center; gap: 6px; background: rgba(16,185,129,.15); border: 1px solid rgba(16,185,129,.3); border-radius: 8px; padding: 4px 12px; font-size: 12px; color: #34D399; font-weight: 600; }

        @media(max-width: 768px) {
            section { padding: 70px 0; }
            .hero { padding: 100px 0 60px; }
            .hero-stats { gap: 28px; }
        }
    </style>
</head>
<body>

<!-- ══════════════════════════════════════════════
     NAVBAR
══════════════════════════════════════════════ -->
<nav class="navbar navbar-expand-lg" id="mainNav">
    <div class="container">
        <a class="navbar-brand" href="/">
            <i class="fas fa-robot me-2" style="background:linear-gradient(135deg,#6366F1,#8B5CF6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;"></i>AI AutoPost SEO
        </a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
            <i class="fas fa-bars" style="color:#6366F1;"></i>
        </button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav mx-auto gap-1">
                <li class="nav-item"><a class="nav-link" href="#features">ฟีเจอร์</a></li>
                <li class="nav-item"><a class="nav-link" href="#how-it-works">วิธีการทำงาน</a></li>
                <li class="nav-item"><a class="nav-link" href="#pricing">ราคา</a></li>
                <li class="nav-item"><a class="nav-link" href="#compare">เปรียบเทียบ</a></li>
            </ul>
            <div class="d-flex gap-2 mt-3 mt-lg-0">
                <a href="/member/login.php" class="nav-link">เข้าสู่ระบบ</a>
                <a href="/member/register.php" class="nav-link btn-nav-cta">เริ่มฟรี</a>
            </div>
        </div>
    </div>
</nav>

<!-- ══════════════════════════════════════════════
     HERO
══════════════════════════════════════════════ -->
<section class="hero">
    <div class="hero-grid"></div>
    <div class="container position-relative">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <div class="hero-badge">
                    <span></span>AI-Powered · ภาษาไทย · WordPress Ready
                </div>
                <h1>
                    เขียน SEO<br>
                    <span class="gradient-text">อัตโนมัติ</span><br>
                    ด้วย AI
                </h1>
                <p>ระบบสร้างบทความ SEO คุณภาพสูงด้วย Claude AI โพสต์อัตโนมัติไปยัง WordPress วางแผนคอนเทนต์ล่วงหน้า และเพิ่มอันดับ Google โดยไม่ต้องนั่งเขียนเอง</p>
                <div class="hero-actions">
                    <a href="/member/register.php" class="btn-hero-primary">
                        <i class="fas fa-rocket"></i>เริ่มต้นฟรี 5 บทความ
                    </a>
                    <a href="#how-it-works" class="btn-hero-secondary">
                        <i class="fas fa-play-circle"></i>ดูวิธีการทำงาน
                    </a>
                </div>
                <div class="hero-stats">
                    <div>
                        <div class="hero-stat-num">Claude AI</div>
                        <div class="hero-stat-label">Powered by Anthropic</div>
                    </div>
                    <div style="width:1px;background:rgba(255,255,255,.1);"></div>
                    <div>
                        <div class="hero-stat-num">100%</div>
                        <div class="hero-stat-label">WordPress Compatible</div>
                    </div>
                    <div style="width:1px;background:rgba(255,255,255,.1);"></div>
                    <div>
                        <div class="hero-stat-num">24/7</div>
                        <div class="hero-stat-label">Auto-posting</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="terminal">
                    <div class="terminal-header">
                        <div class="dot dot-red"></div>
                        <div class="dot dot-yellow"></div>
                        <div class="dot dot-green"></div>
                        <span class="terminal-title">AI AutoPost SEO — Generating Article</span>
                    </div>
                    <div class="terminal-body">
                        <div><span class="t-dim">$</span> <span class="t-green">ai-autopost</span> <span class="t-blue">generate</span> <span class="t-yellow">--keyword</span> <span class="t-purple">"วิธีเพิ่ม Conversion Rate อีคอมเมิร์ซ"</span></div>
                        <br>
                        <div><span class="t-gray">🔍 Researching keyword...</span></div>
                        <div><span class="t-gray">   Volume: 5,400/mo · Difficulty: 38</span></div>
                        <br>
                        <div><span class="t-gray">🤖 Claude AI analyzing topic...</span></div>
                        <div><span class="t-green">✓</span> <span class="t-white">Intent: Informational</span></div>
                        <div><span class="t-green">✓</span> <span class="t-white">LSI keywords: 21 found</span></div>
                        <div><span class="t-green">✓</span> <span class="t-white">Outline: 7 sections</span></div>
                        <br>
                        <div><span class="t-gray">✍️  Writing article (3,100 words)...</span></div>
                        <div style="margin-left:12px;">
                            <span class="t-blue">██████████████████░░</span>
                            <span class="t-white"> 88%</span>
                        </div>
                        <br>
                        <div><span class="t-green">✓</span> <span class="t-white">FAQ Schema generated</span></div>
                        <div><span class="t-green">✓</span> <span class="t-white">Internal links: 4 added</span></div>
                        <div><span class="t-green">✓</span> <span class="t-white">Featured image created</span></div>
                        <br>
                        <div><span class="t-gray">📤 Posting to WordPress...</span></div>
                        <div><span class="t-green">✓ Published!</span> <span class="t-blue">myblog.com/เพิ่ม-conversion-rate-อีคอมเมิร์ซ</span></div>
                        <div><span class="t-gray">📱 Telegram notified</span></div>
                        <br>
                        <div><span class="t-dim">$</span> <span class="cursor"></span></div>
                    </div>
                </div>

                <!-- Floating stats -->
                <div class="row g-2 mt-3">
                    <div class="col-4">
                        <div class="floating-card text-center">
                            <div style="font-size:22px;font-weight:800;color:#7EE787;">3,200</div>
                            <div style="font-size:11px;color:rgba(255,255,255,.5);">คำต่อบทความ</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="floating-card text-center">
                            <div style="font-size:22px;font-weight:800;color:#79C0FF;">8.4s</div>
                            <div style="font-size:11px;color:rgba(255,255,255,.5);">เวลาเฉลี่ย</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="floating-card text-center">
                            <div style="font-size:22px;font-weight:800;color:#D2A8FF;">100%</div>
                            <div style="font-size:11px;color:rgba(255,255,255,.5);">SEO Ready</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════
     FEATURES
══════════════════════════════════════════════ -->
<section class="features-bg" id="features">
    <div class="container">
        <div class="text-center mb-5">
            <div class="section-badge"><i class="fas fa-sparkles me-1"></i>ฟีเจอร์ครบครัน</div>
            <h2 class="section-title">ทุกอย่างที่คุณต้องการ<br>สำหรับ SEO อัตโนมัติ</h2>
            <p class="section-sub">ระบบครบวงจรตั้งแต่วิจัยคีย์เวิร์ด เขียนบทความ ไปจนถึงโพสต์ WordPress โดยอัตโนมัติ</p>
        </div>

        <div class="row g-4">
            <!-- Feature 1 -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background:linear-gradient(135deg,rgba(99,102,241,.15),rgba(139,92,246,.15));">
                        <i class="fas fa-brain" style="color:#6366F1;"></i>
                    </div>
                    <h3 class="feature-title">AI เขียนบทความอัตโนมัติ</h3>
                    <p class="feature-desc">ใช้ Claude AI (Anthropic) สร้างบทความ SEO ที่อ่านได้ตามธรรมชาติ ยาว 2,000–5,000+ คำ ครอบคลุม LSI Keywords และ semantic SEO อย่างครบถ้วน</p>
                    <div class="mt-3 d-flex gap-2 flex-wrap">
                        <span class="tag"><i class="fas fa-check me-1"></i>Claude AI</span>
                        <span class="tag"><i class="fas fa-check me-1"></i>GPT-4</span>
                        <span class="tag"><i class="fas fa-check me-1"></i>OpenRouter</span>
                    </div>
                </div>
            </div>

            <!-- Feature 2 -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background:linear-gradient(135deg,rgba(16,185,129,.15),rgba(6,182,212,.15));">
                        <i class="fab fa-wordpress" style="color:#10B981;"></i>
                    </div>
                    <h3 class="feature-title">Auto-Post ไปยัง WordPress</h3>
                    <p class="feature-desc">โพสต์บทความไปยัง WordPress ผ่าน REST API อัตโนมัติตามตาราง Cron ตั้งค่า Category, Author, Status และ Slug ได้เอง</p>
                    <div class="mt-3 d-flex gap-2 flex-wrap">
                        <span class="tag" style="background:rgba(16,185,129,.1);color:#10B981;"><i class="fas fa-clock me-1"></i>Scheduled</span>
                        <span class="tag" style="background:rgba(16,185,129,.1);color:#10B981;"><i class="fas fa-plug me-1"></i>REST API</span>
                    </div>
                </div>
            </div>

            <!-- Feature 3 -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background:linear-gradient(135deg,rgba(245,158,11,.15),rgba(239,68,68,.15));">
                        <i class="fas fa-magnifying-glass-chart" style="color:#F59E0B;"></i>
                    </div>
                    <h3 class="feature-title">วิจัยคีย์เวิร์ด</h3>
                    <p class="feature-desc">เชื่อมต่อ DataForSEO เพื่อดึงข้อมูล Search Volume, Keyword Difficulty, Traffic Potential และ Search Intent แบบ Real-time</p>
                    <div class="mt-3 d-flex gap-2 flex-wrap">
                        <span class="tag" style="background:rgba(245,158,11,.1);color:#B45309;"><i class="fas fa-chart-bar me-1"></i>DataForSEO</span>
                    </div>
                </div>
            </div>

            <!-- Feature 4 -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background:linear-gradient(135deg,rgba(6,182,212,.15),rgba(59,130,246,.15));">
                        <i class="fas fa-calendar-days" style="color:#06B6D4;"></i>
                    </div>
                    <h3 class="feature-title">Content Calendar</h3>
                    <p class="feature-desc">วางแผนคอนเทนต์ล่วงหน้าด้วย AI ระบบสร้างตาราง Content Plan รายเดือนโดยอัตโนมัติ เลือก Priority และ Topic ได้เอง</p>
                    <div class="mt-3 d-flex gap-2 flex-wrap">
                        <span class="tag" style="background:rgba(6,182,212,.1);color:#0891B2;"><i class="fas fa-robot me-1"></i>AI Planning</span>
                    </div>
                </div>
            </div>

            <!-- Feature 5 -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background:linear-gradient(135deg,rgba(139,92,246,.15),rgba(236,72,153,.15));">
                        <i class="fas fa-link" style="color:#8B5CF6;"></i>
                    </div>
                    <h3 class="feature-title">Internal Link Building</h3>
                    <p class="feature-desc">สร้าง Internal Links อัตโนมัติระหว่างบทความในเว็บ เพิ่ม Homepage Link และ Outbound Links ตามที่กำหนด ช่วยเพิ่ม SEO Authority</p>
                    <div class="mt-3 d-flex gap-2 flex-wrap">
                        <span class="tag" style="background:rgba(139,92,246,.1);color:#7C3AED;"><i class="fas fa-sitemap me-1"></i>Auto Links</span>
                    </div>
                </div>
            </div>

            <!-- Feature 6 -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background:linear-gradient(135deg,rgba(239,68,68,.15),rgba(245,158,11,.15));">
                        <i class="fas fa-code" style="color:#EF4444;"></i>
                    </div>
                    <h3 class="feature-title">FAQ Schema Markup</h3>
                    <p class="feature-desc">สร้าง FAQ Section พร้อม JSON-LD Schema อัตโนมัติในทุกบทความ ช่วยให้ได้ Rich Snippets บน Google เพิ่ม CTR อย่างมีนัยสำคัญ</p>
                    <div class="mt-3 d-flex gap-2 flex-wrap">
                        <span class="tag" style="background:rgba(239,68,68,.1);color:#B91C1C;"><i class="fab fa-google me-1"></i>Rich Snippets</span>
                    </div>
                </div>
            </div>

            <!-- Feature 7 -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background:linear-gradient(135deg,rgba(6,182,212,.15),rgba(16,185,129,.15));">
                        <i class="fab fa-telegram" style="color:#06B6D4;"></i>
                    </div>
                    <h3 class="feature-title">Telegram Notifications</h3>
                    <p class="feature-desc">รับแจ้งเตือนผ่าน Telegram ทันทีเมื่อบทความถูกสร้างและโพสต์ ไม่พลาดทุก Activity ของระบบ ตั้งค่าได้ต่อสมาชิก</p>
                </div>
            </div>

            <!-- Feature 8 -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background:linear-gradient(135deg,rgba(16,185,129,.15),rgba(6,182,212,.15));">
                        <i class="fas fa-shield-halved" style="color:#10B981;"></i>
                    </div>
                    <h3 class="feature-title">Deduplication & Quality</h3>
                    <p class="feature-desc">ระบบตรวจสอบและกรองคีย์เวิร์ดซ้ำอัตโนมัติ ใช้ Similarity Check ป้องกันการเขียนซ้ำ พร้อม Content Quality Checker ก่อนโพสต์</p>
                </div>
            </div>

            <!-- Feature 9 -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon" style="background:linear-gradient(135deg,rgba(16,185,129,.15),rgba(99,102,241,.15));">
                        <i class="fas fa-chart-line" style="color:#10B981;"></i>
                    </div>
                    <h3 class="feature-title">Multi-Site รองรับหลายเว็บ</h3>
                    <p class="feature-desc">จัดการหลายเว็บไซต์ WordPress ในระบบเดียว ตั้งค่า Schedule, Keyword, Prompt แยกต่างหากต่อเว็บ รองรับธุรกิจที่ต้องการ Content ปริมาณสูง</p>
                    <div class="mt-3 d-flex gap-2 flex-wrap">
                        <span class="tag" style="background:rgba(16,185,129,.1);color:#065F46;"><i class="fas fa-globe me-1"></i>Multi-Site</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════
     HOW IT WORKS
══════════════════════════════════════════════ -->
<section class="hiw-bg" id="how-it-works">
    <div class="container">
        <div class="text-center mb-5">
            <div class="section-badge" style="background:rgba(99,102,241,.15);border-color:rgba(99,102,241,.3);color:#A5B4FC;">
                <i class="fas fa-route me-1"></i>วิธีการทำงาน
            </div>
            <h2 class="section-title" style="color:#fff;">ตั้งค่าครั้งเดียว<br><span style="color:#A5B4FC;">ทำงานอัตโนมัติตลอด</span></h2>
            <p class="section-sub" style="color:rgba(255,255,255,.55);">ไม่ต้องมีความรู้ด้าน AI ระบบจัดการทุกอย่างให้ตั้งแต่ต้นจนจบ</p>
        </div>

        <div class="row g-4 position-relative">
            <div class="col-lg-3 col-md-6">
                <div class="step-card">
                    <div class="step-connector"></div>
                    <div class="step-num">1</div>
                    <h4 class="step-title">เพิ่มเว็บไซต์ WordPress</h4>
                    <p class="step-desc">เชื่อมต่อเว็บ WordPress ของคุณด้วย URL และ API Key ใช้เวลาไม่ถึง 2 นาที</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="step-card">
                    <div class="step-connector"></div>
                    <div class="step-num">2</div>
                    <h4 class="step-title">เพิ่มคีย์เวิร์ดเป้าหมาย</h4>
                    <p class="step-desc">ใส่คีย์เวิร์ดที่ต้องการ หรือให้ AI วิจัยและแนะนำคีย์เวิร์ดที่มี Search Volume สูงให้อัตโนมัติ</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="step-card">
                    <div class="step-connector"></div>
                    <div class="step-num">3</div>
                    <h4 class="step-title">AI สร้างและโพสต์บทความ</h4>
                    <p class="step-desc">Claude AI เขียนบทความ SEO คุณภาพสูง พร้อม Schema, Internal Links แล้วโพสต์ไป WordPress ให้อัตโนมัติ</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="step-card">
                    <div class="step-num">4</div>
                    <h4 class="step-title">รับแจ้งเตือน & ติดตามผล</h4>
                    <p class="step-desc">รับ Notification ผ่าน Telegram ทุกครั้งที่บทความถูกโพสต์ ดู Report และ Analytics ได้ใน Dashboard</p>
                </div>
            </div>
        </div>

        <!-- Process details -->
        <div class="row g-4 mt-4">
            <div class="col-md-4">
                <div class="floating-card">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <i class="fas fa-robot" style="color:#A5B4FC;font-size:18px;"></i>
                        <span style="color:#fff;font-weight:600;font-size:14px;">AI ทำงานแทนคุณ</span>
                    </div>
                    <p style="color:rgba(255,255,255,.5);font-size:13px;margin:0;">Cron Job รันทุก 5 นาที ตรวจสอบ Queue และสร้างบทความอัตโนมัติตามตาราง Content Plan</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="floating-card">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <i class="fas fa-filter" style="color:#7EE787;font-size:18px;"></i>
                        <span style="color:#fff;font-weight:600;font-size:14px;">ระบบกรองคุณภาพ</span>
                    </div>
                    <p style="color:rgba(255,255,255,.5);font-size:13px;margin:0;">ตรวจสอบความซ้ำซ้อน, Risk Filter สำหรับเนื้อหาที่ไม่เหมาะสม และ Quality Score ก่อนโพสต์ทุกครั้ง</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="floating-card">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <i class="fas fa-sliders" style="color:#D2A8FF;font-size:18px;"></i>
                        <span style="color:#fff;font-weight:600;font-size:14px;">ปรับแต่งได้ทุกอย่าง</span>
                    </div>
                    <p style="color:rgba(255,255,255,.5);font-size:13px;margin:0;">เลือก AI Model, ความยาวบทความ, Tone, Prompt Template และ Posting Schedule ได้ตามต้องการ</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════
     PRICING
══════════════════════════════════════════════ -->
<section class="pricing-bg" id="pricing">
    <div class="container">
        <div class="text-center mb-5">
            <div class="section-badge"><i class="fas fa-tag me-1"></i>ราคา</div>
            <h2 class="section-title">แพ็คเกจที่เหมาะกับทุกขนาด</h2>
            <p class="section-sub">ทดลองใช้ฟรี 5 บทความก่อนตัดสินใจ ไม่ต้องใส่บัตรเครดิต</p>
        </div>

        <div class="row g-4 align-items-stretch justify-content-center">
            <!-- Trial (Free) -->
            <div class="col-lg-3 col-md-6">
                <div class="trial-card">
                    <div class="plan-name"><i class="fas fa-flask me-1"></i>Trial</div>
                    <div class="plan-price"><sup>฿</sup>0<sub>/ตลอดชีพ</sub></div>
                    <p class="plan-desc">ลองก่อนซื้อ เขียนได้ 5 บทความตลอดชีพ ไม่มีวันหมดอายุ</p>
                    <div class="divider"></div>
                    <div class="plan-feature dim">
                        <i class="fas fa-check check"></i><span>5 บทความตลอดชีพ</span>
                    </div>
                    <div class="plan-feature dim">
                        <i class="fas fa-check check"></i><span>1 เว็บไซต์</span>
                    </div>
                    <div class="plan-feature dim">
                        <i class="fas fa-check check"></i><span>AI เขียนบทความ</span>
                    </div>
                    <div class="plan-feature dim">
                        <i class="fas fa-times cross"></i><span>Auto-posting WordPress</span>
                    </div>
                    <div class="plan-feature dim">
                        <i class="fas fa-times cross"></i><span>Content Calendar</span>
                    </div>
                    <div class="plan-feature dim">
                        <i class="fas fa-times cross"></i><span>Telegram Notifications</span>
                    </div>
                    <div class="plan-feature dim">
                        <i class="fas fa-times cross"></i><span>Queue Processor</span>
                    </div>
                    <a href="/member/register.php" class="btn-plan btn-plan-free">
                        <i class="fas fa-rocket me-2"></i>เริ่มฟรีเลย
                    </a>
                </div>
            </div>

            <!-- Paid plans from DB -->
            <?php $planIdx = 0; foreach ($plans as $plan):
                $isPopular = ($plan['slug'] === 'pro');
                $planIdx++;
                $icons = ['starter'=>'fa-seedling','pro'=>'fa-crown','business'=>'fa-building'];
                $icon  = $icons[$plan['slug']] ?? 'fa-box';
            ?>
            <div class="col-lg-3 col-md-6">
                <div class="plan-card <?= $isPopular ? 'popular' : '' ?>">
                    <?php if ($isPopular): ?>
                    <div class="popular-badge">⭐ แนะนำ</div>
                    <?php endif; ?>
                    <div class="plan-name"><i class="fas <?= $icon ?> me-1"></i><?= sanitize($plan['name']) ?></div>
                    <div class="plan-price"><sup>฿</sup><?= number_format($plan['price'],0) ?><sub>/เดือน</sub></div>
                    <?php
                    $descs = [
                        'starter' => 'สำหรับเว็บไซต์เริ่มต้น ที่ต้องการ SEO Content สม่ำเสมอ',
                        'pro'     => 'สำหรับนักการตลาดที่ต้องการ Content Volume สูง',
                        'business'=> 'สำหรับธุรกิจที่มีหลายเว็บไซต์และต้องการ Content ปริมาณมาก',
                    ];
                    ?>
                    <p class="plan-desc"><?= $descs[$plan['slug']] ?? sanitize($plan['description'] ?? '') ?></p>
                    <div class="divider"></div>
                    <div class="plan-feature">
                        <i class="fas fa-check check"></i>
                        <span><strong><?= $plan['articles_per_month'] > 0 ? number_format($plan['articles_per_month']) : '∞' ?> บทความ</strong>/เดือน</span>
                    </div>
                    <div class="plan-feature">
                        <i class="fas fa-check check"></i>
                        <span><?= $plan['max_sites'] ?> เว็บไซต์ WordPress</span>
                    </div>
                    <div class="plan-feature">
                        <i class="fas fa-check check"></i>
                        <span>คีย์เวิร์ดไม่จำกัด</span>
                    </div>
                    <div class="plan-feature">
                        <i class="fas fa-check check"></i>
                        <span>Auto-posting อัตโนมัติ</span>
                    </div>
                    <div class="plan-feature">
                        <i class="fas fa-check check"></i>
                        <span>Content Calendar & Queue</span>
                    </div>
                    <div class="plan-feature">
                        <i class="fas fa-check check"></i>
                        <span>Telegram Notifications</span>
                    </div>
                    <?php if ($plan['slug'] !== 'starter'): ?>
                    <div class="plan-feature">
                        <i class="fas fa-check check"></i>
                        <span>DataForSEO Keyword Research</span>
                    </div>
                    <?php endif; ?>
                    <?php if ($plan['slug'] === 'business'): ?>
                    <div class="plan-feature">
                        <i class="fas fa-check check"></i>
                        <span>Priority Support</span>
                    </div>
                    <?php endif; ?>
                    <a href="/member/register.php" class="btn-plan <?= $isPopular ? 'btn-plan-primary' : 'btn-plan-outline' ?>">
                        <?= $isPopular ? '<i class="fas fa-bolt me-2"></i>' : '' ?>เริ่มต้นใช้งาน
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <p class="text-center text-muted mt-4" style="font-size:13px;">
            <i class="fas fa-lock me-1"></i>ชำระเงินผ่านโอนธนาคาร/PromptPay · ไม่มีสัญญาผูกมัด · ยกเลิกได้ทุกเมื่อ
        </p>
    </div>
</section>

<!-- ══════════════════════════════════════════════
     COMPARISON TABLE
══════════════════════════════════════════════ -->
<section style="background:#fff;padding:80px 0;" id="compare">
    <div class="container">
        <div class="text-center mb-5">
            <div class="section-badge"><i class="fas fa-table me-1"></i>เปรียบเทียบ</div>
            <h2 class="section-title">Trial vs แพ็คเกจ Paid</h2>
            <p class="section-sub">ดูว่าฟีเจอร์ไหนได้เพิ่มเมื่ออัพเกรด</p>
        </div>

        <?php
        $cmpPlans   = array_values($plans); // paid plans only, from DB
        $cmpCount   = count($cmpPlans);
        $maxPrice   = $cmpCount ? max(array_column($cmpPlans, 'price')) : 0;
        $featColPct = $cmpCount > 3 ? 30 : 35;
        $planColPct = $cmpCount ? round((100 - $featColPct - 15) / $cmpCount) : 15;

        $render = fn($v) => match($v) {
            'check' => '<i class="fas fa-check-circle check-yes"></i>',
            'cross' => '<i class="fas fa-times-circle check-no"></i>',
            default => '<span style="font-size:13px;font-weight:600;">' . $v . '</span>',
        };

        $compareRows = [
            ['label'=>'บทความต่อเดือน',      'trial'=>'5 (ตลอดชีพ)', 'type'=>'articles'],
            ['label'=>'เว็บไซต์ WordPress',   'trial'=>'1',           'type'=>'sites'],
            ['label'=>'คีย์เวิร์ด',           'trial'=>'–',           'type'=>'static', 'paid'=>'∞'],
            ['label'=>'AI เขียนบทความ',        'trial'=>'check',       'type'=>'static', 'paid'=>'check'],
            ['label'=>'Auto-post to WordPress','trial'=>'cross',       'type'=>'static', 'paid'=>'check'],
            ['label'=>'Content Calendar',      'trial'=>'cross',       'type'=>'static', 'paid'=>'check'],
            ['label'=>'Queue Manager',         'trial'=>'cross',       'type'=>'static', 'paid'=>'check'],
            ['label'=>'Telegram Notifications','trial'=>'cross',       'type'=>'static', 'paid'=>'check'],
            ['label'=>'FAQ Schema Markup',     'trial'=>'check',       'type'=>'static', 'paid'=>'check'],
            ['label'=>'Internal Link Builder', 'trial'=>'check',       'type'=>'static', 'paid'=>'check'],
            ['label'=>'Keyword Research',      'trial'=>'cross',       'type'=>'dataseo'],
            ['label'=>'Custom AI Prompts',     'trial'=>'check',       'type'=>'static', 'paid'=>'check'],
            ['label'=>'Priority Support',      'trial'=>'cross',       'type'=>'priority'],
        ];

        $planCellValue = function(array $row, array $p) use ($maxPrice): string {
            switch ($row['type']) {
                case 'articles':  return $p['articles_per_month'] > 0 ? number_format($p['articles_per_month']) : '∞';
                case 'sites':     return (string)$p['max_sites'];
                case 'dataseo':   return ($p['max_sites'] >= 3 || $p['articles_per_month'] >= 80) ? 'check' : 'cross';
                case 'priority':  return ((float)$p['price'] >= (float)$maxPrice) ? 'check' : 'cross';
                default:          return $row['paid'] ?? 'check';
            }
        };
        ?>
        <div class="compare-table table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th style="width:<?= $featColPct ?>%;">ฟีเจอร์</th>
                        <th class="text-center" style="width:15%;">Trial ฟรี</th>
                        <?php foreach ($cmpPlans as $cp): ?>
                        <th class="text-center highlight" style="width:<?= $planColPct ?>%;">
                            <?= sanitize($cp['name']) ?><br>
                            <small style="font-size:11px;font-weight:400;opacity:.85;">฿<?= number_format($cp['price'],0) ?>/เดือน</small>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($compareRows as $row): ?>
                    <tr>
                        <td style="font-weight:500;color:#374151;"><?= $row['label'] ?></td>
                        <td class="text-center"><?= $render($row['trial']) ?></td>
                        <?php foreach ($cmpPlans as $cp): ?>
                        <td class="text-center" style="background:rgba(99,102,241,.03);"><?= $render($planCellValue($row, $cp)) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════
     TESTIMONIALS
══════════════════════════════════════════════ -->
<section style="background:linear-gradient(180deg,#f8faff 0%,#fff 100%);">
    <div class="container">
        <div class="text-center mb-5">
            <div class="section-badge"><i class="fas fa-star me-1"></i>รีวิวจากผู้ใช้</div>
            <h2 class="section-title">ผู้ใช้พูดถึงเรายังไง</h2>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="testi-card">
                    <div class="stars">★★★★★</div>
                    <p class="testi-text">"ประหยัดเวลาได้มหาศาล ก่อนหน้านี้จ้างนักเขียนบทความละ 300 บาท ตอนนี้ AI เขียนได้เร็วกว่าและ SEO ดีกว่ามาก อันดับ Google ขึ้นภายใน 3 เดือน"</p>
                    <div class="d-flex align-items-center gap-3">
                        <div class="testi-avatar">ก</div>
                        <div>
                            <div class="testi-name">กิตติ์ นิธิกร</div>
                            <div class="testi-role">เจ้าของเว็บ Affiliate · Starter Plan</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="testi-card">
                    <div class="stars">★★★★★</div>
                    <p class="testi-text">"ทดลองใช้ฟรี 5 บทความแล้วประทับใจมากครับ ภาษาไทยอ่านแล้วเป็นธรรมชาติ ไม่รู้สึกว่า AI เขียนเลย อัพเกรดเป็น Pro ทันที"</p>
                    <div class="d-flex align-items-center gap-3">
                        <div class="testi-avatar" style="background:linear-gradient(135deg,#10B981,#06B6D4);">ว</div>
                        <div>
                            <div class="testi-name">วรากร สิทธิเจริญ</div>
                            <div class="testi-role">SEO Specialist · Pro Plan</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="testi-card">
                    <div class="stars">★★★★★</div>
                    <p class="testi-text">"ระบบ iGaming Mode เป็น Game Changer เลยครับ ไม่ต้องกังวลเรื่อง Prompt ให้ AI เขียนถูก Tone ทุกอย่าง Auto หมด Business Plan คุ้มมาก"</p>
                    <div class="d-flex align-items-center gap-3">
                        <div class="testi-avatar" style="background:linear-gradient(135deg,#F59E0B,#EF4444);">ป</div>
                        <div>
                            <div class="testi-name">ปณิธาน วรวัฒน์</div>
                            <div class="testi-role">iGaming Webmaster · Business Plan</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════
     FAQ
══════════════════════════════════════════════ -->
<section style="background:#fff;padding:80px 0;">
    <div class="container">
        <div class="text-center mb-5">
            <div class="section-badge"><i class="fas fa-circle-question me-1"></i>คำถามที่พบบ่อย</div>
            <h2 class="section-title">FAQ</h2>
        </div>
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="accordion" id="faqAccordion">
                    <?php
                    $faqs = [
                        ['บทความที่ AI เขียนคุณภาพดีแค่ไหน?', 'ระบบใช้ Claude AI จาก Anthropic (ผู้สร้างเดียวกับ Claude.ai) เขียนบทความยาว 2,000–5,000+ คำ ครอบคลุมหัวข้อย่อย LSI Keywords และ Semantic SEO ผ่านการกรองคุณภาพก่อนโพสต์ทุกครั้ง'],
                        ['รองรับ WordPress ทุกเวอร์ชันไหม?', 'รองรับ WordPress 5.0+ ที่เปิดใช้ REST API เชื่อมต่อด้วย Application Password หรือ JWT Token สามารถตั้งค่า Category, Author, Status และ Slug ได้เอง'],
                        ['ถ้าบทความไม่ผ่านคุณภาพจะเกิดอะไรขึ้น?', 'ระบบจะ Mark งานว่า Failed และแจ้งเตือนผ่าน Telegram พร้อมข้อความ Error สามารถ Retry ได้จาก Queue Manager ในหน้า Member Portal'],
                        ['ชำระเงินอย่างไร?', 'ชำระผ่านการโอนธนาคาร หรือ PromptPay อัพโหลดสลิปในระบบ Admin อนุมัติภายใน 24 ชั่วโมง ไม่มีสัญญาผูกมัด ยกเลิกได้ทุกเมื่อ'],
                        ['ทดลองใช้ฟรีมีข้อจำกัดอะไร?', 'Trial Plan ให้เขียนได้ 5 บทความตลอดชีพ ไม่มีวันหมดอายุ แต่ไม่รองรับ Auto-posting และ Queue Processor บทความที่สร้างจะต้องโพสต์เองด้วยมือ'],
                        ['รองรับภาษาไทยได้ดีแค่ไหน?', 'ระบบถูกออกแบบมาเพื่อภาษาไทยโดยเฉพาะ ใช้ Prompt Template ภาษาไทย AI เขียนบทความที่อ่านเป็นธรรมชาติ ไม่มีกลิ่น Google Translate'],
                    ];
                    foreach ($faqs as $i => [$q, $a]):
                    ?>
                    <div class="accordion-item border mb-2" style="border-radius:12px!important;overflow:hidden;">
                        <h2 class="accordion-header">
                            <button class="accordion-button <?= $i > 0 ? 'collapsed' : '' ?> fw-600" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#faq<?= $i ?>"
                                    style="font-size:14px;font-weight:600;border-radius:12px!important;">
                                <?= $q ?>
                            </button>
                        </h2>
                        <div id="faq<?= $i ?>" class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>"
                             data-bs-parent="#faqAccordion">
                            <div class="accordion-body" style="font-size:14px;color:#475569;line-height:1.75;"><?= $a ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════
     CTA
══════════════════════════════════════════════ -->
<section class="cta-section">
    <div class="container position-relative text-center">
        <div class="ai-chip mb-4">
            <i class="fas fa-circle" style="font-size:8px;"></i>AI พร้อมทำงาน 24/7
        </div>
        <h2>พร้อมเริ่มต้นหรือยัง?</h2>
        <p class="mt-3 mb-5">ทดลองฟรี 5 บทความ ไม่ต้องใส่บัตรเครดิต ไม่มีสัญญาผูกมัด</p>
        <div class="d-flex justify-content-center gap-3 flex-wrap">
            <a href="/member/register.php" class="btn-hero-primary" style="background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.3);backdrop-filter:blur(10px);">
                <i class="fas fa-rocket"></i>เริ่มต้นฟรีเลย
            </a>
            <a href="/member/login.php" class="btn-hero-secondary" style="color:rgba(255,255,255,.8);border-color:rgba(255,255,255,.25);">
                <i class="fas fa-sign-in-alt"></i>เข้าสู่ระบบ
            </a>
        </div>
        <p class="mt-4" style="font-size:13px;color:rgba(255,255,255,.5);">
            <i class="fas fa-shield-halved me-1"></i>ปลอดภัย ·
            <i class="fas fa-clock me-1 ms-2"></i>ตั้งค่าใน 5 นาที ·
            <i class="fas fa-headset me-1 ms-2"></i>Support ตอบเร็ว
        </p>
    </div>
</section>

<!-- ══════════════════════════════════════════════
     FOOTER
══════════════════════════════════════════════ -->
<footer>
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-4">
                <div class="footer-brand">
                    <i class="fas fa-robot me-2" style="color:#818CF8;"></i>AI AutoPost SEO
                </div>
                <p class="footer-desc">ระบบ SaaS สร้างบทความ SEO อัตโนมัติด้วย Claude AI เชื่อมต่อ WordPress และโพสต์อัตโนมัติ รองรับธุรกิจทั่วไปและ iGaming</p>
                <div class="d-flex gap-3 mt-4">
                    <a href="https://t.me/" class="footer-link d-inline-flex align-items-center gap-2" style="margin:0;">
                        <i class="fab fa-telegram" style="font-size:18px;color:#0EA5E9;"></i>
                    </a>
                </div>
            </div>
            <div class="col-sm-4 col-lg-2 offset-lg-2">
                <div class="footer-heading">ระบบ</div>
                <a href="#features" class="footer-link">ฟีเจอร์</a>
                <a href="#how-it-works" class="footer-link">วิธีการทำงาน</a>
                <a href="#pricing" class="footer-link">ราคา</a>
                <a href="#compare" class="footer-link">เปรียบเทียบ</a>
            </div>
            <div class="col-sm-4 col-lg-2">
                <div class="footer-heading">เริ่มต้น</div>
                <a href="/member/register.php" class="footer-link">สมัครสมาชิก</a>
                <a href="/member/login.php" class="footer-link">เข้าสู่ระบบ</a>
                <a href="/member/billing.php" class="footer-link">ชำระเงิน</a>
            </div>
            <div class="col-sm-4 col-lg-2">
                <div class="footer-heading">เทคโนโลยี</div>
                <a href="#" class="footer-link">Claude AI</a>
                <a href="#" class="footer-link">WordPress API</a>
                <a href="#" class="footer-link">DataForSEO</a>
                <a href="#" class="footer-link">OpenRouter</a>
            </div>
        </div>

        <div class="footer-bottom d-flex justify-content-between align-items-center flex-wrap gap-3">
            <p class="footer-copy mb-0">© <?= date('Y') ?> AI AutoPost SEO · Built with Claude AI</p>
            <div class="d-flex gap-4">
                <span class="footer-copy">Powered by Anthropic Claude</span>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Navbar scroll effect
window.addEventListener('scroll', () => {
    document.getElementById('mainNav').classList.toggle('scrolled', window.scrollY > 20);
});

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        const target = document.querySelector(a.getAttribute('href'));
        if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});

// Animate terminal typing
(function() {
    const lines = document.querySelectorAll('.terminal-body > div, .terminal-body > br');
    lines.forEach((el, i) => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(4px)';
        setTimeout(() => {
            el.style.transition = 'opacity .3s, transform .3s';
            el.style.opacity = '1';
            el.style.transform = 'none';
        }, 300 + i * 180);
    });
})();

// Intersection Observer for section animations
const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.style.opacity = '1';
            entry.target.style.transform = 'none';
        }
    });
}, { threshold: 0.1 });

document.querySelectorAll('.feature-card, .step-card, .plan-card, .trial-card, .testi-card').forEach(el => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(24px)';
    el.style.transition = 'opacity .5s ease, transform .5s ease';
    observer.observe(el);
});
</script>
</body>
</html>
