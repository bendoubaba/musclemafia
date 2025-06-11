<?php
// live_search.php

// Include the database connection file
// Make sure this path is correct and $conn is established.
include("database.php");

$output = '';

// Check if the query POST variable is set and not empty
if (isset($_POST['query']) && !empty(trim($_POST['query']))) {
    $search_query = trim($_POST['query']);
    $search_param = "%" . $search_query . "%"; // Prepare search parameter for LIKE query

    // SQL query to search for products
    // It searches in product name and description. Adjust as needed.
    // Fetches limited results for performance.
    $stmt = $conn->prepare("SELECT id, product, price, picture FROM products WHERE (product LIKE ? OR description LIKE ?)  LIMIT 8");

    if ($stmt) {
        $stmt->bind_param("ss", $search_param, $search_param);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            // Start building the HTML output for search results
            $output .= '<ul class="divide-y divide-gray-700">'; // Dark theme divider
            while ($row = $result->fetch_assoc()) {
                $product_name = htmlspecialchars($row['product']);
                $product_id = (int)$row['id'];
                $product_price = number_format($row['price'], 2);
                $product_picture = htmlspecialchars($row['picture'] ? $row['picture'] : 'https://placehold.co/40x40/374151/9CA3AF?text=N/A'); // Dark placeholder

                // Each search result item
                // Note: The link should ideally go to a product detail page.
                // If you don't have one, you might link to add to cart or a modal.
                // For now, it's a placeholder link.
                $output .= '<li class="p-3 hover:bg-gray-700 transition-colors duration-150">';
                // You'll need to pass user_id if product_details.php requires it or for Add to Cart functionality from search
                // For simplicity, I'm making a generic product link here.
                $output .= '<a href="product_details.php?product_id=' . $product_id . '" class="flex items-center space-x-3 text-gray-200">';
                $output .= '<img src="' . $product_picture . '" alt="' . $product_name . '" class="w-10 h-10 rounded object-cover flex-shrink-0" onerror="this.onerror=null;this.src=\'https://placehold.co/40x40/374151/9CA3AF?text=N/A\';">';
                $output .= '<div class="flex-grow">';
                $output .= '<p class="font-semibold text-sm">' . $product_name . '</p>';
                $output .= '<p class="text-xs text-gray-400">$' . $product_price . '</p>';
                $output .= '</div>';
                $output .= '</a>';
                $output .= '</li>';
            }
            $output .= '</ul>';
        } else {
            // No products found
            $output = '<p class="p-3 text-gray-400 text-sm">No products found matching your search.</p>';
        }
        $stmt->close();
    } else {
        // Error in preparing the statement
        $output = '<p class="p-3 text-red-400 text-sm">Search error. Please try again.</p>';
        if (isset($conn) && $conn instanceof mysqli) {
            error_log("Live search prepare error: {$conn->error}"); // Log error for admin
        } else {
            error_log("Live search prepare error: Database connection is invalid or not established.");
        }
    }
} else if (isset($_POST['query']) && empty(trim($_POST['query']))) {
    // Query is empty, so don't show anything or show "type to search"
    $output = ''; // Or a message like '<p class="p-3 text-gray-500 text-sm">Type to search for products.</p>'
}

$conn->close();
echo $output; // Output the HTML for search results
?>