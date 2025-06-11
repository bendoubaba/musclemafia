<?php
// code to delete all cookies + all temp site file
session_start();

// Clear all session variables
$_SESSION = [];

// Destroy session file
session_destroy();

// Delete all cookies set by your site
if (isset($_SERVER['HTTP_COOKIE'])) {
    $cookies = explode(';', $_SERVER['HTTP_COOKIE']);
    foreach ($cookies as $cookie) {
        $parts = explode('=', $cookie);
        $name = trim($parts[0]);
        setcookie($name, '', time() - 3600, '/');
        setcookie($name, '', time() - 3600, '/', $_SERVER['HTTP_HOST'], true, true);
    }
}
?>

<?php
session_start();
include('database.php'); // Ensure this file establishes a mysqli connection as $conn

$error_login = [];
$error_signup = [];

// --- SIGNUP LOGIC ---
if (isset($_POST['submit_signup'])) {
    $username = trim($_POST['username_signup']); // Changed from 'name'
    $email = trim($_POST['email_signup']);
    $password = $_POST['password_signup'];
    $confirm_password = $_POST['confirm_password_signup'];

    // Basic Validations
    if (empty($username)) {
        $error_signup[] = 'Username is required.';
    }
    if (empty($email)) {
        $error_signup[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_signup[] = 'Invalid email format.';
    }
    if (empty($password)) {
        $error_signup[] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $error_signup[] = 'Password must be at least 8 characters long.';
    }
    if ($password !== $confirm_password) {
        $error_signup[] = 'Passwords do not match.';
    }

    if (empty($error_signup)) {
        // Check if email or username already exists using prepared statements
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
        if ($stmt_check) {
            $stmt_check->bind_param("ss", $email, $username);
            $stmt_check->execute();
            $stmt_check->store_result();

            if ($stmt_check->num_rows > 0) {
                $error_signup[] = 'Email or Username already exists!';
            } else {
                // Hash the password securely
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                $stmt_insert = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'user')");
                if ($stmt_insert) {
                    $stmt_insert->bind_param("sss", $username, $email, $hashed_password);
                    if ($stmt_insert->execute()) {
                        // Optionally, log the user in directly or redirect to login with a success message
                        // For now, redirecting to login page (which is the current page)
                        // You might want to add a success message parameter to the URL
                        header('Location: login.php?signup=success');
                        exit();
                    } else {
                        $error_signup[] = 'Registration failed. Please try again. Error: ' . $stmt_insert->error;
                    }
                    $stmt_insert->close();
                } else {
                    $error_signup[] = 'Database error (insert prepare). Please try again. ' . $conn->error;
                }
            }
            $stmt_check->close();
        } else {
            $error_signup[] = 'Database error (check prepare). Please try again. ' . $conn->error;
        }
    }
}

