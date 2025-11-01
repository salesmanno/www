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
                    // Event card with expandable registration
                    const spotsLeft = service.max_attendees ? service.max_attendees - (service.registered_count || 0) : null;
                    const isFull = spotsLeft !== null && spotsLeft <= 0;
                    const eventId = service.product_id;
                    
                    return `
                        <div class="service-card event-card" id="event-${eventId}">
                            ${service.image_url ? `<img src="${escapeHtml(service.image_url)}" alt="${escapeHtml(service.name)}" class="event-image">` : ''}
                            
                            <div class="event-card-content">
                                <h3>${escapeHtml(service.name)}</h3>
                                ${service.description ? `<p class="event-description">${escapeHtml(service.description)}</p>` : ''}
                                
                                <div class="event-details">
                                    ${service.start_date ? `<div class="event-date">📅 ${formatEventDate(service.start_date, service.start_time)}</div>` : ''}
                                    ${service.venue_name ? `<div class="event-venue">📍 ${escapeHtml(service.venue_name)}</div>` : ''}
                                    ${isFull ? `<div class="event-spots full">❌ Fullt</div>` : ''}
                                </div>
                                
                                ${service.ticket_price ? `<div class="service-price">${formatPrice(service.ticket_price)} kr</div>` : '<div class="service-price">Gratis</div>'}
                                
                                ${!isFull ? `
                                    <button class="register-btn" onclick="toggleEventRegistration('${eventId}')">
                                        <span class="btn-text">Reserver billett</span>
                                        <span class="btn-arrow">↓</span>
                                    </button>
                                ` : '<div class="full-badge">Fullt booket</div>'}
                                
                                <div class="event-registration-form" id="form-${eventId}" style="display: none;">
                                    <h4>Meld deg på arrangementet</h4>
                                    <form onsubmit="submitEventRegistration(event, '${eventId}', '${escapeHtml(service.name)}')">
                                        <div class="form-group">
                                            <label>Fullt navn *</label>
                                            <input type="text" name="name" required minlength="2" placeholder="Ola Nordmann">
                                        </div>
                                        <div class="form-group">
                                            <label>E-post *</label>
                                            <input type="email" name="email" required placeholder="ola@example.com">
                                        </div>
                                        <div class="form-group">
                                            <label>Telefon *</label>
                                            <input type="tel" name="phone" required pattern="[0-9]{8,15}" placeholder="912 34 567">
                                        </div>
                                        <div class="form-message-${eventId}" style="display: none;"></div>
                                        <button type="submit" class="submit-btn">Send påmelding</button>
                                    </form>
                                </div>
                            </div>
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

// Toggle event registration form
function toggleEventRegistration(eventId) {
    const form = document.getElementById(`form-${eventId}`);
    const card = document.getElementById(`event-${eventId}`);
    const button = card.querySelector('.register-btn');
    const arrow = button.querySelector('.btn-arrow');
    
    if (form.style.display === 'none') {
        // Show form with smooth animation
        form.style.display = 'block';
        setTimeout(() => {
            form.classList.add('show');
            card.classList.add('expanded');
            arrow.textContent = '↑';
            button.querySelector('.btn-text').textContent = 'Skjul skjema';
        }, 10);
    } else {
        // Hide form
        form.classList.remove('show');
        card.classList.remove('expanded');
        arrow.textContent = '↓';
        button.querySelector('.btn-text').textContent = 'Reserver billett';
        setTimeout(() => {
            form.style.display = 'none';
        }, 300);
    }
}

// Submit event registration
async function submitEventRegistration(e, eventId, eventName) {
    e.preventDefault();
    
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    const messageContainer = document.querySelector(`.form-message-${eventId}`);
    
    const formData = {
        event_id: eventId,
        name: form.name.value.trim(),
        email: form.email.value.trim(),
        phone: form.phone.value.trim()
    };
    
    submitBtn.disabled = true;
    submitBtn.textContent = 'Sender...';
    messageContainer.style.display = 'none';
    
    try {
        const response = await fetch('/api/register_event_public.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(formData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            messageContainer.className = `form-message-${eventId} success`;
            messageContainer.textContent = '✓ ' + result.message;
            messageContainer.style.display = 'block';
            form.reset();
            
            setTimeout(() => {
                toggleEventRegistration(eventId);
                loadData(); // Reload to update capacity
            }, 2000);
        } else {
            messageContainer.className = `form-message-${eventId} error`;
            messageContainer.textContent = result.message;
            messageContainer.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Send påmelding';
        }
    } catch (error) {
        messageContainer.className = `form-message-${eventId} error`;
        messageContainer.textContent = 'Noe gikk galt. Vennligst prøv igjen.';
        messageContainer.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Send påmelding';
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
