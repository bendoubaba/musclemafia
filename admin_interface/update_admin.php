<?php
session_start();
include('database.php'); // Ensure this file uses mysqli and sets up $conn

// --- ADMIN AUTHENTICATION CHECK ---
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin' || !isset($_SESSION['admin_id'])) {
    // If not an admin, redirect to admin login or an error page
    header('Location: /login.php?error=admin_only'); // Adjust path as needed
    exit("Access Denied. Admin privileges required.");
}
$loggedInAdminId = $_SESSION['admin_id'];
$loggedInAdminName = $_SESSION['admin_name'] ?? 'Admin';

// --- GET USER TO EDIT ---
$user_id_to_edit = null;
if (isset($_GET['edit_user_id']) && filter_var($_GET['edit_user_id'], FILTER_VALIDATE_INT)) {
    $user_id_to_edit = (int)$_GET['edit_user_id'];
} else {
    // Redirect to a user listing page or show an error if no user is specified for editing
    // For example: header('Location: admin_manage_users.php?error=nouserselected');
    exit("No user selected for editing.");
}

// Prevent admin from editing their own super-admin details through this specific interface if needed,
// or handle self-edit differently. For now, allowing edit.

$current_username = "";
$current_email = "";
$current_role = "";
$feedback_messages = []; 

// Fetch current user data to pre-fill form
$stmt_fetch = $conn->prepare("SELECT username, email, role FROM admins WHERE id = ?");
if ($stmt_fetch) {
    $stmt_fetch->bind_param("i", $user_id_to_edit);
    $stmt_fetch->execute();
    $result_fetch = $stmt_fetch->get_result();
    if ($user_data_row = $result_fetch->fetch_assoc()) {
        $current_username = htmlspecialchars($user_data_row['username']);
        $current_email = htmlspecialchars($user_data_row['email']);
        $current_role = htmlspecialchars($user_data_row['role']);
    } else {
        $feedback_messages[] = ['type' => 'error', 'text' => 'User with ID ' . $user_id_to_edit . ' not found.'];
        // Optionally, disable forms or redirect
    }
    $stmt_fetch->close();
} else {
    $feedback_messages[] = ['type' => 'error', 'text' => 'Error fetching user data: ' . $conn->error];
    error_log("Error preparing user fetch statement for admin edit: " . $conn->error);
}


