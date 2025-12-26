<?php
session_start();
require_once 'config.php';

function saveToJsonFile($filename, $data) {
    $filepath = __DIR__ . '/data/' . $filename;
    
    // Ensure data directory exists
    $dir = dirname($filepath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    // Create empty array if file doesn't exist
    if (!file_exists($filepath)) {
        file_put_contents($filepath, '[]');
    }
    
    // Read existing data
    $existingData = [];
    if (filesize($filepath) > 0) {
        $existingData = json_decode(file_get_contents($filepath), true);
        if (!is_array($existingData)) {
            $existingData = [];
        }
    }
    
    // Add new data
    $existingData[] = $data;
    
    // Save back to file
    file_put_contents($filepath, json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Initialize current user from session
$currentUser = isset($_SESSION['user']) ? $_SESSION['user'] : null;

// Initialize user preferences
if (!isset($_SESSION['preferences'])) {
    $_SESSION['preferences'] = [
        'font_size' => 'medium',
        'theme' => 'default',
        'language' => 'en'
    ];
}

// ADMIN CRUD FUNCTIONS START ================================================

// Admin credentials (hardcoded for this task)
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', 'password123');

// Check if admin is logged in
function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

// Admin login function
function adminLogin($username, $password) {
    if ($username === ADMIN_USERNAME && $password === ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        return true;
    }
    return false;
}

// Admin logout function
function adminLogout() {
    unset($_SESSION['admin_logged_in']);
    unset($_SESSION['admin_username']);
    session_destroy();
}

// Get all products from JSON file
function getProductsFromJson() {
    $filepath = __DIR__ . '/data/products.json';
    if (!file_exists($filepath)) {
        file_put_contents($filepath, '[]');
        return [];
    }
    
    $data = file_get_contents($filepath);
    $products = json_decode($data, true);
    return is_array($products) ? $products : [];
}

// Save products to JSON file
function saveProductsToJson($products) {
    $filepath = __DIR__ . '/data/products.json';
    file_put_contents($filepath, json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Add new product
function addProduct($productData) {
    $products = getProductsFromJson();
    
    // Generate new ID
    $maxId = 0;
    foreach ($products as $product) {
        if (isset($product['id']) && $product['id'] > $maxId) {
            $maxId = $product['id'];
        }
    }
    
    $productData['id'] = $maxId + 1;
    $products[] = $productData;
    
    saveProductsToJson($products);
    return $productData['id'];
}

// Update existing product
function updateProduct($id, $productData) {
    $products = getProductsFromJson();
    $updated = false;
    
    foreach ($products as &$product) {
        if ($product['id'] == $id) {
            $product = array_merge($product, $productData);
            $updated = true;
            break;
        }
    }
    
    if ($updated) {
        saveProductsToJson($products);
        return true;
    }
    return false;
}

// Delete product
function deleteProduct($id) {
    $products = getProductsFromJson();
    $newProducts = [];
    $deleted = false;
    
    foreach ($products as $product) {
        if ($product['id'] != $id) {
            $newProducts[] = $product;
        } else {
            $deleted = true;
        }
    }
    
    if ($deleted) {
        saveProductsToJson($newProducts);
        return true;
    }
    return false;
}

// ADMIN CRUD FUNCTIONS END ==================================================

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'login':
                $email = $_POST['email'];
                $password = $_POST['password'];
                
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($password, $user['password'])) {
                    $_SESSION['user'] = [
                        'id' => $user['id'],
                        'name' => $user['name'],
                        'email' => $user['email']
                    ];
                    echo json_encode(['success' => true, 'message' => 'Login successful']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
                }
                exit;
                
            case 'register':
                $name = $_POST['name'];
                $email = $_POST['email'];
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                
                try {
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $email, $password]);
                    
                    $_SESSION['user'] = [
                        'id' => $pdo->lastInsertId(),
                        'name' => $name,
                        'email' => $email
                    ];
                    echo json_encode(['success' => true, 'message' => 'Registration successful']);
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'message' => 'Email already exists']);
                }
                exit;
                
            case 'add_to_cart':
                if (!isset($_SESSION['user'])) {
                    echo json_encode(['success' => false, 'message' => 'Please login first']);
                    exit;
                }
                
                $product_id = $_POST['product_id'];
                $quantity = $_POST['quantity'] ?? 1;
                
                // Check if product exists
                $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch();
                
                if ($product) {
                    // Initialize cart if not exists
                    if (!isset($_SESSION['cart'])) {
                        $_SESSION['cart'] = [];
                    }
                    
                    // Add to cart session
                    if (isset($_SESSION['cart'][$product_id])) {
                        $_SESSION['cart'][$product_id]['quantity'] += $quantity;
                    } else {
                        $_SESSION['cart'][$product_id] = [
                            'id' => $product['id'],
                            'name' => $product['name'],
                            'price' => $product['price'],
                            'category' => $product['category'],
                            'image' => $product['image'],
                            'quantity' => $quantity
                        ];
                    }
                    echo json_encode(['success' => true, 'message' => 'Product added to cart']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Product not found']);
                }
                exit;
                
            case 'update_cart':
                if (!isset($_SESSION['user'])) {
                    echo json_encode(['success' => false, 'message' => 'Please login first']);
                    exit;
                }
                
                $product_id = $_POST['product_id'];
                $quantity = $_POST['quantity'];
                
                if ($quantity <= 0) {
                    unset($_SESSION['cart'][$product_id]);
                } else {
                    $_SESSION['cart'][$product_id]['quantity'] = $quantity;
                }
                echo json_encode(['success' => true]);
                exit;
                
            case 'remove_from_cart':
                if (!isset($_SESSION['user'])) {
                    echo json_encode(['success' => false, 'message' => 'Please login first']);
                    exit;
                }
                
                $product_id = $_POST['product_id'];
                unset($_SESSION['cart'][$product_id]);
                echo json_encode(['success' => true]);
                exit;
            
            case 'place_order':
                if (!isset($_SESSION['user'])) {
                    echo json_encode(['success' => false, 'message' => 'Please login first']);
                    exit;
                }
                
                if (empty($_SESSION['cart'])) {
                    echo json_encode(['success' => false, 'message' => 'Your cart is empty']);
                    exit;
                }
                
                try {
                    // Calculate order total
                    $subtotal = 0;
                    foreach ($_SESSION['cart'] as $item) {
                        $subtotal += $item['price'] * $item['quantity'];
                    }
                    
                    $shipping = $subtotal > 100 ? 0 : 15;
                    $tax = $subtotal * 0.08;
                    $total = $subtotal + $shipping + $tax;
                    
                    // Generate order number
                    $orderNumber = 'TP' . date('YmdHis') . rand(100, 999);
                    
                    // Save order to database
                    $pdo->beginTransaction();
                    
                    // Insert into orders table
                    $stmt = $pdo->prepare("INSERT INTO orders (user_id, total, status, shipping_address) VALUES (?, ?, ?, ?)");
                    $stmt->execute([
                        $_SESSION['user']['id'],
                        $total,
                        'pending',
                        '123 Main St, Anytown, USA'
                    ]);
                    
                    $orderId = $pdo->lastInsertId();
                    
                    // Insert order items
                    $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, price) VALUES (?, ?, ?, ?, ?)");
                    foreach ($_SESSION['cart'] as $item) {
                        $stmt->execute([
                            $orderId,
                            $item['id'],
                            $item['name'],
                            $item['quantity'],
                            $item['price']
                        ]);
                    }
                    
                    $pdo->commit();
                    
                    // Also save to JSON file for backup (optional)
                    $orderData = [
                        'order_number' => $orderNumber,
                        'user_id' => $_SESSION['user']['id'],
                        'user_name' => $_SESSION['user']['name'],
                        'user_email' => $_SESSION['user']['email'],
                        'items' => $_SESSION['cart'],
                        'subtotal' => $subtotal,
                        'shipping' => $shipping,
                        'tax' => $tax,
                        'total' => $total,
                        'status' => 'pending',
                        'order_date' => date('Y-m-d H:i:s'),
                        'shipping_address' => '123 Main St, Anytown, USA'
                    ];
                    
                    saveToJsonFile('orders.json', $orderData);
                    
                    // Clear cart
                    $_SESSION['cart'] = [];
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Order placed successfully! Your order number is: ' . $orderNumber,
                        'order_number' => $orderNumber
                    ]);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Failed to place order: ' . $e->getMessage()]);
                }
                exit;
                    
            case 'logout':
                session_destroy();
                echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
                exit;
                
            case 'create_forum_topic':
                if (!isset($_SESSION['user'])) {
                    echo json_encode(['success' => false, 'message' => 'Please login first']);
                    exit;
                }
                
                $title = $_POST['title'];
                $content = $_POST['content'];
                $category = $_POST['category'] ?? 'General';
                
                // Save forum topic to JSON file
                $topicData = [
                    'id' => uniqid(),
                    'user_id' => $_SESSION['user']['id'],
                    'user_name' => $_SESSION['user']['name'],
                    'title' => $title,
                    'content' => $content,
                    'category' => $category,
                    'created_at' => date('Y-m-d H:i:s'),
                    'views' => 0,
                    'replies' => 0,
                    'last_reply' => null
                ];
                
                saveToJsonFile('forum_topics.json', $topicData);
                echo json_encode(['success' => true, 'message' => 'Topic created successfully']);
                exit;
                
            case 'add_forum_reply':
                if (!isset($_SESSION['user'])) {
                    echo json_encode(['success' => false, 'message' => 'Please login first']);
                    exit;
                }
                
                $topic_id = $_POST['topic_id'];
                $content = $_POST['content'];
                
                // Read topics file
                $topicsFile = __DIR__ . '/data/forum_topics.json';
                if (!file_exists($topicsFile)) {
                    echo json_encode(['success' => false, 'message' => 'Topic not found']);
                    exit;
                }
                
                $topics = json_decode(file_get_contents($topicsFile), true);
                $topicFound = false;
                
                foreach ($topics as &$topic) {
                    if ($topic['id'] === $topic_id) {
                        // Add reply to topic
                        if (!isset($topic['replies'])) {
                            $topic['replies'] = [];
                        }
                        
                        $replyData = [
                            'id' => uniqid(),
                            'user_id' => $_SESSION['user']['id'],
                            'user_name' => $_SESSION['user']['name'],
                            'content' => $content,
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        
                        $topic['replies'][] = $replyData;
                        $topic['replies_count'] = count($topic['replies']);
                        $topic['last_reply'] = date('Y-m-d H:i:s');
                        $topicFound = true;
                        break;
                    }
                }
                
                if ($topicFound) {
                    file_put_contents($topicsFile, json_encode($topics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    echo json_encode(['success' => true, 'message' => 'Reply added successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Topic not found']);
                }
                exit;
                
            case 'admin_login':
                $username = $_POST['username'] ?? '';
                $password = $_POST['password'] ?? '';
                
                if (adminLogin($username, $password)) {
                    echo json_encode(['success' => true, 'message' => 'Admin login successful']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invalid admin credentials']);
                }
                exit;
                
            case 'admin_logout':
                adminLogout();
                echo json_encode(['success' => true, 'message' => 'Admin logged out successfully']);
                exit;
                
            case 'admin_add_product':
                if (!isAdminLoggedIn()) {
                    echo json_encode(['success' => false, 'message' => 'Admin authentication required']);
                    exit;
                }
                
                $productData = [
                    'name' => $_POST['name'] ?? '',
                    'description' => $_POST['description'] ?? '',
                    'price' => floatval($_POST['price'] ?? 0),
                    'category' => $_POST['category'] ?? 'Toilet',
                    'image' => $_POST['image'] ?? '',
                    'badge' => $_POST['badge'] ?? '',
                    'stock' => intval($_POST['stock'] ?? 0)
                ];
                
                // Validate required fields
                if (empty($productData['name']) || empty($productData['description']) || $productData['price'] <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Please fill all required fields']);
                    exit;
                }
                
                $id = addProduct($productData);
                echo json_encode(['success' => true, 'message' => 'Product added successfully', 'product_id' => $id]);
                exit;
                
            case 'admin_update_product':
                if (!isAdminLoggedIn()) {
                    echo json_encode(['success' => false, 'message' => 'Admin authentication required']);
                    exit;
                }
                
                $id = intval($_POST['id'] ?? 0);
                $productData = [
                    'name' => $_POST['name'] ?? '',
                    'description' => $_POST['description'] ?? '',
                    'price' => floatval($_POST['price'] ?? 0),
                    'category' => $_POST['category'] ?? 'Toilet',
                    'image' => $_POST['image'] ?? '',
                    'badge' => $_POST['badge'] ?? '',
                    'stock' => intval($_POST['stock'] ?? 0)
                ];
                
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
                    exit;
                }
                
                if (empty($productData['name']) || empty($productData['description']) || $productData['price'] <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Please fill all required fields']);
                    exit;
                }
                
                if (updateProduct($id, $productData)) {
                    echo json_encode(['success' => true, 'message' => 'Product updated successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Product not found']);
                }
                exit;
                
            case 'admin_delete_product':
                if (!isAdminLoggedIn()) {
                    echo json_encode(['success' => false, 'message' => 'Admin authentication required']);
                    exit;
                }
                
                $id = intval($_POST['id'] ?? 0);
                
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
                    exit;
                }
                
                if (deleteProduct($id)) {
                    echo json_encode(['success' => true, 'message' => 'Product deleted successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Product not found']);
                }
                exit;
                
            // Customization preferences
            case 'update_preferences':
                if (isset($_POST['font_size'])) {
                    $_SESSION['preferences']['font_size'] = $_POST['font_size'];
                }
                if (isset($_POST['theme'])) {
                    $_SESSION['preferences']['theme'] = $_POST['theme'];
                }
                echo json_encode(['success' => true, 'message' => 'Preferences updated']);
                exit;
                
            // Search functionality
            case 'search_products':
                $searchTerm = $_POST['search_term'] ?? '';
                
                $stmt = $pdo->prepare("SELECT * FROM products WHERE name LIKE ? OR description LIKE ? OR category LIKE ?");
                $searchParam = "%$searchTerm%";
                $stmt->execute([$searchParam, $searchParam, $searchParam]);
                $searchResults = $stmt->fetchAll();
                
                echo json_encode(['success' => true, 'results' => $searchResults]);
                exit;
        }
    }
}

// Get current page
$currentPage = isset($_GET['page']) ? $_GET['page'] : 'home';

// Get products from database
if ($currentPage === 'products') {
    $stmt = $pdo->query("SELECT * FROM products ORDER BY id DESC");
    $products = $stmt->fetchAll();
} elseif ($currentPage === 'home') {
    // For home page, we don't need to load any products since Featured Products section is removed
    $products = [];
} elseif ($currentPage === 'admin_products') {
    // For admin products page, use JSON file
    $adminProducts = getProductsFromJson();
} elseif ($currentPage === 'forum') {
    // Load forum topics
    $topicsFile = __DIR__ . '/data/forum_topics.json';
    $forumTopics = [];
    
    if (file_exists($topicsFile)) {
        $forumTopics = json_decode(file_get_contents($topicsFile), true);
        if (!is_array($forumTopics)) {
            $forumTopics = [];
        }
    }
    
    // Sort topics by last reply date or creation date
    usort($forumTopics, function($a, $b) {
        $dateA = $a['last_reply'] ?? $a['created_at'];
        $dateB = $b['last_reply'] ?? $b['created_at'];
        return strtotime($dateB) - strtotime($dateA);
    });
}

// Calculate cart total
$cart_total = 0;
$cart_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_total += $item['price'] * $item['quantity'];
        $cart_count += $item['quantity'];
    }
}

