<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Bergsøy</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/customer-view.css" rel="stylesheet">
</head>
<body>
    <div class="app-container">
        <!-- Logo Section -->
        <div class="logo-section">
            <img src="/attached_assets/bergsoy_logo.png" alt="Bergsøy Logo" class="header-logo" id="headerLogo">
            <h1 class="header-company-name" id="headerCompanyName">Bergsøy</h1>
        </div>
        
        <!-- Navigation Header -->
        <div class="nav-header">
            <span class="nav-tab" data-page="about" onclick="swipeTo('about')">Om Oss</span>
            <span class="nav-tab active" data-page="home" onclick="swipeTo('home')">Hjem</span>
            <span class="nav-tab" data-page="services" onclick="swipeTo('services')">Tjenester</span>
        </div>

        <!-- Pages Container -->
        <div class="pages-container" id="pagesContainer">
            <!-- Om Oss Page -->
            <div class="page" id="aboutPage">
                <div class="page-content">
                    <button class="nav-arrow nav-arrow-right" onclick="swipeTo('home')">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 18l6-6-6-6"/>
                        </svg>
                    </button>
                    <div class="content-wrapper">
                        <h1 class="page-title">Om Oss</h1>
                        <div class="about-content" id="aboutContent">
                            <p>Laster innhold...</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hjem Page -->
            <div class="page active" id="homePage">
                <div class="page-content">
                    <button class="nav-arrow nav-arrow-left" onclick="swipeTo('about')">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M15 18l-6-6 6-6"/>
                        </svg>
                    </button>
                    <button class="nav-arrow nav-arrow-right" onclick="swipeTo('services')">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 18l6-6-6-6"/>
                        </svg>
                    </button>
                    <div class="content-wrapper">
                        <div class="hero-section">
                            <p class="hero-subtitle" id="heroSubtitle">Velkommen</p>
                        </div>
                        
                        <!-- News/Announcements Section -->
                        <div class="news-section" id="newsSection">
                            <h2 class="section-title">Nyheter</h2>
                            <div class="news-container" id="newsContainer">
                                <p>Laster nyheter...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tjenester Page -->
            <div class="page" id="servicesPage">
                <div class="page-content">
                    <button class="nav-arrow nav-arrow-left" onclick="swipeTo('home')">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M15 18l-6-6 6-6"/>
                        </svg>
                    </button>
                    <div class="content-wrapper">
                        <h1 class="page-title">Tjenester</h1>
                        <div class="services-grid" id="servicesGrid">
                            <p>Laster tjenester...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/customer-view.js"></script>
</body>
</html>
