<?php
require_once __DIR__ . '/../../app/Config/database.php';
db_init();

if (current_user()) {
    gb_redirect('dashboard.php');
}

	$error = null;
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	    require_csrf_post('register.php');
	    $invite_code = trim($_POST['invite_code'] ?? '');
	    $business_name = trim($_POST['business_name'] ?? '');
	    $business_email = trim($_POST['business_email'] ?? '');
	    $business_type = $_POST['business_type'] ?? 'hotel';
	    $city = trim($_POST['city'] ?? 'Kigali');
	    $user_name = trim($_POST['user_name'] ?? '');
    $user_email = trim($_POST['user_email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

	    if (!registration_is_allowed($invite_code)) {
	        $error = 'Registration is invite-only. Enter a valid pilot invite code.';
	    } elseif ($business_name === '' || $business_email === '' || $user_name === '' || $user_email === '' || $password === '') {
	        $error = 'All fields are required.';
	    } elseif (!filter_var($business_email, FILTER_VALIDATE_EMAIL) || !filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
	        $error = 'Please enter valid email addresses.';
	    } elseif (!array_key_exists($business_type, business_type_options())) {
	        $error = 'Choose a valid business type.';
	    } elseif ($city === '') {
	        $error = 'Choose a launch city.';
	    } elseif ($password !== $password_confirm) {
        $error = 'Passwords do not match.';
    } else {
        $pdo = db_connect();
        try {
            $pdo->beginTransaction();
	            $stmt = $pdo->prepare('INSERT INTO businesses (name, email, business_type, city, approval_status) VALUES (?, ?, ?, ?, ?)');
	            $stmt->execute([$business_name, $business_email, $business_type, $city, 'pending']);
            $business_id = $pdo->lastInsertId();
            $stmt = $pdo->prepare('INSERT INTO users (business_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$business_id, $user_name, $user_email, password_hash($password, PASSWORD_DEFAULT), 'manager']);
            $user_id = $pdo->lastInsertId();
            $stmt = $pdo->prepare('INSERT INTO staff (business_id, user_id, name, role) VALUES (?, ?, ?, ?)');
            $stmt->execute([$business_id, $user_id, $user_name, 'manager']);
            seed_default_staff_roles($pdo, (int)$business_id);
            $pdo->commit();
            flash_set('Registration complete. Your account is pending approval. You will be notified once approved.');
            gb_redirect('login.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Unable to create account. Try another email.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - <?php echo htmlspecialchars(APP_NAME); ?></title>
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

        .auth-wrapper{
            min-height:100vh;
            display:flex;
        }

        .auth-left{
            width:52%;
            background:#ffffff;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:70px;
            position:relative;
            border-right:1px solid var(--border);
        }

        .auth-content{
            width:100%;
            max-width:560px;
        }

        .auth-right{
            width:48%;
            position:relative;
            overflow:hidden;
        }

        /* BRAND */

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

        /* HERO TEXT */

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
            font-size:72px;
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
            max-width:500px;
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

        .section-title{
            color:var(--primary);
            font-size:12px;
            font-weight:800;
            letter-spacing:.22em;
            text-transform:uppercase;
            margin-bottom:22px;
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

        .form-control,
        .form-select{
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

        .form-control::placeholder{
            color:#94a3b8;
        }

        .form-control:focus,
        .form-select:focus{
            outline:none;
            border-color:rgba(37,99,235,.45);
            box-shadow:
                0 0 0 4px rgba(37,99,235,.10),
                0 10px 30px rgba(37,99,235,.08);
        }

        .form-select option{
            color:#0f172a;
        }

        .double-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:16px;
        }

        .divider{
            height:1px;
            background:linear-gradient(
                to right,
                transparent,
                rgba(37,99,235,.18),
                transparent
            );
            margin:30px 0;
        }

        /* BUTTON */

        .register-btn{
            width:100%;
            height:62px;
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
            margin-top:12px;
            transition:.3s ease;
            box-shadow:
                0 12px 30px rgba(37,99,235,.20);
        }

        .register-btn:hover{
            transform:translateY(-3px);
            box-shadow:
                0 20px 50px rgba(37,99,235,.25);
        }

        /* BOTTOM TEXT */

        .bottom-text{
            margin-top:26px;
            text-align:center;
            color:var(--muted);
            font-size:14px;
        }

        .bottom-text a{
            color:var(--primary);
            text-decoration:none;
            font-weight:700;
        }

        .bottom-text a:hover{
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

        .alert-danger{
            background:#fef2f2;
            color:#991b1b;
        }

        /* RIGHT SIDE IMAGE */

        .auth-right img{
            width:100%;
            height:100%;
            object-fit:cover;
            transform:scale(1.03);
            filter:brightness(.78);
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

        .hero-panel{
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
            font-size:72px;
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

            .auth-wrapper{
                flex-direction:column;
            }

            .auth-left,
            .auth-right{
                width:100%;
            }

            .auth-right{
                min-height:420px;
                order:-1;
            }

            .auth-left{
                padding:40px 24px;
            }

            .headline{
                font-size:48px;
            }

            .hero-title{
                font-size:48px;
            }

            .hero-panel{
                left:30px;
                right:30px;
                bottom:40px;
            }
        }

        @media(max-width:640px){

            .double-grid{
                grid-template-columns:1fr;
            }

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

<div class="auth-wrapper">

    <!-- LEFT -->
    <div class="auth-left">

        <div class="auth-content">

            <div class="brand">
                <img src="/guestbridgerwanda/assets/guestbridge-mark.svg" alt="GuestBridge">
                <span><?php echo htmlspecialchars(APP_NAME); ?></span>
            </div>

            <div class="floating-badge">
                Hospitality Enterprise Platform
            </div>

            <h1 class="headline">
                Register your <span class="blue">business</span>.
            </h1>

            <p class="subtext">
                GuestBridge powers modern hospitality brands across Rwanda.
                Referral systems, commission automation, partner ecosystems,
                and operational control — designed for businesses ready to grow.
            </p>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="form-card">

                <form method="post" novalidate>

                    <?php echo csrf_field(); ?>

                    <div class="section-title">
                        Business Information
                    </div>

                    <?php if (INVITE_ONLY_REGISTRATION): ?>
                    <div class="form-group">
                        <label class="form-label">Pilot invite code</label>

                        <input
                            type="text"
                            name="invite_code"
                            class="form-control"
                            value="<?php echo htmlspecialchars($_POST['invite_code'] ?? ''); ?>"
                            required
                        >
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label">Business name</label>

                        <input
                            type="text"
                            name="business_name"
                            class="form-control"
                            placeholder="Kigali Prestige Hotel"
                            value="<?php echo htmlspecialchars($_POST['business_name'] ?? ''); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">Business email</label>

                        <input
                            type="email"
                            name="business_email"
                            class="form-control"
                            placeholder="business@company.com"
                            value="<?php echo htmlspecialchars($_POST['business_email'] ?? ''); ?>"
                            required
                        >
                    </div>

                    <div class="double-grid">

                        <div class="form-group">
                            <label class="form-label">Business type</label>

                            <select name="business_type" class="form-select" required>

                                <?php foreach (business_type_options() as $value => $label): ?>
                                    <option
                                        value="<?php echo htmlspecialchars($value); ?>"
                                        <?php echo ($_POST['business_type'] ?? 'hotel') === $value ? 'selected' : ''; ?>
                                    >
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Launch city</label>

                            <select name="city" class="form-select" required>

                                <?php foreach (city_options() as $value => $label): ?>
                                    <option
                                        value="<?php echo htmlspecialchars($value); ?>"
                                        <?php echo ($_POST['city'] ?? 'Kigali') === $value ? 'selected' : ''; ?>
                                    >
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                    </div>

                    <div class="divider"></div>

                    <div class="section-title">
                        Manager Account
                    </div>

                    <div class="form-group">
                        <label class="form-label">Your name</label>

                        <input
                            type="text"
                            name="user_name"
                            class="form-control"
                            placeholder="John Doe"
                            value="<?php echo htmlspecialchars($_POST['user_name'] ?? ''); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">Your email</label>

                        <input
                            type="email"
                            name="user_email"
                            class="form-control"
                            placeholder="manager@company.com"
                            value="<?php echo htmlspecialchars($_POST['user_email'] ?? ''); ?>"
                            required
                        >
                    </div>

                    <div class="double-grid">

                        <div class="form-group">
                            <label class="form-label">Password</label>

                            <input
                                type="password"
                                name="password"
                                class="form-control"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label">Confirm password</label>

                            <input
                                type="password"
                                name="password_confirm"
                                class="form-control"
                                required
                            >
                        </div>

                    </div>

                    <button class="register-btn">
                        Create Account
                    </button>

                </form>

                <div class="bottom-text">
                    Already have an account?
                    <a href="/guestbridgerwanda/login.php">Login</a>
                </div>

            </div>

        </div>

    </div>

    <!-- RIGHT -->
    <div class="auth-right">

        <img
            src="https://images.unsplash.com/photo-1551882547-ff40c63fe5fa?w=2000&q=80&auto=format&fit=crop"
            alt="GuestBridge Rwanda"
        >

        <div class="overlay"></div>

        <div class="hero-panel">

            <div class="hero-mini">
                GuestBridge Enterprise
            </div>

            <h2 class="hero-title">
                Hospitality,<br>
                elevated.
            </h2>

            <p class="hero-text">
                Join Rwanda's hospitality ecosystem. Built for hotels,
                stays, tour operators, and businesses ready to
                grow with a modern operational platform.
            </p>

        </div>

    </div>

</div>

</body>
</html>