// Contact list data
$contacts = [
    ['last_name' => 'Smith', 'first_name' => 'James', 'email' => 'james.smith@example.com'],
    ['last_name' => 'Johnson', 'first_name' => 'Emma', 'email' => 'emma.johnson@example.com'],
    ['last_name' => 'Williams', 'first_name' => 'Michael', 'email' => 'michael.williams@example.com'],
    ['last_name' => 'Brown', 'first_name' => 'Olivia', 'email' => 'olivia.brown@example.com'],
    ['last_name' => 'Jones', 'first_name' => 'William', 'email' => 'william.jones@example.com'],
    ['last_name' => 'Garcia', 'first_name' => 'Sophia', 'email' => 'sophia.garcia@example.com'],
    ['last_name' => 'Miller', 'first_name' => 'Benjamin', 'email' => 'benjamin.miller@example.com'],
    ['last_name' => 'Davis', 'first_name' => 'Ava', 'email' => 'ava.davis@example.com'],
    ['last_name' => 'Rodriguez', 'first_name' => 'Liam', 'email' => 'liam.rodriguez@example.com'],
    ['last_name' => 'Martinez', 'first_name' => 'Isabella', 'email' => 'isabella.martinez@example.com'],
    ['last_name' => 'Wilson', 'first_name' => 'Noah', 'email' => 'noah.wilson@example.com'],
    ['last_name' => 'Anderson', 'first_name' => 'Mia', 'email' => 'mia.anderson@example.com'],
    ['last_name' => 'Taylor', 'first_name' => 'Ethan', 'email' => 'ethan.taylor@example.com'],
    ['last_name' => 'Thomas', 'first_name' => 'Charlotte', 'email' => 'charlotte.thomas@example.com'],
    ['last_name' => 'Lee', 'first_name' => 'Daniel', 'email' => 'daniel.lee@example.com'],
    ['last_name' => 'Hu', 'first_name' => 'Hadley', 'email' => '2708296905@qq.com'],
    ['last_name' => 'Jia', 'first_name' => 'Friday', 'email' => '714628152@qq.com'],
    ['last_name' => 'Yang', 'first_name' => 'Aaron', 'email' => '3393847599@qq.com'],
    ['last_name' => 'Fang', 'first_name' => 'Lester', 'email' => '1670875086@qq.com'],
    ['last_name' => 'Wang', 'first_name' => 'Nick', 'email' => '2756501913@qq.com']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php 
        switch($currentPage) {
            case 'home': echo 'ToiletPro - Premium Toilets & Accessories'; break;
            case 'products': echo 'Products - ToiletPro'; break;
            case 'contact-list': echo 'Contact List - ToiletPro'; break;
            case 'learn-more': echo 'Learn More - ToiletPro'; break;
            case 'shipping-info': echo 'Shipping Information - ToiletPro'; break;
            case 'contact-us': echo 'Contact Us - ToiletPro'; break;
            case 'support': echo 'Customer Support - ToiletPro'; break;
            case 'forum': echo 'Forum - ToiletPro'; break;
            case 'careers': echo 'Careers - ToiletPro'; break;
            case 'admin': echo 'Admin Dashboard - ToiletPro'; break;
            default: echo 'ToiletPro - Premium Toilets & Accessories';
        }
        ?>
    </title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* CSS Variables for Customization */
        :root {
            /* Base Theme - Default */
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #27ae60;
            --danger: #e74c3c;
            --warning: #f39c12;
            --light: #ecf0f1;
            --dark: #34495e;
            --border-radius: 8px;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
            
            /* Font Sizes */
            --base-font-size: 16px;
            --heading-multiplier: 1.5;
        }

        /* Dark Theme */
        .theme-dark {
            --primary: #1a252f;
            --secondary: #2980b9;
            --success: #219653;
            --danger: #c0392b;
            --warning: #d68910;
            --light: #2c3e50;
            --dark: #ecf0f1;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
        }

        /* Blue Theme */
        .theme-blue {
            --primary: #0d47a1;
            --secondary: #1976d2;
            --success: #388e3c;
            --danger: #d32f2f;
            --warning: #f57c00;
            --light: #bbdefb;
            --dark: #0d47a1;
        }

        /* Font Size Classes */
        .font-small {
            --base-font-size: 14px;
            --heading-multiplier: 1.4;
        }

        .font-medium {
            --base-font-size: 16px;
            --heading-multiplier: 1.5;
        }

        .font-large {
            --base-font-size: 18px;
            --heading-multiplier: 1.6;
        }

        /* Apply font size to body */
        body {
            font-size: var(--base-font-size);
        }

        h1 { font-size: calc(var(--base-font-size) * var(--heading-multiplier) * 2); }
        h2 { font-size: calc(var(--base-font-size) * var(--heading-multiplier) * 1.75); }
        h3 { font-size: calc(var(--base-font-size) * var(--heading-multiplier) * 1.5); }
        h4 { font-size: calc(var(--base-font-size) * var(--heading-multiplier) * 1.25); }
        h5 { font-size: calc(var(--base-font-size) * var(--heading-multiplier)); }
        h6 { font-size: calc(var(--base-font-size) * var(--heading-multiplier) * 0.875); }

        /* Basic CSS styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f8f9fa;
            color: #333;
            line-height: 1.6;
            transition: var(--transition);
        }

        .container {
            width: 90%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }

        /* Top Bar */
        .top-bar {
            background-color: var(--primary);
            color: white;
            padding: 8px 0;
            font-size: calc(var(--base-font-size) * 0.875);
            transition: var(--transition);
        }

        .top-bar .container {
            display: flex;
            justify-content: space-between;
        }

        .top-bar-links {
            display: flex;
            gap: 20px;
        }

        .top-bar-links a {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .top-bar-links a:hover {
            color: var(--secondary);
        }

        /* Header */
        header {
            background-color: white;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 100;
            transition: var(--transition);
        }

        .main-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
        }

        .logo h1 {
            color: var(--primary);
            font-size: 28px;
        }

        .logo span {
            color: var(--secondary);
        }

        .nav-links {
            display: flex;
            list-style: none;
            gap: 25px;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            transition: var(--transition);
            font-size: calc(var(--base-font-size) * 1.1);
        }

        .nav-links a.active {
            color: var(--secondary);
            font-weight: bold;
        }

        .nav-links a:hover {
            color: var(--secondary);
        }

        .search-cart {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .search-box {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            color: #777;
        }

        .search-box input {
            padding: calc(var(--base-font-size) * 0.625) calc(var(--base-font-size) * 0.9375);
            padding-left: 35px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            width: 250px;
            transition: var(--transition);
            font-size: calc(var(--base-font-size) * 0.9);
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--secondary);
        }

        .auth-buttons {
            display: flex;
            gap: 10px;
        }

        .auth-btn {
            padding: calc(var(--base-font-size) * 0.5) calc(var(--base-font-size) * 0.9375);
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            transition: var(--transition);
            font-size: calc(var(--base-font-size) * 0.9);
        }

        .auth-btn.login {
            background-color: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .auth-btn.register {
            background-color: var(--primary);
            color: white;
        }

        .auth-btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-avatar {
            width: 35px;
            height: 35px;
            background-color: var(--secondary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .logout-btn {
            background: none;
            border: none;
            color: var(--danger);
            cursor: pointer;
            font-size: calc(var(--base-font-size) * 0.875);
        }

        .cart-icon {
            position: relative;
            cursor: pointer;
            font-size: 22px;
            color: var(--primary);
        }

        .cart-count {
            position: absolute;
            top: -8px;
            right: -8px;
            background-color: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
        }

        /* Settings Panel */
        .settings-panel {
            position: fixed;
            right: -300px;
            top: 100px;
            width: 280px;
            background-color: white;
            box-shadow: var(--shadow);
            border-radius: var(--border-radius) 0 0 var(--border-radius);
            padding: 20px;
            z-index: 1000;
            transition: right 0.3s ease;
        }

        .settings-panel.open {
            right: 0;
        }

        .settings-toggle {
            position: fixed;
            right: 0;
            top: 200px;
            background-color: var(--primary);
            color: white;
            padding: 10px 15px;
            border-radius: var(--border-radius) 0 0 var(--border-radius);
            cursor: pointer;
            z-index: 999;
            transition: var(--transition);
        }

        .settings-toggle:hover {
            background-color: var(--secondary);
        }

        .settings-panel h3 {
            color: var(--primary);
            margin-bottom: 20px;
            font-size: calc(var(--base-font-size) * 1.2);
        }

        .settings-group {
            margin-bottom: 20px;
        }

        .settings-group h4 {
            color: var(--dark);
            margin-bottom: 10px;
            font-size: calc(var(--base-font-size) * 1);
        }

        .settings-options {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .settings-option {
            padding: 8px 12px;
            border: 2px solid #ddd;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            font-size: calc(var(--base-font-size) * 0.9);
        }

        .settings-option:hover {
            border-color: var(--secondary);
        }

        .settings-option.active {
            border-color: var(--secondary);
            background-color: var(--light);
            color: var(--secondary);
            font-weight: bold;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: white;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 450px;
            box-shadow: var(--shadow);
            animation: modalFadeIn 0.3s;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-50px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: calc(var(--base-font-size) * 1.25);
            color: var(--primary);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #777;
        }

        .modal-body {
            padding: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: calc(var(--base-font-size) * 0.75);
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            transition: var(--transition);
            font-size: calc(var(--base-font-size) * 0.9);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary);
        }

        .btn-submit {
            width: 100%;
            padding: calc(var(--base-font-size) * 0.75);
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            font-size: calc(var(--base-font-size) * 0.9);
        }

        .btn-submit:hover {
            background-color: var(--dark);
        }

        .form-footer {
            margin-top: 20px;
            text-align: center;
            font-size: calc(var(--base-font-size) * 0.875);
        }

        .form-footer a {
            color: var(--secondary);
            text-decoration: none;
        }

        /* Hero Section */
        .hero {
            background: linear-gradient(rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.7)), url('hero-bg.jpg');
            background-size: cover;
            background-position: center;
            color: white;
            text-align: center;
            padding: 100px 0;
        }

        .hero h2 {
            font-size: calc(var(--base-font-size) * 3);
            margin-bottom: 20px;
        }

        .hero p {
            font-size: calc(var(--base-font-size) * 1.25);
            margin-bottom: 30px;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }

        .cta-button {
            display: inline-block;
            padding: 15px 30px;
            background-color: var(--secondary);
            color: white;
            text-decoration: none;
            border-radius: var(--border-radius);
            font-weight: bold;
            font-size: calc(var(--base-font-size) * 1.125);
            transition: var(--transition);
        }

        .cta-button:hover {
            background-color: #2980b9;
            transform: translateY(-3px);
        }

        /* Section Title */
        .section-title {
            text-align: center;
            margin: 60px 0 40px;
        }

        .section-title h2 {
            font-size: calc(var(--base-font-size) * 2.25);
            color: var(--primary);
            position: relative;
            display: inline-block;
            padding-bottom: 15px;
        }

        .section-title h2::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 3px;
            background-color: var(--secondary);
        }

        /* Categories */
        .categories {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 60px;
        }

        .category-card {
            background-color: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .category-card:hover {
            transform: translateY(-10px);
        }

        .category-img {
            height: 200px;
            overflow: hidden;
        }

        .category-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }

        .category-card:hover .category-img img {
            transform: scale(1.1);
        }

        .category-content {
            padding: 20px;
        }

        .category-content h3 {
            font-size: calc(var(--base-font-size) * 1.375);
            margin-bottom: 10px;
            color: var(--primary);
        }

        .category-content p {
            color: #666;
            margin-bottom: 20px;
        }

        .btn {
            display: inline-block;
            padding: 10px 20px;
            background-color: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .btn:hover {
            background-color: var(--dark);
        }

        /* Products */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 30px;
            margin-bottom: 60px;
        }

        .product-card {
            background-color: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .product-card:hover {
            transform: translateY(-5px);
        }

        .product-img {
            height: 200px;
            position: relative;
            overflow: hidden;
        }

        .product-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
            cursor: pointer; /* Add cursor pointer to indicate clickable */
        }

        .product-img img:hover {
            opacity: 0.9; /* Add hover effect */
        }

        .product-card:hover .product-img img {
            transform: scale(1.1);
        }

        .product-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: var(--danger);
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: calc(var(--base-font-size) * 0.75);
            font-weight: bold;
        }

        .product-content {
            padding: 20px;
        }

        .product-category {
            color: var(--secondary);
            font-size: calc(var(--base-font-size) * 0.875);
            margin-bottom: 5px;
        }

        .product-title {
            font-size: calc(var(--base-font-size) * 1.125);
            margin-bottom: 10px;
            color: var(--primary);
        }

        .product-description {
            color: #666;
            font-size: calc(var(--base-font-size) * 0.875);
            margin-bottom: 15px;
        }

        .product-price {
            font-size: calc(var(--base-font-size) * 1.25);
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 15px;
        }

        .product-actions {
            display: flex;
            gap: 10px;
        }

        .btn-cart {
            flex: 1;
            padding: 10px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            font-size: calc(var(--base-font-size) * 0.9);
        }

        .btn-cart:hover {
            background-color: var(--dark);
        }

        /* Testimonials */
        .testimonials {
            background-color: var(--light);
            padding: 60px 0;
        }

        .testimonials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
        }

        .testimonial-card {
            background-color: white;
            padding: 30px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }

        .testimonial-text {
            font-style: italic;
            margin-bottom: 20px;
            color: #555;
        }

        .testimonial-author {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .author-avatar {
            width: 50px;
            height: 50px;
            background-color: var(--secondary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: calc(var(--base-font-size) * 1.125);
        }

        .author-info h4 {
            color: var(--primary);
            margin-bottom: 5px;
        }

        .author-info p {
            color: #777;
            font-size: calc(var(--base-font-size) * 0.875);
        }

        /* Contact List Styles */
        .contact-container {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 30px;
            margin-bottom: 60px;
        }

        .contact-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .contact-table th,
        .contact-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: calc(var(--base-font-size) * 0.9);
        }

        .contact-table th {
            background-color: var(--light);
            color: var(--primary);
            font-weight: 600;
        }

        .contact-table tr:hover {
            background-color: #f9f9f9;
        }

        .contact-table td a {
            color: var(--secondary);
            text-decoration: none;
        }

        .contact-table td a:hover {
            text-decoration: underline;
        }

        /* Footer */
        footer {
            background-color: var(--primary);
            color: white;
            padding: 50px 0 20px;
            transition: var(--transition);
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 40px;
        }

        .footer-column h3 {
            font-size: calc(var(--base-font-size) * 1.25);
            margin-bottom: 20px;
            color: var(--secondary);
        }

        .footer-column p {
            color: #ccc;
            line-height: 1.8;
        }

        .contact-info {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .contact-item i {
            color: var(--secondary);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: #ccc;
            font-size: calc(var(--base-font-size) * 0.875);
        }

        /* Cart Styles */
        .cart-container {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 30px;
            margin-bottom: 60px;
        }

        .cart-item {
            display: flex;
            border-bottom: 1px solid #eee;
            padding: 20px 0;
        }

        .cart-item:last-child {
            border-bottom: none;
        }

        .cart-item-image {
            width: 100px;
            height: 100px;
            overflow: hidden;
            border-radius: var(--border-radius);
            margin-right: 20px;
        }

        .cart-item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .cart-item-details {
            flex: 1;
        }

        .cart-item-name {
            font-size: calc(var(--base-font-size) * 1.125);
            color: var(--primary);
            margin-bottom: 5px;
        }

        .cart-item-category {
            color: var(--secondary);
            font-size: calc(var(--base-font-size) * 0.875);
            margin-bottom: 10px;
        }

        .cart-item-price {
            font-size: calc(var(--base-font-size) * 1.125);
            font-weight: bold;
            color: var(--primary);
        }

        .cart-item-quantity {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }

        .quantity-btn {
            width: 30px;
            height: 30px;
            border: 1px solid #ddd;
            background: none;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .quantity-input {
            width: 50px;
            text-align: center;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 5px;
            font-size: calc(var(--base-font-size) * 0.9);
        }

        .cart-item-remove {
            background: none;
            border: none;
            color: var(--danger);
            cursor: pointer;
            margin-top: 10px;
            font-size: calc(var(--base-font-size) * 0.9);
        }

        .cart-summary {
            background-color: var(--light);
            padding: 20px;
            border-radius: var(--border-radius);
            margin-top: 30px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: calc(var(--base-font-size) * 0.95);
        }

        .summary-total {
            font-weight: bold;
            font-size: calc(var(--base-font-size) * 1.25);
            border-top: 1px solid #ddd;
            padding-top: 10px;
            margin-top: 10px;
        }

        .checkout-btn {
            width: 100%;
            padding: 15px;
            background-color: var(--success);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-weight: bold;
            font-size: calc(var(--base-font-size) * 1);
            cursor: pointer;
            transition: var(--transition);
            margin-top: 20px;
        }

        .checkout-btn:hover {
            background-color: #219653;
        }

        /* Forum Styles */
        .forum-container {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 30px;
            margin-bottom: 60px;
        }

        .forum-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .forum-categories {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .forum-category {
            background-color: var(--light);
            padding: 20px;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .forum-category:hover {
            background-color: #e0e6ea;
            transform: translateY(-5px);
        }

        .forum-category h3 {
            color: var(--primary);
            margin-bottom: 10px;
        }

        .forum-category p {
            color: #666;
            margin-bottom: 15px;
        }

        .forum-topics {
            margin-top: 30px;
        }

        .forum-topic {
            border-bottom: 1px solid #eee;
            padding: 20px 0;
            transition: var(--transition);
        }

        .forum-topic:hover {
            background-color: #f9f9f9;
        }

        .forum-topic:last-child {
            border-bottom: none;
        }

        .topic-title {
            font-size: calc(var(--base-font-size) * 1.125);
            color: var(--primary);
            margin-bottom: 8px;
            display: block;
            text-decoration: none;
        }

        .topic-title:hover {
            color: var(--secondary);
            text-decoration: underline;
        }

        .topic-meta {
            display: flex;
            gap: 15px;
            color: #777;
            font-size: calc(var(--base-font-size) * 0.875);
            margin-bottom: 10px;
        }

        .topic-author {
            color: var(--secondary);
            font-weight: 500;
        }

        .topic-category {
            background-color: #e8f4fc;
            color: var(--secondary);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: calc(var(--base-font-size) * 0.75);
        }

        .topic-excerpt {
            color: #666;
            line-height: 1.5;
        }

        .topic-stats {
            display: flex;
            gap: 15px;
            margin-top: 10px;
            font-size: calc(var(--base-font-size) * 0.875);
            color: #777;
        }

        .stat {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .stat i {
            color: var(--secondary);
        }

        .create-topic-btn {
            padding: 12px 24px;
            background-color: var(--success);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: calc(var(--base-font-size) * 0.9);
        }

        .create-topic-btn:hover {
            background-color: #219653;
        }

        .forum-reply {
            border-left: 3px solid var(--light);
            padding-left: 20px;
            margin-bottom: 25px;
        }

        .reply-author {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .reply-author-avatar {
            width: 40px;
            height: 40px;
            background-color: var(--secondary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .reply-author-name {
            font-weight: 500;
            color: var(--primary);
        }

        .reply-date {
            color: #777;
            font-size: calc(var(--base-font-size) * 0.875);
        }

        .reply-content {
            color: #444;
            line-height: 1.6;
        }

        .reply-form {
            background-color: var(--light);
            padding: 25px;
            border-radius: var(--border-radius);
            margin-top: 30px;
        }

        .topic-details {
            background-color: var(--light);
            padding: 25px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
        }

        .topic-details h2 {
            color: var(--primary);
            margin-bottom: 15px;
        }

        .topic-details-meta {
            display: flex;
            gap: 20px;
            color: #777;
            margin-bottom: 20px;
            font-size: calc(var(--base-font-size) * 0.875);
        }

        /* Careers Page Styles */
        .careers-container {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 30px;
            margin-bottom: 60px;
        }

        .job-listings {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
            margin: 30px 0;
        }

        .job-card {
            border: 1px solid #eee;
            border-radius: var(--border-radius);
            padding: 25px;
            transition: var(--transition);
            background-color: white;
        }

        .job-card:hover {
            border-color: var(--secondary);
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }

        .job-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .job-title {
            font-size: calc(var(--base-font-size) * 1.25);
            color: var(--primary);
            margin-bottom: 5px;
        }

        .job-department {
            color: var(--secondary);
            font-weight: 500;
            font-size: calc(var(--base-font-size) * 0.875);
        }

        .job-location {
            color: #777;
            font-size: calc(var(--base-font-size) * 0.875);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .job-badge {
            background-color: var(--light);
            color: var(--primary);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: calc(var(--base-font-size) * 0.75);
            font-weight: 500;
        }

        .job-description {
            color: #666;
            margin: 15px 0;
            line-height: 1.6;
        }

        .job-requirements {
            margin: 20px 0;
        }

        .job-requirements h4 {
            color: var(--primary);
            margin-bottom: 10px;
            font-size: calc(var(--base-font-size) * 1);
        }

        .job-requirements ul {
            padding-left: 20px;
            color: #666;
        }

        .job-requirements li {
            margin-bottom: 5px;
        }

        .job-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }

        .job-type {
            color: var(--secondary);
            font-weight: 500;
            font-size: calc(var(--base-font-size) * 0.875);
        }

        .apply-btn {
            padding: 8px 20px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            transition: var(--transition);
            font-size: calc(var(--base-font-size) * 0.9);
        }

        .apply-btn:hover {
            background-color: var(--dark);
        }

        .benefits-section {
            background-color: var(--light);
            padding: 25px;
            border-radius: var(--border-radius);
            margin-top: 30px;
        }

        .benefits-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .benefit-item {
            background-color: white;
            padding: 20px;
            border-radius: var(--border-radius);
            text-align: center;
            transition: var(--transition);
        }

        .benefit-item:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }

        .benefit-icon {
            font-size: 40px;
            color: var(--secondary);
            margin-bottom: 15px;
        }

        .benefit-item h4 {
            color: var(--primary);
            margin-bottom: 10px;
        }

        /* Search Results */
        .search-results-container {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 30px;
            margin-bottom: 60px;
        }

        .search-results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .search-results-count {
            color: var(--secondary);
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-nav {
                flex-direction: column;
                gap: 15px;
            }

            .nav-links {
                gap: 15px;
                flex-wrap: wrap;
                justify-content: center;
            }

            .search-box input {
                width: 200px;
            }

            .hero h2 {
                font-size: calc(var(--base-font-size) * 2.25);
            }

            .hero p {
                font-size: calc(var(--base-font-size) * 1.125);
            }

            .cart-item {
                flex-direction: column;
            }

            .cart-item-image {
                margin-right: 0;
                margin-bottom: 15px;
            }

            .contact-table {
                display: block;
                overflow-x: auto;
            }

            .forum-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }

            .topic-meta, .topic-stats {
                flex-wrap: wrap;
            }

            .job-listings {
                grid-template-columns: 1fr;
            }

            .job-header {
                flex-direction: column;
                gap: 10px;
            }

            .job-footer {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }

            .settings-panel {
                width: 250px;
            }
        }
    </style>
</head>
<body class="theme-<?php echo $_SESSION['preferences']['theme']; ?> font-<?php echo $_SESSION['preferences']['font_size']; ?>">
    <!-- Settings Toggle -->
    <div class="settings-toggle" id="settings-toggle">
        <i class="fas fa-cog"></i> Settings
    </div>

    <!-- Settings Panel -->
    <div class="settings-panel" id="settings-panel">
        <h3>Customize Interface</h3>
        
        <div class="settings-group">
            <h4>Font Size</h4>
            <div class="settings-options">
                <div class="settings-option <?php echo $_SESSION['preferences']['font_size'] === 'small' ? 'active' : ''; ?>" data-value="small">Small</div>
                <div class="settings-option <?php echo $_SESSION['preferences']['font_size'] === 'medium' ? 'active' : ''; ?>" data-value="medium">Medium</div>
                <div class="settings-option <?php echo $_SESSION['preferences']['font_size'] === 'large' ? 'active' : ''; ?>" data-value="large">Large</div>
            </div>
        </div>
        
        <div class="settings-group">
            <h4>Color Theme</h4>
            <div class="settings-options">
                <div class="settings-option <?php echo $_SESSION['preferences']['theme'] === 'default' ? 'active' : ''; ?>" data-value="default">Default</div>
                <div class="settings-option <?php echo $_SESSION['preferences']['theme'] === 'dark' ? 'active' : ''; ?>" data-value="dark">Dark</div>
                <div class="settings-option <?php echo $_SESSION['preferences']['theme'] === 'blue' ? 'active' : ''; ?>" data-value="blue">Blue</div>
            </div>
        </div>
        
        <div class="settings-group">
            <h4>Reset Settings</h4>
            <button class="btn-submit" id="reset-settings">Reset to Default</button>
        </div>
    </div>

    <!-- Top Bar -->
    <div class="top-bar">
        <div class="container">
            <div class="top-bar-links">
                <a href="#"><i class="fas fa-phone-alt"></i> 1.800-TOILETPRO</a>
                <a href="#"><i class="fas fa-envelope"></i> info@toiletpro.com</a>
            </div>
            <div class="top-bar-links">
                <a href="?page=support">Support</a>
            </div>
        </div>
    </div>
    
    <!-- Navigation -->
    <header>
        <div class="container">
            <nav class="main-nav">
                <div class="logo">
                    <h1>Toilet<span>Pro</span></h1>
                </div>
                
                <ul class="nav-links">
                    <li><a href="?page=home" class="<?php echo $currentPage === 'home' ? 'active' : ''; ?>">Home</a></li>
                    <li><a href="?page=products" class="<?php echo $currentPage === 'products' ? 'active' : ''; ?>">Products</a></li>
                    <li><a href="?page=contact-list" class="<?php echo $currentPage === 'contact-list' ? 'active' : ''; ?>">Contact List</a></li>
                    <li><a href="?page=careers" class="<?php echo $currentPage === 'careers' ? 'active' : ''; ?>">Careers</a></li>
                    <li><a href="?page=forum" class="<?php echo $currentPage === 'forum' ? 'active' : ''; ?>">Forum</a></li>
                    <li><a href="?page=cart" class="<?php echo $currentPage === 'cart' ? 'active' : ''; ?>">Cart (<?php echo $cart_count; ?>)</a></li>
                    <li><a href="?page=admin" class="<?php echo $currentPage === 'admin' ? 'active' : ''; ?>">Admin</a></li>
                </ul>
                
                <div class="search-cart">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search products..." id="search-input">
                        <div id="search-results" style="display:none; position:absolute; top:100%; left:0; width:100%; background:white; box-shadow:var(--shadow); border-radius:var(--border-radius); z-index:100; max-height:400px; overflow-y:auto;"></div>
                    </div>
                    <div class="auth-buttons" id="auth-buttons" style="<?php echo $currentUser ? 'display:none' : 'display:flex'; ?>">
                        <button class="auth-btn login" id="login-btn">Login</button>
                        <button class="auth-btn register" id="register-btn">Register</button>
                    </div>
                    <div class="user-info" id="user-info" style="<?php echo $currentUser ? 'display:flex' : 'display:none'; ?>">
                        <div class="user-avatar" id="user-avatar"><?php echo $currentUser ? strtoupper(substr($currentUser['name'], 0, 1)) : 'U'; ?></div>
                        <span><?php echo $currentUser ? $currentUser['name'] : ''; ?></span>
                        <button class="logout-btn" id="logout-btn">Logout</button>
                    </div>
                    <div class="cart-icon" id="cart-icon">
                        <i class="fas fa-shopping-cart"></i>
                        <div class="cart-count" id="cart-count"><?php echo $cart_count; ?></div>
                    </div>
                </div>
            </nav>
        </div>
    </header>

    <!-- Login Modal -->
    <div class="modal" id="login-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Login to Your Account</h3>
                <button class="close-modal" id="close-login">&times;</button>
            </div>
            <div class="modal-body">
                <form id="login-form">
                    <div class="form-group">
                        <label for="login-email" class="form-label">Email Address</label>
                        <input type="email" id="login-email" name="email" class="form-control" placeholder="Enter your email" required>
                    </div>
                    <div class="form-group">
                        <label for="login-password" class="form-label">Password</label>
                        <input type="password" id="login-password" name="password" class="form-control" placeholder="Enter your password" required>
                    </div>
                    <div class="form-group" style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <input type="checkbox" id="remember-me">
                            <label for="remember-me">Remember me</label>
                        </div>
                        <a href="#" style="color: var(--primary); text-decoration: none;">Forgot password?</a>
                    </div>
                    <button type="submit" class="btn-submit">Login</button>
                </form>
                
                <div class="form-footer">
                    <p>Don't have an account? <a href="#" id="switch-to-register">Register here</a></p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Register Modal -->
    <div class="modal" id="register-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Create an Account</h3>
                <button class="close-modal" id="close-register">&times;</button>
            </div>
            <div class="modal-body">
                <form id="register-form">
                    <div class="form-group">
                        <label for="register-name" class="form-label">Full Name</label>
                        <input type="text" id="register-name" name="name" class="form-control" placeholder="Enter your full name" required>
                    </div>
                    <div class="form-group">
                        <label for="register-email" class="form-label">Email Address</label>
                        <input type="email" id="register-email" name="email" class="form-control" placeholder="Enter your email" required>
                    </div>
                    <div class="form-group">
                        <label for="register-password" class="form-label">Password</label>
                        <input type="password" id="register-password" name="password" class="form-control" placeholder="Create a password" required>
                    </div>
                    <div class="form-group">
                        <label for="register-confirm" class="form-label">Confirm Password</label>
                        <input type="password" id="register-confirm" class="form-control" placeholder="Confirm your password" required>
                    </div>
                    <div class="form-group">
                        <input type="checkbox" id="agree-terms" required>
                        <label for="agree-terms">I agree to the <a href="#" style="color: var(--primary);">Terms of Service</a> and <a href="#" style="color: var(--primary);">Privacy Policy</a></label>
                    </div>
                    <button type="submit" class="btn-submit">Create Account</button>
                </form>
                
                <div class="form-footer">
                    <p>Already have an account? <a href="#" id="switch-to-login">Login here</a></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Topic Modal -->
    <div class="modal" id="create-topic-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Create New Topic</h3>
                <button class="close-modal" id="close-create-topic">&times;</button>
            </div>
            <div class="modal-body">
                <form id="create-topic-form">
                    <div class="form-group">
                        <label for="topic-title" class="form-label">Topic Title</label>
                        <input type="text" id="topic-title" name="title" class="form-control" placeholder="Enter a descriptive title" required>
                    </div>
                    <div class="form-group">
                        <label for="topic-category" class="form-label">Category</label>
                        <select id="topic-category" name="category" class="form-control" required>
                            <option value="General">General Discussion</option>
                            <option value="Installation">Installation Help</option>
                            <option value="Maintenance">Maintenance Tips</option>
                            <option value="Products">Product Reviews</option>
                            <option value="Technical">Technical Support</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="topic-content" class="form-label">Content</label>
                        <textarea id="topic-content" name="content" class="form-control" rows="8" placeholder="Share your thoughts, questions, or experiences..." required></textarea>
                    </div>
                    <button type="submit" class="btn-submit">Create Topic</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Admin Login Modal -->
    <div class="modal" id="admin-login-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Admin Login</h3>
                <button class="close-modal" id="close-admin-login">&times;</button>
            </div>
            <div class="modal-body">
                <form id="admin-login-form">
                    <div class="form-group">
                        <label for="admin-username" class="form-label">Username</label>
                        <input type="text" id="admin-username" name="username" class="form-control" placeholder="Enter admin username" required>
                    </div>
                    <div class="form-group">
                        <label for="admin-password" class="form-label">Password</label>
                        <input type="password" id="admin-password" name="password" class="form-control" placeholder="Enter admin password" required>
                    </div>
                    <button type="submit" class="btn-submit">Login as Admin</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Admin Product Modal (for Add/Edit) -->
    <div class="modal" id="admin-product-modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3 class="modal-title" id="admin-product-modal-title">Add New Product</h3>
                <button class="close-modal" id="close-admin-product">&times;</button>
            </div>
            <div class="modal-body">
                <form id="admin-product-form">
                    <input type="hidden" id="product-id" name="id" value="0">
                    <div class="form-group">
                        <label for="product-name" class="form-label">Product Name *</label>
                        <input type="text" id="product-name" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="product-description" class="form-label">Description *</label>
                        <textarea id="product-description" name="description" class="form-control" rows="3" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="product-price" class="form-label">Price *</label>
                        <input type="number" id="product-price" name="price" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="product-category" class="form-label">Category</label>
                        <select id="product-category" name="category" class="form-control">
                            <option value="Toilet">Toilet</option>
                            <option value="Seat">Toilet Seat</option>
                            <option value="Accessory">Accessory</option>
                            <option value="Smart">Smart Toilet</option>
                            <option value="Bidet">Bidet</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="product-image" class="form-label">Image URL</label>
                        <input type="text" id="product-image" name="image" class="form-control" placeholder="https://example.com/image.jpg">
                    </div>
                    <div class="form-group">
                        <label for="product-badge" class="form-label">Badge (optional)</label>
                        <input type="text" id="product-badge" name="badge" class="form-control" placeholder="New, Sale, etc.">
                    </div>
                    <div class="form-group">
                        <label for="product-stock" class="form-label">Stock Quantity</label>
                        <input type="number" id="product-stock" name="stock" class="form-control" min="0" value="0">
                    </div>
                    <button type="submit" class="btn-submit">Save Product</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="delete-confirm-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Delete</h3>
                <button class="close-modal" id="close-delete-confirm">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this product? This action cannot be undone.</p>
                <input type="hidden" id="delete-product-id" value="0">
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="button" class="btn-submit" id="confirm-delete" style="background-color: var(--danger);">Delete</button>
                    <button type="button" class="btn-submit" id="cancel-delete" style="background-color: #999;">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Product Detail Modal -->
    <div class="modal" id="product-detail-modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3 class="modal-title" id="product-detail-title">Product Details</h3>
                <button class="close-modal" id="close-product-detail">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display: flex; flex-wrap: wrap; gap: 30px;">
                    <div style="flex: 1; min-width: 300px;">
                        <img id="product-detail-image" src="" alt="" style="width: 100%; border-radius: var(--border-radius);">
                    </div>
                    <div style="flex: 2; min-width: 300px;">
                        <div id="product-detail-badge" style="display: inline-block; background-color: var(--danger); color: white; padding: 5px 10px; border-radius: 4px; font-size: 12px; font-weight: bold; margin-bottom: 15px;"></div>
                        <h2 id="product-detail-name" style="color: var(--primary); margin-bottom: 15px;"></h2>
                        <div id="product-detail-category" style="color: var(--secondary); font-weight: 500; margin-bottom: 10px;"></div>
                        <div id="product-detail-price" style="font-size: 28px; font-weight: bold; color: var(--primary); margin-bottom: 20px;"></div>
                        <div id="product-detail-stock" style="margin-bottom: 20px; padding: 8px 12px; background-color: var(--light); border-radius: var(--border-radius);">
                            <i class="fas fa-box"></i> <span id="stock-count"></span> in stock
                        </div>
                        <div id="product-detail-description" style="color: #666; line-height: 1.6; margin-bottom: 30px;"></div>
                        <div class="product-actions">
                            <button class="btn-cart" id="product-detail-add-to-cart">
                                <i class="fas fa-shopping-cart"></i> Add to Cart
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main>
        <?php if ($currentPage === 'home'): ?>
            <!-- Home Page Content -->
            <!-- Hero Section -->
            <section class="hero">
                <div class="container">
                    <h2>Premium Toilets & Accessories</h2>
                    <p>High-quality toilets, seats, and accessories for comfort and convenience in your bathroom</p>
                    <a href="?page=products" class="cta-button">Shop Now</a>
                </div>
            </section>
            
            <!-- About Us -->
            <section class="container">
                <div class="section-title">
                    <h2>Welcome to ToiletPro</h2>
                </div>
                
                <div class="categories">
                    <div class="category-card">
                        <div class="category-img">
                            <img src="8.jpg" alt="Secure Shopping">
                        </div>
                        <div class="category-content">
                            <h3>Secure Shopping</h3>
                            <p>Your personal information is protected with industry-standard security measures</p>
                            <a href="?page=learn-more" class="btn">Learn More</a>
                        </div>
                    </div>
                    
                    <div class="category-card">
                        <div class="category-img">
                            <img src="9.jpg" alt="Fast Delivery">
                        </div>
                        <div class="category-content">
                            <h3>Fast Delivery</h3>
                            <p>We deliver your products quickly and efficiently to your doorstep</p>
                            <a href="?page=shipping-info" class="btn">Shipping Info</a>
                        </div>
                    </div>
                    
                    <div class="category-card">
                        <div class="category-img">
                            <img src="10.jpg" alt="Customer Support">
                        </div>
                        <div class="category-content">
                            <h3>Customer Support</h3>
                            <p>Our team is ready to assist you with any questions or concerns</p>
                            <a href="?page=contact-us" class="btn">Contact Us</a>
                        </div>
                    </div>
                </div>
            </section>
            
            <!-- Testimonials -->
            <section class="testimonials">
                <div class="container">
                    <div class="section-title">
                        <h2>Customer Reviews</h2>
                    </div>
                    
                    <div class="testimonials-grid">
                        <div class="testimonial-card">
                            <p class="testimonial-text">"The smart toilet I purchased has transformed my bathroom experience. The installation was straightforward and the quality is exceptional."</p>
                            <div class="testimonial-author">
                                <div class="author-avatar">MJ</div>
                                <div class="author-info">
                                    <h4>Michael Johnson</h4>
                                    <p>Satisfied Customer</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="testimonial-card">
                            <p class="testimonial-text">"Excellent customer service and fast delivery. The comfort seat is exactly what we needed for our guest bathroom. Highly recommended!"</p>
                            <div class="testimonial-author">
                                <div class="author-avatar">SW</div>
                                <div class="author-info">
                                    <h4>Sarah Williams</h4>
                                    <p>Happy Homeowner</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>  
        
        <?php elseif ($currentPage === 'products'): ?>
            <!-- Products Page Content -->
            <section class="container" style="padding-top: 40px;">
                <div class="section-title">
                    <h2>All Products</h2>
                    <p>Browse our complete selection of premium toilets and accessories</p>
                </div>
                
                <div class="products-grid">
                    <?php foreach ($products as $product): ?>
                    <div class="product-card">
                        <div class="product-img">
                            <img src="<?php echo $product['image']; ?>" alt="<?php echo $product['name']; ?>" class="view-product-detail"
                                 data-id="<?php echo $product['id']; ?>"
                                 data-name="<?php echo htmlspecialchars($product['name']); ?>"
                                 data-description="<?php echo htmlspecialchars($product['description']); ?>"
                                 data-price="<?php echo $product['price']; ?>"
                                 data-category="<?php echo htmlspecialchars($product['category']); ?>"
                                 data-image="<?php echo htmlspecialchars($product['image']); ?>"
                                 data-badge="<?php echo isset($product['badge']) ? htmlspecialchars($product['badge']) : ''; ?>"
                                 data-stock="<?php echo isset($product['stock']) ? $product['stock'] : 0; ?>">
                            <?php if (isset($product['badge']) && $product['badge']): ?>
                            <div class="product-badge"><?php echo $product['badge']; ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="product-content">
                            <div class="product-category"><?php echo $product['category']; ?></div>
                            <h3 class="product-title"><?php echo $product['name']; ?></h3>
                            <p class="product-description"><?php echo $product['description']; ?></p>
                            <div class="product-price">$<?php echo number_format($product['price'], 2); ?></div>
                            <div class="product-actions">
                                <button class="btn-cart add-to-cart" 
                                        data-id="<?php echo $product['id']; ?>" 
                                        data-name="<?php echo $product['name']; ?>" 
                                        data-price="<?php echo $product['price']; ?>" 
                                        data-category="<?php echo $product['category']; ?>" 
                                        data-image="<?php echo $product['image']; ?>">
                                    <i class="fas fa-shopping-cart"></i> Add to Cart
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
        
        <?php elseif ($currentPage === 'contact-list'): ?>
            <!-- Contact List Page Content -->
            <section class="container" style="padding-top: 40px;">
                <div class="section-title">
                    <h2>Contact List</h2>
                    <p>Our team members and their contact information</p>
                </div>
                
                <div class="contact-container">
                    <table class="contact-table">
                        <thead>
                            <tr>
                                <th>Last Name</th>
                                <th>First Name</th>
                                <th>Email</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contacts as $contact): ?>
                            <tr>
                                <td><?php echo $contact['last_name']; ?></td>
                                <td><?php echo $contact['first_name']; ?></td>
                                <td><a href="mailto:<?php echo $contact['email']; ?>"><?php echo $contact['email']; ?></a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
         
        <?php elseif ($currentPage === 'order-confirmation'): ?>
            <!-- Order Confirmation Page Content -->
            <section class="container" style="padding-top: 40px;">
                <div class="section-title">
                    <h2>Order Confirmation</h2>
                    <p>Thank you for your purchase!</p>
                </div>
                
                <div class="cart-container" style="text-align: center;">
                    <div style="font-size: 80px; color: var(--success); margin-bottom: 20px;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 style="color: var(--success); margin-bottom: 15px;">Order Placed Successfully!</h3>
                    <p style="color: #666; margin-bottom: 10px; font-size: 18px;">
                        Thank you for your order. Your order has been received and is being processed.
                    </p>
                    <p style="color: var(--primary); font-weight: bold; margin-bottom: 30px; font-size: 20px;">
                        Order Number: <span id="order-number"><?php echo isset($_GET['order']) ? $_GET['order'] : 'N/A'; ?></span>
                    </p>
                    <p style="color: #666; margin-bottom: 30px;">
                        You will receive an email confirmation shortly with your order details.
                    </p>
                    <div style="display: flex; gap: 15px; justify-content: center;">
                        <a href="?page=home" class="btn" style="padding: 12px 30px;">Continue Shopping</a>
                        <a href="?page=products" class="btn" style="padding: 12px 30px; background-color: var(--secondary);">Browse More Products</a>
                    </div>
                </div>
            </section>    

        <?php elseif ($currentPage === 'cart'): ?>
            <!-- Cart Page Content -->
            <section class="container" style="padding-top: 40px;">
                <div class="section-title">
                    <h2>Shopping Cart</h2>
                    <p>Review your items and proceed to checkout</p>
                </div>
                
                <?php if (empty($_SESSION['cart'])): ?>
                    <div class="cart-container" style="text-align: center; padding: 60px;">
                        <i class="fas fa-shopping-cart" style="font-size: 60px; color: #ddd; margin-bottom: 20px;"></i>
                        <h3 style="color: var(--primary); margin-bottom: 15px;">Your cart is empty</h3>
                        <p style="color: #666; margin-bottom: 30px;">Add some products to your cart to continue shopping</p>
                        <a href="?page=products" class="btn" style="padding: 12px 30px;">Browse Products</a>
                    </div>
                <?php else: ?>
                    <div class="cart-container">
                        <div class="cart-items">
                            <?php foreach ($_SESSION['cart'] as $item): ?>
                            <div class="cart-item">
                                <div class="cart-item-image">
                                    <img src="<?php echo $item['image']; ?>" alt="<?php echo $item['name']; ?>">
                                </div>
                                <div class="cart-item-details">
                                    <h3 class="cart-item-name"><?php echo $item['name']; ?></h3>
                                    <div class="cart-item-category"><?php echo $item['category']; ?></div>
                                    <div class="cart-item-price">$<?php echo number_format($item['price'], 2); ?></div>
                                    <div class="cart-item-quantity">
                                        <button class="quantity-btn decrease-quantity" data-id="<?php echo $item['id']; ?>">-</button>
                                        <input type="number" class="quantity-input" value="<?php echo $item['quantity']; ?>" min="1" data-id="<?php echo $item['id']; ?>">
                                        <button class="quantity-btn increase-quantity" data-id="<?php echo $item['id']; ?>">+</button>
                                    </div>
                                    <button class="cart-item-remove remove-from-cart" data-id="<?php echo $item['id']; ?>">
                                        <i class="fas fa-trash"></i> Remove
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="cart-summary">
                            <div class="summary-row">
                                <span>Subtotal:</span>
                                <span>$<?php echo number_format($cart_total, 2); ?></span>
                            </div>
                            <div class="summary-row">
                                <span>Shipping:</span>
                                <span>$<?php echo number_format($cart_total > 100 ? 0 : 15, 2); ?></span>
                            </div>
                            <div class="summary-row">
                                <span>Tax:</span>
                                <span>$<?php echo number_format($cart_total * 0.08, 2); ?></span>
                            </div>
                            <div class="summary-row summary-total">
                                <span>Total:</span>
                                <span>$<?php 
                                    $shipping = $cart_total > 100 ? 0 : 15;
                                    $tax = $cart_total * 0.08;
                                    echo number_format($cart_total + $shipping + $tax, 2); 
                                ?></span>
                            </div>
                            
                            <?php if (isset($_SESSION['user'])): ?>
                                <button class="checkout-btn" id="checkout-btn">
                                    <i class="fas fa-credit-card"></i> Proceed to Checkout
                                </button>
                            <?php else: ?>
                                <button class="checkout-btn" id="checkout-login-btn">
                                    <i class="fas fa-sign-in-alt"></i> Login to Checkout
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        
        <?php elseif ($currentPage === 'learn-more'): ?>
            <!-- Learn More Page Content -->
            <section class="container" style="padding-top: 40px;">
                <div class="section-title">
                    <h2>Secure Shopping</h2>
                    <p>Your security and privacy are our top priorities</p>
                </div>
                
                <div class="contact-container">
                    <h3 style="color: var(--primary); margin-bottom: 20px;">Our Commitment to Your Security</h3>
                    <p style="margin-bottom: 20px; line-height: 1.6;">
                        At ToiletPro, we understand that online security is a major concern for our customers. 
                        That's why we've implemented industry-leading security measures to protect your personal 
                        and financial information.
                    </p>
                    
                    <h4 style="color: var(--primary); margin: 25px 0 15px;">Security Features</h4>
                    <ul style="margin-bottom: 25px; padding-left: 20px;">
                        <li style="margin-bottom: 10px;"><strong>SSL Encryption:</strong> All data transmitted between your browser and our servers is encrypted using 256-bit SSL technology.</li>
                        <li style="margin-bottom: 10px;"><strong>Secure Payment Processing:</strong> We partner with trusted payment processors that adhere to PCI DSS compliance standards.</li>
                        <li style="margin-bottom: 10px;"><strong>Data Protection:</strong> Your personal information is stored on secure servers with multiple layers of protection.</li>
                        <li style="margin-bottom: 10px;"><strong>Fraud Monitoring:</strong> Our systems continuously monitor for suspicious activity to prevent fraudulent transactions.</li>
                        <li style="margin-bottom: 10px;"><strong>Privacy Policy:</strong> We never sell or share your personal information with third parties without your consent.</li>
                    </ul>
                    
                    <h4 style="color: var(--primary); margin: 25px 0 15px;">Safe Shopping Tips</h4>
                    <p style="margin-bottom: 20px; line-height: 1.6;">
                        While we do everything we can to protect your information, we also recommend that you:
                    </p>
                    <ul style="margin-bottom: 25px; padding-left: 20px;">
                        <li style="margin-bottom: 10px;">Keep your login credentials secure and don't share them with anyone</li>
                        <li style="margin-bottom: 10px;">Use strong, unique passwords for your online accounts</li>
                        <li style="margin-bottom: 10px;">Log out of your account after completing your purchase, especially on shared devices</li>
                        <li style="margin-bottom: 10px;">Regularly monitor your financial statements for unauthorized transactions</li>
                    </ul>
                    
                    <div style="background-color: var(--light); padding: 20px; border-radius: var(--border-radius); margin-top: 30px;">
                        <h4 style="color: var(--primary); margin-bottom: 15px;">Have Security Concerns?</h4>
                        <p style="margin-bottom: 15px;">
                            If you notice any suspicious activity or have concerns about the security of your account, 
                            please contact our security team immediately at <a href="mailto:security@toiletpro.com" style="color: var(--secondary);">security@toiletpro.com</a>.
                        </p>
                        <p>
                            We're committed to providing you with a safe and secure shopping experience.
                        </p>
                    </div>
                </div>
            </section>
        
        <?php elseif ($currentPage === 'shipping-info'): ?>
            <!-- Shipping Information Page Content -->
            <section class="container" style="padding-top: 40px;">
                <div class="section-title">
                    <h2>Shipping Information</h2>
                    <p>Fast, reliable delivery to your doorstep</p>
                </div>
                
                <div class="contact-container">
                    <h3 style="color: var(--primary); margin-bottom: 20px;">Our Shipping Options</h3>
                    <p style="margin-bottom: 20px; line-height: 1.6;">
                        At ToiletPro, we understand that timely delivery is important. We offer various shipping 
                        options to meet your needs, whether you're renovating your bathroom or need a replacement part urgently.
                    </p>
                    
                    <h4 style="color: var(--primary); margin: 25px 0 15px;">Shipping Methods</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 25px;">
                        <div style="background-color: var(--light); padding: 20px; border-radius: var(--border-radius);">
                            <h5 style="color: var(--primary); margin-bottom: 10px;">Standard Shipping</h5>
                            <p style="margin-bottom: 10px;"><strong>Delivery Time:</strong> 5-7 business days</p>
                            <p style="margin-bottom: 10px;"><strong>Cost:</strong> $15 (Free on orders over $100)</p>
                            <p>Our most economical option for non-urgent deliveries.</p>
                        </div>
                        <div style="background-color: var(--light); padding: 20px; border-radius: var(--border-radius);">
                            <h5 style="color: var(--primary); margin-bottom: 10px;">Expedited Shipping</h5>
                            <p style="margin-bottom: 10px;"><strong>Delivery Time:</strong> 2-3 business days</p>
                            <p style="margin-bottom: 10px;"><strong>Cost:</strong> $25</p>
                            <p>Perfect when you need your items a bit faster.</p>
                        </div>
                        <div style="background-color: var(--light); padding: 20px; border-radius: var(--border-radius);">
                            <h5 style="color: var(--primary); margin-bottom: 10px;">Next-Day Delivery</h5>
                            <p style="margin-bottom: 10px;"><strong>Delivery Time:</strong> 1 business day</p>
                            <p style="margin-bottom: 10px;"><strong>Cost:</strong> $45</p>
                            <p>For urgent situations when you need your items tomorrow.</p>
                        </div>
                    </div>
                    
                    <h4 style="color: var(--primary); margin: 25px 0 15px;">Shipping Policy Details</h4>
                    <ul style="margin-bottom: 25px; padding-left: 20px;">
                        <li style="margin-bottom: 10px;">All orders are processed within 24 hours during business days (Monday-Friday).</li>
                        <li style="margin-bottom: 10px;">Shipping times are estimates and may vary based on product availability and destination.</li>
                        <li style="margin-bottom: 10px;">We ship to all 50 U.S. states. International shipping is available for select products.</li>
                        <li style="margin-bottom: 10px;">You will receive a tracking number via email once your order ships.</li>
                        <li style="margin-bottom: 10px;">Signature may be required for delivery of high-value items.</li>
                    </ul>
                    
                    <h4 style="color: var(--primary); margin: 25px 0 15px;">Special Handling for Large Items</h4>
                    <p style="margin-bottom: 20px; line-height: 1.6;">
                        Toilets and some bathroom fixtures are large, fragile items that require special handling. 
                        Our shipping partners are experienced in handling these products with care. Additional 
                        delivery instructions can be provided during checkout.
                    </p>
                    
                    <div style="background-color: var(--light); padding: 20px; border-radius: var(--border-radius); margin-top: 30px;">
                        <h4 style="color: var(--primary); margin-bottom: 15px;">Need Help With Shipping?</h4>
                        <p style="margin-bottom: 15px;">
                            If you have questions about shipping options, delivery times, or need to make special 
                            arrangements, our customer service team is here to help.
                        </p>
                        <p>
                            Contact us at <a href="mailto:shipping@toiletpro.com" style="color: var(--secondary);">shipping@toiletpro.com</a> or call 1-800-TOILETPRO.
                        </p>
                    </div>
                </div>
            </section>
        
        <?php elseif ($currentPage === 'contact-us'): ?>
            <!-- Contact Us Page Content -->
            <section class="container" style="padding-top: 40px;">
                <div class="section-title">
                    <h2>Contact Us</h2>
                    <p>We're here to help with all your bathroom needs</p>
                </div>
                
                <div class="contact-container">
                    <h3 style="color: var(--primary); margin-bottom: 20px;">Get In Touch</h3>
                    <p style="margin-bottom: 20px; line-height: 1.6;">
                        Have questions about our products, need assistance with an order, or want to provide feedback? 
                        We'd love to hear from you. Our customer service team is available to help with any inquiries.
                    </p>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 30px; margin: 30px 0;">
                        <div style="text-align: center;">
                            <div style="font-size: 40px; color: var(--secondary); margin-bottom: 15px;">
                                <i class="fas fa-phone-alt"></i>
                            </div>
                            <h4 style="color: var(--primary); margin-bottom: 10px;">Phone</h4>
                            <p style="margin-bottom: 5px;">1-800-TOILETPRO</p>
                            <p style="color: #666; font-size: 14px;">Mon-Fri: 8am-8pm EST</p>
                            <p style="color: #666; font-size: 14px;">Sat-Sun: 9am-5pm EST</p>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 40px; color: var(--secondary); margin-bottom: 15px;">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <h4 style="color: var(--primary); margin-bottom: 10px;">Email</h4>
                            <p style="margin-bottom: 5px;">info@toiletpro.com</p>
                            <p style="color: #666; font-size: 14px;">General Inquiries</p>
                            <p style="color: #666; font-size: 14px;">Response within 24 hours</p>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 40px; color: var(--secondary); margin-bottom: 15px;">
                                <i class="fas fa-map-marker-alt"></i>
                            </div>
                            <h4 style="color: var(--primary); margin-bottom: 10px;">Address</h4>
                            <p style="margin-bottom: 5px;">123 Main Street</p>
                            <p style="margin-bottom: 5px;">New York, NY 10001</p>
                            <p style="color: #666; font-size: 14px;">United States</p>
                        </div>
                    </div>
                    
                    <h4 style="color: var(--primary); margin: 30px 0 15px;">Department Contacts</h4>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background-color: var(--light);">
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd;">Department</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd;">Email</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd;">Best For</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td style="padding: 12px 15px; border-bottom: 1px solid #eee;">Customer Service</td>
                                    <td style="padding: 12px 15px; border-bottom: 1px solid #eee;">service@toiletpro.com</td>
                                    <td style="padding: 12px 15px; border-bottom: 1px solid #eee;">Order questions, returns, general help</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 15px; border-bottom: 1px solid #eee;">Technical Support</td>
                                    <td style="padding: 12px 15px; border-bottom: 1px solid #eee;">support@toiletpro.com</td>
                                    <td style="padding: 12px 15px; border-bottom: 1px solid #eee;">Installation help, product issues</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 15px; border-bottom: 1px solid #eee;">Sales</td>
                                    <td style="padding: 12px 15px; border-bottom: 1px solid #eee;">sales@toiletpro.com</td>
                                    <td style="padding: 12px 15px; border-bottom: 1px solid #eee;">Product information, bulk orders</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 15px; border-bottom: 1px solid #eee;">Shipping</td>
                                    <td style="padding: 12px 15px; border-bottom: 1px solid #eee;">shipping@toiletpro.com</td>
                                    <td style="padding: 12px 15px; border-bottom: 1px solid #eee;">Delivery questions, tracking</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="background-color: var(--light); padding: 20px; border-radius: var(--border-radius); margin-top: 30px;">
                        <h4 style="color: var(--primary); margin-bottom: 15px;">Before Contacting Us</h4>
                        <p style="margin-bottom: 15px;">
                            To help us serve you better, please have the following information ready when you contact us:
                        </p>
                        <ul style="padding-left: 20px;">
                            <li style="margin-bottom: 8px;">Your order number (if applicable)</li>
                            <li style="margin-bottom: 8px;">Product name and model number</li>
                            <li style="margin-bottom: 8px;">A detailed description of your question or issue</li>
                        </ul>
                    </div>
                </div>
            </section>
        
        <?php elseif ($currentPage === 'support'): ?>
            <!-- Support Page Content -->
            <section class="container" style="padding-top: 40px;">
                <div class="section-title">
                    <h2>Customer Support</h2>
                    <p>Comprehensive help for all your needs</p>
                </div>
                
                <div class="contact-container">
                    <h3 style="color: var(--primary); margin-bottom: 20px;">How Can We Help You?</h3>
                    <p style="margin-bottom: 20px; line-height: 1.6;">
                        Our customer support team is dedicated to providing you with the best possible assistance. 
                        Whether you have questions before purchasing, need help with installation, or require 
                        post-purchase support, we're here for you.
                    </p>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; margin: 30px 0;">
                        <div style="border: 1px solid #eee; border-radius: var(--border-radius); padding: 25px; text-align: center; transition: var(--transition);">
                            <div style="font-size: 50px; color: var(--secondary); margin-bottom: 20px;">
                                <i class="fas fa-question-circle"></i>
                            </div>
                            <h4 style="color: var(--primary); margin-bottom: 15px;">FAQ</h4>
                            <p style="margin-bottom: 20px; color: #666;">
                                Find answers to commonly asked questions about our products, shipping, returns, and more.
                            </p>
                            <a href="#" class="btn" style="display: inline-block;">Browse FAQs</a>
                        </div>
                        <div style="border: 1px solid #eee; border-radius: var(--border-radius); padding: 25px; text-align: center; transition: var(--transition);">
                            <div style="font-size: 50px; color: var(--secondary); margin-bottom: 20px;">
                                <i class="fas fa-tools"></i>
                            </div>
                            <h4 style="color: var(--primary); margin-bottom: 15px;">Installation Guides</h4>
                            <p style="margin-bottom: 20px; color: #666;">
                                Step-by-step instructions and video tutorials for installing our products.
                            </p>
                            <a href="#" class="btn" style="display: inline-block;">View Guides</a>
                        </div>
                        <div style="border: 1px solid #eee; border-radius: var(--border-radius); padding: 25px; text-align: center; transition: var(--transition);">
                            <div style="font-size: 50px; color: var(--secondary); margin-bottom: 20px;">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <h4 style="color: var(--primary); margin-bottom: 15px;">Manuals & Documentation</h4>
                            <p style="margin-bottom: 20px; color: #666;">
                                Product manuals, specifications, and technical documentation for all our products.
                            </p>
                            <a href="#" class="btn" style="display: inline-block;">Find Manuals</a>
                        </div>
                    </div>
                    
                    <h4 style="color: var(--primary); margin: 30px 0 15px;">Common Support Topics</h4>
                    <div style="background-color: var(--light); border-radius: var(--border-radius); overflow: hidden;">
                        <div style="padding: 0;">
                            <div style="padding: 15px 20px; border-bottom: 1px solid #ddd; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                                <span>How do I track my order?</span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div style="padding: 15px 20px; border-bottom: 1px solid #ddd; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                                <span>What is your return policy?</span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div style="padding: 15px 20px; border-bottom: 1px solid #ddd; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                                <span>How do I install a toilet seat?</span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div style="padding: 15px 20px; border-bottom: 1px solid #ddd; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                                <span>Do you offer professional installation services?</span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div style="padding: 15px 20px; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                                <span>What should I do if my product arrives damaged?</span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; margin: 30px 0;">
                        <div style="background-color: var(--light); padding: 25px; border-radius: var(--border-radius);">
                            <h4 style="color: var(--primary); margin-bottom: 15px;">Live Chat</h4>
                            <p style="margin-bottom: 20px; color: #666;">
                                Get immediate help from our support team through live chat during business hours.
                            </p>
                            <button class="btn" style="width: 100%;">Start Chat</button>
                        </div>
                        <div style="background-color: var(--light); padding: 25px; border-radius: var(--border-radius);">
                            <h4 style="color: var(--primary); margin-bottom: 15px;">Submit a Ticket</h4>
                            <p style="margin-bottom: 20px; color: #666;">
                                For complex issues, submit a support ticket and we'll get back to you within 24 hours.
                            </p>
                            <button class="btn" style="width: 100%;">Create Ticket</button>
                        </div>
                    </div>
                    
                    <div style="background-color: var(--light); padding: 20px; border-radius: var(--border-radius); margin-top: 30px;">
                        <h4 style="color: var(--primary); margin-bottom: 15px;">Support Hours</h4>
                        <p style="margin-bottom: 10px;"><strong>Phone Support:</strong> Monday-Friday 8am-8pm EST | Saturday-Sunday 9am-5pm EST</p>
                        <p style="margin-bottom: 10px;"><strong>Live Chat:</strong> Monday-Friday 9am-6pm EST</p>
                        <p style="margin-bottom: 10px;"><strong>Email Support:</strong> 24/7 (Response within 24 hours)</p>
                        <p>For emergency issues outside business hours, please leave a detailed message and we'll contact you as soon as possible.</p>
                    </div>
                </div>
            </section>
        
        <?php elseif ($currentPage === 'forum'): ?>
            <!-- Forum Page Content (English Version) -->
            <section class="container" style="padding-top: 40px;">
                <div class="section-title">
                    <h2>Community Forum</h2>
                    <p>Connect with other customers, share experiences, and get help</p>
                </div>
                
                <div class="forum-container">
                    <div class="forum-header">
                        <h3 style="color: var(--primary);">Recent Discussions</h3>
                        <?php if (isset($_SESSION['user'])): ?>
                            <button class="create-topic-btn" id="create-topic-btn">
                                <i class="fas fa-plus"></i> New Topic
                            </button>
                        <?php else: ?>
                            <button class="create-topic-btn" id="login-to-create-topic">
                                <i class="fas fa-sign-in-alt"></i> Login to Post
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Forum Categories -->
                    <div class="forum-categories">
                        <div class="forum-category">
                            <h3>General Discussion</h3>
                            <p>Talk about anything related to toilets, bathrooms, and home improvement.</p>
                            <a href="#" class="btn" style="font-size: 14px;">Browse Topics</a>
                        </div>
                        <div class="forum-category">
                            <h3>Installation Help</h3>
                            <p>Get help with installing toilets, seats, and other bathroom fixtures.</p>
                            <a href="#" class="btn" style="font-size: 14px;">Browse Topics</a>
                        </div>
                        <div class="forum-category">
                            <h3>Maintenance Tips</h3>
                            <p>Share tips and advice for maintaining and cleaning bathroom fixtures.</p>
                            <a href="#" class="btn" style="font-size: 14px;">Browse Topics</a>
                        </div>
                        <div class="forum-category">
                            <h3>Product Reviews</h3>
                            <p>Share your experiences with ToiletPro products and read others' reviews.</p>
                            <a href="#" class="btn" style="font-size: 14px;">Browse Topics</a>
                        </div>
                    </div>
                    
                    <!-- Forum Topics -->
                    <div class="forum-topics">
                        <?php if (empty($forumTopics)): ?>
                            <div style="text-align: center; padding: 40px;">
                                <i class="fas fa-comments" style="font-size: 60px; color: #ddd; margin-bottom: 20px;"></i>
                                <h3 style="color: var(--primary); margin-bottom: 15px;">No topics yet</h3>
                                <p style="color: #666; margin-bottom: 30px;">Be the first to start a discussion!</p>
                                <?php if (isset($_SESSION['user'])): ?>
                                    <button class="create-topic-btn" id="create-first-topic">
                                        <i class="fas fa-plus"></i> Create First Topic
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php foreach ($forumTopics as $topic): ?>
                                <div class="forum-topic">
                                    <a href="?page=forum-topic&id=<?php echo $topic['id']; ?>" class="topic-title">
                                        <?php echo htmlspecialchars($topic['title']); ?>
                                    </a>
                                    <div class="topic-meta">
                                        <span class="topic-author">By <?php echo htmlspecialchars($topic['user_name']); ?></span>
                                        <span class="topic-category"><?php echo htmlspecialchars($topic['category']); ?></span>
                                        <span><?php echo date('M j, Y', strtotime($topic['created_at'])); ?></span>
                                    </div>
                                    <p class="topic-excerpt">
                                        <?php echo strlen($topic['content']) > 150 ? substr($topic['content'], 0, 150) . '...' : $topic['content']; ?>
                                    </p>
                                    <div class="topic-stats">
                                        <div class="stat">
                                            <i class="fas fa-eye"></i>
                                            <span><?php echo $topic['views'] ?? 0; ?> views</span>
                                        </div>
                                        <div class="stat">
                                            <i class="fas fa-comment"></i>
                                            <span><?php echo isset($topic['replies']) ? count($topic['replies']) : 0; ?> replies</span>
                                        </div>
                                        <div class="stat">
                                            <i class="fas fa-clock"></i>
                                            <span>Last activity: <?php echo $topic['last_reply'] ? date('M j, Y', strtotime($topic['last_reply'])) : 'No replies yet'; ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Forum Rules -->
                    <div style="background-color: var(--light); padding: 20px; border-radius: var(--border-radius); margin-top: 40px;">
                        <h4 style="color: var(--primary); margin-bottom: 15px;">Forum Rules</h4>
                        <ul style="padding-left: 20px; color: #666;">
                            <li style="margin-bottom: 8px;">Be respectful to other members</li>
                            <li style="margin-bottom: 8px;">Stay on topic and post in the appropriate category</li>
                            <li style="margin-bottom: 8px;">No spam, advertising, or self-promotion</li>
                            <li style="margin-bottom: 8px;">Do not post personal information</li>
                            <li style="margin-bottom: 8px;">Use English language for all posts</li>
                            <li>Report any inappropriate content to moderators</li>
                        </ul>
                    </div>
                </div>
            </section>
        
        <?php elseif ($currentPage === 'forum-topic' && isset($_GET['id'])): ?>
            <!-- Individual Forum Topic Page -->
            <?php
            $topicId = $_GET['id'];
            $topic = null;
            $replies = [];
            
            if (file_exists(__DIR__ . '/data/forum_topics.json')) {
                $topics = json_decode(file_get_contents(__DIR__ . '/data/forum_topics.json'), true);
                foreach ($topics as $t) {
                    if ($t['id'] === $topicId) {
                        $topic = $t;
                        $replies = $t['replies'] ?? [];
                        break;
                    }
                }
            }
            
            if (!$topic): ?>
                <section class="container" style="padding-top: 40px;">
                    <div class="section-title">
                        <h2>Topic Not Found</h2>
                    </div>
                    <div class="forum-container" style="text-align: center; padding: 60px;">
                        <i class="fas fa-exclamation-circle" style="font-size: 60px; color: var(--danger); margin-bottom: 20px;"></i>
                        <h3 style="color: var(--primary); margin-bottom: 15px;">Topic Not Found</h3>
                        <p style="color: #666; margin-bottom: 30px;">The topic you're looking for doesn't exist or has been removed.</p>
                        <a href="?page=forum" class="btn" style="padding: 12px 30px;">Back to Forum</a>
                    </div>
                </section>
            <?php else: ?>
                <section class="container" style="padding-top: 40px;">
                    <div class="section-title">
                        <h2>Forum Topic</h2>
                        <p>Join the discussion</p>
                    </div>
                    
                    <div class="forum-container">
                        <!-- Topic Details -->
                        <div class="topic-details">
                            <h2><?php echo htmlspecialchars($topic['title']); ?></h2>
                            <div class="topic-details-meta">
                                <span><strong>Author:</strong> <?php echo htmlspecialchars($topic['user_name']); ?></span>
                                <span><strong>Category:</strong> <?php echo htmlspecialchars($topic['category']); ?></span>
                                <span><strong>Posted:</strong> <?php echo date('F j, Y, g:i a', strtotime($topic['created_at'])); ?></span>
                            </div>
                            <div style="color: #444; line-height: 1.6;">
                                <?php echo nl2br(htmlspecialchars($topic['content'])); ?>
                            </div>
                        </div>
                        
                        <!-- Replies -->
                        <h3 style="color: var(--primary); margin-bottom: 20px;">
                            Replies (<?php echo count($replies); ?>)
                                                    </div>
                        
                        <!-- Replies -->
                        <h3 style="color: var(--primary); margin-bottom: 20px;">
                            Replies (<?php echo count($replies); ?>)
                        </h3>
                        
                        <?php if (empty($replies)): ?>
                            <div style="text-align: center; padding: 30px; background-color: var(--light); border-radius: var(--border-radius);">
                                <i class="fas fa-comment-slash" style="font-size: 50px; color: #ccc; margin-bottom: 15px;"></i>
                                <p style="color: #666;">No replies yet. Be the first to reply!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($replies as $reply): ?>
                                <div class="forum-reply">
                                    <div class="reply-author">
                                        <div class="reply-author-avatar">
                                            <?php echo strtoupper(substr($reply['user_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="reply-author-name"><?php echo htmlspecialchars($reply['user_name']); ?></div>
                                            <div class="reply-date"><?php echo date('F j, Y, g:i a', strtotime($reply['created_at'])); ?></div>
                                        </div>
                                    </div>
                                    <div class="reply-content">
                                        <?php echo nl2br(htmlspecialchars($reply['content'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <!-- Reply Form -->
                        <?php if (isset($_SESSION['user'])): ?>
                            <div class="reply-form">
                                <h4 style="color: var(--primary); margin-bottom: 20px;">Post a Reply</h4>
                                <form id="reply-form">
                                    <input type="hidden" id="reply-topic-id" value="<?php echo $topicId; ?>">
                                    <div class="form-group">
                                        <textarea id="reply-content" class="form-control" rows="6" placeholder="Type your reply here..." required></textarea>
                                    </div>
                                    <button type="submit" class="btn-submit">Post Reply</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div style="background-color: var(--light); padding: 25px; border-radius: var(--border-radius); text-align: center; margin-top: 30px;">
                                <h4 style="color: var(--primary); margin-bottom: 15px;">Join the Discussion</h4>
                                <p style="color: #666; margin-bottom: 20px;">You need to be logged in to post a reply.</p>
                                <button class="btn" id="login-to-reply" style="padding: 10px 25px;">Login to Reply</button>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Navigation -->
                        <div style="display: flex; justify-content: space-between; margin-top: 30px;">
                            <a href="?page=forum" class="btn" style="padding: 10px 20px;">
                                <i class="fas fa-arrow-left"></i> Back to Forum
                            </a>
                            <?php if (isset($_SESSION['user']) && $_SESSION['user']['id'] == $topic['user_id']): ?>
                                <button class="btn" style="padding: 10px 20px; background-color: var(--warning);">
                                    <i class="fas fa-edit"></i> Edit Topic
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        <?php elseif ($currentPage === 'admin'): ?>
    <!-- Admin Dashboard -->
    <section class="container" style="padding-top: 40px;">
        <div class="section-title">
            <h2>Admin Dashboard</h2>
            <p>Manage your product catalog</p>
        </div>
        
        <?php if (!isAdminLoggedIn()): ?>
            <div class="contact-container" style="text-align: center; padding: 60px;">
                <i class="fas fa-lock" style="font-size: 60px; color: var(--primary); margin-bottom: 20px;"></i>
                <h3 style="color: var(--primary); margin-bottom: 15px;">Admin Login Required</h3>
                <p style="color: #666; margin-bottom: 30px;">Please login to access the admin dashboard.</p>
                <button class="btn" id="admin-login-btn" style="padding: 12px 30px;">
                    <i class="fas fa-sign-in-alt"></i> Admin Login
                </button>
            </div>
        <?php else: ?>
            <div class="contact-container">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
                    <div>
                        <h3 style="color: var(--primary); margin-bottom: 5px;">Welcome, Admin!</h3>
                        <p style="color: #666;">Manage your product catalog below</p>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn" id="add-product-btn" style="background-color: var(--success);">
                            <i class="fas fa-plus"></i> Add New Product
                        </button>
                        <button class="btn" id="admin-logout-btn" style="background-color: var(--danger);">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </button>
                    </div>
                </div>
                
                <!-- Products Table -->
                <div style="overflow-x: auto;">
                    <table class="contact-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Image</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="admin-products-table">
                            <?php 
                            $adminProducts = getProductsFromJson();
                            if (empty($adminProducts)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px;">
                                        <i class="fas fa-box-open" style="font-size: 40px; color: #ddd; margin-bottom: 10px;"></i>
                                        <p style="color: #666;">No products found. Add your first product!</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($adminProducts as $product): ?>
                                <tr data-id="<?php echo $product['id']; ?>">
                                    <td><?php echo $product['id']; ?></td>
                                    <td>
                                        <?php if (!empty($product['image'])): ?>
                                            <img src="<?php echo htmlspecialchars($product['image']); ?>" 
                                                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                                                 style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;">
                                        <?php else: ?>
                                            <div style="width: 50px; height: 50px; background-color: #f0f0f0; border-radius: 4px; display: flex; align-items: center; justify-content: center;">
                                                <i class="fas fa-image" style="color: #ccc;"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($product['name']); ?></td>
                                    <td>
                                        <span style="background-color: #e8f4fc; color: var(--secondary); padding: 4px 8px; border-radius: 12px; font-size: 12px;">
                                            <?php echo htmlspecialchars($product['category']); ?>
                                        </span>
                                    </td>
                                    <td>$<?php echo number_format($product['price'], 2); ?></td>
                                    <td><?php echo $product['stock'] ?? 0; ?></td>
                                    <td>
                                        <div style="display: flex; gap: 5px;">
                                            <button class="btn edit-product-btn" 
                                                    data-id="<?php echo $product['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($product['name']); ?>"
                                                    data-description="<?php echo htmlspecialchars($product['description']); ?>"
                                                    data-price="<?php echo $product['price']; ?>"
                                                    data-category="<?php echo htmlspecialchars($product['category']); ?>"
                                                    data-image="<?php echo htmlspecialchars($product['image'] ?? ''); ?>"
                                                    data-badge="<?php echo htmlspecialchars($product['badge'] ?? ''); ?>"
                                                    data-stock="<?php echo $product['stock'] ?? 0; ?>"
                                                    style="padding: 5px 10px; font-size: 12px; background-color: var(--warning);">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn delete-product-btn" 
                                                    data-id="<?php echo $product['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($product['name']); ?>"
                                                    style="padding: 5px 10px; font-size: 12px; background-color: var(--danger);">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="margin-top: 30px; padding: 20px; background-color: var(--light); border-radius: var(--border-radius);">
                    <h4 style="color: var(--primary); margin-bottom: 15px;">Quick Stats</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                        <div style="text-align: center; background-color: white; padding: 15px; border-radius: var(--border-radius);">
                            <div style="font-size: 24px; font-weight: bold; color: var(--primary);">
                                <?php echo count($adminProducts); ?>
                            </div>
                            <div style="font-size: 14px; color: #666;">Total Products</div>
                        </div>
                        <div style="text-align: center; background-color: white; padding: 15px; border-radius: var(--border-radius);">
                            <div style="font-size: 24px; font-weight: bold; color: var(--success);">
                                $<?php 
                                    $totalValue = 0;
                                    foreach ($adminProducts as $product) {
                                        $totalValue += $product['price'] * ($product['stock'] ?? 0);
                                    }
                                    echo number_format($totalValue, 2);
                                ?>
                            </div>
                            <div style="font-size: 14px; color: #666;">Total Inventory Value</div>
                        </div>
                        <div style="text-align: center; background-color: white; padding: 15px; border-radius: var(--border-radius);">
                            <div style="font-size: 24px; font-weight: bold; color: var(--secondary);">
                                <?php 
                                    $categories = [];
                                    foreach ($adminProducts as $product) {
                                        $category = $product['category'];
                                        if (!isset($categories[$category])) {
                                            $categories[$category] = 0;
                                        }
                                        $categories[$category]++;
                                    }
                                    echo count($categories);
                                ?>
                            </div>
                            <div style="font-size: 14px; color: #666;">Categories</div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </section>
        <?php elseif ($currentPage === 'careers'): ?>
            <!-- Careers Page Content (English Version) -->
            <section class="container" style="padding-top: 40px;">
                <div class="section-title">
                    <h2>Careers at ToiletPro</h2>
                    <p>Join our team and help revolutionize the bathroom experience</p>
                </div>
                
                <div class="careers-container">
                    <h3 style="color: var(--primary); margin-bottom: 20px;">Why Work at ToiletPro?</h3>
                    <p style="margin-bottom: 20px; line-height: 1.6;">
                        At ToiletPro, we're passionate about creating the best bathroom products and experiences for our customers. 
                        We're a dynamic, fast-growing company looking for talented individuals who share our commitment to 
                        innovation, quality, and customer satisfaction.
                    </p>
                    
                    <div class="benefits-section">
                        <h4 style="color: var(--primary); margin-bottom: 20px;">Employee Benefits</h4>
                        <div class="benefits-grid">
                            <div class="benefit-item">
                                <div class="benefit-icon">
                                    <i class="fas fa-heartbeat"></i>
                                </div>
                                <h4>Health & Wellness</h4>
                                <p>Comprehensive health, dental, and vision insurance for you and your family</p>
                            </div>
                            <div class="benefit-item">
                                <div class="benefit-icon">
                                    <i class="fas fa-coins"></i>
                                </div>
                                <h4>Competitive Pay</h4>
                                <p>Industry-competitive salaries with performance-based bonuses and incentives</p>
                            </div>
                            <div class="benefit-item">
                                <div class="benefit-icon">
                                    <i class="fas fa-graduation-cap"></i>
                                </div>
                                <h4>Professional Growth</h4>
                                <p>Training programs, workshops, and opportunities for career advancement</p>
                            </div>
                            <div class="benefit-item">
                                <div class="benefit-icon">
                                    <i class="fas fa-umbrella-beach"></i>
                                </div>
                                <h4>Work-Life Balance</h4>
                                <p>Generous paid time off, flexible schedules, and remote work options</p>
                            </div>
                        </div>
                    </div>
                    
                    <h4 style="color: var(--primary); margin: 40px 0 20px;">Current Openings</h4>
                    <p style="margin-bottom: 20px; color: #666;">
                        Explore our current job opportunities and find the perfect fit for your skills and career goals.
                    </p>
                    
                    <div class="job-listings">
                        <!-- Job Listing 1 -->
                        <div class="job-card">
                            <div class="job-header">
                                <div>
                                    <h3 class="job-title">Product Development Engineer</h3>
                                    <p class="job-department">Engineering Department</p>
                                    <p class="job-location"><i class="fas fa-map-marker-alt"></i> New York, NY</p>
                                </div>
                                <span class="job-badge">Full-time</span>
                            </div>
                            <p class="job-description">
                                We're looking for a creative Product Development Engineer to design and develop innovative 
                                toilet products and bathroom solutions. You'll work closely with our design and engineering 
                                teams to bring new products from concept to market.
                            </p>
                            <div class="job-requirements">
                                <h4>Requirements:</h4>
                                <ul>
                                    <li>Bachelor's degree in Mechanical Engineering or related field</li>
                                    <li>3+ years of product development experience</li>
                                    <li>Proficiency in CAD software (SolidWorks preferred)</li>
                                    <li>Experience with prototyping and testing</li>
                                    <li>Strong problem-solving and communication skills</li>
                                </ul>
                            </div>
                            <div class="job-footer">
                                <span class="job-type">Full-time | On-site</span>
                                <button class="apply-btn">Apply Now</button>
                            </div>
                        </div>
                        
                        <!-- Job Listing 2 -->
                        <div class="job-card">
                            <div class="job-header">
                                <div>
                                    <h3 class="job-title">Customer Support Specialist</h3>
                                    <p class="job-department">Customer Service Department</p>
                                    <p class="job-location"><i class="fas fa-map-marker-alt"></i> Remote (USA)</p>
                                </div>
                                <span class="job-badge">Full-time</span>
                            </div>
                            <p class="job-description">
                                Join our customer service team and help customers with product inquiries, technical issues, 
                                and installation questions. You'll be the frontline representative of ToiletPro, ensuring 
                                our customers have exceptional experiences.
                            </p>
                            <div class="job-requirements">
                                <h4>Requirements:</h4>
                                <ul>
                                    <li>2+ years of customer service experience</li>
                                    <li>Excellent verbal and written communication skills</li>
                                    <li>Technical aptitude and ability to troubleshoot issues</li>
                                    <li>Patience and empathy when dealing with customer concerns</li>
                                    <li>Ability to work flexible hours including weekends</li>
                                </ul>
                            </div>
                            <div class="job-footer">
                                <span class="job-type">Full-time | Remote</span>
                                <button class="apply-btn">Apply Now</button>
                            </div>
                        </div>
                        
                        <!-- Job Listing 3 -->
                        <div class="job-card">
                            <div class="job-header">
                                <div>
                                    <h3 class="job-title">Digital Marketing Manager</h3>
                                    <p class="job-department">Marketing Department</p>
                                    <p class="job-location"><i class="fas fa-map-marker-alt"></i> Chicago, IL</p>
                                </div>
                                <span class="job-badge">Full-time</span>
                            </div>
                            <p class="job-description">
                                We need a creative Digital Marketing Manager to develop and execute our online marketing 
                                strategies. You'll oversee our social media presence, email campaigns, SEO, and digital 
                                advertising to drive brand awareness and sales.
                            </p>
                            <div class="job-requirements">
                                <h4>Requirements:</h4>
                                <ul>
                                    <li>Bachelor's degree in Marketing or related field</li>
                                    <li>5+ years of digital marketing experience</li>
                                    <li>Proven track record with SEO, SEM, and social media marketing</li>
                                    <li>Experience with marketing analytics tools</li>
                                    <li>Creative mindset with strong analytical skills</li>
                                </ul>
                            </div>
                            <div class="job-footer">
                                <span class="job-type">Full-time | Hybrid</span>
                                <button class="apply-btn">Apply Now</button>
                            </div>
                        </div>
                        
                        <!-- Job Listing 4 -->
                        <div class="job-card">
                            <div class="job-header">
                                <div>
                                    <h3 class="job-title">Warehouse Operations Supervisor</h3>
                                    <p class="job-department">Operations Department</p>
                                    <p class="job-location"><i class="fas fa-map-marker-alt"></i> Los Angeles, CA</p>
                                </div>
                                <span class="job-badge">Full-time</span>
                            </div>
                            <p class="job-description">
                                Oversee daily operations at our Los Angeles distribution center. You'll manage a team, 
                                optimize warehouse processes, ensure inventory accuracy, and maintain high standards 
                                for order fulfillment and shipping.
                            </p>
                            <div class="job-requirements">
                                <h4>Requirements:</h4>
                                <ul>
                                    <li>3+ years of warehouse or logistics management experience</li>
                                    <li>Experience with warehouse management systems</li>
                                    <li>Strong leadership and team management skills</li>
                                    <li>Knowledge of safety regulations and best practices</li>
                                    <li>Ability to lift up to 50 pounds and work in a fast-paced environment</li>
                                </ul>
                            </div>
                            <div class="job-footer">
                                <span class="job-type">Full-time | On-site</span>
                                <button class="apply-btn">Apply Now</button>
                            </div>
                        </div>
                    </div>
                    
                    <div style="background-color: var(--light); padding: 25px; border-radius: var(--border-radius); margin-top: 40px;">
                        <h4 style="color: var(--primary); margin-bottom: 15px;">Application Process</h4>
                        <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 20px; flex-wrap: wrap;">
                            <div style="text-align: center; flex: 1; min-width: 200px;">
                                <div style="width: 60px; height: 60px; background-color: var(--secondary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; font-weight: bold; font-size: 20px;">1</div>
                                <p><strong>Submit Application</strong></p>
                                <p style="color: #666; font-size: 14px;">Complete our online application form</p>
                            </div>
                            <div style="text-align: center; flex: 1; min-width: 200px;">
                                <div style="width: 60px; height: 60px; background-color: var(--secondary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; font-weight: bold; font-size: 20px;">2</div>
                                <p><strong>Initial Screening</strong></p>
                                <p style="color: #666; font-size: 14px;">Phone interview with HR</p>
                            </div>
                            <div style="text-align: center; flex: 1; min-width: 200px;">
                                <div style="width: 60px; height: 60px; background-color: var(--secondary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; font-weight: bold; font-size: 20px;">3</div>
                                <p><strong>Interviews</strong></p>
                                <p style="color: #666; font-size: 14px;">Meet with hiring manager and team</p>
                            </div>
                            <div style="text-align: center; flex: 1; min-width: 200px;">
                                <div style="width: 60px; height: 60px; background-color: var(--secondary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; font-weight: bold; font-size: 20px;">4</div>
                                <p><strong>Offer & Onboarding</strong></p>
                                <p style="color: #666; font-size: 14px;">Receive offer and join our team</p>
                            </div>
                        </div>
                    </div>
                    
                    <div style="text-align: center; margin-top: 40px; padding-top: 30px; border-top: 1px solid #eee;">
                        <h4 style="color: var(--primary); margin-bottom: 15px;">Don't See the Perfect Role?</h4>
                        <p style="color: #666; margin-bottom: 20px;">
                            We're always looking for talented individuals. Send us your resume and we'll keep you in mind 
                            for future opportunities that match your skills and experience.
                        </p>
                        <button class="btn" style="padding: 12px 30px;">Submit General Application</button>
                    </div>
                </div>
            </section>
        
        <?php endif; ?>
    </main>
    
    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-column">
                    <h3>ToiletPro</h3>
                    <p>Premium toilet and accessory store dedicated to providing high-quality bathroom solutions for every home.</p>
                </div>
                
                <div class="footer-column">
                    <h3>Quick Links</h3>
                    <div class="contact-info">
                        <a href="?page=home" style="color: #ccc; text-decoration: none; display: block; margin-bottom: 10px;">Home</a>
                        <a href="?page=products" style="color: #ccc; text-decoration: none; display: block; margin-bottom: 10px;">Products</a>
                        <a href="?page=contact-list" style="color: #ccc; text-decoration: none; display: block; margin-bottom: 10px;">Contact List</a>
                        <a href="?page=careers" style="color: #ccc; text-decoration: none; display: block; margin-bottom: 10px;">Careers</a>
                        <a href="?page=forum" style="color: #ccc; text-decoration: none; display: block; margin-bottom: 10px;">Forum</a>
                        <a href="?page=support" style="color: #ccc; text-decoration: none; display: block; margin-bottom: 10px;">Support</a>
                    </div>
                </div>
                
                <div class="footer-column">
                    <h3>Contact Us</h3>
                    <div class="contact-info">
                        <div class="contact-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <span>123 Main Street, New York, NY 10001</span>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-phone-alt"></i>
                            <span>1.800-TOILETPRO</span>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-envelope"></i>
                            <span>info@toiletpro.com</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> ToiletPro. All rights reserved.</p>
            </div>
        </div>
    </footer>
    
    <script>
        // JavaScript functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Settings functionality
            const settingsToggle = document.getElementById('settings-toggle');
            const settingsPanel = document.getElementById('settings-panel');
            
            if (settingsToggle && settingsPanel) {
                settingsToggle.addEventListener('click', function() {
                    settingsPanel.classList.toggle('open');
                });
                
                // Close settings panel when clicking outside
                document.addEventListener('click', function(e) {
                    if (!settingsPanel.contains(e.target) && !settingsToggle.contains(e.target)) {
                        settingsPanel.classList.remove('open');
                    }
                });
            }
            
            // Font size settings
            const fontSizeOptions = document.querySelectorAll('.settings-option[data-value]');
            fontSizeOptions.forEach(option => {
                option.addEventListener('click', function() {
                    const value = this.getAttribute('data-value');
                    const group = this.closest('.settings-group');
                    
                    // Remove active class from all options in group
                    group.querySelectorAll('.settings-option').forEach(opt => {
                        opt.classList.remove('active');
                    });
                    
                    // Add active class to clicked option
                    this.classList.add('active');
                    
                    // Determine if it's font size or theme
                    if (group.querySelector('h4').textContent === 'Font Size') {
                        updatePreferences('font_size', value);
                    } else if (group.querySelector('h4').textContent === 'Color Theme') {
                        updatePreferences('theme', value);
                    }
                });
            });
            
            // Reset settings
            const resetSettingsBtn = document.getElementById('reset-settings');
            if (resetSettingsBtn) {
                resetSettingsBtn.addEventListener('click', function() {
                    updatePreferences('font_size', 'medium');
                    updatePreferences('theme', 'default');
                    
                    // Update UI
                    document.querySelectorAll('.settings-option[data-value="medium"]').forEach(opt => {
                        opt.classList.add('active');
                    });
                    document.querySelectorAll('.settings-option[data-value="default"]').forEach(opt => {
                        opt.classList.add('active');
                    });
                    
                    // Remove active class from others
                    document.querySelectorAll('.settings-option[data-value="small"]').forEach(opt => {
                        opt.classList.remove('active');
                    });
                    document.querySelectorAll('.settings-option[data-value="large"]').forEach(opt => {
                        opt.classList.remove('active');
                    });
                    document.querySelectorAll('.settings-option[data-value="dark"]').forEach(opt => {
                        opt.classList.remove('active');
                    });
                    document.querySelectorAll('.settings-option[data-value="blue"]').forEach(opt => {
                        opt.classList.remove('active');
                    });
                });
            }
            
            // Update preferences function
            function updatePreferences(type, value) {
                const formData = new FormData();
                formData.append('action', 'update_preferences');
                formData.append(type, value);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Reload page to apply changes
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred. Please try again.', 'error');
                });
            }
            
            // Search functionality
            const searchInput = document.getElementById('search-input');
            const searchResults = document.getElementById('search-results');
            
            if (searchInput && searchResults) {
                let searchTimeout;
                
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    const searchTerm = this.value.trim();
                    
                    if (searchTerm.length < 2) {
                        searchResults.style.display = 'none';
                        return;
                    }
                    
                    searchTimeout = setTimeout(() => {
                        const formData = new FormData();
                        formData.append('action', 'search_products');
                        formData.append('search_term', searchTerm);
                        
                        fetch('', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                displaySearchResults(data.results, searchTerm);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                        });
                    }, 300);
                });
                
                // Close search results when clicking outside
                document.addEventListener('click', function(e) {
                    if (!searchResults.contains(e.target) && !searchInput.contains(e.target)) {
                        searchResults.style.display = 'none';
                    }
                });
            }
            
            function displaySearchResults(results, searchTerm) {
                if (results.length === 0) {
                    searchResults.innerHTML = '<div style="padding: 20px; text-align: center; color: #666;">No products found for "' + searchTerm + '"</div>';
                    searchResults.style.display = 'block';
                    return;
                }
                
                let html = '<div style="padding: 10px;">';
                html += '<div style="font-weight: bold; color: var(--primary); margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #eee;">Search Results (' + results.length + ')</div>';
                
                results.forEach(product => {
                    html += '<a href="?page=products" style="display: flex; align-items: center; padding: 10px; border-bottom: 1px solid #f0f0f0; text-decoration: none; color: #333; transition: background-color 0.2s;">';
                    html += '<div style="width: 50px; height: 50px; margin-right: 10px; border-radius: 4px; overflow: hidden;">';
                    html += '<img src="' + product.image + '" alt="' + product.name + '" style="width: 100%; height: 100%; object-fit: cover;">';
                    html += '</div>';
                    html += '<div>';
                    html += '<div style="font-weight: 500; color: var(--primary);">' + product.name + '</div>';
                    html += '<div style="font-size: 12px; color: var(--secondary); margin-top: 2px;">' + product.category + '</div>';
                    html += '<div style="font-weight: bold; color: var(--primary); margin-top: 5px;">$' + parseFloat(product.price).toFixed(2) + '</div>';
                    html += '</div>';
                    html += '</a>';
                });
                
                html += '</div>';
                searchResults.innerHTML = html;
                searchResults.style.display = 'block';
            }
            
            // Login functionality
            document.getElementById('login-form').addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData();
                formData.append('action', 'login');
                formData.append('email', document.getElementById('login-email').value);
                formData.append('password', document.getElementById('login-password').value);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message);
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        showNotification(data.message, 'error');
                    document.getElementById('login-password').value = '';
                    document.getElementById('login-password').focus();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred. Please try again.', 'error');
                });
            });

            // Register functionality
            document.getElementById('register-form').addEventListener('submit', function(e) {
                e.preventDefault();
                const password = document.getElementById('register-password').value;
                const confirmPassword = document.getElementById('register-confirm').value;
                
                if (password !== confirmPassword) {
                    showNotification('Passwords do not match', 'error');
                    document.getElementById('register-confirm').value = '';
                    document.getElementById('register-confirm').focus();
                    return;
                }
                
                if (!document.getElementById('agree-terms').checked) {
                    showNotification('Please agree to the terms and conditions', 'error');
                    return;
                }
                
                const formData = new FormData();
                formData.append('action', 'register');
                formData.append('name', document.getElementById('register-name').value);
                formData.append('email', document.getElementById('register-email').value);
                formData.append('password', password);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message);
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        showNotification(data.message, 'error');
                        if (data.message.includes('Email')) {
                            document.getElementById('register-email').value = '';
                            document.getElementById('register-email').focus();
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred. Please try again.', 'error');
                });
            });

            // Add to cart functionality
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('add-to-cart') || e.target.closest('.add-to-cart')) {
                    const button = e.target.classList.contains('add-to-cart') ? e.target : e.target.closest('.add-to-cart');
                    const productId = button.getAttribute('data-id');
                    const formData = new FormData();
                    formData.append('action', 'add_to_cart');
                    formData.append('product_id', productId);
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message);
                            setTimeout(() => {
                                location.reload();
                            }, 1000);
                        } else {
                            showNotification(data.message, 'error');
                            if (data.message === 'Please login first') {
                                document.getElementById('login-modal').style.display = 'flex';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('An error occurred. Please try again.', 'error');
                    });
                }
            });

            // Cart quantity functionality
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('increase-quantity') || e.target.closest('.increase-quantity')) {
                    const button = e.target.classList.contains('increase-quantity') ? e.target : e.target.closest('.increase-quantity');
                    const productId = button.getAttribute('data-id');
                    const input = document.querySelector(`.quantity-input[data-id="${productId}"]`);
                    input.value = parseInt(input.value) + 1;
                    updateCartQuantity(productId, input.value);
                }
                
                if (e.target.classList.contains('decrease-quantity') || e.target.closest('.decrease-quantity')) {
                    const button = e.target.classList.contains('decrease-quantity') ? e.target : e.target.closest('.decrease-quantity');
                    const productId = button.getAttribute('data-id');
                    const input = document.querySelector(`.quantity-input[data-id="${productId}"]`);
                    if (parseInt(input.value) > 1) {
                        input.value = parseInt(input.value) - 1;
                        updateCartQuantity(productId, input.value);
                    }
                }
            });

            // Update cart quantity
            function updateCartQuantity(productId, quantity) {
                const formData = new FormData();
                formData.append('action', 'update_cart');
                formData.append('product_id', productId);
                formData.append('quantity', quantity);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred. Please try again.', 'error');
                });
            }

            // Remove from cart
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('remove-from-cart') || e.target.closest('.remove-from-cart')) {
                    const button = e.target.classList.contains('remove-from-cart') ? e.target : e.target.closest('.remove-from-cart');
                    const productId = button.getAttribute('data-id');
                    
                    const formData = new FormData();
                    formData.append('action', 'remove_from_cart');
                    formData.append('product_id', productId);
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification('Product removed from cart');
                            setTimeout(() => {
                                location.reload();
                            }, 1000);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('An error occurred. Please try again.', 'error');
                    });
                }
            });

            // Checkout functionality
            document.addEventListener('click', function(e) {
                if (e.target.id === 'checkout-btn' || e.target.closest('#checkout-btn')) {
                    const formData = new FormData();
                    formData.append('action', 'place_order');
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message);
                            setTimeout(() => {
                                // Jump to order confirmation page with order number
                                location.href = '?page=order-confirmation&order=' + data.order_number;
                            }, 1500);
                        } else {
                            showNotification(data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('An error occurred. Please try again.', 'error');
                    });
                }
                
                if (e.target.id === 'checkout-login-btn' || e.target.closest('#checkout-login-btn')) {
                    document.getElementById('login-modal').style.display = 'flex';
                }
            });

            // Logout functionality
            if (document.getElementById('logout-btn')) {
                document.getElementById('logout-btn').addEventListener('click', function() {
                    const formData = new FormData();
                    formData.append('action', 'logout');
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message);
                            setTimeout(() => {
                                location.reload();
                            }, 1000);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        location.reload();
                    });
                });
            }

            // Forum functionality
            // Create topic button
            const createTopicBtn = document.getElementById('create-topic-btn');
            const createFirstTopicBtn = document.getElementById('create-first-topic');
            const loginToCreateTopicBtn = document.getElementById('login-to-create-topic');
            
            if (createTopicBtn) {
                createTopicBtn.addEventListener('click', function() {
                    document.getElementById('create-topic-modal').style.display = 'flex';
                });
            }
            
            if (createFirstTopicBtn) {
                createFirstTopicBtn.addEventListener('click', function() {
                    document.getElementById('create-topic-modal').style.display = 'flex';
                });
            }
            
            if (loginToCreateTopicBtn) {
                loginToCreateTopicBtn.addEventListener('click', function() {
                    document.getElementById('login-modal').style.display = 'flex';
                });
            }
            
            // Create topic form
            const createTopicForm = document.getElementById('create-topic-form');
            if (createTopicForm) {
                createTopicForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const title = document.getElementById('topic-title').value;
                    const content = document.getElementById('topic-content').value;
                    const category = document.getElementById('topic-category').value;
                    
                    const formData = new FormData();
                    formData.append('action', 'create_forum_topic');
                    formData.append('title', title);
                    formData.append('content', content);
                    formData.append('category', category);
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message);
                            document.getElementById('create-topic-modal').style.display = 'none';
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            showNotification(data.message, 'error');
                            if (data.message === 'Please login first') {
                                document.getElementById('create-topic-modal').style.display = 'none';
                                document.getElementById('login-modal').style.display = 'flex';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('An error occurred. Please try again.', 'error');
                    });
                });
            }
            
            // Reply form
            const replyForm = document.getElementById('reply-form');
            if (replyForm) {
                replyForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const topicId = document.getElementById('reply-topic-id').value;
                    const content = document.getElementById('reply-content').value;
                    
                    const formData = new FormData();
                    formData.append('action', 'add_forum_reply');
                    formData.append('topic_id', topicId);
                    formData.append('content', content);
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message);
                            document.getElementById('reply-content').value = '';
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            showNotification(data.message, 'error');
                            if (data.message === 'Please login first') {
                                document.getElementById('login-modal').style.display = 'flex';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('An error occurred. Please try again.', 'error');
                    });
                });
            }
            
            // Login to reply button
            const loginToReplyBtn = document.getElementById('login-to-reply');
            if (loginToReplyBtn) {
                loginToReplyBtn.addEventListener('click', function() {
                    document.getElementById('login-modal').style.display = 'flex';
                });
            }

            // Careers Apply buttons functionality
            const applyButtons = document.querySelectorAll('.apply-btn');
            applyButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const jobTitle = this.closest('.job-card').querySelector('.job-title').textContent;
                    showNotification('Application for "' + jobTitle + '" will open soon!');
                    
                    // In a real application, this would redirect to an application form
                    // For now, we'll just show a notification
                });
            });

            // Modal functionality
            document.getElementById('login-btn').addEventListener('click', function() {
                document.getElementById('login-modal').style.display = 'flex';
            });

            document.getElementById('register-btn').addEventListener('click', function() {
                document.getElementById('register-modal').style.display = 'flex';
            });

            document.getElementById('close-login').addEventListener('click', function() {
                document.getElementById('login-modal').style.display = 'none';
            });

            document.getElementById('close-register').addEventListener('click', function() {
                document.getElementById('register-modal').style.display = 'none';
            });

            const closeCreateTopicBtn = document.getElementById('close-create-topic');
            if (closeCreateTopicBtn) {
                closeCreateTopicBtn.addEventListener('click', function() {
                    document.getElementById('create-topic-modal').style.display = 'none';
                });
            }

            document.getElementById('switch-to-register').addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('login-modal').style.display = 'none';
                document.getElementById('register-modal').style.display = 'flex';
            });

            document.getElementById('switch-to-login').addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('register-modal').style.display = 'none';
                document.getElementById('login-modal').style.display = 'flex';
            });

            // Product Detail Modal functionality
            // View product detail when clicking product image
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('view-product-detail') || e.target.closest('.view-product-detail')) {
                    const img = e.target.classList.contains('view-product-detail') ? e.target : e.target.closest('.view-product-detail');
                    
                    // Get product data from data attributes
                    const productId = img.getAttribute('data-id');
                    const productName = img.getAttribute('data-name');
                    const productDescription = img.getAttribute('data-description');
                    const productPrice = img.getAttribute('data-price');
                    const productCategory = img.getAttribute('data-category');
                    const productImage = img.getAttribute('data-image');
                    const productBadge = img.getAttribute('data-badge');
                    const productStock = img.getAttribute('data-stock');
                    
                    // Set modal content
                    document.getElementById('product-detail-title').textContent = productName;
                    document.getElementById('product-detail-name').textContent = productName;
                    document.getElementById('product-detail-category').textContent = productCategory;
                    document.getElementById('product-detail-price').textContent = '$' + parseFloat(productPrice).toFixed(2);
                    document.getElementById('product-detail-description').textContent = productDescription;
                    document.getElementById('product-detail-image').src = productImage;
                    document.getElementById('product-detail-image').alt = productName;
                    
                    // Set badge if exists
                    const badgeElement = document.getElementById('product-detail-badge');
                    if (productBadge && productBadge.trim() !== '') {
                        badgeElement.textContent = productBadge;
                        badgeElement.style.display = 'inline-block';
                    } else {
                        badgeElement.style.display = 'none';
                    }
                    
                    // Set stock
                    const stockElement = document.getElementById('stock-count');
                    stockElement.textContent = productStock;
                    
                    // Set add to cart button data
                    const addToCartBtn = document.getElementById('product-detail-add-to-cart');
                    addToCartBtn.setAttribute('data-id', productId);
                    addToCartBtn.setAttribute('data-name', productName);
                    addToCartBtn.setAttribute('data-price', productPrice);
                    addToCartBtn.setAttribute('data-category', productCategory);
                    addToCartBtn.setAttribute('data-image', productImage);
                    
                    // Show modal
                    document.getElementById('product-detail-modal').style.display = 'flex';
                }
            });
            
            // Close product detail modal
            const closeProductDetailBtn = document.getElementById('close-product-detail');
            if (closeProductDetailBtn) {
                closeProductDetailBtn.addEventListener('click', function() {
                    document.getElementById('product-detail-modal').style.display = 'none';
                });
            }
            
            // Add to cart from product detail modal
            const productDetailAddToCartBtn = document.getElementById('product-detail-add-to-cart');
            if (productDetailAddToCartBtn) {
                productDetailAddToCartBtn.addEventListener('click', function() {
                    const productId = this.getAttribute('data-id');
                    const formData = new FormData();
                    formData.append('action', 'add_to_cart');
                    formData.append('product_id', productId);
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message);
                            document.getElementById('product-detail-modal').style.display = 'none';
                            setTimeout(() => {
                                location.reload();
                            }, 1000);
                        } else {
                            showNotification(data.message, 'error');
                            if (data.message === 'Please login first') {
                                document.getElementById('product-detail-modal').style.display = 'none';
                                document.getElementById('login-modal').style.display = 'flex';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('An error occurred. Please try again.', 'error');
                    });
                });
            }

            // Close modal when clicking outside
            window.addEventListener('click', function(e) {
                if (e.target.classList.contains('modal')) {
                    e.target.style.display = 'none';
                }
            });

            // Notification function
            function showNotification(message, type = 'success') {
                const notification = document.createElement('div');
                notification.style.cssText = `
                    position: fixed;
                    top: 100px;
                    right: 20px;
                    background: ${type === 'success' ? 'var(--success)' : 'var(--danger)'};
                    color: white;
                    padding: 15px 20px;
                    border-radius: var(--border-radius);
                    box-shadow: var(--shadow);
                    z-index: 1000;
                    transition: var(--transition);
                `;
                notification.textContent = message;
                document.body.appendChild(notification);
                
                setTimeout(() => {
                    notification.style.opacity = '0';
                    setTimeout(() => {
                        if (document.body.contains(notification)) {
                            document.body.removeChild(notification);
                        }
                    }, 300);
                }, 3000);
            }
            
            // Admin functionality
            // Admin login button
            const adminLoginBtn = document.getElementById('admin-login-btn');
            if (adminLoginBtn) {
                adminLoginBtn.addEventListener('click', function() {
                    document.getElementById('admin-login-modal').style.display = 'flex';
                });
            }
            
            // Admin login form
            const adminLoginForm = document.getElementById('admin-login-form');
            if (adminLoginForm) {
                adminLoginForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData();
                    formData.append('action', 'admin_login');
                    formData.append('username', document.getElementById('admin-username').value);
                    formData.append('password', document.getElementById('admin-password').value);
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message);
                            document.getElementById('admin-login-modal').style.display = 'none';
                            setTimeout(() => {
                                location.reload();
                            }, 1000);
                        } else {
                            showNotification(data.message, 'error');
                            document.getElementById('admin-password').value = '';
                            document.getElementById('admin-password').focus();
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('An error occurred. Please try again.', 'error');
                    });
                });
            }
            
            // Admin logout
            const adminLogoutBtn = document.getElementById('admin-logout-btn');
            if (adminLogoutBtn) {
                adminLogoutBtn.addEventListener('click', function() {
                    const formData = new FormData();
                    formData.append('action', 'admin_logout');
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message);
                            setTimeout(() => {
                                location.reload();
                            }, 1000);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        location.reload();
                    });
                });
            }
            
            // Add product button
            const addProductBtn = document.getElementById('add-product-btn');
            if (addProductBtn) {
                addProductBtn.addEventListener('click', function() {
                    // Reset form
                    document.getElementById('product-id').value = '0';
                    document.getElementById('product-name').value = '';
                    document.getElementById('product-description').value = '';
                    document.getElementById('product-price').value = '';
                    document.getElementById('product-category').value = 'Toilet';
                    document.getElementById('product-image').value = '';
                    document.getElementById('product-badge').value = '';
                    document.getElementById('product-stock').value = '0';
                    document.getElementById('admin-product-modal-title').textContent = 'Add New Product';
                    
                    document.getElementById('admin-product-modal').style.display = 'flex';
                });
            }
            
            // Edit product buttons
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('edit-product-btn') || e.target.closest('.edit-product-btn')) {
                    const button = e.target.classList.contains('edit-product-btn') ? e.target : e.target.closest('.edit-product-btn');
                    
                    document.getElementById('product-id').value = button.getAttribute('data-id');
                    document.getElementById('product-name').value = button.getAttribute('data-name');
                    document.getElementById('product-description').value = button.getAttribute('data-description');
                    document.getElementById('product-price').value = button.getAttribute('data-price');
                    document.getElementById('product-category').value = button.getAttribute('data-category');
                    document.getElementById('product-image').value = button.getAttribute('data-image');
                    document.getElementById('product-badge').value = button.getAttribute('data-badge');
                    document.getElementById('product-stock').value = button.getAttribute('data-stock');
                    document.getElementById('admin-product-modal-title').textContent = 'Edit Product';
                    
                    document.getElementById('admin-product-modal').style.display = 'flex';
                }
                
                // Delete product buttons
                if (e.target.classList.contains('delete-product-btn') || e.target.closest('.delete-product-btn')) {
                    const button = e.target.classList.contains('delete-product-btn') ? e.target : e.target.closest('.delete-product-btn');
                    const productId = button.getAttribute('data-id');
                    const productName = button.getAttribute('data-name');
                    
                    document.getElementById('delete-product-id').value = productId;
                    document.getElementById('delete-confirm-modal').style.display = 'flex';
                }
            });
            
            // Product form (add/edit)
            const adminProductForm = document.getElementById('admin-product-form');
            if (adminProductForm) {
                adminProductForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const productId = document.getElementById('product-id').value;
                    const formData = new FormData();
                    
                    if (productId === '0') {
                        formData.append('action', 'admin_add_product');
                    } else {
                        formData.append('action', 'admin_update_product');
                        formData.append('id', productId);
                    }
                    
                    formData.append('name', document.getElementById('product-name').value);
                    formData.append('description', document.getElementById('product-description').value);
                    formData.append('price', document.getElementById('product-price').value);
                    formData.append('category', document.getElementById('product-category').value);
                    formData.append('image', document.getElementById('product-image').value);
                    formData.append('badge', document.getElementById('product-badge').value);
                    formData.append('stock', document.getElementById('product-stock').value);
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message);
                            document.getElementById('admin-product-modal').style.display = 'none';
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            showNotification(data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('An error occurred. Please try again.', 'error');
                    });
                });
            }
            
            // Delete confirmation
            const confirmDeleteBtn = document.getElementById('confirm-delete');
            if (confirmDeleteBtn) {
                confirmDeleteBtn.addEventListener('click', function() {
                    const productId = document.getElementById('delete-product-id').value;
                    
                    const formData = new FormData();
                    formData.append('action', 'admin_delete_product');
                    formData.append('id', productId);
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message);
                            document.getElementById('delete-confirm-modal').style.display = 'none';
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            showNotification(data.message, 'error');
                            document.getElementById('delete-confirm-modal').style.display = 'none';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('An error occurred. Please try again.', 'error');
                        document.getElementById('delete-confirm-modal').style.display = 'none';
                    });
                });
            }
            
            // Cancel delete
            const cancelDeleteBtn = document.getElementById('cancel-delete');
            if (cancelDeleteBtn) {
                cancelDeleteBtn.addEventListener('click', function() {
                    document.getElementById('delete-confirm-modal').style.display = 'none';
                });
            }
            
            // Close admin modals
            const closeAdminLogin = document.getElementById('close-admin-login');
            if (closeAdminLogin) {
                closeAdminLogin.addEventListener('click', function() {
                    document.getElementById('admin-login-modal').style.display = 'none';
                });
            }
            
            const closeAdminProduct = document.getElementById('close-admin-product');
            if (closeAdminProduct) {
                closeAdminProduct.addEventListener('click', function() {
                    document.getElementById('admin-product-modal').style.display = 'none';
                });
            }
            
            const closeDeleteConfirm = document.getElementById('close-delete-confirm');
            if (closeDeleteConfirm) {
                closeDeleteConfirm.addEventListener('click', function() {
                    document.getElementById('delete-confirm-modal').style.display = 'none';
                });
            }
            
            // Close modals when clicking outside
            window.addEventListener('click', function(e) {
                if (e.target.classList.contains('modal')) {
                    e.target.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>