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
                    // Event card - opens registration overlay
                    const spotsLeft = service.max_attendees ? service.max_attendees - (service.registered_count || 0) : null;
                    const isFull = spotsLeft !== null && spotsLeft <= 0;
                    const eventData = JSON.stringify(service).replace(/"/g, '&quot;');
                    
                    return `
                        <div class="service-card event-card">
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
                                    <button class="register-btn" onclick='openEventRegistration(${eventData})'>
                                        Reserver billett
                                    </button>
                                ` : '<div class="full-badge">Fullt booket</div>'}
                            </div>
                        </div>
                    `;
                } else {
                    // Regular service card - use 'id' as service_id for booking
                    const bookingData = {
                        ...service,
                        service_id: service.id  // Use output_products.id for booking API
                    };
                    return `
                        <div class="service-card">
                            <h3>${escapeHtml(service.name)}</h3>
                            ${service.description ? `<p>${escapeHtml(service.description)}</p>` : ''}
                            ${service.price ? `<div class="service-price">${formatPrice(service.price)} kr</div>` : ''}
                            <button class="book-service-btn" onclick='openServiceBooking(${JSON.stringify(bookingData).replace(/'/g, "&apos;")})'>
                                📅 Book time
                            </button>
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

// Open event registration overlay
function openEventRegistration(eventData) {
    const overlay = document.getElementById('eventRegistrationOverlay');
    const content = document.getElementById('registrationContent');
    
    // Build registration page content
    content.innerHTML = `
        ${eventData.image_url ? `<img src="${escapeHtml(eventData.image_url)}" alt="${escapeHtml(eventData.name)}" class="registration-event-image">` : ''}
        
        <h2 class="registration-event-title">${escapeHtml(eventData.name)}</h2>
        
        <div class="registration-event-info">
            ${eventData.description ? `<p class="event-info-description">${escapeHtml(eventData.description)}</p>` : ''}
            ${eventData.start_date ? `<div class="event-info-item">📅 ${formatEventDate(eventData.start_date, eventData.start_time)}</div>` : ''}
            ${eventData.venue_name ? `<div class="event-info-item">📍 ${escapeHtml(eventData.venue_name)}</div>` : ''}
            ${eventData.ticket_price ? `<div class="event-info-item">💰 ${formatPrice(eventData.ticket_price)} kr</div>` : ''}
            <div class="event-info-notice">ℹ️ Medlemspriser tildeles automatisk</div>
        </div>
        
        <form id="eventRegistrationForm" class="registration-form" onsubmit="submitEventRegistration(event, '${eventData.product_id}')">
            <h3 class="form-title">Påmeldingsinformasjon</h3>
            
            <div class="form-field">
                <label for="reg_name">Fullt navn *</label>
                <input type="text" id="reg_name" name="name" required minlength="2" placeholder="Ola Nordmann">
            </div>
            
            <div class="form-field">
                <label for="reg_email">E-postadresse *</label>
                <input type="email" id="reg_email" name="email" required placeholder="ola@example.com">
            </div>
            
            <div class="form-field">
                <label for="reg_phone">Telefonnummer *</label>
                <input type="tel" id="reg_phone" name="phone" required pattern="[0-9]{8,15}" placeholder="912 34 567">
            </div>
            
            <div id="registrationMessage" class="registration-message" style="display: none;"></div>
            
            <button type="submit" class="submit-registration-btn">Send reservasjon</button>
        </form>
    `;
    
    // Show overlay with slide-up animation
    overlay.classList.add('active');
}

// Close event registration overlay
function closeEventRegistration() {
    const overlay = document.getElementById('eventRegistrationOverlay');
    overlay.classList.remove('active');
}

// Open service booking overlay
let currentServiceData = null;
let currentAvailability = null;
let selectedDate = null;
let selectedTime = null;
let selectedStaff = null;

