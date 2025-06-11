<?php
session_start();

// --- DATABASE CONNECTION ---
include('database.php'); // Ensure this file uses mysqli and sets up $conn

// --- AJAX CHAT HANDLING ---
if (isset($_REQUEST['ajax_action_user_chat'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Invalid AJAX action for user chat.'];

    if (!isset($_SESSION['user_id']) || !filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT)) {
        if (isset($_REQUEST['user_id_ajax']) && filter_var($_REQUEST['user_id_ajax'], FILTER_VALIDATE_INT)) {
             $_SESSION['user_id'] = (int)$_REQUEST['user_id_ajax'];
        } else {
            echo json_encode(['success' => false, 'message' => 'User not authenticated for chat action. Session ID missing or invalid.']);
            exit;
        }
    }
    $current_user_id_chat = (int)$_SESSION['user_id'];
    $target_admin_id_chat = 1; 

    // --- CHAT FILE UPLOAD CONSTANTS AND FUNCTIONS (AJAX SCOPE) ---
    define('UPLOAD_BASE_DIR_RELATIVE_TO_SCRIPT_PARENT_USER_AJAX', '../upload/'); 
    define('UPLOAD_BASE_DIR_FULL_PATH_USER_AJAX', realpath(dirname(__FILE__) . '/' . UPLOAD_BASE_DIR_RELATIVE_TO_SCRIPT_PARENT_USER_AJAX) ?: dirname(__FILE__) . '/' . UPLOAD_BASE_DIR_RELATIVE_TO_SCRIPT_PARENT_USER_AJAX);
    define('CHAT_FILES_DIR_NAME_USER_AJAX', 'chat_files');
    define('CHAT_FILES_DIR_FULL_PATH_USER_AJAX', rtrim(UPLOAD_BASE_DIR_FULL_PATH_USER_AJAX, '/') . '/' . CHAT_FILES_DIR_NAME_USER_AJAX . '/');
    define('BASE_UPLOAD_URL_PATH_USER_AJAX', '/upload/'); 
    define('CHAT_FILES_URL_PREFIX_USER_AJAX', BASE_UPLOAD_URL_PATH_USER_AJAX . CHAT_FILES_DIR_NAME_USER_AJAX . '/');

    if (!function_exists('ensure_directory_exists_user_ajax')) { 
        function ensure_directory_exists_user_ajax($dir_path) {
            if (!is_dir($dir_path)) {
                if (!mkdir($dir_path, 0755, true)) {
                    error_log("Failed to create directory (AJAX): " . $dir_path);
                    return false;
                }
            }
            if (!is_writable($dir_path)) {
                error_log("Directory not writable (AJAX): " . $dir_path);
                return false;
            }
            return true;
        }
    }
    if (!ensure_directory_exists_user_ajax(CHAT_FILES_DIR_FULL_PATH_USER_AJAX)) {
        error_log("CRITICAL (AJAX): Chat files directory " . CHAT_FILES_DIR_FULL_PATH_USER_AJAX . " could not be created or is not writable.");
    }

    if (!function_exists('handle_file_upload_user_ajax')) {
        function handle_file_upload_user_ajax($file_input_name, $upload_target_full_path_dir, $url_prefix) {
            if (!ensure_directory_exists_user_ajax($upload_target_full_path_dir)) {
                return ['error' => 'Upload directory is not accessible or writable: ' . basename($upload_target_full_path_dir)];
            }
            if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == UPLOAD_ERR_OK) {
                $file = $_FILES[$file_input_name];
                $allowed_mimes_general = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'video/mp4', 'video/webm', 'video/ogg'];
                $allowed_extensions_general = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'doc', 'docx', 'mp4', 'webm', 'ogg'];
                $max_size = 50 * 1024 * 1024; // 50MB

                $file_mime_type = mime_content_type($file['tmp_name']);
                if (!in_array($file_mime_type, $allowed_mimes_general)) {
                    return ['error' => 'Invalid file type. Detected: ' . $file_mime_type];
                }
                if ($file['size'] > $max_size) {
                    return ['error' => 'File is too large. Max 50MB allowed.'];
                }
                $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($extension, $allowed_extensions_general)) {
                    return ['error' => 'Invalid file extension.'];
                }
                $original_filename_base = pathinfo($file['name'], PATHINFO_FILENAME);
                $safe_original_filename_base = preg_replace("/[^a-zA-Z0-9_-]/", "", $original_filename_base);
                $filename = uniqid($safe_original_filename_base . '_userchat_', true) . '.' . $extension;
                $destination = rtrim($upload_target_full_path_dir, '/') . '/' . $filename;

                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    return ['success' => true, 'path' => rtrim($url_prefix, '/') . '/' . $filename, 'original_name' => $file['name']];
                } else {
                    return ['error' => 'Failed to move uploaded file. PHP Error Code: ' . $file['error']];
                }
            } elseif (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] != UPLOAD_ERR_NO_FILE) {
                return ['error' => 'File upload error code: ' . $_FILES[$file_input_name]['error']];
            }
            return ['success' => false]; 
        }
    }
    
    if (!function_exists('delete_local_file_user_ajax')) {
        function delete_local_file_user_ajax($relative_url_path) {
            if (empty($relative_url_path)) return;
            if (strpos($relative_url_path, CHAT_FILES_URL_PREFIX_USER_AJAX) === 0) {
                $file_path_segment = substr($relative_url_path, strlen(BASE_UPLOAD_URL_PATH_USER_AJAX));
                $full_server_path = rtrim(UPLOAD_BASE_DIR_FULL_PATH_USER_AJAX, '/') . '/' . ltrim($file_path_segment, '/');
                
                error_log("Attempting to delete local chat file (AJAX): " . $full_server_path);
                if (file_exists($full_server_path) && is_file($full_server_path)) {
                    if (!@unlink($full_server_path)) {
                        error_log("Failed to delete local chat file (AJAX): " . $full_server_path);
                    } else {
                        error_log("Successfully deleted local chat file (AJAX): " . $full_server_path);
                    }
                } else {
                    error_log("Local chat file not found for deletion (AJAX): " . $full_server_path);
                }
            }
        }
    }

    if (!$conn || $conn->connect_error) {
        echo json_encode(['success' => false, 'message' => 'Database connection error for chat.']);
        error_log("AJAX Chat: DB connection error: " . ($conn ? $conn->connect_error : "Not initialized"));
        exit;
    }

    $action = $_REQUEST['ajax_action_user_chat'];

    switch ($action) {
        case 'fetch_user_chat_messages':
            $last_message_id = isset($_GET['last_message_id']) ? (int)$_GET['last_message_id'] : 0;
            $messages = [];
            $stmt_fetch_msg = $conn->prepare("SELECT id, sender_id, receiver_id, message, file_path, created_at, edit, typesender, is_read FROM messages WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)) AND id > ? ORDER BY created_at ASC");
            if ($stmt_fetch_msg) {
                $stmt_fetch_msg->bind_param("iiiii", $current_user_id_chat, $target_admin_id_chat, $target_admin_id_chat, $current_user_id_chat, $last_message_id);
                $stmt_fetch_msg->execute();
                $result_msg = $stmt_fetch_msg->get_result();
                while ($row = $result_msg->fetch_assoc()) {
                    $messages[] = $row;
                }
                $stmt_fetch_msg->close();
                $response['success'] = true;
                $response['messages'] = $messages;

                if (!empty($messages)) { 
                    $stmt_mark_read = $conn->prepare("UPDATE messages SET is_read = 1 WHERE receiver_id = ? AND sender_id = ? AND is_read = 0");
                    if ($stmt_mark_read) {
                        $stmt_mark_read->bind_param("ii", $current_user_id_chat, $target_admin_id_chat);
                        $stmt_mark_read->execute();
                        $stmt_mark_read->close();
                    } else {
                        error_log("Failed to prepare statement to mark messages as read: " . $conn->error);
                    }
                }
            } else {
                $response['message'] = 'Failed to prepare statement to fetch messages: ' . $conn->error;
                error_log($response['message']);
            }
            break;

        case 'send_chat_message':
            $message_text = isset($_POST['message']) ? trim($_POST['message']) : '';
            $file_path_db = '';
            $original_file_name_db = '';

            if (isset($_FILES['chat_file']) && $_FILES['chat_file']['error'] == UPLOAD_ERR_OK) {
                $upload_result = handle_file_upload_user_ajax('chat_file', CHAT_FILES_DIR_FULL_PATH_USER_AJAX, CHAT_FILES_URL_PREFIX_USER_AJAX);
                if (isset($upload_result['success']) && $upload_result['success']) {
                    $file_path_db = $upload_result['path'];
                    $original_file_name_db = $upload_result['original_name'];
                    if (empty($message_text)) {
                        $message_text = "File: " . htmlspecialchars($original_file_name_db);
                    }
                } else {
                    $response['message'] = 'File upload failed: ' . ($upload_result['error'] ?? 'Unknown error');
                    echo json_encode($response);
                    exit;
                }
            }

            if (empty($message_text) && empty($file_path_db)) {
                $response['message'] = 'Message cannot be empty unless a file is uploaded.';
                echo json_encode($response);
                exit;
            }
            
            $stmt_send = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, file_path, typesender, is_read) VALUES (?, ?, ?, ?, 'user', 0)");
            if ($stmt_send) {
                $stmt_send->bind_param("iiss", $current_user_id_chat, $target_admin_id_chat, $message_text, $file_path_db);
                if ($stmt_send->execute()) {
                    $new_message_id = $stmt_send->insert_id;
                    $response['success'] = true;
                    $response['message'] = 'Message sent.';
                    $stmt_get_sent = $conn->prepare("SELECT id, sender_id, receiver_id, message, file_path, created_at, edit, typesender, is_read FROM messages WHERE id = ?");
                    if($stmt_get_sent){
                        $stmt_get_sent->bind_param("i", $new_message_id);
                        $stmt_get_sent->execute();
                        $result_sent = $stmt_get_sent->get_result();
                        $response['sent_message'] = $result_sent->fetch_assoc();
                        $stmt_get_sent->close();
                    }
                } else {
                    $response['message'] = 'Failed to send message: ' . $stmt_send->error;
                    error_log($response['message']);
                }
                $stmt_send->close();
            } else {
                $response['message'] = 'Failed to prepare statement for sending message: ' . $conn->error;
                error_log($response['message']);
            }
            break;

        case 'edit_chat_message':
            $message_id_edit = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
            $new_message_text_edit = isset($_POST['new_message_text']) ? trim($_POST['new_message_text']) : '';

            if (empty($new_message_text_edit)) {
                $response['message'] = 'Message text cannot be empty for an edit.';
            } elseif ($message_id_edit > 0) {
                $stmt_check_owner = $conn->prepare("SELECT sender_id FROM messages WHERE id = ?");
                $owner_id = null;
                if($stmt_check_owner){
                    $stmt_check_owner->bind_param("i", $message_id_edit);
                    $stmt_check_owner->execute();
                    $stmt_check_owner->bind_result($owner_id);
                    $stmt_check_owner->fetch();
                    $stmt_check_owner->close();
                }

                if ($owner_id === $current_user_id_chat) {
                    $stmt_edit = $conn->prepare("UPDATE messages SET message = ?, edit = 0 WHERE id = ? AND sender_id = ?"); 
                    if ($stmt_edit) {
                        $stmt_edit->bind_param("sii", $new_message_text_edit, $message_id_edit, $current_user_id_chat);
                        if ($stmt_edit->execute()) {
                            $response['success'] = true; // Mark as success if SQL execution was okay
                            if ($stmt_edit->affected_rows > 0) {
                                $response['message'] = 'Message updated successfully.';
                            } else {
                                // This means the content might have been identical, but 'edit = 0' was still set.
                                $response['message'] = 'Message processed. Content may be unchanged if identical.';
                            }
                        } else {
                            $response['success'] = false; // Explicitly false on execute error
                            $response['message'] = 'Failed to update message: ' . $stmt_edit->error;
                            error_log($response['message']);
                        }
                        $stmt_edit->close();
                    } else {
                        $response['success'] = false;
                        $response['message'] = 'Failed to prepare statement for editing message: ' . $conn->error;
                        error_log($response['message']);
                    }
                } else {
                    $response['message'] = 'You can only edit your own messages or message not found.';
                }
            } else {
                $response['message'] = 'Invalid message ID for edit.';
            }
            break;

        case 'delete_chat_message':
            $message_id_delete = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
            if ($message_id_delete > 0) {
                $stmt_fetch_delete_info = $conn->prepare("SELECT sender_id, file_path FROM messages WHERE id = ?");
                $file_to_delete_path = null;
                $sender_of_message_to_delete = null;
                if($stmt_fetch_delete_info){
                    $stmt_fetch_delete_info->bind_param("i", $message_id_delete);
                    $stmt_fetch_delete_info->execute();
                    $stmt_fetch_delete_info->bind_result($sender_of_message_to_delete, $file_to_delete_path);
                    $stmt_fetch_delete_info->fetch();
                    $stmt_fetch_delete_info->close();
                }

                if ($sender_of_message_to_delete === $current_user_id_chat) {
                    $stmt_delete = $conn->prepare("DELETE FROM messages WHERE id = ? AND sender_id = ?");
                    if ($stmt_delete) {
                        $stmt_delete->bind_param("ii", $message_id_delete, $current_user_id_chat);
                        if ($stmt_delete->execute()) {
                            if ($stmt_delete->affected_rows > 0) {
                                $response['success'] = true;
                                $response['message'] = 'Message deleted successfully.';
                                if (!empty($file_to_delete_path)) {
                                    delete_local_file_user_ajax($file_to_delete_path); 
                                }
                            } else {
                                $response['message'] = 'Message not found or already deleted.';
                            }
                        } else {
                            $response['message'] = 'Failed to delete message: ' . $stmt_delete->error;
                            error_log($response['message']);
                        }
                        $stmt_delete->close();
                    } else {
                        $response['message'] = 'Failed to prepare statement for deleting message: ' . $conn->error;
                        error_log($response['message']);
                    }
                } else {
                    $response['message'] = 'You can only delete your own messages or message not found.';
                }
            } else {
                $response['message'] = 'Invalid message ID for delete.';
            }
            break;

        case 'get_unread_admin_messages_count':
            $unread_count = 0;
            $stmt_count = $conn->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND sender_id = ? AND is_read = 0");
            if ($stmt_count) {
                $stmt_count->bind_param("ii", $current_user_id_chat, $target_admin_id_chat);
                $stmt_count->execute();
                $stmt_count->bind_result($unread_count);
                $stmt_count->fetch();
                $stmt_count->close();
                $response['success'] = true;
                $response['unread_count'] = (int)$unread_count;
            } else {
                $response['message'] = 'Failed to prepare statement for unread count: ' . $conn->error;
                error_log($response['message']);
            }
            break;
        default:
             $response['message'] = 'Unknown AJAX chat action: ' . htmlspecialchars($action);
    }
    echo json_encode($response);
    exit; 
}

