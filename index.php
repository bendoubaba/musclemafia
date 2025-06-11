<?php
session_start(); // Start session to potentially handle post-login redirects
// Ensure database.php is in the same directory or adjust the path.
// It should establish a $conn variable for the database connection.
if (file_exists("database.php")) {
    include("database.php");
} else {
    // Fallback or error if database.php is not found
    // For demonstration, we'll create a dummy $conn if it doesn't exist
    // In a real application, you'd handle this more robustly (e.g., die with an error)
    if (!isset($conn) || !$conn) {
        // Create a dummy mysqli object to prevent errors if database.php is missing or fails
        // This is purely for allowing the page to render without a real DB for snippet testing
        $conn = new stdClass();
        $conn->error = "Database connection file not found or failed to connect.";
        // Mock query method for dummy $conn
        $conn->query = function ($sql) {
            // Simulate no results or specific results for counts if needed for demo
            if (strpos($sql, "COUNT(id) as count FROM users") !== false) {
                $mockResult = new stdClass();
                $mockResult->fetch_assoc = function () {
                    return ['count' => 0]; }; // Default to 0 users
                return $mockResult;
            }
            $mockResult = new stdClass();
            $mockResult->num_rows = 0;
            $mockResult->fetch_assoc = function () {
                return null; };
            return $mockResult;
        };
        // Mock close method
        $conn->close = function () { };
    }
}


// --- Data Fetching ---

// Total Users Count
$total_users = 0;
if (isset($conn) && is_object($conn) && method_exists($conn, 'query')) {
    $result_users_count = $conn->query("SELECT COUNT(id) as count FROM users");
    if ($result_users_count && method_exists($result_users_count, 'fetch_assoc') && $row_users_count = $result_users_count->fetch_assoc()) {
        $total_users = (int) $row_users_count['count'];
    }
}


// Fetch Categories
$categories_data = [];
if (isset($conn) && is_object($conn) && method_exists($conn, 'query')) {
    $cat_sql = "SELECT id, name, ico_img FROM categories ORDER BY name LIMIT 6"; // Limit for display
    $cat_result = $conn->query($cat_sql);
    if ($cat_result && $cat_result->num_rows > 0) {
        while ($row = $cat_result->fetch_assoc()) {
            $categories_data[] = $row;
        }
    }
}
// Dummy categories if DB fails or is not present
if (empty($categories_data)) {
    $categories_data = [
        ['id' => '1', 'name' => 'Living Room', 'ico_img' => 'https://placehold.co/50x50/00ffff/000000?text=LR'],
        ['id' => '2', 'name' => 'Bedroom', 'ico_img' => 'https://placehold.co/50x50/FF00FF/000000?text=BR'],
        ['id' => '3', 'name' => 'Kitchen', 'ico_img' => 'https://placehold.co/50x50/FFFF00/000000?text=KT'],
        ['id' => '4', 'name' => 'Office', 'ico_img' => 'https://placehold.co/50x50/00FF00/000000?text=OF'],
    ];
}


// Fetch Featured Products for Hero Carousel
$hero_products = [];
if (isset($conn) && is_object($conn) && method_exists($conn, 'query')) {
    $hero_sql = "SELECT id, product AS title, description, price, picture AS image_url, `count` as stock_count
                 FROM products
                 WHERE `count` > 0  -- Optionally show only in-stock items in hero
                 ORDER BY RAND() LIMIT 3"; // Fewer for a cleaner hero
    $hero_result = $conn->query($hero_sql);
    if ($hero_result && $hero_result->num_rows > 0) {
        while ($row = $hero_result->fetch_assoc()) {
            $hero_products[] = $row;
        }
    }
}
// Dummy hero products if DB fails
if (empty($hero_products)) {
    $hero_products = [
        ['id' => '101', 'title' => 'Futuristic Sofa', 'description' => 'A sleek sofa for modern living.', 'price' => '999.99', 'image_url' => 'https://placehold.co/600x400/1a1a1a/00ffff?text=Sofa+3D', 'stock_count' => 10],
        ['id' => '102', 'title' => 'Orbital Lamp', 'description' => 'Light up your world with this lamp.', 'price' => '149.50', 'image_url' => 'https://placehold.co/600x400/1a1a1a/FF00FF?text=Lamp+3D', 'stock_count' => 5],
    ];
}

