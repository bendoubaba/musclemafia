<?php
session_start();
// Ensure database.php is in the same directory or adjust the path.
// It should establish a $conn variable for the database connection.
if (file_exists("database.php")) {
    include("database.php");
} else {
    // Fallback or error if database.php is not found
    if (!isset($conn) || !$conn) {
        $conn = new stdClass(); // Create a dummy object to prevent fatal errors
        $conn->error = "Database connection file not found or failed to connect.";
        // Mock query method for dummy $conn
        $conn->query = function($sql) {
            // Simulate product found for a specific ID for demo purposes
            if (preg_match("/WHERE p.id = (\d+)/", $sql, $matches)) {
                $pid = (int)$matches[1];
                if ($pid === 1 || $pid === 101) { // Example product IDs
                    $mockResult = new stdClass();
                    $mockResult->num_rows = 1;
                    $mockResult->fetch_assoc = function() use ($pid) {
                        return [
                            'id' => $pid,
                            'product' => 'Demo Product ' . $pid,
                            'description' => 'This is a detailed description for the demo product. It highlights its features and benefits, designed to look good on the page.',
                            'price' => ($pid === 1) ? 199.99 : 249.50,
                            'picture' => 'https://placehold.co/600x600/0a0a0c/00ffff?text=Product+'.$pid,
                            'count' => 10,
                            'category_id' => 1,
                            'category_name' => 'Demo Category'
                        ];
                    };
                    return $mockResult;
                }
            }
             // Simulate related products
            if (strpos($sql, "WHERE category_id = ? AND id != ?") !== false) {
                $mockResult = new stdClass();
                $mockResult->num_rows = 2;
                $related_data = [
                    ['id' => 201, 'title' => 'Related Item 1', 'price' => 99.00, 'image_url' => 'https://placehold.co/300x220/111111/FF00FF?text=Related1', 'stock_count' => 5],
                    ['id' => 202, 'title' => 'Related Item 2', 'price' => 129.00, 'image_url' => 'https://placehold.co/300x220/111111/FFFF00?text=Related2', 'stock_count' => 8],
                ];
                $idx = 0;
                $mockResult->fetch_assoc = function() use (&$related_data, &$idx) {
                    if ($idx < count($related_data)) {
                        return $related_data[$idx++];
                    }
                    return null;
                };
                return $mockResult;
            }
            // Default mock for other queries (e.g., cart count)
            $mockResult = new stdClass();
            $mockResult->num_rows = 0;
            $mockResult->fetch_assoc = function() { return null; };
            return $mockResult;
        };
        // Mock prepare, bind_param, execute, get_result, close for statements
        $mockStatement = new stdClass();
        $mockStatement->bind_param = function(...$params) {};
        $mockStatement->execute = function() {};
        $mockStatement->get_result = function() use ($conn, $mockStatement) { // Pass $conn to access its query method
            // A bit hacky: try to guess the SQL from what might have been prepared
            // This is very limited and only for the demo product query
            if (isset($mockStatement->sql_template) && strpos($mockStatement->sql_template, "FROM products p") !== false) {
                 // Simulate a specific product ID if bound
                $simulated_id = $mockStatement->bound_id ?? 1; // Default to 1 if not bound
                return $conn->query("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = " . $simulated_id);
            }
             if (isset($mockStatement->sql_template) && strpos($mockStatement->sql_template, "WHERE category_id = ? AND id != ?") !== false) {
                return $conn->query("SELECT id, product AS title, price, picture AS image_url, `count` as stock_count FROM products WHERE category_id = 1 AND id != 1 ORDER BY RAND() LIMIT 3"); // Simplified
            }
            // Fallback for cart sum
            if (isset($mockStatement->sql_template) && strpos($mockStatement->sql_template, "SUM(quantity) AS total_items FROM cart") !== false) {
                 $mockResult = new stdClass();
                 $mockResult->fetch_assoc = function() { return ['total_items' => 0]; };
                 return $mockResult;
            }
            $emptyResult = new stdClass();
            $emptyResult->num_rows = 0;
            $emptyResult->fetch_assoc = function() { return null; };
            return $emptyResult;
        };
        $mockStatement->close = function() {};

        $conn->prepare = function($sql) use ($mockStatement) {
            $mockStatement->sql_template = $sql; // Store SQL template for get_result logic
            // If binding product_id, capture it for the mock get_result
            if (strpos($sql, "WHERE p.id = ?") !== false) {
                 $mockStatement->bind_param = function($types, &$var1) use ($mockStatement) {
                    $mockStatement->bound_id = $var1;
                };
            } else if (strpos($sql, "WHERE category_id = ? AND id != ?") !== false) {
                $mockStatement->bind_param = function($types, &$var1, &$var2) use ($mockStatement) {
                    // Not strictly needed for current mock but good practice
                    $mockStatement->bound_cat_id = $var1;
                    $mockStatement->bound_prod_id = $var2;
                };
            }

            return $mockStatement;
        };
        $conn->close = function() {}; // Mock close method
    }
}


