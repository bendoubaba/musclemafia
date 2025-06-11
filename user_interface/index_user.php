<?php
// Establish the database connection at the beginning
include("database.php"); // Ensure this file uses mysqli and sets up $conn

$user_id = "";
$display_name = "Guest"; // Default name

if (isset($_GET['user_id']) && filter_var($_GET['user_id'], FILTER_VALIDATE_INT)) {
    $user_id = (int) $_GET['user_id'];

    $stmt_user_check = $conn->prepare("SELECT id, username FROM users WHERE id = ?");
    if ($stmt_user_check) {
        $stmt_user_check->bind_param("i", $user_id);
        $stmt_user_check->execute();
        $result_user_check = $stmt_user_check->get_result();
        if ($result_user_check->num_rows > 0) {
            $user_data = $result_user_check->fetch_assoc();
            $display_name = htmlspecialchars($user_data['username']);
        } else {
            header("Location: /login.php?error=invaliduser");
            exit;
        }
        $stmt_user_check->close();
    } else {
        error_log("Error preparing user check statement: " . $conn->error);
        header("Location: /login.php?error=db_error");
        exit;
    }
} else {
    header("Location: /login.php");
    exit;
}

// Get cart item count (SUM of quantities, assuming 'cart' table has 'quantity' column)
$num_total_items_in_cart = 0;
// Ensure you have added 'quantity' column to your cart table
// If cart table does NOT have quantity, this should be COUNT(id)
$sql_cart_total_items = "SELECT SUM(quantity) AS total_items FROM cart WHERE user_id = ?"; 
$stmt_cart_total_items = $conn->prepare($sql_cart_total_items);
if ($stmt_cart_total_items) {
    $stmt_cart_total_items->bind_param("i", $user_id);
    $stmt_cart_total_items->execute();
    $result_cart_total_items = $stmt_cart_total_items->get_result();
    if ($row_cart_total = $result_cart_total_items->fetch_assoc()) {
        $num_total_items_in_cart = (int)($row_cart_total['total_items'] ?? 0);
    }
    $stmt_cart_total_items->close();
} else {
    error_log("Error preparing cart total items statement: " . $conn->error);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MuscleMafia Shop - <?php echo $display_name; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="shortcut icon" href="/access/img/logo_bw.png" type="image/png"> <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper/swiper-bundle.min.css" />
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #111827; color: #D1D5DB; }
        .header-bg { background-color: #000000; }
        .logo snap { color: #FFFFFF; }
        .nav-icons a { margin-left: 1rem; color: #D1D5DB; text-decoration: none; }
        .nav-icons a:hover { color: #FFFFFF; }
        .badge {
            position: absolute; top: -8px; right: -8px; padding: 1px 5px;
            border-radius: 50%; background-color: #FFFFFF; color: #000000;
            font-size: 0.7rem; font-weight: 600; border: 1px solid #000000;
            min-width: 18px; text-align: center; line-height: normal;
        }
        .nav-icons .fa-shopping-cart { position: relative; }
        
        .product-card-grid {
            background: #1F2937; border: 1px solid #374151;
            border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            overflow: hidden; color: #D1D5DB; display: flex; flex-direction: column;
        }
        .product-card-grid .image img { 
            width: 100%; height: 180px; object-fit: cover; 
            filter: grayscale(50%); transition: filter 0.3s ease; 
        }
        .product-card-grid:hover .image img { filter: grayscale(0%); }
        .product-card-grid .card-content { padding: 1rem; flex-grow: 1; display: flex; flex-direction: column; }
        .product-card-grid .name { font-size: 1.1rem; font-weight: 600; display: block; margin-bottom: 0.5rem; color: #F9FAFB; }
        .product-card-grid .description-text { 
            font-size: 0.8rem; color: #9CA3AF; display: block; margin-bottom: 0.5rem; 
            min-height: 3.6em; 
            line-height: 1.2em; overflow: hidden;
            display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical;
        }
        .product-card-grid .stock-text { font-size: 0.75rem; color: #6ee7b7; margin-bottom: 0.75rem; }
        .product-card-grid .stock-text.out-of-stock { color: #fca5a5; }
        .product-card-grid .price-text { font-size: 1.25rem; font-weight: bold; color: #FFFFFF; margin-bottom: 1rem; }
        .product-card-grid .button .open-quantity-modal-btn {
            background-color: #FFFFFF; color: #000000;
            border: 1px solid #FFFFFF; padding: 0.625rem 1rem; border-radius: 5px;
            cursor: pointer; width: 100%; text-align: center; font-weight: 500;
            transition: background-color 0.2s ease, color 0.2s ease, opacity 0.2s ease;
            text-decoration: none; display: block; margin-top: auto;
        }
        .product-card-grid .button .open-quantity-modal-btn:hover:not(:disabled) {
            background-color: #000000; color: #FFFFFF;
        }
        .product-card-grid .button .open-quantity-modal-btn:disabled {
            opacity: 0.5; cursor: not-allowed; background-color: #4B5563; color: #9CA3AF; border-color: #4B5563;
        }

        .carousel__item-details .open-quantity-modal-btn { 
             background-color: #FFFFFF; color: #111827; padding: 8px 15px; border-radius: 5px; 
             text-decoration: none; font-weight: 600;
             transition: background-color 0.2s ease, transform 0.2s ease, opacity 0.2s ease;
        }
        .carousel__item-details .open-quantity-modal-btn:hover:not(:disabled) { background-color: #E5E7EB; transform: translateY(-1px); }
        .carousel__item-details .open-quantity-modal-btn:disabled { opacity: 0.5; cursor: not-allowed; background-color: #4B5563; color: #9CA3AF;}

        .carousel { 
            position: relative; width: 100%; max-width: 1200px; margin: 20px auto; overflow: hidden;
            border-radius: 8px; box-shadow: 0 8px 16px rgba(0,0,0,0.2); border: 1px solid #374151;
        }
        .carousel__items { /* This is the sliding container */
            display: flex; 
            transition: transform 0.5s ease-in-out; 
        }
        .carousel__item { /* Each individual slide */
            min-width: 100%; 
            position: relative;
        }
        .carousel__item img { width: 100%; height: 400px; object-fit: cover; display: block; filter: grayscale(30%); }
        .carousel__item-content {
            position: absolute; bottom: 0; left: 0; right: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.9) 0%, rgba(0,0,0,0.7) 50%, rgba(0,0,0,0) 100%);
            color: white; padding: 30px 20px;
        }
        .carousel__item-title { font-size: 2rem; font-weight: bold; margin-bottom: 0.5rem; text-shadow: 1px 1px 3px rgba(0,0,0,0.7); }
        .carousel__item-description { font-size: 1rem; margin-bottom: 1rem; color: #E5E7EB; }
        .carousel__nav { position: absolute; bottom: 15px; left: 50%; transform: translateX(-50%); display: flex; }
        .carousel__button {
            width: 10px; height: 10px; border-radius: 50%; background: rgba(255, 255, 255, 0.4);
            margin: 0 5px; cursor: pointer; border: 1px solid rgba(0,0,0,0.2); transition: background-color 0.3s ease;
        }
        .carousel__button--selected { background: white; }
        
        .category-title-bg { background-color: #1F2937; padding: 0.75rem 1.5rem; border-bottom: 1px solid #374151; }
        .category-title-text { color: #F9FAFB; }

        #liveSearchResults { background-color: #1F2937; border-color: #374151; color: #D1D5DB; }
        #liveSearchResults li:hover { background-color: #374151; }
        #liveSearchResults a { color: #D1D5DB; }
        #liveSearchResults img { border: 1px solid #4B5563; }

        a { transition: color 0.2s ease-in-out; }
        .footer-bg { background-color: #000000; }
        .toast-notification {
            position: fixed; bottom: 20px; right: 20px;
            background-color: #22c55e; color: white;
            padding: 12px 20px; border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 10001;
            opacity: 0; transition: opacity 0.3s ease-in-out, transform 0.3s ease-in-out;
            transform: translateY(20px);
        }
        .toast-notification.show { opacity: 1; transform: translateY(0); }
        .toast-notification.error { background-color: #ef4444; }

        .modal-backdrop {
            position: fixed; inset: 0; background-color: rgba(0,0,0,0.75);
            display: flex; align-items: center; justify-content: center;
            z-index: 10000; opacity: 0; pointer-events: none; transition: opacity 0.3s ease-in-out;
        }
        .modal-backdrop.active { opacity: 1; pointer-events: auto; }
        .modal-content {
            background-color: #1F2937; 
            padding: 1.5rem; border-radius: 0.5rem; box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            width: 90%; max-width: 400px;
            transform: scale(0.95); transition: transform 0.3s ease-in-out;
        }
        .modal-backdrop.active .modal-content { transform: scale(1); }
        .modal-quantity-input {
            width: 60px; text-align: center; margin: 0 0.75rem;
            background-color: #374151; color: #F9FAFB; border: 1px solid #4B5563;
            border-radius: 4px; padding: 0.5rem;
            -moz-appearance: textfield; 
        }
        .modal-quantity-input::-webkit-outer-spin-button,
        .modal-quantity-input::-webkit-inner-spin-button {
            -webkit-appearance: none; margin: 0;
        }
        .modal-quantity-btn {
            background-color: #4B5563; color: #F9FAFB; border: none;
            width: 36px; height: 36px; border-radius: 4px;
            font-weight: bold; cursor: pointer; font-size: 1.25rem; line-height: 1;
        }
    </style>
</head>

<body class="antialiased">
    <header class="header-bg text-gray-300 shadow-lg sticky top-0 z-50">
        <div class="nav container mx-auto flex items-center justify-between p-4">
            <div class="grid-2 pr-4 ">
                <a href="index_user.php?user_id=<?php echo $user_id; ?>" class="logo text-2xl font-bold">Muscle<snap>Mafia</snap></a>
                <div class="text-xs md:text-sm">Welcome, <span class="font-semibold text-white"><?php echo $display_name; ?></span></div>
            </div>
            <div class="relative mx-2 flex-grow max-w-xs md:max-w-sm">
                <input type="text" id="liveSearchInput"
                    class="bg-gray-800 border border-gray-700 text-white placeholder-gray-500 text-sm rounded-md py-2 px-4 w-full focus:ring-2 focus:ring-gray-500 focus:border-transparent outline-none"
                    placeholder="Search products...">
                <div id="liveSearchResults"
                    class="absolute top-full left-0 right-0 mt-1 border border-gray-700 rounded-b-md shadow-lg z-[100] hidden max-h-80 overflow-y-auto">
                </div>
            </div>
            <div class="nav-icons flex items-center space-x-3 md:space-x-4">
                <a href="/user_interface/update_user.php?user_id=<?php $_SESSION['user_id']=$user_id;  echo $user_id; ?>" class="hover:text-white transition-colors text-sm" title="Profile">
                    <i class="fas fa-user-edit"></i><span class="hidden md:inline ml-1">Profile</span>
                </a>
                <a href="/login.php" class="hover:text-white transition-colors text-sm" title="Log out">
                    <i class="fas fa-sign-out-alt"></i><span class="hidden md:inline ml-1">Logout</span>
                </a>
                <a href="/user_interface/card.php?id=<?php echo $user_id; ?>" class="relative hover:text-white transition-colors" title="Shopping Cart">
                    <i class="fas fa-shopping-cart fa-lg"></i>
                    <span class="badge <?php echo ($num_total_items_in_cart == 0) ? 'hidden' : ''; ?>" id="cartItemCountBadge"><?php echo $num_total_items_in_cart; ?></span>
                </a>
            </div>
        </div>
    </header>

    <main class="pb-3">
        <?php
        // Hero Carousel: Corrected SQL to include price and stock_count
        $hero_sql = "SELECT id, product AS title, description, price, picture AS image_url, `count` as stock_count 
                     FROM products 
                     ORDER BY RAND() LIMIT 5";
        $hero_result = $conn->query($hero_sql);
        ?>
        <?php if ($hero_result && $hero_result->num_rows > 0): ?>
            <div class="carousel my-4 md:my-6">
                <div class="carousel__items">
                    <?php while ($hero_row = $hero_result->fetch_assoc()): ?>
                        <div class="carousel__item">
                            <img src="<?php echo htmlspecialchars($hero_row['image_url'] ? $hero_row['image_url'] : 'https://placehold.co/1200x400/1F2937/4B5563?text=MuscleMafia+Promotion'); ?>"
                                alt="<?php echo htmlspecialchars($hero_row['title']); ?>"
                                onerror="this.onerror=null;this.src='https://placehold.co/1200x400/1F2937/4B5563?text=MuscleMafia+Promotion';">
                            <div class="carousel__item-content">
                                <h2 class="carousel__item-title"><?php echo htmlspecialchars($hero_row['title']); ?></h2>
                                <p class="carousel__item-description hidden sm:block">
                           v         <?php echo htmlspecialchars(substr($hero_row['description'], 0, 120)); ?>...</p>
                                <ul class="carousel__item-details mt-2 flex items-center space-x-4">
                                    <li class="font-semibold text-lg">DZ<?php echo number_format((float)($hero_row['price'] ?? 0), 2); ?></li>
                                     <li class="text-sm <?php echo ((int)($hero_row['stock_count'] ?? 0) > 0) ? 'text-green-400' : 'text-red-400'; ?>">
                                        Stock: <?php echo ((int)($hero_row['stock_count'] ?? 0) > 0) ? htmlspecialchars($hero_row['stock_count']) : 'Out'; ?>
                                    </li>
                                    <li>
                                        <button class="btn-carousel-add open-quantity-modal-btn"
                                            data-product-id="<?php echo $hero_row['id']; ?>"
                                            data-product-name="<?php echo htmlspecialchars($hero_row['title']); ?>"
                                            data-product-stock="<?php echo (int)($hero_row['stock_count'] ?? 0); ?>"
                                            <?php echo ((int)($hero_row['stock_count'] ?? 0) <= 0) ? 'disabled' : ''; ?>>
                                            <i class="fas fa-cart-plus mr-1"></i><?php echo ((int)($hero_row['stock_count'] ?? 0) > 0) ? 'ADD TO CART' : 'OUT OF STOCK'; ?>
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
                </div>
        <?php endif; ?>

        <div class="categories-slider-section mt-8 max-w-7xl mx-auto px-4">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-2xl font-semibold text-gray-100">Shop by Category</h2>
                <button id="showAllProductsBtn" class="text-sm text-sky-400 hover:text-sky-300 focus:outline-none">View All Products</button>
            </div>
            <?php
            $cat_sql = "SELECT id, name, ico_img FROM categories ORDER BY name";
            $cat_result = $conn->query($cat_sql);
            if ($cat_result && $cat_result->num_rows > 0) {
            ?>
            <div class="swiper categorySwiper container">
                <div class="swiper-wrapper py-4">
                    <?php while ($category = $cat_result->fetch_assoc()): ?>
                        <div class="swiper-slide bg-gray-800 rounded-lg p-4 text-center hover:bg-gray-700 transition-colors cursor-pointer category-slide-item" data-category-id="<?php echo $category['id']; ?>" data-category-name="<?php echo htmlspecialchars($category['name']); ?>">
                            <?php if ($category['ico_img']): ?>
                                <img src="<?php echo htmlspecialchars($category['ico_img']); ?>" alt="<?php echo htmlspecialchars($category['name']); ?>" class="w-12 h-12 mx-auto mb-2 object-contain"  onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                <i class="fas fa-tag text-3xl mx-auto mb-2 hidden"></i>
                            <?php else: ?>
                                <i class="fas fa-tag text-3xl mx-auto mb-2"></i>
                            <?php endif; ?>
                            <h3 class="font-medium text-white text-sm truncate"><?php echo htmlspecialchars($category['name']); ?></h3>
                        </div>
                    <?php endwhile; ?>
                </div>
                <div class="swiper-pagination category-swiper-pagination mt-4 relative"></div>
            </div>
            <?php
            } else {
                 echo "<p class='text-gray-500 text-center py-4'>No categories found.</p>";
            }
            ?>
        </div>

        <div class="all-products-section mt-12 max-w-7xl mx-auto p-4">
            <h2 class="text-3xl font-semibold mb-6 pb-3 text-gray-100 border-b-2 border-gray-700" id="allProductsTitle">All Products</h2>
             <?php
            $sql_all_products = "SELECT id, product AS title, description, price, picture AS image_url, category_id, `count` as stock_count FROM products ORDER BY product";
            $result_all_products = $conn->query($sql_all_products);
            ?>
            <?php if ($result_all_products && $result_all_products->num_rows > 0): ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6" id="productsGrid">
                    <?php while ($product = $result_all_products->fetch_assoc()): ?>
                        <div class="product-card-grid" data-product-id="<?php echo $product['id']; ?>" data-category-id="<?php echo $product['category_id']; ?>" data-stock-count="<?php echo (int)$product['stock_count']; ?>">
                            <div class="image">
                                <img src="<?php echo htmlspecialchars($product['image_url'] ? $product['image_url'] : 'https://placehold.co/300x180/374151/9CA3AF?text=MuscleMafia'); ?>" 
                                     alt="<?php echo htmlspecialchars($product['title']); ?>"
                                     onerror="this.onerror=null;this.src='https://placehold.co/300x180/374151/9CA3AF?text=MuscleMafia';">
                            </div>
                            <div class="card-content">
                                <h3 class="name truncate product-name" title="<?php echo htmlspecialchars($product['title']); ?>"><?php echo htmlspecialchars($product['title']); ?></h3>
                                <p class="description-text"><?php echo htmlspecialchars(substr($product['description'], 0, 70)); ?>...</p>
                                <p class="price-text">DZ<?php echo number_format((float)($product['price'] ?? 0), 2); ?></p>
                                <p class="stock-text <?php echo ((int)($product['stock_count'] ?? 0) > 0) ? '' : 'out-of-stock'; ?>">
                                    Available: <span class="stock-value"><?php echo ((int)($product['stock_count'] ?? 0) > 0) ? htmlspecialchars($product['stock_count']) : 'Out of Stock'; ?></span>
                                </p>
                                <div class="button">
                                    <button class="open-quantity-modal-btn" 
                                            data-product-id="<?php echo $product['id']; ?>"
                                            data-product-name="<?php echo htmlspecialchars($product['title']); ?>"
                                            data-product-stock="<?php echo (int)($product['stock_count'] ?? 0); ?>"
                                            <?php echo ((int)($product['stock_count'] ?? 0) <= 0) ? 'disabled' : ''; ?>>
                                        <i class="fas fa-cart-plus mr-2"></i><?php echo ((int)($product['stock_count'] ?? 0) > 0) ? 'ADD TO CART' : 'OUT OF STOCK'; ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <p class='text-xl text-center text-gray-500 py-10'>No products found at the moment. Please check back later!</p>
            <?php endif; ?>
        </div>
    </main>

    <div id="quantityModal" class="modal-backdrop">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 id="quantityModalTitle" class="text-xl font-semibold text-white">Select Quantity</h3>
                <button id="closeQuantityModal" class="text-gray-300 hover:text-white text-2xl">&times;</button>
            </div>
            <p class="text-sm text-gray-400 mb-1">Product: <span id="modalProductName" class="font-medium text-gray-200"></span></p>
            <p class="text-sm text-gray-400 mb-4">Available Stock: <span id="modalProductStock" class="font-medium text-gray-200"></span></p>
            
            <div class="flex items-center justify-center mb-6">
                <button type="button" id="modalMinusQty" class="modal-quantity-btn">-</button>
                <input type="number" id="modalQuantityInput" value="1" min="1" class="modal-quantity-input">
                <button type="button" id="modalPlusQty" class="modal-quantity-btn">+</button>
            </div>
            <input type="hidden" id="modalProductId">

            <div class="flex justify-end space-x-3">
                <button type="button" id="cancelQuantityModal" class="bg-gray-600 hover:bg-gray-500 text-white py-2 px-4 rounded-md font-medium">Cancel</button>
                <button type="button" id="confirmAddToCartBtn" class="bg-sky-600 hover:bg-sky-700 text-white py-2 px-4 rounded-md font-medium">Add to Cart</button>
            </div>
        </div>
    </div>

    <footer class="footer-bg text-gray-400 text-center p-6 mt-12 border-t border-gray-700">
        <p>&copy; <?php echo date("Y"); ?> MuscleMafia. All rights reserved.</p>
        <p class="text-xs">Train hard. Lift heavy. No filters. Just black and white.</p>
    </footer>

    <div id="toast-container" class="fixed bottom-5 right-5 z-[10001] space-y-2"></div>

    <script src="https://cdn.jsdelivr.net/npm/swiper/swiper-bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        try {
            if (document.querySelector('.categorySwiper .swiper-slide')) {
                new Swiper(".categorySwiper", {
                    slidesPerView: 2.5, spaceBetween: 10,
                    loop: false, grabCursor: true,
                    pagination: { el: ".category-swiper-pagination", clickable: true, dynamicBullets: true, },
                    breakpoints: {
                        640: { slidesPerView: 3.5, spaceBetween: 15 },
                        768: { slidesPerView: 4.5, spaceBetween: 15 },
                        1024: { slidesPerView: 6.5, spaceBetween: 20 },
                        1280: { slidesPerView: 8.5, spaceBetween: 20 }
                    }
                });
            }
        } catch (e) { console.error("Category Swiper init error:", e); }
        
        document.querySelectorAll(".carousel").forEach((carousel) => {
            try {
                const itemsContainer = carousel.querySelector(".carousel__items");
                const items = carousel.querySelectorAll(".carousel__item");
                if (!itemsContainer || items.length === 0) return;
                let buttonsHtml = '';
                if (items.length > 1) buttonsHtml = Array.from(items, () => `<span class="carousel__button"></span>`).join("");
                if (buttonsHtml) carousel.insertAdjacentHTML("beforeend", `<div class="carousel__nav">${buttonsHtml}</div>`);
                
                const buttons = carousel.querySelectorAll(".carousel__button");
                let currentIndex = 0; let intervalId;
                const changeItem = (index) => {
                    itemsContainer.style.transform = `translateX(-${index * 100}%)`;
                    if (buttons.length > 0) {
                        buttons.forEach(button => button.classList.remove("carousel__button--selected"));
                        if (buttons[index]) buttons[index].classList.add("carousel__button--selected");
                    }
                };
                const nextItem = () => { currentIndex = (currentIndex + 1) % items.length; changeItem(currentIndex); };
                const startAutoPlay = () => { if (items.length > 1) intervalId = setInterval(nextItem, 5000); };
                const stopAutoPlay = () => clearInterval(intervalId);
                if (buttons.length > 0) buttons.forEach((button, i) => button.addEventListener("click", () => { stopAutoPlay(); currentIndex = i; changeItem(currentIndex); startAutoPlay(); }));
                changeItem(currentIndex); startAutoPlay();
                carousel.addEventListener('mouseenter', stopAutoPlay);
                carousel.addEventListener('mouseleave', startAutoPlay);
            } catch (e) { console.error("Hero Carousel JS error:", e); }
        });

        const searchInput = document.getElementById('liveSearchInput');
        const searchResultsContainer = document.getElementById('liveSearchResults');
        let searchTimeout;
        const currentUserIdForSearch = <?php echo json_encode($user_id ? (int)$user_id : 0); ?>;
        if (searchInput && searchResultsContainer) {
            try {
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout); const query = this.value.trim();
                    if (query.length >= 2) {
                        searchResultsContainer.innerHTML = '<p class="p-3 text-gray-400 text-sm text-center">Searching...</p>';
                        searchResultsContainer.classList.remove('hidden');
                        searchTimeout = setTimeout(() => {
                            fetch('live_search.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'query=' + encodeURIComponent(query) + '&user_id=' + encodeURIComponent(currentUserIdForSearch)
                            })
                            .then(response => {
                                if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                                return response.text();
                            })
                            .then(data => {
                                searchResultsContainer.innerHTML = data;
                                if (data.trim() === '' || data.includes('No products found')) {
                                    if (data.trim() === '') searchResultsContainer.classList.add('hidden');
                                }
                            })
                            .catch(error => { console.error('Search Fetch Error:', error); searchResultsContainer.innerHTML = '<p class="p-3 text-red-400 text-sm text-center">Error loading results.</p>'; });
                        }, 300);
                    } else { searchResultsContainer.innerHTML = ''; searchResultsContainer.classList.add('hidden'); }
                });
                document.addEventListener('click', (event) => { if (searchInput && searchResultsContainer && !searchInput.contains(event.target) && !searchResultsContainer.contains(event.target)) searchResultsContainer.classList.add('hidden'); });
                searchInput.addEventListener('focus', () => { if (searchInput.value.trim().length >= 2 && searchResultsContainer.innerHTML.trim() !== '') searchResultsContainer.classList.remove('hidden'); });
            } catch (e) { console.error("Live Search JS error:", e); }
        }

        const cartBadge = document.getElementById('cartItemCountBadge');
        const currentUserIdForCart = <?php echo json_encode($user_id ? (int)$user_id : 0); ?>;

        function showToast(message, isError = false) {
            try {
                const toastContainer = document.getElementById('toast-container');
                if (!toastContainer) return;
                const toast = document.createElement('div');
                toast.className = 'toast-notification' + (isError ? ' error' : '');
                toast.textContent = message;
                toastContainer.appendChild(toast);
                setTimeout(() => { toast.classList.add('show'); }, 10);
                setTimeout(() => {
                    toast.classList.remove('show');
                    setTimeout(() => { toast.remove(); }, 300);
                }, 3000);
            } catch (e) { console.error("Toast display error:", e); }
        }

        const quantityModal = document.getElementById('quantityModal');
        const closeQuantityModalBtn = document.getElementById('closeQuantityModal');
        const cancelQuantityModalBtn = document.getElementById('cancelQuantityModal');
        const confirmAddToCartBtn = document.getElementById('confirmAddToCartBtn');
        const modalProductName = document.getElementById('modalProductName');
        const modalProductStock = document.getElementById('modalProductStock');
        const modalQuantityInput = document.getElementById('modalQuantityInput');
        const modalProductIdInput = document.getElementById('modalProductId');
        const modalMinusQtyBtn = document.getElementById('modalMinusQty');
        const modalPlusQtyBtn = document.getElementById('modalPlusQty');

        function openQuantityModal(productId, productName, productStock) {
            if (!quantityModal) return;
            modalProductIdInput.value = productId;
            modalProductName.textContent = productName;
            modalProductStock.textContent = productStock;
            modalQuantityInput.value = 1; 
            modalQuantityInput.max = productStock; 
            confirmAddToCartBtn.disabled = (productStock <= 0);
            quantityModal.classList.add('active');
        }

        function closeQuantityModal() {
            if (quantityModal) quantityModal.classList.remove('active');
        }

        if(closeQuantityModalBtn) closeQuantityModalBtn.addEventListener('click', closeQuantityModal);
        if(cancelQuantityModalBtn) cancelQuantityModalBtn.addEventListener('click', closeQuantityModal);
        
        if(modalMinusQtyBtn && modalQuantityInput) {
            modalMinusQtyBtn.addEventListener('click', () => {
                let currentValue = parseInt(modalQuantityInput.value);
                if (currentValue > 1) modalQuantityInput.value = currentValue - 1;
            });
        }
        if(modalPlusQtyBtn && modalQuantityInput) {
            modalPlusQtyBtn.addEventListener('click', () => {
                let currentValue = parseInt(modalQuantityInput.value);
                const maxStock = parseInt(modalQuantityInput.max);
                if (currentValue < maxStock) modalQuantityInput.value = currentValue + 1;
            });
        }
        if(modalQuantityInput){
            modalQuantityInput.addEventListener('change', () => {
                let currentValue = parseInt(modalQuantityInput.value);
                const maxStock = parseInt(modalQuantityInput.max);
                if (isNaN(currentValue) || currentValue < 1) modalQuantityInput.value = 1;
                if (currentValue > maxStock && maxStock > 0) modalQuantityInput.value = maxStock;
                else if (maxStock === 0 && currentValue > 0 ) modalQuantityInput.value = 0; // Should be handled by disabled button
            });
        }

        document.querySelectorAll('.open-quantity-modal-btn').forEach(button => {
            button.addEventListener('click', function() {
                if (this.disabled) return;
                const productId = this.dataset.productId;
                const productName = this.dataset.productName;
                const productStock = parseInt(this.dataset.productStock);
                openQuantityModal(productId, productName, productStock);
            });
        });

        if (confirmAddToCartBtn) {
            confirmAddToCartBtn.addEventListener('click', function() {
                const productId = modalProductIdInput.value;
                const quantity = parseInt(modalQuantityInput.value);
                const buttonReference = this;

                if (!productId || !currentUserIdForCart || isNaN(quantity) || quantity < 1) {
                    showToast('Invalid product or quantity.', true);
                    return;
                }
                
                buttonReference.disabled = true;
                const originalButtonText = buttonReference.innerHTML;
                buttonReference.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Adding...';

                fetch('/user_interface/add_to_cart_ajax.php', { // ** ENSURE THIS PATH IS CORRECT **
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', },
                    body: `user_id=${encodeURIComponent(currentUserIdForCart)}&product_id=${encodeURIComponent(productId)}&quantity=${encodeURIComponent(quantity)}`
                })
                .then(response => {
                    if (!response.ok) {
                        return response.text().then(text => { throw new Error('Network error: ' + response.status + " " + text); });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        if (cartBadge) {
                            cartBadge.textContent = data.new_cart_total_items;
                            cartBadge.classList.toggle('hidden', data.new_cart_total_items === 0);
                        }
                        showToast(data.message || 'Item(s) added to cart!');
                        closeQuantityModal();
                        
                        // Update stock display on ALL relevant product cards/buttons on the page
                        const newStockCount = parseInt(modalProductStock.textContent) - quantity;
                        document.querySelectorAll(`.open-quantity-modal-btn[data-product-id="${productId}"]`).forEach(btn => {
                            btn.dataset.productStock = newStockCount;
                            const card = btn.closest('.product-card-grid') || btn.closest('.carousel__item');
                            if (card) {
                                const stockSpan = card.querySelector('.stock-value');
                                if(stockSpan) stockSpan.textContent = newStockCount > 0 ? newStockCount : 'Out of Stock';
                                if(newStockCount <= 0) {
                                    btn.innerHTML = '<i class="fas fa-times-circle mr-2"></i>OUT OF STOCK';
                                    btn.disabled = true;
                                }
                            }
                        });

                    } else {
                        showToast(data.message || 'Could not add item(s) to cart.', true);
                    }
                })
                .catch(error => {
                    console.error('Add to Cart Fetch Error:', error);
                    showToast('An error occurred. Please try again. ' + error.message, true);
                })
                .finally(() => {
                    buttonReference.disabled = false;
                    buttonReference.innerHTML = originalButtonText;
                });
            });
        }

        const productGrid = document.getElementById('productsGrid');
        const allProductCards = productGrid ? Array.from(productGrid.querySelectorAll('.product-card-grid')) : [];
        const allProductsTitle = document.getElementById('allProductsTitle');
        const showAllProductsBtn = document.getElementById('showAllProductsBtn');

        document.querySelectorAll('.category-slide-item').forEach(catButton => {
            catButton.addEventListener('click', function() {
                const categoryId = this.dataset.categoryId;
                const categoryName = this.dataset.categoryName || "Selected Category";
                
                if (allProductsTitle) allProductsTitle.textContent = `Products in: ${categoryName}`;
                allProductCards.forEach(card => {
                    card.style.display = (card.dataset.categoryId === categoryId) ? 'flex' : 'none';
                });
                if (allProductsTitle) allProductsTitle.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });
        
        if(showAllProductsBtn && allProductsTitle && allProductCards.length > 0) {
            showAllProductsBtn.addEventListener('click', () => {
                allProductCards.forEach(card => card.style.display = 'flex');
                allProductsTitle.textContent = 'All Products';
                 if (allProductsTitle) allProductsTitle.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        }
    });
    </script>
</body>
</html>
<?php
if (is_object($conn) && method_exists($conn, 'close')) {
    $conn->close();
}
?>
