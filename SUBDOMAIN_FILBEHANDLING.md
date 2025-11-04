# Subdomain Filbehandling - Forklaring

## Hvordan Domeneshop Subdomener Fungerer

### DNS-pekere
Når du oppretter et subdomain via Domeneshop API (f.eks. `bergsoy.salesman.no`), skjer følgende:

1. **DNS A-record opprettes**: Subdomenet peker til samme IP-adresse som hoveddomenet
2. **Samme server**: Alle subdomener går til den samme webserveren
3. **Samme filer**: Det er INGEN automatisk filkopiering eller separate mapper

### Eksempel
```
salesman.no          → IP: 185.xxx.xxx.xxx → /public_html/
bergsoy.salesman.no  → IP: 185.xxx.xxx.xxx → /public_html/  (SAMME MAPPE!)
firma2.salesman.no   → IP: 185.xxx.xxx.xxx → /public_html/  (SAMME MAPPE!)
```

## Multi-Tenant Routing

For å servere forskjellige filer per subdomain, må du implementere **multi-tenant routing** i koden:

### Metode 1: PHP Routing (Anbefalt)
```php
<?php
// index.php
$subdomain = getSubdomain($_SERVER['HTTP_HOST']);

if ($subdomain === 'bergsoy') {
    // Vis Bergsøy sine filer
    include 'tenants/bergsoy/index.php';
} elseif ($subdomain === 'firma2') {
    // Vis Firma2 sine filer
    include 'tenants/firma2/index.php';
} else {
    // Vis hovedside
    include 'main/index.php';
}

function getSubdomain($host) {
    $parts = explode('.', $host);
    if (count($parts) >= 3) {
        return $parts[0]; // Returnerer 'bergsoy' fra 'bergsoy.salesman.no'
    }
    return null;
}
```

### Metode 2: .htaccess Routing
```apache
RewriteEngine On
RewriteCond %{HTTP_HOST} ^([^.]+)\.salesman\.no$
RewriteRule ^(.*)$ /tenants/%1/$1 [L]
```

## Mappestruktur for Multi-Tenant

```
/public_html/
├── index.php                    # Hovedruter
├── saas_portal/                 # SaaS admin (fra root)
├── tenants/
│   ├── bergsoy/
│   │   ├── index.php
│   │   ├── css/
│   │   └── images/
│   ├── firma2/
│   │   ├── index.php
│   │   ├── css/
│   │   └── images/
│   └── shared/                  # Delte ressurser
│       ├── includes/
│       └── templates/
└── main/                        # Hovedside (salesman.no)
    ├── index.php
    └── assets/
```

## Nåværende Implementering

### Hva Fungerer Nå
1. **DNS-oppretting**: Subdomener opprettes via Domeneshop API ✅
2. **Database tracking**: Subdomain status lagres i `tenant_companies` ✅
3. **Admin portal**: Administrere subdomener fra SaaS-portalen ✅

### Hva Som Mangler
1. **Multi-tenant routing**: Kode som ruter trafikk basert på subdomain ❌
2. **Tenant-spesifikke filer**: Separate filer/mapper per bedrift ❌
3. **Automatisk provisjonering**: Auto-opprett tenant-mapper ved ny bedrift ❌

## Implementeringsplan

### Steg 1: Opprett Tenant Router
Implementer `index.php` i roten som leser subdomain og ruter til riktig tenant.

### Steg 2: Auto-Provisjonering
Når et nytt firma opprettes:
1. Opprett mappe: `/tenants/{subdomain}/`
2. Kopier mal-filer fra `/tenants/_template/`
3. Konfigurer tenant-spesifikke innstillinger

### Steg 3: Database-Driven Configuration
Bruk `tenant_website_config` og `tenant_website_general` tabeller til å:
- Laste dynamisk innhold per tenant
- Konfigurere farger, logoer, tekst
- Tilpasse layout og features

## Viktig Merknad

**INGEN filer flyttes automatisk ved subdomain-oppretting!**

Domeneshop API kun oppretter DNS-peker. All filhåndtering må gjøres:
- Via FTP/SFTP manuelt
- Via automatisk provisjonering-script
- Via web-basert filhåndtering i admin-portalen

## Anbefalinger

1. **Bruk database-driven design**: Lagre alt i database, ikke filer
2. **Delt kodebase**: Én kodebase som tilpasser innhold basert på subdomain
3. **Template system**: Bruk samme HTML/CSS med dynamiske variabler
4. **Asset management**: Lagre bilder/filer i object storage eller database
