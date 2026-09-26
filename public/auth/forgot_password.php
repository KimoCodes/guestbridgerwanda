<?php
require_once __DIR__ . '/../../app/Config/database.php';
db_init();

if (current_user()) {
    gb_redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('forgot_password.php');
    $email = trim($_POST['email'] ?? '');

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $pdo = db_connect();
        $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && !recent_password_reset_request_exists($pdo, (int) $user['id'])) {
            $token = create_password_reset_request($pdo, (int) $user['id']);
            $reset_url = password_reset_url($token);
            send_password_reset_email($user['email'], $user['name'], $reset_url);
        }
    }

    flash_set('If an account exists with that email, a password reset link has been sent.');
    gb_redirect('login.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - GuestBridge Rwanda</title>
    <link rel="icon" href="<?php echo htmlspecialchars(gb_url('assets/guestbridge-mark.svg')); ?>">

    <?php gb_render_stylesheets(); ?>

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

        *{ margin:0; padding:0; box-sizing:border-box; }

        body{
            font-family:'Inter',sans-serif;
            background:#ffffff;
            color:var(--text);
            min-height:100vh;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:40px 20px;
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

        .wrap{ width:100%; max-width:460px; }

        .brand{
            display:flex;
            align-items:center;
            gap:14px;
            margin-bottom:32px;
            justify-content:center;
        }

        .brand img{ width:42px; height:42px; object-fit:contain; }

        .brand span{
            font-size:14px;
            font-weight:800;
            letter-spacing:.24em;
            text-transform:uppercase;
            color:var(--primary);
        }

        .headline{
            font-family:'Cormorant Garamond',serif;
            font-size:38px;
            font-weight:700;
            text-align:center;
            margin-bottom:10px;
            color:#0f172a;
        }

        .subtext{
            color:var(--muted);
            font-size:14px;
            line-height:1.7;
            text-align:center;
            margin-bottom:28px;
        }

        .form-card{
            background:#ffffff;
            border:1px solid var(--border);
            border-radius:30px;
            padding:36px;
            box-shadow:0 10px 40px rgba(37,99,235,.08);
        }

        .form-group{ margin-bottom:18px; }

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
            box-shadow:0 0 0 4px rgba(37,99,235,.10), 0 10px 30px rgba(37,99,235,.08);
        }

        .form-control::placeholder{ color:#94a3b8; }

        .login-btn{
            width:100%;
            height:60px;
            border:none;
            border-radius:18px;
            background:linear-gradient(135deg, #2563eb, #3b82f6);
            color:#fff;
            font-size:14px;
            font-weight:800;
            letter-spacing:.12em;
            text-transform:uppercase;
            cursor:pointer;
            transition:.3s ease;
            margin-top:10px;
            box-shadow:0 12px 30px rgba(37,99,235,.20);
        }

        .login-btn:hover{
            transform:translateY(-3px);
            box-shadow:0 20px 50px rgba(37,99,235,.25);
        }

        .divider{
            height:1px;
            background:linear-gradient(to right, transparent, rgba(37,99,235,.18), transparent);
            margin:28px 0;
        }

        .bottom-links{ text-align:center; }

        .bottom-links a{
            color:var(--primary);
            text-decoration:none;
            font-weight:700;
            font-size:14px;
        }

        .bottom-links a:hover{ text-decoration:underline; }

        .alert{
            border:none;
            border-radius:18px;
            padding:16px 18px;
            margin-bottom:22px;
            font-size:14px;
        }

        .alert-danger{ background:#fef2f2; color:#991b1b; }
    </style>
</head>
<body>

<div class="wrap">

    <div class="brand">
        <img src="<?php echo htmlspecialchars(gb_url('assets/guestbridge-mark.svg')); ?>" alt="GuestBridge">
        <span>GuestBridge Rwanda</span>
    </div>

    <h1 class="headline">Forgot your password?</h1>
    <p class="subtext">Enter the email linked to your account and we'll send you a link to reset your password.</p>

    <div class="form-card">

        <form method="post" novalidate>

            <?php echo csrf_field(); ?>

            <div class="form-group">
                <label class="form-label">Email address</label>
                <input
                    type="email"
                    name="email"
                    class="form-control"
                    placeholder="Enter your account email"
                    required
                >
            </div>

            <button class="login-btn">Send reset link</button>

        </form>

        <div class="divider"></div>

        <div class="bottom-links">
            <a href="<?php echo htmlspecialchars(gb_url('login.php')); ?>">Back to login</a>
        </div>

    </div>

</div>

</body>
</html>
