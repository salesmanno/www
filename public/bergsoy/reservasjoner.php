<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arrangement - Bergsøy Danseklubb</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/reservations.css" rel="stylesheet">
</head>
<body>
    <div class="page-container">
        <!-- Header -->
        <header class="site-header">
            <div class="header-content">
                <div class="logo-section">
                    <img src="/attached_assets/bergsoy_logo.png" alt="Logo" class="header-logo">
                    <h1 class="company-name" id="companyName">Bergsøy Danseklubb</h1>
                </div>
                <nav class="main-nav">
                    <a href="/public/bergsoy/" class="nav-link">Tilbake til hjem</a>
                </nav>
            </div>
        </header>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-wrapper">
                <h1 class="page-title">Kommende arrangementer</h1>
                <p class="page-subtitle">Meld deg på våre spennende arrangementer</p>

                <div id="eventsContainer" class="events-container">
                    <div class="loading">Laster arrangementer...</div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="site-footer">
            <p>&copy; 2025 Bergsøy Danseklubb. Alle rettigheter reservert.</p>
        </footer>
    </div>

    <!-- Registration Success Modal -->
    <div id="successModal" class="modal hidden">
        <div class="modal-content success">
            <div class="modal-header">
                <h2>✓ Påmelding bekreftet!</h2>
            </div>
            <div class="modal-body">
                <p id="successMessage">Du er nå påmeldt arrangementet.</p>
                <button class="btn btn-primary" onclick="closeSuccessModal()">Lukk</button>
            </div>
        </div>
    </div>

    <script src="../assets/js/reservations.js"></script>
</body>
</html>
