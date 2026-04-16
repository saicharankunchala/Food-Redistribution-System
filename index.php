<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Asia/Kolkata');

const STORAGE_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
const USERS_FILE = STORAGE_DIR . DIRECTORY_SEPARATOR . 'users.json';
const FOODS_FILE = STORAGE_DIR . DIRECTORY_SEPARATOR . 'foods.json';
const NOTIFICATIONS_FILE = STORAGE_DIR . DIRECTORY_SEPARATOR . 'notifications.json';

bootstrap_storage();
expire_food_listings();
seed_demo_data_if_requested();

$action = $_POST['action'] ?? $_GET['action'] ?? 'home';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handle_post_action($action);
    }

    if ($action === 'notifications_json') {
        output_notifications_json();
    }
} catch (Throwable $exception) {
    set_flash('error', $exception->getMessage());
    header('Location: index.php');
    exit;
}

$currentUser = current_user();
$flash = get_flash();
$foods = read_json(FOODS_FILE);
$notifications = $currentUser ? get_user_notifications((string) $currentUser['id']) : [];
$receiverResults = [];
$receiverPreferences = [
    'radius_km' => $currentUser['preferences']['radius_km'] ?? 10,
    'latitude' => $currentUser['latitude'] ?? '',
    'longitude' => $currentUser['longitude'] ?? '',
];

if ($currentUser && $currentUser['role'] === 'receiver') {
    $receiverResults = get_receiver_matches($currentUser, $foods);
}

function bootstrap_storage(): void
{
    if (!is_dir(STORAGE_DIR)) {
        mkdir(STORAGE_DIR, 0777, true);
    }

    ensure_json_file(USERS_FILE, []);
    ensure_json_file(FOODS_FILE, []);
    ensure_json_file(NOTIFICATIONS_FILE, []);
}

function ensure_json_file(string $file, array $default): void
{
    if (!file_exists($file)) {
        file_put_contents($file, json_encode($default, JSON_PRETTY_PRINT));
    }
}