// --- HANDLE PROFILE INFO UPDATE (USERNAME, EMAIL, ROLE) ---
if (isset($_POST['submit_profile_update'])) {
    $form_user_id = (int)$_POST['user_id_for_update'];

    if ($form_user_id !== $user_id_to_edit) {
        $feedback_messages[] = ['type' => 'error', 'text' => 'Data mismatch. Update failed.'];
    } else {
        $new_username = trim($_POST['username']);
        $new_email = trim($_POST['email']);
        $new_role = trim($_POST['role']);

        $update_fields = [];
        $update_params_types = "";
        $update_params_values = [];
        $can_proceed = true;

        // Validate and prepare username update
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
                        $feedback_messages[] = ['type' => 'error', 'text' => 'Username already taken by another user.'];
                        $can_proceed = false;
                    } else {
                        $update_fields[] = "username = ?"; $update_params_types .= "s"; $update_params_values[] = $new_username;
                    }
                    $stmt_check_username->close();
                } else { $feedback_messages[] = ['type' => 'error', 'text' => 'DB error (username check).']; $can_proceed = false;}
            }
        }

        // Validate and prepare email update
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
                        $feedback_messages[] = ['type' => 'error', 'text' => 'Email already taken by another user.'];
                        $can_proceed = false;
                    } else {
                        $update_fields[] = "email = ?"; $update_params_types .= "s"; $update_params_values[] = $new_email;
                    }
                    $stmt_check_email->close();
                } else { $feedback_messages[] = ['type' => 'error', 'text' => 'DB error (email check).']; $can_proceed = false;}
            }
        }
        
        // Prepare role update
        if ($can_proceed && !empty($new_role) && $new_role !== $current_role) {
            if (in_array($new_role, ['user', 'admin'])) { // Add other valid roles if any
                // Prevent admin from demoting the last admin or their own primary admin account if specific rules apply
                // For simplicity, allowing role change here.
                if ($user_id_to_edit === $loggedInAdminId && $new_role !== 'admin') {
                     $feedback_messages[] = ['type' => 'error', 'text' => 'Admin cannot demote their own account through this interface.'];
                     $can_proceed = false;
                } else {
                    $update_fields[] = "role = ?"; $update_params_types .= "s"; $update_params_values[] = $new_role;
                }
            } else {
                $feedback_messages[] = ['type' => 'error', 'text' => 'Invalid role specified.'];
                $can_proceed = false;
            }
        }


        if ($can_proceed && !empty($update_fields)) {
            $update_sql = "UPDATE admins SET " . implode(", ", $update_fields) . " WHERE id = ?";
            $update_params_types .= "i";
            $update_params_values[] = $user_id_to_edit;

            $stmt_update = $conn->prepare($update_sql);
            if ($stmt_update) {
                $stmt_update->bind_param($update_params_types, ...$update_params_values);
                if ($stmt_update->execute()) {
                    $feedback_messages[] = ['type' => 'success', 'text' => 'User profile updated successfully! Refreshing data...'];
                    // Re-fetch data to show updated values
                    $stmt_refetch = $conn->prepare("SELECT username, email, role FROM users WHERE id = ?");
                    if ($stmt_refetch) {
                        $stmt_refetch->bind_param("i", $user_id_to_edit);
                        $stmt_refetch->execute();
                        $result_refetch = $stmt_refetch->get_result();
                        if ($user_data_re = $result_refetch->fetch_assoc()) {
                            $current_username = htmlspecialchars($user_data_re['username']);
                            $current_email = htmlspecialchars($user_data_re['email']);
                            $current_role = htmlspecialchars($user_data_re['role']);
                        }
                        $stmt_refetch->close();
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

// --- HANDLE PASSWORD UPDATE BY ADMIN ---
if (isset($_POST['submit_password_update'])) {
    $form_user_id_pwd = (int)$_POST['user_id_for_password'];
    $new_password = $_POST['new_password_admin']; // Admin sets new password directly
    $confirm_new_password = $_POST['confirm_new_password_admin'];

     if ($form_user_id_pwd !== $user_id_to_edit) {
        $feedback_messages[] = ['type' => 'error', 'text' => 'Data mismatch. Password update failed.'];
    } elseif (empty($new_password) || empty($confirm_new_password)) {
        $feedback_messages[] = ['type' => 'error', 'text' => 'New password and confirmation are required.'];
    } elseif (strlen($new_password) < 8) {
        $feedback_messages[] = ['type' => 'error', 'text' => 'New password must be at least 8 characters long.'];
    } elseif ($new_password !== $confirm_new_password) {
        $feedback_messages[] = ['type' => 'error', 'text' => 'New passwords do not match.'];
    } else {
        $hashed_new_password = ($new_password);
        $stmt_update_pass = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
        if ($stmt_update_pass) {
            $stmt_update_pass->bind_param("si", $hashed_new_password, $user_id_to_edit);
            if ($stmt_update_pass->execute()) {
                $feedback_messages[] = ['type' => 'success', 'text' => "Password for user '{$current_username}' updated successfully!"];
            } else {
                $feedback_messages[] = ['type' => 'error', 'text' => 'Failed to update password: ' . $stmt_update_pass->error];
            }
            $stmt_update_pass->close();
        } else {
            $feedback_messages[] = ['type' => 'error', 'text' => 'Database error (password update prepare): ' . $conn->error];
        }
    }
}

// Cart item count for header (for the logged-in admin, if they also act as a user)
$admin_cart_items = 0;
$stmt_admin_cart = $conn->prepare("SELECT COUNT(id) AS num_items FROM cart WHERE user_id = ?");
if($stmt_admin_cart){
    $stmt_admin_cart->bind_param("i", $loggedInAdminId);
    $stmt_admin_cart->execute();
    $res_admin_cart = $stmt_admin_cart->get_result();
    if($row_admin_cart = $res_admin_cart->fetch_assoc()){
        $admin_cart_items = (int)$row_admin_cart['num_items'];
    }
    $stmt_admin_cart->close();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon"
        href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🌌</text></svg>">
    
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin: Edit User - <?php echo $current_username ?: 'N/A'; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="/access/img/logo_bw.png" type="image/png"> <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.1/css/all.min.css">
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
        .footer-bg { background-color: #000000; }
        .feedback-message { padding: 0.75rem 1rem; margin-bottom: 1.5rem; border-radius: 0.375rem; font-size: 0.875rem; }
        .feedback-message.success { background-color: #d1fae5; border: 1px solid #6ee7b7; color: #065f46; }
        .feedback-message.error   { background-color: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }
        .feedback-message.info    { background-color: #e0f2fe; border: 1px solid #7dd3fc; color: #0c4a6e; }
        .input-field { background-color: #374151; border-color: #4B5563; color: #F3F4F6; }
        .input-field:focus { border-color: #60A5FA; ring-color: #60A5FA; outline: none; box-shadow: 0 0 0 2px #60A5FA; }
        .btn-action { background-color: #2563EB; color: white; } /* blue-600 */
        .btn-action:hover { background-color: #1D4ED8; } /* blue-700 */
    </style>
</head>
<body class="antialiased">
    <header class="header-bg text-gray-300 shadow-lg sticky top-0 z-50">
        <div class="nav container mx-auto flex items-center justify-between p-4">
            <a href="/admin_interface/login.php" class="logo text-2xl font-bold text-white">Muscle<span class="text-yellow-400">Mafia</span> <span class="text-sm text-gray-400">Admin</span></a>
            <div class="text-xs md:text-sm">Logged in as Admin: <span class="font-semibold text-white"><?php echo htmlspecialchars($loggedInAdminName); ?></span></div>
            <div class="nav-icons flex items-center space-x-3 md:space-x-4">
                <a href="/admin_interface/admin_panel.php" class="hover:text-white transition-colors text-sm" title="Admin Dashboard"><i class="fas fa-tachometer-alt"></i><span class="hidden md:inline ml-1">Dashboard</span></a>
                <a href="/login.php?logout=true" class="hover:text-white transition-colors text-sm" title="Log out"><i class="fas fa-sign-out-alt"></i><span class="hidden md:inline ml-1">Logout</span></a>
            </div>
        </div>
    </header>

    <main class="container mx-auto py-8 px-4 max-w-3xl">
        <h1 class="text-3xl font-bold mb-4 text-center text-gray-100">Edit Admin Profile</h1>
        <p class="text-center text-gray-400 mb-8">Editing profile for: <strong class="text-white"><?php echo $current_username ?: 'N/A'; ?></strong> (ID: <?php echo $user_id_to_edit; ?>)</p>

        <?php if (!empty($feedback_messages)): ?>
            <?php foreach ($feedback_messages as $msg): ?>
                <div class="feedback-message <?php echo htmlspecialchars($msg['type']); ?>" role="alert">
                    <?php echo htmlspecialchars($msg['text']); ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($current_username)): // Only show forms if user was found ?>
        <div class="bg-gray-800 p-6 md:p-8 rounded-lg shadow-xl mb-10 border border-gray-700">
            <h2 class="text-2xl font-semibold mb-6 text-white">User Information</h2>
            <form action="update_admin.php?edit_user_id=<?php echo $user_id_to_edit; ?>" method="POST">
                <input type="hidden" name="user_id_for_update" value="<?php echo $user_id_to_edit; ?>">
                <div class="mb-5">
                    <label for="username" class="block mb-2 text-sm font-medium text-gray-300">Username</label>
                    <input type="text" id="username" name="username"
                           class="w-full px-4 py-3 input-field rounded-lg focus:ring-2 focus:ring-sky-500 focus:border-transparent outline-none transition-shadow duration-300"
                           value="<?php echo $current_username; ?>" required minlength="3">
                </div>
                <div class="mb-5">
                    <label for="email" class="block mb-2 text-sm font-medium text-gray-300">Email Address</label>
                    <input type="email" id="email" name="email"
                           class="w-full px-4 py-3 input-field rounded-lg focus:ring-2 focus:ring-sky-500 focus:border-transparent outline-none transition-shadow duration-300"
                           value="<?php echo $current_email; ?>" required>
                </div>

                <button type="submit" name="submit_profile_update"
                        class="w-full btn-action text-white font-semibold py-3 px-6 rounded-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-800 focus:ring-sky-500 transition-all duration-300 ease-in-out transform hover:scale-105 active:scale-95">
                    Save Profile Changes
                </button>
            </form>
        </div>

        <div class="bg-gray-800 p-6 md:p-8 rounded-lg shadow-xl border border-gray-700">
            <h2 class="text-2xl font-semibold mb-6 text-white">Set New Password for User</h2>
            <form action="update_admin.php?edit_user_id=<?php echo $user_id_to_edit; ?>" method="POST" id="changePasswordFormAdmin">
                 <input type="hidden" name="user_id_for_password" value="<?php echo $user_id_to_edit; ?>">
                <div class="mb-5">
                    <label for="new_password_admin" class="block mb-2 text-sm font-medium text-gray-300">New Password</label>
                    <input type="password" id="new_password_admin" name="new_password_admin"
                           class="w-full px-4 py-3 input-field rounded-lg focus:ring-2 focus:ring-sky-500 focus:border-transparent outline-none transition-shadow duration-300"
                           placeholder="Min. 8 characters" required minlength="8">
                </div>
                <div class="mb-6">
                    <label for="confirm_new_password_admin" class="block mb-2 text-sm font-medium text-gray-300">Confirm New Password</label>
                    <input type="password" id="confirm_new_password_admin" name="confirm_new_password_admin"
                           class="w-full px-4 py-3 input-field rounded-lg focus:ring-2 focus:ring-sky-500 focus:border-transparent outline-none transition-shadow duration-300"
                           required>
                    <p id="passwordMatchErrorAdmin" class="text-red-400 text-xs mt-1 hidden">New passwords do not match.</p>
                </div>
                <button type="submit" name="submit_password_update"
                        class="w-full btn-action text-white font-semibold py-3 px-6 rounded-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-800 focus:ring-sky-500 transition-all duration-300 ease-in-out transform hover:scale-105 active:scale-95">
                    Set New Password
                </button>
            </form>
        </div>
        <?php else: ?>
             <p class="text-center text-xl text-red-400">User could not be loaded for editing.</p>
        <?php endif; ?>
    </main>

    <footer class="footer-bg text-gray-400 text-center p-6 mt-12 border-t border-gray-700">
        <p>&copy; <?php echo date("Y"); ?> MuscleMafia. Admin Panel.</p>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const changePasswordFormAdmin = document.getElementById('changePasswordFormAdmin');
            if (changePasswordFormAdmin) {
                const newPasswordField = document.getElementById('new_password_admin');
                const confirmNewPasswordField = document.getElementById('confirm_new_password_admin');
                const passwordMatchError = document.getElementById('passwordMatchErrorAdmin');

                function validatePasswordMatchAdmin() {
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

                if(newPasswordField) newPasswordField.addEventListener('input', validatePasswordMatchAdmin);
                if(confirmNewPasswordField) confirmNewPasswordField.addEventListener('input', validatePasswordMatchAdmin);

                changePasswordFormAdmin.addEventListener('submit', function(event) {
                    if (newPasswordField.value !== confirmNewPasswordField.value) {
                        event.preventDefault(); 
                        validatePasswordMatchAdmin(); 
                        if(confirmNewPasswordField) confirmNewPasswordField.focus();
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
