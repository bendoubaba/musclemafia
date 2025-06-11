-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 27, 2025 at 07:26 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `storesport`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'admin'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `username`, `password`, `email`, `role`) VALUES
(1, 'admin', 'UPDATE users', '9@gmail.com', 'admin');

-- --------------------------------------------------------

--
-- Table structure for table `cart`
--

CREATE TABLE `cart` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `ico_img` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `ico_img`) VALUES
(1, 'Exercise bikes', 'https://m.media-amazon.com/images/I/71PYD65+psL._AC_SL1500_.jpg'),
(3, 'fldkjhk', '/upload/categories/category_ico_68324ea423c533.38642624.png');

-- --------------------------------------------------------

--
-- Table structure for table `factures`
--

CREATE TABLE `factures` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `cart_id` int(11) DEFAULT NULL,
  `total_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `shipping_address` text DEFAULT NULL,
  `phone_number` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `factures`
--

INSERT INTO `factures` (`id`, `user_id`, `cart_id`, `total_price`, `shipping_address`, `phone_number`, `created_at`) VALUES
(1, 2, NULL, 2239.00, NULL, NULL, '2025-05-26 21:34:38'),
(2, 2, NULL, 897.00, NULL, NULL, '2025-05-26 21:37:31'),
(9, 2, NULL, 534.00, NULL, NULL, '2025-05-26 21:45:37'),
(11, 2, NULL, 897.00, '456 Iron Pump Blvd, Apt 12C, Flex Town, CA 90210', '(415) 987-6543', '2025-05-26 22:00:18'),
(12, 1, NULL, 5382.00, '456 Iron Pump Blvd, Apt 12C, Flex Town, CA 90210', '(415) 987-6543', '2025-05-26 22:04:30'),
(13, 1, NULL, 2691.00, '456 Iron Pump Blvd, Apt 12C, Flex Town, CA 90210', '(415) 987-6543', '2025-05-26 22:06:21'),
(14, 1, NULL, 11017.00, '456 Iron Pump Blvd, Apt 12C, Flex Town, CA 90210', '0792272086', '2025-05-26 22:21:58'),
(15, 3, NULL, 2773.00, '456 Iron Pump Blvd, Apt 12C, Flex Town, CA 90210', '0792272086', '2025-05-26 22:25:08'),
(16, 3, NULL, 534.00, 'G7bKJ8-QvMW2B-rcNLDb', '(415) 987-6543', '2025-05-26 23:50:00'),
(17, 3, NULL, 267.00, '179 Manor Drive, Hamillchester, Michigan', '+1 (983) 682-0257', '2025-05-27 09:33:11'),
(18, 3, NULL, 89.00, '179 Manor Drive, Hamillchester, Michigan', '(415) 987-6543', '2025-05-27 09:35:25');

-- --------------------------------------------------------

--
-- Table structure for table `facture_items`
--

CREATE TABLE `facture_items` (
  `id` int(11) NOT NULL,
  `facture_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `facture_items`
--

INSERT INTO `facture_items` (`id`, `facture_id`, `product_id`, `quantity`, `price`) VALUES
(1, 1, 1, 5, 89.00),
(2, 1, 3, 2, 897.00),
(3, 2, 3, 1, 897.00),
(4, 9, 1, 6, 89.00),
(5, 11, 3, 1, 897.00),
(6, 12, 3, 6, 897.00),
(7, 13, 3, 3, 897.00),
(8, 14, 1, 23, 89.00),
(9, 14, 3, 10, 897.00),
(10, 15, 1, 11, 89.00),
(11, 15, 3, 2, 897.00),
(12, 16, 1, 6, 89.00),
(13, 17, 1, 3, 89.00),
(14, 18, 1, 1, 89.00);

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `edit` int(3) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `file_path` text NOT NULL DEFAULT '',
  `typesender` varchar(300) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=unread, 1=read'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`id`, `sender_id`, `receiver_id`, `message`, `edit`, `created_at`, `file_path`, `typesender`, `is_read`) VALUES