async function openServiceBooking(serviceData) {
    currentServiceData = serviceData;
    const overlay = document.getElementById('serviceBookingOverlay');
    const content = document.getElementById('serviceBookingContent');
    
    // Use service_id if available, otherwise fall back to id
    const serviceId = serviceData.service_id || serviceData.id;
    
    // Show loading state
    content.innerHTML = `
        <h2 class="registration-event-title">Book ${escapeHtml(serviceData.name)}</h2>
        <div style="text-align: center; padding: 40px;">
            <p>Laster tilgjengelige tider...</p>
        </div>
    `;
    
    overlay.classList.add('active');
    
    // Fetch availability
    try {
        const response = await fetch(`/api/get_public_service_availability.php?service_id=${serviceId}&days=14`);
        const result = await response.json();
        
        if (result.success) {
            currentAvailability = result;
            renderServiceBookingModal();
        } else {
            content.innerHTML = `
                <h2 class="registration-event-title">Book ${escapeHtml(serviceData.name)}</h2>
                <div style="text-align: center; padding: 40px;">
                    <p style="color: #e74c3c;">${result.message || 'Kunne ikke hente tilgjengelige tider'}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error fetching availability:', error);
        content.innerHTML = `
            <h2 class="registration-event-title">Book ${escapeHtml(serviceData.name)}</h2>
            <div style="text-align: center; padding: 40px;">
                <p style="color: #e74c3c;">En feil oppstod. Vennligst prøv igjen senere.</p>
            </div>
        `;
    }
}

function renderServiceBookingModal() {
    const content = document.getElementById('serviceBookingContent');
    const service = currentAvailability.service;
    const availability = currentAvailability.availability;
    const dates = Object.keys(availability).sort();
    
    // Auto-select first date if none selected
    if (!selectedDate && dates.length > 0) {
        selectedDate = dates[0];
    }
    
    content.innerHTML = `
        <h2 class="registration-event-title">${escapeHtml(service.name)}</h2>
        
        <div class="registration-event-info">
            ${service.description ? `<p class="event-info-description">${escapeHtml(service.description)}</p>` : ''}
            <div class="event-info-item">⏱️ Varighet: ${service.duration} minutter</div>
            ${service.price ? `<div class="event-info-item">💰 ${formatPrice(service.price)} kr</div>` : ''}
            <div class="event-info-notice">ℹ️ Medlemspriser tildeles automatisk</div>
        </div>
        
        <div class="booking-calendar-section">
            <h3 class="form-title">Velg dato</h3>
            <div class="date-selector">
                ${dates.map(date => {
                    const dateObj = new Date(date);
                    const dayName = dateObj.toLocaleDateString('no-NO', { weekday: 'short' });
                    const dayNum = dateObj.getDate();
                    const monthName = dateObj.toLocaleDateString('no-NO', { month: 'short' });
                    const isSelected = selectedDate === date;
                    
                    return `
                        <button type="button" class="date-option ${isSelected ? 'selected' : ''}" onclick="selectDate('${date}')">
                            <div class="date-day">${dayName}</div>
                            <div class="date-num">${dayNum}</div>
                            <div class="date-month">${monthName}</div>
                        </button>
                    `;
                }).join('')}
            </div>
        </div>
        
        ${selectedDate ? renderTimeSlots() : ''}
        
        ${selectedTime ? renderBookingForm() : ''}
    `;
}

function renderTimeSlots() {
    const dateData = currentAvailability.availability[selectedDate];
    if (!dateData) return '';
    
    const slots = dateData.slots;
    const timeSlots = Object.values(slots);
    
    return `
        <div class="booking-time-section">
            <h3 class="form-title">Velg tidspunkt</h3>
            <div class="staff-calendar">
                <div class="staff-calendar-header">
                    <div class="time-column-header">Tid</div>
                    ${Array.from({length: currentAvailability.staff_count}, (_, i) => 
                        `<div class="staff-column-header">Ansatt ${i + 1}</div>`
                    ).join('')}
                </div>
                <div class="staff-calendar-body">
                    ${timeSlots.map(slot => {
                        const isSelected = selectedTime === slot.time;
                        const hasAvailability = slot.is_available;
                        
                        return `
                            <div class="time-slot-row ${hasAvailability ? '' : 'unavailable'}">
                                <div class="time-label">${slot.time_display}</div>
                                ${Object.entries(slot.staff).map(([staffNum, isAvailable]) => `
                                    <div class="staff-cell ${isAvailable ? 'available' : 'booked'} ${isSelected && selectedStaff == staffNum ? 'selected' : ''}" 
                                         ${isAvailable ? `onclick="selectTimeSlot('${slot.time}', ${staffNum})"` : ''}>
                                        ${isAvailable ? '✓' : '✗'}
                                    </div>
                                `).join('')}
                            </div>
                        `;
                    }).join('')}
                </div>
            </div>
            <div class="calendar-legend">
                <div class="legend-item"><span class="legend-icon available">✓</span> Ledig</div>
                <div class="legend-item"><span class="legend-icon booked">✗</span> Opptatt</div>
            </div>
        </div>
    `;
}

function renderBookingForm() {
    const timeObj = new Date(`${selectedDate} ${selectedTime}`);
    const timeDisplay = timeObj.toLocaleTimeString('no-NO', { hour: '2-digit', minute: '2-digit' });
    const dateDisplay = new Date(selectedDate).toLocaleDateString('no-NO', { 
        weekday: 'long', 
        day: 'numeric', 
        month: 'long' 
    });
    
    return `
        <form id="serviceBookingForm" class="registration-form" onsubmit="submitServiceBooking(event)">
            <h3 class="form-title">Bekreft booking</h3>
            
            <div class="booking-summary">
                <div class="summary-item">📅 ${dateDisplay}</div>
                <div class="summary-item">⏰ Kl. ${timeDisplay}</div>
                <div class="summary-item">👤 Ansatt ${selectedStaff}</div>
            </div>
            
            <div class="form-field">
                <label for="booking_name">Fullt navn *</label>
                <input type="text" id="booking_name" name="name" required minlength="2" placeholder="Ola Nordmann">
            </div>
            
            <div class="form-field">
                <label for="booking_email">E-postadresse *</label>
                <input type="email" id="booking_email" name="email" required placeholder="ola@example.com">
            </div>
            
            <div class="form-field">
                <label for="booking_phone">Telefonnummer *</label>
                <input type="tel" id="booking_phone" name="phone" required pattern="[0-9]{8,15}" placeholder="912 34 567">
            </div>
            
            <div id="bookingMessage" class="registration-message" style="display: none;"></div>
            
            <button type="submit" class="submit-registration-btn">Bekreft booking</button>
        </form>
    `;
}

function selectDate(date) {
    selectedDate = date;
    selectedTime = null;
    selectedStaff = null;
    renderServiceBookingModal();
}

function selectTimeSlot(time, staffNum) {
    selectedTime = time;
    selectedStaff = staffNum;
    renderServiceBookingModal();
    
    // Scroll to booking form
    setTimeout(() => {
        const form = document.getElementById('serviceBookingForm');
        if (form) {
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, 100);
}

async function submitServiceBooking(e) {
    e.preventDefault();
    
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    const messageEl = document.getElementById('bookingMessage');
    
    // Use service_id if available, otherwise fall back to id
    const serviceId = currentServiceData.service_id || currentServiceData.id;
    
    const formData = {
        service_id: serviceId,
        date: selectedDate,
        time: selectedTime,
        preferred_staff: selectedStaff,
        customer_name: form.name.value.trim(),
        customer_email: form.email.value.trim(),
        customer_phone: form.phone.value.trim()
    };
    
    submitBtn.disabled = true;
    submitBtn.textContent = 'Sender...';
    messageEl.style.display = 'none';
    
    try {
        const response = await fetch('/api/book_service_public.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(formData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            messageEl.className = 'registration-message success';
            messageEl.textContent = result.message || 'Bookingen er bekreftet!';
            messageEl.style.display = 'block';
            
            form.reset();
            
            setTimeout(() => {
                closeServiceBooking();
            }, 2500);
        } else {
            messageEl.className = 'registration-message error';
            messageEl.textContent = result.message || 'En feil oppstod. Vennligst prøv igjen.';
            messageEl.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Bekreft booking';
        }
    } catch (error) {
        console.error('Booking error:', error);
        messageEl.className = 'registration-message error';
        messageEl.textContent = 'En feil oppstod. Vennligst prøv igjen senere.';
        messageEl.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Bekreft booking';
    }
}

function closeServiceBooking() {
    const overlay = document.getElementById('serviceBookingOverlay');
    overlay.classList.remove('active');
    selectedDate = null;
    selectedTime = null;
    selectedStaff = null;
    currentServiceData = null;
    currentAvailability = null;
}

// Submit event registration
async function submitEventRegistration(e, eventId) {
    e.preventDefault();
    
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    const messageEl = document.getElementById('registrationMessage');
    
    const formData = {
        event_id: eventId,
        name: form.name.value.trim(),
        email: form.email.value.trim(),
        phone: form.phone.value.trim()
    };
    
    submitBtn.disabled = true;
    submitBtn.textContent = 'Sender...';
    messageEl.style.display = 'none';
    
    try {
        const response = await fetch('/api/register_event_public.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(formData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            messageEl.className = 'registration-message success';
            messageEl.textContent = '✓ ' + result.message;
            messageEl.style.display = 'block';
            form.reset();
            
            setTimeout(() => {
                closeEventRegistration();
                loadData(); // Reload to update capacity
            }, 2000);
        } else {
            messageEl.className = 'registration-message error';
            messageEl.textContent = result.message;
            messageEl.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Send reservasjon';
        }
    } catch (error) {
        messageEl.className = 'registration-message error';
        messageEl.textContent = 'Noe gikk galt. Vennligst prøv igjen.';
        messageEl.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Send reservasjon';
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