$product_id = null;
$product = null;
$category_name = "N/A";
$related_products = [];
$login_page_url = "login.php"; // Adjust if your login page path is different. For demo, will be #login

// User session details
$user_is_logged_in = isset($_SESSION['user_id']);
$current_page_user_id =  0;
$display_name =  "Guest";


if (isset($_GET['product_id']) && filter_var($_GET['product_id'], FILTER_VALIDATE_INT)) {
    $product_id = (int)$_GET['product_id'];

    if (isset($conn) && is_object($conn) && method_exists($conn, 'prepare')) {
        // Fetch main product details
        $stmt_product = $conn->prepare(
            "SELECT p.*, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id
             WHERE p.id = ?"
        );
        if ($stmt_product) {
            $stmt_product->bind_param("i", $product_id);
            $stmt_product->execute();
            $result_product = $stmt_product->get_result();
            if ($result_product && $result_product->num_rows > 0) {
                $product = $result_product->fetch_assoc();
                $category_name = $product['category_name'] ?? "Uncategorized";

                // Fetch related products
                if ($product['category_id']) {
                    $stmt_related = $conn->prepare(
                        "SELECT id, product AS title, price, picture AS image_url, `count` as stock_count
                         FROM products
                         WHERE category_id = ? AND id != ?
                         ORDER BY RAND() LIMIT 4" // Show 4 related products
                    );
                    if ($stmt_related) {
                        $stmt_related->bind_param("ii", $product['category_id'], $product_id);
                        $stmt_related->execute();
                        $result_related = $stmt_related->get_result();
                        while ($result_related && $row_related = $result_related->fetch_assoc()) {
                            $related_products[] = $row_related;
                        }
                        $stmt_related->close();
                    } else {
                        error_log("Error preparing related products statement: " . ($conn->error ?? 'Unknown DB error'));
                    }
                }
            } else {
                 // Product not found with this ID
            }
            $stmt_product->close();
        } else {
            error_log("Error preparing product statement: " . ($conn->error ?? 'Unknown DB error'));
        }
    } else {
        error_log("Database connection error or prepare method not available.");
    }
}

// Cart item count
$num_total_items_in_cart = 0;
if ($user_is_logged_in && $current_page_user_id && isset($conn) && is_object($conn) && method_exists($conn, 'prepare')) {
    $sql_cart_total_items = "SELECT SUM(quantity) AS total_items FROM cart WHERE user_id = ?";
    $stmt_cart_total_items = $conn->prepare($sql_cart_total_items);
    if ($stmt_cart_total_items) {
        $stmt_cart_total_items->bind_param("i", $current_page_user_id);
        $stmt_cart_total_items->execute();
        $result_cart_total_items = $stmt_cart_total_items->get_result();
        if ($result_cart_total_items && $row_cart_total = $result_cart_total_items->fetch_assoc()) {
            $num_total_items_in_cart = (int)($row_cart_total['total_items'] ?? 0);
        }
        $stmt_cart_total_items->close();
    } else {
        error_log("Error preparing cart total items statement: " . ($conn->error ?? 'Unknown DB error'));
    }
}

