<?php
session_start(); // If you rely on session for user_id consistency
include("database.php"); // Ensure this file uses mysqli and sets up $conn

$response = [
    'success' => false,
    'message' => 'Invalid request.',
    'new_subtotal' => 0,
    'new_grand_total' => 0,
    'new_cart_total_items' => 0, // Sum of quantities
    'updated_product_stock' => 0 // For client-side update if needed
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cart_item_id'], $_POST['new_quantity'], $_POST['user_id'])) {
    $cart_item_id = filter_var($_POST['cart_item_id'], FILTER_VALIDATE_INT);
    $new_quantity = filter_var($_POST['new_quantity'], FILTER_VALIDATE_INT);
    $user_id = filter_var($_POST['user_id'], FILTER_VALIDATE_INT);

    // Basic validation
    if (!$cart_item_id || !$user_id || $new_quantity < 0) { // Allow 0 for deletion by quantity update
        $response['message'] = 'Invalid input data.';
        header('Content-Type: application/json');
        echo json_encode($response);
        $conn->close();
        exit();
    }
    
    // If new quantity is 0, treat it as a delete request
    if ($new_quantity === 0) {
        // This logic is similar to the delete logic in card.php, but consolidated here
        // For simplicity, we'll redirect to card.php with delete params, or you can replicate delete logic here.
        // For a true AJAX update, replicating delete logic here is better.
        // For now, let's assume quantity >= 1 for update, and delete is separate.
        // If you want 0 quantity to delete, this block needs full delete + stock restore logic.
        // For now, we'll enforce quantity >= 1 for update.
        if ($new_quantity < 1) {
            $response['message'] = 'Quantity must be at least 1. Use the remove button to delete items.';
             header('Content-Type: application/json');
             echo json_encode($response);
             $conn->close();
             exit();
        }
    }


    $conn->begin_transaction();

    try {
        // 1. Get current cart item details (product_id, old_quantity)
        $old_quantity = 0;
        $product_id = null;
        $item_price = 0;

        $stmt_get_item = $conn->prepare("SELECT c.product_id, c.quantity, p.price, p.count as current_stock, p.product as product_name 
                                         FROM cart c 
                                         JOIN products p ON c.product_id = p.id 
                                         WHERE c.id = ? AND c.user_id = ?");
        if (!$stmt_get_item) throw new Exception("Prepare failed (get_item): " . $conn->error);
        $stmt_get_item->bind_param("ii", $cart_item_id, $user_id);
        $stmt_get_item->execute();
        $result_item = $stmt_get_item->get_result();
        if ($item_details = $result_item->fetch_assoc()) {
            $product_id = (int)$item_details['product_id'];
            $old_quantity = (int)$item_details['quantity'];
            $item_price = (float)$item_details['price'];
            $current_product_stock = (int)$item_details['current_stock'];
            $product_name_for_msg = $item_details['product_name'];
        } else {
            throw new Exception("Cart item not found or does not belong to user.");
        }
        $stmt_get_item->close();

        // 2. Calculate quantity difference
        $quantity_difference = $new_quantity - $old_quantity;

        // 3. Check if enough stock for the change
        // If increasing quantity, new_stock = current_stock - quantity_difference
        // If decreasing quantity, new_stock = current_stock - quantity_difference (which is current_stock + abs(quantity_difference))
        $required_stock_change = $quantity_difference; // If positive, we need this much more from stock. If negative, we add back.

        if ($required_stock_change > $current_product_stock) {
             throw new Exception("Not enough stock for \"".htmlspecialchars($product_name_for_msg)."\". Requested {$new_quantity} (needs {$required_stock_change} more), but only {$current_product_stock} available in total.");
        }
        
        // 4. Update quantity in cart
        $stmt_update_cart = $conn->prepare("UPDATE cart SET quantity = ? WHERE id = ? AND user_id = ?");
        if (!$stmt_update_cart) throw new Exception("Prepare failed (update_cart): " . $conn->error);
        $stmt_update_cart->bind_param("iii", $new_quantity, $cart_item_id, $user_id);
        if (!$stmt_update_cart->execute()) throw new Exception("Execute failed (update_cart): " . $stmt_update_cart->error);
        $stmt_update_cart->close();

        // 5. Update product stock in products table
        // new stock = current stock - quantity_difference
        $stmt_update_stock = $conn->prepare("UPDATE products SET `count` = `count` - ? WHERE id = ?");
        if (!$stmt_update_stock) throw new Exception("Prepare failed (update_stock): " . $conn->error);
        $stmt_update_stock->bind_param("ii", $quantity_difference, $product_id);
        if (!$stmt_update_stock->execute()) throw new Exception("Execute failed (update_stock): " . $stmt_update_stock->error);
        $stmt_update_stock->close();
        
        $response['updated_product_stock'] = $current_product_stock - $quantity_difference;

        // 6. Calculate new totals
        $response['new_subtotal'] = $item_price * $new_quantity;

        $stmt_grand_total = $conn->prepare("SELECT SUM(p.price * c.quantity) AS grand_total, SUM(c.quantity) AS total_items 
                                            FROM cart c 
                                            JOIN products p ON c.product_id = p.id 
                                            WHERE c.user_id = ?");
        if (!$stmt_grand_total) throw new Exception("Prepare failed (grand_total): " . $conn->error);
        $stmt_grand_total->bind_param("i", $user_id);
        $stmt_grand_total->execute();
        $result_grand_total = $stmt_grand_total->get_result();
        if ($total_row = $result_grand_total->fetch_assoc()) {
            $response['new_grand_total'] = (float)($total_row['grand_total'] ?? 0);
            $response['new_cart_total_items'] = (int)($total_row['total_items'] ?? 0);
        }
        $stmt_grand_total->close();

        $conn->commit();
        $response['success'] = true;
        $response['message'] = "Quantity for \"".htmlspecialchars($product_name_for_msg)."\" updated to {$new_quantity}.";

    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = $e->getMessage();
        error_log("Update Cart AJAX Error: " . $e->getMessage());
    }

} else {
     $response['message'] = 'Required data not provided.';
}

if (is_object($conn) && method_exists($conn, 'close')) {
    $conn->close();
}
header('Content-Type: application/json');
echo json_encode($response);
exit();
?>