// --- REGULAR PAGE LOAD ---
if (isset($_GET['user_id']) && filter_var($_GET['user_id'], FILTER_VALIDATE_INT)) {
    $_SESSION['user_id'] = (int)$_GET['user_id'];
}

define('UPLOAD_BASE_DIR_RELATIVE_TO_SCRIPT_PARENT_USER', '../upload/'); 
define('UPLOAD_BASE_DIR_FULL_PATH_USER', realpath(dirname(__FILE__) . '/' . UPLOAD_BASE_DIR_RELATIVE_TO_SCRIPT_PARENT_USER) ?: dirname(__FILE__) . '/' . UPLOAD_BASE_DIR_RELATIVE_TO_SCRIPT_PARENT_USER);
define('CHAT_FILES_DIR_NAME_USER', 'chat_files');
define('CHAT_FILES_DIR_FULL_PATH_USER', rtrim(UPLOAD_BASE_DIR_FULL_PATH_USER, '/') . '/' . CHAT_FILES_DIR_NAME_USER . '/');
define('BASE_UPLOAD_URL_PATH_USER', '/upload/'); 
define('CHAT_FILES_URL_PREFIX_USER', BASE_UPLOAD_URL_PATH_USER . CHAT_FILES_DIR_NAME_USER . '/');

