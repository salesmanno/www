// Configuration
const subdomain = 'bergsoy';
const API_BASE = '/api';

// State
let currentPage = 'home';
let touchStartX = 0;
let touchEndX = 0;
let isTransitioning = false;

// Page order
const pages = ['about', 'home', 'services'];

// Initialize on load
document.addEventListener('DOMContentLoaded', () => {
    loadData();
    setupSwipeListeners();
    setupDotNavigation();
});

// Load data from API
async function loadData() {
    try {
        const response = await fetch(`${API_BASE}/get_public_website_data.php?subdomain=${subdomain}`);
        const data = await response.json();
        
        if (data.success) {
            updatePageContent(data);
        } else {
            console.error('Failed to load data:', data.error);
        }
    } catch (error) {
        console.error('Error loading data:', error);
    }
}

// Update page content with API data
function updatePageContent(data) {
    // Update company info
    if (data.company) {
        const titleEl = document.getElementById('heroTitle');
        if (titleEl) titleEl.textContent = data.company.name;
    }
    
    // Update hero section with settings
    if (data.settings) {
        const subtitleEl = document.getElementById('heroSubtitle');
        if (subtitleEl && data.settings.hero_description) {
            subtitleEl.textContent = data.settings.hero_description;
        }
    }
    
    // Update About Us content
    if (data.aboutUs) {
        const aboutContent = document.getElementById('aboutContent');
        if (aboutContent) {
            aboutContent.innerHTML = data.aboutUs;
        }
    } else {
        const aboutContent = document.getElementById('aboutContent');
        if (aboutContent) {
            aboutContent.innerHTML = '<p>Informasjon kommer snart...</p>';
        }
    }
    
    // Update News/Announcements
    if (data.announcements && data.announcements.length > 0) {
        const newsContainer = document.getElementById('newsContainer');
        if (newsContainer) {
            newsContainer.innerHTML = data.announcements.map(news => `
                <div class="news-card ${news.is_pinned ? 'pinned' : ''}">
                    <h3>${escapeHtml(news.title)}</h3>
                    <p>${escapeHtml(news.content)}</p>
                    <div class="news-date">${formatDate(news.publish_at || news.created_at)}</div>
                </div>
            `).join('');
        }
    } else {
        const newsContainer = document.getElementById('newsContainer');
        if (newsContainer) {
            newsContainer.innerHTML = '<p>Ingen nyheter for øyeblikket.</p>';
        }
    }
    
    // Update Services
    if (data.services && data.services.length > 0) {
        const servicesGrid = document.getElementById('servicesGrid');
        if (servicesGrid) {
            servicesGrid.innerHTML = data.services.map(service => `
                <div class="service-card">
                    <h3>${escapeHtml(service.name)}</h3>
                    ${service.description ? `<p>${escapeHtml(service.description)}</p>` : ''}
                    ${service.price ? `<div class="service-price">${formatPrice(service.price)} kr</div>` : ''}
                </div>
            `).join('');
        }
    } else {
        const servicesGrid = document.getElementById('servicesGrid');
        if (servicesGrid) {
            servicesGrid.innerHTML = '<p>Ingen tjenester tilgjengelig for øyeblikket.</p>';
        }
    }
}

// Swipe navigation
function swipeTo(pageName) {
    if (isTransitioning) return;
    
    const pageIndex = pages.indexOf(pageName);
    if (pageIndex === -1) return;
    
    isTransitioning = true;
    currentPage = pageName;
    
    const container = document.getElementById('pagesContainer');
    const offset = -pageIndex * 100;
    
    container.style.transform = `translateX(${offset}vw)`;
    container.classList.add('transitioning');
    
    updateActivePage();
    updateDots();
    
    setTimeout(() => {
        isTransitioning = false;
        container.classList.remove('transitioning');
    }, 400);
}

// Update active page class
function updateActivePage() {
    document.querySelectorAll('.page').forEach(page => {
        page.classList.remove('active');
    });
    
    const activePage = document.getElementById(`${currentPage}Page`);
    if (activePage) {
        activePage.classList.add('active');
    }
}

// Update navigation dots
function updateDots() {
    document.querySelectorAll('.dot').forEach(dot => {
        dot.classList.remove('active');
        if (dot.dataset.page === currentPage) {
            dot.classList.add('active');
        }
    });
}

// Setup swipe listeners
function setupSwipeListeners() {
    const container = document.getElementById('pagesContainer');
    
    container.addEventListener('touchstart', (e) => {
        touchStartX = e.touches[0].clientX;
    }, { passive: true });
    
    container.addEventListener('touchend', (e) => {
        touchEndX = e.changedTouches[0].clientX;
        handleSwipe();
    }, { passive: true });
}

// Handle swipe gesture
function handleSwipe() {
    const swipeThreshold = 50;
    const diff = touchStartX - touchEndX;
    
    if (Math.abs(diff) < swipeThreshold) return;
    
    const currentIndex = pages.indexOf(currentPage);
    
    if (diff > 0 && currentIndex < pages.length - 1) {
        // Swipe left - go to next page
        swipeTo(pages[currentIndex + 1]);
    } else if (diff < 0 && currentIndex > 0) {
        // Swipe right - go to previous page
        swipeTo(pages[currentIndex - 1]);
    }
}

// Setup dot navigation clicks
function setupDotNavigation() {
    document.querySelectorAll('.dot').forEach(dot => {
        dot.addEventListener('click', () => {
            swipeTo(dot.dataset.page);
        });
    });
}

// Utility functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatPrice(price) {
    return parseFloat(price).toLocaleString('no-NO', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatDate(dateString) {
    if (!dateString) return '';
    
    const date = new Date(dateString);
    const now = new Date();
    const diffTime = Math.abs(now - date);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    
    if (diffDays === 0) {
        return 'I dag';
    } else if (diffDays === 1) {
        return 'I går';
    } else if (diffDays < 7) {
        return `${diffDays} dager siden`;
    } else {
        return date.toLocaleDateString('no-NO', {
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        });
    }
}

// Keyboard navigation (optional)
document.addEventListener('keydown', (e) => {
    if (isTransitioning) return;
    
    const currentIndex = pages.indexOf(currentPage);
    
    if (e.key === 'ArrowLeft' && currentIndex > 0) {
        swipeTo(pages[currentIndex - 1]);
    } else if (e.key === 'ArrowRight' && currentIndex < pages.length - 1) {
        swipeTo(pages[currentIndex + 1]);
    }
});
