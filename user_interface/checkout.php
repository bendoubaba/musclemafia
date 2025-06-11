<?php
session_start(); 
unset($_SESSION['user_id']);
unset($_SESSION['user_name']);
include("database.php"); 

$user_id = null;
$display_name = "Guest";
$feedback_messages = [];

// Prioritize user_id from session for security on a checkout page
if ((isset($_GET['user_id']) && filter_var($_GET['user_id'], FILTER_VALIDATE_INT)) || (isset($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT))) {
    $user_id = isset($_GET['user_id']) ? $_GET['user_id'] : (isset($_GET['id']) ? $_GET['id'] : null);

    if (isset($_GET['user_name'])) {
        // Fetch username if not in session but user_id is
        $stmt_session_user = $conn->prepare("SELECT username FROM users WHERE id = ?");
        if ($stmt_session_user) {
            $stmt_session_user->bind_param("i", $user_id);
            $stmt_session_user->execute();
            $res_session_user = $stmt_session_user->get_result();
            if($data_session_user = $res_session_user->fetch_assoc()){
                $display_name = htmlspecialchars($data_session_user['username']);
                $_SESSION['user_name'] = $display_name; // Store it for future use this session
            } else {
                 // User ID in session doesn't exist in DB, critical error, force re-login
                unset($_SESSION['user_id']);
                unset($_SESSION['user_name']);
                header("Location: /login.php?error=invalid_session_user");
                exit("Invalid user session. Please log in again.");
            }
            $stmt_session_user->close();
        } else {
            error_log("Error preparing user session name fetch: " . $conn->error);
            // Continue with $display_name as "Guest" or a generic user name
        }
    }
} else {
    // If no user_id in session, redirect to login. GET 'id' should not be primary for checkout.
    header("Location: /login.php?error=login_required_for_checkout");
    exit("User ID not found in session. Please log in.");
}