if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log("Database connection object (\$conn) is not a valid mysqli object in update_user.php. Check database.php.");
}

if (!function_exists('ensure_directory_exists_user')) {
    function ensure_directory_exists_user($dir_path) {
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
}

if (!ensure_directory_exists_user(CHAT_FILES_DIR_FULL_PATH_USER)) { 
    error_log("CRITICAL: Chat files directory " . CHAT_FILES_DIR_FULL_PATH_USER . " could not be created or is not writable.");
}

$user_id = null;
$display_name = "Guest";
$current_username = "";
$current_email = "";
$feedback_messages = [];
$user_factures = [];

if (isset($_SESSION['user_id']) && filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT)) {
    $user_id = (int)$_SESSION['user_id'];

    if (isset($_SESSION['user_name'])) {
        $display_name = htmlspecialchars($_SESSION['user_name']);
    } else {
        if ($conn && !($conn->connect_error)) { 
            $stmt_session_user = $conn->prepare("SELECT username FROM users WHERE id = ?");
            if ($stmt_session_user) {
                $stmt_session_user->bind_param("i", $user_id);
                $stmt_session_user->execute();
                $res_session_user = $stmt_session_user->get_result();
                if($data_session_user = $res_session_user->fetch_assoc()){
                    $display_name = htmlspecialchars($data_session_user['username']);
                    $_SESSION['user_name'] = $display_name; 
                } else {
                    unset($_SESSION['user_id']);
                    unset($_SESSION['user_name']);
                    $user_id = null; 
                    $feedback_messages[] = ['type' => 'error', 'text' => 'Session user not found. Please re-login.'];
                }
                $stmt_session_user->close();
            } else { error_log("Error preparing user session name fetch: " . $conn->error); }
        } else { error_log("DB connection error before fetching session user name.");}
    }
} elseif (isset($_GET['user_id']) && filter_var($_GET['user_id'], FILTER_VALIDATE_INT)) {
    $feedback_messages[] = ['type' => 'error', 'text' => 'You must be logged in via a valid session to manage your profile. Attempting to use GET user_id.'];
    $user_id = (int)$_GET['user_id']; 
     if ($conn && !($conn->connect_error)) { 
            $stmt_session_user = $conn->prepare("SELECT username FROM users WHERE id = ?");
            if ($stmt_session_user) {
                $stmt_session_user->bind_param("i", $user_id);
                $stmt_session_user->execute();
                $res_session_user = $stmt_session_user->get_result();
                if($data_session_user = $res_session_user->fetch_assoc()){
                    $display_name = htmlspecialchars($data_session_user['username']);
                } else {
                     $user_id = null; 
                     $feedback_messages[] = ['type' => 'error', 'text' => 'User specified in URL not found.'];
                }
                $stmt_session_user->close();
            }
        }
}


if (is_null($user_id) && empty($feedback_messages)) {
    $feedback_messages[] = ['type' => 'error', 'text' => 'Could not identify user. Please log in.'];
}

$user_id_to_edit = $user_id; 

if ($user_id_to_edit && $conn && !($conn->connect_error)) {
    $stmt_fetch = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
    if ($stmt_fetch) {
        $stmt_fetch->bind_param("i", $user_id_to_edit);
        $stmt_fetch->execute();
        $result_fetch = $stmt_fetch->get_result();
        if ($user_data_row = $result_fetch->fetch_assoc()) {
            $current_username = htmlspecialchars($user_data_row['username']);
            $current_email = htmlspecialchars($user_data_row['email']);
            if ($display_name === "Guest" || (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id_to_edit && (!isset($_SESSION['user_name']) || $_SESSION['user_name'] != $current_username)) ){
                $display_name = $current_username;
                $_SESSION['user_name'] = $display_name; 
            }
        } else {
            $feedback_messages[] = ['type' => 'error', 'text' => 'User data not found for profile.'];
            unset($_SESSION['user_id']);
            unset($_SESSION['user_name']);
            $user_id_to_edit = null; 
        }
        $stmt_fetch->close();
    } else {
        $feedback_messages[] = ['type' => 'error', 'text' => 'Error fetching user data: ' . $conn->error];
        error_log("Error preparing user fetch statement for profile: " . $conn->error);
    }
} elseif (!$user_id_to_edit && empty($feedback_messages)) {
    $feedback_messages[] = ['type' => 'error', 'text' => 'User not identified. Cannot load profile data.'];
}

if (isset($_POST['submit_profile_update']) && $user_id_to_edit && $conn && !($conn->connect_error)) {
    $new_username = trim($_POST['username']);
    $new_email = trim($_POST['email']);
    $form_user_id = isset($_POST['user_id_for_update']) ? (int)$_POST['user_id_for_update'] : 0;

    if ($form_user_id !== $user_id_to_edit) {
        $feedback_messages[] = ['type' => 'error', 'text' => 'Unauthorized update attempt or session mismatch.'];
    } else {
        $update_fields = [];
        $update_params_types = "";
        $update_params_values = [];
        $can_proceed = true;

        if (!empty($new_username) && $new_username !== $current_username) {
            if (strlen($new_username) < 3) {
                $feedback_messages[] = ['type' => 'error', 'text' => 'Username must be at least 3 characters.'];
                $can_proceed = false;
            } else {
                $stmt_check_username = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                if ($stmt_check_username) {
                    $stmt_check_username->bind_param("si", $new_username, $user_id_to_edit);
                    $stmt_check_username->execute();
                    $stmt_check_username->store_result();
                    if ($stmt_check_username->num_rows > 0) {
                        $feedback_messages[] = ['type' => 'error', 'text' => 'Username already taken.'];
                        $can_proceed = false;
                    } else {
                        $update_fields[] = "username = ?"; $update_params_types .= "s"; $update_params_values[] = $new_username;
                    }
                    $stmt_check_username->close();
                } else { $feedback_messages[] = ['type' => 'error', 'text' => 'DB error (username check).']; $can_proceed = false;}
            }
        }

        if ($can_proceed && !empty($new_email) && $new_email !== $current_email) {
            if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                $feedback_messages[] = ['type' => 'error', 'text' => 'Invalid email format.'];
                $can_proceed = false;
            } else {
                $stmt_check_email = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                if ($stmt_check_email) {
                    $stmt_check_email->bind_param("si", $new_email, $user_id_to_edit);
                    $stmt_check_email->execute();
                    $stmt_check_email->store_result();
                    if ($stmt_check_email->num_rows > 0) {
                        $feedback_messages[] = ['type' => 'error', 'text' => 'Email already taken.'];
                        $can_proceed = false;
                    } else {
                        $update_fields[] = "email = ?"; $update_params_types .= "s"; $update_params_values[] = $new_email;
                    }
                    $stmt_check_email->close();
                } else { $feedback_messages[] = ['type' => 'error', 'text' => 'DB error (email check).']; $can_proceed = false;}
            }
        }
        
        if ($can_proceed && !empty($update_fields)) {
            $update_sql = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE id = ?";
            $update_params_types .= "i";
            $update_params_values[] = $user_id_to_edit;

            $stmt_update = $conn->prepare($update_sql);
            if ($stmt_update) {
                $stmt_update->bind_param($update_params_types, ...$update_params_values);
                if ($stmt_update->execute()) {
                    $feedback_messages[] = ['type' => 'success', 'text' => 'Profile updated successfully!'];
                    if (in_array("username = ?", $update_fields) ) {
                        $_SESSION['user_name'] = $new_username; 
                        $display_name = $new_username; 
                        $current_username = $new_username; 
                    }
                    if (in_array("email = ?", $update_fields)) {
                        $current_email = $new_email; 
                    }
                } else {
                    $feedback_messages[] = ['type' => 'error', 'text' => 'Failed to update profile: ' . $stmt_update->error];
                }
                $stmt_update->close();
            } else {
                $feedback_messages[] = ['type' => 'error', 'text' => 'Database error (profile update prepare): ' . $conn->error];
            }
        } elseif ($can_proceed && empty($update_fields)) {
            $feedback_messages[] = ['type' => 'info', 'text' => 'No changes were submitted for profile information.'];
        }
    }
}

