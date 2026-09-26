<?php
require_once __DIR__ . '/../../app/Config/database.php';
db_init();

if (current_user()) {
    gb_redirect('dashboard.php');
}

$error = null;
$rate_limited = false;

// Rate limiting: max 5 failed attempts per IP per 15 minutes
$rate_limit_key = 'login_attempts_' . md5($_SERVER['REMOTE_ADDR'] ?? '0');
if (!isset($_SESSION[$rate_limit_key])) {
    $_SESSION[$rate_limit_key] = ['count' => 0, 'first_at' => time()];
}
$attempts = &$_SESSION[$rate_limit_key];
// Reset window after 15 minutes
if (time() - $attempts['first_at'] > 900) {
    $attempts = ['count' => 0, 'first_at' => time()];
}
if ($attempts['count'] >= 5) {
    $rate_limited = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('login.php');
    if ($rate_limited) {
        $error = 'Too many failed login attempts. Please wait 15 minutes and try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = 'Please enter email and password.';
        } else {
            $pdo = db_connect();
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password_hash'])) {
                // Authentication succeeded — reset rate limit before business checks
                unset($_SESSION[$rate_limit_key]);
                if ((int) ($user['business_id'] ?? 0) <= 0) {
                    $error = 'This account is not linked to a business. Contact support or register a new business.';
                } else {
                $pdo = db_connect();
                $stmt = $pdo->prepare('SELECT id, approval_status FROM businesses WHERE id = ? LIMIT 1');
                $stmt->execute([(int) $user['business_id']]);
                $business = $stmt->fetch();
                if (!$business) {
                    $error = 'Your business record is missing. Please register again or contact support.';
                } elseif (($business['approval_status'] ?? 'active') === 'pending') {
                    $error = 'Your business account is pending approval. You will be notified once approved.';
                } elseif (($business['approval_status'] ?? 'active') === 'suspended') {
                    $error = 'Your business account has been suspended. Please contact support.';
                } elseif (($business['approval_status'] ?? 'active') === 'rejected') {
                    $error = 'Your business registration was not approved. Please contact support.';
                } elseif (($business['approval_status'] ?? 'active') === 'deactivated') {
                    $error = 'Your business account has been deactivated. Please contact support.';
                } else {
                session_regenerate_id(true);
                // Regenerate CSRF token after authentication
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['business_id'] = (int) $user['business_id'];
                require_once __DIR__ . '/../../app/Config/tenant.php';
                tenant_ensure_user_staff_link($pdo, $user);
                log_login(
                    $pdo,
                    (int) $user['id'],
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                );
                gb_redirect('dashboard.php');
                }
                }
            } else {
                // Authentication failed — increment rate limit counter
                $attempts['count']++;
                $error = 'Invalid login details.';
            }
        }
    }
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - GuestBridge Rwanda</title>
    <link rel="icon" href="<?php echo htmlspecialchars(gb_url('assets/guestbridge-mark.svg')); ?>">

    <?php gb_render_stylesheets(); ?>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Cormorant+Garamond:wght@500;600;700&display=swap" rel="stylesheet">

    <style>

        :root{
            --primary:#2563eb;
            --primary-light:#3b82f6;
            --primary-soft:#dbeafe;
            --bg:#ffffff;
            --card:#ffffff;
            --text:#0f172a;
            --muted:#64748b;
            --border:#e2e8f0;
        }

        *{
            margin:0;
            padding:0;
            box-sizing:border-box;
        }

        body{
            font-family:'Inter',sans-serif;
            background:#ffffff;
            color:var(--text);
            overflow-x:hidden;
        }

        body::before{
            content:'';
            position:fixed;
            inset:0;
            background:
                radial-gradient(circle at top left, rgba(37,99,235,.08), transparent 35%),
                radial-gradient(circle at bottom right, rgba(37,99,235,.05), transparent 30%);
            z-index:-1;
        }

        .login-wrapper{
            min-height:100vh;
            display:flex;
        }

        /* LEFT SIDE */

        .login-left{
            width:50%;
            background:#ffffff;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:70px;
            position:relative;
        }

        .login-content{
            width:100%;
            max-width:500px;
        }

        .brand{
            display:flex;
            align-items:center;
            gap:14px;
            margin-bottom:42px;
        }

        .brand img{
            width:46px;
            height:46px;
            object-fit:contain;
        }

        .brand span{
            font-size:15px;
            font-weight:800;
            letter-spacing:.24em;
            text-transform:uppercase;
            color:var(--primary);
        }

        .floating-badge{
            display:inline-flex;
            align-items:center;
            padding:12px 18px;
            border-radius:999px;
            background:rgba(37,99,235,.07);
            color:var(--primary);
            font-size:12px;
            font-weight:700;
            letter-spacing:.18em;
            text-transform:uppercase;
            margin-bottom:24px;
            border:1px solid rgba(37,99,235,.12);
        }

        .headline{
            font-family:'Cormorant Garamond',serif;
            font-size:68px;
            line-height:.92;
            font-weight:700;
            letter-spacing:-2px;
            margin-bottom:22px;
            color:#0f172a;
        }

        .headline .blue{
            color:var(--primary);
            font-style:italic;
        }

        .subtext{
            color:var(--muted);
            font-size:15px;
            line-height:1.9;
            margin-bottom:38px;
            max-width:460px;
        }

        /* FORM CARD */

        .form-card{
            background:#ffffff;
            border:1px solid var(--border);
            border-radius:30px;
            padding:36px;
            box-shadow:
                0 10px 40px rgba(37,99,235,.08);
        }

        .form-title{
            font-size:13px;
            color:var(--primary);
            font-weight:800;
            letter-spacing:.22em;
            text-transform:uppercase;
            margin-bottom:24px;
        }

        .form-group{
            margin-bottom:18px;
        }

        .form-label{
            display:block;
            margin-bottom:10px;
            color:#0f172a;
            font-size:13px;
            font-weight:600;
        }

        .form-control{
            width:100%;
            height:58px;
            border-radius:18px;
            border:1px solid var(--border);
            background:#fff;
            color:#0f172a;
            padding:0 18px;
            font-size:15px;
            transition:.25s ease;
        }

        .form-control:focus{
            outline:none;
            border-color:rgba(37,99,235,.45);
            box-shadow:
                0 0 0 4px rgba(37,99,235,.10),
                0 10px 30px rgba(37,99,235,.08);
        }

        .form-control::placeholder{
            color:#94a3b8;
        }

        .login-btn{
            width:100%;
            height:60px;
            border:none;
            border-radius:18px;
            background:linear-gradient(
                135deg,
                #2563eb,
                #3b82f6
            );
            color:#fff;
            font-size:14px;
            font-weight:800;
            letter-spacing:.12em;
            text-transform:uppercase;
            cursor:pointer;
            transition:.3s ease;
            margin-top:10px;
            box-shadow:
                0 12px 30px rgba(37,99,235,.20);
        }

        .login-btn:hover{
            transform:translateY(-3px);
            box-shadow:
                0 20px 50px rgba(37,99,235,.25);
        }

        .divider{
            height:1px;
            background:linear-gradient(
                to right,
                transparent,
                rgba(37,99,235,.18),
                transparent
            );
            margin:28px 0;
        }

        .bottom-links{
            text-align:center;
        }

        .bottom-links p{
            margin-bottom:10px;
            color:var(--muted);
            font-size:14px;
        }

        .bottom-links a{
            color:var(--primary);
            text-decoration:none;
            font-weight:700;
        }

        .bottom-links a:hover{
            text-decoration:underline;
        }

        /* ALERTS */

        .alert{
            border:none;
            border-radius:18px;
            padding:16px 18px;
            margin-bottom:22px;
            font-size:14px;
        }

        .alert-success{
            background:#ecfdf3;
            color:#166534;
        }

        .alert-danger{
            background:#fef2f2;
            color:#991b1b;
        }

        /* RIGHT SIDE */

        .login-right{
            width:50%;
            position:relative;
            overflow:hidden;
        }

        .login-right img{
            width:100%;
            height:100%;
            object-fit:cover;
            transform:scale(1.03);
            filter:brightness(.75);
        }

        .overlay{
            position:absolute;
            inset:0;
            background:
                linear-gradient(
                    to bottom,
                    rgba(37,99,235,.08),
                    rgba(15,23,42,.60)
                );
        }

        .hero-content{
            position:absolute;
            left:60px;
            right:60px;
            bottom:70px;
            z-index:2;
            color:#fff;
        }

        .hero-mini{
            color:#bfdbfe;
            text-transform:uppercase;
            letter-spacing:.28em;
            font-size:12px;
            font-weight:800;
            margin-bottom:20px;
        }

        .hero-title{
            font-family:'Cormorant Garamond',serif;
            font-size:70px;
            line-height:.92;
            font-weight:700;
            margin-bottom:22px;
        }

        .hero-text{
            font-size:16px;
            line-height:1.9;
            color:rgba(255,255,255,.88);
            max-width:500px;
        }

        /* RESPONSIVE */

        @media(max-width:991px){

            .login-wrapper{
                flex-direction:column;
            }

            .login-left,
            .login-right{
                width:100%;
            }

            .login-right{
                min-height:420px;
                order:-1;
            }

            .login-left{
                padding:40px 24px;
            }

            .headline{
                font-size:48px;
            }

            .hero-title{
                font-size:48px;
            }

            .hero-content{
                left:30px;
                right:30px;
                bottom:40px;
            }
        }

        @media(max-width:640px){

            .headline{
                font-size:40px;
            }

            .hero-title{
                font-size:40px;
            }

            .form-card{
                padding:24px;
            }
        }

    </style>