// --- HANDLE ORDER CONFIRMATION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_order_final'])) {
    if (!$user_id) { // Should not happen if session check above is effective
        $feedback_messages[] = ['type' => 'error', 'text' => 'User not identified. Please log in again.'];
    } else {
        $shipping_address = trim($_POST['shipping_address'] ?? '');
        $phone_number = trim($_POST['phone_number'] ?? '');

        if (empty($shipping_address) || empty($phone_number)) {
            $feedback_messages[] = ['type' => 'error', 'text' => 'Shipping address and phone number are required.'];
        } else {
            $cart_items_to_order = [];
            $current_grand_total = 0;
            $first_cart_item_id_for_facture = null; 

            $sql_get_cart = "SELECT c.id as cart_item_actual_id, c.product_id, c.quantity, p.price, p.product as product_name 
                             FROM cart c 
                             JOIN products p ON c.product_id = p.id 
                             WHERE c.user_id = ?";
            $stmt_get_cart = $conn->prepare($sql_get_cart);
            if ($stmt_get_cart) {
                $stmt_get_cart->bind_param("i", $user_id);
                $stmt_get_cart->execute();
                $result_get_cart = $stmt_get_cart->get_result();
                $is_first_item = true;
                while ($item = $result_get_cart->fetch_assoc()) {
                    if ($is_first_item && $item['cart_item_actual_id']) { // Ensure cart_item_actual_id is not null
                        $first_cart_item_id_for_facture = (int)$item['cart_item_actual_id'];
                        $is_first_item = false;
                    }
                    $cart_items_to_order[] = $item;
                    $current_grand_total += (float)$item['price'] * (int)$item['quantity'];
                }
                $stmt_get_cart->close();
            } else {
                $feedback_messages[] = ['type' => 'error', 'text' => 'Could not retrieve cart items for order processing. DB Error: ' . $conn->error];
            }

            if (empty($cart_items_to_order) && empty($feedback_messages)) {
                $feedback_messages[] = ['type' => 'info', 'text' => 'Your cart is empty. Nothing to order.'];
            } 
            // Removed: elseif (!$first_cart_item_id_for_facture && !empty($cart_items_to_order))
            // Because cart_id in factures is now NULLABLE, so $first_cart_item_id_for_facture can be null if cart is empty.
            // The main check is empty($cart_items_to_order).
            elseif (empty($feedback_messages)) { 
                $conn->begin_transaction();
                try {
                    // 1. Create a new entry in the 'factures' table
                    // CORRECTED: Using `total_price` instead of `total_amount`
                    $stmt_insert_facture = $conn->prepare(
                        "INSERT INTO factures (user_id, cart_id, total_price, shipping_address, phone_number, created_at) 
                         VALUES (?, ?, ?, ?, ?, NOW())"
                    );
                    if (!$stmt_insert_facture) throw new Exception("Prepare facture insert failed: " . $conn->error);
                    
                    $cart_id_to_insert = !empty($cart_items_to_order) ? $first_cart_item_id_for_facture : null;

                    $stmt_insert_facture->bind_param("iidss", $user_id, $cart_id_to_insert, $current_grand_total, $shipping_address, $phone_number);
                    if (!$stmt_insert_facture->execute()) throw new Exception("Execute facture insert failed: " . $stmt_insert_facture->error);
                    
                    $facture_id = $stmt_insert_facture->insert_id; 
                    $stmt_insert_facture->close();

                    if (!$facture_id) throw new Exception("Failed to create facture record.");

                    // 2. Move items from cart to 'facture_items'
                    // CORRECTED: Using `price` instead of `price_each` for facture_items
                    $stmt_insert_facture_item = $conn->prepare("INSERT INTO facture_items (facture_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
                    if (!$stmt_insert_facture_item) throw new Exception("Prepare facture_items insert failed: " . $conn->error);

                    foreach ($cart_items_to_order as $item) {
                        $product_id_item = (int)$item['product_id'];
                        $quantity_item = (int)$item['quantity'];
                        $price_at_purchase = (float)$item['price']; 

                        $stmt_insert_facture_item->bind_param("iiid", $facture_id, $product_id_item, $quantity_item, $price_at_purchase);
                        if (!$stmt_insert_facture_item->execute()) {
                            throw new Exception("Execute facture_items insert failed for product ID {$product_id_item}: " . $stmt_insert_facture_item->error);
                        }
                    }
                    $stmt_insert_facture_item->close();

                    // 3. Clear the user's cart
                    $stmt_clear_cart = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
                    if (!$stmt_clear_cart) throw new Exception("Prepare cart clear failed: " . $conn->error);
                    $stmt_clear_cart->bind_param("i", $user_id);
                    if (!$stmt_clear_cart->execute()) throw new Exception("Execute cart clear failed: " . $stmt_clear_cart->error);
                    $stmt_clear_cart->close();

                    $conn->commit();
                    // Use session for success message to survive redirect
                    $_SESSION['order_success_message'] = "Your order (Facture ID: #{$facture_id}) has been placed successfully! Shipping to: " . htmlspecialchars($shipping_address);
                    
                    header("Location: /user_interface/update_user.php?user_id={$user_id}&order_status=success&fid={$facture_id}"); // Adjust path as needed
                    exit;

                } catch (Exception $e) {
                    $conn->rollback(); 
                    $feedback_messages[] = ['type' => 'error', 'text' => 'Order processing critical error: ' . $e->getMessage()];
                    error_log("Checkout Critical Error for user {$user_id}: " . $e->getMessage());
                }
            }
        }
    }
}

// --- Fetch cart items for display (if not a successful POST or if POST failed) ---
$cart_items_display = [];
$grand_total_display = 0;
if (!($_SERVER['REQUEST_METHOD'] === 'POST' && empty($feedback_messages) && isset($_SESSION['order_success_message']))) {
    // If it was a POST and order_success_message is set, it means we redirected, so cart items should be empty.
    // This condition ensures we don't try to display cart items if they were just cleared.
    if (isset($_SESSION['order_success_message'])) {
        // If order was successful, cart is empty, so don't try to fetch.
    } else {
        $sql_display_cart = "SELECT c.id AS cart_item_id, p.id AS product_id, p.product AS product_name, 
                                    p.picture AS image_url, c.quantity, p.price, p.count as product_available_stock
                             FROM cart c
                             JOIN products p ON c.product_id = p.id
                             WHERE c.user_id = ?";
        $stmt_display_cart = $conn->prepare($sql_display_cart);
        if ($stmt_display_cart) {
            $stmt_display_cart->bind_param("i", $user_id);
            $stmt_display_cart->execute();
            $result_display_cart = $stmt_display_cart->get_result();
            while ($item = $result_display_cart->fetch_assoc()) {
                $cart_items_display[] = $item;
                $grand_total_display += (float)$item['price'] * (int)$item['quantity'];
            }
            $stmt_display_cart->close();
        } else {
            if(empty($feedback_messages)) {
                 $feedback_messages[] = ['type' => 'error', 'text' => 'Could not retrieve cart items for display. DB Error: ' . $conn->error];
            }
        }
    }
}