if (isset($_POST['submit_password_update']) && $user_id_to_edit && $conn && !($conn->connect_error)) {
    $current_password_input = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_new_password = $_POST['confirm_new_password'];
    $form_user_id_pwd = isset($_POST['user_id_for_password']) ? (int)$_POST['user_id_for_password'] : 0;

    if ($form_user_id_pwd !== $user_id_to_edit) {
        $feedback_messages[] = ['type' => 'error', 'text' => 'Unauthorized password update attempt.'];
    } elseif (empty($current_password_input) || empty($new_password) || empty($confirm_new_password)) {
        $feedback_messages[] = ['type' => 'error', 'text' => 'All password fields are required.'];
    } elseif (strlen($new_password) < 8) {
        $feedback_messages[] = ['type' => 'error', 'text' => 'New password must be at least 8 characters long.'];
    } elseif ($new_password !== $confirm_new_password) {
        $feedback_messages[] = ['type' => 'error', 'text' => 'New passwords do not match.'];
    } else {
        $stmt_verify_pass = $conn->prepare("SELECT password FROM users WHERE id = ?");
        if ($stmt_verify_pass) {
            $stmt_verify_pass->bind_param("i", $user_id_to_edit);
            $stmt_verify_pass->execute();
            $result_verify_pass = $stmt_verify_pass->get_result();
            if ($user_pass_row = $result_verify_pass->fetch_assoc()) {
                if (password_verify($current_password_input, $user_pass_row['password'])) {
                    $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt_update_pass = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    if ($stmt_update_pass) {
                        $stmt_update_pass->bind_param("si", $hashed_new_password, $user_id_to_edit);
                        if ($stmt_update_pass->execute()) {
                            $feedback_messages[] = ['type' => 'success', 'text' => 'Password updated successfully!'];
                        } else {
                            $feedback_messages[] = ['type' => 'error', 'text' => 'Failed to update password: ' . $stmt_update_pass->error];
                        }
                        $stmt_update_pass->close();
                    } else {
                        $feedback_messages[] = ['type' => 'error', 'text' => 'Database error (password update prepare): ' . $conn->error];
                    }
                } else {
                    $feedback_messages[] = ['type' => 'error', 'text' => 'Incorrect current password.'];
                }
            }
            $stmt_verify_pass->close();
        } else {
            $feedback_messages[] = ['type' => 'error', 'text' => 'Database error (verify password prepare): ' . $conn->error];
        }
    }
}

if ($user_id_to_edit && $conn && !($conn->connect_error)) {
    $sql_factures = "SELECT id, total_price, created_at, shipping_address, phone_number 
                     FROM factures 
                     WHERE user_id = ? 
                     ORDER BY created_at DESC";
    $stmt_factures = $conn->prepare($sql_factures);
    if ($stmt_factures) {
        $stmt_factures->bind_param("i", $user_id_to_edit);
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
            }
            $facture['items'] = $facture_items;
            $user_factures[] = $facture;
        }
        $stmt_factures->close();
    } else {
        $feedback_messages[] = ['type' => 'error', 'text' => 'Error fetching order history: ' . $conn->error];
        error_log("Error preparing factures statement: " . $conn->error);
    }
}

$num_items_header = 0;
if ($user_id && $conn && !($conn->connect_error)) { 
    $sql_cart_count_header = "SELECT SUM(quantity) AS num_items FROM cart WHERE user_id = ?";
    $stmt_cart_count_header = $conn->prepare($sql_cart_count_header);
    if ($stmt_cart_count_header) {
        $stmt_cart_count_header->bind_param("i", $user_id);
        $stmt_cart_count_header->execute();
        $result_cart_count_header = $stmt_cart_count_header->get_result();
        if ($row_cart_count_header = $result_cart_count_header->fetch_assoc()) {
            $num_items_header = (int)($row_cart_count_header['num_items'] ?? 0);
        }
        $stmt_cart_count_header->close();
    }
}

