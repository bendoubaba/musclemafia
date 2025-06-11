<?php
session_start();
// The include should happen *after* potential session-related headers, but before $conn is used.
// For now, keeping it here, assuming database.php doesn't output anything or start sessions.
include('database.php'); // Ensure this file uses mysqli and sets up $conn

// --- AUTHENTICATION CHECK ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin' || !isset($_SESSION['admin_id'])) {
    // For production, uncomment and ensure this redirect works:
    // header('Location: /login.php');
    // exit;

    // Demo fallback (remove for production)
    if (!isset($_SESSION['admin_id']))
        $_SESSION['admin_id'] = 1; // Default admin ID for demo
    if (!isset($_SESSION['admin_name']))
        $_SESSION['admin_name'] = 'Demo Admin';
    if (!isset($_SESSION['user_role']))
        $_SESSION['user_role'] = 'admin';
}
$admin_id_session = $_SESSION['admin_id'];

// --- UPLOAD DIRECTORY SETUP ---
define('UPLOAD_BASE_DIR_RELATIVE_TO_SCRIPT_PARENT', '../upload/');
define('UPLOAD_BASE_DIR_FULL_PATH', realpath(dirname(__FILE__) . '/' . UPLOAD_BASE_DIR_RELATIVE_TO_SCRIPT_PARENT) ?: dirname(__FILE__) . '/' . UPLOAD_BASE_DIR_RELATIVE_TO_SCRIPT_PARENT);

define('PRODUCT_IMG_DIR_NAME', 'products');
define('CATEGORY_ICO_DIR_NAME', 'categories');
define('CHAT_FILES_DIR_NAME', 'chat_files'); 

define('PRODUCT_IMG_DIR_FULL_PATH', rtrim(UPLOAD_BASE_DIR_FULL_PATH, '/') . '/' . PRODUCT_IMG_DIR_NAME . '/');
define('CATEGORY_ICO_DIR_FULL_PATH', rtrim(UPLOAD_BASE_DIR_FULL_PATH, '/') . '/' . CATEGORY_ICO_DIR_NAME . '/');
define('CHAT_FILES_DIR_FULL_PATH', rtrim(UPLOAD_BASE_DIR_FULL_PATH, '/') . '/' . CHAT_FILES_DIR_NAME . '/');


define('BASE_UPLOAD_URL_PATH', '/upload/'); 
define('PRODUCT_IMG_URL_PREFIX', BASE_UPLOAD_URL_PATH . PRODUCT_IMG_DIR_NAME . '/');
define('CATEGORY_ICO_URL_PREFIX', BASE_UPLOAD_URL_PATH . CATEGORY_ICO_DIR_NAME . '/');
define('CHAT_FILES_URL_PREFIX', BASE_UPLOAD_URL_PATH . CHAT_FILES_DIR_NAME . '/');


function ensure_directory_exists($dir_path)
{
    if (!is_dir($dir_path)) {
        if (!mkdir($dir_path, 0755, true)) { 
            error_log("Failed to create directory: " . $dir_path . " - Check permissions and path.");
            return false;
        }
    }
    if (!is_writable($dir_path)) {
        error_log("Directory not writable: " . $dir_path . " - Check permissions.");
        return false;
    }
    return true;
}

ensure_directory_exists(PRODUCT_IMG_DIR_FULL_PATH);
ensure_directory_exists(CATEGORY_ICO_DIR_FULL_PATH);
ensure_directory_exists(CHAT_FILES_DIR_FULL_PATH); 

function handle_file_upload($file_input_name, $upload_target_full_path_dir, $url_prefix)
{
    if (!ensure_directory_exists($upload_target_full_path_dir)) {
        return ['error' => 'Upload directory is not accessible or writable: ' . basename($upload_target_full_path_dir)];
    }
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == UPLOAD_ERR_OK) {
        $file = $_FILES[$file_input_name];
        $allowed_mimes_general = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']; 
        $allowed_extensions_general = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'doc', 'docx']; 
        
        if ($upload_target_full_path_dir === PRODUCT_IMG_DIR_FULL_PATH || $upload_target_full_path_dir === CATEGORY_ICO_DIR_FULL_PATH) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        } else { 
            $allowed_types = $allowed_mimes_general;
            $allowed_extensions = $allowed_extensions_general;
        }

        $max_size = 50 * 1024 * 1024; // 50MB

        $file_mime_type = mime_content_type($file['tmp_name']);
        if (!in_array($file_mime_type, $allowed_types)) { 
            return ['error' => 'Invalid file type. Detected: ' . $file_mime_type . '. Allowed: ' . implode(', ', $allowed_extensions)];
        }
        if ($file['size'] > $max_size) {
            return ['error' => 'File is too large. Max 50MB allowed.'];
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed_extensions)) { 
            return ['error' => 'Invalid file extension. Please use allowed types: ' . implode(', ', $allowed_extensions)];
        }
        
        $original_filename_base = pathinfo($file['name'], PATHINFO_FILENAME);
        $safe_original_filename_base = preg_replace("/[^a-zA-Z0-9_-]/", "", $original_filename_base);
        $filename = uniqid($safe_original_filename_base . '_', true) . '.' . $extension;
        $destination = rtrim($upload_target_full_path_dir, '/') . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            return ['success' => true, 'path' => rtrim($url_prefix, '/') . '/' . $filename, 'original_name' => $file['name']];
        } else {
            $last_error = error_get_last();
            $move_error_message = 'Failed to move uploaded file.';
            if ($last_error && strpos($last_error['message'], 'move_uploaded_file') !== false) {
                $move_error_message .= ' System error: ' . $last_error['message'];
            }
            error_log($move_error_message . " Temp: {$file['tmp_name']}, Dest: {$destination}");
            return ['error' => $move_error_message . ' PHP Error Code: ' . $file['error']];
        }
    } elseif (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] != UPLOAD_ERR_NO_FILE) {
        return ['error' => 'File upload error code: ' . $_FILES[$file_input_name]['error']];
    }
    return ['success' => false]; 
}

function delete_local_file($relative_url_path)
{
    if (empty($relative_url_path)) return;

    $known_prefixes = [BASE_UPLOAD_URL_PATH . PRODUCT_IMG_DIR_NAME . '/', BASE_UPLOAD_URL_PATH . CATEGORY_ICO_DIR_NAME . '/', BASE_UPLOAD_URL_PATH . CHAT_FILES_DIR_NAME . '/'];
    $is_local_managed = false;
    foreach($known_prefixes as $prefix){
        if(strpos($relative_url_path, $prefix) === 0){
            $is_local_managed = true;
            break;
        }
    }
    if (!$is_local_managed && strpos($relative_url_path, BASE_UPLOAD_URL_PATH) === 0) {
         $is_local_managed = true; 
    }

    if ($is_local_managed) {
        $file_path_segment = substr($relative_url_path, strlen(BASE_UPLOAD_URL_PATH));
        $full_server_path = rtrim(UPLOAD_BASE_DIR_FULL_PATH, '/') . '/' . ltrim($file_path_segment, '/');

        if (file_exists($full_server_path) && is_file($full_server_path)) {
            if (!@unlink($full_server_path)) {
                error_log("Failed to delete local file: " . $full_server_path);
            }
        }
    }
}

