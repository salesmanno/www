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
    // Update company info in header
    if (data.company) {
        const headerNameEl = document.getElementById('headerCompanyName');
        if (headerNameEl) headerNameEl.textContent = data.company.name;
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
            servicesGrid.innerHTML = data.services.map(service => {
                if (service.product_type === 'event') {
                    // Event card with registration
                    const spotsLeft = service.max_attendees ? service.max_attendees - (service.registered_count || 0) : null;
                    const isFull = spotsLeft !== null && spotsLeft <= 0;
                    
                    return `
                        <div class="service-card event-card">
                            <h3>${escapeHtml(service.name)}</h3>
                            ${service.description ? `<p>${escapeHtml(service.description)}</p>` : ''}
                            <div class="event-details">
                                ${service.start_date ? `<div class="event-date">📅 ${formatEventDate(service.start_date, service.start_time)}</div>` : ''}
                                ${service.venue_name ? `<div class="event-venue">📍 ${escapeHtml(service.venue_name)}</div>` : ''}
                                ${spotsLeft !== null ? `<div class="event-spots ${isFull ? 'full' : ''}">${isFull ? '❌ Fullt' : `✓ ${spotsLeft} plasser igjen`}</div>` : ''}
                            </div>
                            ${service.ticket_price ? `<div class="service-price">${formatPrice(service.ticket_price)} kr</div>` : '<div class="service-price">Gratis</div>'}
                            ${!isFull ? `<button class="register-btn" onclick="openEventRegistration('${service.product_id}', '${escapeHtml(service.name)}')">Meld deg på</button>` : '<div class="full-badge">Fullt booket</div>'}
                        </div>
                    `;
                } else {
                    // Regular service card
                    return `
                        <div class="service-card">
                            <h3>${escapeHtml(service.name)}</h3>
                            ${service.description ? `<p>${escapeHtml(service.description)}</p>` : ''}
                            ${service.price ? `<div class="service-price">${formatPrice(service.price)} kr</div>` : ''}
                        </div>
                    `;
                }
            }).join('');
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
    
    // Update header tabs
    document.querySelectorAll('.nav-tab').forEach(tab => {
        tab.classList.remove('active');
        if (tab.getAttribute('data-page') === pageName) {
            tab.classList.add('active');
        }
    });
    
    const pageIndex = pages.indexOf(pageName);
    if (pageIndex === -1) return;
    
    isTransitioning = true;
    currentPage = pageName;
    
    const container = document.getElementById('pagesContainer');
    const offset = -pageIndex * 100;
    
    container.style.transform = `translateX(${offset}vw)`;
    container.classList.add('transitioning');
    
    updateActivePage();
    
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

// Update navigation tabs (handled in swipeTo function)

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

function formatEventDate(dateStr, timeStr) {
    if (!dateStr) return '';
    
    const date = new Date(dateStr);
    const dateFormatted = date.toLocaleDateString('no-NO', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric'
    });
    
    if (timeStr) {
        return `${dateFormatted} kl. ${timeStr.substring(0, 5)}`;
    }
    
    return dateFormatted;
}

// Open event registration modal
function openEventRegistration(eventId, eventName) {
    const modal = document.createElement('div');
    modal.className = 'registration-modal';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h2>Meld deg på</h2>
                <button class="close-btn" onclick="this.closest('.registration-modal').remove()">×</button>
            </div>
            <div class="modal-body">
                <h3>${escapeHtml(eventName)}</h3>
                <form id="registrationForm" onsubmit="submitEventRegistration(event, '${eventId}')">
                    <div class="form-group">
                        <label for="reg_name">Fullt navn *</label>
                        <input type="text" id="reg_name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="reg_email">E-post *</label>
                        <input type="email" id="reg_email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="reg_phone">Telefon</label>
                        <input type="tel" id="reg_phone" name="phone">
                    </div>
                    <div id="registration_message" class="message"></div>
                    <button type="submit" class="submit-btn">Meld meg på</button>
                </form>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
}

// Submit event registration
async function submitEventRegistration(event, eventId) {
    event.preventDefault();
    
    const form = event.target;
    const submitBtn = form.querySelector('.submit-btn');
    const messageEl = document.getElementById('registration_message');
    
    const formData = {
        event_id: eventId,
        name: form.name.value,
        email: form.email.value,
        phone: form.phone.value
    };
    
    submitBtn.disabled = true;
    submitBtn.textContent = 'Sender...';
    messageEl.textContent = '';
    
    try {
        const response = await fetch('/api/register_event_public.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(formData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            messageEl.className = 'message success';
            messageEl.textContent = result.message;
            form.reset();
            
            setTimeout(() => {
                document.querySelector('.registration-modal').remove();
            }, 2000);
        } else {
            messageEl.className = 'message error';
            messageEl.textContent = result.message;
            submitBtn.disabled = false;
            submitBtn.textContent = 'Meld meg på';
        }
    } catch (error) {
        messageEl.className = 'message error';
        messageEl.textContent = 'Noe gikk galt. Vennligst prøv igjen.';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Meld meg på';
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