function read_json(string $file): array
{
    $raw = file_get_contents($file);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

function write_json(string $file, array $data): void
{
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function next_id(array $items): string
{
    return uniqid('', true);
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function handle_post_action(string $action): void
{
    switch ($action) {
        case 'register':
            register_user();
            break;
        case 'login':
            login_user();
            break;
        case 'logout':
            logout_user();
            break;
        case 'save_receiver_preferences':
            require_role('receiver');
            save_receiver_preferences();
            break;
        case 'create_listing':
            require_role('donor');
            create_listing();
            break;
        case 'request_food':
            require_role('receiver');
            request_food();
            break;
        case 'review_request':
            require_role('donor');
            review_request();
            break;
        case 'mark_completed':
            require_login();
            mark_completed();
            break;
        case 'mark_notifications_read':
            require_login();
            mark_notifications_read();
            break;
        default:
            set_flash('error', 'Unsupported action.');
            header('Location: index.php');
            exit;
    }
}

function register_user(): void
{
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $role = (string) ($_POST['role'] ?? '');
    $organization = trim((string) ($_POST['organization'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $latitude = normalize_float($_POST['latitude'] ?? null);
    $longitude = normalize_float($_POST['longitude'] ?? null);

    if ($name === '' || $email === '' || $password === '' || !in_array($role, ['donor', 'receiver'], true)) {
        throw new RuntimeException('Please complete the registration form.');
    }

    $users = read_json(USERS_FILE);
    foreach ($users as $user) {
        if (($user['email'] ?? '') === $email) {
            throw new RuntimeException('This email is already registered.');
        }
    }

    $user = [
        'id' => next_id($users),
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'organization' => $organization,
        'phone' => $phone,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'preferences' => [
            'radius_km' => $role === 'receiver' ? 10 : null,
        ],
        'created_at' => date(DATE_ATOM),
    ];

    $users[] = $user;
    write_json(USERS_FILE, $users);
    add_notification($user['id'], 'Welcome! Your account is ready for demo use.');

    $_SESSION['user_id'] = $user['id'];
    set_flash('success', 'Registration successful.');
    header('Location: index.php');
    exit;
}

function login_user(): void
{
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $users = read_json(USERS_FILE);

    foreach ($users as $user) {
        if (($user['email'] ?? '') === $email && password_verify($password, (string) $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            set_flash('success', 'Welcome back, ' . $user['name'] . '.');
            header('Location: index.php');
            exit;
        }
    }

    throw new RuntimeException('Invalid email or password.');
}

function logout_user(): void
{
    session_unset();
    session_destroy();
    session_start();
    set_flash('success', 'Logged out successfully.');
    header('Location: index.php');
    exit;
}

function current_user(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $users = read_json(USERS_FILE);
    foreach ($users as $user) {
        if (($user['id'] ?? '') === $_SESSION['user_id']) {
            return $user;
        }
    }

    unset($_SESSION['user_id']);
    return null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        throw new RuntimeException('Please log in first.');
    }

    return $user;
}

function require_role(string $role): array
{
    $user = require_login();
    if (($user['role'] ?? '') !== $role) {
        throw new RuntimeException('Access denied for this action.');
    }

    return $user;
}

function normalize_float($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    return round((float) $value, 6);
}

function save_receiver_preferences(): void
{
    $user = require_role('receiver');
    $users = read_json(USERS_FILE);
    $radius = max(1, min(100, (int) ($_POST['radius_km'] ?? 10)));
    $latitude = normalize_float($_POST['latitude'] ?? null);
    $longitude = normalize_float($_POST['longitude'] ?? null);

    foreach ($users as &$savedUser) {
        if (($savedUser['id'] ?? '') === $user['id']) {
            $savedUser['preferences']['radius_km'] = $radius;
            $savedUser['latitude'] = $latitude;
            $savedUser['longitude'] = $longitude;
            break;
        }
    }
    unset($savedUser);

    write_json(USERS_FILE, $users);
    set_flash('success', 'Location preferences saved.');
    header('Location: index.php');
    exit;
}

function create_listing(): void
{
    $user = require_role('donor');
    $foods = read_json(FOODS_FILE);
    $title = trim((string) ($_POST['title'] ?? ''));
    $foodType = trim((string) ($_POST['food_type'] ?? ''));
    $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
    $unit = trim((string) ($_POST['unit'] ?? 'meal boxes'));
    $latitude = normalize_float($_POST['latitude'] ?? null);
    $longitude = normalize_float($_POST['longitude'] ?? null);
    $address = trim((string) ($_POST['address'] ?? ''));
    $expiry = (string) ($_POST['expiry_time'] ?? '');
    $description = trim((string) ($_POST['description'] ?? ''));

    if ($title === '' || $foodType === '' || $latitude === null || $longitude === null || $expiry === '') {
        throw new RuntimeException('Please fill all food listing details.');
    }

    $expiryTimestamp = strtotime($expiry);
    if ($expiryTimestamp === false || $expiryTimestamp <= time()) {
        throw new RuntimeException('Expiry time must be in the future.');
    }

    $listing = [
        'id' => next_id($foods),
        'donor_id' => $user['id'],
        'donor_name' => $user['name'],
        'title' => $title,
        'food_type' => $foodType,
        'quantity' => $quantity,
        'unit' => $unit,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'address' => $address,
        'expiry_time' => date(DATE_ATOM, $expiryTimestamp),
        'description' => $description,
        'status' => 'available',
        'created_at' => date(DATE_ATOM),
        'requests' => [],
    ];

    $foods[] = $listing;
    write_json(FOODS_FILE, $foods);
    notify_receivers_for_listing($listing);
    set_flash('success', 'Food listing posted successfully.');
    header('Location: index.php');
    exit;
}

function notify_receivers_for_listing(array $listing): void
{
    $users = read_json(USERS_FILE);
    foreach ($users as $user) {
        if (($user['role'] ?? '') !== 'receiver') {
            continue;
        }

        if (!isset($user['latitude'], $user['longitude']) || $user['latitude'] === null || $user['longitude'] === null) {
            continue;
        }

        $radius = (int) ($user['preferences']['radius_km'] ?? 10);
        $distance = haversine_km(
            (float) $listing['latitude'],
            (float) $listing['longitude'],
            (float) $user['latitude'],
            (float) $user['longitude']
        );

        if ($distance <= $radius) {
            add_notification(
                $user['id'],
                sprintf('New nearby food posted: %s (%.1f km away).', $listing['title'], $distance)
            );
        }
    }
}

function request_food(): void
{
    $user = require_role('receiver');
    $listingId = (string) ($_POST['listing_id'] ?? '');
    $requestedQuantity = max(1, (int) ($_POST['requested_quantity'] ?? 1));
    $note = trim((string) ($_POST['note'] ?? ''));
    $foods = read_json(FOODS_FILE);

    foreach ($foods as &$food) {
        if (($food['id'] ?? '') !== $listingId) {
            continue;
        }

        if (($food['status'] ?? '') !== 'available') {
            throw new RuntimeException('This listing is no longer available.');
        }

        if ($requestedQuantity > (int) $food['quantity']) {
            throw new RuntimeException('Requested quantity exceeds availability.');
        }

        foreach ($food['requests'] as $existingRequest) {
            if (($existingRequest['receiver_id'] ?? '') === $user['id']) {
                throw new RuntimeException('You already requested this listing.');
            }
        }

        $food['requests'][] = [
            'id' => next_id($food['requests']),
            'receiver_id' => $user['id'],
            'receiver_name' => $user['name'],
            'requested_quantity' => $requestedQuantity,
            'note' => $note,
            'status' => 'pending',
            'requested_at' => date(DATE_ATOM),
        ];
        $food['status'] = 'requested';

        add_notification($food['donor_id'], 'A receiver requested your listing: ' . $food['title'] . '.');
        add_notification($user['id'], 'Your request has been sent to the donor.');
        write_json(FOODS_FILE, $foods);
        set_flash('success', 'Request submitted to donor.');
        header('Location: index.php');
        exit;
    }
    unset($food);

    throw new RuntimeException('Listing not found.');
}

function review_request(): void
{
    $user = require_role('donor');
    $listingId = (string) ($_POST['listing_id'] ?? '');
    $requestId = (string) ($_POST['request_id'] ?? '');
    $decision = (string) ($_POST['decision'] ?? '');
    $foods = read_json(FOODS_FILE);

    foreach ($foods as &$food) {
        if (($food['id'] ?? '') !== $listingId || ($food['donor_id'] ?? '') !== $user['id']) {
            continue;
        }

        foreach ($food['requests'] as &$request) {
            if (($request['id'] ?? '') !== $requestId) {
                continue;
            }

            if (!in_array($decision, ['accepted', 'rejected'], true)) {
                throw new RuntimeException('Invalid decision.');
            }

            $request['status'] = $decision;
            $request['reviewed_at'] = date(DATE_ATOM);

            if ($decision === 'accepted') {
                foreach ($food['requests'] as &$otherRequest) {
                    if (($otherRequest['id'] ?? '') !== $requestId && ($otherRequest['status'] ?? '') === 'pending') {
                        $otherRequest['status'] = 'rejected';
                        $otherRequest['reviewed_at'] = date(DATE_ATOM);
                    }
                }
                unset($otherRequest);

                $food['status'] = 'accepted';
                add_notification($request['receiver_id'], 'Your request was accepted for: ' . $food['title'] . '.');
            } else {
                $food['status'] = has_pending_requests($food) ? 'requested' : 'available';
                add_notification($request['receiver_id'], 'Your request was rejected for: ' . $food['title'] . '.');
            }

            write_json(FOODS_FILE, $foods);
            set_flash('success', 'Request updated.');
            header('Location: index.php');
            exit;
        }
        unset($request);
    }
    unset($food);

    throw new RuntimeException('Request not found.');
}

function has_pending_requests(array $food): bool
{
    foreach ($food['requests'] as $request) {
        if (($request['status'] ?? '') === 'pending') {
            return true;
        }
    }

    return false;
}

function mark_completed(): void
{
    $user = require_login();
    $listingId = (string) ($_POST['listing_id'] ?? '');
    $foods = read_json(FOODS_FILE);

    foreach ($foods as &$food) {
        if (($food['id'] ?? '') !== $listingId) {
            continue;
        }

        if (($food['status'] ?? '') !== 'accepted') {
            throw new RuntimeException('Only accepted listings can be completed.');
        }

        if (($user['id'] ?? '') !== ($food['donor_id'] ?? '') && !user_has_accepted_request($food, $user['id'])) {
            throw new RuntimeException('You cannot complete this listing.');
        }

        $food['status'] = 'completed';
        $food['completed_at'] = date(DATE_ATOM);

        add_notification($food['donor_id'], 'Collection marked as completed for: ' . $food['title'] . '.');
        foreach ($food['requests'] as $request) {
            if (($request['status'] ?? '') === 'accepted') {
                add_notification($request['receiver_id'], 'Pickup completed for: ' . $food['title'] . '.');
            }
        }

        write_json(FOODS_FILE, $foods);
        set_flash('success', 'Listing marked as completed.');
        header('Location: index.php');
        exit;
    }
    unset($food);

    throw new RuntimeException('Listing not found.');
}

function user_has_accepted_request(array $food, string $userId): bool
{
    foreach ($food['requests'] as $request) {
        if (($request['receiver_id'] ?? '') === $userId && ($request['status'] ?? '') === 'accepted') {
            return true;
        }
    }

    return false;
}

function expire_food_listings(): void
{
    $foods = read_json(FOODS_FILE);
    $changed = false;

    foreach ($foods as &$food) {
        $expiry = strtotime((string) ($food['expiry_time'] ?? ''));
        if ($expiry !== false && $expiry <= time() && !in_array($food['status'], ['completed', 'expired'], true)) {
            $food['status'] = 'expired';
            $food['expired_at'] = date(DATE_ATOM);
            $changed = true;

            add_notification($food['donor_id'], 'Listing expired automatically: ' . $food['title'] . '.');
            foreach ($food['requests'] as $request) {
                add_notification($request['receiver_id'], 'Listing expired before pickup: ' . $food['title'] . '.');
            }
        }
    }
    unset($food);

    if ($changed) {
        write_json(FOODS_FILE, $foods);
    }
}

function add_notification(string $userId, string $message): void
{
    $notifications = read_json(NOTIFICATIONS_FILE);
    $notifications[] = [
        'id' => next_id($notifications),
        'user_id' => $userId,
        'message' => $message,
        'read' => false,
        'created_at' => date(DATE_ATOM),
    ];
    write_json(NOTIFICATIONS_FILE, $notifications);
}

function get_user_notifications(string $userId): array
{
    $notifications = read_json(NOTIFICATIONS_FILE);
    $filtered = array_values(array_filter($notifications, static fn(array $item): bool => ($item['user_id'] ?? '') === $userId));

    usort($filtered, static function (array $a, array $b): int {
        return strcmp((string) $b['created_at'], (string) $a['created_at']);
    });

    return $filtered;
}

function mark_notifications_read(): void
{
    $user = require_login();
    $notifications = read_json(NOTIFICATIONS_FILE);
    foreach ($notifications as &$notification) {
        if (($notification['user_id'] ?? '') === $user['id']) {
            $notification['read'] = true;
        }
    }
    unset($notification);

    write_json(NOTIFICATIONS_FILE, $notifications);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

function output_notifications_json(): void
{
    $user = require_login();
    $notifications = get_user_notifications($user['id']);
    $unread = count(array_filter($notifications, static fn(array $item): bool => !($item['read'] ?? false)));

    header('Content-Type: application/json');
    echo json_encode([
        'notifications' => array_slice($notifications, 0, 8),
        'unread' => $unread,
    ]);
    exit;
}

function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

    return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

function get_receiver_matches(array $receiver, array $foods): array
{
    $radius = (float) ($receiver['preferences']['radius_km'] ?? 10);
    if (!isset($receiver['latitude'], $receiver['longitude']) || $receiver['latitude'] === null || $receiver['longitude'] === null) {
        return [];
    }

    $results = [];
    foreach ($foods as $food) {
        if (!in_array($food['status'], ['available', 'requested', 'accepted'], true)) {
            continue;
        }

        $distance = haversine_km(
            (float) $receiver['latitude'],
            (float) $receiver['longitude'],
            (float) $food['latitude'],
            (float) $food['longitude']
        );

        if ($distance > $radius) {
            continue;
        }

        $hoursLeft = max(0.1, (strtotime((string) $food['expiry_time']) - time()) / 3600);
        $quantity = max(1, (int) $food['quantity']);

        $distanceScore = max(0, 100 - ($distance * 8));
        $timeScore = min(100, $hoursLeft * 12);
        $quantityScore = min(100, $quantity * 5);
        $score = round(($distanceScore * 0.45) + ($timeScore * 0.35) + ($quantityScore * 0.20), 2);

        $food['match_score'] = $score;
        $food['distance_km'] = round($distance, 2);
        $food['hours_left'] = round($hoursLeft, 2);
        $results[] = $food;
    }

    usort($results, static function (array $a, array $b): int {
        return $b['match_score'] <=> $a['match_score'];
    });

    return $results;
}

function seed_demo_data_if_requested(): void
{
    if (!isset($_GET['seed_demo'])) {
        return;
    }

    $users = read_json(USERS_FILE);
    if (count($users) > 0) {
        set_flash('success', 'Demo data already exists.');
        header('Location: index.php');
        exit;
    }

    $demoUsers = [
        [
            'id' => next_id([]),
            'name' => 'Metro Kitchen',
            'email' => 'donor@example.com',
            'password_hash' => password_hash('demo123', PASSWORD_DEFAULT),
            'role' => 'donor',
            'organization' => 'Metro Kitchen Events',
            'phone' => '9000000001',
            'latitude' => 17.385,
            'longitude' => 78.4867,
            'preferences' => ['radius_km' => null],
            'created_at' => date(DATE_ATOM),
        ],
        [
            'id' => next_id([]),
            'name' => 'Hope NGO',
            'email' => 'receiver@example.com',
            'password_hash' => password_hash('demo123', PASSWORD_DEFAULT),
            'role' => 'receiver',
            'organization' => 'Hope Foundation',
            'phone' => '9000000002',
            'latitude' => 17.4065,
            'longitude' => 78.4772,
            'preferences' => ['radius_km' => 15],
            'created_at' => date(DATE_ATOM),
        ],
    ];

    write_json(USERS_FILE, $demoUsers);

    $foods = [
        [
            'id' => next_id([]),
            'donor_id' => $demoUsers[0]['id'],
            'donor_name' => 'Metro Kitchen',
            'title' => 'Veg Biryani Dinner Packets',
            'food_type' => 'Cooked Meal',
            'quantity' => 20,
            'unit' => 'packs',
            'latitude' => 17.385,
            'longitude' => 78.4867,
            'address' => 'Nampally, Hyderabad',
            'expiry_time' => date(DATE_ATOM, strtotime('+6 hours')),
            'description' => 'Freshly packed vegetarian meal from an event surplus.',
            'status' => 'available',
            'created_at' => date(DATE_ATOM),
            'requests' => [],
        ],
    ];

    write_json(FOODS_FILE, $foods);
    write_json(NOTIFICATIONS_FILE, []);
    add_notification($demoUsers[0]['id'], 'Demo donor account loaded.');
    add_notification($demoUsers[1]['id'], 'Demo receiver account loaded.');

    set_flash('success', 'Demo data created. Use donor@example.com / demo123 and receiver@example.com / demo123.');
    header('Location: index.php');
    exit;
}

function badge_class(string $status): string
{
    return match ($status) {
        'available' => 'badge badge-green',
        'requested' => 'badge badge-yellow',
        'accepted' => 'badge badge-blue',
        'completed' => 'badge badge-dark',
        'expired' => 'badge badge-red',
        default => 'badge',
    };
}

function unread_count(array $notifications): int
{
    return count(array_filter($notifications, static fn(array $item): bool => !($item['read'] ?? false)));
}

function user_listing_requests(array $listing): array
{
    return $listing['requests'] ?? [];
}

function format_datetime(string $value): string
{
    return date('d M Y, h:i A', strtotime($value));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Geo-Fenced Food Redistribution System</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body data-authenticated="<?php echo $currentUser ? 'yes' : 'no'; ?>">
    <div class="page-shell">
        <header class="hero">
            <div class="hero-copy">
                <p class="eyebrow">Faculty Demo Ready</p>
                <h1>Geo-Fenced Real-Time Food Redistribution System</h1>
                <p class="lead">
                    A demonstration-focused web application that turns the PPT requirements into a working donor and receiver platform with intelligent matching, live-style notifications, and expiry-aware workflows.
                </p>
                <div class="hero-actions">
                    <a class="button primary" href="index.php?seed_demo=1">Load Demo Data</a>
                    <a class="button ghost" href="#modules">See Modules</a>
                </div>
            </div>
            <div class="hero-panel">
                <div class="stat-card">
                    <span>Modules Covered</span>
                    <strong>8</strong>
                </div>
                <div class="stat-card">
                    <span>Matching Inputs</span>
                    <strong>Distance + Time + Quantity</strong>
                </div>
                <div class="stat-card">
                    <span>Deployment Style</span>
                    <strong>PHP Demo App</strong>
                </div>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="flash <?php echo htmlspecialchars($flash['type']); ?>">
                <?php echo htmlspecialchars($flash['message']); ?>
            </div>
        <?php endif; ?>

        <?php if ($currentUser): ?>
            <section class="toolbar">
                <div>
                    <h2><?php echo htmlspecialchars($currentUser['name']); ?></h2>
                    <p><?php echo ucfirst(htmlspecialchars($currentUser['role'])); ?> dashboard</p>
                </div>
                <div class="toolbar-actions">
                    <button type="button" class="button ghost notification-button" id="notificationButton">
                        Notifications <span id="notificationCount"><?php echo unread_count($notifications); ?></span>
                    </button>
                    <form method="post">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="button secondary">Logout</button>
                    </form>
                </div>
            </section>

            <aside class="notifications-panel" id="notificationsPanel">
                <div class="notifications-header">
                    <h3>Recent Notifications</h3>
                    <button type="button" class="text-button" id="markNotificationsRead">Mark all as read</button>
                </div>
                <div id="notificationsList">
                    <?php if (!$notifications): ?>
                        <p class="muted">No notifications yet.</p>
                    <?php endif; ?>
                    <?php foreach (array_slice($notifications, 0, 8) as $notification): ?>
                        <div class="notification-item <?php echo !($notification['read'] ?? false) ? 'unread' : ''; ?>">
                            <p><?php echo htmlspecialchars($notification['message']); ?></p>
                            <small><?php echo htmlspecialchars(format_datetime((string) $notification['created_at'])); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </aside>

            <?php if ($currentUser['role'] === 'donor'): ?>
                <main class="dashboard-grid">
                    <section class="card">
                        <h3>Post Surplus Food</h3>
                        <form method="post" class="form-grid">
                            <input type="hidden" name="action" value="create_listing">
                            <label>
                                Food title
                                <input type="text" name="title" placeholder="Ex: Paneer meals from event" required>
                            </label>
                            <label>
                                Food type
                                <input type="text" name="food_type" placeholder="Cooked Meal / Bakery / Dry Food" required>
                            </label>
                            <label>
                                Quantity
                                <input type="number" name="quantity" min="1" value="10" required>
                            </label>
                            <label>
                                Unit
                                <input type="text" name="unit" value="meal boxes" required>
                            </label>
                            <label>
                                Latitude
                                <input type="number" step="0.000001" name="latitude" value="<?php echo htmlspecialchars((string) ($currentUser['latitude'] ?? '')); ?>" required>
                            </label>
                            <label>
                                Longitude
                                <input type="number" step="0.000001" name="longitude" value="<?php echo htmlspecialchars((string) ($currentUser['longitude'] ?? '')); ?>" required>
                            </label>
                            <label class="full">
                                Address / pickup location
                                <input type="text" name="address" placeholder="Pickup spot for receiver" required>
                            </label>
                            <label>
                                Expiry time
                                <input type="datetime-local" name="expiry_time" required>
                            </label>
                            <label class="full">
                                Description
                                <textarea name="description" rows="4" placeholder="Freshness note, packaging details, contact instructions"></textarea>
                            </label>
                            <button type="submit" class="button primary full">Publish Listing</button>
                        </form>
                    </section>

                    <section class="card">
                        <h3>Your Listings</h3>
                        <div class="stack">
                            <?php
                            $donorListings = array_values(array_filter($foods, static fn(array $food): bool => ($food['donor_id'] ?? '') === $currentUser['id']));
                            if (!$donorListings):
                            ?>
                                <p class="muted">No listings yet. Post one to start the workflow.</p>
                            <?php endif; ?>
                            <?php foreach ($donorListings as $listing): ?>
                                <article class="listing-card">
                                    <div class="listing-header">
                                        <div>
                                            <h4><?php echo htmlspecialchars($listing['title']); ?></h4>
                                            <p><?php echo htmlspecialchars($listing['quantity'] . ' ' . $listing['unit']); ?> - Expires <?php echo htmlspecialchars(format_datetime((string) $listing['expiry_time'])); ?></p>
                                        </div>
                                        <span class="<?php echo badge_class((string) $listing['status']); ?>"><?php echo htmlspecialchars(ucfirst((string) $listing['status'])); ?></span>
                                    </div>
                                    <p><?php echo htmlspecialchars($listing['description'] ?: 'No extra notes added.'); ?></p>

                                    <?php foreach (user_listing_requests($listing) as $request): ?>
                                        <div class="request-card">
                                            <div>
                                                <strong><?php echo htmlspecialchars($request['receiver_name']); ?></strong>
                                                <p><?php echo htmlspecialchars((string) $request['requested_quantity']); ?> requested - <?php echo htmlspecialchars($request['note'] ?: 'No receiver note'); ?></p>
                                                <small><?php echo htmlspecialchars(ucfirst((string) $request['status'])); ?></small>
                                            </div>
                                            <?php if (($request['status'] ?? '') === 'pending'): ?>
                                                <div class="inline-actions">
                                                    <form method="post">
                                                        <input type="hidden" name="action" value="review_request">
                                                        <input type="hidden" name="listing_id" value="<?php echo htmlspecialchars((string) $listing['id']); ?>">
                                                        <input type="hidden" name="request_id" value="<?php echo htmlspecialchars((string) $request['id']); ?>">
                                                        <input type="hidden" name="decision" value="accepted">
                                                        <button type="submit" class="button success">Accept</button>
                                                    </form>
                                                    <form method="post">
                                                        <input type="hidden" name="action" value="review_request">
                                                        <input type="hidden" name="listing_id" value="<?php echo htmlspecialchars((string) $listing['id']); ?>">
                                                        <input type="hidden" name="request_id" value="<?php echo htmlspecialchars((string) $request['id']); ?>">
                                                        <input type="hidden" name="decision" value="rejected">
                                                        <button type="submit" class="button danger">Reject</button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php if (($listing['status'] ?? '') === 'accepted'): ?>
                                        <form method="post" class="completion-form">
                                            <input type="hidden" name="action" value="mark_completed">
                                            <input type="hidden" name="listing_id" value="<?php echo htmlspecialchars((string) $listing['id']); ?>">
                                            <button type="submit" class="button secondary">Mark Pickup Completed</button>
                                        </form>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </main>
            <?php else: ?>
                <main class="dashboard-grid">
                    <section class="card">
                        <h3>Receiver Geo-Fence Settings</h3>
                        <form method="post" class="form-grid">
                            <input type="hidden" name="action" value="save_receiver_preferences">
                            <label>
                                Search radius (km)
                                <input type="number" name="radius_km" min="1" max="100" value="<?php echo htmlspecialchars((string) $receiverPreferences['radius_km']); ?>" required>
                            </label>
                            <label>
                                Latitude
                                <input type="number" id="receiverLatitude" step="0.000001" name="latitude" value="<?php echo htmlspecialchars((string) $receiverPreferences['latitude']); ?>" required>
                            </label>
                            <label>
                                Longitude
                                <input type="number" id="receiverLongitude" step="0.000001" name="longitude" value="<?php echo htmlspecialchars((string) $receiverPreferences['longitude']); ?>" required>
                            </label>
                            <div class="full inline-actions">
                                <button type="button" class="button ghost" id="detectLocationButton">Use Current Browser Location</button>
                                <button type="submit" class="button primary">Save Preferences</button>
                            </div>
                        </form>
                    </section>

                    <section class="card">
                        <h3>Nearby Food Matches</h3>
                        <div class="stack">
                            <?php if (!$receiverResults): ?>
                                <p class="muted">No nearby listings found. Save your location or widen the radius to see demo matches.</p>
                            <?php endif; ?>
                            <?php foreach ($receiverResults as $listing): ?>
                                <article class="listing-card">
                                    <div class="listing-header">
                                        <div>
                                            <h4><?php echo htmlspecialchars($listing['title']); ?></h4>
                                            <p><?php echo htmlspecialchars($listing['food_type']); ?> - <?php echo htmlspecialchars($listing['quantity'] . ' ' . $listing['unit']); ?></p>
                                        </div>
                                        <span class="<?php echo badge_class((string) $listing['status']); ?>"><?php echo htmlspecialchars(ucfirst((string) $listing['status'])); ?></span>
                                    </div>

                                    <div class="metrics">
                                        <div><strong><?php echo htmlspecialchars((string) $listing['match_score']); ?></strong><span>Match Score</span></div>
                                        <div><strong><?php echo htmlspecialchars((string) $listing['distance_km']); ?> km</strong><span>Distance</span></div>
                                        <div><strong><?php echo htmlspecialchars((string) $listing['hours_left']); ?> hr</strong><span>Time Left</span></div>
                                    </div>

                                    <p><?php echo htmlspecialchars($listing['description'] ?: 'No extra notes added.'); ?></p>
                                    <p class="muted"><?php echo htmlspecialchars((string) $listing['address']); ?></p>

                                    <?php if (($listing['status'] ?? '') === 'available'): ?>
                                        <form method="post" class="form-grid compact">
                                            <input type="hidden" name="action" value="request_food">
                                            <input type="hidden" name="listing_id" value="<?php echo htmlspecialchars((string) $listing['id']); ?>">
                                            <label>
                                                Request quantity
                                                <input type="number" name="requested_quantity" min="1" max="<?php echo htmlspecialchars((string) $listing['quantity']); ?>" value="5" required>
                                            </label>
                                            <label class="full">
                                                Note to donor
                                                <input type="text" name="note" placeholder="Pickup ETA, contact person, urgency">
                                            </label>
                                            <button type="submit" class="button primary full">Request Food</button>
                                        </form>
                                    <?php else: ?>
                                        <p class="muted">This listing is already in progress. You can still show the matching result to faculty as part of the module demo.</p>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </main>
            <?php endif; ?>
        <?php else: ?>
            <main class="public-grid">
                <section class="card">
                    <h3>Register</h3>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="action" value="register">
                        <label>
                            Full name
                            <input type="text" name="name" required>
                        </label>
                        <label>
                            Email
                            <input type="email" name="email" required>
                        </label>
                        <label>
                            Password
                            <input type="password" name="password" required>
                        </label>
                        <label>
                            Role
                            <select name="role" required>
                                <option value="donor">Donor</option>
                                <option value="receiver">Receiver</option>
                            </select>
                        </label>
                        <label>
                            Organization
                            <input type="text" name="organization">
                        </label>
                        <label>
                            Phone
                            <input type="text" name="phone">
                        </label>
                        <label>
                            Latitude
                            <input type="number" step="0.000001" name="latitude" placeholder="17.3850">
                        </label>
                        <label>
                            Longitude
                            <input type="number" step="0.000001" name="longitude" placeholder="78.4867">
                        </label>
                        <button type="submit" class="button primary full">Create Account</button>
                    </form>
                </section>

                <section class="card">
                    <h3>Login</h3>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="action" value="login">
                        <label>
                            Email
                            <input type="email" name="email" required>
                        </label>
                        <label>
                            Password
                            <input type="password" name="password" required>
                        </label>
                        <button type="submit" class="button secondary full">Login</button>
                    </form>
                    <div class="demo-box">
                        <h4>Quick faculty demo</h4>
                        <p>Click <strong>Load Demo Data</strong> above, then log in with:</p>
                        <p><code>donor@example.com / demo123</code></p>
                        <p><code>receiver@example.com / demo123</code></p>
                    </div>
                </section>
            </main>
        <?php endif; ?>

        <section class="module-section" id="modules">
            <div class="module-card">
                <h3>User Registration & Login</h3>
                <p>Separate donor and receiver roles with protected actions.</p>
            </div>
            <div class="module-card">
                <h3>Food Posting</h3>
                <p>Donors can publish type, quantity, location, expiry time, and pickup notes.</p>
            </div>
            <div class="module-card">
                <h3>Geo-Fencing</h3>
                <p>Receivers only see listings within their chosen radius and location.</p>
            </div>
            <div class="module-card">
                <h3>Intelligent Matching</h3>
                <p>Each listing is ranked using distance, time left, and quantity score.</p>
            </div>
            <div class="module-card">
                <h3>Real-Time Notifications</h3>
                <p>Notification panel refreshes automatically for new workflow events.</p>
            </div>
            <div class="module-card">
                <h3>Request & Approval Flow</h3>
                <p>Receivers request food, and donors approve or reject each request.</p>
            </div>
            <div class="module-card">
                <h3>Status Tracking</h3>
                <p>Listings move through Available, Requested, Accepted, Completed, and Expired.</p>
            </div>
            <div class="module-card">
                <h3>Auto Expiry Cleanup</h3>
                <p>Expired listings are updated automatically during each app interaction.</p>
            </div>
        </section>
    </div>

    <script src="assets/app.js"></script>
</body>
</html>