// --- AJAX HANDLER ---
if (isset($_REQUEST['ajax_action'])) {
    // Attempt to set up more robust error handling for AJAX
    ini_set('display_errors', 0); 
    ini_set('log_errors', 1);     
    // IMPORTANT: Ensure this path is writable by the web server user.
    // You might need to create this file and set permissions.
    // A common practice is to log to a general PHP error log configured in php.ini
    // ini_set('error_log', dirname(__FILE__) . '/ajax_php_errors.log'); // Example path
    error_reporting(E_ALL); // Report all PHP errors to the log

    // Test basic error logging - check your PHP error log for this line
    // error_log("AJAX handler started for action: " . ($_REQUEST['ajax_action'] ?? 'UNKNOWN_ACTION'));

    header('Content-Type: application/json'); 
    ob_start(); 

    if (!isset($conn) || !($conn instanceof mysqli)) {
        $error_msg = '$conn is not a valid mysqli object. Check database.php include and connection.';
        error_log("AJAX Critical Error: " . $error_msg);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Server configuration error (DB). Please check server logs.']);
        exit();
    }
    if ($conn->connect_error) {
        $error_msg = "Database connection failed: " . $conn->connect_error;
        error_log("AJAX Critical Error: " . $error_msg);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Server error (DB Connection). Please check server logs.']);
        exit();
    }

    $action = $_REQUEST['ajax_action'];
    $response = ['success' => false, 'message' => 'Invalid AJAX action.']; 

    // --- PRODUCT AJAX ACTIONS ---
    if ($action === 'get_products') {
        $search = isset($_GET['search']) ? '%' . $conn->real_escape_string(trim($_GET['search'])) . '%' : '%';
        $stmt = $conn->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.product LIKE ? OR p.description LIKE ? OR c.name LIKE ? ORDER BY p.id DESC");
        if ($stmt) {
            $stmt->bind_param("sss", $search, $search, $search);
            $stmt->execute();
            $result = $stmt->get_result();
            $products = [];
            while ($row = $result->fetch_assoc()) {
                $products[] = $row;
            }
            $stmt->close();
            $response = ['success' => true, 'products' => $products];
        } else {
            $response['message'] = "Error preparing statement (get_products): " . $conn->error;
            error_log("Get Products Error: " . $conn->error);
        }
    } elseif ($action === 'add_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $product_name = trim($_POST['product_name_modal']);
        $category_id = isset($_POST['category_id_modal']) ? (int) $_POST['category_id_modal'] : 0;
        $description = trim($_POST['description_modal']);
        $count = isset($_POST['count_modal']) ? (int) $_POST['count_modal'] : 0;
        $price = isset($_POST['price_modal']) ? (float) $_POST['price_modal'] : 0.0;
        $image_source_type = $_POST['image_source_type_product'] ?? 'url';
        $picture_path_to_store = null;
        $newly_uploaded_file_path = null;
        $error_occurred = false;

        if ($image_source_type === 'file' && isset($_FILES['product_image_file']) && $_FILES['product_image_file']['error'] != UPLOAD_ERR_NO_FILE) {
            $upload_result = handle_file_upload('product_image_file', PRODUCT_IMG_DIR_FULL_PATH, PRODUCT_IMG_URL_PREFIX);
            if (isset($upload_result['error'])) {
                $response['message'] = $upload_result['error'];
                $error_occurred = true;
            } elseif ($upload_result['success']) {
                $picture_path_to_store = $upload_result['path'];
                $newly_uploaded_file_path = $picture_path_to_store;
            }
        } elseif ($image_source_type === 'url' && !empty(trim($_POST['picture_url_modal']))) {
            $url_input = trim($_POST['picture_url_modal']);
            if (filter_var($url_input, FILTER_VALIDATE_URL)) {
                $picture_path_to_store = $url_input;
            } else {
                $response['message'] = 'Invalid image URL provided for product.';
                $error_occurred = true;
            }
        }

        if (!$error_occurred) {
            if (empty($product_name) || empty($category_id) || $price < 0 || $count < 0) {
                $response['message'] = 'Product name, category, valid price (>=0), and count (>=0) are required.';
                if ($newly_uploaded_file_path) delete_local_file($newly_uploaded_file_path);
            } else {
                $stmt = $conn->prepare("INSERT INTO products (product, category_id, description, `count`, price, picture) VALUES (?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("sisids", $product_name, $category_id, $description, $count, $price, $picture_path_to_store);
                    if ($stmt->execute()) {
                        $response = ['success' => true, 'message' => 'Product added successfully!', 'product_id' => $stmt->insert_id];
                    } else {
                        $response['message'] = 'Failed to add product: ' . $stmt->error;
                        error_log("Add Product DB Error: " . $stmt->error);
                        if ($newly_uploaded_file_path) delete_local_file($newly_uploaded_file_path);
                    }
                    $stmt->close();
                } else {
                    $response['message'] = "Error preparing statement (add_product): " . $conn->error;
                    error_log("Add Product Prepare Error: " . $conn->error);
                    if ($newly_uploaded_file_path) delete_local_file($newly_uploaded_file_path);
                }
            }
        }
    } elseif ($action === 'get_product_details' && isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($product = $result->fetch_assoc()) {
                $response = ['success' => true, 'product' => $product];
            } else {
                $response['message'] = 'Product not found.';
            }
            $stmt->close();
        } else {
            $response['message'] = "Error preparing statement (get_product_details): " . $conn->error;
             error_log("Get Product Details Error: " . $conn->error);
        }
    } elseif ($action === 'update_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = isset($_POST['edit_product_id']) ? (int) $_POST['edit_product_id'] : 0;
        $product_name = trim($_POST['product_name_modal']);
        $category_id = isset($_POST['category_id_modal']) ? (int) $_POST['category_id_modal'] : 0;
        $description = trim($_POST['description_modal']);
        $count = isset($_POST['count_modal']) ? (int) $_POST['count_modal'] : 0;
        $price = isset($_POST['price_modal']) ? (float) $_POST['price_modal'] : 0.0;
        $image_source_type = $_POST['image_source_type_product'] ?? 'url';
        $error_occurred = false;
        
        $existing_picture_db_path = null; 
        $stmt_get_old_pic = $conn->prepare("SELECT picture FROM products WHERE id = ?");
        if($stmt_get_old_pic) {
            $stmt_get_old_pic->bind_param("i", $id);
            $stmt_get_old_pic->execute();
            $result_old_pic = $stmt_get_old_pic->get_result();
            if($row_old_pic = $result_old_pic->fetch_assoc()){
                $existing_picture_db_path = $row_old_pic['picture'];
            }
            $stmt_get_old_pic->close();
        } else {
            $response['message'] = "Error fetching existing product image: " . $conn->error;
            error_log("Update Product - Get Old Pic Error: " . $conn->error);
            $error_occurred = true;
        }

        $picture_path_for_update = $existing_picture_db_path; 
        $newly_uploaded_file_for_cleanup = null; 

        if (!$error_occurred) {
            if (isset($_FILES['product_image_file']) && $_FILES['product_image_file']['error'] == UPLOAD_ERR_OK) {
                $upload_result = handle_file_upload('product_image_file', PRODUCT_IMG_DIR_FULL_PATH, PRODUCT_IMG_URL_PREFIX);
                if (isset($upload_result['error'])) {
                    $response['message'] = "File Upload Error: " . $upload_result['error'];
                    $error_occurred = true;
                } elseif ($upload_result['success']) {
                    $picture_path_for_update = $upload_result['path'];
                    $newly_uploaded_file_for_cleanup = $picture_path_for_update; 
                }
            } 
            elseif ($image_source_type === 'url') {
                $url_input = trim($_POST['picture_url_modal']);
                if ($url_input !== $existing_picture_db_path || (empty($url_input) && !is_null($existing_picture_db_path)) ) {
                     if (!empty($url_input)) { 
                        if (filter_var($url_input, FILTER_VALIDATE_URL)) {
                            $picture_path_for_update = $url_input;
                        } else {
                            $response['message'] = 'Invalid new image URL provided for product.';
                            $error_occurred = true;
                        }
                    } else { 
                        $picture_path_for_update = null; 
                    }
                }
            }
        }
        
        if (!$error_occurred) {
            if (empty($id) || empty($product_name) || empty($category_id) || $price < 0 || $count < 0) {
                $response['message'] = 'Product ID, name, category, valid price (>=0), and count (>=0) are required.';
                if ($newly_uploaded_file_for_cleanup) delete_local_file($newly_uploaded_file_for_cleanup);
            } else {
                $stmt = $conn->prepare("UPDATE products SET product = ?, category_id = ?, description = ?, `count` = ?, price = ?, picture = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("sisidsi", $product_name, $category_id, $description, $count, $price, $picture_path_for_update, $id);
                    if ($stmt->execute()) {
                        if ($picture_path_for_update !== $existing_picture_db_path && !empty($existing_picture_db_path) && strpos($existing_picture_db_path, BASE_UPLOAD_URL_PATH) === 0) {
                            delete_local_file($existing_picture_db_path);
                        }
                        $response = ['success' => true, 'message' => 'Product updated successfully!'];
                    } else {
                        $response['message'] = 'Failed to update product: ' . $stmt->error;
                        error_log("Update Product DB Error: " . $stmt->error);
                        if ($newly_uploaded_file_for_cleanup && $newly_uploaded_file_for_cleanup !== $existing_picture_db_path) { 
                            delete_local_file($newly_uploaded_file_for_cleanup);
                        }
                    }
                    $stmt->close();
                } else {
                    $response['message'] = "Error preparing statement (update_product): " . $conn->error;
                    error_log("Update Product Prepare Error: " . $conn->error);
                    if ($newly_uploaded_file_for_cleanup) delete_local_file($newly_uploaded_file_for_cleanup);
                }
            }
        }
    } elseif ($action === 'delete_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if (empty($id)) {
            $response['message'] = 'Product ID is required for deletion.';
        } else {
            $old_picture_path = null;
            $stmt_get_pic = $conn->prepare("SELECT picture FROM products WHERE id = ?");
            if ($stmt_get_pic) {
                $stmt_get_pic->bind_param("i", $id);
                $stmt_get_pic->execute();
                $result_pic = $stmt_get_pic->get_result();
                if ($row_pic = $result_pic->fetch_assoc()) {
                    $old_picture_path = $row_pic['picture'];
                }
                $stmt_get_pic->close();
            } else {
                 error_log("Delete Product - Get Pic Error: " . $conn->error);
            }

            $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    delete_local_file($old_picture_path);
                    $response = ['success' => true, 'message' => 'Product deleted successfully!'];
                } else {
                    $response['message'] = 'Failed to delete product: ' . $stmt->error;
                    error_log("Delete Product DB Error: " . $stmt->error);
                }
                $stmt->close();
            } else {
                $response['message'] = "Error preparing statement (delete_product): " . $conn->error;
                error_log("Delete Product Prepare Error: " . $conn->error);
            }
        }
    }

    // --- CATEGORY AJAX ACTIONS ---
    elseif ($action === 'get_categories') {
        $search = isset($_GET['search']) ? '%' . $conn->real_escape_string(trim($_GET['search'])) . '%' : '%';
        $stmt = $conn->prepare("
            SELECT c.*, COUNT(p.id) as product_count 
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id
            WHERE c.name LIKE ? 
            GROUP BY c.id, c.name, c.ico_img
            ORDER BY c.name ASC
        ");
        if ($stmt) {
            $stmt->bind_param("s", $search);
            $stmt->execute();
            $result = $stmt->get_result();
            $categories = [];
            while ($row = $result->fetch_assoc()) {
                $categories[] = $row;
            }
            $stmt->close();
            $response = ['success' => true, 'categories' => $categories];
        } else {
            $response['message'] = "Error preparing statement (get_categories): " . $conn->error;
            error_log("Get Categories Error: " . $conn->error);
        }
    } 
    elseif ($action === 'add_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $category_name = trim($_POST['category_name_modal']);
        $image_source_type = $_POST['image_source_type_category'] ?? 'url';
        $ico_img_path_to_store = null;
        $newly_uploaded_ico_path = null;
        $error_occurred = false;

        if ($image_source_type === 'file' && isset($_FILES['category_ico_file']) && $_FILES['category_ico_file']['error'] != UPLOAD_ERR_NO_FILE) {
            $upload_result = handle_file_upload('category_ico_file', CATEGORY_ICO_DIR_FULL_PATH, CATEGORY_ICO_URL_PREFIX);
            if (isset($upload_result['error'])) {
                $response['message'] = $upload_result['error'];
                $error_occurred = true;
            } elseif ($upload_result['success']) {
                $ico_img_path_to_store = $upload_result['path'];
                $newly_uploaded_ico_path = $ico_img_path_to_store;
            }
        } elseif ($image_source_type === 'url' && !empty(trim($_POST['ico_img_url_modal']))) {
            $url_input = trim($_POST['ico_img_url_modal']);
            if (filter_var($url_input, FILTER_VALIDATE_URL)) {
                $ico_img_path_to_store = $url_input;
            } else {
                $response['message'] = 'Invalid icon URL provided for category.';
                $error_occurred = true;
            }
        }
        
        if (!$error_occurred) {
            if (empty($category_name)) {
                $response['message'] = 'Category name is required.';
                if ($newly_uploaded_ico_path) delete_local_file($newly_uploaded_ico_path);
            } else {
                $stmt = $conn->prepare("INSERT INTO categories (name, ico_img) VALUES (?, ?)");
                if ($stmt) {
                    $stmt->bind_param("ss", $category_name, $ico_img_path_to_store);
                    if ($stmt->execute()) {
                        $response = ['success' => true, 'message' => 'Category added successfully!', 'category_id' => $stmt->insert_id];
                    } else {
                        $response['message'] = 'Failed to add category: ' . $stmt->error;
                        error_log("Add Category DB Error: " . $stmt->error);
                        if ($newly_uploaded_ico_path) delete_local_file($newly_uploaded_ico_path);
                    }
                    $stmt->close();
                } else {
                    $response['message'] = "Error preparing statement (add_category): " . $conn->error;
                    error_log("Add Category Prepare Error: " . $conn->error);
                    if ($newly_uploaded_ico_path) delete_local_file($newly_uploaded_ico_path);
                }
            }
        }
    }
    elseif ($action === 'get_category_details' && isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        $stmt = $conn->prepare("SELECT * FROM categories WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($category = $result->fetch_assoc()) {
                $response = ['success' => true, 'category' => $category];
            } else {
                $response['message'] = 'Category not found.';
            }
            $stmt->close();
        } else {
            $response['message'] = "Error preparing statement (get_category_details): " . $conn->error;
            error_log("Get Category Details Error: " . $conn->error);
        }
    } elseif ($action === 'update_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = isset($_POST['edit_category_id']) ? (int) $_POST['edit_category_id'] : 0; 
        $category_name = trim($_POST['category_name_modal']);
        $image_source_type = $_POST['image_source_type_category'] ?? 'url';
        $error_occurred = false;

        $existing_ico_db_path = null;
        $stmt_get_old_ico = $conn->prepare("SELECT ico_img FROM categories WHERE id = ?");
        if($stmt_get_old_ico){
            $stmt_get_old_ico->bind_param("i", $id);
            $stmt_get_old_ico->execute();
            $result_old_ico = $stmt_get_old_ico->get_result();
            if($row_old_ico = $result_old_ico->fetch_assoc()){
                $existing_ico_db_path = $row_old_ico['ico_img'];
            }
            $stmt_get_old_ico->close();
        } else {
            $response['message'] = "Error fetching existing category icon: " . $conn->error;
            error_log("Update Category - Get Old Ico Error: " . $conn->error);
            $error_occurred = true;
        }
        
        $ico_path_for_update = $existing_ico_db_path;
        $newly_uploaded_ico_for_cleanup = null;

        if(!$error_occurred) {
            if (isset($_FILES['category_ico_file']) && $_FILES['category_ico_file']['error'] == UPLOAD_ERR_OK) {
                $upload_result = handle_file_upload('category_ico_file', CATEGORY_ICO_DIR_FULL_PATH, CATEGORY_ICO_URL_PREFIX);
                if (isset($upload_result['error'])) {
                    $response['message'] = "File Upload Error: " . $upload_result['error'];
                    $error_occurred = true;
                } elseif ($upload_result['success']) {
                    $ico_path_for_update = $upload_result['path'];
                    $newly_uploaded_ico_for_cleanup = $ico_path_for_update;
                }
            } 
            elseif ($image_source_type === 'url') {
                $url_input = trim($_POST['ico_img_url_modal']);
                if ($url_input !== $existing_ico_db_path || (empty($url_input) && !is_null($existing_ico_db_path))) {
                     if (!empty($url_input)) {
                        if (filter_var($url_input, FILTER_VALIDATE_URL)) {
                            $ico_path_for_update = $url_input;
                        } else {
                            $response['message'] = 'Invalid new icon URL provided for category.';
                             $error_occurred = true;
                        }
                    } else {
                         $ico_path_for_update = null;
                    }
                }
            }
        }

        if (!$error_occurred) {
            if (empty($id) || empty($category_name)) {
                $response['message'] = 'Category ID and name are required.';
                if ($newly_uploaded_ico_for_cleanup) delete_local_file($newly_uploaded_ico_for_cleanup);
            } else {
                $stmt = $conn->prepare("UPDATE categories SET name = ?, ico_img = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("ssi", $category_name, $ico_path_for_update, $id);
                    if ($stmt->execute()) {
                        if ($ico_path_for_update !== $existing_ico_db_path && !empty($existing_ico_db_path) && strpos($existing_ico_db_path, BASE_UPLOAD_URL_PATH) === 0) {
                            delete_local_file($existing_ico_db_path);
                        }
                        $response = ['success' => true, 'message' => 'Category updated successfully!'];
                    } else {
                        $response['message'] = 'Failed to update category: ' . $stmt->error;
                        error_log("Update Category DB Error: " . $stmt->error);
                        if ($newly_uploaded_ico_for_cleanup && $newly_uploaded_ico_for_cleanup !== $existing_ico_db_path) {
                             delete_local_file($newly_uploaded_ico_for_cleanup);
                        }
                    }
                    $stmt->close();
                } else {
                    $response['message'] = "Error preparing statement (update_category): " . $conn->error;
                    error_log("Update Category Prepare Error: " . $conn->error);
                     if ($newly_uploaded_ico_for_cleanup) delete_local_file($newly_uploaded_ico_for_cleanup);
                }
            }
        }
    } elseif ($action === 'delete_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if (empty($id)) {
            $response['message'] = 'Category ID is required for deletion.';
        } else {
            $old_ico_path = null;
            $stmt_get_ico = $conn->prepare("SELECT ico_img FROM categories WHERE id = ?");
            if ($stmt_get_ico) {
                $stmt_get_ico->bind_param("i", $id);
                $stmt_get_ico->execute();
                $result_ico = $stmt_get_ico->get_result();
                if ($row_ico = $result_ico->fetch_assoc()) {
                    $old_ico_path = $row_ico['ico_img'];
                }
                $stmt_get_ico->close();
            } else {
                 error_log("Delete Category - Get Ico Error: " . $conn->error);
            }

            $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
            if (!$stmt) {
                $response['message'] = "Error preparing delete statement (delete_category): " . $conn->error;
                 error_log("Delete Category Prepare Error: " . $conn->error);
            } else {
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) { 
                    delete_local_file($old_ico_path);
                    $response = ['success' => true, 'message' => 'Category deleted successfully! Associated products may also be affected if cascade is set.'];
                } else {
                    $response['message'] = 'Failed to delete category. Error: ' . $stmt->error . '. Ensure no products are linked if ON DELETE CASCADE is not active.';
                    error_log("Error deleting category ID {$id}: " . $stmt->error);
                }
                $stmt->close();
            }
        }
    }

    // --- USER AJAX ACTIONS ---
    elseif ($action === 'get_users') { 
        $search = isset($_GET['search']) ? '%' . $conn->real_escape_string(trim($_GET['search'])) . '%' : '%';
        $is_for_chat = isset($_GET['for_chat']) && $_GET['for_chat'] == '1';
        $sql_where_clause = "WHERE (u.username LIKE ? OR u.email LIKE ?)";
        if ($is_for_chat) {
            $sql_where_clause .= " AND u.id != " . (int)$admin_id_session; 
        }

        $sql = "SELECT u.id, u.username, u.email, u.role, 
                       (SELECT COUNT(c.id) FROM cart c WHERE c.user_id = u.id) as cart_item_count,
                       (SELECT MAX(m.created_at) FROM messages m WHERE (m.sender_id = u.id AND m.receiver_id = ".(int)$admin_id_session.") OR (m.receiver_id = u.id AND m.sender_id = ".(int)$admin_id_session.")) as last_message_time
                FROM users u 
                $sql_where_clause
                ORDER BY last_message_time DESC, u.username ASC"; 
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ss", $search, $search);
            $stmt->execute();
            $result = $stmt->get_result();
            $users = [];
            while ($row = $result->fetch_assoc()) {
                $row['cart_item_count'] = (int) ($row['cart_item_count'] ?? 0);
                $users[] = $row;
            }
            $stmt->close();
            $response = ['success' => true, 'users' => $users];
        } else {
            $response['message'] = "Error preparing statement (get_users): " . $conn->error;
            error_log("Get Users Error: " . $conn->error);
        }
    } 
    elseif ($action === 'update_user_role' && $_SERVER['REQUEST_METHOD'] === 'POST') { 
        $user_id_to_update = isset($_POST['user_id_to_update']) ? (int) $_POST['user_id_to_update'] : 0;
        $new_role = trim($_POST['new_role_value']);

        if (empty($user_id_to_update)) {
            $response['message'] = 'User ID is required.';
        } elseif ($user_id_to_update === $admin_id_session && $new_role !== 'admin') {
             $response['message'] = 'Admin cannot change their own role from admin.';
        } elseif (in_array($new_role, ['user', 'admin'])) {
            $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $new_role, $user_id_to_update);
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'User role updated.'];
                } else {
                    $response['message'] = 'Failed to update user role: ' . $stmt->error;
                    error_log("Update User Role DB Error: " . $stmt->error);
                }
                $stmt->close();
            } else {
                $response['message'] = "Error preparing statement (update_user_role): " . $conn->error;
                error_log("Update User Role Prepare Error: " . $conn->error);
            }
        } else {
            $response['message'] = 'Invalid role specified.';
        }
    } 
    elseif ($action === 'get_user_order_history' && isset($_GET['user_id'])) {
        $user_id_to_view = (int)$_GET['user_id'];
        $user_factures = [];
        $user_details_for_modal = null;
    
        $stmt_user = $conn->prepare("SELECT username FROM users WHERE id = ?");
        if ($stmt_user) {
            $stmt_user->bind_param("i", $user_id_to_view);
            $stmt_user->execute();
            $result_user = $stmt_user->get_result();
            if ($user_row = $result_user->fetch_assoc()) {
                $user_details_for_modal = $user_row;
            }
            $stmt_user->close();
        } else {
             error_log("Get User Order History - User Fetch Prepare Error: " . $conn->error);
        }
    
        if (!$user_details_for_modal) {
            $response = ['success' => false, 'message' => 'User not found.'];
        } else {
            $sql_factures = "SELECT id, total_price, created_at, shipping_address, phone_number 
                             FROM factures 
                             WHERE user_id = ? 
                             ORDER BY created_at DESC";
            $stmt_factures = $conn->prepare($sql_factures);
            if ($stmt_factures) {
                $stmt_factures->bind_param("i", $user_id_to_view);
                $stmt_factures->execute();
                $result_factures = $stmt_factures->get_result();
                while ($facture = $result_factures->fetch_assoc()) {
                    $facture_items = [];
                    $sql_facture_items = "SELECT fi.quantity, fi.price as price_at_purchase, p.product as product_name, p.picture as product_image 
                                          FROM facture_items fi
                                          JOIN products p ON fi.product_id = p.id
                                          WHERE fi.facture_id = ?";
                    $stmt_items = $conn->prepare($sql_facture_items);
                    if ($stmt_items) {
                        $stmt_items->bind_param("i", $facture['id']);
                        $stmt_items->execute();
                        $result_items = $stmt_items->get_result();
                        while ($item = $result_items->fetch_assoc()) {
                            $facture_items[] = $item;
                        }
                        $stmt_items->close();
                    } else {
                        error_log("Get User Order History - Items Fetch Prepare Error: " . $conn->error);
                    }
                    $facture['items'] = $facture_items;
                    $user_factures[] = $facture;
                }
                $stmt_factures->close();
                $response = ['success' => true, 'username' => $user_details_for_modal['username'], 'factures' => $user_factures];
            } else {
                $response = ['success' => false, 'message' => 'Error fetching order history: ' . $conn->error];
                error_log("Error preparing factures statement for user {$user_id_to_view}: " . $conn->error);
            }
        }
    }
    elseif ($action === 'delete_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $user_id_to_delete = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if (empty($user_id_to_delete)) {
            $response['message'] = 'User ID is required for deletion.';
        } elseif ($user_id_to_delete === $admin_id_session) {
            $response['message'] = 'Admin cannot delete their own account.';
        } else {
            $conn->begin_transaction();
            try {
                $stmt_cart_del = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
                if (!$stmt_cart_del) throw new Exception("Prepare cart delete failed: " . $conn->error);
                $stmt_cart_del->bind_param("i", $user_id_to_delete);
                $stmt_cart_del->execute();
                $stmt_cart_del->close();

                $stmt_msg_del = $conn->prepare("DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?");
                if (!$stmt_msg_del) throw new Exception("Prepare messages delete failed: " . $conn->error);
                $stmt_msg_del->bind_param("ii", $user_id_to_delete, $user_id_to_delete);
                $stmt_msg_del->execute();
                $stmt_msg_del->close();
                                
                $stmt_user_del = $conn->prepare("DELETE FROM users WHERE id = ?");
                if (!$stmt_user_del) throw new Exception("Prepare user delete failed: " . $conn->error);
                $stmt_user_del->bind_param("i", $user_id_to_delete);
                if ($stmt_user_del->execute()) {
                    if ($stmt_user_del->affected_rows > 0) {
                        $conn->commit();
                        $response = ['success' => true, 'message' => 'User and their associated data deleted successfully.'];
                    } else {
                         throw new Exception('User not found or already deleted.');
                    }
                } else {
                    throw new Exception('Failed to delete user: ' . $stmt_user_del->error);
                }
                $stmt_user_del->close();
            } catch (Exception $e) {
                $conn->rollback();
                $response['message'] = $e->getMessage();
                error_log("User Deletion Error (User ID: {$user_id_to_delete}): " . $e->getMessage());
            }
        }
    }
    // --- CHAT AJAX ACTIONS ---
    elseif ($action === 'get_chat_messages' && isset($_GET['user_id'])) {
        $user_id = (int)$_GET['user_id'];
        $last_message_id = isset($_GET['last_message_id']) ? (int)$_GET['last_message_id'] : 0;
        $messages = [];

        $sql = "SELECT id, sender_id, message, created_at, file_path, typesender, edit
                FROM messages 
                WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
                AND id > ?
                ORDER BY created_at ASC";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("iiiii", $admin_id_session, $user_id, $user_id, $admin_id_session, $last_message_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $messages[] = $row;
            }
            $stmt->close();
            $response = ['success' => true, 'messages' => $messages];
        } else {
            $response['message'] = "Error fetching messages: " . $conn->error;
            error_log("Get Chat Messages Prepare Error: " . $conn->error);
        }
    } elseif ($action === 'send_chat_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $receiver_id = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
        $message_text = isset($_POST['message']) ? trim($_POST['message']) : '';
        $file_path_to_store = null;
        $file_upload_error_message = null; 
        
        error_log("send_chat_message: START. Receiver ID: {$receiver_id}, Admin ID: {$admin_id_session}, Message: '{$message_text}'");

        if (empty($receiver_id) || (empty($message_text) && empty($_FILES['chat_file']['name']))) {
            $response['message'] = 'Receiver ID and message or file are required.';
            error_log("send_chat_message: Validation failed - " . $response['message']);
        } else {
            if (isset($_FILES['chat_file']) && $_FILES['chat_file']['error'] == UPLOAD_ERR_OK) {
                error_log("send_chat_message: File upload detected: " . $_FILES['chat_file']['name']);
                $upload_result = handle_file_upload('chat_file', CHAT_FILES_DIR_FULL_PATH, CHAT_FILES_URL_PREFIX);
                if (isset($upload_result['error'])) {
                    $file_upload_error_message = "File upload error: " . $upload_result['error'];
                    $response['message'] = $file_upload_error_message; 
                    error_log("send_chat_message: " . $file_upload_error_message);
                } elseif ($upload_result['success']) {
                    $file_path_to_store = $upload_result['path']; 
                    if(empty($message_text) && isset($upload_result['original_name'])) {
                        $message_text = "File: " . htmlspecialchars($upload_result['original_name']);
                    }
                    error_log("send_chat_message: File uploaded successfully. Path: " . $file_path_to_store);
                }
            }
    
            if (!$file_upload_error_message && (!empty($message_text) || !empty($file_path_to_store))) {
                error_log("send_chat_message: Proceeding to DB insert. Message: '{$message_text}', File Path: '{$file_path_to_store}'");
                $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, typesender, file_path) VALUES (?, ?, ?, 'admin', ?)");
                if ($stmt) {
                    $current_admin_id = (int)$admin_id_session;
                    $current_receiver_id = (int)$receiver_id;
                    $current_message_text = (string)$message_text;
                    $current_file_path = is_null($file_path_to_store) ? '' : (string)$file_path_to_store; // Ensure empty string for DB if null
    
                    $stmt->bind_param("iiss", $current_admin_id, $current_receiver_id, $current_message_text, $current_file_path);
                    
                    if ($stmt->execute()) {
                        $new_message_id = $stmt->insert_id;
                        error_log("send_chat_message: Message inserted. New ID: " . $new_message_id);
                        $stmt_fetch = $conn->prepare("SELECT id, sender_id, receiver_id, message, created_at, file_path, typesender, edit FROM messages WHERE id = ?");
                        if($stmt_fetch){
                            $stmt_fetch->bind_param("i", $new_message_id);
                            $stmt_fetch->execute();
                            $result_fetch = $stmt_fetch->get_result();
                            if ($new_message_data = $result_fetch->fetch_assoc()) {
                                $response = ['success' => true, 'message' => 'Message sent!', 'sent_message' => $new_message_data];
                            } else {
                                $response = ['success' => true, 'message' => 'Message sent, but could not retrieve it. ID: ' . $new_message_id . '. Fetch found no rows.'];
                                error_log("Chat send: Message inserted (ID: $new_message_id) but fetch found no rows.");
                            }
                            $stmt_fetch->close();
                        } else {
                             $response = ['success' => true, 'message' => 'Message sent, but could not retrieve it. DB Error on fetch prepare: ' . $conn->error];
                             error_log("Chat send: Failed to prepare fetch statement after insert: " . $conn->error);
                        }
                    } else {
                        $response['message'] = 'Failed to send message: ' . $stmt->error;
                        error_log("Chat send: Failed to execute insert: " . $stmt->error . " (AdminID: $admin_id_session, ReceiverID: $receiver_id, Message: '$message_text', File: '$file_path_to_store')");
                        if($file_path_to_store) delete_local_file($file_path_to_store); 
                    }
                    $stmt->close();
                } else {
                    $response['message'] = "Error preparing send message statement: " . $conn->error;
                    error_log("Chat send: Failed to prepare insert statement: " . $conn->error);
                    if($file_path_to_store) delete_local_file($file_path_to_store);
                }
            } elseif ($file_upload_error_message) {
                // $response['message'] is already set
                 error_log("send_chat_message: Aborted due to file upload error.");
            } elseif (empty($message_text) && empty($file_path_to_store)) {
                $response['message'] = 'Nothing to send (no message text or successfully uploaded file).';
                error_log("send_chat_message: Aborted, nothing to send.");
            }
        }
    } elseif ($action === 'edit_chat_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
        $new_message_text = isset($_POST['new_message_text']) ? trim($_POST['new_message_text']) : '';

        if (empty($message_id) || empty($new_message_text)) {
            $response['message'] = 'Message ID and new text are required.';
        } else {
            $stmt_check = $conn->prepare("SELECT sender_id FROM messages WHERE id = ?");
            if(!$stmt_check) { $response['message'] = "DB Error (check sender): " . $conn->error; error_log("Edit Chat Msg Check Sender Prepare Error: " . $conn->error); goto end_edit_chat_message; }
            $stmt_check->bind_param("i", $message_id);
            if(!$stmt_check->execute()) { $response['message'] = "DB Error (exec check sender): " . $stmt_check->error; error_log("Edit Chat Msg Check Sender Exec Error: " . $stmt_check->error); $stmt_check->close(); goto end_edit_chat_message; }
            $result_check = $stmt_check->get_result();
            if ($msg_row = $result_check->fetch_assoc()) {
                if ($msg_row['sender_id'] == $admin_id_session) {
                    $stmt = $conn->prepare("UPDATE messages SET message = ?, edit = 0 WHERE id = ? AND sender_id = ?"); 
                    if ($stmt) {
                        $stmt->bind_param("sii", $new_message_text, $message_id, $admin_id_session);
                        if ($stmt->execute()) {
                            $response = ['success' => true, 'message' => 'Message updated successfully.'];
                        } else {
                            $response['message'] = 'Failed to update message: ' . $stmt->error;
                            error_log("Edit Chat Msg Update DB Error: " . $stmt->error);
                        }
                        $stmt->close();
                    } else {
                        $response['message'] = "Error preparing update message statement: " . $conn->error;
                        error_log("Edit Chat Msg Update Prepare Error: " . $conn->error);
                    }
                } else {
                    $response['message'] = 'You can only edit your own messages.';
                }
            } else {
                $response['message'] = 'Message not found.';
            }
            $stmt_check->close();
            end_edit_chat_message:;
        }
    } elseif ($action === 'delete_chat_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
        if (empty($message_id)) {
            $response['message'] = 'Message ID is required.';
        } else {
            $stmt_check = $conn->prepare("SELECT sender_id, file_path FROM messages WHERE id = ?");
            if(!$stmt_check) { $response['message'] = "DB Error (check sender for delete): " . $conn->error; error_log("Delete Chat Msg Check Sender Prepare Error: " . $conn->error); goto end_delete_chat_message; }
            $stmt_check->bind_param("i", $message_id);
            if(!$stmt_check->execute()) { $response['message'] = "DB Error (exec check sender for delete): " . $stmt_check->error; error_log("Delete Chat Msg Check Sender Exec Error: " . $stmt_check->error); $stmt_check->close(); goto end_delete_chat_message; }
            $result_check = $stmt_check->get_result();
            if ($msg_row = $result_check->fetch_assoc()) {
                if ($msg_row['sender_id'] == $admin_id_session) {
                    $stmt = $conn->prepare("DELETE FROM messages WHERE id = ? AND sender_id = ?");
                    if ($stmt) {
                        $stmt->bind_param("ii", $message_id, $admin_id_session);
                        if ($stmt->execute()) {
                            if(!empty($msg_row['file_path'])) {
                                delete_local_file($msg_row['file_path']);
                            }
                            $response = ['success' => true, 'message' => 'Message deleted successfully.'];
                        } else {
                            $response['message'] = 'Failed to delete message: ' . $stmt->error;
                             error_log("Delete Chat Msg DB Error: " . $stmt->error);
                        }
                        $stmt->close();
                    } else {
                        $response['message'] = "Error preparing delete message statement: " . $conn->error;
                        error_log("Delete Chat Msg Prepare Error: " . $conn->error);
                    }
                } else {
                     $response['message'] = 'You can only delete your own messages.';
                }
            } else {
                $response['message'] = 'Message not found.';
            }
            $stmt_check->close();
            end_delete_chat_message:;
        }
    }

    ob_end_clean(); 
    echo json_encode($response);
    if (is_object($conn) && method_exists($conn, 'close') && isset($conn->thread_id) && $conn->thread_id) {
        $conn->close();
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - MuscleMafia</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.1/css/all.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #111827; 
            color: #D1D5DB; 
        }
        .header-bg { background-color: #000000; }
        .content-bg { background-color: #1F2937; }
        .card-bg { background-color: #374151; }
        .input-bg {
            background-color: #4B5563; 
            border-color: #6B7280; 
            color: #F3F4F6; 
        }
        .input-bg:focus {
            border-color: #A5B4FC; 
            ring-color: #A5B4FC;
            outline: none;
            box-shadow: 0 0 0 2px #A5B4FC;
        }
        .btn-primary {
            background-color: #FFFFFF; 
            color: #111827; 
            transition: background-color 0.2s ease;
        }
        .btn-primary:hover { background-color: #E5E7EB; }
        .btn-danger {
            background-color: #EF4444; 
            color: #FFFFFF;
            transition: background-color 0.2s ease;
        }
        .btn-danger:hover { background-color: #DC2626; }
        .btn-edit { 
            background-color: #3B82F6; 
            color: #FFFFFF;
            transition: background-color 0.2s ease;
        }
        .btn-edit:hover { background-color: #2563EB; }

        .modal {
            display: none; 
            position: fixed;
            inset: 0;
            background-color: rgba(0, 0, 0, 0.75); 
            align-items: center;
            justify-content: center;
            z-index: 50;
            padding: 1rem;
        }
        .modal.active { display: flex; }
        .modal-content-area {
            background-color: #374151; 
            padding: 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            width: 100%;
            max-width: 36rem; 
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-content-area.max-w-md { max-width: 28rem; } 
        .modal-content-area.max-w-lg { max-width: 32rem; } 
        .modal-content-area.max-w-2xl { max-width: 48rem; } 


        .table th, .table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #4B5563; 
        }
        .table th {
            background-color: #374151; 
            font-weight: 600;
        }
        .table tr:hover td { background-color: #4B5563; }
        .table img.product-image {
            max-height: 40px;
            width: auto;
            border-radius: 4px;
            object-fit: cover; 
        }
        .facture-item-image { 
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 0.25rem;
            background-color: #4B5563; 
        }


        .tab-button { transition: background-color 0.3s, color 0.3s, border-color 0.3s; }
        .tab-button.active {
            background-color: #FFFFFF; 
            color: #111827; 
            border-color: #FFFFFF !important;
        }
        .tab-button:not(.active) { border-color: #4B5563; }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.5s; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 10001; 
            opacity: 0;
            transition: opacity 0.3s ease-in-out, transform 0.3s ease-in-out;
            transform: translateY(20px);
            color: white;
            font-size: 0.875rem; 
            font-weight: 500;
        }
        .toast-notification.success { background-color: #22c55e; }
        .toast-notification.error { background-color: #ef4444; }
        .toast-notification.show { opacity: 1; transform: translateY(0); }

        .image-source-option { display: none; }
        .image-source-option.active { display: block; }

        .modal-content-area::-webkit-scrollbar { width: 8px; }
        .modal-content-area::-webkit-scrollbar-track { background: #4B5563; border-radius: 10px;}
        .modal-content-area::-webkit-scrollbar-thumb { background: #6B7280; border-radius: 10px;}
        .modal-content-area::-webkit-scrollbar-thumb:hover { background: #9CA3AF; }

        /* Chat System Styles */
        #chat-container { display: flex; height: calc(100vh - 200px); /* Adjust height as needed */ }
        #chat-user-list { width: 30%; border-right: 1px solid #4B5563; overflow-y: auto; background-color: #1F2937;}
        #chat-user-list .chat-user-item { padding: 0.75rem; cursor: pointer; border-bottom: 1px solid #4B5563; }
        #chat-user-list .chat-user-item:hover { background-color: #374151; }
        #chat-user-list .chat-user-item.active { background-color: #4A5568; color: #FFFFFF; }
        #chat-window { width: 70%; display: flex; flex-direction: column; }
        #chat-messages { flex-grow: 1; padding: 1rem; overflow-y: auto; background-color: #111827; display:flex; flex-direction:column-reverse; } /* Reversed for new messages at bottom */
        .chat-message { margin-bottom: 0.75rem; padding: 0.5rem 0.75rem; border-radius: 0.5rem; max-width: 70%; word-wrap: break-word; }
        .chat-message.sender { background-color: #3B82F6; color: white; margin-left: auto; align-self: flex-end; }
        .chat-message.receiver { background-color: #4B5563; color: #E5E7EB; margin-right: auto; align-self: flex-start;}
        .chat-message .msg-time { font-size: 0.7rem; color: #9CA3AF; display: block; margin-top: 0.25rem; }
        .chat-message.sender .msg-time { text-align: right; }
        .chat-message.receiver .msg-time { text-align: left; }
        .chat-message .msg-actions button { background: none; border: none; color: #A5B4FC; cursor: pointer; font-size:0.7rem; margin-left: 5px;}
        .chat-message .msg-actions button:hover { text-decoration:underline; }

        #chat-input-area { padding: 1rem; border-top: 1px solid #4B5563; background-color: #1F2937;}
        #chat-input-area form { display: flex; }
        #chat-input-area input[type="text"] { flex-grow: 1; margin-right: 0.5rem; }
        #chat-file-input-label { cursor: pointer; padding: 0.5rem; background-color: #4B5563; border-radius: 0.375rem; margin-right: 0.5rem; }
        #chat-file-input-label:hover { background-color: #6B7280; }
        #chat-file-input { display: none; }
        #chat-selected-file-name { font-size: 0.75rem; color: #9CA3AF; margin-top: 0.25rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 150px;}


    </style>
</head>

<body class="antialiased">
    <header class="header-bg text-gray-300 shadow-lg sticky top-0 z-40">
        <div class="container mx-auto flex items-center justify-between p-4">
            <h1 class="text-xl font-bold text-white">MuscleMafia Admin Panel</h1>
            <div>
                <span class="mr-3">Welcome, <?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?></span>
                <a href="/admin_interface/update_admin.php?edit_user_id=<?php echo $admin_id_session; ?>" class="hover:text-white transition-colors text-sm" title="Profile">
                    <i class="fas fa-user-edit"></i><span class="hidden md:inline ml-1">Profile</span>
                </a>
                <a href="/login.php" class="text-sm hover:text-white pl-4" title="Log out"><i
                        class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </header>

    <main class="container mx-auto p-4 md:p-6">
        <div class="mb-6">
            <nav class="flex flex-wrap -mb-px text-sm font-medium text-center text-gray-400">
                <button class="tab-button active mr-2 inline-block p-3 border-b-2 rounded-t-lg" data-tab="products">
                    <i class="fas fa-box-open mr-2"></i>Products
                </button>
                <button class="tab-button mr-2 inline-block p-3 border-b-2 rounded-t-lg" data-tab="categories">
                    <i class="fas fa-tags mr-2"></i>Categories
                </button>
                <button class="tab-button mr-2 inline-block p-3 border-b-2 rounded-t-lg" data-tab="users">
                    <i class="fas fa-users mr-2"></i>Users
                </button>
                 <button class="tab-button mr-2 inline-block p-3 border-b-2 rounded-t-lg" data-tab="chat"> <i class="fas fa-comments mr-2"></i>Chat
                </button>
            </nav>
        </div>

        <div id="tab-products" class="tab-content active">
            <div class="content-bg p-4 md:p-6 rounded-lg shadow-xl">
                <div class="flex flex-col md:flex-row justify-between items-center mb-4">
                    <h2 class="text-2xl font-semibold text-white mb-3 md:mb-0">Manage Products</h2>
                    <button id="openAddProductModal" class="btn-primary py-2 px-4 rounded-md font-medium"><i
                            class="fas fa-plus mr-2"></i>Add Product</button>
                </div>
                <input type="text" id="searchProductInput" class="input-bg w-full md:w-1/2 p-2 rounded-md mb-4"
                    placeholder="Search products by name, description, category...">
                <div class="overflow-x-auto">
                    <table class="table w-full text-sm text-left text-gray-300">
                        <thead class="text-xs uppercase">
                            <tr>
                                <th>Image</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Desc.</th>
                                <th>Stock</th>
                                <th>Price</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="productsTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="tab-categories" class="tab-content">
            <div class="content-bg p-4 md:p-6 rounded-lg shadow-xl">
                <div class="flex flex-col md:flex-row justify-between items-center mb-4">
                    <h2 class="text-2xl font-semibold text-white mb-3 md:mb-0">Manage Categories</h2>
                    <button id="openAddCategoryModal" class="btn-primary py-2 px-4 rounded-md font-medium"><i
                            class="fas fa-plus mr-2"></i>Add Category</button>
                </div>
                <input type="text" id="searchCategoryInput" class="input-bg w-full md:w-1/2 p-2 rounded-md mb-4"
                    placeholder="Search categories by name...">
                <div class="overflow-x-auto">
                    <table class="table w-full text-sm text-left text-gray-300">
                        <thead class="text-xs uppercase">
                            <tr>
                                <th>ID</th>
                                <th>Icon</th>
                                <th>Name</th>
                                <th>Products #</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="categoriesTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="tab-users" class="tab-content">
            <div class="content-bg p-4 md:p-6 rounded-lg shadow-xl">
                <h2 class="text-2xl font-semibold text-white mb-4">Manage Users</h2>
                <input type="text" id="searchUserInput" class="input-bg w-full md:w-1/2 p-2 rounded-md mb-4"
                    placeholder="Search users by username or email...">
                <div class="overflow-x-auto">
                    <table class="table w-full text-sm text-left text-gray-300">
                        <thead class="text-xs uppercase">
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Cart Items</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="tab-chat" class="tab-content">
            <div class="content-bg p-0 rounded-lg shadow-xl overflow-hidden"> <div id="chat-container">
                    <div id="chat-user-list">
                        <div class="p-4 border-b border-gray-700">
                             <input type="text" id="searchChatUserInput" class="input-bg w-full p-2 rounded-md text-sm" placeholder="Search users for chat...">
                        </div>
                        <div id="chat-user-list-items">
                            <p class="p-4 text-gray-400 text-sm">Loading users...</p>
                        </div>
                    </div>
                    <div id="chat-window">
                        <div id="chat-header" class="p-3 border-b border-gray-700 bg-gray-700 text-white font-semibold hidden">
                            Chat with <span id="chat-with-username">User</span>
                        </div>
                        <div id="chat-messages">
                            <p class="p-4 text-gray-400 text-center">Select a user to start chatting.</p>
                            </div>
                        <div id="chat-input-area" class="hidden">
                            <form id="chat-message-form" enctype="multipart/form-data">
                                <input type="hidden" id="chat-receiver-id" name="receiver_id">
                                <label for="chat-file-input" id="chat-file-input-label" title="Attach file">
                                    <i class="fas fa-paperclip"></i>
                                </label>
                                <input type="file" id="chat-file-input" name="chat_file" accept="image/*,application/pdf,.txt,.doc,.docx">
                                <input type="text" id="chat-message-input" name="message" class="input-bg p-2 rounded-md" placeholder="Type a message..." autocomplete="off">
                                <button type="submit" class="btn-primary py-2 px-4 rounded-md ml-2"><i class="fas fa-paper-plane"></i> Send</button>
                            </form>
                             <div id="chat-selected-file-name" class="mt-1"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>


    </main>

    <div id="productModal" class="modal">
        <div class="modal-content-area">
            <div class="flex justify-between items-center mb-4">
                <h3 id="productModalTitle" class="text-xl font-semibold text-white">Add New Product</h3>
                <button id="closeProductModal" class="text-gray-300 hover:text-white text-2xl">&times;</button>
            </div>
            <form id="productForm" enctype="multipart/form-data">
                <input type="hidden" id="edit_product_id" name="edit_product_id">
                <input type="hidden" id="existing_picture_url_modal" name="existing_picture_url_modal">
                <div class="mb-4">
                    <label for="product_name_modal" class="block text-sm font-medium mb-1">Product Name</label>
                    <input type="text" id="product_name_modal" name="product_name_modal"
                        class="input-bg w-full p-2 rounded-md" required>
                </div>
                <div class="mb-4">
                    <label for="category_id_modal" class="block text-sm font-medium mb-1">Category</label>
                    <select id="category_id_modal" name="category_id_modal" class="input-bg w-full p-2 rounded-md"
                        required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories_for_forms as $category): ?>
                            <option value="<?php echo $category['id']; ?>">
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label for="description_modal" class="block text-sm font-medium mb-1">Description</label>
                    <textarea id="description_modal" name="description_modal" rows="3"
                        class="input-bg w-full p-2 rounded-md"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="count_modal" class="block text-sm font-medium mb-1">Stock Count</label>
                        <input type="number" id="count_modal" name="count_modal" class="input-bg w-full p-2 rounded-md"
                             min="0" value="0" required>
                    </div>
                    <div>
                        <label for="price_modal" class="block text-sm font-medium mb-1">Price (DZ)</label> <input type="number" id="price_modal" name="price_modal" step="0.01"
                            class="input-bg w-full p-2 rounded-md"  min="0" value="0.00" required>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Product Image</label>
                    <div class="flex items-center space-x-4 mb-2">
                        <label><input type="radio" name="image_source_type_product" value="url" checked
                                class="image-source-radio-product mr-1"> URL</label>
                        <label><input type="radio" name="image_source_type_product" value="file"
                                class="image-source-radio-product mr-1"> Upload File</label>
                    </div>
                    <div id="productImageUrlInputContainer" class="image-source-option active">
                        <input type="url" id="picture_url_modal" name="picture_url_modal"
                            class="input-bg w-full p-2 rounded-md" placeholder="https://example.com/image.jpg">
                        <p class="text-xs text-gray-400 mt-1">Current: <span id="currentProductImageDisplay">None</span></p>
                    </div>
                    <div id="productImageFileInputContainer" class="image-source-option">
                        <input type="file" id="product_image_file" name="product_image_file" accept="image/jpeg,image/png,image/gif,image/webp"
                            class="input-bg w-full p-1.5 rounded-md text-sm file:mr-2 file:py-1 file:px-2 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-gray-600 file:text-gray-200 hover:file:bg-gray-500">
                    </div>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancelProductForm"
                        class="bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded-md font-medium">Cancel</button>
                    <button type="submit" class="btn-primary py-2 px-4 rounded-md font-medium">Save Product</button>
                </div>
            </form>
        </div>
    </div>

    <div id="categoryModal" class="modal">
        <div class="modal-content-area max-w-md">
            <div class="flex justify-between items-center mb-4">
                <h3 id="categoryModalTitle" class="text-xl font-semibold text-white">Add New Category</h3>
                <button id="closeCategoryModal" class="text-gray-300 hover:text-white text-2xl">&times;</button>
            </div>
            <form id="categoryForm" enctype="multipart/form-data">
                <input type="hidden" id="edit_category_id_modal_hidden" name="edit_category_id"> 
                <input type="hidden" id="existing_ico_img_url_modal" name="existing_ico_img_url_modal">
                <div class="mb-4">
                    <label for="category_name_modal" class="block text-sm font-medium mb-1">Category Name</label>
                    <input type="text" id="category_name_modal" name="category_name_modal"
                        class="input-bg w-full p-2 rounded-md" required>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Category Icon</label>
                    <div class="flex items-center space-x-4 mb-2">
                        <label><input type="radio" name="image_source_type_category" value="url" checked
                                class="image-source-radio-category mr-1"> URL</label>
                        <label><input type="radio" name="image_source_type_category" value="file"
                                class="image-source-radio-category mr-1"> Upload File</label>
                    </div>
                    <div id="categoryIconUrlInputContainer" class="image-source-option active">
                        <input type="url" id="ico_img_url_modal" name="ico_img_url_modal"
                            class="input-bg w-full p-2 rounded-md" placeholder="https://example.com/icon.png">
                        <p class="text-xs text-gray-400 mt-1">Current: <span id="currentCategoryIconDisplay">None</span></p>
                    </div>
                    <div id="categoryIconFileInputContainer" class="image-source-option">
                        <input type="file" id="category_ico_file" name="category_ico_file" accept="image/jpeg,image/png,image/gif,image/webp"
                            class="input-bg w-full p-1.5 rounded-md text-sm file:mr-2 file:py-1 file:px-2 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-gray-600 file:text-gray-200 hover:file:bg-gray-500">
                    </div>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancelCategoryForm"
                        class="bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded-md font-medium">Cancel</button>
                    <button type="submit" class="btn-primary py-2 px-4 rounded-md font-medium">Save Category</button>
                </div>
            </form>
        </div>
    </div>

    <div id="userOrderHistoryModal" class="modal"> <div class="modal-content-area max-w-2xl"> 
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold text-white">Order History for <span id="orderHistoryModalUsername" class="font-bold">User</span></h3>
                <button id="closeOrderHistoryModal" class="text-gray-300 hover:text-white text-2xl">&times;</button>
            </div>
            <div id="orderHistoryContent" class="space-y-6" style="min-height: 200px;"> 
                <p class="text-center text-gray-400 py-10">Loading order history...</p>
            </div>
            <div class="flex justify-end space-x-3 mt-6">
                <button type="button" id="cancelOrderHistoryModalBtn" class="bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded-md font-medium">Close</button>
            </div>
        </div>
    </div>
    
    <div id="userRoleEditModal" class="modal" style="display:none;"> <div class="modal-content-area max-w-lg">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold text-white">Edit Role for <span id="userRoleEditModalUsername" class="font-bold"></span></h3>
                <button id="closeUserRoleEditModal" class="text-gray-300 hover:text-white text-2xl">&times;</button>
            </div>
            <form id="userRoleEditForm">
                <input type="hidden" id="edit_user_id_for_role_hidden" name="user_id_to_update">
                <div class="mb-4">
                    <select id="edit_user_role_select" name="new_role_value" class="input-bg w-full p-2 rounded-md" required>
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" id="cancelUserRoleEditForm"
                        class="bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded-md font-medium">Cancel</button>
                    <button type="submit" class="btn-primary py-2 px-4 rounded-md font-medium">Update Role</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editChatMessageModal" class="modal">
        <div class="modal-content-area max-w-lg">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold text-white">Edit Message</h3>
                <button id="closeEditChatMessageModal" class="text-gray-300 hover:text-white text-2xl">&times;</button>
            </div>
            <form id="editChatMessageForm">
                <input type="hidden" id="edit_chat_message_id_modal" name="message_id">
                <div class="mb-4">
                    <label for="edit_chat_message_text_modal" class="block text-sm font-medium mb-1">Message</label>
                    <textarea id="edit_chat_message_text_modal" name="new_message_text" rows="3" class="input-bg w-full p-2 rounded-md" required></textarea>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancelEditChatMessageForm" class="bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded-md font-medium">Cancel</button>
                    <button type="submit" class="btn-primary py-2 px-4 rounded-md font-medium">Save Changes</button>
                </div>
            </form>
        </div>
    </div>


    <div id="toast-container" class="fixed bottom-5 right-5 z-[10001] space-y-2"></div>

    <script>
        // --- GLOBALS & CONSTANTS ---
        const ADMIN_ID_SESSION = <?php echo json_encode($admin_id_session); ?>;
        const BASE_UPLOAD_URL_PATH_JS = "<?php echo BASE_UPLOAD_URL_PATH; ?>"; 
        let currentChatUserId = null;
        let messagePollingInterval = null;
        let lastFetchedMessageId = 0;


        // --- UTILITIES ---
        function showToast(message, isError = false) {
            const toastContainer = document.getElementById('toast-container');
            if (!toastContainer) { console.warn("Toast container not found!"); return; }
            const toast = document.createElement('div');
            toast.className = `toast-notification ${isError ? 'error' : 'success'}`;
            toast.textContent = message;
            toastContainer.appendChild(toast);
            setTimeout(() => { toast.classList.add('show'); }, 10);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => { toast.remove(); }, 500); 
            }, 3000);
        }

        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) modal.classList.add('active');
        }
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) modal.classList.remove('active');
        }

        // --- TABS ---
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabContents = document.querySelectorAll('.tab-content');
        tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                const targetTab = button.dataset.tab;
                tabButtons.forEach(btn => {
                    btn.classList.remove('active', 'bg-white', 'text-gray-900');
                    btn.classList.add('text-gray-400', 'border-transparent', 'hover:text-gray-300', 'hover:border-gray-300');
                });
                button.classList.add('active', 'bg-white', 'text-gray-900');
                button.classList.remove('text-gray-400', 'border-transparent');
                tabContents.forEach(content => content.classList.toggle('active', content.id === `tab-${targetTab}`));

                if (targetTab === 'products') loadProducts();
                else if (targetTab === 'categories') loadCategories();
                else if (targetTab === 'users') loadUsers();
                else if (targetTab === 'chat') loadChatUsers(); 
            });
        });

        // --- IMAGE SOURCE TOGGLE ---
        function setupImageSourceToggle(radioGroupName, urlInputContainerId, fileInputContainerId) {
            const radios = document.querySelectorAll(`input[name="${radioGroupName}"]`);
            const urlInputDiv = document.getElementById(urlInputContainerId);
            const fileInputDiv = document.getElementById(fileInputContainerId);
            const fileInput = fileInputDiv ? fileInputDiv.querySelector('input[type="file"]') : null;

            function toggleVisibility(showUrl) {
                if (urlInputDiv) urlInputDiv.classList.toggle('active', showUrl);
                if (fileInputDiv) fileInputDiv.classList.toggle('active', !showUrl);
                if (!showUrl && fileInput) fileInput.value = '';
            }

            radios.forEach(radio => {
                radio.addEventListener('change', function () {
                    toggleVisibility(this.value === 'url');
                });
            });
            const defaultRadio = document.querySelector(`input[name="${radioGroupName}"]:checked`);
            toggleVisibility(defaultRadio ? defaultRadio.value === 'url' : true); 
        }
        setupImageSourceToggle('image_source_type_product', 'productImageUrlInputContainer', 'productImageFileInputContainer');
        setupImageSourceToggle('image_source_type_category', 'categoryIconUrlInputContainer', 'categoryIconFileInputContainer');

        // --- PRODUCT MODAL & CRUD ---
        document.getElementById('openAddProductModal').addEventListener('click', () => {
            document.getElementById('productModalTitle').textContent = 'Add New Product';
            document.getElementById('productForm').reset();
            document.getElementById('edit_product_id').value = '';
            document.getElementById('existing_picture_url_modal').value = ''; 
            document.getElementById('currentProductImageDisplay').textContent = 'None';
            document.querySelector('input[name="image_source_type_product"][value="url"]').checked = true;
            setupImageSourceToggle('image_source_type_product', 'productImageUrlInputContainer', 'productImageFileInputContainer'); 
            openModal('productModal');
        });
        document.getElementById('closeProductModal').addEventListener('click', () => closeModal('productModal'));
        document.getElementById('cancelProductForm').addEventListener('click', () => closeModal('productModal'));

        // --- CATEGORY MODAL & CRUD ---
        document.getElementById('openAddCategoryModal').addEventListener('click', () => {
            document.getElementById('categoryModalTitle').textContent = 'Add New Category';
            document.getElementById('categoryForm').reset();
            document.getElementById('edit_category_id_modal_hidden').value = '';
            document.getElementById('existing_ico_img_url_modal').value = ''; 
            document.getElementById('currentCategoryIconDisplay').textContent = 'None';
            document.querySelector('input[name="image_source_type_category"][value="url"]').checked = true;
            setupImageSourceToggle('image_source_type_category', 'categoryIconUrlInputContainer', 'categoryIconFileInputContainer'); 
            openModal('categoryModal');
        });
        document.getElementById('closeCategoryModal').addEventListener('click', () => closeModal('categoryModal'));
        document.getElementById('cancelCategoryForm').addEventListener('click', () => closeModal('categoryModal'));

        // --- USER ORDER HISTORY MODAL ---
        document.getElementById('closeOrderHistoryModal').addEventListener('click', () => closeModal('userOrderHistoryModal'));
        document.getElementById('cancelOrderHistoryModalBtn').addEventListener('click', () => closeModal('userOrderHistoryModal'));
        
        // --- USER ROLE EDIT MODAL ---
        document.getElementById('closeUserRoleEditModal').addEventListener('click', () => closeModal('userRoleEditModal'));
        document.getElementById('cancelUserRoleEditForm').addEventListener('click', () => closeModal('userRoleEditModal'));

        // --- CHAT MESSAGE EDIT MODAL ---
        document.getElementById('closeEditChatMessageModal').addEventListener('click', () => closeModal('editChatMessageModal'));
        document.getElementById('cancelEditChatMessageForm').addEventListener('click', () => closeModal('editChatMessageModal'));


        const productsTableBody = document.getElementById('productsTableBody');
        const productForm = document.getElementById('productForm');
        const searchProductInput = document.getElementById('searchProductInput');
        

        async function loadProducts(searchTerm = '') {
            try {
                const response = await fetch(`admin_panel.php?ajax_action=get_products&search=${encodeURIComponent(searchTerm)}`);
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                const data = await response.json();
                productsTableBody.innerHTML = ''; 
                if (data.success && data.products && data.products.length > 0) {
                    data.products.forEach(p => {
                        const row = productsTableBody.insertRow();
                        let imgSrc = p.picture ? p.picture : 'https://placehold.co/60x40/4B5563/9CA3AF?text=N/A';
                        row.innerHTML = `
                            <td><img src="${imgSrc}" alt="${p.product ? p.product.substring(0,20) : 'Product'}" class="product-image" onerror="this.onerror=null;this.src='https://placehold.co/60x40/4B5563/9CA3AF?text=Error'; this.alt='Image error';"></td>
                            <td class="font-medium">${p.product || 'N/A'}</td>
                            <td>${p.category_name || 'N/A'}</td>
                            <td class="text-xs">${p.description ? p.description.substring(0, 50) + (p.description.length > 50 ? '...' : '') : ''}</td>
                            <td>${p.count !== null ? p.count : 'N/A'}</td>
                            <td>DZ${p.price !== null ? parseFloat(p.price).toFixed(2) : 'N/A'}</td> <td>
                                <button class="btn-edit text-xs py-1 px-2 rounded mr-1 edit-product-btn" data-id="${p.id}"><i class="fas fa-edit"></i></button>
                                <button class="btn-danger text-xs py-1 px-2 rounded delete-product-btn" data-id="${p.id}"><i class="fas fa-trash"></i></button>
                            </td>
                        `;
                    });
                } else {
                    productsTableBody.innerHTML = '<tr><td colspan="7" class="text-center py-4">No products found.</td></tr>';
                }
            } catch (error) {
                console.error('Error loading products:', error);
                showToast('Error loading products: ' + error.message, true);
                productsTableBody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-red-400">Error loading products. ${error.message}</td></tr>`;
            }
        }

        productForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const formData = new FormData(this);
            const editId = document.getElementById('edit_product_id').value;
            const action = editId ? 'update_product' : 'add_product';
            formData.append('ajax_action', action);
            
            try {
                const response = await fetch('admin_panel.php', { method: 'POST', body: formData });
                if (!response.ok) { 
                     const errorText = await response.text(); 
                     throw new Error(`HTTP error! status: ${response.status}, Message: ${errorText.substring(0,200)}`);
                }
                const data = await response.json();
                showToast(data.message, !data.success);
                if (data.success) {
                    loadProducts(searchProductInput.value);
                    closeModal('productModal');
                }
            } catch (error) {
                console.error('Error saving product:', error);
                showToast('Error saving product: ' + error.message, true);
            }
        });

        productsTableBody.addEventListener('click', async function (e) {
            if (e.target.closest('.edit-product-btn')) {
                const id = e.target.closest('.edit-product-btn').dataset.id;
                try {
                    const response = await fetch(`admin_panel.php?ajax_action=get_product_details&id=${id}`);
                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                    const data = await response.json();
                    if (data.success && data.product) {
                        document.getElementById('productModalTitle').textContent = 'Edit Product';
                        document.getElementById('edit_product_id').value = data.product.id;
                        document.getElementById('product_name_modal').value = data.product.product;
                        document.getElementById('category_id_modal').value = data.product.category_id;
                        document.getElementById('description_modal').value = data.product.description;
                        document.getElementById('count_modal').value = data.product.count;
                        document.getElementById('price_modal').value = parseFloat(data.product.price).toFixed(2);

                        const currentImageDisplay = document.getElementById('currentProductImageDisplay');
                        const pictureUrlInput = document.getElementById('picture_url_modal');
                        const imageFileInput = document.getElementById('product_image_file');
                        const imageUrlRadio = document.querySelector('input[name="image_source_type_product"][value="url"]');

                        const existingPic = data.product.picture || '';
                        document.getElementById('existing_picture_url_modal').value = existingPic; 
                        currentImageDisplay.textContent = existingPic ? (existingPic.split('/').pop() || 'Image') : 'None';
                        if(imageFileInput) imageFileInput.value = ''; 

                        if (existingPic && (existingPic.startsWith('http://') || existingPic.startsWith('https://'))) {
                            pictureUrlInput.value = existingPic;
                        } else {
                            pictureUrlInput.value = ''; 
                        }
                        if(imageUrlRadio) imageUrlRadio.checked = true; 
                        setupImageSourceToggle('image_source_type_product', 'productImageUrlInputContainer', 'productImageFileInputContainer');
                        openModal('productModal');
                    } else { showToast(data.message || 'Could not fetch product details.', true); }
                } catch (error) { console.error('Error fetching product details:', error); showToast('Error fetching product details: ' + error.message, true); }
            }
            if (e.target.closest('.delete-product-btn')) {
                const id = e.target.closest('.delete-product-btn').dataset.id;
                if (confirm('Are you sure you want to delete this product?')) { 
                    const formData = new FormData();
                    formData.append('ajax_action', 'delete_product');
                    formData.append('id', id);
                    try {
                        const response = await fetch('admin_panel.php', { method: 'POST', body: formData });
                        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                        const data = await response.json();
                        showToast(data.message, !data.success);
                        if (data.success) loadProducts(searchProductInput.value);
                    } catch (error) { console.error('Error deleting product:', error); showToast('Error deleting product: ' + error.message, true); }
                }
            }
        });
        if (searchProductInput) searchProductInput.addEventListener('input', () => loadProducts(searchProductInput.value));

        const categoriesTableBody = document.getElementById('categoriesTableBody');
        const categoryForm = document.getElementById('categoryForm');
        const searchCategoryInput = document.getElementById('searchCategoryInput');

        async function loadCategories(searchTerm = '') {
            try {
                const response = await fetch(`admin_panel.php?ajax_action=get_categories&search=${encodeURIComponent(searchTerm)}`);
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                const data = await response.json();
                categoriesTableBody.innerHTML = '';
                if (data.success && data.categories && data.categories.length > 0) {
                    data.categories.forEach(cat => {
                        const row = categoriesTableBody.insertRow();
                        let iconSrc = cat.ico_img ? cat.ico_img : 'https://placehold.co/30x30/4B5563/9CA3AF?text=Icon';
                        row.innerHTML = `
                            <td>${cat.id}</td>
                            <td><img src="${iconSrc}" alt="${cat.name ? cat.name.substring(0,10) : 'Icon'}" class="product-image" style="max-height:30px; border-radius:3px;" onerror="this.onerror=null;this.src='https://placehold.co/30x30/4B5563/9CA3AF?text=Err'; this.alt='Icon error';"></td>
                            <td class="font-medium">${cat.name || 'N/A'}</td>
                            <td class="text-center">${cat.product_count !== null ? cat.product_count : 0}</td>
                            <td>
                                <button class="btn-edit text-xs py-1 px-2 rounded mr-1 edit-category-btn" data-id="${cat.id}"><i class="fas fa-edit"></i></button>
                                <button class="btn-danger text-xs py-1 px-2 rounded delete-category-btn" data-id="${cat.id}"><i class="fas fa-trash"></i></button>
                            </td>
                        `;
                    });
                } else { categoriesTableBody.innerHTML = '<tr><td colspan="5" class="text-center py-4">No categories found.</td></tr>'; }
            } catch (error) {
                console.error('Error loading categories:', error);
                showToast('Error loading categories: ' + error.message, true);
                categoriesTableBody.innerHTML = `<tr><td colspan="5" class="text-center py-4 text-red-400">Error loading categories. ${error.message}</td></tr>`;
            }
        }

        categoryForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const formData = new FormData(this);
            const editId = document.getElementById('edit_category_id_modal_hidden').value;
            const action = editId ? 'update_category' : 'add_category';
            formData.append('ajax_action', action);

            try {
                const response = await fetch('admin_panel.php', { method: 'POST', body: formData });
                 if (!response.ok) {
                     const errorText = await response.text();
                     throw new Error(`HTTP error! status: ${response.status}, Message: ${errorText.substring(0,200)}`);
                }
                const data = await response.json();
                showToast(data.message, !data.success);
                if (data.success) {
                    loadCategories(searchCategoryInput.value);
                    fetchCategoriesForProductForm(); 
                    closeModal('categoryModal');
                }
            } catch (error) { console.error('Error saving category:', error); showToast('Error saving category: ' + error.message, true); }
        });

        categoriesTableBody.addEventListener('click', async function (e) {
            if (e.target.closest('.edit-category-btn')) {
                const id = e.target.closest('.edit-category-btn').dataset.id;
                try {
                    const response = await fetch(`admin_panel.php?ajax_action=get_category_details&id=${id}`);
                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                    const data = await response.json();
                    if (data.success && data.category) {
                        document.getElementById('categoryModalTitle').textContent = 'Edit Category';
                        document.getElementById('edit_category_id_modal_hidden').value = data.category.id;
                        document.getElementById('category_name_modal').value = data.category.name;

                        const currentIconDisplay = document.getElementById('currentCategoryIconDisplay');
                        const icoUrlInput = document.getElementById('ico_img_url_modal');
                        const catIcoFileInput = document.getElementById('category_ico_file');
                        const catImageUrlRadio = document.querySelector('input[name="image_source_type_category"][value="url"]');

                        const existingIco = data.category.ico_img || '';
                        document.getElementById('existing_ico_img_url_modal').value = existingIco;
                        currentIconDisplay.textContent = existingIco ? (existingIco.split('/').pop() || 'Icon') : 'None';
                        if(catIcoFileInput) catIcoFileInput.value = '';

                        if (existingIco && (existingIco.startsWith('http://') || existingIco.startsWith('https://'))) {
                            icoUrlInput.value = existingIco;
                            if(catImageUrlRadio) catImageUrlRadio.checked = true;
                        } else {
                            icoUrlInput.value = '';
                            if(catImageUrlRadio) catImageUrlRadio.checked = true;
                        }
                        setupImageSourceToggle('image_source_type_category', 'categoryIconUrlInputContainer', 'categoryIconFileInputContainer');
                        openModal('categoryModal');
                    } else { showToast(data.message || 'Could not fetch category details.', true); }
                } catch (error) { console.error('Error fetching category details:', error); showToast('Error fetching category details: ' + error.message, true); }
            }
            if (e.target.closest('.delete-category-btn')) {
                const id = e.target.closest('.delete-category-btn').dataset.id;
                if (confirm('Are you sure you want to delete this category? If ON DELETE CASCADE is set in your database, all associated products will ALSO BE DELETED.')) {
                    const formData = new FormData();
                    formData.append('ajax_action', 'delete_category');
                    formData.append('id', id);
                    try {
                        const response = await fetch('admin_panel.php', { method: 'POST', body: formData });
                        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                        const data = await response.json();
                        showToast(data.message, !data.success);
                        if (data.success) {
                            loadCategories(searchCategoryInput.value);
                            fetchCategoriesForProductForm(); 
                            loadProducts(); 
                        }
                    } catch (error) { console.error('Error deleting category:', error); showToast('Error deleting category: ' + error.message, true); }
                }
            }
        });
        if (searchCategoryInput) searchCategoryInput.addEventListener('input', () => loadCategories(searchCategoryInput.value));

        async function fetchCategoriesForProductForm() { 
            try {
                const response = await fetch(`admin_panel.php?ajax_action=get_categories`); 
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                const data = await response.json();
                const categorySelect = document.getElementById('category_id_modal');

                if (data.success && data.categories && categorySelect) {
                    const currentValue = categorySelect.value; 
                    while (categorySelect.options.length > 1) categorySelect.remove(1);
                    data.categories.forEach(cat => {
                        const option = new Option(cat.name, cat.id);
                        categorySelect.add(option);
                    });
                    if (Array.from(categorySelect.options).some(opt => opt.value === currentValue)) {
                        categorySelect.value = currentValue;
                    }
                }
            } catch (error) { console.error('Error fetching categories for product form:', error); }
        }

        // --- USER MANAGEMENT, ORDER HISTORY & ROLE EDIT ---
        const usersTableBody = document.getElementById('usersTableBody');
        const searchUserInput = document.getElementById('searchUserInput');
        const adminSessionIdJS = <?php echo json_encode((int)$admin_id_session); ?>; 

        async function loadUsers(searchTerm = '') { 
            const isForChatList = document.getElementById('tab-chat').classList.contains('active');
            let url = `admin_panel.php?ajax_action=get_users&search=${encodeURIComponent(searchTerm)}`;
            if(isForChatList) {
                url += `&for_chat=1`;
            }

            try {
                const response = await fetch(url);
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                const data = await response.json();

                if (isForChatList) {
                    const chatUserListItems = document.getElementById('chat-user-list-items');
                    chatUserListItems.innerHTML = '';
                     if (data.success && data.users && data.users.length > 0) {
                        data.users.forEach(user => {
                            const userItem = document.createElement('div');
                            userItem.className = 'chat-user-item';
                            userItem.textContent = user.username || 'Unknown User';
                            userItem.dataset.userId = user.id;
                            userItem.dataset.username = user.username || 'Unknown User';
                            userItem.addEventListener('click', () => selectChatUser(user.id, user.username));
                            chatUserListItems.appendChild(userItem);
                        });
                    } else {
                        chatUserListItems.innerHTML = '<p class="p-4 text-gray-400 text-sm">No users found.</p>';
                    }
                } else { 
                    usersTableBody.innerHTML = '';
                    if (data.success && data.users && data.users.length > 0) {
                        data.users.forEach(user => {
                            const row = usersTableBody.insertRow();
                            const isAdmin = user.role === 'admin';
                            const isCurrentUser = user.id == adminSessionIdJS; 

                            row.innerHTML = `
                                <td>${user.id}</td>
                                <td class="font-medium">${user.username || 'N/A'}</td>
                                <td>${user.email || 'N/A'}</td>
                                <td class="text-center">${user.cart_item_count !== null ? user.cart_item_count : 0}</td>
                                <td>
                                    <button class="btn-edit text-xs py-1 px-2 rounded mr-1 view-user-history-btn" 
                                        data-id="${user.id}" 
                                        data-username="${user.username || ''}" > 
                                        <i class="fas fa-history"></i> History
                                    </button>
                                    <button class="btn-danger text-xs py-1 px-2 rounded delete-user-btn" 
                                        data-id="${user.id}" 
                                        ${isCurrentUser ? 'disabled title="Cannot delete self"' : ''}>
                                        <i class="fas fa-user-times"></i>
                                    </button>
                                </td>
                            `;
                        });
                    } else { usersTableBody.innerHTML = '<tr><td colspan="6" class="text-center py-4">No users found.</td></tr>'; }
                }
            } catch (error) {
                console.error('Error loading users:', error);
                showToast('Error loading users: ' + error.message, true);
                if(isForChatList) {
                    document.getElementById('chat-user-list-items').innerHTML = `<p class="p-4 text-red-400 text-sm">Error loading users. ${error.message}</p>`;
                } else {
                    usersTableBody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-red-400">Error loading users. ${error.message}</td></tr>`;
                }
            }
        }
        
        function renderOrderHistory(factures, username) {
            const contentDiv = document.getElementById('orderHistoryContent');
            const usernameSpan = document.getElementById('orderHistoryModalUsername');
            
            if (usernameSpan) usernameSpan.textContent = username;
            if (!contentDiv) return;

            if (!factures || factures.length === 0) {
                contentDiv.innerHTML = '<p class="text-center text-gray-400 py-10 content-bg rounded-lg shadow-xl">This user has no past orders.</p>';
                return;
            }

            let html = '<div class="space-y-6">';
            factures.forEach(facture => {
                html += `
                    <div class="facture-card p-6 rounded-lg shadow-xl border border-gray-700">
                        <div class="flex flex-wrap justify-between items-center mb-4 border-b border-gray-600 pb-3">
                            <div>
                                <h3 class="text-xl font-semibold text-sky-400">Facture ID: #${facture.id}</h3>
                                <p class="text-sm text-gray-400">Date: ${new Date(facture.created_at).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</p>
                            </div>
                            <p class="text-xl font-bold text-white">Total: DZ${parseFloat(facture.total_price).toFixed(2)}</p>
                        </div>
                        <div class="mb-4">
                            <h4 class="text-md font-semibold text-gray-200 mb-1">Shipping To:</h4>
                            <p class="text-sm text-gray-400">${facture.shipping_address ? facture.shipping_address.replace(/\n/g, '<br>') : 'N/A'}</p>
                            <p class="text-sm text-gray-400">Phone: ${facture.phone_number || 'N/A'}</p>
                        </div>
                        <div>
                            <h4 class="text-md font-semibold text-gray-200 mb-2">Items Ordered:</h4>
                            <ul class="space-y-2">`;
                if (facture.items && facture.items.length > 0) {
                    facture.items.forEach(item => {
                        const itemImage = item.product_image || 'https://placehold.co/40x40/374151/9CA3AF?text=N/A';
                        const itemTotal = parseFloat(item.price_at_purchase) * parseInt(item.quantity);
                        html += `
                            <li class="flex items-center justify-between text-sm border-b border-gray-700 py-2 last:border-b-0">
                                <div class="flex items-center">
                                    <img src="${itemImage}" 
                                         alt="${item.product_name || 'Item'}" 
                                         class="facture-item-image mr-3"
                                         onerror="this.onerror=null;this.src='https://placehold.co/40x40/374151/9CA3AF?text=N/A';">
                                    <div>
                                        <span class="text-gray-100">${item.product_name || 'Unknown Product'}</span>
                                        <span class="text-gray-400 ml-2">(x${item.quantity})</span>
                                    </div>
                                </div>
                                <span class="text-gray-300">DZ${itemTotal.toFixed(2)}</span>
                            </li>`;
                    });
                } else {
                    html += '<li class="text-gray-400">No items found for this facture.</li>';
                }
                html += `</ul></div></div>`; 
            });
            html += '</div>'; 
            contentDiv.innerHTML = html;
        }

        usersTableBody.addEventListener('click', async function (e) {
            const historyBtn = e.target.closest('.view-user-history-btn');
            if (historyBtn) {
                const userId = historyBtn.dataset.id;
                const username = historyBtn.dataset.username;
                
                document.getElementById('orderHistoryModalUsername').textContent = username || 'User';
                const contentDiv = document.getElementById('orderHistoryContent');
                contentDiv.innerHTML = '<p class="text-center text-gray-400 py-10">Loading order history...</p>';
                openModal('userOrderHistoryModal');

                try {
                    const response = await fetch(`admin_panel.php?ajax_action=get_user_order_history&user_id=${userId}`);
                    if (!response.ok) {
                        const errorText = await response.text();
                        throw new Error(`HTTP error! Status: ${response.status}, Message: ${errorText.substring(0,200)}`);
                    }
                    const data = await response.json();
                    if (data.success) {
                        renderOrderHistory(data.factures, data.username);
                    } else {
                        showToast(data.message || 'Could not fetch order history.', true);
                        contentDiv.innerHTML = `<p class="text-center text-red-400 py-10">${data.message || 'Could not fetch order history.'}</p>`;
                    }
                } catch (error) {
                    console.error('Error fetching user order history:', error);
                    showToast('Error fetching order history: ' + error.message, true);
                    contentDiv.innerHTML = `<p class="text-center text-red-400 py-10">Error: ${error.message}</p>`;
                }
            }

            const editRoleBtn = e.target.closest('.edit-user-role-btn');
            if (editRoleBtn) {
                const button = editRoleBtn;
                const userId = button.dataset.id;
                const currentRole = button.dataset.role;
                const username = button.dataset.username;

                if (userId == adminSessionIdJS && currentRole === 'admin') {
                    showToast("Admins cannot change their own role from 'admin' through this interface.", true);
                    return;
                }
                document.getElementById('edit_user_id_for_role_hidden').value = userId;
                document.getElementById('userRoleEditModalUsername').textContent = username;
                document.getElementById('edit_user_role_select').value = currentRole;
                openModal('userRoleEditModal');
            }


            const deleteBtn = e.target.closest('.delete-user-btn');
            if (deleteBtn) {
                if (deleteBtn.disabled) return; 
                const id = deleteBtn.dataset.id;
                if (confirm('Are you sure you want to delete this user? This will also delete their cart items, messages, and potentially their order history if database constraints are set for cascading deletes.')) {
                    const formData = new FormData();
                    formData.append('ajax_action', 'delete_user');
                    formData.append('id', id);
                    try {
                        const response = await fetch('admin_panel.php', { method: 'POST', body: formData });
                        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                        const data = await response.json();
                        showToast(data.message, !data.success);
                        if (data.success) loadUsers(searchUserInput.value);
                    } catch (error) { console.error('Error deleting user:', error); showToast('Error deleting user: ' + error.message, true); }
                }
            }
        });
        
        const userRoleEditForm = document.getElementById('userRoleEditForm');
        if (userRoleEditForm) {
            userRoleEditForm.addEventListener('submit', async function (e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('ajax_action', 'update_user_role');
                try {
                    const response = await fetch('admin_panel.php', { method: 'POST', body: formData });
                    if (!response.ok) {
                        const errorText = await response.text();
                        throw new Error(`HTTP error! status: ${response.status}, Message: ${errorText.substring(0,200)}`);
                    }
                    const data = await response.json();
                    showToast(data.message, !data.success);
                    if (data.success) {
                        loadUsers(searchUserInput.value);
                        closeModal('userRoleEditModal');
                    }
                } catch (error) { console.error('Error updating user role:', error); showToast('Error updating user role: ' + error.message, true); }
            });
        }


        if (searchUserInput) searchUserInput.addEventListener('input', () => loadUsers(searchUserInput.value));

        // --- CHAT SYSTEM ---
        const chatUserListItemsDiv = document.getElementById('chat-user-list-items');
        const chatMessagesDiv = document.getElementById('chat-messages');
        const chatMessageForm = document.getElementById('chat-message-form');
        const chatReceiverIdInput = document.getElementById('chat-receiver-id');
        const chatMessageInput = document.getElementById('chat-message-input');
        const chatHeaderDiv = document.getElementById('chat-header');
        const chatWithUsernameSpan = document.getElementById('chat-with-username');
        const chatInputAreaDiv = document.getElementById('chat-input-area');
        const searchChatUserInput = document.getElementById('searchChatUserInput');
        const chatFileInput = document.getElementById('chat-file-input');
        const chatSelectedFileNameDiv = document.getElementById('chat-selected-file-name');


        if(searchChatUserInput) {
            searchChatUserInput.addEventListener('input', () => loadChatUsers(searchChatUserInput.value));
        }
        if(chatFileInput) {
            chatFileInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    chatSelectedFileNameDiv.textContent = `File: ${this.files[0].name}`;
                } else {
                    chatSelectedFileNameDiv.textContent = '';
                }
            });
        }


        async function loadChatUsers(searchTerm = '') {
            await loadUsers(searchTerm); 
        }

        function selectChatUser(userId, username) {
            currentChatUserId = userId;
            lastFetchedMessageId = 0; 
            if (chatReceiverIdInput) chatReceiverIdInput.value = userId;
            if (chatWithUsernameSpan) chatWithUsernameSpan.textContent = username;
            if (chatHeaderDiv) chatHeaderDiv.classList.remove('hidden');
            if (chatInputAreaDiv) chatInputAreaDiv.classList.remove('hidden');
            
            document.querySelectorAll('#chat-user-list .chat-user-item').forEach(item => item.classList.remove('active'));
            document.querySelector(`#chat-user-list .chat-user-item[data-user-id='${userId}']`)?.classList.add('active');

            chatMessagesDiv.innerHTML = '<p class="p-4 text-gray-400 text-center">Loading messages...</p>';
            fetchChatMessages(true); 

            if (messagePollingInterval) clearInterval(messagePollingInterval);
            messagePollingInterval = setInterval(() => fetchChatMessages(false), 5000); 
        }

        async function fetchChatMessages(isInitialLoad = false) {
            if (!currentChatUserId) return;
            try {
                const response = await fetch(`admin_panel.php?ajax_action=get_chat_messages&user_id=${currentChatUserId}&last_message_id=${lastFetchedMessageId}`);
                if (!response.ok) {
                     const errorText = await response.text();
                     throw new Error(`HTTP error! status: ${response.status}, Response: ${errorText.substring(0,200)}`);
                }
                const data = await response.json();

                if (data.success && data.messages) {
                    if (isInitialLoad) chatMessagesDiv.innerHTML = ''; 
                    
                    if (data.messages.length === 0 && isInitialLoad && chatMessagesDiv.childElementCount === 0) { 
                         chatMessagesDiv.innerHTML = '<p class="p-4 text-gray-400 text-center">No messages yet. Start the conversation!</p>';
                    } else {
                        data.messages.forEach(msg => appendChatMessage(msg, false)); 
                        if (data.messages.length > 0) {
                            lastFetchedMessageId = data.messages[data.messages.length - 1].id;
                        }
                    }
                    if(isInitialLoad && data.messages.length > 0) scrollToBottom(chatMessagesDiv); 
                } else if (!data.success) {
                    if(isInitialLoad) showToast(data.message || 'Could not fetch messages.', true);
                }
            } catch (error) {
                console.error('Error fetching chat messages:', error);
                if(isInitialLoad) showToast('Error fetching messages: ' + error.message, true);
            }
        }
        
        function formatChatMessageTime(timestamp) {
            const date = new Date(timestamp);
            return date.toLocaleString('en-GB', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: 'short' });
        }

        function appendChatMessage(msg, prepend = false) {
            const msgDiv = document.createElement('div');
            msgDiv.classList.add('chat-message');
            msgDiv.dataset.messageId = msg.id;

            let messageTextEscaped = msg.message.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\n/g, '<br>');
            let messageContentHTML = `<span class="block">${messageTextEscaped}</span>`;
            
            if(msg.file_path) {
                const fileName = msg.file_path.split('/').pop();
                const safeFileName = fileName.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
                messageContentHTML += `<a href="${msg.file_path}" target="_blank" class="text-indigo-300 hover:text-indigo-100 underline text-sm block mt-1"><i class="fas fa-paperclip mr-1"></i> ${safeFileName}</a>`;
            }

            if (msg.sender_id == ADMIN_ID_SESSION) { 
                msgDiv.classList.add('sender');
                msgDiv.innerHTML = `
                    <div>${messageContentHTML}</div>
                    <div class="msg-time">${formatChatMessageTime(msg.created_at)} ${msg.edit == 0 ? '(edited)' : ''}</div>
                    <div class="msg-actions">
                        <button class="edit-chat-msg-btn" data-id="${msg.id}" title="Edit"><i class="fas fa-edit"></i></button>
                        <button class="delete-chat-msg-btn" data-id="${msg.id}" title="Delete"><i class="fas fa-trash"></i></button>
                    </div>
                `;
            } else { 
                msgDiv.classList.add('receiver');
                 msgDiv.innerHTML = `
                    <div>${messageContentHTML}</div>
                    <div class="msg-time">${formatChatMessageTime(msg.created_at)}</div>
                `;
            }
            
            if (prepend) { 
                chatMessagesDiv.appendChild(msgDiv); 
            } else {
                chatMessagesDiv.insertBefore(msgDiv, chatMessagesDiv.firstChild); 
            }
            if (!prepend) scrollToBottom(chatMessagesDiv);
        }

        function scrollToBottom(element) {
            element.scrollTop = 0; 
        }


        if (chatMessageForm) {
            chatMessageForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const messageText = chatMessageInput.value.trim();
                const receiverId = chatReceiverIdInput.value;
                const chatFile = chatFileInput.files[0];

                if (!receiverId || (!messageText && !chatFile)) {
                    showToast('Please type a message or select a file.', true);
                    return;
                }

                const formData = new FormData();
                formData.append('ajax_action', 'send_chat_message');
                formData.append('receiver_id', receiverId);
                formData.append('message', messageText);
                if (chatFile) {
                    formData.append('chat_file', chatFile);
                }

                try {
                    const response = await fetch('admin_panel.php', { method: 'POST', body: formData });
                    if (!response.ok) {
                         const errorText = await response.text(); 
                         console.error("Raw error response text:", errorText); 
                         throw new Error(`HTTP error! status: ${response.status}, Response: ${errorText.substring(0,200)}`);
                    }
                    
                    let data;
                    try {
                        data = await response.json();
                    } catch (jsonError) {
                        const errorTextForJson = await response.text(); 
                        console.error("JSON Parse Error. Raw response text (re-fetched):", errorTextForJson);
                        throw new Error(`Failed to parse JSON response. Server status: ${response.status}. Content: ${errorTextForJson.substring(0,200)}`);
                    }


                    if (data.success && data.sent_message) {
                        if(chatMessagesDiv.querySelector('p.text-center')) {
                            chatMessagesDiv.innerHTML = '';
                        }
                        appendChatMessage(data.sent_message, false); 
                        chatMessageInput.value = '';
                        chatFileInput.value = ''; 
                        chatSelectedFileNameDiv.textContent = ''; 
                    } else {
                        showToast(data.message || 'Failed to send message.', true);
                    }
                } catch (error) {
                    console.error('Error sending message:', error);
                    showToast('Error sending message: ' + error.message, true);
                }
            });
        }
        
        chatMessagesDiv.addEventListener('click', function(e) {
            if (e.target.closest('.edit-chat-msg-btn')) {
                const button = e.target.closest('.edit-chat-msg-btn');
                const messageId = button.dataset.id;
                const messageDiv = button.closest('.chat-message');
                const messageSpan = messageDiv.querySelector('span.block');
                const messageText = messageSpan ? messageSpan.innerHTML.replace(/<br\s*\/?>/gi, "\n") : ''; 

                document.getElementById('edit_chat_message_id_modal').value = messageId;
                document.getElementById('edit_chat_message_text_modal').value = messageText;
                openModal('editChatMessageModal');
            }
            if (e.target.closest('.delete-chat-msg-btn')) {
                const button = e.target.closest('.delete-chat-msg-btn');
                const messageId = button.dataset.id;
                if (confirm('Are you sure you want to delete this message?')) {
                    deleteChatMessage(messageId);
                }
            }
        });

        const editChatMessageForm = document.getElementById('editChatMessageForm');
        if(editChatMessageForm) {
            editChatMessageForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('ajax_action', 'edit_chat_message');

                try {
                    const response = await fetch('admin_panel.php', {method: 'POST', body: formData});
                    if(!response.ok) {
                        const errorText = await response.text();
                        throw new Error(`HTTP Error! Status: ${response.status}, Message: ${errorText.substring(0,200)}`);
                    }
                    const data = await response.json();
                    showToast(data.message, !data.success);
                    if(data.success) {
                        closeModal('editChatMessageModal');
                        lastFetchedMessageId = 0; 
                        fetchChatMessages(true); 
                    }
                } catch (error) {
                    console.error("Error editing chat message:", error);
                    showToast("Error editing message: " + error.message, true);
                }
            });
        }

        async function deleteChatMessage(messageId) {
            const formData = new FormData();
            formData.append('ajax_action', 'delete_chat_message');
            formData.append('message_id', messageId);
            try {
                const response = await fetch('admin_panel.php', {method: 'POST', body: formData});
                if(!response.ok) {
                    const errorText = await response.text();
                    throw new Error(`HTTP Error! Status: ${response.status}, Message: ${errorText.substring(0,200)}`);
                }
                const data = await response.json();
                showToast(data.message, !data.success);
                if(data.success) {
                    const messageElement = chatMessagesDiv.querySelector(`.chat-message[data-message-id='${messageId}']`);
                    if(messageElement) messageElement.remove();
                }
            } catch (error) {
                console.error("Error deleting chat message:", error);
                showToast("Error deleting message: " + error.message, true);
            }
        }


        // --- INITIAL LOAD ---
        document.addEventListener('DOMContentLoaded', () => {
            if (document.getElementById('tab-products').classList.contains('active')) {
                loadProducts();
            }
            fetchCategoriesForProductForm(); 
        });
    </script>
</body>
</html>
<?php
// This final close is likely redundant if AJAX calls always exit, but harmless.
if (is_object($conn) && method_exists($conn, 'close') && isset($conn->thread_id) && $conn->thread_id) {
    $conn->close();
}
?>