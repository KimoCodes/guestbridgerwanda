<?php
/**
 * Seed partner_places, place_gallery, and sample data.
 * Run: php database/seed_places.php
 */
require_once __DIR__ . '/../app/Config/database.php';
require_once __DIR__ . '/../app/Domain/places.php';

function seed_place(PDO $pdo, array $data, array $gallery, ?int $business_id = null): int
{
    $slug = $data['slug'] ?? places_slugify($data['name']);
    $stmt = $pdo->prepare('SELECT id FROM partner_places WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $existing = $stmt->fetch();

    if ($existing) {
        $id = (int) $existing['id'];
        $pdo->prepare('UPDATE partner_places SET name = ?, location = ?, description = ?, featured_image = ?, video_url = ?, status = ?, sort_order = ?, business_id = ? WHERE id = ?')
            ->execute([
                $data['name'],
                $data['location'],
                $data['description'],
                $data['featured_image'],
                $data['video_url'] ?? null,
                $data['status'] ?? 'active',
                $data['sort_order'] ?? 0,
                $business_id,
                $id,
            ]);
        $pdo->prepare('DELETE FROM place_gallery WHERE place_id = ?')->execute([$id]);
    } else {
        $pdo->prepare('INSERT INTO partner_places (slug, name, location, description, featured_image, video_url, status, sort_order, business_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $slug,
                $data['name'],
                $data['location'],
                $data['description'],
                $data['featured_image'],
                $data['video_url'] ?? null,
                $data['status'] ?? 'active',
                $data['sort_order'] ?? 0,
                $business_id,
            ]);
        $id = (int) $pdo->lastInsertId();
    }

    $sort = 0;
    foreach ($gallery as $g) {
        $pdo->prepare('INSERT INTO place_gallery (place_id, image_url, caption, sort_order) VALUES (?, ?, ?, ?)')
            ->execute([$id, $g['url'], $g['caption'] ?? null, $sort++]);
    }

    return $id;
}

try {
    $pdo = db_connect();
    db_init();

    echo "Seeding partner places...\n";

    $biz = [];
    foreach (['admin@pilot.kig', 'mountain@hotel.kig', 'spa@nyarutarama.kig', 'tours@kigali.kig', 'city@hotel.kig', 'airport@guesthouse.kig'] as $email) {
        $stmt = $pdo->prepare('SELECT b.id FROM businesses b JOIN users u ON u.business_id = b.id WHERE u.email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        $biz[$email] = $row ? (int) $row['id'] : null;
    }

    $img = function (string $id) {
        return 'https://images.unsplash.com/' . $id . '?w=800&h=600&fit=crop';
    };

    $places = [
        [
            'slug' => 'pilot-hotel-kigali',
            'name' => 'Pilot Hotel Kigali',
            'location' => 'Kigali, Rwanda',
            'description' => 'Boutique city hotel in the heart of Kigali. Ideal for business travellers and guests exploring Rwanda’s capital. Partner referrals include spa, tours, and dining experiences.',
            'featured_image' => $img('photo-1566073771259-6a8506099f29'),
            'video_url' => 'https://www.youtube.com/watch?v=9V6tWC4Ys8w',
            'sort_order' => 1,
            'business_id' => $biz['admin@pilot.kig'] ?? null,
            'gallery' => [
                ['url' => $img('photo-1582719478250-c89cae4dc85b'), 'caption' => 'Lobby'],
                ['url' => $img('photo-1618773928121-c32242e63f39'), 'caption' => 'Guest room'],
                ['url' => $img('photo-1520250497591-112deb8ce329'), 'caption' => 'Pool area'],
            ],
        ],
        [
            'slug' => 'mountain-view-hotel',
            'name' => 'Mountain View Hotel',
            'location' => 'Kigali, Rwanda',
            'description' => 'Premium hospitality with panoramic views. Luxury accommodations and refined service for international guests.',
            'featured_image' => $img('photo-1551882547-ff40c63fe5fa'),
            'video_url' => null,
            'sort_order' => 2,
            'business_id' => $biz['mountain@hotel.kig'] ?? null,
            'gallery' => [
                ['url' => $img('photo-1578683010236-d716f9a3fdf5'), 'caption' => 'Suite'],
                ['url' => $img('photo-1590490360182-c33d57733427'), 'caption' => 'Restaurant'],
            ],
        ],
        [
            'slug' => 'nyarutarama-spa',
            'name' => 'Nyarutarama Spa',
            'location' => 'Nyarutarama, Kigali',
            'description' => 'Wellness and spa treatments for hotel guests. Ultimate relaxation after long travel days.',
            'featured_image' => $img('photo-1540555700478-4be289fbecef'),
            'video_url' => 'https://youtu.be/ScMzIvxBSi4',
            'sort_order' => 3,
            'business_id' => $biz['spa@nyarutarama.kig'] ?? null,
            'gallery' => [
                ['url' => $img('photo-1544161515-4ab6ce6db874'), 'caption' => 'Treatment room'],
                ['url' => $img('photo-1515377905706-aafcb7ff29f5'), 'caption' => 'Relaxation lounge'],
            ],
        ],
        [
            'slug' => 'kigali-tours',
            'name' => 'Kigali Tours',
            'location' => 'Kigali, Rwanda',
            'description' => 'Guided city tours, cultural experiences, and gorilla trekking connections. Global hospitality excellence for adventurous guests.',
            'featured_image' => $img('photo-1488646953014-85cb44e25828'),
            'video_url' => null,
            'sort_order' => 4,
            'business_id' => $biz['tours@kigali.kig'] ?? null,
            'gallery' => [
                ['url' => $img('photo-1469854523086-cc02eed5cfe0'), 'caption' => 'City tour'],
                ['url' => $img('photo-1506905925346-21bda4d32df4'), 'caption' => 'Countryside'],
            ],
        ],
        [
            'slug' => 'city-centre-hotel',
            'name' => 'City Centre Hotel',
            'location' => 'Downtown Kigali',
            'description' => 'Refined comfort and style in the central business district. Perfect base for meetings and urban exploration.',
            'featured_image' => $img('photo-1522798510911-7631e303dca5'),
            'video_url' => null,
            'sort_order' => 5,
            'business_id' => $biz['city@hotel.kig'] ?? null,
            'gallery' => [
                ['url' => $img('photo-1631049301184-908c35fbe9a3'), 'caption' => 'Exterior'],
            ],
        ],
        [
            'slug' => 'airport-guesthouse',
            'name' => 'Airport Guesthouse',
            'location' => 'Kigali International Airport',
            'description' => 'Timeless convenience for early flights and layovers. Elegant rooms minutes from the terminal.',
            'featured_image' => $img('photo-1596394516083-006b629f4169'),
            'video_url' => null,
            'sort_order' => 6,
            'business_id' => $biz['airport@guesthouse.kig'] ?? null,
            'gallery' => [
                ['url' => $img('photo-1564501049412-61c2a3083791'), 'caption' => 'Room'],
                ['url' => $img('photo-1571896349842-33c89424de2d'), 'caption' => 'Breakfast'],
            ],
        ],
    ];

    foreach ($places as $p) {
        $gallery = $p['gallery'];
        unset($p['gallery']);
        $bid = $p['business_id'] ?? null;
        unset($p['business_id']);
        seed_place($pdo, $p, $gallery, $bid);
        echo "  · {$p['name']}\n";
    }

    echo "Partner places seed complete.\n";
} catch (Exception $e) {
    echo 'Seed failed: ' . $e->getMessage() . "\n";
    exit(1);
}
