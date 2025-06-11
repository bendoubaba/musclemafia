<?php
session_start(); 
include("database.php"); 

if (isset($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    $user_id = (int)$_GET['id'];
} else {
    header('Location: /login.php');
    exit("User ID not found. Please log in.");
}

// --- DELETE ITEM FROM CART LOGIC (Remains largely the same, but ensure stock restoration is correct) ---
if (isset($_GET['delete_cart_item_id']) && filter_var($_GET['delete_cart_item_id'], FILTER_VALIDATE_INT)) {
    $cart_item_id_to_delete = (int)$_GET['delete_cart_item_id'];
    $conn->begin_transaction();
    try {
        $product_id_to_update_stock = null;
        $quantity_to_restore = 0;

        $stmt_get_cart_item = $conn->prepare("SELECT product_id, quantity FROM cart WHERE id = ? AND user_id = ?");
        if (!$stmt_get_cart_item) throw new Exception("Prepare failed (get_cart_item): " . $conn->error);
        $stmt_get_cart_item->bind_param("ii", $cart_item_id_to_delete, $user_id);
        $stmt_get_cart_item->execute();
        $result_cart_item = $stmt_get_cart_item->get_result();

        if ($cart_item_details = $result_cart_item->fetch_assoc()) {
            $product_id_to_update_stock = (int)$cart_item_details['product_id'];
            $quantity_to_restore = (int)$cart_item_details['quantity'];
        }
        $stmt_get_cart_item->close();

        if ($product_id_to_update_stock && $quantity_to_restore > 0) {
            $stmt_update_stock = $conn->prepare("UPDATE products SET `count` = `count` + ? WHERE id = ?");
            if (!$stmt_update_stock) throw new Exception("Prepare failed (update_stock): " . $conn->error);
            $stmt_update_stock->bind_param("ii", $quantity_to_restore, $product_id_to_update_stock);
            if (!$stmt_update_stock->execute()) throw new Exception("Execute failed (update_stock): " . $stmt_update_stock->error);
            $stmt_update_stock->close();

            $stmt_delete_cart = $conn->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?");
            if (!$stmt_delete_cart) throw new Exception("Prepare failed (delete_cart): " . $conn->error);
            $stmt_delete_cart->bind_param("ii", $cart_item_id_to_delete, $user_id);
            if (!$stmt_delete_cart->execute()) throw new Exception("Execute failed (delete_cart): " . $stmt_delete_cart->error);
            $stmt_delete_cart->close();

            $conn->commit();
            $_SESSION['cart_message'] = ['type' => 'success', 'text' => 'Item removed and stock restored.'];
        } else {
            throw new Exception("Cart item not found for deletion or invalid quantity.");
        }
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Cart Deletion Error: " . $e->getMessage());
        $_SESSION['cart_message'] = ['type' => 'error', 'text' => 'Error removing item: ' . $e->getMessage()];
    }
    header('Location: card.php?id=' . $user_id);
    exit();
}

// --- FETCH CART ITEMS FOR DISPLAY ---
$sql_cart_items = "SELECT c.id AS cart_item_id, p.id AS product_id, p.product AS product_name, 
                          p.description, p.price, p.picture AS image_url, c.quantity, p.count as product_available_stock
                   FROM cart c
                   JOIN products p ON c.product_id = p.id
                   WHERE c.user_id = ?";
$stmt_cart_items = $conn->prepare($sql_cart_items);
if (!$stmt_cart_items) {
    error_log("Error preparing cart items statement: " . $conn->error);
    die("Error fetching cart items. Please try again later.");
}
$stmt_cart_items->bind_param("i", $user_id);
$stmt_cart_items->execute();
$result_cart_items = $stmt_cart_items->get_result();
$cart_items_array = [];
while($row = $result_cart_items->fetch_assoc()){
    $cart_items_array[] = $row;
}
$stmt_cart_items->close();
$grand_total = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon"
        href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🌌</text></svg>">
    
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - MuscleMafia</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="/access/img/logo_bw.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.1/css/all.min.css">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #111827; color: #D1D5DB; }
        .header-bg { background-color: #000000; }
        .content-bg { background-color: #1F2937; }
        .table th, .table td { padding: 0.75rem 1rem; text-align: left; border-bottom: 1px solid #374151; }
        .table th { background-color: #374151; font-weight: 600; text-transform: uppercase; color: #F3F4F6; }
        .product-image-cart { width: 60px; height: 60px; object-fit: cover; border-radius: 0.25rem; border: 1px solid #4B5563; }
        .quantity-input-cart {
            width: 60px; text-align: center;
            background-color: #374151; /* bg-gray-700 */
            color: #F9FAFB; /* text-gray-50 */
            border: 1px solid #4B5563; /* border-gray-600 */
            border-radius: 0.25rem; /* rounded-sm */
            padding: 0.25rem; margin: 0 0.25rem;
            -moz-appearance: textfield; /* Firefox */
        }
        .quantity-input-cart::-webkit-outer-spin-button,
        .quantity-input-cart::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

        .btn-update-qty { background-color: #0ea5e9; /* sky-500 */ color: white; }
        .btn-update-qty:hover { background-color: #0284c7; /* sky-600 */ }
        .btn-update-qty:disabled { background-color: #4B5563; cursor: not-allowed; }

        .btn-danger { background-color: #EF4444; color: #FFFFFF; }
        .btn-danger:hover { background-color: #DC2626; }
        .btn-primary { background-color: #3B82F6; color: #FFFFFF; }
        .btn-primary:hover { background-color: #2563EB; }
        .btn-secondary { background-color: #6B7280; color: #FFFFFF; }
        .btn-secondary:hover { background-color: #4B5563; }
        .feedback-message { padding: 0.75rem 1rem; margin-bottom: 1.5rem; border-radius: 0.375rem; font-size: 0.875rem; }
        .feedback-message.success { background-color: #d1fae5; border: 1px solid #6ee7b7; color: #065f46; }
        .feedback-message.error   { background-color: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }
        .toast-notification { /* Keep your toast styles */
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
    <header class="header-bg text-gray-300 shadow-lg">
        <div class="container mx-auto flex items-center justify-between p-4">
            <a href="/user_interface/index_user.php?user_id=<?php echo $user_id; ?>" class="logo text-2xl font-bold text-white">Muscle<span class="text-yellow-400">Mafia</span></a>
            <nav class="space-x-4">
                <a href="/user_interface/index_user.php?user_id=<?php echo $user_id; ?>" class="hover:text-white">Shop</a>
                <a href="/user_interface/update_user.php?id=<?php echo $user_id; ?>" class="hover:text-white">Profile</a>
                 <a href="/login.php" class="hover:text-white">Logout</a>
            </nav>
        </div>
    </header>

    <div class="container mx-auto py-8 px-4">
        <h1 class="text-3xl font-bold mb-6 text-gray-100">Your Shopping Cart</h1>

        <?php 
        if (isset($_SESSION['cart_message'])) {
            $msg_type = $_SESSION['cart_message']['type'];
            $msg_text = $_SESSION['cart_message']['text'];
            echo "<div class='feedback-message {$msg_type}'>" . htmlspecialchars($msg_text) . "</div>";
            unset($_SESSION['cart_message']);
        }
        ?>

        <?php if (count($cart_items_array) > 0) : ?>
            <div class="overflow-x-auto content-bg rounded-lg shadow-xl">
                <table class="table w-full">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Product</th>
                            <th>Price</th>
                            <th class="text-center">Quantity</th>
                            <th>Subtotal</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="cartTableBody">
                        <?php 
                        foreach ($cart_items_array as $item) {
                            $subtotal = (float)$item['price'] * (int)$item['quantity'];
                            $grand_total += $subtotal;
                            // Max quantity user can set in input = current product stock in DB + what they already have in cart for this item,
                            // but not exceeding the actual total stock of the product.
                            // The actual available stock for *this specific item* to increase is product_available_stock.
                            // The input field's max should be product_available_stock (from DB) + current_item_quantity_in_cart
                            $max_input_quantity = (int)$item['product_available_stock'] + (int)$item['quantity'];
                        ?>
                            <tr data-cart-item-id="<?php echo $item['cart_item_id']; ?>">
                                <td>
                                    <img src="<?php echo htmlspecialchars($item['image_url'] ? $item['image_url'] : 'https://placehold.co/60x60/374151/9CA3AF?text=N/A'); ?>" 
                                         alt="<?php echo htmlspecialchars($item['product_name']); ?>" class="product-image-cart"
                                         onerror="this.onerror=null;this.src='https://placehold.co/60x60/374151/9CA3AF?text=N/A';">
                                </td>
                                <td class="font-medium text-gray-100 product-name"><?php echo htmlspecialchars($item['product_name']); ?></td>
                                <td class="text-gray-300 product-price" data-price="<?php echo (float)$item['price']; ?>">DZ<?php echo number_format((float)$item['price'], 2); ?></td>
                                <td class="text-center">
                                    <div class="flex items-center justify-center">
                                        <input type="number" value="<?php echo (int)$item['quantity']; ?>" min="1" max="<?php echo $max_input_quantity; ?>" 
                                               class="quantity-input-cart" 
                                               data-cart-item-id="<?php echo $item['cart_item_id']; ?>"
                                               data-product-id="<?php echo $item['product_id']; ?>"
                                               data-old-quantity="<?php echo (int)$item['quantity']; ?>"
                                               aria-label="Quantity for <?php echo htmlspecialchars($item['product_name']); ?>">
                                        <button class="btn-update-qty text-xs py-1 px-2 rounded-md ml-2" data-cart-item-id="<?php echo $item['cart_item_id']; ?>">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                    </div>
                                </td>
                                <td class="text-gray-100 font-semibold product-subtotal">DZ<?php echo number_format($subtotal, 2); ?></td>
                                <td>
                                    <a href="card.php?id=<?php echo $user_id; ?>&delete_cart_item_id=<?php echo $item['cart_item_id']; ?>" 
                                       class="btn-danger text-xs py-2 px-3 rounded-md font-medium inline-flex items-center"
                                       onclick="return confirm('Are you sure you want to remove this item (<?php echo (int)$item['quantity']; ?> units of <?php echo htmlspecialchars(addslashes($item['product_name'])); ?>) from your cart? This will restore stock.');">
                                       <i class="fas fa-trash-alt mr-1"></i> Remove
                                    </a>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <div class="mt-8 flex flex-col md:flex-row justify-between items-center content-bg p-6 rounded-lg shadow-xl">
                <div class="text-2xl font-semibold text-white mb-4 md:mb-0">
                    Grand Total: <span class="text-yellow-400" id="grandTotalDisplay">DZ<?php echo number_format($grand_total, 2); ?></span>
                </div>
                <div class="space-y-3 md:space-y-0 md:space-x-3">
                    <a href="/user_interface/index_user.php?user_id=<?php echo $user_id; ?>" class="btn-secondary py-2 px-6 rounded-lg font-semibold shadow-md transition-transform transform hover:scale-105 inline-block text-center w-full md:w-auto">
                        <i class="fas fa-arrow-left mr-2"></i> Continue Shopping
                    </a>
                    <a href="/user_interface/checkout.php?user_id=<?php echo $user_id; ?>" class="btn-primary py-2 px-6 rounded-lg font-semibold shadow-md transition-transform transform hover:scale-105 inline-block text-center w-full md:w-auto">
                        Proceed to Checkout <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
            </div>

        <?php else : ?>
            <div class="text-center py-10 content-bg rounded-lg shadow-xl">
                <i class="fas fa-shopping-cart fa-3x text-gray-500 mb-4"></i>
                <p class="text-xl text-gray-300 mb-2">Your shopping cart is empty.</p>
                <p class="text-gray-400 mb-6">Looks like you haven't added anything to your cart yet.</p>
                <a href="/user_interface/index_user.php?user_id=<?php echo $user_id; ?>" class="btn-primary py-2 px-6 rounded-lg font-semibold shadow-md transition-transform transform hover:scale-105">
                    Start Shopping
                </a>
            </div>
        <?php endif; ?>
    </div>
    
    <div id="toast-container" class="fixed bottom-5 right-5 z-[10001] space-y-2"></div>

    <footer class="footer-bg text-gray-400 text-center p-6 mt-12 border-t border-gray-700">
        <p>&copy; <?php echo date("Y"); ?> MuscleMafia. All rights reserved.</p>
    </footer>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const currentUserId = <?php echo json_encode($user_id ? (int)$user_id : 0); ?>;
        const cartTableBody = document.getElementById('cartTableBody');
        const grandTotalDisplay = document.getElementById('grandTotalDisplay');
        const cartBadgeHeader = document.getElementById('cartItemCountBadge'); // Assuming your header badge has this ID

        function showToast(message, isError = false) {
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
        }

        function updateGrandTotal() {
            let newGrandTotal = 0;
            document.querySelectorAll('#cartTableBody tr').forEach(row => {
                const subtotalText = row.querySelector('.product-subtotal').textContent.replace('$', '');
                newGrandTotal += parseFloat(subtotalText);
            });
            if (grandTotalDisplay) {
                grandTotalDisplay.textContent = '$' + newGrandTotal.toFixed(2);
            }
        }
        
        function updateCartBadge(totalItems) {
            if (cartBadgeHeader) {
                cartBadgeHeader.textContent = totalItems;
                cartBadgeHeader.classList.toggle('hidden', totalItems === 0);
            }
        }


        if (cartTableBody) {
            cartTableBody.addEventListener('click', function(event) {
                const updateButton = event.target.closest('.btn-update-qty');
                if (updateButton) {
                    const cartItemId = updateButton.dataset.cartItemId;
                    const row = updateButton.closest('tr');
                    const quantityInput = row.querySelector('.quantity-input-cart');
                    const newQuantity = parseInt(quantityInput.value);
                    const oldQuantity = parseInt(quantityInput.dataset.oldQuantity);
                    const productId = quantityInput.dataset.productId; // Make sure this is set

                    if (isNaN(newQuantity) || newQuantity < 1) {
                        showToast('Quantity must be at least 1.', true);
                        quantityInput.value = oldQuantity; // Revert
                        return;
                    }
                    
                    // Disable button during AJAX
                    updateButton.disabled = true;
                    updateButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';


                    const formData = new FormData();
                    formData.append('cart_item_id', cartItemId);
                    formData.append('new_quantity', newQuantity);
                    formData.append('user_id', currentUserId); // Send user_id for validation

                    // ** ADJUST PATH TO YOUR update_cart_item_ajax.php SCRIPT **
                    fetch('/user_interface/update_cart_item_ajax.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) {
                           return response.text().then(text => {throw new Error("Network error: " + response.status + " " + text)});
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            showToast(data.message || 'Cart updated successfully!');
                            // Update UI
                            const pricePerItem = parseFloat(row.querySelector('.product-price').dataset.price);
                            row.querySelector('.product-subtotal').textContent = '$' + data.new_subtotal.toFixed(2);
                            quantityInput.value = newQuantity; // Confirm new quantity in input
                            quantityInput.dataset.oldQuantity = newQuantity; // Update old quantity for next change
                            
                            // Update max attribute of quantity input based on new stock
                            // The AJAX response should ideally return the new max possible for this item
                            // For now, we assume the AJAX handler validated stock.
                            // If you add 'updated_product_stock' to AJAX response:
                            if(typeof data.updated_product_stock !== 'undefined'){
                                const currentStockInDb = data.updated_product_stock;
                                quantityInput.max = currentStockInDb + newQuantity; // new max is current stock + what's now in cart
                            }


                            updateGrandTotal();
                            updateCartBadge(data.new_cart_total_items);
                        } else {
                            showToast(data.message || 'Failed to update cart.', true);
                            quantityInput.value = oldQuantity; // Revert on failure
                        }
                    })
                    .catch(error => {
                        console.error('Error updating cart:', error);
                        showToast('Error: ' + error.message, true);
                        quantityInput.value = oldQuantity; // Revert on error
                    })
                    .finally(() => {
                        updateButton.disabled = false;
                        updateButton.innerHTML = '<i class="fas fa-sync-alt"></i>';
                    });
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
?>