// For header cart badge
$num_total_items_in_cart_header = 0; 
if ($user_id) {
    $stmt_cart_badge = $conn->prepare("SELECT SUM(quantity) AS total_items FROM cart WHERE user_id = ?");
    if ($stmt_cart_badge) {
        $stmt_cart_badge->bind_param("i", $user_id);
        $stmt_cart_badge->execute();
        $res_badge = $stmt_cart_badge->get_result();
        if ($row_badge = $res_badge->fetch_assoc()) {
            $num_total_items_in_cart_header = (int)($row_badge['total_items'] ?? 0);
        }
        $stmt_cart_badge->close();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon"
        href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🌌</text></svg>">
    
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - MuscleMafia Shop</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="/access/img/logo_bw.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #111827; color: #D1D5DB; }
        .header-bg { background-color: #000000; border-bottom: 1px solid #222;}
        .logo snap { color: #FFFFFF; }
        .nav-link { color: #b0b0b0; padding: 0.5rem 0.75rem; border-radius: 0.375rem; transition: background-color 0.3s ease, color 0.3s ease; text-transform: uppercase; font-size: 0.8rem; font-weight: 500; letter-spacing: 0.5px;}
        .nav-link:hover { color: #00FFFF; background-color: rgba(0, 255, 255, 0.05); }
        .badge {
            position: absolute; top: -8px; right: -8px; padding: 1px 5px;
            border-radius: 50%; background-color: #00FFFF; color: #000000;
            font-size: 0.7rem; font-weight: 700; border: 1px solid #00FFFF;
            min-width: 20px; height:20px; display:flex; align-items:center; justify-content:center;
        }
        .content-bg { background-color: #1F2937; }
        .table th, .table td { padding: 0.75rem 1rem; text-align: left; border-bottom: 1px solid #374151; }
        .table th { background-color: #374151; font-weight: 600; text-transform: uppercase; color: #F3F4F6; }
        .product-image-checkout { width: 50px; height: 50px; object-fit: cover; border-radius: 0.25rem; border: 1px solid #4B5563; }
        
        .btn-action {
            background-color: #00FFFF; color: #000000; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
            border: 2px solid #00FFFF; padding: 0.875rem 2rem; border-radius: 6px;
            cursor: pointer; text-align: center; 
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        .btn-action:hover {
            background-color: #000000; color: #00FFFF;
            box-shadow: 0 0 15px #00FFFF, 0 0 25px #00FFFF, inset 0 0 5px #00FFFF;
            transform: translateY(-2px);
        }
        .btn-secondary { background-color: #4B5563; color: #FFFFFF; } 
        .btn-secondary:hover { background-color: #5a6675; } 

        .feedback-message { padding: 0.75rem 1rem; margin-bottom: 1.5rem; border-radius: 0.375rem; font-size: 0.875rem; }
        .feedback-message.success { background-color: #10B981; border: 1px solid #059669; color: #E0F2F7; }
        .feedback-message.error   { background-color: #EF4444; border: 1px solid #DC2626; color: #FEE2E2; }
        .feedback-message.info    { background-color: #3B82F6; border: 1px solid #2563EB; color: #EFF6FF; }
        .footer-bg { background-color: #000000; border-top: 1px solid #222;}
        
        /* Modal Styles */
        .modal-backdrop {
            position: fixed; inset: 0; background-color: rgba(0,0,0,0.85);
            display: flex; align-items: center; justify-content: center;
            z-index: 10000; opacity: 0; pointer-events: none; transition: opacity 0.3s ease-in-out;
        }
        .modal-backdrop.active { opacity: 1; pointer-events: auto; }
        .modal-content {
            background: #1F2937; 
            padding: 1.5rem; md:padding: 2rem; border-radius: 12px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.5), 0 0 0 1px #00FFFF; 
            width: 90%; max-width: 500px;
            transform: scale(0.95) translateY(20px); 
            transition: transform 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275), opacity 0.3s ease-out;
        }
        .modal-backdrop.active .modal-content { transform: scale(1) translateY(0); }
        .modal-input {
            background-color: #374151; color: #F9FAFB; border: 1px solid #4B5563;
            border-radius: 6px; padding: 0.75rem 1rem; width: 100%;
        }
        .modal-input:focus { border-color: #00FFFF; box-shadow: 0 0 0 2px rgba(0,255,255,0.5); outline: none; }
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
    </style>
</head>
<body class="antialiased">
    <header class="header-bg text-gray-300 shadow-lg sticky top-0 z-50">
        <div class="nav container mx-auto flex items-center justify-between p-4">
            <a href="/user_interface/index_user.php?user_id=<?php echo $user_id; ?>" class="logo text-2xl font-bold text-white">Muscle<span class="text-yellow-400">Mafia</span></a>
            <div class="text-xs md:text-sm">User: <span class="font-semibold text-white"><?php echo $display_name; ?></span></div>
            <nav class="space-x-2">
                <a href="/user_interface/index_user.php?user_id=<?php echo $user_id; ?>" class="nav-link">Shop</a>
                <a href="/user_interface/update_user.php?id=<?php echo $user_id; ?>" class="nav-link">Profile</a>
                 <a href="/user_interface/card.php?id=<?php echo $user_id; ?>" class="nav-link relative">
                    <i class="fas fa-shopping-cart"></i> Cart
                    <span class="badge <?php echo ($num_total_items_in_cart_header == 0) ? 'hidden' : ''; ?>" id="cartItemCountBadgeHeader"><?php echo $num_total_items_in_cart_header; ?></span>
                </a>
                <a href="/login.php?logout=true" class="nav-link">Logout</a>
            </nav>
        </div>
    </header>

    <div class="container mx-auto py-8 px-4">
        <h1 class="text-4xl font-orbitron font-bold mb-8 text-center text-white">Checkout</h1>

        <?php 
        // Display messages passed via session (e.g., after order success redirect)
        if (isset($_SESSION['checkout_feedback'])) {
            $msg_type = $_SESSION['checkout_feedback']['type'];
            $msg_text = $_SESSION['checkout_feedback']['text'];
            echo "<div class='feedback-message {$msg_type} max-w-2xl mx-auto' role='alert'>" . htmlspecialchars($msg_text) . "</div>";
            unset($_SESSION['checkout_feedback']);
        }
        // Display messages generated on this page load (e.g., POST errors)
        if (!empty($feedback_messages)): 
            foreach ($feedback_messages as $msg): ?>
                <div class="feedback-message <?php echo htmlspecialchars($msg['type']); ?> max-w-2xl mx-auto" role="alert">
                    <?php echo htmlspecialchars($msg['text']); ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (empty($feedback_messages) && empty($cart_items_display) && $_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_SESSION['order_success_message'])): ?>
             <div class="text-center py-10 content-bg rounded-lg shadow-xl max-w-md mx-auto">
                <i class="fas fa-shopping-cart fa-3x text-gray-500 mb-4"></i>
                <p class="text-xl text-gray-300 mb-2">Your cart is empty.</p>
                <p class="text-gray-400 mb-6">There's nothing to checkout. Add some gear first!</p>
                <a href="/user_interface/index_user.php?user_id=<?php echo $user_id; ?>" class="btn-secondary py-2 px-6 rounded-lg font-semibold shadow-md transition-transform transform hover:scale-105">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Shop
                </a>
            </div>
        <?php elseif (!empty($cart_items_display)): ?>
            <div class="content-bg p-6 md:p-8 rounded-lg shadow-xl max-w-3xl mx-auto">
                <h2 class="text-2xl font-semibold text-white mb-6 border-b border-gray-700 pb-3">Order Summary</h2>
                <div class="overflow-x-auto mb-6">
                    <table class="table w-full text-sm">
                        <thead>
                            <tr>
                                <th class="w-1/6">Image</th>
                                <th class="w-2/5">Product</th>
                                <th class="text-right">Price</th>
                                <th class="text-center">Qty</th>
                                <th class="text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cart_items_display as $item): ?>
                                <?php $item_subtotal = (float)$item['price'] * (int)$item['quantity']; ?>
                                <tr>
                                    <td>
                                        <img src="<?php echo htmlspecialchars($item['image_url'] ? $item['image_url'] : 'https://placehold.co/50x50/374151/9CA3AF?text=N/A'); ?>" 
                                             alt="<?php echo htmlspecialchars($item['product_name']); ?>" class="product-image-checkout"
                                             onerror="this.onerror=null;this.src='https://placehold.co/50x50/374151/9CA3AF?text=N/A';">
                                    </td>
                                    <td class="font-medium text-gray-100"><?php echo htmlspecialchars($item['product_name']); ?></td>
                                    <td class="text-gray-300 text-right">DZ<?php echo number_format((float)$item['price'], 2); ?></td>
                                    <td class="text-gray-300 text-center"><?php echo (int)$item['quantity']; ?></td>
                                    <td class="text-gray-100 font-semibold text-right">DZ<?php echo number_format($item_subtotal, 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-gray-700 pt-6">
                    <div class="flex justify-end items-center mb-6">
                        <span class="text-xl font-semibold text-gray-200 mr-3">Grand Total:</span>
                        <span class="text-3xl font-bold text-cyan-400">DZ<?php echo number_format($grand_total_display, 2); ?></span>
                    </div>
                    <button type="button" id="openShippingModalBtn" class="btn-action w-full py-3 px-6 rounded-lg font-semibold text-lg">
                        <i class="fas fa-shipping-fast mr-2"></i> Enter Shipping Details
                    </button>
                </div>
                 <div class="mt-6 text-center">
                    <a href="card.php?id=<?php echo $user_id; ?>" class="text-sm text-sky-400 hover:text-sky-300">
                        <i class="fas fa-edit mr-1"></i> Modify Cart
                    </a>
                </div>
            </div>
        <?php elseif (empty($feedback_messages) && isset($_SESSION['order_success_message'])): 
            // This case is for displaying the success message after redirect
             echo "<div class='feedback-message success max-w-2xl mx-auto' role='alert'>" . htmlspecialchars($_SESSION['order_success_message']) . "</div>";
             echo '<div class="text-center mt-6"><a href="/user_interface/index_user.php?user_id='.$user_id.'" class="btn-secondary py-2 px-6 rounded-lg font-semibold shadow-md transition-transform transform hover:scale-105"><i class="fas fa-arrow-left mr-2"></i> Back to Shop</a></div>';
             unset($_SESSION['order_success_message']);
        ?>
        <?php elseif (empty($feedback_messages)): // Fallback if cart became empty for other reasons
             ?>
             <div class="text-center py-10 content-bg rounded-lg shadow-xl max-w-md mx-auto">
                <p class="text-xl text-gray-300 mb-6">Your cart is now empty or an error occurred.</p>
                <a href="/user_interface/index_user.php?user_id=<?php echo $user_id; ?>" class="btn-secondary py-2 px-6 rounded-lg font-semibold shadow-md transition-transform transform hover:scale-105">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Shop
                </a>
            </div>
        <?php endif; ?>
    </div>
    
    <div id="shippingModal" class="modal-backdrop">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-2xl font-orbitron font-semibold text-white">Shipping Details</h3>
                <button id="closeShippingModal" class="text-gray-400 hover:text-white text-3xl leading-none">&times;</button>
            </div>
            <form action="checkout.php?id=<?php echo $user_id; ?>" method="POST" id="checkoutForm">
                <div class="mb-4">
                    <label for="shipping_address" class="block text-sm font-medium text-gray-300 mb-1">Full Shipping Address</label>
                    <textarea id="shipping_address" name="shipping_address" rows="3" class="modal-input" placeholder="e.g., 123 Muscle St, Apt 4B, Gym City, ST 12345" required></textarea>
                </div>
                <div class="mb-6">
                    <label for="phone_number" class="block text-sm font-medium text-gray-300 mb-1">Phone Number</label>
                    <input type="tel" id="phone_number" name="phone_number" class="modal-input" placeholder="e.g., (555) 123-4567" required
                           pattern="[0-9\s\-\(\)\+]{7,20}" title="Enter a valid phone number (7-20 digits, can include spaces, hyphens, parentheses, plus).">
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancelShippingModal" class="btn-secondary py-2 px-4 rounded-md font-medium">Cancel</button>
                    <button type="submit" name="confirm_order_final" class="btn-action py-2 px-4 rounded-md font-semibold">
                        <i class="fas fa-check-circle mr-2"></i>Place Order Now
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="toast-container" class="fixed bottom-5 right-5 z-[10001] space-y-2"></div>

    <footer class="footer-bg text-gray-400 text-center p-6 mt-12 border-t border-gray-700">
        <p>&copy; <?php echo date("Y"); ?> MuscleMafia. All rights reserved.</p>
    </footer>
    <script>
        function showToast(message, isError = false) {
            const toastContainer = document.getElementById('toast-container');
            if (!toastContainer) return;
            const toast = document.createElement('div');
            toast.className = `p-4 rounded-md shadow-lg text-white text-sm ${isError ? 'bg-red-600' : 'bg-green-600'}`;
            toast.textContent = message;
            toastContainer.appendChild(toast);
            toast.style.opacity = 0;
            setTimeout(() => { toast.style.transition = 'opacity 0.5s'; toast.style.opacity = 1; }, 10);
            setTimeout(() => {
                toast.style.opacity = 0;
                setTimeout(() => { toast.remove(); }, 500);
            }, 3000);
        }
        
        <?php
        // Display session-based toast messages (e.g. from order success redirect)
        if (isset($_SESSION['order_success_message'])) {
            echo "showToast(" . json_encode($_SESSION['order_success_message']) . ", false);";
            unset($_SESSION['order_success_message']);
        }
        // You can also use this for other session flash messages if needed
        if (isset($_SESSION['checkout_feedback_toast'])) { // A different session key for general toasts
            $toast_s = $_SESSION['checkout_feedback_toast'];
            echo "showToast(" . json_encode($toast_s['text']) . ", " . json_encode($toast_s['type'] === 'error') . ");";
            unset($_SESSION['checkout_feedback_toast']);
        }
        ?>

        document.addEventListener('DOMContentLoaded', function() {
            const shippingModal = document.getElementById('shippingModal');
            const openShippingModalBtn = document.getElementById('openShippingModalBtn');
            const closeShippingModalBtn = document.getElementById('closeShippingModal');
            const cancelShippingModalBtn = document.getElementById('cancelShippingModal');
            const checkoutForm = document.getElementById('checkoutForm');

            if (openShippingModalBtn && shippingModal) {
                openShippingModalBtn.addEventListener('click', () => {
                    shippingModal.classList.add('active');
                });
            }
            if (closeShippingModalBtn && shippingModal) {
                closeShippingModalBtn.addEventListener('click', () => {
                    shippingModal.classList.remove('active');
                });
            }
            if (cancelShippingModalBtn && shippingModal) {
                cancelShippingModalBtn.addEventListener('click', () => {
                    shippingModal.classList.remove('active');
                });
            }
            if (shippingModal) {
                shippingModal.addEventListener('click', function(event) {
                    if (event.target === this) { 
                        shippingModal.classList.remove('active');
                    }
                });
            }

            if (checkoutForm) {
                checkoutForm.addEventListener('submit', function(event) {
                    const address = document.getElementById('shipping_address').value.trim();
                    const phone = document.getElementById('phone_number').value.trim();
                    if (address === '' || phone === '') {
                        event.preventDefault(); 
                        showToast('Please fill in all shipping details.', true);
                    }
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
session_destroy(); // Clear session data after checkout to prevent reuse
?>