(24, 1, 3, 'hi how are you bro i want you get me help in this error , i need it urglly', 0, '2025-05-27 12:50:54', '/upload/chat_files/category_ico_68324ea423c53338642624_6835b52e5b37c6.35724305.png', 'admin', 1),
(25, 1, 2, 'hello how are you', 1, '2025-05-27 13:12:30', '', 'admin', 1),
(26, 1, 2, 'you won 350 steam account enjoy bro', 1, '2025-05-27 13:47:26', '/upload/chat_files/350xSteamFullCAPTURE_6835c26e1adcb4.84011661.txt', 'admin', 1),
(27, 3, 1, 'this privite shit don\'t shear it', 1, '2025-05-27 14:26:18', '/upload/chat_files/private_userchat_6835cb8ac5c531.40915950.txt', 'user', 1),
(28, 3, 1, 'good bye btw i like your website negga', 1, '2025-05-27 14:28:00', '', 'user', 1),
(29, 3, 1, 'let play somtihg else', 1, '2025-05-27 14:28:15', '', 'user', 1),
(30, 1, 3, 'ok', 1, '2025-05-27 14:28:56', '', 'admin', 1),
(31, 3, 1, 'thanks betwwen', 1, '2025-05-27 14:29:13', '', 'user', 1),
(32, 1, 3, 'okaynigga', 1, '2025-05-27 14:31:52', '', 'admin', 1),
(34, 1, 3, 'ko', 1, '2025-05-27 14:35:13', '', 'admin', 1),
(36, 1, 3, 'kl;', 1, '2025-05-27 14:43:46', '', 'admin', 1),
(37, 1, 3, 'hi', 1, '2025-05-27 14:54:08', '', 'admin', 1),
(38, 3, 1, 'hi', 1, '2025-05-27 14:55:03', '', 'user', 1),
(40, 3, 1, 'lk', 1, '2025-05-27 14:55:35', '', 'user', 1),
(41, 3, 1, 'l', 1, '2025-05-27 14:55:44', '', 'user', 1),
(42, 3, 1, 'klk', 1, '2025-05-27 14:55:56', '', 'user', 1),
(43, 1, 3, 'ji', 1, '2025-05-27 14:56:11', '', 'admin', 1),
(44, 3, 1, 'kl', 1, '2025-05-27 14:56:25', '', 'user', 1),
(45, 1, 3, ';l', 1, '2025-05-27 14:57:29', '', 'admin', 1),
(46, 3, 1, 'chatgpt hhh', 1, '2025-05-27 14:58:03', '/upload/chat_files/ChatGPTImageApr4202510_07_16PM_userchat_6835d2fb25adf9.67668647.png', 'user', 1),
(48, 1, 3, 'klkklkl', 1, '2025-05-27 15:15:07', '', 'admin', 0),
(49, 1, 3, 'kl;;l', 1, '2025-05-27 15:15:15', '', 'admin', 0);

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `cart_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `product` varchar(255) NOT NULL,
  `category_id` int(11) NOT NULL,
  `picture` varchar(500) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `count` int(11) DEFAULT 0,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `product`, `category_id`, `picture`, `description`, `count`, `price`) VALUES
(1, 'YOSUDA Indoor Cycling Bike Brake Pad/Magnetic Stationary Bike - Cycle Bike with Tablet Mount & Comfortable Seat Cushion', 1, 'https://m.media-amazon.com/images/I/71vmEikTWdL._AC_SL1500_.jpg', 'About this item\r\nChoose YOSUDA: No buying worries: Designed and produce top-quality exercise machines for home use for over 20 years. YOSUDA has been chosen by more than 3,000,000 families and we will always stand behind our product\r\nSmooth Stationery Bike: 30 lbs flywheel and heavy-duty steel frame of the exercise bike guarantee stability while cycling. The belt-driven system provides a smoother and quieter ride than chain transport. It won\'t disturb your sleeping kids or neighbors\r\nSafe to Use: Designed to be sturdy and stable, even during intense workouts, YOSUDA exercise bike will provide a safe and secure exercise experience. Maximum weight capacity can be up to 300 lbs\r\nPersonalized Fit Exercise Bike: 2-way adjustable handlebar, 4-way padded seat. This cycling bike is suitable for users from 4\'8\'\' to 6\'1\'\'. A large range of resistance gives users a comfortable indoor riding experience. Suitable for all family members\r\nLCD Monitor and Tablet Mount: The LCD monitor on the exercise bike tracks your pulse, time, speed, distance, calories burned, and odometer. The gift Tablet holder allows you to enjoy riding and music at the same time, making it easier to keep your workout\r\nWhat You Get: A YOSUDA exercise bike, all tools and instructions are in the package. Online instruction videos can help you complete the assembly within 30 minutes. ONE-YEAR-FREE parts replacement\r\nUser-Friendly Design: The adjustable cage pedals protect you from a fast ride. Press the resistance bar to stop the flywheel immediately. The water bottle holder allows you to replenish water in time. Transport wheels help you easily move this cycle bike', 50090, 89.00),
(3, 'hlhkhkln,nmnm', 3, '/upload/products/product_image_6835a3e2141de9.59484739.png', 'kljkkllkjjlk', 5000, 897.00);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'user',
  `admin_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `role`, `admin_id`) VALUES
(1, 'gamerkillrer', 'confirmation', 'qn-unconscionable@hidmail.org', 'user', NULL),
(2, 'ys-belch_mocking', '$2y$10$ETUN8sdUIidY6tGhls24eONjtuBFO68i5/ikfgzEGaZBjxF6D1VZm', 'ys-belch_mocking@hidmail.org', 'user', NULL),
(3, 'Dodziricks@gmail.com', '$2y$10$tSDrdjNAhYCI1CP5awaHJuFSes5hE4f30ZlfRJBLY3Aw1gmo1q9JW', 'Dodziricks@gmail.com', 'user', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `factures`
--
ALTER TABLE `factures`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `factures_ibfk_2` (`cart_id`);

--
-- Indexes for table `facture_items`
--
ALTER TABLE `facture_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `facture_id` (`facture_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `cart_id` (`cart_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `cart`
--
ALTER TABLE `cart`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `factures`
--
ALTER TABLE `factures`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `facture_items`
--
ALTER TABLE `facture_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cart`
--
ALTER TABLE `cart`
  ADD CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `factures`
--
ALTER TABLE `factures`
  ADD CONSTRAINT `factures_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `factures_ibfk_2` FOREIGN KEY (`cart_id`) REFERENCES `cart` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `facture_items`
--
ALTER TABLE `facture_items`
  ADD CONSTRAINT `facture_items_ibfk_1` FOREIGN KEY (`facture_id`) REFERENCES `factures` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `facture_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`cart_id`) REFERENCES `cart` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `orders_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