// Fetch All Products
$all_products = [];
if (isset($conn) && is_object($conn) && method_exists($conn, 'query')) {
    $sql_all_products = "SELECT id, product AS title, description, price, picture AS image_url, category_id, `count` as stock_count
                         FROM products
                         ORDER BY RAND() LIMIT 8"; // Limit initial load for performance
    $result_all_products = $conn->query($sql_all_products);
    if ($result_all_products && $result_all_products->num_rows > 0) {
        while ($row = $result_all_products->fetch_assoc()) {
            $all_products[] = $row;
        }
    } else if (is_object($conn) && property_exists($conn, 'error') && $conn->error && strpos($conn->error, "Database connection file not found") === false) {
        error_log("Error fetching all products for homepage: " . $conn->error);
    }
}
// Dummy products if DB fails or returns empty
if (empty($all_products)) {
    $all_products = [
        ['id' => '201', 'title' => 'Cyber Chair', 'description' => 'Ergonomic and stylish chair.', 'price' => '299.00', 'image_url' => 'https://placehold.co/300x220/111111/00ffff?text=Chair1', 'category_id' => '1', 'stock_count' => 15],
        ['id' => '202', 'title' => 'Nova Bed', 'description' => 'Sleep among the stars.', 'price' => '750.00', 'image_url' => 'https://placehold.co/300x220/111111/FF00FF?text=Bed1', 'category_id' => '2', 'stock_count' => 8],
        ['id' => '203', 'title' => 'Astro Table', 'description' => 'A table for future gatherings.', 'price' => '450.00', 'image_url' => 'https://placehold.co/300x220/111111/FFFF00?text=Table1', 'category_id' => '1', 'stock_count' => 0],
        ['id' => '204', 'title' => 'Smart Desk', 'description' => 'Work smarter, not harder.', 'price' => '599.99', 'image_url' => 'https://placehold.co/300x220/111111/00FF00?text=Desk1', 'category_id' => '4', 'stock_count' => 12],
        ['id' => '205', 'title' => 'Cosmic Bookshelf', 'description' => 'Store your knowledge in style.', 'price' => '320.00', 'image_url' => 'https://placehold.co/300x220/111111/00ffff?text=Shelf', 'category_id' => '1', 'stock_count' => 7],
        ['id' => '206', 'title' => 'Galaxy Projector', 'description' => 'Bring the universe indoors.', 'price' => '89.90', 'image_url' => 'https://placehold.co/300x220/111111/FF00FF?text=Projector', 'category_id' => '2', 'stock_count' => 20],
    ];
}


// For guests, cart count is 0
$num_total_items_in_cart = 0;