// For demo purposes if login_form/login.php doesn't exist
if (strpos($login_page_url, "login.php") !== false && !is_dir(dirname(__DIR__ . $login_page_url))) { // Basic check
    $login_page_url = "#login-required"; // Fallback for demo
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon"
        href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🌌</text></svg>">
    
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $product ? htmlspecialchars($product['product']) . ' - MuscleMafia' : 'Product Not Found - MuscleMafia'; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.1/css/all.min.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🌌</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper/swiper-bundle.min.css" />
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&family=Inter:wght@300;400;500;700&display=swap');
        html { scroll-behavior: smooth; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(145deg, #101012 0%, #08080A 100%); color: #E0E0E0; overflow-x: hidden; }
        .font-orbitron { font-family: 'Orbitron', sans-serif; }
        .neon-accent-text { color: #00FFFF; text-shadow: 0 0 5px #00FFFF, 0 0 10px #00FFFF, 0 0 15px #00FFFF, 0 0 20px #00FFFF; }
        .header-bg { background: rgba(16, 16, 18, 0.85); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); border-bottom: 1px solid #2a2a2a;}
        .nav-link { color: #b0b0b0; padding: 0.5rem 1rem; border-radius: 0.375rem; transition: background-color 0.3s ease, color 0.3s ease; text-decoration: none; }
        .nav-link:hover, .nav-link.active { color: #FFFFFF; background-color: rgba(255,255,255,0.05); }
        .nav-link-cta { background-color: #00FFFF; color: #000000; font-weight: 600; }
        .nav-link-cta:hover { background-color: #00DDDD; }
        .badge { position: absolute; top: -8px; right: -8px; padding: 1px 5px; border-radius: 50%; background-color: #FFFFFF; color: #000000; font-size: 0.7rem; font-weight: 600; border: 1px solid #000000; min-width: 18px; height: 18px; display:flex; align-items:center; justify-content:center; line-height: 1; }
        
        .three-canvas-placeholder { min-height: 400px; background: #0d0d0d; border: 1px dashed #333; display: flex; align-items: center; justify-content: center; text-align: center; color: #555; font-size: 1rem; border-radius: 0.5rem; position: relative; overflow:hidden; }
        .three-canvas-placeholder img { transition: transform 0.5s ease-out; }
        .three-canvas-placeholder:hover img { transform: scale(1.05); }

        .product-detail-card { background: rgba(22, 22, 25, 0.75); backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px); border: 1px solid rgba(60, 60, 60, 0.5); box-shadow: 0 10px 35px 0 rgba(0, 0, 0, 0.5); }
        
        .action-button-details {
            background-color: #00FFFF; color: #000000; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
            border: 2px solid #00FFFF; padding: 0.875rem 2rem; border-radius: 6px;
            cursor: pointer; text-align: center; 
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1); display: inline-flex; align-items: center; justify-content: center;
        }
        .action-button-details:hover:not(:disabled) {
            background-color: #000000; color: #00FFFF;
            box-shadow: 0 0 15px #00FFFF, 0 0 25px #00FFFF, inset 0 0 5px #00FFFF;
            transform: translateY(-3px);
        }
        .action-button-details:disabled { opacity: 0.6; cursor: not-allowed; background-color: #374151 !important; border-color: #374151 !important; color: #9CA3AF !important; box-shadow: none !important; transform: none !important;}

        .return-button {
            background-color: transparent; color: #00FFFF; border: 2px solid #00FFFF;
            padding: 0.6rem 1.5rem; border-radius: 6px; font-weight: 600;
            transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center;
        }
        .return-button:hover { background-color: rgba(0, 255, 255, 0.1); box-shadow: 0 0 10px #00FFFF; }

        /* Related Product Card - using styles from login.php for consistency */
        .product-card-grid {
            background: rgba(25, 25, 28, 0.7); border: 1px solid rgba(50, 50, 50, 0.5);
            border-radius: 12px; overflow: hidden; transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex; flex-direction: column; height: 100%; /* Ensure full height for flex */
        }
        .product-card-grid:hover { transform: translateY(-5px) scale(1.01); box-shadow: 0 10px 30px rgba(0, 255, 255, 0.15); }
        .product-card-grid .image { position: relative; overflow: hidden; border-bottom: 1px solid #2a2a2a; height: 180px; /* Adjusted height */ }
        .product-card-grid .image img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease; }
        .product-card-grid:hover .image img { transform: scale(1.08); }
        .product-card-grid .card-content { padding: 1rem; flex-grow: 1; display: flex; flex-direction: column; }
        .product-card-grid .name { font-family: 'Orbitron', sans-serif; font-size: 1rem; font-weight: 600; color: #FFFFFF; margin-bottom: 0.4rem; }
        .product-card-grid .price-text { font-size: 1.1rem; font-weight: 700; color: #00FFFF; margin-bottom: 0.75rem; }
        .product-card-grid .button { margin-top: auto; }
        .product-card-grid .action-button {
            display: block; text-align: center; background-color: transparent; border: 1.5px solid #00FFFF; color: #00FFFF;
            padding: 0.5rem 0.8rem; border-radius: 6px; font-weight: 600; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.5px;
            transition: all 0.3s ease; text-decoration: none;
        }
        .product-card-grid .action-button:hover:not([disabled]) { background-color: #00FFFF; color: #000000; box-shadow: 0 0 10px #00FFFF; }
        
        ::-webkit-scrollbar { width: 8px; height: 8px; } ::-webkit-scrollbar-track { background: #101010; } ::-webkit-scrollbar-thumb { background: #00FFFF; border-radius: 4px; } ::-webkit-scrollbar-thumb:hover { background: #00AAAA; }
        #mobileMenu { transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-100%); opacity: 0; }
        #mobileMenu.active { transform: translateY(0); opacity: 1; }

        /* Quantity Modal Styles */
        .modal-backdrop { display: none; position: fixed; inset: 0; background-color: rgba(0,0,0,0.7); backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); z-index: 10000; align-items: center; justify-content: center; padding: 1rem; }
        .modal-backdrop.active { display: flex; }
        .modal-content { background-color: #161619; color: #e0e0e0; padding: 1.5rem 2rem; border-radius: 0.75rem; box-shadow: 0 10px 30px rgba(0,0,0,0.5); width: 100%; max-width: 400px; border: 1px solid #2a2a2a; }
        .modal-quantity-btn { background-color: #2a2a2e; color: #00FFFF; border: 1px solid #00FFFF; width: 36px; height: 36px; border-radius: 50%; font-size: 1.25rem; transition: background-color 0.2s; }
        .modal-quantity-btn:hover { background-color: #00FFFF; color: #101012; }
        .modal-quantity-input { background-color: #0c0c0e; color: #e0e0e0; border: 1px solid #3a3a3e; text-align: center; width: 60px; height: 36px; border-radius: 0.375rem; margin: 0 0.5rem; -moz-appearance: textfield; }
        .modal-quantity-input::-webkit-outer-spin-button, .modal-quantity-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        
        /* Toast Notification Styles */
        #toast-container { position: fixed; bottom: 1.25rem; right: 1.25rem; z-index: 10001; display: flex; flex-direction: column; gap: 0.5rem; }
        .toast-notification { background-color: #22c55e; color: white; padding: 0.75rem 1.25rem; border-radius: 0.375rem; box-shadow: 0 4px 12px rgba(0,0,0,0.2); opacity: 0; transform: translateX(100%); transition: opacity 0.3s ease, transform 0.3s ease; font-size: 0.9rem; }
        .toast-notification.show { opacity: 1; transform: translateX(0); }
        .toast-notification.error { background-color: #ef4444; }
        .prose-invert { --tw-prose-body: #d1d5db; --tw-prose-headings: #fff; /* Add other prose styles as needed */ }
    </style>
</head>
<body class="antialiased">

    <header class="header-bg sticky top-0 z-50 shadow-2xl">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between h-20">
            <a href="login.php<?php echo $user_is_logged_in ? '?user_id='.$current_page_user_id : ''; ?>" class="text-3xl md:text-4xl font-orbitron font-bold tracking-wider" aria-label="MuscleMafia Homepage">
                Muscle<span class="neon-accent-text">Mafia</span>
            </a>
            <nav class="hidden md:flex items-center space-x-1">
                <a href="home.php#categories" class="nav-link px-3 py-2">Categories</a>
                <a href="home.php'#showcase'" class="nav-link px-3 py-2">Showcase</a>
                <?php if ($user_is_logged_in && $current_page_user_id > 0): // Ensure user_id is valid ?>
                    <a href="/user_interface/update_user.php?id=<?php echo $current_page_user_id; ?>" class="nav-link px-3 py-2" aria-label="User Profile">Profile</a>
                    <a href="/user_interface/card.php?id=<?php echo $current_page_user_id; ?>" class="nav-link px-3 py-2 relative" title="Shopping Cart" aria-label="Shopping Cart">
                        <i class="fas fa-shopping-cart fa-lg" aria-hidden="true"></i>
                        <span class="badge <?php echo ($num_total_items_in_cart == 0) ? 'hidden' : ''; ?>" id="cartItemCountBadgeHeader"><?php echo $num_total_items_in_cart; ?></span>
                    </a>
                    <a href="<?php echo $login_page_url; ?>" class="nav-link nav-link-cta px-4 py-2 ml-2">Logout</a>
                <?php else: ?>
                    <a href="<?php echo $login_page_url; ?>" class="nav-link nav-link-cta px-4 py-2 ml-2">Login / Sign Up</a>
                <?php endif; ?>
            </nav>
            <button id="mobileMenuButton" class="md:hidden text-gray-300 hover:text-white focus:outline-none text-2xl" aria-label="Toggle mobile menu" aria-expanded="false">
                <i class="fas fa-bars" aria-hidden="true"></i>
            </button>
        </div>
         <div id="mobileMenu" class="md:hidden hidden bg-black bg-opacity-95 absolute w-full shadow-xl border-t border-gray-800">
            <a href="home.php#categories" class="block nav-link text-center py-3 border-b border-gray-800 mobile-menu-link">Categories</a>
            <a href="home.php#showcase" class="block nav-link text-center py-3 border-b border-gray-800 mobile-menu-link">Showcase</a>
            <?php if ($user_is_logged_in && $current_page_user_id > 0): ?>
                <a href="/user_interface/update_user.php?id=<?php echo $current_page_user_id; ?>" class="block nav-link text-center py-3 border-b border-gray-800 mobile-menu-link">Profile</a>
                <a href="/user_interface/card.php?id=<?php echo $current_page_user_id; ?>" class="block nav-link text-center py-3 border-b border-gray-800 mobile-menu-link">Cart</a>
                <a href="<?php echo $login_page_url; ?>" class="block nav-link nav-link-cta text-center py-3 mx-4 my-3 mobile-menu-link">Logout</a>
            <?php else: ?>
                <a href="<?php echo $login_page_url; ?>" class="block nav-link nav-link-cta text-center py-3 mx-4 my-3 mobile-menu-link">Login / Sign Up</a>
            <?php endif; ?>
        </div>
    </header>

    <main class="container mx-auto px-4 sm:px-6 lg:px-8 py-12 md:py-16">
        <?php if ($product): ?>
            <div class="mb-8">
                 <a href="home.php" class="return-button">
                    <i class="fas fa-arrow-left mr-2" aria-hidden="true"></i> Return to Shop
                </a>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-8 md:gap-12 items-start">
                <div class="lg:col-span-3 product-detail-card rounded-xl p-4 md:p-6 scroll-animate fade-in-up">
                    <div class="three-canvas-placeholder relative aspect-square">
                        <p class="text-lg hidden">Interactive 3D Model of "<?php echo htmlspecialchars($product['product']); ?>"</p>
                        <img src="<?php echo htmlspecialchars($product['picture'] ?: 'https://placehold.co/800x800/0a0a0c/00ffff?text=MuscleMafia+Product'); ?>"
                             alt="Main image of <?php echo htmlspecialchars($product['product']); ?>"
                             class="absolute inset-0 w-full h-full object-contain"
                             onerror="this.onerror=null;this.src='https://placehold.co/800x800/0a0a0c/cccccc?text=Image+Error';">
                    </div>
                    <div class="mt-4 text-center text-sm text-gray-400">
                        <p></p>
                    </div>
                </div>

                <div class="lg:col-span-2 product-detail-card rounded-xl p-6 md:p-8 scroll-animate fade-in-up" style="animation-delay: 0.15s;">
                    <a href="login.php<?php echo $user_is_logged_in ? '?user_id='.$current_page_user_id : ''; ?>#category-<?php echo htmlspecialchars($product['category_id']); ?>" class="text-sm text-cyan-400 hover:text-cyan-300 uppercase tracking-wider font-medium">
                        <?php echo htmlspecialchars($category_name); ?>
                    </a>
                    <h1 class="text-3xl md:text-4xl font-orbitron font-bold text-white mt-2 mb-4"><?php echo htmlspecialchars($product['product']); ?></h1>

                    <p class="text-2xl md:text-3xl font-semibold text-white mb-4 price-text">
                        DZ<?php echo number_format((float)($product['price'] ?? 0), 2); ?>
                    </p>

                    <div class="prose prose-sm sm:prose-base prose-invert text-gray-300 mb-6 max-w-none">
                        <?php echo nl2br(htmlspecialchars($product['description'] ?? 'No description available.')); ?>
                    </div>

                    <p class="mb-6 stock-text <?php echo ((int)($product['count'] ?? 0) > 0) ? 'text-green-400' : 'text-red-500'; ?>">
                        Availability:
                        <span class="font-semibold"><?php echo ((int)($product['count'] ?? 0) > 0) ? htmlspecialchars($product['count']) . ' in stock' : 'Out of Stock'; ?></span>
                    </p>

                    <?php
                        $login_redirect_with_product = $login_page_url . (strpos($login_page_url, '?') ? '&' : '?') . "redirect=" . urlencode("product_details.php?product_id=" . $product['id']);

                        if ($user_is_logged_in) {
                            $action_button_text = ((int)($product['count'] ?? 0) > 0) ? 'ADD TO CART' : 'OUT OF STOCK';
                            $action_button_disabled = ((int)($product['count'] ?? 0) <= 0);
                            $action_button_js_action = "openQuantityModal(".$product['id'].", '".htmlspecialchars(addslashes($product['product']))."', ".(int)($product['count'] ?? 0).")";
                        } else {
                            $action_button_text = ((int)($product['count'] ?? 0) > 0) ? 'LOGIN TO PURCHASE' : 'OUT OF STOCK';
                            $action_button_disabled = ((int)($product['count'] ?? 0) <= 0);
                            $action_button_js_action = "window.location.href='".$login_redirect_with_product."'";
                        }
                    ?>
                    <button onclick="<?php echo $action_button_js_action; ?>"
                            class="w-full action-button-details open-quantity-modal-btn"
                            data-product-id="<?php echo $product['id']; ?>"
                            data-product-name="<?php echo htmlspecialchars($product['product']); ?>"
                            data-product-stock="<?php echo (int)($product['count'] ?? 0); ?>"
                            <?php if ($action_button_disabled) echo 'disabled'; ?>>
                        <i class="fas fa-cart-plus mr-2" aria-hidden="true"></i> <?php echo $action_button_text; ?>
                    </button>

                    <div class="mt-6 border-t border-gray-700 pt-6">
                        <h4 class="text-lg font-semibold text-white mb-3">Specifications:</h4>
                        <ul class="text-sm text-gray-400 space-y-1">
                            <li>ID: #<?php echo htmlspecialchars($product['id']); ?></li>
                            <li>Category: <?php echo htmlspecialchars($category_name); ?></li>
                            <li>Material: Quantum Alloy (Placeholder)</li>
                            <li>Dimensions: Variable (Placeholder)</li>
                        </ul>
                    </div>
                </div>
            </div>

            <?php if (!empty($related_products)): ?>
            <section class="mt-16 md:mt-24">
                <h2 class="text-3xl font-orbitron font-bold text-white mb-8 text-center scroll-animate fade-in-up">
                    Related <span class="neon-accent-text">Designs</span>
                </h2>
                <div class="swiper relatedProductsSwiper">
                    <div class="swiper-wrapper pb-12">
                        <?php foreach ($related_products as $related_product): ?>
                        <div class="swiper-slide h-auto"> <div class="product-card-grid h-full"> <div class="image">
                                    <a href="product_details.php?product_id=<?php echo $related_product['id']; ?><?php echo $user_is_logged_in ? '&user_id='.$current_page_user_id : ''; ?>" aria-label="View details for <?php echo htmlspecialchars($related_product['title']); ?>">
                                    <img src="<?php echo htmlspecialchars($related_product['image_url'] ?: 'https://placehold.co/300x220/111111/cccccc?text=Related'); ?>"
                                         alt="<?php echo htmlspecialchars($related_product['title']); ?>"
                                         onerror="this.onerror=null;this.src='https://placehold.co/300x220/111111/cccccc?text=Error';">
                                    </a>
                                </div>
                                <div class="card-content">
                                    <h3 class="name truncate" title="<?php echo htmlspecialchars($related_product['title']); ?>">
                                        <a href="product_details.php?product_id=<?php echo $related_product['id']; ?><?php echo $user_is_logged_in ? '&user_id='.$current_page_user_id : ''; ?>" class="hover:text-cyan-400 transition-colors">
                                            <?php echo htmlspecialchars($related_product['title']); ?>
                                        </a>
                                    </h3>
                                    <p class="price-text">DZ<?php echo number_format((float)($related_product['price'] ?? 0), 2); ?></p>
                                    <div class="button mt-auto">
                                        <a href="product_details.php?product_id=<?php echo $related_product['id']; ?><?php echo $user_is_logged_in ? '&user_id='.$current_page_user_id : ''; ?>"
                                           class="action-button text-xs">
                                            View Details
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="swiper-pagination related-swiper-pagination mt-8 relative"></div>
                </div>
            </section>
            <?php endif; ?>

        <?php else: ?>
            <div class="text-center py-20 min-h-[60vh] flex flex-col items-center justify-center">
                <i class="fas fa-ghost fa-5x text-gray-700 mb-6" aria-hidden="true"></i>
                <h1 class="text-4xl font-orbitron text-white mb-4">Product Not Found</h1>
                <p class="text-gray-400 mb-8 max-w-md">The dimension you're looking for seems to be... elsewhere. Or it never existed in this reality.</p>
                <a href="login.php<?php echo $user_is_logged_in ? '?user_id='.$current_page_user_id : ''; ?>" class="return-button text-lg">
                    <i class="fas fa-home mr-2" aria-hidden="true"></i> Back to MuscleMafia Portal
                </a>
            </div>
        <?php endif; ?>
    </main>

    <div id="quantityModal" class="modal-backdrop">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 id="quantityModalTitle" class="text-xl font-semibold text-white">Select Quantity</h3>
                <button id="closeQuantityModal" class="text-gray-300 hover:text-white text-2xl" aria-label="Close quantity selection modal">&times;</button>
            </div>
            <p class="text-sm text-gray-400 mb-1">Product: <span id="modalProductName" class="font-medium text-gray-200"></span></p>
            <p class="text-sm text-gray-400 mb-4">Available Stock: <span id="modalProductStock" class="font-medium text-gray-200"></span></p>
            <div class="flex items-center justify-center mb-6">
                <button type="button" id="modalMinusQty" class="modal-quantity-btn" aria-label="Decrease quantity">-</button>
                <input type="number" id="modalQuantityInput" value="1" min="1" class="modal-quantity-input" aria-label="Selected quantity">
                <button type="button" id="modalPlusQty" class="modal-quantity-btn" aria-label="Increase quantity">+</button>
            </div>
            <input type="hidden" id="modalProductId">
            <div class="flex justify-end space-x-3">
                <button type="button" id="cancelQuantityModal" class="bg-gray-600 hover:bg-gray-500 text-white py-2 px-4 rounded-md font-medium transition-colors">Cancel</button>
                <button type="button" id="confirmAddToCartBtnModal" class="action-button-details !py-2 !px-4 !text-sm !bg-sky-500 hover:!bg-sky-600 !border-sky-500 hover:!border-sky-600 !text-white">
                    <i class="fas fa-check mr-2"></i>Confirm & Add
                </button>
            </div>
        </div>
    </div>

    <div id="toast-container"></div>

    <footer class="footer-bg text-gray-400 text-center py-12 md:py-16 border-t border-gray-800 mt-16">
        <div class="container mx-auto px-4">
            <a href="login.php<?php echo $user_is_logged_in ? '?user_id='.$current_page_user_id : ''; ?>" class="text-3xl font-orbitron font-bold tracking-wider text-white" aria-label="MuscleMafia Homepage">
                Muscle<span class="neon-accent-text">Mafia</span>
            </a>
            <p class="mt-4 mb-6 text-sm max-w-md mx-auto">Crafting the future of living, one immersive experience at a time.</p>
            <div class="flex justify-center space-x-6 mb-6">
                <a href="#" class="hover:text-cyan-400 transition-colors" aria-label="MuscleMafia on Instagram"><i class="fab fa-instagram fa-2x" aria-hidden="true"></i></a>
                <a href="#" class="hover:text-cyan-400 transition-colors" aria-label="MuscleMafia on Twitter"><i class="fab fa-twitter fa-2x" aria-hidden="true"></i></a>
                <a href="#" class="hover:text-cyan-400 transition-colors" aria-label="MuscleMafia on Pinterest"><i class="fab fa-pinterest fa-2x" aria-hidden="true"></i></a>
            </div>
            <p class="text-xs">&copy; <?php echo date("Y"); ?> MuscleMafia Industries. All rights reserved. Dare to dream differently.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/swiper/swiper-bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        // Mobile Menu
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
        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    obs.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1 });
        animatedElements.forEach(el => { observer.observe(el); });

        // Related Products Swiper
        const relatedSwiperElement = document.querySelector('.relatedProductsSwiper');
        if (relatedSwiperElement && typeof Swiper !== 'undefined') {
            try {
                if (relatedSwiperElement.querySelectorAll('.swiper-slide').length > 0) {
                    new Swiper(".relatedProductsSwiper", {
                        slidesPerView: 1.5, spaceBetween: 15,
                        grabCursor: true,
                        pagination: { el: ".related-swiper-pagination", clickable: true, dynamicBullets: true },
                        breakpoints: {
                            640: { slidesPerView: 2.5, spaceBetween: 20 },
                            768: { slidesPerView: 3.5, spaceBetween: 20 },
                            1024: { slidesPerView: 4, spaceBetween: 25 }, // Show more on larger screens
                        }
                    });
                }
            } catch (e) { console.error("Related Products Swiper init error:", e); }
        }

        // --- Quantity Modal & Add to Cart Logic ---
        const quantityModal = document.getElementById('quantityModal');
        const closeQuantityModalBtn = document.getElementById('closeQuantityModal');
        const cancelQuantityModalBtn = document.getElementById('cancelQuantityModal');
        const confirmAddToCartBtn = document.getElementById('confirmAddToCartBtnModal');
        const modalProductNameEl = document.getElementById('modalProductName');
        const modalProductStockEl = document.getElementById('modalProductStock');
        const modalQuantityInput = document.getElementById('modalQuantityInput');
        const modalProductIdInput = document.getElementById('modalProductId');
        const modalMinusQtyBtn = document.getElementById('modalMinusQty');
        const modalPlusQtyBtn = document.getElementById('modalPlusQty');
        const cartBadgeHeader = document.getElementById('cartItemCountBadgeHeader');

        const userIsLoggedIn = <?php echo json_encode($user_is_logged_in); ?>;
        const currentUserIdForCart = <?php echo json_encode($current_page_user_id); ?>;
        const loginRedirectBaseUrl = "<?php echo $login_page_url; ?>";
        // IMPORTANT: Ensure this path is correct for your server setup.
        const addToCartAjaxUrl = '/user_interface/add_to_cart_ajax.php';


        function showToast(message, isError = false) {
            const toastContainer = document.getElementById('toast-container');
            if (!toastContainer) return;
            const toast = document.createElement('div');
            toast.className = 'toast-notification' + (isError ? ' error' : '');
            toast.textContent = message;
            toastContainer.appendChild(toast);
            setTimeout(() => { toast.classList.add('show'); }, 10); // Delay for transition
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => { toast.remove(); }, 300); // Remove after transition
            }, 3000);
        }

        window.openQuantityModal = function(productId, productName, productStock) { // Make it global for inline onclick
            if (!userIsLoggedIn) {
                const redirectUrl = `${loginRedirectBaseUrl}${loginRedirectBaseUrl.includes('?') ? '&' : '?'}redirect=${encodeURIComponent(window.location.pathname + window.location.search)}`;
                window.location.href = redirectUrl;
                return;
            }
            if (!quantityModal || !modalProductIdInput || !modalProductNameEl || !modalProductStockEl || !modalQuantityInput || !confirmAddToCartBtn) {
                console.error("Modal elements not found!"); return;
            }
            modalProductIdInput.value = productId;
            modalProductNameEl.textContent = productName;
            modalProductStockEl.textContent = productStock;
            modalQuantityInput.value = 1;
            modalQuantityInput.max = productStock;
            confirmAddToCartBtn.disabled = (productStock <= 0);
            modalPlusQtyBtn.disabled = (productStock <= 1 && productStock > 0) || productStock <= 0; // Disable + if stock is 1 or 0
            modalMinusQtyBtn.disabled = true; // Initially disable minus as qty is 1

            quantityModal.classList.add('active');
        }

        function closeQuantityModal() {
            if (quantityModal) quantityModal.classList.remove('active');
        }

        if(closeQuantityModalBtn) closeQuantityModalBtn.addEventListener('click', closeQuantityModal);
        if(cancelQuantityModalBtn) cancelQuantityModalBtn.addEventListener('click', closeQuantityModal);

        function updateModalQuantityButtonsState() {
            if (!modalQuantityInput || !modalPlusQtyBtn || !modalMinusQtyBtn) return;
            const currentValue = parseInt(modalQuantityInput.value);
            const maxStock = parseInt(modalQuantityInput.max);
            modalMinusQtyBtn.disabled = currentValue <= 1;
            modalPlusQtyBtn.disabled = currentValue >= maxStock;
        }

        if(modalMinusQtyBtn && modalQuantityInput) {
            modalMinusQtyBtn.addEventListener('click', () => {
                let currentValue = parseInt(modalQuantityInput.value);
                if (currentValue > 1) modalQuantityInput.value = currentValue - 1;
                updateModalQuantityButtonsState();
            });
        }
        if(modalPlusQtyBtn && modalQuantityInput) {
            modalPlusQtyBtn.addEventListener('click', () => {
                let currentValue = parseInt(modalQuantityInput.value);
                const maxStock = parseInt(modalQuantityInput.max);
                if (currentValue < maxStock) modalQuantityInput.value = currentValue + 1;
                updateModalQuantityButtonsState();
            });
        }
        if(modalQuantityInput){
            modalQuantityInput.addEventListener('input', () => { // Use 'input' for immediate feedback
                let currentValue = parseInt(modalQuantityInput.value);
                const maxStock = parseInt(modalQuantityInput.max);
                if (isNaN(currentValue) || currentValue < 1) {
                    modalQuantityInput.value = 1;
                } else if (currentValue > maxStock && maxStock > 0) {
                    modalQuantityInput.value = maxStock;
                } else if (maxStock === 0 && currentValue > 0 ) {
                     modalQuantityInput.value = 0; // Or 1 if you prefer, but 0 makes sense for OOS
                }
                updateModalQuantityButtonsState();
            });
        }

        // Attach to main product page button if it exists (it's dynamically generated by PHP)
        // The onclick is directly in PHP, but if you prefer JS attachment:
        // document.querySelector('.action-button-details.open-quantity-modal-btn')?.addEventListener('click', function() { ... });

        if (confirmAddToCartBtn) {
            confirmAddToCartBtn.addEventListener('click', function() {
                if (!userIsLoggedIn) { // Should be caught earlier, but good for safety
                    const redirectUrl = `${loginRedirectBaseUrl}${loginRedirectBaseUrl.includes('?') ? '&' : '?'}redirect=${encodeURIComponent(window.location.pathname + window.location.search)}`;
                    window.location.href = redirectUrl;
                    return;
                }

                const productId = modalProductIdInput.value;
                const quantity = parseInt(modalQuantityInput.value);
                const buttonReference = this;

                if (!productId || !currentUserIdForCart || isNaN(quantity) || quantity < 1) {
                    showToast('Invalid product data or quantity selected.', true); return;
                }
                if (quantity > parseInt(modalProductStockEl.textContent)) {
                    showToast('Requested quantity exceeds available stock.', true); return;
                }

                buttonReference.disabled = true;
                const originalButtonHTML = buttonReference.innerHTML;
                buttonReference.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Adding...';

                fetch(addToCartAjaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', },
                    body: `user_id=${encodeURIComponent(currentUserIdForCart)}&product_id=${encodeURIComponent(productId)}&quantity=${encodeURIComponent(quantity)}`
                })
                .then(response => {
                    if (!response.ok) { // Check for HTTP errors
                        return response.text().then(text => { throw new Error('Network error: ' + response.status + " - " + text); });
                    }
                    return response.json(); // Try to parse JSON
                })
                .then(data => {
                    if (data.success) {
                        if (cartBadgeHeader) {
                            cartBadgeHeader.textContent = data.new_cart_total_items;
                            cartBadgeHeader.classList.toggle('hidden', data.new_cart_total_items === 0);
                        }
                        showToast(data.message || 'Item(s) added to cart successfully!');
                        closeQuantityModal();

                        // Update stock display on the main product page
                        const mainProductStockTextEl = document.querySelector('.stock-text .font-semibold');
                        const mainProductButtonEl = document.querySelector('.action-button-details.open-quantity-modal-btn[data-product-id="' + productId + '"]');

                        if (mainProductStockTextEl && mainProductButtonEl) {
                            const currentStockOnPage = parseInt(mainProductButtonEl.dataset.productStock);
                            const newStockOnPage = currentStockOnPage - quantity;

                            mainProductStockTextEl.textContent = newStockOnPage > 0 ? `${newStockOnPage} in stock` : 'Out of Stock';
                            mainProductStockTextEl.parentElement.classList.toggle('text-red-500', newStockOnPage <= 0);
                            mainProductStockTextEl.parentElement.classList.toggle('text-green-400', newStockOnPage > 0);
                            mainProductButtonEl.dataset.productStock = newStockOnPage; // Update data attribute

                            if (newStockOnPage <= 0) {
                                mainProductButtonEl.innerHTML = '<i class="fas fa-times-circle mr-2"></i>OUT OF STOCK';
                                mainProductButtonEl.disabled = true;
                            }
                        }
                    } else {
                        showToast(data.message || 'Could not add item(s) to cart. Please try again.', true);
                    }
                })
                .catch(error => {
                    console.error('Add to Cart Fetch Error:', error);
                    showToast('An error occurred while adding to cart. ' + error.message, true);
                })
                .finally(() => {
                    // Only re-enable if stock > 0. If it became 0, it's handled above.
                    const finalStock = parseInt(modalProductStockEl.textContent) - quantity;
                    if (finalStock > 0) {
                         buttonReference.disabled = false;
                    }
                    buttonReference.innerHTML = originalButtonHTML;
                });
            });
        }
    });
    </script>
</body>
</html>
<?php
if (isset($conn) && is_object($conn) && method_exists($conn, 'close')) {
    $conn->close();
}
?>