</head>

<body>

<div class="login-wrapper">

    <!-- LEFT -->

    <div class="login-left">

        <div class="login-content">

            <div class="brand">
                <img src="/guestbridgerwanda/assets/guestbridge-mark.svg" alt="GuestBridge">
                <span>GuestBridge Rwanda</span>
            </div>

            <div class="floating-badge">
                Hospitality Enterprise Platform
            </div>

            <h1 class="headline">
                Welcome <span class="blue">back</span>.
            </h1>

            <p class="subtext">
                Manage hotel referrals, commissions, guest transfers,
                and partnerships through a secure enterprise dashboard
                built for modern hospitality businesses.
            </p>

            <?php if ($flash): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($flash); ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="form-card">

                <div class="form-title">
                    Secure Login
                </div>

                <form method="post" novalidate>

                    <?php echo csrf_field(); ?>

                    <div class="form-group">

                        <label class="form-label">
                            Email address
                        </label>

                        <input
                            type="email"
                            name="email"
                            class="form-control"
                            placeholder="Enter your email"
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label class="form-label">
                            Password
                        </label>

                        <input
                            type="password"
                            name="password"
                            class="form-control"
                            placeholder="Enter your password"
                            required
                        >

                    </div>

                    <button class="login-btn">
                        Access Dashboard
                    </button>

                </form>

                <div class="divider"></div>

                <div class="bottom-links">

                    <p>
                        New business?
                        <a href="<?php echo htmlspecialchars(gb_url('register.php')); ?>">
                            Register here
                        </a>
                    </p>

                    <p>
                        <a href="forgot_password.php">
                            Forgot your password?
                        </a>
                    </p>

                    <p class="small">
                        <a href="<?php echo htmlspecialchars(gb_url('partner_places.php')); ?>">
                            Browse partner hotels & places
                        </a>
                    </p>

                </div>

            </div>

        </div>

    </div>

    <!-- RIGHT -->

    <div class="login-right">

        <img
            src="https://images.unsplash.com/photo-1520250497591-112f2f40a3f4?q=80&w=1974&auto=format&fit=crop"
            alt="GuestBridge Rwanda"
        >

        <div class="overlay"></div>

        <div class="hero-content">

            <div class="hero-mini">
                GuestBridge Enterprise
            </div>

            <h2 class="hero-title">
                Rwanda's<br>
                hospitality<br>
                network.
            </h2>

            <p class="hero-text">
                A modern platform for hotels, resorts,
                and travel businesses that expect
                operational excellence.
            </p>

        </div>

    </div>

</div>

</body>
</html>