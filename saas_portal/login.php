<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SaaS Admin - Innlogging</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-start: #dc2626;
            --primary-end: #991b1b;
            --shadow-light: rgba(220, 38, 38, 0.15);
            --shadow-medium: rgba(220, 38, 38, 0.25);
            --shadow-strong: rgba(220, 38, 38, 0.35);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, var(--primary-start) 0%, var(--primary-end) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            max-width: 500px;
            width: 100%;
        }
        
        .login-section {
            padding: 60px 50px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .login-header h1 {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-start), var(--primary-end));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }
        
        .login-header p {
            color: #6b7280;
            font-size: 15px;
            font-weight: 400;
        }
        
        .form-floating {
            margin-bottom: 20px;
        }
        
        .form-floating > .form-control {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            font-size: 15px;
            transition: all 0.2s ease;
            height: 58px;
        }
        
        .form-floating > .form-control:focus {
            border-color: var(--primary-start);
            box-shadow: 0 0 0 4px var(--shadow-light);
            outline: none;
        }
        
        .form-floating > label {
            padding: 1rem 1.25rem;
            color: #9ca3af;
            font-weight: 500;
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--primary-start) 0%, var(--primary-end) 100%);
            border: none;
            padding: 16px 24px;
            font-weight: 600;
            font-size: 16px;
            border-radius: 12px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px var(--shadow-medium);
            color: white;
            width: 100%;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px var(--shadow-strong);
            background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            font-size: 14px;
            font-weight: 500;
            padding: 14px 18px;
            margin-bottom: 24px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-section">
            <div class="login-header">
                <h1>SaaS Admin Portal</h1>
                <p>Systemadministrasjon</p>
            </div>
            
            <?php
            if (isset($_GET['error'])):
                $error_msg = 'Ukjent feil';
                switch($_GET['error']) {
                    case 'database_configuration': $error_msg = 'Databaseinnstillingene på serveren mangler eller er ugyldige. Konfigurer MYSQL-variablene eller den private salesman-private/mysql.php-filen utenfor webroten. Oppdatering fra Git setter ikke disse innstillingene.'; break;
                    case 'database_connection': $error_msg = 'Serveren kunne ikke koble til databasen. Kontroller databasevert, port og innloggingsopplysninger på webhotellet.'; break;
                    case 'database_query': $error_msg = 'Databaseforbindelsen ble opprettet, men et databaseoppslag under innlogging feilet. Se serverens PHP-feillogg.'; break;
                    case 'system_error': $error_msg = 'Innloggingen kunne ikke fullføres på grunn av en systemfeil. Prøv igjen. Hvis feilen vedvarer, må databaseforbindelsen kontrolleres.'; break;
                    case 'invalid_request': $error_msg = 'Ugyldig innloggingsforespørsel. Prøv igjen.'; break;
                    case 'invalid_credentials': $error_msg = 'Ugyldig e-post eller passord'; break;
                    case 'not_authorized': $error_msg = 'Ingen tilgang'; break;
                    case 'inactive': $error_msg = 'Kontoen er inaktivert'; break;
                }
            ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error_msg ?>
            </div>
            <?php endif; ?>
            
            <form action="/saas_portal/auth/authenticate.php" method="POST">
                <div class="form-floating">
                    <input type="email" class="form-control" id="email" name="email" placeholder="E-post" required>
                    <label for="email">E-postadresse</label>
                </div>
                
                <div class="form-floating">
                    <input type="password" class="form-control" id="password" name="password" placeholder="Passord" required>
                    <label for="password">Passord</label>
                </div>
                
                <button type="submit" class="btn btn-login">Logg inn</button>
            </form>
        </div>
    </div>
</body>
</html>