$TARGET_ADMIN_ID_FOR_CHAT = 1; 

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🌌</text></svg>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - <?php echo $display_name; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="/access/img/logo_bw.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #111827; color: #D1D5DB; }
        .header-bg { background-color: #000000; border-bottom: 1px solid #222;}
        .logo span { color: #FFD700; } 
        .nav-link { color: #b0b0b0; padding: 0.5rem 0.75rem; border-radius: 0.375rem; transition: background-color 0.3s ease, color 0.3s ease; text-transform: uppercase; font-size: 0.8rem; font-weight: 500; letter-spacing: 0.5px;}
        .nav-link:hover { color: #00FFFF; background-color: rgba(0, 255, 255, 0.05); }
        .badge {
            position: absolute; top: -8px; right: -8px; padding: 1px 5px;
            border-radius: 50%; background-color: #00FFFF; color: #000000;
            font-size: 0.7rem; font-weight: 700; border: 1px solid #00FFFF;
            min-width: 20px; height:20px; display:flex; align-items:center; justify-content:center;
        }
        .chat-unread-badge {
            background-color: #EF4444; color: white;
            padding: 0.1rem 0.4rem; border-radius: 0.75rem; font-size: 0.65rem; font-weight: bold;
            position: absolute; top: -5px; right: -10px; line-height: 1;
            min-width: 16px; text-align: center; border: 1px solid #111827; 
        }
        button.nav-link.relative { position: relative; }
        .footer-bg { background-color: #000000; border-top: 1px solid #222;}
        .feedback-message { padding: 0.75rem 1rem; margin-bottom: 1.5rem; border-radius: 0.375rem; font-size: 0.875rem; }
        .feedback-message.success { background-color: #10B981; border: 1px solid #059669; color: #E0F2F7; }
        .feedback-message.error   { background-color: #EF4444; border: 1px solid #DC2626; color: #FEE2E2; }
        .feedback-message.info    { background-color: #3B82F6; border: 1px solid #2563EB; color: #EFF6FF; }
        .input-field { background-color: #374151; border-color: #4B5563; color: #F3F4F6; }
        .input-field:focus { border-color: #00FFFF; box-shadow: 0 0 0 2px rgba(0,255,255,0.5); outline: none; }
        .btn-action, .btn-primary { background-color: #00FFFF; color: #000000; }
        .btn-action:hover, .btn-primary:hover { background-color: #00DDDD; box-shadow: 0 0 10px #00FFFF; }
        .facture-card { background-color: #1F2937; border: 1px solid #374151; }
        .facture-item-image { width: 40px; height: 40px; object-fit: cover; border-radius: 0.25rem; }

        .chat-modal { display: none; position: fixed; inset: 0; background-color: rgba(0,0,0,0.75); align-items: center; justify-content: center; z-index: 1000; padding: 1rem;}
        .chat-modal.active { display: flex; }
        .chat-modal-content { /* Base style for modal content area */
            background-color: #1F2937; color: #D1D5DB; padding: 1.5rem; 
            border-radius: 0.5rem; box-shadow: 0 10px 25px rgba(0,0,0,0.5); 
            width: 100%; max-width: 500px; max-height: 80vh; 
            display: flex; flex-direction: column; 
        }
        .chat-modal-header { font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 1px solid #374151; display: flex; justify-content: space-between; align-items: center; }
        .chat-messages-area { flex-grow: 1; overflow-y: auto; margin-bottom: 1rem; padding-right: 0.5rem; display:flex; flex-direction:column-reverse; }
        .chat-message { margin-bottom: 0.75rem; padding: 0.5rem 0.75rem; border-radius: 0.5rem; max-width: 80%; word-wrap: break-word; font-size: 0.875rem; }
        .chat-message.sender { background-color: #00FFFF; color: #111827; margin-left: auto; align-self: flex-end; }
        .chat-message.receiver { background-color: #374151; color: #E5E7EB; margin-right: auto; align-self: flex-start; }
        .chat-message .msg-time { font-size: 0.7rem; color: #9CA3AF; display: block; margin-top: 0.25rem; }
        .chat-message.sender .msg-time { text-align: right; }
        .chat-message.receiver .msg-time { text-align: left; }
        .chat-message .msg-status { font-size: 0.7rem; color: #888; display: inline; margin-left: 5px;}
        .chat-message .msg-actions button { background: none; border: none; color: #007bff; cursor: pointer; font-size:0.7rem; margin-left: 5px; opacity: 0.7;}
        .chat-message.sender .msg-actions button { color: #111827; }
        .chat-message .msg-actions button:hover { text-decoration:underline; opacity: 1;}
        .chat-input-form { display: flex; gap: 0.5rem; }
        .chat-input-form input[type="text"] { flex-grow: 1; }
        #chat-file-input-label-user { cursor: pointer; padding: 0.6rem; background-color: #4B5563; border-radius: 0.375rem; display:flex; align-items:center; justify-content:center; }
        #chat-file-input-label-user:hover { background-color: #6B7280; }
        #chat-file-input-user { display: none; }
        #userChatSelectedFileName { font-size: 0.75rem; color: #9CA3AF; margin-top: 0.25rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: calc(100% - 100px); }
        .toast-notification-user { position: fixed; bottom: 20px; right: 20px; padding: 10px 15px; border-radius: 6px; box-shadow: 0 2px 10px rgba(0,0,0,0.2); z-index: 10001; opacity: 0; transition: opacity 0.3s ease, transform 0.3s ease; transform: translateY(20px); font-size: 0.875rem; font-weight: 500; }
        .toast-notification-user.success { background-color: #10B981; color: white; }
        .toast-notification-user.error { background-color: #EF4444; color: white; }
        .toast-notification-user.show { opacity: 1; transform: translateY(0); }

        /* Edit Chat Message Modal specific content styling if needed */
        #editChatMessageModal .modal-content-area { /* This is the inner div for edit modal */
            /* Inherits .chat-modal-content styles by default if class is changed, or can be styled uniquely */
            /* For example, to use user's preferred max-width: */
             max-width: 32rem; /* Tailwind's lg */
        }
        .chat-embedded-image, .chat-embedded-video {
            max-width: 100%; max-height: 250px; border-radius: 0.25rem; 
            margin-top: 0.5rem; display: block; background-color: #4B5563;
        }
        .chat-embedded-video { border: 1px solid #4B5563; }
        .file-link-style { color: #60A5FA; text-decoration: underline; }
        .chat-message.sender .file-link-style { color: #0e7490; }
        .chat-message.receiver .file-link-style { color: #93C5FD; }
    </style>
</head>
<body class="antialiased">
    <header class="header-bg text-gray-300 shadow-lg sticky top-0 z-50">
        <div class="nav container mx-auto flex items-center justify-between p-4">
            <a href="/user_interface/index_user.php?user_id=<?php echo $user_id; ?>" class="logo text-2xl font-bold text-white">Muscle<span>Mafia</span></a>
            <div class="text-xs md:text-sm">Logged in as: <span class="font-semibold text-white"><?php echo $display_name; ?></span></div>
            <nav class="space-x-2">
                <a href="/user_interface/index_user.php?user_id=<?php echo $user_id; ?>" class="nav-link">Shop</a>
                <a href="/user_interface/card.php?id=<?php echo $user_id; ?>" class="nav-link relative">
                    <i class="fas fa-shopping-cart"></i> Cart
                    <span class="badge <?php echo ($num_items_header == 0) ? 'hidden' : ''; ?>" id="cartItemCountBadgeHeader"><?php echo $num_items_header; ?></span>
                </a>
                <?php if ($user_id): ?>
                <button id="openUserChatBtn" class="nav-link relative">
                    <i class="fas fa-comments"></i> Chat
                    <span id="chatUnreadBadge" class="chat-unread-badge hidden">0</span>
                </button>
                <?php endif; ?>
                <a href="/login.php" class="nav-link">Logout</a>
            </nav>
        </div>
    </header>

    <main class="container mx-auto py-8 px-4 max-w-3xl">
        <h1 class="text-4xl font-orbitron font-bold mb-10 text-center text-white">My Profile</h1>

        <?php if (!empty($feedback_messages)): ?>
            <?php foreach ($feedback_messages as $msg): ?>
                <div class="feedback-message <?php echo htmlspecialchars($msg['type']); ?> max-w-xl mx-auto" role="alert">
                    <?php echo htmlspecialchars($msg['text']); ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($user_id_to_edit): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div class="bg-gray-800 p-6 rounded-lg shadow-xl border border-gray-700">
                <h2 class="text-2xl font-semibold mb-6 text-white">Update Information</h2>
                <form action="update_user.php?user_id=<?php echo $user_id_to_edit; ?>" method="POST">
                    <input type="hidden" name="user_id_for_update" value="<?php echo $user_id_to_edit; ?>">
                    <div class="mb-5">
                        <label for="username" class="block mb-1 text-sm font-medium text-gray-300">Username</label>
                        <input type="text" id="username" name="username"
                               class="w-full px-4 py-2.5 input-field rounded-md focus:ring-2 focus:ring-sky-500"
                               value="<?php echo $current_username; ?>" required minlength="3">
                    </div>
                    <div class="mb-6">
                        <label for="email" class="block mb-1 text-sm font-medium text-gray-300">Email Address</label>
                        <input type="email" id="email" name="email"
                               class="w-full px-4 py-2.5 input-field rounded-md focus:ring-2 focus:ring-sky-500"
                               value="<?php echo $current_email; ?>" required>
                    </div>
                    <button type="submit" name="submit_profile_update"
                            class="w-full btn-action text-black font-semibold py-2.5 px-6 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-800 focus:ring-sky-500 transition-all duration-300 ease-in-out transform hover:scale-105">
                        Save Profile Changes
                    </button>
                </form>
            </div>

            <div class="bg-gray-800 p-6 rounded-lg shadow-xl border border-gray-700">
                <h2 class="text-2xl font-semibold mb-6 text-white">Change Password</h2>
                <form action="update_user.php?user_id=<?php echo $user_id_to_edit; ?>" method="POST" id="changePasswordForm">
                    <input type="hidden" name="user_id_for_password" value="<?php echo $user_id_to_edit; ?>">
                    <div class="mb-5">
                        <label for="current_password" class="block mb-1 text-sm font-medium text-gray-300">Current Password</label>
                        <input type="password" id="current_password" name="current_password"
                               class="w-full px-4 py-2.5 input-field rounded-md focus:ring-2 focus:ring-sky-500"
                               required>
                    </div>
                    <div class="mb-5">
                        <label for="new_password" class="block mb-1 text-sm font-medium text-gray-300">New Password</label>
                        <input type="password" id="new_password" name="new_password"
                               class="w-full px-4 py-2.5 input-field rounded-md focus:ring-2 focus:ring-sky-500"
                               placeholder="Min. 8 characters" required minlength="8">
                    </div>
                    <div class="mb-6">
                        <label for="confirm_new_password" class="block mb-1 text-sm font-medium text-gray-300">Confirm New Password</label>
                        <input type="password" id="confirm_new_password" name="confirm_new_password"
                               class="w-full px-4 py-2.5 input-field rounded-md focus:ring-2 focus:ring-sky-500"
                               required>
                        <p id="passwordMatchError" class="text-red-400 text-xs mt-1 hidden">New passwords do not match.</p>
                    </div>
                    <button type="submit" name="submit_password_update"
                            class="w-full btn-action text-black font-semibold py-2.5 px-6 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-800 focus:ring-sky-500 transition-all duration-300 ease-in-out transform hover:scale-105">
                        Update Password
                    </button>
                </form>
            </div>
        </div>

        <div class="mt-12 md:mt-16">
            <h2 class="text-3xl font-orbitron font-bold mb-8 text-center text-white">My Order History</h2>
            <?php if (!empty($user_factures)): ?>
                <div class="space-y-6">
                    <?php foreach ($user_factures as $facture): ?>
                        <div class="facture-card p-6 rounded-lg shadow-xl border border-gray-700">
                            <div class="flex flex-wrap justify-between items-center mb-4 border-b border-gray-600 pb-3">
                                <div>
                                    <h3 class="text-xl font-semibold text-sky-400">Facture ID: #<?php echo $facture['id']; ?></h3>
                                    <p class="text-sm text-gray-400">Date: <?php echo date("M d, Y H:i", strtotime($facture['created_at'])); ?></p>
                                </div>
                                <p class="text-xl font-bold text-white">Total: DZ<?php echo number_format((float)$facture['total_price'], 2); ?></p>
                            </div>
                            <div class="mb-4">
                                <h4 class="text-md font-semibold text-gray-200 mb-1">Shipping To:</h4>
                                <p class="text-sm text-gray-400"><?php echo nl2br(htmlspecialchars($facture['shipping_address'])); ?></p>
                                <p class="text-sm text-gray-400">Phone: <?php echo htmlspecialchars($facture['phone_number']); ?></p>
                            </div>
                            <div>
                                <h4 class="text-md font-semibold text-gray-200 mb-2">Items Ordered:</h4>
                                <ul class="space-y-2">
                                    <?php if (!empty($facture['items'])): ?>
                                        <?php foreach ($facture['items'] as $item): ?>
                                            <li class="flex items-center justify-between text-sm border-b border-gray-700 py-2 last:border-b-0">
                                                <div class="flex items-center">
                                                    <img src="<?php echo htmlspecialchars($item['product_image'] ?: 'https://placehold.co/40x40/374151/9CA3AF?text=N/A'); ?>" 
                                                         alt="<?php echo htmlspecialchars($item['product_name']); ?>" 
                                                         class="facture-item-image mr-3"
                                                         onerror="this.onerror=null;this.src='https://placehold.co/40x40/374151/9CA3AF?text=N/A';">
                                                    <div>
                                                        <span class="text-gray-100"><?php echo htmlspecialchars($item['product_name']); ?></span>
                                                        <span class="text-gray-400 ml-2">(x<?php echo $item['quantity']; ?>)</span>
                                                    </div>
                                                </div>
                                                <span class="text-gray-300">DZ<?php echo number_format((float)$item['price_at_purchase'] * (int)$item['quantity'], 2); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <li class="text-gray-400">No items found for this facture.</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-center text-gray-400 py-10 bg-gray-800 rounded-lg shadow-xl">You have no past orders.</p>
            <?php endif; ?>
        </div>
        <?php else: ?>
            <p class="text-center text-red-500 text-lg">Could not load profile information. Please ensure you are logged in correctly and the user exists.</p>
        <?php endif; ?>
    </main>

    <div id="userChatModal" class="chat-modal">
        <div class="chat-modal-content">
            <div class="chat-modal-header">
                <span>Chat with Admin</span>
                <button id="closeUserChatModalBtn" class="text-gray-300 hover:text-white text-2xl">&times;</button>
            </div>
            <div id="userChatMessagesArea" class="chat-messages-area">
                <p class="p-4 text-gray-400 text-center">Loading chat...</p>
            </div>
            <div id="userChatSelectedFileName" class="text-xs text-gray-400 mb-1 truncate"></div>
            <form id="userChatMessageForm" class="chat-input-form">
                <label for="chat-file-input-user" id="chat-file-input-label-user" title="Attach file">
                    <i class="fas fa-paperclip"></i>
                </label>
                <input type="file" id="chat-file-input-user" name="chat_file" accept="image/*,video/*,application/pdf,.txt,.doc,.docx">
                <input type="text" id="userChatMessageInput" name="message" class="input-field px-3 py-2 rounded-md w-full" placeholder="Type a message..." autocomplete="off">
                <button type="submit" class="btn-action py-2 px-4 rounded-md"><i class="fas fa-paper-plane"></i></button>
            </form>
        </div>
    </div>

    <div id="editChatMessageModal" class="chat-modal"> 
        <div class="chat-modal-content modal-content-area"> <div class="chat-modal-header"> <h3 class="text-xl font-semibold text-white">Edit Message</h3>
                <button id="closeEditChatMessageModal" class="text-gray-300 hover:text-white text-2xl">&times;</button>
            </div>
            <form id="editChatMessageForm" class="pt-4"> <input type="hidden" id="edit_chat_message_id_modal" name="message_id">
                <div class="mb-4">
                    <label for="edit_chat_message_text_modal" class="block text-sm font-medium mb-1 text-gray-300">Message</label>
                    <textarea id="edit_chat_message_text_modal" name="new_message_text" rows="3" class="input-field w-full p-2 rounded-md" required></textarea>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancelEditChatMessageForm" class="bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded-md font-medium">Cancel</button>
                    <button type="submit" class="btn-primary py-2 px-4 rounded-md font-medium">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <div id="toast-container-user" class="fixed bottom-5 right-5 z-[10001] space-y-2"></div>

    <footer class="footer-bg text-gray-400 text-center p-6 mt-12 border-t border-gray-700">
        <p>&copy; <?php echo date("Y"); ?> MuscleMafia. All rights reserved.</p>
    </footer>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const changePasswordForm = document.getElementById('changePasswordForm');
        if (changePasswordForm) {
            // Password validation logic (unchanged)
            const newPasswordField = document.getElementById('new_password');
            const confirmNewPasswordField = document.getElementById('confirm_new_password');
            const passwordMatchError = document.getElementById('passwordMatchError');
            function validatePasswordMatch() {
                if (newPasswordField.value !== confirmNewPasswordField.value && confirmNewPasswordField.value !== '') {
                    passwordMatchError.classList.remove('hidden');
                    confirmNewPasswordField.classList.add('border-red-500', 'focus:ring-red-500');
                    confirmNewPasswordField.classList.remove('focus:ring-sky-500');
                } else {
                    passwordMatchError.classList.add('hidden');
                    confirmNewPasswordField.classList.remove('border-red-500', 'focus:ring-red-500');
                    confirmNewPasswordField.classList.add('focus:ring-sky-500');
                }
            }
            if(newPasswordField) newPasswordField.addEventListener('input', validatePasswordMatch);
            if(confirmNewPasswordField) confirmNewPasswordField.addEventListener('input', validatePasswordMatch);
            changePasswordForm.addEventListener('submit', function(event) {
                if (newPasswordField.value !== confirmNewPasswordField.value) {
                    event.preventDefault(); 
                    validatePasswordMatch(); 
                    if(confirmNewPasswordField) confirmNewPasswordField.focus();
                }
            });
        }

        const LOGGED_IN_USER_ID = <?php echo json_encode($user_id); ?>; 
        const TARGET_ADMIN_ID = <?php echo json_encode($TARGET_ADMIN_ID_FOR_CHAT); ?>;
        const CHAT_POLL_INTERVAL = 5000; 
        const UNREAD_COUNT_POLL_INTERVAL = 15000; 

        const userChatModal = document.getElementById('userChatModal');
        const openUserChatBtn = document.getElementById('openUserChatBtn');
        const closeUserChatModalBtn = document.getElementById('closeUserChatModalBtn');
        const userChatMessagesArea = document.getElementById('userChatMessagesArea');
        const userChatMessageForm = document.getElementById('userChatMessageForm');
        const userChatMessageInput = document.getElementById('userChatMessageInput');
        const userChatFileInput = document.getElementById('chat-file-input-user');
        const userChatSelectedFileNameDiv = document.getElementById('userChatSelectedFileName');
        const chatUnreadBadge = document.getElementById('chatUnreadBadge');

        const editChatMessageModalEl = document.getElementById('editChatMessageModal');
        const closeEditChatMessageModalBtn = document.getElementById('closeEditChatMessageModal');
        const cancelEditChatMessageFormBtn = document.getElementById('cancelEditChatMessageForm');
        const editChatMessageForm = document.getElementById('editChatMessageForm');

        let userChatPollingInterval = null;
        let unreadCountPollingInterval = null;
        let lastUserFetchedMessageId = 0;

        function showUserToast(message, isError = false) {
            // Toast function (unchanged)
            const toastContainer = document.getElementById('toast-container-user');
            if (!toastContainer) { console.warn("User toast container not found!"); return; } 
            const toast = document.createElement('div');
            toast.className = `toast-notification-user ${isError ? 'error' : 'success'}`;
            toast.textContent = message;
            toastContainer.appendChild(toast);
            setTimeout(() => { toast.classList.add('show'); }, 10);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => { if(toast.parentNode) toast.remove(); }, 300); 
            }, 3000 + (toastContainer.childElementCount * 500)); 
        }

        async function fetchUnreadMessagesCount() {
            // Unread count fetch (unchanged)
            if (!LOGGED_IN_USER_ID) return;
            try {
                const response = await fetch(`update_user.php?ajax_action_user_chat=get_unread_admin_messages_count&user_id_ajax=${LOGGED_IN_USER_ID}`);
                const data = await handleFetchResponse(response);
                if (data.success && typeof data.unread_count !== 'undefined') {
                    updateUnreadBadge(data.unread_count);
                } else {
                    console.warn("Failed to fetch unread count:", data.message || "No message from server");
                }
            } catch (error) {
                console.error('Error fetching unread messages count:', error);
            }
        }

        function updateUnreadBadge(count) {
            // Update badge (unchanged)
            if (chatUnreadBadge) {
                if (count > 0) {
                    chatUnreadBadge.textContent = count > 99 ? '99+' : count;
                    chatUnreadBadge.classList.remove('hidden');
                } else {
                    chatUnreadBadge.classList.add('hidden');
                }
            }
        }

        async function fetchUserChatMessages(initialFetch = false) {
            // Fetch messages (unchanged)
            if (!LOGGED_IN_USER_ID) return;
            try {
                const response = await fetch(`update_user.php?ajax_action_user_chat=fetch_user_chat_messages&last_message_id=${lastUserFetchedMessageId}&user_id_ajax=${LOGGED_IN_USER_ID}`);
                const data = await handleFetchResponse(response);
                if (data.success && Array.isArray(data.messages)) {
                    if (initialFetch) {
                        userChatMessagesArea.innerHTML = ''; 
                        lastUserFetchedMessageId = 0; 
                    }
                    if (data.messages.length > 0) {
                         if (userChatMessagesArea.innerHTML === '' || userChatMessagesArea.querySelector('p.text-center')) {
                            userChatMessagesArea.innerHTML = ''; 
                        }
                        data.messages.forEach(msg => {
                            appendUserChatMessage(msg, true); 
                            if (parseInt(msg.id) > lastUserFetchedMessageId) {
                                lastUserFetchedMessageId = parseInt(msg.id);
                            }
                        });
                        if (initialFetch) scrollToBottomChat(userChatMessagesArea);
                    } else if (initialFetch && userChatMessagesArea.innerHTML === '') {
                         userChatMessagesArea.innerHTML = '<p class="p-4 text-gray-400 text-center">No messages yet. Say hi to the admin!</p>';
                    }
                    fetchUnreadMessagesCount();
                } else {
                     console.warn("Failed to fetch messages or no new messages:", data.message || "No message from server");
                     if (initialFetch) userChatMessagesArea.innerHTML = '<p class="p-4 text-gray-400 text-center">Could not load messages. Try again.</p>';
                }
            } catch (error) {
                console.error('Error fetching user chat messages:', error);
                if (initialFetch) userChatMessagesArea.innerHTML = `<p class="p-4 text-red-400 text-center">Error loading messages: ${error.message}</p>`;
            }
        }

        if (openUserChatBtn) {
            // Open chat button listener (unchanged)
            openUserChatBtn.addEventListener('click', () => {
                if (!LOGGED_IN_USER_ID) {
                    showUserToast('You must be logged in to chat.', true); return;
                }
                userChatModal.classList.add('active');
                lastUserFetchedMessageId = 0; 
                userChatMessagesArea.innerHTML = '<p class="p-4 text-gray-400 text-center">Loading messages...</p>';
                fetchUserChatMessages(true);
                if (userChatPollingInterval) clearInterval(userChatPollingInterval);
                userChatPollingInterval = setInterval(() => fetchUserChatMessages(false), CHAT_POLL_INTERVAL);
            });
        }

        if (closeUserChatModalBtn) {
            // Close chat button listener (unchanged)
            closeUserChatModalBtn.addEventListener('click', () => {
                userChatModal.classList.remove('active');
                if (userChatPollingInterval) clearInterval(userChatPollingInterval);
                fetchUnreadMessagesCount(); 
            });
        }
        
        if(userChatFileInput) {
            // File input listener (unchanged)
            userChatFileInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    userChatSelectedFileNameDiv.textContent = `File: ${this.files[0].name}`;
                } else {
                    userChatSelectedFileNameDiv.textContent = '';
                }
            });
        }

        async function handleFetchResponse(response) {
            // Handle fetch response (unchanged)
            if (!response.ok) {
                let errorText = `HTTP error! Status: ${response.status}`;
                try { const serverError = await response.text(); errorText += `, Response: ${serverError.substring(0, 200)}`; } catch (e) {}
                throw new Error(errorText);
            }
            const contentType = response.headers.get("content-type");
            if (contentType && contentType.indexOf("application/json") !== -1) {
                return response.json();
            } else {
                const responseText = await response.text();
                console.error("Non-JSON response from server:", responseText); 
                throw new Error(`Expected JSON, got ${contentType}. Response: ${responseText.substring(0,200)}`);
            }
        }
        
        function formatUserChatMessageTime(timestamp) {
            // Format time (unchanged)
            if (!timestamp) return 'Sending...';
            const date = new Date(timestamp.replace(' ', 'T')+'Z'); 
            return date.toLocaleString('en-GB', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: 'short' });
        }

        function appendUserChatMessage(msg, prepend = false) { 
            // Append message to UI (unchanged from previous version with edit/delete buttons)
            const msgDiv = document.createElement('div');
            msgDiv.classList.add('chat-message');
            msgDiv.dataset.messageId = msg.id;

            let messageTextEscaped = (msg.message || "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\n/g, '<br>');
            let messageContentHTML = `<span class="block msg-text-content">${messageTextEscaped}</span>`;
            
            if(msg.file_path) {
                const fileName = msg.file_path.split('/').pop() || "attachment";
                const safeFileName = (fileName || "Attached File").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
                const fileExtension = fileName.split('.').pop().toLowerCase();
                const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
                const videoExtensions = ['mp4', 'webm', 'ogg'];
                let mediaHTML = '';
                if (imageExtensions.includes(fileExtension)) {
                    mediaHTML = `<img src="${msg.file_path}" alt="${safeFileName}" class="chat-embedded-image" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">`; 
                } else if (videoExtensions.includes(fileExtension)) {
                    let videoType = (fileExtension === 'mp4') ? 'video/mp4' : (fileExtension === 'webm') ? 'video/webm' : (fileExtension === 'ogg') ? 'video/ogg' : '';
                    mediaHTML = `<video controls class="chat-embedded-video" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"><source src="${msg.file_path}" type="${videoType}">Your browser does not support the video tag.</video>`;
                }
                if (mediaHTML) messageContentHTML += mediaHTML;
                const linkClass = msg.sender_id == LOGGED_IN_USER_ID ? 'file-link-style sender' : 'file-link-style receiver';
                messageContentHTML += `<a href="${msg.file_path}" target="_blank" class="${linkClass} text-sm block mt-1"><i class="fas fa-paperclip mr-1"></i> ${safeFileName}</a>`;
            }

            let editedStatus = '';
            if (msg.edit == 0) { 
                editedStatus = `<span class="msg-status">(edited${msg.typesender === 'admin' && msg.sender_id != LOGGED_IN_USER_ID ? ' by admin' : ''})</span>`;
            }

            if (msg.sender_id == LOGGED_IN_USER_ID) { 
                msgDiv.classList.add('sender');
                msgDiv.innerHTML = `<div>${messageContentHTML}</div><div class="msg-time">${formatUserChatMessageTime(msg.created_at)} ${editedStatus}</div><div class="msg-actions"><button class="edit-user-chat-msg-btn" data-id="${msg.id}" title="Edit"><i class="fas fa-edit"></i></button><button class="delete-user-chat-msg-btn" data-id="${msg.id}" title="Delete"><i class="fas fa-trash"></i></button></div>`;
            } else { 
                msgDiv.classList.add('receiver');
                msgDiv.innerHTML = `<div>${messageContentHTML}</div><div class="msg-time">${formatUserChatMessageTime(msg.created_at)} ${editedStatus}</div>`;
            }
            if (prepend) userChatMessagesArea.insertBefore(msgDiv, userChatMessagesArea.firstChild);
            else userChatMessagesArea.appendChild(msgDiv);
        }

        function scrollToBottomChat(element) {
            // Scroll to bottom (unchanged)
            element.scrollTop = 0; 
        }

        if (userChatMessageForm) {
            // Send message form listener (unchanged)
            userChatMessageForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const messageText = userChatMessageInput.value.trim();
                const chatFile = userChatFileInput.files[0];
                if (!messageText && !chatFile) { showUserToast('Please type a message or select a file.', true); return; }
                const formData = new FormData();
                formData.append('ajax_action_user_chat', 'send_chat_message');
                formData.append('message', messageText);
                formData.append('user_id_ajax', LOGGED_IN_USER_ID);
                if (chatFile) formData.append('chat_file', chatFile);
                userChatMessageInput.disabled = true; userChatFileInput.disabled = true; this.querySelector('button[type="submit"]').disabled = true;
                try {
                    const response = await fetch('update_user.php', { method: 'POST', body: formData });
                    const data = await handleFetchResponse(response);
                    if (data.success && data.sent_message) {
                        if(userChatMessagesArea.querySelector('p.text-center')) userChatMessagesArea.innerHTML = '';
                        appendUserChatMessage(data.sent_message, true); 
                        userChatMessageInput.value = ''; userChatFileInput.value = ''; userChatSelectedFileNameDiv.textContent = ''; 
                        scrollToBottomChat(userChatMessagesArea); 
                    } else {
                        showUserToast(data.message || 'Failed to send message.', true); console.error("Send message reported not success:", data.message);
                    }
                } catch (error) {
                    console.error('Error sending user chat message:', error); showUserToast('Error sending message: ' + error.message, true);
                } finally {
                    userChatMessageInput.disabled = false; userChatFileInput.disabled = false; this.querySelector('button[type="submit"]').disabled = false; userChatMessageInput.focus();
                }
            });
        }

        userChatMessagesArea.addEventListener('click', function(e) {
            // Edit/Delete button listeners in chat area (unchanged logic for opening modal)
            const editButton = e.target.closest('.edit-user-chat-msg-btn');
            const deleteButton = e.target.closest('.delete-user-chat-msg-btn');
            if (editButton) {
                const messageId = editButton.dataset.id;
                const messageDiv = editButton.closest('.chat-message');
                let textToEdit = '';
                const textSpan = messageDiv.querySelector('.msg-text-content'); 
                if (textSpan) textToEdit = textSpan.innerHTML.replace(/<br\s*\/?>/gi, "\n").replace(/<a [^>]*>.*?<\/a>/g, '').replace(/<img [^>]*>/g, '').replace(/<video [^>]*>.*?<\/video>/g, '').replace(/<[^>]+>/g, '').trim();
                else { const firstDiv = messageDiv.querySelector('div:first-child'); if(firstDiv) textToEdit = (firstDiv.innerText || firstDiv.textContent).trim(); }
                document.getElementById('edit_chat_message_id_modal').value = messageId;
                document.getElementById('edit_chat_message_text_modal').value = textToEdit; 
                if(editChatMessageModalEl) editChatMessageModalEl.classList.add('active');
                else console.error("Edit chat message modal element not found!");
            }
            if (deleteButton) {
                const messageId = deleteButton.dataset.id;
                if (confirm('Are you sure you want to delete this message? This action cannot be undone.')) {
                    deleteUserChatMessage(messageId);
                }
            }
        });
        
        if(closeEditChatMessageModalBtn) {
            // Close edit modal button (unchanged)
            closeEditChatMessageModalBtn.addEventListener('click', () => {
                if(editChatMessageModalEl) editChatMessageModalEl.classList.remove('active');
            });
        }

        if(cancelEditChatMessageFormBtn) {
            // Cancel edit form button (unchanged)
            cancelEditChatMessageFormBtn.addEventListener('click', () => {
                 if(editChatMessageModalEl) editChatMessageModalEl.classList.remove('active');
            });
        }

        if(editChatMessageForm) {
            // Edit form submission
            editChatMessageForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('ajax_action_user_chat', 'edit_chat_message');
                formData.append('user_id_ajax', LOGGED_IN_USER_ID);
                try {
                    const response = await fetch('update_user.php', {method: 'POST', body: formData});
                    const data = await handleFetchResponse(response);
                    showUserToast(data.message, !data.success); // Show feedback from server
                    if(data.success) { // PHP now sends success:true if SQL execute was fine
                        if(editChatMessageModalEl) editChatMessageModalEl.classList.remove('active');
                        // For simplicity and robustness, just refetch all messages after an edit.
                        // This ensures the "edited" status and new text are correctly displayed.
                        lastUserFetchedMessageId = 0; // Reset to fetch all
                        fetchUserChatMessages(true); 
                    } else {
                        console.error("Edit message reported not success by server or error:", data.message);
                    }
                } catch (error) {
                    console.error("Error editing user chat message:", error);
                    showUserToast("Error editing message: " + error.message, true);
                }
            });
        }

        async function deleteUserChatMessage(messageId) {
            // Delete message function (unchanged)
            const formData = new FormData();
            formData.append('ajax_action_user_chat', 'delete_chat_message');
            formData.append('message_id', messageId);
            formData.append('user_id_ajax', LOGGED_IN_USER_ID);
            try {
                const response = await fetch('update_user.php', {method: 'POST', body: formData});
                const data = await handleFetchResponse(response);
                showUserToast(data.message, !data.success);
                if(data.success) {
                    const messageElement = userChatMessagesArea.querySelector(`.chat-message[data-message-id='${messageId}']`);
                    if(messageElement) messageElement.remove();
                     if (userChatMessagesArea.childElementCount === 0)  userChatMessagesArea.innerHTML = '<p class="p-4 text-gray-400 text-center">No messages yet. Say hi to the admin!</p>';
                    fetchUnreadMessagesCount(); 
                } else {
                     console.error("Delete message reported not success:", data.message);
                }
            } catch (error) {
                console.error("Error deleting user chat message:", error);
                showUserToast("Error deleting message: " + error.message, true);
            }
        }

        if (LOGGED_IN_USER_ID) {
            // Initial calls and polling setup (unchanged)
            fetchUnreadMessagesCount();
            if (unreadCountPollingInterval) clearInterval(unreadCountPollingInterval);
            unreadCountPollingInterval = setInterval(fetchUnreadMessagesCount, UNREAD_COUNT_POLL_INTERVAL);
        }
    });
    </script>
</body>
</html>
<?php
if (isset($conn) && is_object($conn) && method_exists($conn, 'close') && property_exists($conn, 'thread_id') && $conn->thread_id) { 
    $conn->close();
}
?>
