<?php
session_start();

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration with error handling
$host = "localhost";
$db_name = "food_online_order";
$username = "root";
$password = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("System temporarily unavailable. Please try again later.");
}

// Security: Input validation function
function sanitize_input($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

// Get restaurant config with fallback
$config_stmt = $pdo->query("SELECT * FROM restaurant_config ORDER BY id DESC LIMIT 1");
$config = $config_stmt->fetch();
if (!$config) {
    $config = [
        'restaurant_name' => 'MODERN FOODS',
        'delivery_fee' => 5.99,
        'tax_rate' => 8.5,
        'primary_color' => '#ea580c',
        'secondary_color' => '#ffffff',
        'text_color' => '#1f2937',
        'background_color' => '#f9fafb',
        'accent_color' => '#10b981'
    ];
}

// Get menu items with categories
$menu_stmt = $pdo->query("SELECT * FROM menu_items WHERE is_available = 1 ORDER BY 
    FIELD(category, 'appetizers', 'mains', 'desserts'), name");
$menu_items = $menu_stmt->fetchAll();

// Group menu items by category for better organization
$menu_by_category = [];
foreach ($menu_items as $item) {
    $menu_by_category[$item['category']][] = $item;
}

// Handle form submissions with validation
if ($_POST) {
    try {
        if (isset($_POST['place_order'])) {
            // Validate required fields - 移除了 delivery_fee 和 final_total 的必填验证
            $required_fields = ['customer_name', 'customer_phone', 'cart_items', 'subtotal', 'tax_amount'];
            foreach ($required_fields as $field) {
                if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
                    throw new Exception("Please fill in all required fields. Missing: " . $field);
                }
            }

            // Sanitize inputs
            $customer_name = sanitize_input($_POST['customer_name']);
            $customer_phone = sanitize_input($_POST['customer_phone']);
            
            // Validate phone number (basic validation)
            if (strlen($customer_phone) < 5) {
                throw new Exception("Please enter a valid phone number.");
            }

            $order_number = 'ORD' . date('YmdHis');
            $cart_items = $_POST['cart_items'];
            $subtotal = floatval($_POST['subtotal']);
            $tax_amount = floatval($_POST['tax_amount']);
            
            // 安全地获取 delivery_fee，如果不存在则使用默认值
            $delivery_fee = isset($_POST['delivery_fee']) ? floatval($_POST['delivery_fee']) : 0;
            
            // 计算 final_total 而不是从 POST 获取
            $final_total = $subtotal + $tax_amount + $delivery_fee;
            
            $service_type = $_POST['service_type'] ?? 'take-out';
            $delivery_option = $_POST['delivery_option'] ?? 'pickup';
            
            // Validate cart is not empty
            $cart_data = json_decode($cart_items, true);
            if (empty($cart_data)) {
                throw new Exception("Your cart is empty. Please add items before placing an order.");
            }
            
            $stmt = $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_phone, items, subtotal, tax_amount, delivery_fee, final_total, service_type, delivery_option) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            if ($stmt->execute([$order_number, $customer_name, $customer_phone, $cart_items, $subtotal, $tax_amount, $delivery_fee, $final_total, $service_type, $delivery_option])) {
                $_SESSION['success_message'] = "🎉 Order placed successfully! Your order number is: " . $order_number;
                unset($_SESSION['cart']);
                header("Location: index.php?page=home");
                exit();
            } else {
                throw new Exception("Failed to place order. Please try again.");
            }
        }
        
        // Cart actions
        if (isset($_POST['add_to_cart'])) {
            $item_id = intval($_POST['item_id']);
            $item_name = sanitize_input($_POST['item_name']);
            $item_price = floatval($_POST['item_price']);
            $item_image = sanitize_input($_POST['item_image']);
            
            if (!isset($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }
            
            $found = false;
            foreach ($_SESSION['cart'] as &$item) {
                if ($item['id'] == $item_id) {
                    $item['quantity'] += 1;
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $_SESSION['cart'][] = [
                    'id' => $item_id,
                    'name' => $item_name,
                    'price' => $item_price,
                    'image' => $item_image,
                    'quantity' => 1
                ];
            }
            
            $_SESSION['cart_success'] = "{$item_name} added to cart!";
            header("Location: index.php?page=menu");
            exit;
        }

        if (isset($_POST['remove_from_cart'])) {
            $item_id = intval($_POST['item_id']);
            
            if (isset($_SESSION['cart'])) {
                $_SESSION['cart'] = array_filter($_SESSION['cart'], function($item) use ($item_id) {
                    return $item['id'] != $item_id;
                });
            }
            
            header("Location: index.php?page=cart");
            exit;
        }

        if (isset($_POST['update_quantity'])) {
            $item_id = intval($_POST['item_id']);
            $change = intval($_POST['change']);
            
            if (isset($_SESSION['cart'])) {
                foreach ($_SESSION['cart'] as &$item) {
                    if ($item['id'] == $item_id) {
                        $item['quantity'] += $change;
                        if ($item['quantity'] <= 0) {
                            $_SESSION['cart'] = array_filter($_SESSION['cart'], function($cart_item) use ($item_id) {
                                return $cart_item['id'] != $item_id;
                            });
                        }
                        break;
                    }
                }
            }
            
            header("Location: index.php?page=cart");
            exit;
        }

        if (isset($_POST['set_service_type'])) {
            $service_type = sanitize_input($_POST['service_type']);
            $_SESSION['service_type'] = $service_type;
            $_SESSION['service_message'] = $service_type === 'dine-in' ? 
                "🍽️ Dine In selected! Enjoy our cozy atmosphere." : 
                "🥡 Take Out selected! Quick and convenient.";
            header("Location: index.php?page=menu");
            exit;
        }

    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
    }
}

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Get current page
$page = isset($_GET['page']) ? sanitize_input($_GET['page']) : 'home';
$service_type = $_SESSION['service_type'] ?? null;

// Calculate cart totals
$cart_total = array_sum(array_column($_SESSION['cart'], 'quantity'));
$cart_subtotal = array_sum(array_map(function($item) { 
    return $item['price'] * $item['quantity']; 
}, $_SESSION['cart']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($config['restaurant_name']); ?> - Premium Food Experience</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: <?php echo $config['primary_color']; ?>;
            --background-color: <?php echo $config['background_color']; ?>;
            --text-color: <?php echo $config['text_color']; ?>;
        }
        
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--background-color);
            color: var(--text-color);
            scroll-behavior: smooth;
        }
        
        .page { 
            display: none; 
            min-height: calc(100vh - 80px);
            animation: fadeIn 0.5s ease-in;
        }
        
        .page.active { 
            display: block; 
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .menu-item { 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        }
        
        .menu-item:hover { 
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        
        .cart-item { 
            animation: slideIn 0.4s ease-out;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-30px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 16px 24px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            z-index: 1000;
            animation: toastSlide 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            display: flex;
            align-items: center;
            gap: 12px;
            max-width: 400px;
        }
        
        @keyframes toastSlide {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .error-toast {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #dc2626);
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(234, 88, 12, 0.3);
        }
        
        .food-image {
            transition: transform 0.3s ease;
        }
        
        .food-image:hover {
            transform: scale(1.05);
        }
        
        .category-badge {
            background: var(--primary-color);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .loading-spinner {
            border: 3px solid #f3f4f6;
            border-top: 3px solid var(--primary-color);
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 8px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .sticky-cart {
            position: sticky;
            top: 20px;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Enhanced Navigation -->
    <nav class="bg-white shadow-lg border-b border-gray-200 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center space-x-4">
                    <div class="w-12 h-12 rounded-full flex items-center justify-center shadow-lg pulse" style="background-color: <?php echo $config['primary_color']; ?>">
                        <i class="fas fa-utensils text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($config['restaurant_name']); ?></h1>
                        <p class="text-xs text-gray-500">Premium Dining Experience</p>
                    </div>
                </div>
                <div class="flex items-center space-x-6">
                    <a href="?page=home" class="nav-link group relative px-4 py-2 font-semibold transition-all duration-300 <?php echo $page == 'home' ? 'text-orange-600' : 'text-gray-600 hover:text-orange-600'; ?>">
                        <i class="fas fa-home mr-2"></i>Home
                        <?php if($page == 'home'): ?>
                        <span class="absolute bottom-0 left-0 w-full h-0.5 bg-orange-600 transform origin-left transition-transform duration-300"></span>
                        <?php endif; ?>
                    </a>
                    <a href="?page=menu" class="nav-link group relative px-4 py-2 font-semibold transition-all duration-300 <?php echo $page == 'menu' ? 'text-orange-600' : 'text-gray-600 hover:text-orange-600'; ?>">
                        <i class="fas fa-book-open mr-2"></i>Menu
                        <?php if($page == 'menu'): ?>
                        <span class="absolute bottom-0 left-0 w-full h-0.5 bg-orange-600 transform origin-left transition-transform duration-300"></span>
                        <?php endif; ?>
                    </a>
                    <a href="?page=cart" class="nav-link group relative px-4 py-2 font-semibold transition-all duration-300 <?php echo $page == 'cart' ? 'text-orange-600' : 'text-gray-600 hover:text-orange-600'; ?> flex items-center">
                        <i class="fas fa-shopping-cart mr-2"></i>
                        Cart 
                        <span class="ml-2 bg-orange-500 text-white rounded-full px-2 py-1 text-xs font-bold min-w-6 h-6 flex items-center justify-center transform transition-transform duration-300 hover:scale-110">
                            <?php echo $cart_total; ?>
                        </span>
                        <?php if($page == 'cart'): ?>
                        <span class="absolute bottom-0 left-0 w-full h-0.5 bg-orange-600 transform origin-left transition-transform duration-300"></span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Notification System -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="toast">
            <i class="fas fa-check-circle text-white"></i>
            <div>
                <div class="font-semibold">Success!</div>
                <div class="text-sm"><?php echo $_SESSION['success_message']; ?></div>
            </div>
            <button onclick="this.parentElement.remove()" class="ml-auto text-white hover:text-gray-200">
                <i class="fas fa-times"></i>
            </button>
            <?php unset($_SESSION['success_message']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="toast error-toast">
            <i class="fas fa-exclamation-triangle text-white"></i>
            <div>
                <div class="font-semibold">Error!</div>
                <div class="text-sm"><?php echo $_SESSION['error_message']; ?></div>
            </div>
            <button onclick="this.parentElement.remove()" class="ml-auto text-white hover:text-gray-200">
                <i class="fas fa-times"></i>
            </button>
            <?php unset($_SESSION['error_message']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['cart_success'])): ?>
        <div class="toast">
            <i class="fas fa-shopping-cart text-white"></i>
            <div>
                <div class="font-semibold">Cart Updated</div>
                <div class="text-sm"><?php echo $_SESSION['cart_success']; ?></div>
            </div>
            <button onclick="this.parentElement.remove()" class="ml-auto text-white hover:text-gray-200">
                <i class="fas fa-times"></i>
            </button>
            <?php unset($_SESSION['cart_success']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['service_message'])): ?>
        <div class="toast">
            <i class="fas fa-concierge-bell text-white"></i>
            <div>
                <div class="font-semibold">Service Selected</div>
                <div class="text-sm"><?php echo $_SESSION['service_message']; ?></div>
            </div>
            <button onclick="this.parentElement.remove()" class="ml-auto text-white hover:text-gray-200">
                <i class="fas fa-times"></i>
            </button>
            <?php unset($_SESSION['service_message']); ?>
        </div>
    <?php endif; ?>

    <!-- Home Page -->
    <div id="home-page" class="page <?php echo $page == 'home' ? 'active' : ''; ?>">
        <!-- Hero Section -->
        <section class="bg-gradient-to-br from-orange-50 to-red-50 py-20">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                <div class="flex justify-center mb-8">
                    <div class="w-32 h-32 rounded-full flex items-center justify-center shadow-2xl pulse" style="background-color: <?php echo $config['primary_color']; ?>">
                        <i class="fas fa-utensils text-white text-4xl"></i>
                    </div>
                </div>
                <h1 class="text-6xl font-bold text-gray-800 mb-6">Welcome to <span class="text-orange-600"><?php echo htmlspecialchars($config['restaurant_name']); ?></span></h1>
                <p class="text-2xl text-gray-600 mb-12 max-w-3xl mx-auto leading-relaxed">
                    Experience culinary excellence with our carefully crafted dishes, prepared with the finest ingredients and passion.
                </p>
                
                <!-- Stats Section -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-16 max-w-4xl mx-auto">
                    <div class="bg-white rounded-2xl p-8 shadow-lg transform hover:scale-105 transition-transform duration-300">
                        <i class="fas fa-star text-yellow-400 text-3xl mb-4"></i>
                        <div class="text-3xl font-bold text-gray-800">4.9/5</div>
                        <div class="text-gray-600">Customer Rating</div>
                    </div>
                    <div class="bg-white rounded-2xl p-8 shadow-lg transform hover:scale-105 transition-transform duration-300">
                        <i class="fas fa-clock text-green-500 text-3xl mb-4"></i>
                        <div class="text-3xl font-bold text-gray-800">15-20min</div>
                        <div class="text-gray-600">Average Prep Time</div>
                    </div>
                    <div class="bg-white rounded-2xl p-8 shadow-lg transform hover:scale-105 transition-transform duration-300">
                        <i class="fas fa-users text-blue-500 text-3xl mb-4"></i>
                        <div class="text-3xl font-bold text-gray-800">2,500+</div>
                        <div class="text-gray-600">Happy Customers</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Service Options -->
        <section class="py-20 bg-white">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <h2 class="text-4xl font-bold text-center text-gray-800 mb-4">Choose Your Experience</h2>
                <p class="text-xl text-gray-600 text-center mb-12">Select how you'd like to enjoy our delicious food</p>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12">
                    <!-- Dine In Option -->
                    <div class="group relative bg-gradient-to-br from-white to-gray-50 rounded-3xl shadow-2xl overflow-hidden transform hover:scale-105 transition-all duration-500">
                        <div class="absolute inset-0 bg-gradient-to-br from-orange-500/10 to-red-500/10 opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
                        <div class="relative p-12 text-center">
                            <div class="w-24 h-24 bg-gradient-to-br from-orange-500 to-red-500 rounded-full flex items-center justify-center mx-auto mb-8 shadow-2xl group-hover:scale-110 transition-transform duration-500">
                                <i class="fas fa-concierge-bell text-white text-3xl"></i>
                            </div>
                            <h3 class="text-3xl font-bold text-gray-800 mb-4">Dine In Experience</h3>
                            <p class="text-gray-600 text-lg mb-8 leading-relaxed">Immerse yourself in our cozy atmosphere with exceptional table service</p>
                            
                            <ul class="text-left space-y-4 mb-10">
                                <li class="flex items-center text-gray-700">
                                    <i class="fas fa-check text-green-500 mr-3 text-lg"></i>
                                    <span>Comfortable seating for all occasions</span>
                                </li>
                                <li class="flex items-center text-gray-700">
                                    <i class="fas fa-check text-green-500 mr-3 text-lg"></i>
                                    <span>Full-service dining experience</span>
                                </li>
                                <li class="flex items-center text-gray-700">
                                    <i class="fas fa-check text-green-500 mr-3 text-lg"></i>
                                    <span>Complimentary bread and appetizers</span>
                                </li>
                                <li class="flex items-center text-gray-700">
                                    <i class="fas fa-check text-green-500 mr-3 text-lg"></i>
                                    <span>Professional wait staff</span>
                                </li>
                            </ul>
                            
                            <form method="POST" class="mt-8">
                                <input type="hidden" name="set_service_type" value="1">
                                <input type="hidden" name="service_type" value="dine-in">
                                <button type="submit" class="w-full bg-gradient-to-r from-orange-500 to-red-500 text-white py-4 px-8 rounded-2xl font-bold text-lg shadow-2xl transform hover:scale-105 transition-all duration-300 hover:shadow-3xl">
                                    <i class="fas fa-utensils mr-3"></i>Choose Dine In Experience
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Take Out Option -->
                    <div class="group relative bg-gradient-to-br from-white to-gray-50 rounded-3xl shadow-2xl overflow-hidden transform hover:scale-105 transition-all duration-500">
                        <div class="absolute inset-0 bg-gradient-to-br from-green-500/10 to-emerald-500/10 opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
                        <div class="relative p-12 text-center">
                            <div class="w-24 h-24 bg-gradient-to-br from-green-500 to-emerald-500 rounded-full flex items-center justify-center mx-auto mb-8 shadow-2xl group-hover:scale-110 transition-transform duration-500">
                                <i class="fas fa-shopping-bag text-white text-3xl"></i>
                            </div>
                            <h3 class="text-3xl font-bold text-gray-800 mb-4">Take Out & Delivery</h3>
                            <p class="text-gray-600 text-lg mb-8 leading-relaxed">Enjoy restaurant-quality food in the comfort of your home</p>
                            
                            <ul class="text-left space-y-4 mb-10">
                                <li class="flex items-center text-gray-700">
                                    <i class="fas fa-check text-green-500 mr-3 text-lg"></i>
                                    <span>Quick and convenient pickup</span>
                                </li>
                                <li class="flex items-center text-gray-700">
                                    <i class="fas fa-check text-green-500 mr-3 text-lg"></i>
                                    <span>Eco-friendly packaging</span>
                                </li>
                                <li class="flex items-center text-gray-700">
                                    <i class="fas fa-check text-green-500 mr-3 text-lg"></i>
                                    <span>Ready in 15-20 minutes</span>
                                </li>
                                <li class="flex items-center text-gray-700">
                                    <i class="fas fa-check text-green-500 mr-3 text-lg"></i>
                                    <span>Contactless delivery available</span>
                                </li>
                            </ul>
                            
                            <form method="POST" class="mt-8">
                                <input type="hidden" name="set_service_type" value="1">
                                <input type="hidden" name="service_type" value="take-out">
                                <button type="submit" class="w-full bg-gradient-to-r from-green-500 to-emerald-500 text-white py-4 px-8 rounded-2xl font-bold text-lg shadow-2xl transform hover:scale-105 transition-all duration-300 hover:shadow-3xl">
                                    <i class="fas fa-motorcycle mr-3"></i>Choose Take Out & Delivery
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features Section -->
        <section class="py-20 bg-gray-50">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <h2 class="text-4xl font-bold text-center text-gray-800 mb-4">Why Choose Us?</h2>
                <p class="text-xl text-gray-600 text-center mb-16">Experience the difference with our premium offerings</p>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div class="text-center p-8 bg-white rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-2">
                        <div class="w-20 h-20 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-seedling text-orange-600 text-2xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-800 mb-4">Fresh Ingredients</h3>
                        <p class="text-gray-600">We source only the freshest, highest-quality ingredients for every dish</p>
                    </div>
                    <div class="text-center p-8 bg-white rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-2">
                        <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-clock text-green-600 text-2xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-800 mb-4">Quick Service</h3>
                        <p class="text-gray-600">Your food is prepared quickly without compromising on quality or taste</p>
                    </div>
                    <div class="text-center p-8 bg-white rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-2">
                        <div class="w-20 h-20 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-heart text-blue-600 text-2xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-800 mb-4">Made with Love</h3>
                        <p class="text-gray-600">Every dish is prepared with passion and attention to detail</p>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Menu Page -->
    <div id="menu-page" class="page <?php echo $page == 'menu' ? 'active' : ''; ?>">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <!-- Header -->
            <div class="text-center mb-16">
                <h2 class="text-5xl font-bold text-gray-800 mb-6">Our Menu</h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto leading-relaxed">
                    Discover our carefully crafted selection of dishes, each prepared with the finest ingredients and culinary expertise
                </p>
                
                <?php if($service_type): ?>
                <div class="inline-flex items-center bg-green-50 text-green-800 px-6 py-3 rounded-full mt-6">
                    <i class="fas fa-check-circle mr-3"></i>
                    <span class="font-semibold"><?php echo $service_type === 'dine-in' ? 'Dine In' : 'Take Out'; ?> Service Selected</span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Category Navigation -->
            <div class="flex justify-center mb-12">
                <div class="bg-white rounded-2xl p-2 shadow-lg inline-flex">
                    <button onclick="filterMenu('all')" class="category-btn px-6 py-3 rounded-xl font-semibold transition-all duration-300 bg-orange-500 text-white shadow-lg">
                        <i class="fas fa-th-large mr-2"></i>All Items
                    </button>
                    <button onclick="filterMenu('appetizers')" class="category-btn px-6 py-3 rounded-xl font-semibold transition-all duration-300 text-gray-600 hover:bg-gray-100 mx-2">
                        <i class="fas fa-utensil-spoon mr-2"></i>Appetizers
                    </button>
                    <button onclick="filterMenu('mains')" class="category-btn px-6 py-3 rounded-xl font-semibold transition-all duration-300 text-gray-600 hover:bg-gray-100 mx-2">
                        <i class="fas fa-drumstick-bite mr-2"></i>Main Courses
                    </button>
                    <button onclick="filterMenu('desserts')" class="category-btn px-6 py-3 rounded-xl font-semibold transition-all duration-300 text-gray-600 hover:bg-gray-100">
                        <i class="fas fa-ice-cream mr-2"></i>Desserts
                    </button>
                </div>
            </div>

            <!-- Menu Items by Category -->
            <?php foreach ($menu_by_category as $category => $items): ?>
            <div class="mb-16 category-section" data-category="<?php echo $category; ?>">
                <div class="flex items-center mb-8">
                    <div class="w-12 h-1 bg-orange-500 mr-4"></div>
                    <h3 class="text-3xl font-bold text-gray-800 capitalize">
                        <?php 
                        $category_titles = [
                            'appetizers' => 'Appetizers & Starters',
                            'mains' => 'Main Courses',
                            'desserts' => 'Desserts & Beverages'
                        ];
                        echo $category_titles[$category] ?? ucfirst($category); 
                        ?>
                    </h3>
                    <div class="w-12 h-1 bg-orange-500 ml-4"></div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-8">
                    <?php foreach ($items as $item): ?>
                    <div class="menu-item bg-white rounded-2xl shadow-xl overflow-hidden border border-gray-100" data-category="<?php echo $item['category']; ?>">
                        <!-- Food Image -->
                        <div class="relative overflow-hidden">
                            <img 
                                src="<?php echo !empty($item['image_url']) ? htmlspecialchars($item['image_url']) : 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=500&h=350&fit=crop'; ?>" 
                                alt="<?php echo htmlspecialchars($item['name']); ?>"
                                class="w-full h-48 object-cover food-image"
                                onerror="this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=500&h=350&fit=crop'"
                            >
                            <div class="absolute top-4 right-4 category-badge">
                                <?php echo $item['category']; ?>
                            </div>
                            <div class="absolute top-4 left-4 bg-black/50 text-white px-3 py-1 rounded-full text-sm font-semibold">
                                $<?php echo number_format($item['price'], 2); ?>
                            </div>
                        </div>
                        
                        <!-- Food Details -->
                        <div class="p-6">
                            <div class="flex items-start justify-between mb-3">
                                <h4 class="text-xl font-bold text-gray-800 flex-1"><?php echo htmlspecialchars($item['name']); ?></h4>
                            </div>
                            <p class="text-gray-600 text-sm mb-6 leading-relaxed"><?php echo htmlspecialchars($item['description']); ?></p>
                            
                            <div class="flex items-center justify-between">
                                <div class="text-2xl font-bold" style="color: <?php echo $config['primary_color']; ?>">
                                    $<?php echo number_format($item['price'], 2); ?>
                                </div>
                                <form method="POST" class="flex items-center">
                                    <input type="hidden" name="add_to_cart" value="1">
                                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                    <input type="hidden" name="item_name" value="<?php echo htmlspecialchars($item['name']); ?>">
                                    <input type="hidden" name="item_price" value="<?php echo $item['price']; ?>">
                                    <input type="hidden" name="item_image" value="<?php echo !empty($item['image_url']) ? htmlspecialchars($item['image_url']) : 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=500&h=350&fit=crop'; ?>">
                                    <button type="submit" class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-3 rounded-xl font-semibold shadow-lg transform hover:scale-105 transition-all duration-300 hover:shadow-xl flex items-center">
                                        <i class="fas fa-plus mr-2"></i>Add to Cart
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Cart Page -->
    <div id="cart-page" class="page <?php echo $page == 'cart' ? 'active' : ''; ?>">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <div class="text-center mb-12">
                <h2 class="text-5xl font-bold text-gray-800 mb-4">Your Order</h2>
                <p class="text-xl text-gray-600">Review your items and complete your order</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Cart Items -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-2xl shadow-xl p-8">
                        <div class="flex items-center justify-between mb-8">
                            <h3 class="text-2xl font-bold text-gray-800">Order Items</h3>
                            <div class="text-lg font-semibold text-gray-600">
                                <?php echo $cart_total; ?> item<?php echo $cart_total != 1 ? 's' : ''; ?>
                            </div>
                        </div>
                        
                        <div id="cart-items">
                            <?php if (empty($_SESSION['cart'])): ?>
                            <div class="text-center py-16">
                                <i class="fas fa-shopping-cart text-gray-300 text-6xl mb-6"></i>
                                <h4 class="text-2xl font-bold text-gray-500 mb-4">Your cart is empty</h4>
                                <p class="text-gray-600 mb-8">Add some delicious items from our menu to get started!</p>
                                <a href="?page=menu" class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-8 py-4 rounded-xl font-semibold text-lg shadow-lg transform hover:scale-105 transition-all duration-300 inline-flex items-center">
                                    <i class="fas fa-book-open mr-3"></i>Browse Menu
                                </a>
                            </div>
                            <?php else: ?>
                                <?php foreach ($_SESSION['cart'] as $item): ?>
                                <div class="cart-item bg-gray-50 rounded-xl p-6 mb-4 border border-gray-200">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-4 flex-1">
                                            <div class="w-16 h-16 bg-gray-200 rounded-lg overflow-hidden">
                                                <img 
                                                    src="<?php echo !empty($item['image']) ? htmlspecialchars($item['image']) : 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=100&h=100&fit=crop'; ?>" 
                                                    alt="<?php echo htmlspecialchars($item['name']); ?>"
                                                    class="w-full h-full object-cover"
                                                    onerror="this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=100&h=100&fit=crop'"
                                                >
                                            </div>
                                            <div class="flex-1">
                                                <h4 class="font-bold text-gray-800 text-lg"><?php echo htmlspecialchars($item['name']); ?></h4>
                                                <p class="text-gray-600">$<?php echo number_format($item['price'], 2); ?> each</p>
                                                <div class="text-sm text-green-600 font-semibold">
                                                    $<?php echo number_format($item['price'] * $item['quantity'], 2); ?> total
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="flex items-center space-x-4">
                                            <!-- Quantity Controls -->
                                            <div class="flex items-center space-x-3 bg-white rounded-xl border border-gray-300 p-2">
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="update_quantity" value="1">
                                                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                    <input type="hidden" name="change" value="-1">
                                                    <button type="submit" class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center hover:bg-gray-200 transition-colors text-gray-600 hover:text-gray-800">
                                                        <i class="fas fa-minus"></i>
                                                    </button>
                                                </form>
                                                <span class="font-bold text-lg text-gray-800 min-w-8 text-center"><?php echo $item['quantity']; ?></span>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="update_quantity" value="1">
                                                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                    <input type="hidden" name="change" value="1">
                                                    <button type="submit" class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center hover:bg-gray-200 transition-colors text-gray-600 hover:text-gray-800">
                                                        <i class="fas fa-plus"></i>
                                                    </button>
                                                </form>
                                            </div>
                                            
                                            <!-- Remove Button -->
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="remove_from_cart" value="1">
                                                <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                <button type="submit" class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center hover:bg-red-100 transition-colors text-red-600 hover:text-red-800" title="Remove item">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Order Summary & Customer Info -->
                <div class="space-y-6 sticky-cart">
                    <!-- Customer Information -->
                    <div class="bg-white rounded-2xl shadow-xl p-8">
                        <h3 class="text-2xl font-bold text-gray-800 mb-6">Customer Information</h3>
                        <form method="POST" class="space-y-6" id="order-form">
                            <input type="hidden" name="place_order" value="1">
                            <input type="hidden" name="cart_items" id="cart-items-input" value="<?php echo htmlspecialchars(json_encode($_SESSION['cart'])); ?>">
                            <input type="hidden" name="service_type" value="<?php echo $service_type ?: 'take-out'; ?>">
                            <input type="hidden" id="subtotal-input" name="subtotal" value="<?php echo $cart_subtotal; ?>">
                            <input type="hidden" id="tax-amount-input" name="tax_amount" value="<?php echo $cart_subtotal * ($config['tax_rate'] / 100); ?>">
                            <input type="hidden" id="delivery-fee-input" name="delivery_fee" value="0">
                            <input type="hidden" id="final-total-input" name="final_total" value="<?php echo $cart_subtotal + ($cart_subtotal * ($config['tax_rate'] / 100)); ?>">
                            
                            <div>
                                <label for="customer-name" class="block text-sm font-semibold text-gray-700 mb-3">Full Name *</label>
                                <input type="text" id="customer-name" name="customer_name" required 
                                       class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition-all duration-300"
                                       placeholder="Enter your full name">
                            </div>
                            <div>
                                <label for="customer-phone" class="block text-sm font-semibold text-gray-700 mb-3">Phone Number *</label>
                                <input type="tel" id="customer-phone" name="customer_phone" required 
                                       class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition-all duration-300"
                                       placeholder="Enter your phone number">
                            </div>
                            
                            <!-- Delivery Options -->
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-3">Delivery Option</label>
                                <div class="space-y-3">
                                    <label class="flex items-center p-4 border-2 border-gray-200 rounded-xl cursor-pointer hover:border-orange-300 transition-all duration-300">
                                        <input type="radio" name="delivery_option" value="pickup" class="mr-4 w-5 h-5 text-orange-500 focus:ring-orange-500" checked>
                                        <div>
                                            <div class="font-semibold text-gray-800">Pickup</div>
                                            <div class="text-sm text-gray-600">Free - Ready in 15-20 minutes</div>
                                        </div>
                                    </label>
                                    <label class="flex items-center p-4 border-2 border-gray-200 rounded-xl cursor-pointer hover:border-orange-300 transition-all duration-300">
                                        <input type="radio" name="delivery_option" value="delivery" class="mr-4 w-5 h-5 text-orange-500 focus:ring-orange-500">
                                        <div>
                                            <div class="font-semibold text-gray-800">Delivery</div>
                                            <div class="text-sm text-gray-600">+$<?php echo number_format($config['delivery_fee'], 2); ?> - 25-35 minutes</div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Order Summary -->
                    <div class="bg-white rounded-2xl shadow-xl p-8">
                        <h3 class="text-2xl font-bold text-gray-800 mb-6">Order Summary</h3>
                        <?php
                        $subtotal = $cart_subtotal;
                        $tax_rate = $config['tax_rate'] / 100;
                        $delivery_fee = 0;
                        $tax_amount = $subtotal * $tax_rate;
                        $final_total = $subtotal + $tax_amount + $delivery_fee;
                        ?>
                        <div class="space-y-4 text-lg">
                            <div class="flex justify-between items-center py-2">
                                <span class="text-gray-600">Subtotal:</span>
                                <span class="font-semibold text-gray-800" id="subtotal-display">$<?php echo number_format($subtotal, 2); ?></span>
                            </div>
                            <div class="flex justify-between items-center py-2">
                                <span class="text-gray-600">Tax (<?php echo $config['tax_rate']; ?>%):</span>
                                <span class="font-semibold text-gray-800" id="tax-amount-display">$<?php echo number_format($tax_amount, 2); ?></span>
                            </div>
                            <div class="flex justify-between items-center py-2">
                                <span class="text-gray-600">Delivery Fee:</span>
                                <span class="font-semibold text-gray-800" id="delivery-fee-display">$0.00</span>
                            </div>
                            <hr class="my-4 border-gray-300">
                            <div class="flex justify-between items-center py-2">
                                <span class="text-xl font-bold text-gray-800">Total:</span>
                                <span class="text-2xl font-bold text-orange-600" id="final-total-display">$<?php echo number_format($final_total, 2); ?></span>
                            </div>
                        </div>
                        
                        <button type="submit" form="order-form"
                                class="w-full mt-8 bg-gradient-to-r from-orange-500 to-red-500 text-white py-4 rounded-2xl font-bold text-lg shadow-2xl transform hover:scale-105 transition-all duration-300 hover:shadow-3xl disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none flex items-center justify-center"
                                id="place-order-btn"
                                <?php echo empty($_SESSION['cart']) ? 'disabled' : ''; ?>>
                            <?php if(empty($_SESSION['cart'])): ?>
                                <i class="fas fa-shopping-cart mr-3"></i>Cart is Empty
                            <?php else: ?>
                                <i class="fas fa-credit-card mr-3"></i>Place Order - $<?php echo number_format($final_total, 2); ?>
                            <?php endif; ?>
                        </button>
                        
                        <?php if(!empty($_SESSION['cart'])): ?>
                        <div class="text-center mt-4">
                            <a href="?page=menu" class="text-orange-600 hover:text-orange-700 font-semibold inline-flex items-center">
                                <i class="fas fa-plus mr-2"></i>Add More Items
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Enhanced JavaScript functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Update delivery option and recalculate totals
            const deliveryRadios = document.querySelectorAll('input[name="delivery_option"]');
            deliveryRadios.forEach(radio => {
                radio.addEventListener('change', calculateTotals);
            });
            
            // Initial calculation
            calculateTotals();
            
            // Auto-close toasts after 5 seconds
            setTimeout(() => {
                document.querySelectorAll('.toast').forEach(toast => {
                    toast.style.animation = 'toastSlide 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55) reverse';
                    setTimeout(() => toast.remove(), 400);
                });
            }, 5000);

            // Add event listener for form submission to update totals
            const orderForm = document.getElementById('order-form');
            if (orderForm) {
                orderForm.addEventListener('submit', function() {
                    calculateTotals(); // Ensure all fields are updated before submission
                });
            }
        });

        // Filter menu by category
        function filterMenu(category) {
            const categories = document.querySelectorAll('.category-section');
            const buttons = document.querySelectorAll('.category-btn');
            
            // Update button styles
            buttons.forEach(btn => {
                if (btn.textContent.toLowerCase().includes(category) || (category === 'all' && btn.textContent.includes('All Items'))) {
                    btn.className = 'category-btn px-6 py-3 rounded-xl font-semibold transition-all duration-300 bg-orange-500 text-white shadow-lg';
                } else {
                    btn.className = 'category-btn px-6 py-3 rounded-xl font-semibold transition-all duration-300 text-gray-600 hover:bg-gray-100 mx-2';
                }
            });
            
            // Show/hide categories
            categories.forEach(section => {
                if (category === 'all' || section.dataset.category === category) {
                    section.style.display = 'block';
                    setTimeout(() => {
                        section.style.opacity = '1';
                        section.style.transform = 'translateY(0)';
                    }, 50);
                } else {
                    section.style.opacity = '0';
                    section.style.transform = 'translateY(20px)';
                    setTimeout(() => {
                        section.style.display = 'none';
                    }, 300);
                }
            });
            
            // Smooth scroll to the category
            if (category !== 'all') {
                const section = document.querySelector(`[data-category="${category}"]`);
                if (section) {
                    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        }

        // Calculate order totals
        function calculateTotals() {
            const subtotal = <?php echo $cart_subtotal; ?>;
            const taxRate = <?php echo $config['tax_rate']; ?> / 100;
            const deliveryFee = document.querySelector('input[name="delivery_option"]:checked').value === 'delivery' ? <?php echo $config['delivery_fee']; ?> : 0;
            
            const taxAmount = subtotal * taxRate;
            const finalTotal = subtotal + taxAmount + deliveryFee;

            // Update display
            document.getElementById('subtotal-display').textContent = '$' + subtotal.toFixed(2);
            document.getElementById('tax-amount-display').textContent = '$' + taxAmount.toFixed(2);
            document.getElementById('delivery-fee-display').textContent = '$' + deliveryFee.toFixed(2);
            document.getElementById('final-total-display').textContent = '$' + finalTotal.toFixed(2);

            // Update hidden form fields
            document.getElementById('subtotal-input').value = subtotal;
            document.getElementById('tax-amount-input').value = taxAmount;
            document.getElementById('delivery-fee-input').value = deliveryFee;
            document.getElementById('final-total-input').value = finalTotal;

            // Update place order button
            const placeOrderBtn = document.getElementById('place-order-btn');
            if (placeOrderBtn && !placeOrderBtn.disabled) {
                placeOrderBtn.innerHTML = `<i class="fas fa-credit-card mr-3"></i>Place Order - $${finalTotal.toFixed(2)}`;
            }
        }

        // Show custom toast message
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast ${type === 'error' ? 'error-toast' : ''}`;
            toast.innerHTML = `
                <i class="fas fa-${type === 'error' ? 'exclamation-triangle' : 'check-circle'} text-white"></i>
                <div>
                    <div class="font-semibold">${type === 'error' ? 'Error!' : 'Success!'}</div>
                    <div class="text-sm">${message}</div>
                </div>
                <button onclick="this.parentElement.remove()" class="ml-auto text-white hover:text-gray-200">
                    <i class="fas fa-times"></i>
                </button>
            `;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'toastSlide 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55) reverse';
                setTimeout(() => toast.remove(), 400);
            }, 5000);
        }

        // Smooth page transitions
        function navigateToPage(page) {
            document.querySelectorAll('.page').forEach(p => {
                p.classList.remove('active');
            });
            document.getElementById(page + '-page').classList.add('active');
            
            // Update URL without reload
            history.pushState(null, null, `?page=${page}`);
        }

        // Handle browser back/forward buttons
        window.addEventListener('popstate', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const page = urlParams.get('page') || 'home';
            navigateToPage(page);
        });

        // Add loading states to forms
        document.addEventListener('submit', function(e) {
            const form = e.target;
            const submitBtn = form.querySelector('button[type="submit"]');
            
            if (submitBtn && !submitBtn.disabled) {
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<span class="loading-spinner"></span>Processing...';
                submitBtn.disabled = true;
                
                // Re-enable after 5 seconds if still disabled (fallback)
                setTimeout(() => {
                    if (submitBtn.disabled) {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                        showToast('Request timed out. Please try again.', 'error');
                    }
                }, 5000);
            }
        });
    </script>
</body>
</html>