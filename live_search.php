<?php
// live_search.php
include("database.php"); // Ensure this path is correct

header('Content-Type: application/json');
$output = ['success' => false, 'html' => '<p class="p-3 text-gray-400 text-sm text-center">No input provided.</p>'];

// For guest view, user_id might not be relevant for search result links,
// but good to have if you want to customize later.
// $user_id_for_links = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0; 

if (isset($_POST['query']) && !empty(trim($_POST['query']))) {
    $search_query = trim($_POST['query']);
    $search_param = "%" . $search_query . "%";

    // Search in product name and description.
    // Ensure 'price' and 'picture' columns exist in your 'products' table.
    $stmt = $conn->prepare("SELECT id, product, price, picture FROM products WHERE (product LIKE ? OR description LIKE ?) LIMIT 6");

    if ($stmt) {
        $stmt->bind_param("ss", $search_param, $search_param);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $html_output = '<ul class="divide-y divide-gray-700">';
            while ($row = $result->fetch_assoc()) {
                $product_name = htmlspecialchars($row['product']);
                $product_id = (int)$row['id'];
                $product_price = number_format((float)($row['price'] ?? 0), 2);
                $product_picture = htmlspecialchars($row['picture'] ? $row['picture'] : 'https://placehold.co/40x40/1a1a1a/333333?text=Nyx');

                // Link for guests should go to login, passing product ID for potential redirect after login
                $login_redirect_url = "product_details.php?product_id=" . $product_id;

                $html_output .= '<li class="p-3 hover:bg-gray-800 transition-colors duration-150">';
                $html_output .= '<a href="' . $login_redirect_url . '" class="flex items-center space-x-3 text-gray-300 hover:text-cyan-400">';
                $html_output .= '<img src="' . $product_picture . '" alt="' . $product_name . '" class="w-10 h-10 rounded-md object-cover flex-shrink-0 border border-gray-700" onerror="this.onerror=null;this.src=\'https://placehold.co/40x40/1a1a1a/333333?text=Nyx\';">';
                $html_output .= '<div class="flex-grow">';
                $html_output .= '<p class="font-semibold text-sm">' . $product_name . '</p>';
                $html_output .= '<p class="text-xs text-cyan-500">$' . $product_price . '</p>';
                $html_output .= '</div>';
                $html_output .= '</a>';
                $html_output .= '</li>';
            }
            $html_output .= '</ul>';
            $output = ['success' => true, 'html' => $html_output];
        } else {
            $output = ['success' => false, 'html' => '<p class="p-3 text-gray-400 text-sm text-center">No items match your vision.</p>'];
        }
        $stmt->close();
    } else {
        $output = ['success' => false, 'html' => '<p class="p-3 text-red-400 text-sm text-center">Search error. Please try again.</p>'];
        error_log("Live search prepare error: " . $conn->error);
    }
} elseif (isset($_POST['query']) && empty(trim($_POST['query']))) {
    $output = ['success' => false, 'html' => '']; // Clear results if query is empty
}

if (is_object($conn) && method_exists($conn, 'close')) {
    $conn->close();
}
echo json_encode($output);
exit();
?>
