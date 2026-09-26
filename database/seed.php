<?php
require_once __DIR__ . '/../app/Config/database.php';

function seed_business(PDO $pdo, string $name, string $email, string $phone, string $address, string $business_type = 'hotel', string $city = 'Kigali'): int
{
    $stmt = $pdo->prepare('SELECT id FROM businesses WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if ($row) {
        $stmt = $pdo->prepare('UPDATE businesses SET business_type = ?, city = ? WHERE id = ?');
        $stmt->execute([$business_type, $city, $row['id']]);
        return (int)$row['id'];
    }

    $stmt = $pdo->prepare('INSERT INTO businesses (name, email, phone, address, business_type, city) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $email, $phone, $address, $business_type, $city]);
    return (int)$pdo->lastInsertId();
}

function seed_user(PDO $pdo, int $business_id, string $name, string $email, string $password, string $role): int
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if ($row) {
        return (int)$row['id'];
    }

    $stmt = $pdo->prepare('INSERT INTO users (business_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$business_id, $name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
    return (int)$pdo->lastInsertId();
}

function seed_staff(PDO $pdo, int $business_id, int $user_id, string $name, string $role, string $phone): int
{
    $stmt = $pdo->prepare('SELECT id FROM staff WHERE business_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$business_id, $user_id]);
    $row = $stmt->fetch();
    if ($row) {
        return (int)$row['id'];
    }

    $stmt = $pdo->prepare('INSERT INTO staff (business_id, user_id, name, role, phone) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$business_id, $user_id, $name, $role, $phone]);
    return (int)$pdo->lastInsertId();
}

function seed_partnership(PDO $pdo, int $business_id, int $partner_business_id, string $status, float $commission_rate, string $agreement_text = ''): int
{
    $stmt = $pdo->prepare('SELECT id FROM partnerships WHERE business_id = ? AND partner_business_id = ? LIMIT 1');
    $stmt->execute([$business_id, $partner_business_id]);
    $row = $stmt->fetch();
    if ($row) {
        $stmt = $pdo->prepare('UPDATE partnerships SET status = ?, commission_rate = ?, agreement_text = ? WHERE id = ?');
        $stmt->execute([$status, $commission_rate, $agreement_text, $row['id']]);
        return (int)$row['id'];
    }

    $stmt = $pdo->prepare('INSERT INTO partnerships (business_id, partner_business_id, status, commission_rate, agreement_text) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$business_id, $partner_business_id, $status, $commission_rate, $agreement_text]);
    return (int)$pdo->lastInsertId();
}

function seed_referral(PDO $pdo, string $code, int $source_business_id, int $target_business_id, int $staff_id, string $note, float $commission_percentage, float $estimated_value, string $status, ?string $used_at = null, ?string $created_at = null): int
{
    $stmt = $pdo->prepare('SELECT id FROM referrals WHERE referral_code = ? LIMIT 1');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if ($row) {
        return (int)$row['id'];
    }

    $created_at = $created_at ?: date('Y-m-d H:i:s');
    $stmt = $pdo->prepare('INSERT INTO referrals (referral_code, source_business_id, target_business_id, staff_id, note, commission_percentage, estimated_value, status, used_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$code, $source_business_id, $target_business_id, $staff_id, $note, $commission_percentage, $estimated_value, $status, $used_at, $created_at]);
    return (int)$pdo->lastInsertId();
}

function seed_commission(PDO $pdo, int $referral_id, int $source_business_id, int $target_business_id, float $commission_percentage, float $estimated_value, int $owed_to_business_id, string $status, string $month): int
{
    $stmt = $pdo->prepare('SELECT id FROM commissions WHERE referral_id = ? LIMIT 1');
    $stmt->execute([$referral_id]);
    $row = $stmt->fetch();
    if ($row) {
        return (int)$row['id'];
    }

    $amount = ($estimated_value * $commission_percentage) / 100;
    $stmt = $pdo->prepare('INSERT INTO commissions (referral_id, source_business_id, target_business_id, commission_percentage, estimated_value, amount, owed_to_business_id, status, month) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$referral_id, $source_business_id, $target_business_id, $commission_percentage, $estimated_value, $amount, $owed_to_business_id, $status, $month]);
    return (int)$pdo->lastInsertId();
}

try {
    $pdo = db_connect();
    db_init();

    echo "Seeding database...\n";

    $pilot = seed_business($pdo, 'Pilot Hotel Kigali', 'admin@pilot.kig', '+250788000000', 'Kigali, Rwanda', 'hotel');
    $mountain = seed_business($pdo, 'Mountain View Hotel', 'mountain@hotel.kig', '+250788000001', 'Kigali, Rwanda', 'hotel');
    $city = seed_business($pdo, 'City Centre Hotel', 'city@hotel.kig', '+250788000002', 'Kigali, Rwanda', 'hotel');
    $spa = seed_business($pdo, 'Nyarutarama Spa', 'spa@nyarutarama.kig', '+250788000003', 'Nyarutarama, Kigali', 'spa');
    $tours = seed_business($pdo, 'Kigali Tours', 'tours@kigali.kig', '+250788000004', 'Kigali, Rwanda', 'tourism');
    $airport = seed_business($pdo, 'Airport Guesthouse', 'airport@guesthouse.kig', '+250788000005', 'Kigali International Airport', 'hotel');
    $kampala = seed_business($pdo, 'Kampala Garden Hotel', 'manager@kampala.guestbridge', '+256700000001', 'Kololo, Kampala', 'hotel', 'Kampala');
    $nairobi = seed_business($pdo, 'Nairobi City Tours', 'manager@nairobi.guestbridge', '+254700000001', 'Westlands, Nairobi', 'tourism', 'Nairobi');
    $dar = seed_business($pdo, 'Dar Airport Transfers', 'manager@dar.guestbridge', '+255700000001', 'Julius Nyerere Airport, Dar es Salaam', 'transport', 'Dar es Salaam');

    $pilot_user = seed_user($pdo, $pilot, 'Admin User', 'admin@pilot.kig', 'password', 'manager');
    $mountain_user = seed_user($pdo, $mountain, 'Mountain Manager', 'manager@mountain.kig', 'password', 'manager');
    $city_user = seed_user($pdo, $city, 'City Manager', 'manager@city.kig', 'password', 'manager');
    $spa_user = seed_user($pdo, $spa, 'Spa Manager', 'manager@spa.kig', 'password', 'manager');
    $tours_user = seed_user($pdo, $tours, 'Tours Manager', 'manager@tours.kig', 'password', 'manager');
    $airport_user = seed_user($pdo, $airport, 'Airport Manager', 'manager@airport.kig', 'password', 'manager');
    $kampala_user = seed_user($pdo, $kampala, 'Kampala Manager', 'manager@kampala.guestbridge', 'password', 'manager');
    $nairobi_user = seed_user($pdo, $nairobi, 'Nairobi Manager', 'manager@nairobi.guestbridge', 'password', 'manager');
    $dar_user = seed_user($pdo, $dar, 'Dar Manager', 'manager@dar.guestbridge', 'password', 'manager');

    $pilot_staff = seed_staff($pdo, $pilot, $pilot_user, 'Admin User', 'manager', '+250788000000');
    $mountain_staff = seed_staff($pdo, $mountain, $mountain_user, 'Mountain Manager', 'manager', '+250788000001');
    $city_staff = seed_staff($pdo, $city, $city_user, 'City Manager', 'manager', '+250788000002');
    $spa_staff = seed_staff($pdo, $spa, $spa_user, 'Spa Manager', 'manager', '+250788000003');
    $tours_staff = seed_staff($pdo, $tours, $tours_user, 'Tours Manager', 'manager', '+250788000004');
    $airport_staff = seed_staff($pdo, $airport, $airport_user, 'Airport Manager', 'manager', '+250788000005');
    seed_staff($pdo, $kampala, $kampala_user, 'Kampala Manager', 'manager', '+256700000001');
    seed_staff($pdo, $nairobi, $nairobi_user, 'Nairobi Manager', 'manager', '+254700000001');
    seed_staff($pdo, $dar, $dar_user, 'Dar Manager', 'manager', '+255700000001');

    seed_partnership($pdo, $pilot, $mountain, 'active', 12.5, 'Referral agreement for hotel stays');
    seed_partnership($pdo, $pilot, $city, 'active', 10.0, 'City center hotel cross-referral');
    seed_partnership($pdo, $mountain, $pilot, 'active', 10.0, 'Mutual hotel partnership');
    seed_partnership($pdo, $city, $tours, 'active', 8.5, 'Tour referral partnership');
    seed_partnership($pdo, $pilot, $spa, 'pending', 15.0, 'Spa referral request pending approval');

    $referral1 = seed_referral($pdo, 'ref123pilot', $pilot, $mountain, $pilot_staff, 'Guest arriving tomorrow needs a twin room.', 12.5, 120000, 'used', date('Y-m-d H:i:s', strtotime('-2 days')));
    seed_commission($pdo, $referral1, $pilot, $mountain, 12.5, 120000, $pilot, 'confirmed', date('Y-m'));

    $referral2 = seed_referral($pdo, 'ref456city', $city, $tours, $city_staff, 'Evening Kigali city tour, 5 guests.', 8.5, 80000, 'created', null);
    seed_commission($pdo, $referral2, $city, $tours, 8.5, 80000, $city, 'pending', date('Y-m'));

    $referral3 = seed_referral($pdo, 'ref789pilot', $pilot, $spa, $pilot_staff, 'Guest wants a spa treatment after check-in.', 15.0, 60000, 'created', null);
    seed_commission($pdo, $referral3, $pilot, $spa, 15.0, 60000, $pilot, 'pending', date('Y-m'));

    $history_rows = [
        ['refhistpilot01', '-12 months', 12.5, 180000, 'used'],
        ['refhistpilot02', '-12 months +4 days', 12.5, 150000, 'used'],
        ['refhistpilot03', '-12 months +9 days', 12.5, 110000, 'used'],
        ['refhistpilot04', '-9 months', 12.0, 90000, 'used'],
        ['refhistpilot05', '-8 months', 12.0, 70000, 'created'],
        ['refhistpilot06', '-6 months', 11.5, 100000, 'used'],
        ['refhistpilot07', '-4 months', 11.5, 80000, 'created'],
        ['refhistpilot08', '-2 months', 12.0, 95000, 'used'],
    ];

    foreach ($history_rows as $row) {
        [$code, $relative_date, $rate, $value, $status] = $row;
        $created_at = date('Y-m-d H:i:s', strtotime($relative_date));
        $used_at = $status === 'used' ? date('Y-m-d H:i:s', strtotime($relative_date . ' +1 day')) : null;
        $history_referral = seed_referral($pdo, $code, $pilot, $mountain, $pilot_staff, 'Historical pilot referral used for seasonality testing.', $rate, $value, $status, $used_at, $created_at);
        seed_commission($pdo, $history_referral, $pilot, $mountain, $rate, $value, $pilot, $status === 'used' ? 'confirmed' : 'pending', date('Y-m', strtotime($created_at)));
    }

    recalculate_business_reputation($pdo);

    echo "Seed complete.\n";
} catch (Exception $e) {
    echo 'Seed failed: ' . $e->getMessage() . "\n";
    exit(1);
}