// Login redirect base URL (used in HTML)
$login_page_url = "login.php"; // Adjust if your login page path is different

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MuscleMafia</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.1/css/all.min.css">
    <link rel="icon"
        href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🌌</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper/swiper-bundle.min.css" />
    <!--
        For actual 3D rendering and advanced animations, you would include libraries like:
        <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.9.1/gsap.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.9.1/ScrollTrigger.min.js"></script>
    -->
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&family=Inter:wght@300;400;500;700&display=swap');

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(145deg, #101012 0%, #08080A 100%);
            color: #E0E0E0;
            overflow-x: hidden;
        }

        .font-orbitron {
            font-family: 'Orbitron', sans-serif;
        }

        .neon-accent-text {
            color: #00FFFF;
            text-shadow: 0 0 5px #00FFFF, 0 0 10px #00FFFF, 0 0 15px #00FFFF, 0 0 20px #00FFFF;
        }

        .neon-glow-button {
            background-color: transparent;
            border: 2px solid #00FFFF;
            color: #00FFFF;
            text-shadow: 0 0 3px #00FFFF, 0 0 5px rgba(0, 255, 255, 0.7);
            box-shadow: 0 0 8px rgba(0, 255, 255, 0.6), inset 0 0 8px rgba(0, 255, 255, 0.3);
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            position: relative;
            overflow: hidden;
        }

        .neon-glow-button:before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(120deg, transparent, rgba(0, 255, 255, 0.2), transparent);
            transition: all 0.6s ease;
        }

        .neon-glow-button:hover:before {
            left: 100%;
        }

        .neon-glow-button:hover {
            background-color: rgba(0, 255, 255, 0.1);
            box-shadow: 0 0 15px #00FFFF, 0 0 25px #00FFFF, 0 0 35px #00FFFF, inset 0 0 10px #00FFFF;
            transform: translateY(-3px) scale(1.03);
        }

        .neon-glow-button-magenta {
            /* Added for variety */
            background-color: transparent;
            border: 2px solid #FF00FF;
            color: #FF00FF;
            text-shadow: 0 0 3px #FF00FF, 0 0 5px rgba(255, 0, 255, 0.7);
            box-shadow: 0 0 8px rgba(255, 0, 255, 0.6), inset 0 0 8px rgba(255, 0, 255, 0.3);
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        .neon-glow-button-magenta:hover {
            background-color: rgba(255, 0, 255, 0.1);
            box-shadow: 0 0 15px #FF00FF, 0 0 25px #FF00FF, 0 0 35px #FF00FF, inset 0 0 10px #FF00FF;
            transform: translateY(-3px) scale(1.03);
        }

        .glass-card {
            background: rgba(22, 22, 25, 0.65);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(60, 60, 60, 0.4);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.5);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-radius: 0.75rem;
            /* Added for consistency */
        }

        .glass-card:hover {
            transform: translateY(-6px) scale(1.02);
            box-shadow: 0 12px 45px 0 rgba(0, 255, 255, 0.12);
        }

        .scroll-animate {
            opacity: 0;
            transition: opacity 0.8s ease-out, transform 0.8s ease-out;
        }

        .scroll-animate.fade-in-up {
            transform: translateY(50px);
        }

        .scroll-animate.scale-in {
            transform: scale(0.9);
        }

        .scroll-animate.is-visible {
            opacity: 1;
            transform: translateY(0) scale(1);
        }

        .three-canvas-placeholder {
            min-height: 300px;
            background: #0d0d0d;
            border: 1px dashed #333;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #555;
            font-size: 0.9rem;
        }

        .parallax-bg {
            background-image: url('https://placehold.co/1920x1080/0a0a0c/1a1a1e?text=MuscleMafia+store');
            /* Placeholder for actual image */
            background-attachment: fixed;
            background-position: center;
            background-repeat: no-repeat;
            background-size: cover;
        }

        .category-item {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-radius: 12px;
        }

        .category-item:hover {
            transform: scale(1.05) perspective(600px) rotateY(4deg);
            box-shadow: 0 10px 25px rgba(0, 255, 255, 0.2);
        }

        .category-item img,
        .category-item i {
            transition: transform 0.3s ease;
        }

        .category-item:hover img,
        .category-item:hover i {
            transform: scale(1.15);
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #101010;
        }

        ::-webkit-scrollbar-thumb {
            background: #00FFFF;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #00AAAA;
        }

        #mobileMenu {
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
            transform: translateY(-100%);
            opacity: 0;
        }

        #mobileMenu.active {
            transform: translateY(0);
            opacity: 1;
        }

        .nav-link {
            color: #b0b0b0;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .nav-link:hover,
        .nav-link.active {
            color: #FFFFFF;
            background-color: rgba(255, 255, 255, 0.05);
        }

        .nav-link-cta {
            background-color: #00FFFF;
            color: #000000;
            font-weight: 600;
        }

        .nav-link-cta:hover {
            background-color: #00DDDD;
        }

        .header-bg {
            background: rgba(16, 16, 18, 0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        /* Added for header style */
        /* --- Product Card Styling --- */
        .product-card-grid {
            background: rgba(25, 25, 28, 0.7);
            border: 1px solid rgba(50, 50, 50, 0.5);
            border-radius: 12px;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex;
            /* Use flex for internal layout */
            flex-direction: column;
        }

        .product-card-grid:hover {
            transform: translateY(-5px) scale(1.01);
            box-shadow: 0 10px 30px rgba(0, 255, 255, 0.15);
        }

        .product-card-grid .image {
            position: relative;
            overflow: hidden;
            border-bottom: 1px solid #2a2a2a;
            height: 200px;
            /* Fixed height for consistency */
        }

        .product-card-grid .image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s ease;
        }

        .product-card-grid:hover .image img {
            transform: scale(1.08);
        }

        .product-card-grid .card-content {
            padding: 1.25rem;
            flex-grow: 1;
            /* Make content grow */
            display: flex;
            flex-direction: column;
        }

        .product-card-grid .name {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.1rem;
            font-weight: 600;
            color: #FFFFFF;
            margin-bottom: 0.5rem;
        }

        .product-card-grid .description-text {
            font-size: 0.85rem;
            color: #a0a0a0;
            margin-bottom: 1rem;
            line-height: 1.4;
            height: 3.9em;
            /* Approx 3 lines */
            overflow: hidden;
        }

        .product-card-grid .price-text {
            font-size: 1.25rem;
            font-weight: 700;
            color: #00FFFF;
            margin-bottom: 0.5rem;
        }

        .product-card-grid .stock-text {
            font-size: 0.8rem;
            font-weight: 500;
            color: #4ade80;
            /* Green for in stock */
            margin-bottom: 1.25rem;
        }

        .product-card-grid .stock-text.out-of-stock {
            color: #f87171;
            /* Red for out of stock */
        }

        .product-card-grid .button {
            margin-top: auto;
            /* Push button to bottom */
        }

        .product-card-grid .action-button {
            display: block;
            text-align: center;
            background-color: transparent;
            border: 1.5px solid #00FFFF;
            color: #00FFFF;
            padding: 0.6rem 1rem;
            border-radius: 6px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
        }

        .product-card-grid .action-button:hover:not([disabled]) {
            background-color: #00FFFF;
            color: #000000;
            box-shadow: 0 0 10px #00FFFF;
        }

        .product-card-grid .action-button[disabled] {
            cursor: not-allowed;
            background-color: #333 !important;
            border-color: #333 !important;
            color: #777 !important;
        }

        /* --- Animation --- */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fadeInUp {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        /* Swiper Pagination */
        .swiper-pagination-bullet {
            background: #555 !important;
            opacity: 0.7 !important;
        }

        .swiper-pagination-bullet-active {
            background: #00FFFF !important;
            opacity: 1 !important;
        }
    </style>
</head>

<body class="antialiased">

    <header class="header-bg sticky top-0 z-50 shadow-2xl">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between h-20">
            <a href="home.php" class="text-3xl md:text-4xl font-orbitron font-bold tracking-wider">
                Muscle<span class="neon-accent-text">Mafia</span>
            </a>

            <div class="relative w-2/5 md:w-1/3 hidden sm:block">
                <input type="search" id="liveProductSearch"
                    class="w-full bg-gray-900 border border-gray-700 text-gray-200 placeholder-gray-500 text-sm rounded-lg py-2.5 px-4 pl-10 focus:ring-2 focus:ring-cyan-500 focus:border-transparent outline-none transition-all duration-300"
                    placeholder="Search designs, furniture, appliances...">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <i class="fas fa-search text-gray-500"></i>
                </div>
                <div id="search-suggestions-container"
                    class="absolute top-full left-0 right-0 mt-1 z-40 glass-card rounded-md p-0 hidden max-h-80 overflow-y-auto shadow-2xl">
                </div>
            </div>

            <nav class="hidden md:flex items-center space-x-1">
                <a href="#categories" class="nav-link px-3 py-2">Categories</a>
                <a href="#showcase" class="nav-link px-3 py-2">Showcase</a>
                <a href="#all-products" class="nav-link px-3 py-2">All Products</a>
                <a href="<?php echo $login_page_url; ?>" class="nav-link nav-link-cta px-4 py-2 ml-2">Login / Sign
                    Up</a>
            </nav>
            <button id="mobileMenuButton" class="md:hidden text-gray-300 hover:text-white focus:outline-none text-2xl">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        <div id="mobileMenu"
            class="md:hidden hidden bg-black bg-opacity-95 absolute w-full shadow-xl border-t border-gray-800">
            <a href="#categories"
                class="block nav-link text-center py-3 border-b border-gray-800 mobile-menu-link">Categories</a>
            <a href="#showcase"
                class="block nav-link text-center py-3 border-b border-gray-800 mobile-menu-link">Showcase</a>
            <a href="#all-products"
                class="block nav-link text-center py-3 border-b border-gray-800 mobile-menu-link">All Products</a>
            <a href="<?php echo $login_page_url; ?>"
                class="block nav-link nav-link-cta text-center py-3 mx-4 my-3 mobile-menu-link">Login / Sign Up</a>
        </div>
    </header>

    <section
        class="relative h-[80vh] md:h-screen flex items-center justify-center text-center overflow-hidden parallax-bg">
        <div class="absolute inset-0 bg-black opacity-60 z-0"></div>
        <div id="hero-3d-canvas" class="absolute inset-0 z-0 opacity-30 three-canvas-placeholder">
            <p class="text-2xl"></p>
        </div>
        <div class="relative z-10 p-4 max-w-3xl mx-auto">
            <h1 class="text-5xl md:text-7xl font-orbitron font-black text-white uppercase animate-fadeInUp"
                style="animation-delay: 0.2s;">
                DESIGN <span class="neon-accent-text">YOUR</span> FUTURE
            </h1>
            <p class="mt-6 mb-10 text-lg md:text-xl text-gray-300 max-w-xl mx-auto animate-fadeInUp"
                style="animation-delay: 0.5s;">
Experience the next generation of fitness. Interactive training, dynamic workouts, and a universe of gains.            </p>
            <a href="#showcase" class="neon-glow-button text-lg font-semibold py-3 px-8 rounded-md animate-fadeInUp"
                style="animation-delay: 0.8s;">
                Explore Product <i class="fas fa-arrow-right ml-2"></i>
            </a>
        </div>
        <div id="hero-particles" class="absolute inset-0 z-0 pointer-events-none">
        </div>
    </section>

    <section id="showcase" class="py-16 md:py-24 bg-black">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <h2
                class="text-4xl md:text-5xl font-orbitron font-bold text-center text-white mb-12 md:mb-16 scroll-animate fade-in-up">
                Featured <span class="neon-accent-text">Creations</span>
            </h2>
            <?php if (!empty($hero_products)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 md:gap-12">
                    <?php foreach ($hero_products as $index => $product): ?>
                        <div class="product-showcase-item glass-card rounded-xl overflow-hidden scroll-animate scale-in"
                            style="animation-delay: <?php echo $index * 0.15; ?>s;">
                            <div class="three-canvas-placeholder h-64 md:h-80 relative">
                                <img src="<?php echo htmlspecialchars($product['image_url'] ? $product['image_url'] : 'https://placehold.co/600x400/0d0d0d/1a1a1a?text=3D+Model+Preview'); ?>"
                                    alt="<?php echo htmlspecialchars($product['title']); ?>"
                                    class="absolute inset-0 w-full h-full object-cover opacity-70"
                                    onerror="this.onerror=null;this.src='https://placehold.co/600x400/0d0d0d/1a1a1a?text=Image+Error';">
                                <p class="text-lg relative z-10 p-4">
                                    <?php echo htmlspecialchars($product['title']); ?></p>
                            </div>
                            <div class="p-6">
                                <h3 class="text-xl font-orbitron font-semibold text-white mb-1">
                                    <?php echo htmlspecialchars($product['title']); ?></h3>
                                <p class="text-sm text-gray-400 mb-4 h-16 overflow-hidden">
                                    <?php echo htmlspecialchars($product['description']); ?></p>
                                <div class="flex items-center justify-between">
                                    <a href="product_details.php?product_id=<?php echo htmlspecialchars($product['id']); ?>"
                                        class="neon-glow-button py-2 px-5 text-sm rounded-md font-semibold">
                                        Customize & View
                                    </a>

                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-center text-gray-400">No featured products to display currently.</p>
            <?php endif; ?>
        </div>
    </section>


    <section id="categories" class="py-16 md:py-24 bg-gray-950">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <h2
                class="text-4xl md:text-5xl font-orbitron font-bold text-center text-white mb-12 md:mb-16 scroll-animate fade-in-up">
                Discover <span class="neon-accent-text">Spaces</span>
            </h2>
            <?php if (!empty($categories_data)): ?>
                <div class="swiper categorySwiper">
                    <div class="swiper-wrapper pb-12">
                        <?php foreach ($categories_data as $index => $category): ?>
                            <div class="swiper-slide category-item glass-card p-6 md:p-8 text-center scroll-animate scale-in"
                                style="animation-delay: <?php echo $index * 0.08; ?>s;">
                                <a href="#all-products" class="block category-filter-link"
                                    data-category-id="<?php echo htmlspecialchars($category['id']); ?>"
                                    data-category-name="<?php echo htmlspecialchars($category['name']); ?>">
                                    <div
                                        class="h-24 w-24 mx-auto mb-6 flex items-center justify-center rounded-full bg-gray-800 border-2 border-cyan-500 shadow-lg">
                                        <?php if (!empty($category['ico_img'])): ?>
                                            <img src="<?php echo htmlspecialchars($category['ico_img']); ?>"
                                                alt="<?php echo htmlspecialchars($category['name']); ?>"
                                                class="w-12 h-12 object-contain"
                                                style="drop-shadow(0 0 3px #00FFFF);"
                                                onerror="this.style.display='none'; this.nextElementSibling.style.display='block';" />
                                            <i class="fas fa-shapes text-4xl text-cyan-400 hidden" aria-hidden="true"></i>
                                        <?php else: ?>
                                            <i class="fas fa-shapes text-4xl text-cyan-400" aria-hidden="true"></i> <?php endif; ?>
                                    </div>
                                    <h3 class="text-xl font-orbitron font-semibold text-white mb-2">
                                        <?php echo htmlspecialchars($category['name']); ?></h3>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="swiper-pagination category-swiper-pagination mt-8"></div>
                </div>
            <?php else: ?>
                <p class="text-center text-gray-400">No categories to display currently.</p>
            <?php endif; ?>
        </div>
    </section>


    <section id="all-products" class="py-16 md:py-24 bg-gray-950">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-4xl md:text-5xl font-orbitron font-bold text-center text-white mb-12 md:mb-16 scroll-animate fade-in-up"
                id="allProductsSectionTitle">
                Our <span class="neon-accent-text">Full Collection</span>
            </h2>
            <?php if (!empty($all_products)): ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6 md:gap-8"
                    id="productsGridContainer">
                    <?php foreach ($all_products as $index => $product): ?>
                        <div class="product-card-grid scroll-animate scale-in"
                            style="animation-delay: <?php echo ($index % 4) * 0.1 + 0.1; ?>s;"
                            data-product-id="<?php echo htmlspecialchars($product['id']); ?>"
                            data-category-id="<?php echo htmlspecialchars($product['category_id']); ?>">
                            <div class="image">
                                <img src="<?php echo htmlspecialchars($product['image_url'] ? $product['image_url'] : 'https://placehold.co/300x220/111111/333333?text=MuscleMafia+Product'); ?>"
                                    alt="<?php echo htmlspecialchars($product['title']); ?>"
                                    onerror="this.onerror=null;this.src='https://placehold.co/300x220/111111/333333?text=Image+Error';">
                            </div>
                            <div class="card-content">
                                <h3 class="name truncate" title="<?php echo htmlspecialchars($product['title']); ?>">
                                    <?php echo htmlspecialchars($product['title']); ?></h3>
                                <p class="description-text">
                                    <?php echo htmlspecialchars(substr($product['description'], 0, 80)); ?>...</p>
                                <p class="price-text">DZ<?php echo number_format((float) ($product['price'] ?? 0), 2); ?></p>
                                <p
                                    class="stock-text <?php echo ((int) ($product['stock_count'] ?? 0) > 0) ? '' : 'out-of-stock'; ?>">
                                    <?php echo ((int) ($product['stock_count'] ?? 0) > 0) ? 'In Stock' : 'Out of Stock'; ?>
                                </p>
                                <div class="button mt-auto">
                                    <a href="<?php echo $login_page_url; ?>?redirect=product&product_id=<?php echo htmlspecialchars($product['id']); ?>"
                                        class="action-button" <?php echo ((int) ($product['stock_count'] ?? 0) <= 0) ? 'disabled aria-disabled="true"' : ''; ?>>
                                        <i class="fas fa-shopping-cart mr-2"
                                            aria-hidden="true"></i><?php echo ((int) ($product['stock_count'] ?? 0) > 0) ? 'Login to Buy' : 'OUT OF STOCK'; ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="no-products-msg" class="text-center text-gray-400 mt-10 text-xl hidden">No products found in this
                    category.</div>
                <div class="text-center mt-12">
                    <button id="showAllProductsBtn" class="neon-glow-button py-2 px-6 text-sm rounded-md font-semibold">Show
                        All Products</button>
                </div>
            <?php else: ?>
                <p class='text-xl text-center text-gray-500 py-10'>Our collection is currently empty. Explore new dimensions
                    soon!</p>
            <?php endif; ?>
        </div>
    </section>

    <section class="py-16 md:py-24 bg-black">
        <div class="container mx-auto px-4 text-center">
            <h2 class="text-3xl md:text-4xl font-orbitron font-bold text-white mb-4 scroll-animate fade-in-up">
                Join the <span class="neon-accent-text">MuscleMafia</span> Universe
            </h2>
            <p class="text-lg text-gray-400 mb-8 max-w-2xl mx-auto scroll-animate fade-in-up"
                style="animation-delay: 0.2s;">
                Currently home to <strong class="text-white text-xl"><?php echo number_format($total_users); ?></strong>
                visionary designers and homeowners.
            </p>
            <a href="<?php echo $login_page_url; ?>"
                class="neon-glow-button-magenta text-lg font-semibold py-3 px-8 rounded-md scroll-animate fade-in-up"
                style="animation-delay: 0.4s;">
                Create Your Account <i class="fas fa-user-plus ml-2" aria-hidden="true"></i>
            </a>
        </div>
    </section>


    <footer class="footer-bg text-gray-400 text-center py-12 md:py-16 border-t border-gray-800">
        <div class="container mx-auto px-4">
            <a href="login.php" class="text-3xl font-orbitron font-bold tracking-wider text-white">
                Muscle<span class="neon-accent-text">Mafia</span>
            </a>
            <p class="mt-4 mb-6 text-sm max-w-md mx-auto">
                Crafting the future of living, one immersive experience at a time.
            </p>
            <div class="flex justify-center space-x-6 mb-6">
                <a href="#" class="hover:text-cyan-400 transition-colors" aria-label="MuscleMafia on Instagram"><i
                        class="fab fa-instagram fa-2x" aria-hidden="true"></i></a>
                <a href="#" class="hover:text-cyan-400 transition-colors" aria-label="MuscleMafia on Twitter"><i
                        class="fab fa-twitter fa-2x" aria-hidden="true"></i></a>
                <a href="#" class="hover:text-cyan-400 transition-colors" aria-label="MuscleMafia on Pinterest"><i
                        class="fab fa-pinterest fa-2x" aria-hidden="true"></i></a>
            </div>
            <p class="text-xs">&copy; <?php echo date("Y"); ?> MuscleMafia Industries. All rights reserved. Dare to dream
                differently.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/swiper/swiper-bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Mobile Menu Toggle
            const menuButton = document.getElementById('mobileMenuButton');
            const mobileMenu = document.getElementById('mobileMenu');
            if (menuButton && mobileMenu) {
                menuButton.addEventListener('click', () => {
                    const isExpanded = menuButton.getAttribute('aria-expanded') === 'true' || false;
                    menuButton.setAttribute('aria-expanded', !isExpanded);
                    mobileMenu.classList.toggle('hidden');
                    mobileMenu.classList.toggle('active');
                });
                mobileMenu.querySelectorAll('.mobile-menu-link').forEach(link => {
                    link.addEventListener('click', () => {
                        mobileMenu.classList.add('hidden');
                        mobileMenu.classList.remove('active');
                        menuButton.setAttribute('aria-expanded', 'false');
                    });
                });
            }

            // Scroll Animations
            const animatedElements = document.querySelectorAll('.scroll-animate');
            const observerOptions = { threshold: 0.1 };
            const observer = new IntersectionObserver((entries, obs) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        obs.unobserve(entry.target); // Unobserve after animation
                    }
                });
            }, observerOptions);
            animatedElements.forEach(el => { observer.observe(el); });

            // Hero Swiper (Check if it exists before initializing)
            // This section is commented out as there's no .heroSwiper element in the current HTML structure.
            // If you add a hero swiper, uncomment and adapt this.
            /*
            const heroSwiperElement = document.querySelector('.heroSwiper');
            if (heroSwiperElement) {
                try {
                    if (heroSwiperElement.querySelectorAll('.swiper-slide').length > 1) {
                        new Swiper(".heroSwiper", {
                            loop: true, effect: 'fade',
                            fadeEffect: { crossFade: true },
                            autoplay: { delay: 6000, disableOnInteraction: false },
                            pagination: { el: ".hero-swiper-pagination", clickable: true },
                            navigation: { nextEl: ".hero-swiper-button-next", prevEl: ".hero-swiper-button-prev" },
                        });
                    } else if (heroSwiperElement.querySelector('.swiper-slide')) { // Single slide, no loop/nav
                        new Swiper(".heroSwiper", { loop: false });
                    }
                } catch (e) { console.error("Hero Swiper init error:", e); }
            }
            */


            // Category Swiper
            const categorySwiperElement = document.querySelector('.categorySwiper');
            if (categorySwiperElement && typeof Swiper !== 'undefined') {
                try {
                    // Only initialize if there are enough slides to make a carousel meaningful
                    if (categorySwiperElement.querySelectorAll('.swiper-slide').length > 1) {
                        new Swiper(".categorySwiper", {
                            slidesPerView: 2.2, // Start with a mobile-friendly number
                            spaceBetween: 10,
                            grabCursor: true,
                            pagination: { el: ".category-swiper-pagination", clickable: true, dynamicBullets: true },
                            breakpoints: {
                                640: { slidesPerView: 3.5, spaceBetween: 15 },
                                768: { slidesPerView: 4.5, spaceBetween: 20 },
                                1024: { slidesPerView: 5.5, spaceBetween: 20 },
                                1280: { slidesPerView: 6.5, spaceBetween: 25 }
                            }
                        });
                    }
                } catch (e) { console.error("Category Swiper init error:", e); }
            }

            // Live Search AJAX
            const searchInput = document.getElementById('liveProductSearch');
            const suggestionsContainer = document.getElementById('search-suggestions-container');
            let searchTimeout;
            if (searchInput && suggestionsContainer) {
                searchInput.addEventListener('input', function () {
                    clearTimeout(searchTimeout);
                    const query = this.value.trim();
                    if (query.length >= 2) { // Start search after 2 characters
                        suggestionsContainer.innerHTML = '<p class="p-3 text-gray-400 text-sm text-center">Searching dimensions...</p>';
                        suggestionsContainer.classList.remove('hidden');
                        searchTimeout = setTimeout(() => {
                            // Ensure 'live_search.php' exists and is correctly configured to handle requests.
                            // For this example, we'll assume it returns JSON like: { success: true, html: "..." }
                            fetch('live_search.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'query=' + encodeURIComponent(query)
                            })
                                .then(response => {
                                    if (!response.ok) {
                                        // If live_search.php is not found or returns an error
                                        if (response.status === 404) {
                                            throw new Error('Search endpoint not found (live_search.php).');
                                        }
                                        throw new Error('Network response was not ok: ' + response.statusText);
                                    }
                                    return response.json();
                                })
                                .then(data => {
                                    if (data.success && data.html) {
                                        suggestionsContainer.innerHTML = data.html;
                                    } else {
                                        suggestionsContainer.innerHTML = data.html || '<p class="p-3 text-gray-400 text-sm text-center">No results found.</p>';
                                    }
                                })
                                .catch(error => {
                                    console.error('Search Fetch Error:', error);
                                    suggestionsContainer.innerHTML = `<p class="p-3 text-red-400 text-sm text-center">Search error: ${error.message}</p>`;
                                });
                        }, 400); // Debounce search
                    } else {
                        suggestionsContainer.innerHTML = '';
                        suggestionsContainer.classList.add('hidden');
                    }
                });
                // Hide suggestions when clicking outside
                document.addEventListener('click', (event) => {
                    if (!searchInput.contains(event.target) && !suggestionsContainer.contains(event.target)) {
                        suggestionsContainer.classList.add('hidden');
                    }
                });
                // Show suggestions on focus if there's content
                searchInput.addEventListener('focus', () => {
                    if (searchInput.value.trim().length >= 2 && suggestionsContainer.innerHTML.trim() !== '' && suggestionsContainer.innerHTML.indexOf('Searching dimensions...') === -1) {
                        suggestionsContainer.classList.remove('hidden');
                    }
                });
            }

            // --- CATEGORY FILTERING LOGIC ---
            const productGrid = document.getElementById('productsGridContainer');
            const allProductCards = productGrid ? Array.from(productGrid.querySelectorAll('.product-card-grid')) : [];
            const allProductsSectionTitle = document.getElementById('allProductsSectionTitle');
            const noProductsMsgElement = document.getElementById('no-products-msg'); // Renamed for clarity

            document.querySelectorAll('.category-filter-link').forEach(catLink => {
                catLink.addEventListener('click', function (e) {
                    e.preventDefault(); // Prevent default anchor behavior (page jump)

                    const categoryId = this.dataset.categoryId;
                    const categoryName = this.dataset.categoryName || "Selected Category";

                    // Update the section title with the category name
                    if (allProductsSectionTitle) {
                        allProductsSectionTitle.innerHTML = `EXPLORE: <span class="neon-accent-text">${categoryName.toUpperCase()}</span>`;
                    }

                    let productsFoundInCategory = false;
                    // Filter products based on the selected category
                    allProductCards.forEach(card => {
                        if (card.dataset.categoryId === categoryId) {
                            card.style.display = 'flex'; // Or 'block' or 'grid' depending on your card's display type
                            productsFoundInCategory = true;
                        } else {
                            card.style.display = 'none'; // Hide cards not in this category
                        }
                    });

                    // Show or hide the "no products" message
                    if (noProductsMsgElement) {
                        noProductsMsgElement.style.display = productsFoundInCategory ? 'none' : 'block';
                    }

                    // Smooth scroll to the "all-products" section to show the filtered results
                    const targetSection = document.getElementById('all-products');
                    if (targetSection) {
                        targetSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            });

            // "Show All Products" Button Logic
            const showAllProductsBtn = document.getElementById('showAllProductsBtn');
            if (showAllProductsBtn && allProductsSectionTitle && allProductCards.length > 0) {
                showAllProductsBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    // Reset the section title
                    allProductsSectionTitle.innerHTML = `Our <span class="neon-accent-text">Full Collection</span>`;
                    // Show all product cards
                    allProductCards.forEach(card => {
                        card.style.display = 'flex'; // Or 'block' or 'grid'
                    });
                    // Hide the "no products" message
                    if (noProductsMsgElement) {
                        noProductsMsgElement.style.display = 'none';
                    }
                    // Optionally scroll back to the top of the section
                    const targetSection = document.getElementById('all-products');
                    if (targetSection) {
                        targetSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            }
            // --- END OF CATEGORY FILTERING LOGIC ---

        });
    </script>
</body>

</html>
<?php
// Close the database connection if it's an object and has a close method
if (isset($conn) && is_object($conn) && method_exists($conn, 'close')) {
    $conn->close();
}
?>