// --- LOGIN LOGIC ---
if (isset($_POST['submit_login'])) {
    $email_login = trim($_POST['email_login']);
    $password_login = $_POST['password_login'];

    if (empty($email_login) || empty($password_login)) {
        $error_login[] = 'Email and Password are required.';
    } else {
        // Check admins table first
        $stmt_admin = $conn->prepare("SELECT id, username, password FROM admins WHERE email = ?");
        if ($stmt_admin) {
            $stmt_admin->bind_param("s", $email_login);
            $stmt_admin->execute();
            $result_admin = $stmt_admin->get_result();

            if ($result_admin->num_rows > 0) {
                $row_admin = $result_admin->fetch_assoc();
                // Verify admin password (assuming admin passwords are also hashed with password_hash)
                // If admin passwords are MD5 or plain, this needs to change, but that's insecure.
                if ($password_login == $row_admin['password']) {
                    $_SESSION['admin_name'] = $row_admin['username'];
                    $_SESSION['admin_id'] = $row_admin['id']; // Store admin ID
                    $_SESSION['user_role'] = 'admin';
                    header('Location: /admin_interface/admin_panel.php?adminid=' . $row_admin['id']);
                    exit();
                }
            }
            $stmt_admin->close();
        } else {
            $error_login[] = 'Database error (admin check). Please try again. ' . $conn->error;
        }

        // If not an admin or admin password incorrect, check users table
        $stmt_user = $conn->prepare("SELECT id, username, password, role FROM users WHERE email = ?");
        if ($stmt_user) {
            $stmt_user->bind_param("s", $email_login);
            $stmt_user->execute();
            $result_user = $stmt_user->get_result();

            if ($result_user->num_rows > 0) {
                $row_user = $result_user->fetch_assoc();
                if (password_verify($password_login, $row_user['password'])) {
                    $_SESSION['user_id'] = $row_user['id']; // Store user ID
                    $_SESSION['user_name'] = $row_user['username']; // Store username
                    $_SESSION['user_role'] = $row_user['role'];
                    header('Location: /user_interface/index_user.php?user_id=' . $row_user['id']);
                    exit();
                }
            }
            $stmt_user->close();
        } else {
            $error_login[] = 'Database error (user check). Please try again. ' . $conn->error;
        }

        // If no user found or password incorrect after checking both tables
        $error_login[] = 'Incorrect email or password!';
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
    <title>Gym Store - Account</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        .form-transition {
            transition: opacity 0.5s ease-in-out, transform 0.5s ease-in-out;
        }

        .form-hidden {
            opacity: 0;
            transform: translateY(20px);
            pointer-events: none;
            position: absolute;
            width: 100%;
        }

        .form-visible {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
            position: relative;
        }

        .error-message-container {
            background-color: #fef2f2;
            /* red-50 */
            border: 1px solid #fecaca;
            /* red-200 */
            color: #b91c1c;
            /* red-700 */
            padding: 0.75rem 1rem;
            border-radius: 0.375rem;
            /* rounded-md */
            margin-bottom: 1.5rem;
            /* mb-6 */
            font-size: 0.875rem;
            /* text-sm */
        }

        .error-message-container ul {
            list-style-type: disc;
            margin-left: 1.25rem;
        }
    </style>
</head>

<body class="bg-zinc-900 flex items-center justify-center min-h-screen p-4 selection:bg-black selection:text-white">
    <div class="bg-white text-black p-8 md:p-12 rounded-xl shadow-2xl w-full max-w-md relative overflow-hidden">

        <div class="flex border-b border-zinc-300 mb-8">
            <button id="showLogin"
                class="group flex-1 py-3 px-2 text-center font-semibold text-sm sm:text-base focus:outline-none transition-colors duration-300 hover:bg-zinc-100 text-zinc-600 data-[active=true]:text-black"
                data-active="true">
                <span
                    class="inline-block pb-1 group-data-[active=true]:border-b-2 group-data-[active=true]:border-black">Login
                    to Your Power Hub</span>
            </button>
            <button id="showSignup"
                class="group flex-1 py-3 px-2 text-center font-semibold text-sm sm:text-base focus:outline-none transition-colors duration-300 hover:bg-zinc-100 text-zinc-600 data-[active=true]:text-black"
                data-active="false">
                <span
                    class="inline-block pb-1 group-data-[active=true]:border-b-2 group-data-[active=true]:border-black">Join
                    the Fit Squad</span>
            </button>
        </div>

        <div id="loginForm"
            class="form-transition <?php echo (isset($_POST['submit_signup']) && !empty($error_signup)) ? 'form-hidden' : 'form-visible'; ?>">
            <?php if (!empty($error_login)): ?>
                <div class="error-message-container">
                    <ul>
                        <?php foreach ($error_login as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['signup']) && $_GET['signup'] == 'success'): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4"
                    role="alert">
                    <strong class="font-bold">Success!</strong>
                    <span class="block sm:inline"> Registration successful. Please log in.</span>
                </div>
            <?php endif; ?>

            <h2 class="text-3xl font-bold mb-2 text-center">Welcome Back, Champion!</h2>
            <p class="text-zinc-600 mb-8 text-center text-sm">Access your account and continue your fitness journey.</p>

            <form action="login.php" method="POST">
                <div class="mb-5">
                    <label for="login-email" class="block mb-2 text-sm font-medium text-zinc-700">Email Address</label>
                    <input type="email" id="login-email" name="email_login"
                        class="w-full px-4 py-3 border border-zinc-300 rounded-lg focus:ring-2 focus:ring-black focus:border-transparent outline-none transition-shadow duration-300"
                        placeholder="you@example.com" required
                        value="<?php echo isset($_POST['email_login']) ? htmlspecialchars($_POST['email_login']) : ''; ?>">
                </div>
                <div class="mb-6">
                    <label for="login-password" class="block mb-2 text-sm font-medium text-zinc-700">Password</label>
                    <input type="password" id="login-password" name="password_login"
                        class="w-full px-4 py-3 border border-zinc-300 rounded-lg focus:ring-2 focus:ring-black focus:border-transparent outline-none transition-shadow duration-300"
                        placeholder="••••••••" required>
                </div>
                <button type="submit" name="submit_login"
                    class="w-full bg-black text-white font-semibold py-3 px-6 rounded-lg hover:bg-zinc-800 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-black transition-all duration-300 ease-in-out transform hover:scale-105 active:scale-95">
                    Log In & Lift Off
                </button>
            </form>
            <p class="text-center text-sm text-zinc-600 mt-8">
                New to the squad? <button onclick="toggleForms('signup')"
                    class="font-semibold text-black hover:underline">Sign Up Here</button>
            </p>
            <p class="text-center text-sm text-zinc-600 mt-8">
                New to the squad came  <a href="/index.php"
                    class="font-semibold text-black hover:underline">see home  Here</a>
            </p>
        </div>

        <div id="signupForm"
            class="form-transition <?php echo (isset($_POST['submit_signup']) && !empty($error_signup)) ? 'form-visible' : 'form-hidden'; ?>">
            <?php if (!empty($error_signup)): ?>
                <div class="error-message-container">
                    <ul>
                        <?php foreach ($error_signup as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <h2 class="text-3xl font-bold mb-2 text-center">Create Your Power Account</h2>
            <p class="text-zinc-600 mb-8 text-center text-sm">Sign up to unlock exclusive gear and track your gains.</p>

            <form action="login.php" method="POST" id="htmlSignupForm">
                <div class="mb-5">
                    <label for="signup-username" class="block mb-2 text-sm font-medium text-zinc-700">Username</label>
                    <input type="text" id="signup-username" name="username_signup"
                        class="w-full px-4 py-3 border border-zinc-300 rounded-lg focus:ring-2 focus:ring-black focus:border-transparent outline-none transition-shadow duration-300"
                        placeholder="Your Username" required
                        value="<?php echo isset($_POST['username_signup']) ? htmlspecialchars($_POST['username_signup']) : ''; ?>">
                </div>
                <div class="mb-5">
                    <label for="signup-email" class="block mb-2 text-sm font-medium text-zinc-700">Email Address</label>
                    <input type="email" id="signup-email" name="email_signup"
                        class="w-full px-4 py-3 border border-zinc-300 rounded-lg focus:ring-2 focus:ring-black focus:border-transparent outline-none transition-shadow duration-300"
                        placeholder="you@example.com" required
                        value="<?php echo isset($_POST['email_signup']) ? htmlspecialchars($_POST['email_signup']) : ''; ?>">
                </div>
                <div class="mb-6">
                    <label for="signup-password" class="block mb-2 text-sm font-medium text-zinc-700">Create
                        Password</label>
                    <input type="password" id="signup-password" name="password_signup"
                        class="w-full px-4 py-3 border border-zinc-300 rounded-lg focus:ring-2 focus:ring-black focus:border-transparent outline-none transition-shadow duration-300"
                        placeholder="•••••••• (Min. 8 characters)" required minlength="8">
                </div>
                <div class="mb-6">
                    <label for="signup-confirm-password" class="block mb-2 text-sm font-medium text-zinc-700">Confirm
                        Password</label>
                    <input type="password" id="signup-confirm-password" name="confirm_password_signup"
                        class="w-full px-4 py-3 border border-zinc-300 rounded-lg focus:ring-2 focus:ring-black focus:border-transparent outline-none transition-shadow duration-300"
                        placeholder="••••••••" required>
                </div>
                <button type="submit" name="submit_signup"
                    class="w-full bg-black text-white font-semibold py-3 px-6 rounded-lg hover:bg-zinc-800 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-black transition-all duration-300 ease-in-out transform hover:scale-105 active:scale-95">
                    Sign Up & Get Strong
                </button>
            </form>
            <p class="text-center text-sm text-zinc-600 mt-8">
                Already have an account? <button onclick="toggleForms('login')"
                    class="font-semibold text-black hover:underline">Log In Here</button>
            </p>
        </div>

        <div class="mt-10 text-center text-xs text-zinc-500">
            <p>&copy; <span id="currentYear"></span> Gym Store Inc. Fuel Your Passion.</p>
            <p>Your one-stop shop for premium fitness gear and supplements.</p>
        </div>
    </div>

    <script>
        const loginFormDiv = document.getElementById('loginForm');
        const signupFormDiv = document.getElementById('signupForm');
        const showLoginBtn = document.getElementById('showLogin');
        const showSignupBtn = document.getElementById('showSignup');

        function toggleForms(formToShow) {
            const isLoginActive = loginFormDiv.classList.contains('form-visible');
            const isSignupActive = signupFormDiv.classList.contains('form-visible');

            if (formToShow === 'login' && !isLoginActive) {
                loginFormDiv.classList.remove('form-hidden');
                loginFormDiv.classList.add('form-visible');
                signupFormDiv.classList.add('form-hidden');
                signupFormDiv.classList.remove('form-visible');
                showLoginBtn.setAttribute('data-active', 'true');
                showSignupBtn.setAttribute('data-active', 'false');
                setTimeout(() => { loginFormDiv.querySelector('input[type="email"]').focus(); }, 50);
            } else if (formToShow === 'signup' && !isSignupActive) {
                signupFormDiv.classList.remove('form-hidden');
                signupFormDiv.classList.add('form-visible');
                loginFormDiv.classList.add('form-hidden');
                loginFormDiv.classList.remove('form-visible');
                showSignupBtn.setAttribute('data-active', 'true');
                showLoginBtn.setAttribute('data-active', 'false');
                setTimeout(() => { signupFormDiv.querySelector('input[type="text"]').focus(); }, 50);
            }
        }

        showLoginBtn.addEventListener('click', () => toggleForms('login'));
        showSignupBtn.addEventListener('click', () => toggleForms('signup'));

        document.getElementById('currentYear').textContent = new Date().getFullYear();

        // Client-side password match validation for signup form
        const htmlSignupForm = document.getElementById('htmlSignupForm');
        if (htmlSignupForm) {
            htmlSignupForm.addEventListener('submit', function (event) {
                const passwordInput = this.querySelector('#signup-password');
                const confirmPasswordInput = this.querySelector('#signup-confirm-password');

                let existingErrorMsg = confirmPasswordInput.parentNode.querySelector('.password-mismatch-error');
                if (existingErrorMsg) existingErrorMsg.remove();
                confirmPasswordInput.classList.remove('border-red-500', 'focus:ring-red-500');
                confirmPasswordInput.removeAttribute('aria-invalid');
                confirmPasswordInput.removeAttribute('aria-describedby');

                if (passwordInput.value !== confirmPasswordInput.value) {
                    event.preventDefault(); // Stop form submission

                    confirmPasswordInput.classList.add('border-red-500', 'focus:ring-red-500');
                    confirmPasswordInput.setAttribute('aria-invalid', 'true');
                    const errorMsgElement = document.createElement('p');
                    errorMsgElement.id = 'passwordMismatchError';
                    errorMsgElement.className = 'text-red-500 text-xs mt-1 password-mismatch-error';
                    errorMsgElement.textContent = 'Passwords do not match.';
                    confirmPasswordInput.parentNode.insertBefore(errorMsgElement, confirmPasswordInput.nextSibling);
                    confirmPasswordInput.setAttribute('aria-describedby', 'passwordMismatchError');
                    confirmPasswordInput.focus();
                }
            });
        }

        // Check if PHP errors for signup exist, then switch to signup form
        <?php if (isset($_POST['submit_signup']) && !empty($error_signup)): ?>
            toggleForms('signup');
        <?php elseif (isset($_GET['signup']) && $_GET['signup'] == 'success'): ?>
            // If signup was successful, ensure login form is shown by default
            toggleForms('login'); // Or just let default behavior handle it.
        <?php else: ?>
            // Default to login form or maintain current state if no specific action
            // This ensures that if login fails, the login form remains visible.
            // If the page is loaded fresh, login is visible by default.
            if (!loginFormDiv.classList.contains('form-visible') && !signupFormDiv.classList.contains('form-visible')) {
                toggleForms('login'); // Default to login if neither is visible (e.g. initial load)
            } else if (loginFormDiv.classList.contains('form-hidden') && signupFormDiv.classList.contains('form-hidden')) {
                toggleForms('login'); // If somehow both are hidden, default to login
            }
        <?php endif; ?>

        // Initial focus if no specific form was toggled by PHP errors
        if (loginFormDiv.classList.contains('form-visible')) {
            setTimeout(() => { loginFormDiv.querySelector('input[type="email"]').focus(); }, 0);
        } else if (signupFormDiv.classList.contains('form-visible')) {
            setTimeout(() => { signupFormDiv.querySelector('input[type="text"]').focus(); }, 0);
        }

    </script>
</body>

</html>