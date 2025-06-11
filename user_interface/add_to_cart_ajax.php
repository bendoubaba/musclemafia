<?php
// add_to_cart_ajax.php
include("database.php"); // Ensure this file uses mysqli and sets up $conn

$response = [
    'success' => false,
    'message' => 'Invalid request. Ensure product, user ID, and quantity are provided.',
    'new_cart_total_items' => 0 // This will be the SUM of quantities in the cart
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['product_id'], $_POST['quantity'])) {
    $user_id = filter_var($_POST['user_id'], FILTER_VALIDATE_INT);
    $product_id = filter_var($_POST['product_id'], FILTER_VALIDATE_INT);
    $quantity_to_add = filter_var($_POST['quantity'], FILTER_VALIDATE_INT);

    if ($user_id && $product_id && $quantity_to_add > 0) {
        
        // Start transaction - if your DB engine supports it (e.g., InnoDB)
        // $conn->begin_transaction(); // Uncomment if using transactions

        try {
            // 1. Get product details (stock and name)
            $product_stock = 0;
            $product_name_for_msg = "Product";
            $stmt_product = $conn->prepare("SELECT `count`, `product` FROM products WHERE id = ?");
            if (!$stmt_product) throw new Exception("Error preparing product statement: " . $conn->error);
            
            $stmt_product->bind_param("i", $product_id);
            $stmt_product->execute();
            $result_product = $stmt_product->get_result();
            if ($product_row = $result_product->fetch_assoc()) {
                $product_stock = (int)$product_row['count'];
                $product_name_for_msg = $product_row['product'];
            } else {
                throw new Exception('Product not found.');
            }
            $stmt_product->close();

            // 2. Check if item already in cart for this user
            $current_cart_quantity = 0;
            $cart_entry_id = null;
            $stmt_check_cart = $conn->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?");
            if (!$stmt_check_cart) throw new Exception("Error preparing cart check statement: " . $conn->error);

            $stmt_check_cart->bind_param("ii", $user_id, $product_id);
            $stmt_check_cart->execute();
            $result_check_cart = $stmt_check_cart->get_result();
            if ($cart_row = $result_check_cart->fetch_assoc()) {
                $current_cart_quantity = (int)$cart_row['quantity'];
                $cart_entry_id = (int)$cart_row['id'];
            }
            $stmt_check_cart->close();

            // 3. Validate requested quantity against stock
            $final_quantity_in_cart = $current_cart_quantity + $quantity_to_add;
            if ($cart_entry_id === null) { // If item is not in cart yet
                $final_quantity_in_cart = $quantity_to_add;
            }

            if ($quantity_to_add > $product_stock && $cart_entry_id === null) { // Adding new, not enough stock for initial add
                 throw new Exception("Cannot add {$quantity_to_add}. Only {$product_stock} \"" . htmlspecialchars($product_name_for_msg) . "\" available.");
            }
            // If updating existing, check if (quantity_to_add) exceeds available stock NOT ALREADY IN CART
            // The `products.count` is total available.
            // If user wants to add 5, and has 2, and stock is 6:
            // they want total 7. Available to add is stock - current_in_cart = 6 - 2 = 4.
            // Since 5 > 4, this is an issue.
            $available_to_add_to_cart = $product_stock - $current_cart_quantity;
            if ($cart_entry_id !== null && $quantity_to_add > $available_to_add_to_cart) {
                 throw new Exception("Cannot add {$quantity_to_add} more. Only {$available_to_add_to_cart} additional units of \"" . htmlspecialchars($product_name_for_msg) . "\" available (Total stock: {$product_stock}, In cart: {$current_cart_quantity}).");
            }
             if ($quantity_to_add > $product_stock && $cart_entry_id === null) { // Adding new, not enough stock for initial add
                 throw new Exception("Cannot add {$quantity_to_add}. Only {$product_stock} \"" . htmlspecialchars($product_name_for_msg) . "\" available.");
            }


            // 4. Add or Update cart
            if ($cart_entry_id !== null) { // Item exists, update quantity
                $new_cart_item_quantity = $current_cart_quantity + $quantity_to_add;
                $stmt_update_cart = $conn->prepare("UPDATE cart SET quantity = ? WHERE id = ?");
                if (!$stmt_update_cart) throw new Exception("Error preparing cart update: " . $conn->error);
                $stmt_update_cart->bind_param("ii", $new_cart_item_quantity, $cart_entry_id);
                if (!$stmt_update_cart->execute()) throw new Exception('Error updating cart quantity: ' . $stmt_update_cart->error);
                $stmt_update_cart->close();
                $response['message'] = $quantity_to_add . ' more "' . htmlspecialchars($product_name_for_msg) . '" added/updated in cart.';
            } else { // Item does not exist, insert new
                $stmt_insert_cart = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)");
                if (!$stmt_insert_cart) throw new Exception("Error preparing cart insert: " . $conn->error);
                $stmt_insert_cart->bind_param("iii", $user_id, $product_id, $quantity_to_add);
                if (!$stmt_insert_cart->execute()) throw new Exception('Error adding to cart: ' . $stmt_insert_cart->error);
                $stmt_insert_cart->close();
                $response['message'] = $quantity_to_add . ' "' . htmlspecialchars($product_name_for_msg) . '" added to cart.';
            }

            // 5. Update product stock (deduct quantity)
            $new_product_stock = $product_stock - $quantity_to_add;
            $stmt_update_stock = $conn->prepare("UPDATE products SET `count` = ? WHERE id = ?");
            if (!$stmt_update_stock) throw new Exception("Error preparing stock update: " . $conn->error);
            $stmt_update_stock->bind_param("ii", $new_product_stock, $product_id);
            if (!$stmt_update_stock->execute()) throw new Exception('Error updating product stock: ' . $stmt_update_stock->error);
            $stmt_update_stock->close();

            $response['success'] = true;
            // $conn->commit(); // Uncomment if using transactions

        } catch (Exception $e) {
            // $conn->rollback(); // Uncomment if using transactions
            $response['message'] = $e->getMessage();
            error_log('AJAX Add to Cart Exception: ' . $e->getMessage());
        }

        // 6. Get new total cart items (sum of quantities)
        $stmt_total_items = $conn->prepare("SELECT SUM(quantity) AS total_items FROM cart WHERE user_id = ?");
        if ($stmt_total_items) {
            $stmt_total_items->bind_param("i", $user_id);
            $stmt_total_items->execute();
            $result_total_items = $stmt_total_items->get_result();
            if ($row_total = $result_total_items->fetch_assoc()) {
                $response['new_cart_total_items'] = (int)($row_total['total_items'] ?? 0);
            }
            $stmt_total_items->close();
        } else {
             error_log('AJAX Add to Cart - Total Count Prepare Error: ' . $conn->error);
             if ($response['success']) $response['message'] .= ' Could not retrieve updated cart total.';
        }

    } elseif ($quantity_to_add <= 0) {
        $response['message'] = 'Quantity must be at least 1.';
    } else {
        $response['message'] = 'Invalid user, product ID, or quantity.';
    }
}

if (is_object($conn) && method_exists($conn, 'close')) {
    $conn->close();
}
header('Content-Type: application/json');
echo json_encode($response);
exit();
?>
