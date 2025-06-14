<?php
// File: orders.php - Orders Management Interface
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Bootstrap and configuration
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

// Session and authentication check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ' . getNavUrl('login.php'));
    exit;
}

// Database connection
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    die("Database connection factory not configured correctly.");
}
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include models
require_once BASE_PATH . '/models/Order.php';
require_once BASE_PATH . '/models/Product.php';

$orderModel = new Order($db);
$productModel = new Product($db);

// Handle operations
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $orderData = [
                'order_number' => trim($_POST['order_number'] ?? ''),
                'customer_name' => trim($_POST['customer_name'] ?? ''),
                'customer_email' => trim($_POST['customer_email'] ?? ''),
                'shipping_address' => trim($_POST['shipping_address'] ?? ''),
                'order_date' => $_POST['order_date'] ?? date('Y-m-d H:i:s'),
                'status' => $_POST['status'] ?? Order::STATUS_PENDING,
                'notes' => trim($_POST['notes'] ?? '')
            ];
            
            // Process order items
            $items = [];
            if (!empty($_POST['items'])) {
                foreach ($_POST['items'] as $item) {
                    if (!empty($item['product_id']) && !empty($item['quantity']) && !empty($item['unit_price'])) {
                        $items[] = [
                            'product_id' => intval($item['product_id']),
                            'quantity_ordered' => intval($item['quantity']),
                            'unit_price' => floatval($item['unit_price'])
                        ];
                    }
                }
            }
            
            if (empty($orderData['customer_name'])) {
                $message = 'Numele clientului este obligatoriu.';
                $messageType = 'error';
            } elseif (empty($items)) {
                $message = 'Comanda trebuie să conțină cel puțin un produs.';
                $messageType = 'error';
            } else {
                $orderId = $orderModel->create($orderData, $items);
                if ($orderId) {
                    $message = 'Comanda a fost creată cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la crearea comenzii.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'update':
            $orderId = intval($_POST['order_id'] ?? 0);
            $orderData = [
                'customer_name' => trim($_POST['customer_name'] ?? ''),
                'customer_email' => trim($_POST['customer_email'] ?? ''),
                'shipping_address' => trim($_POST['shipping_address'] ?? ''),
                'status' => $_POST['status'] ?? '',
                'tracking_number' => trim($_POST['tracking_number'] ?? ''),
                'notes' => trim($_POST['notes'] ?? '')
            ];
            
            if ($orderId <= 0 || empty($orderData['customer_name'])) {
                $message = 'Date invalide pentru actualizarea comenzii.';
                $messageType = 'error';
            } else {
                if ($orderModel->update($orderId, $orderData)) {
                    $message = 'Comanda a fost actualizată cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la actualizarea comenzii.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'delete':
            $orderId = intval($_POST['order_id'] ?? 0);
            
            if ($orderId <= 0) {
                $message = 'ID comandă invalid.';
                $messageType = 'error';
            } else {
                if ($orderModel->delete($orderId)) {
                    $message = 'Comanda a fost ștearsă cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la ștergerea comenzii. Doar comenzile cu status "În Așteptare" sau "Anulat" pot fi șterse.';
                    $messageType = 'error';
                }
            }
            break;
    }
}

// Get data for display
$filters = [
    'status' => $_GET['status'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'customer_name' => $_GET['customer_name'] ?? ''
];

$orders = $orderModel->getAllOrders($filters);
$products = $productModel->getProductsWithInventory();
$statuses = $orderModel->getStatuses();

$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <title>Gestionare Comenzi - WMS</title>
</head>
<body class="app">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <main class="main-content">
        <div class="orders-container">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Gestionare Comenzi</h1>
                <button class="btn btn-primary" onclick="openCreateModal()">
                    <span class="material-symbols-outlined">add_shopping_cart</span>
                    Comandă Nouă
                </button>
            </div>

            <!-- Display Messages -->
            <?php if (!empty($message)): ?>
                <div class="message <?= $messageType ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" class="filter-form">
                    <select name="status" onchange="this.form.submit()">
                        <option value="">Toate statusurile</option>
                        <?php foreach ($statuses as $statusKey => $statusLabel): ?>
                            <option value="<?= htmlspecialchars($statusKey) ?>" <?= $filters['status'] === $statusKey ? 'selected' : '' ?>>
                                <?= htmlspecialchars($statusLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from']) ?>" 
                           placeholder="De la data" onchange="this.form.submit()">
                    
                    <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to']) ?>" 
                           placeholder="Până la data" onchange="this.form.submit()">
                    
                    <input type="text" name="customer_name" value="<?= htmlspecialchars($filters['customer_name']) ?>" 
                           placeholder="Numele clientului" onkeyup="debounceFilter(this.form)">
                    
                    <button type="submit" class="btn btn-secondary">
                        <span class="material-symbols-outlined">search</span>
                        Filtrează
                    </button>
                </form>
            </div>

            <!-- Orders Table -->
            <?php if (!empty($orders)): ?>
                <div class="orders-table-container">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Numărul Comenzii</th>
                                <th>Client</th>
                                <th>Data</th>
                                <th>Status</th>
                                <th>Articole</th>
                                <th>Progress</th>
                                <th>Valoare</th>
                                <th>Acțiuni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr class="order-row" data-status="<?= htmlspecialchars($order['status']) ?>">
                                    <td>
                                        <div class="order-number">
                                            <strong><?= htmlspecialchars($order['order_number']) ?></strong>
                                            <?php if ($order['tracking_number']): ?>
                                                <small>Track: <?= htmlspecialchars($order['tracking_number']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="customer-info">
                                            <strong><?= htmlspecialchars($order['customer_name']) ?></strong>
                                            <?php if ($order['customer_email']): ?>
                                                <small><?= htmlspecialchars($order['customer_email']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?= date('d.m.Y H:i', strtotime($order['order_date'])) ?></td>
                                    <td>
                                        <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $order['status'])) ?>">
                                            <?= htmlspecialchars($statuses[$order['status']] ?? $order['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center"><?= number_format($order['item_count']) ?></td>
                                    <td>
                                        <?php 
                                        $progressPercent = $order['total_items'] > 0 ? ($order['picked_items'] / $order['total_items']) * 100 : 0;
                                        ?>
                                        <div class="progress-container">
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?= $progressPercent ?>%"></div>
                                            </div>
                                            <span class="progress-text"><?= number_format($progressPercent, 0) ?>%</span>
                                        </div>
                                        <small><?= $order['picked_items'] ?> / <?= $order['total_items'] ?></small>
                                    </td>
                                    <td class="text-right">
                                        <?php if ($order['total_value']): ?>
                                            <strong><?= number_format($order['total_value'], 2) ?> RON</strong>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <button class="btn btn-sm btn-secondary" onclick="viewOrder(<?= $order['id'] ?>)" title="Vezi detalii">
                                                <span class="material-symbols-outlined">visibility</span>
                                            </button>
                                            <button class="btn btn-sm btn-secondary" onclick="openEditModal(<?= $order['id'] ?>)" title="Editează">
                                                <span class="material-symbols-outlined">edit</span>
                                            </button>
                                            <?php if (in_array($order['status'], [Order::STATUS_PENDING, Order::STATUS_CANCELLED])): ?>
                                                <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $order['id'] ?>, '<?= htmlspecialchars($order['order_number']) ?>')" title="Șterge">
                                                    <span class="material-symbols-outlined">delete</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <span class="material-symbols-outlined">shopping_cart</span>
                    <h3>Nu există comenzi</h3>
                    <p>Creați prima comandă folosind butonul de mai sus.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Create Order Modal -->
    <div id="createOrderModal" class="modal">
        <div class="modal-content modal-large">
            <div class="modal-header">
                <h2 class="modal-title">Comandă Nouă</h2>
                <button class="close" onclick="closeCreateModal()">&times;</button>
            </div>
            <form id="createOrderForm" method="POST">
                <input type="hidden" name="action" value="create">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="order_number" class="form-label">Numărul Comenzii</label>
                        <input type="text" name="order_number" id="order_number" class="form-input" 
                               placeholder="Auto-generat dacă este gol">
                    </div>
                    
                    <div class="form-group">
                        <label for="customer_name" class="form-label">Numele Clientului *</label>
                        <input type="text" name="customer_name" id="customer_name" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="customer_email" class="form-label">Email Client</label>
                        <input type="email" name="customer_email" id="customer_email" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label for="order_date" class="form-label">Data Comenzii</label>
                        <input type="datetime-local" name="order_date" id="order_date" class="form-input" 
                               value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="shipping_address" class="form-label">Adresa de Livrare</label>
                    <textarea name="shipping_address" id="shipping_address" class="form-textarea" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-input">
                        <?php foreach ($statuses as $statusKey => $statusLabel): ?>
                            <option value="<?= htmlspecialchars($statusKey) ?>" <?= $statusKey === Order::STATUS_PENDING ? 'selected' : '' ?>>
                                <?= htmlspecialchars($statusLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Order Items -->
                <div class="form-section">
                    <div class="section-header">
                        <h3>Produse Comandate</h3>
                        <button type="button" class="btn btn-sm btn-success" onclick="addOrderItem()">
                            <span class="material-symbols-outlined">add</span>
                            Adaugă Produs
                        </button>
                    </div>
                    
                    <div id="orderItems">
                        <!-- Order items will be added here dynamically -->
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="notes" class="form-label">Notițe</label>
                    <textarea name="notes" id="notes" class="form-textarea" rows="3"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Anulează</button>
                    <button type="submit" class="btn btn-primary">Creează Comanda</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Order Modal -->
    <div id="editOrderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Editează Comanda</h2>
                <button class="close" onclick="closeEditModal()">&times;</button>
            </div>
            <form id="editOrderForm" method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="order_id" id="edit_order_id">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit_customer_name" class="form-label">Numele Clientului *</label>
                        <input type="text" name="customer_name" id="edit_customer_name" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_customer_email" class="form-label">Email Client</label>
                        <input type="email" name="customer_email" id="edit_customer_email" class="form-input">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="edit_shipping_address" class="form-label">Adresa de Livrare</label>
                    <textarea name="shipping_address" id="edit_shipping_address" class="form-textarea" rows="3"></textarea>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit_status" class="form-label">Status</label>
                        <select name="status" id="edit_status" class="form-input">
                            <?php foreach ($statuses as $statusKey => $statusLabel): ?>
                                <option value="<?= htmlspecialchars($statusKey) ?>">
                                    <?= htmlspecialchars($statusLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_tracking_number" class="form-label">Numărul de Urmărire</label>
                        <input type="text" name="tracking_number" id="edit_tracking_number" class="form-input">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="edit_notes" class="form-label">Notițe</label>
                    <textarea name="notes" id="edit_notes" class="form-textarea" rows="3"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Anulează</button>
                    <button type="submit" class="btn btn-primary">Actualizează</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Order Details Modal -->
    <div id="orderDetailsModal" class="modal">
        <div class="modal-content modal-large">
            <div class="modal-header">
                <h2 class="modal-title">Detalii Comandă</h2>
                <button class="close" onclick="closeDetailsModal()">&times;</button>
            </div>
            <div id="orderDetailsContent">
                <!-- Order details will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content modal-small">
            <div class="modal-header">
                <h2 class="modal-title">Confirmare Ștergere</h2>
                <button class="close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Sunteți sigur că doriți să ștergeți comanda <strong id="deleteOrderNumber"></strong>?</p>
                <p><small>Această acțiune nu poate fi anulată.</small></p>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="order_id" id="deleteOrderId">
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Anulează</button>
                    <button type="submit" class="btn btn-danger">Șterge</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let orderItemCount = 0;
        let filterTimeout;

        // Modal functions
        function openCreateModal() {
            orderItemCount = 0;
            document.getElementById('orderItems').innerHTML = '';
            addOrderItem(); // Add first item by default
            document.getElementById('createOrderModal').style.display = 'block';
        }

        function closeCreateModal() {
            document.getElementById('createOrderModal').style.display = 'none';
            document.getElementById('createOrderForm').reset();
        }

        function openEditModal(orderId) {
            // Load order data via AJAX
            fetch(`order_details.php?id=${orderId}`)
                .then(response => response.json())
                .then(order => {
                    document.getElementById('edit_order_id').value = order.id;
                    document.getElementById('edit_customer_name').value = order.customer_name || '';
                    document.getElementById('edit_customer_email').value = order.customer_email || '';
                    document.getElementById('edit_shipping_address').value = order.shipping_address || '';
                    document.getElementById('edit_status').value = order.status || '';
                    document.getElementById('edit_tracking_number').value = order.tracking_number || '';
                    document.getElementById('edit_notes').value = order.notes || '';
                    
                    document.getElementById('editOrderModal').style.display = 'block';
                })
                .catch(error => {
                    console.error('Error loading order:', error);
                    alert('Eroare la încărcarea comenzii');
                });
        }

        function closeEditModal() {
            document.getElementById('editOrderModal').style.display = 'none';
        }

        function viewOrder(orderId) {
            // Load full order details via AJAX
            fetch(`order_details.php?id=${orderId}&full=1`)
                .then(response => response.json())
                .then(order => {
                    const detailsHtml = generateOrderDetailsHtml(order);
                    document.getElementById('orderDetailsContent').innerHTML = detailsHtml;
                    document.getElementById('orderDetailsModal').style.display = 'block';
                })
                .catch(error => {
                    console.error('Error loading order details:', error);
                    alert('Eroare la încărcarea detaliilor comenzii');
                });
        }

        function closeDetailsModal() {
            document.getElementById('orderDetailsModal').style.display = 'none';
        }

        function confirmDelete(orderId, orderNumber) {
            document.getElementById('deleteOrderId').value = orderId;
            document.getElementById('deleteOrderNumber').textContent = orderNumber;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Order item management
        function addOrderItem() {
            orderItemCount++;
            const itemHtml = `
                <div class="order-item" id="orderItem${orderItemCount}">
                    <div class="item-grid">
                        <div class="form-group">
                            <select name="items[${orderItemCount}][product_id]" class="form-input" required>
                                <option value="">Selectați produsul</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?= $product['product_id'] ?>" data-price="<?= $product['price'] ?>">
                                        <?= htmlspecialchars($product['sku']) ?> - <?= htmlspecialchars($product['name']) ?> (<?= $product['current_stock'] ?> în stoc)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <input type="number" name="items[${orderItemCount}][quantity]" class="form-input" 
                                   placeholder="Cantitate" min="1" required>
                        </div>
                        <div class="form-group">
                            <input type="number" name="items[${orderItemCount}][unit_price]" class="form-input" 
                                   placeholder="Preț unitar" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeOrderItem(${orderItemCount})">
                                <span class="material-symbols-outlined">delete</span>
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('orderItems').insertAdjacentHTML('beforeend', itemHtml);
            
            // Add event listener for automatic price filling
            const productSelect = document.querySelector(`select[name="items[${orderItemCount}][product_id]"]`);
            const priceInput = document.querySelector(`input[name="items[${orderItemCount}][unit_price]"]`);
            
            productSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const price = selectedOption.getAttribute('data-price');
                if (price) {
                    priceInput.value = price;
                }
            });
        }

        function removeOrderItem(itemId) {
            const item = document.getElementById(`orderItem${itemId}`);
            if (item) {
                item.remove();
            }
        }

        function generateOrderDetailsHtml(order) {
            let itemsHtml = '';
            if (order.items && order.items.length > 0) {
                itemsHtml = order.items.map(item => `
                    <tr>
                        <td>${item.sku} - ${item.product_name}</td>
                        <td class="text-center">${item.quantity_ordered}</td>
                        <td class="text-center">${item.picked_quantity}</td>
                        <td class="text-right">${parseFloat(item.unit_price).toFixed(2)} RON</td>
                        <td class="text-right">${parseFloat(item.line_total).toFixed(2)} RON</td>
                    </tr>
                `).join('');
            }
            
            return `
                <div class="order-details">
                    <div class="details-grid">
                        <div class="detail-section">
                            <h4>Informații Comandă</h4>
                            <p><strong>Numărul:</strong> ${order.order_number}</p>
                            <p><strong>Data:</strong> ${new Date(order.order_date).toLocaleString('ro-RO')}</p>
                            <p><strong>Status:</strong> ${order.status}</p>
                            ${order.tracking_number ? `<p><strong>Urmărire:</strong> ${order.tracking_number}</p>` : ''}
                        </div>
                        <div class="detail-section">
                            <h4>Informații Client</h4>
                            <p><strong>Nume:</strong> ${order.customer_name}</p>
                            ${order.customer_email ? `<p><strong>Email:</strong> ${order.customer_email}</p>` : ''}
                            ${order.shipping_address ? `<p><strong>Adresă:</strong><br>${order.shipping_address}</p>` : ''}
                        </div>
                    </div>
                    
                    ${order.items && order.items.length > 0 ? `
                        <div class="items-section">
                            <h4>Produse Comandate</h4>
                            <table class="details-table">
                                <thead>
                                    <tr>
                                        <th>Produs</th>
                                        <th>Comandat</th>
                                        <th>Ridicat</th>
                                        <th>Preț Unitar</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${itemsHtml}
                                </tbody>
                            </table>
                        </div>
                    ` : ''}
                    
                    ${order.notes ? `
                        <div class="notes-section">
                            <h4>Notițe</h4>
                            <p>${order.notes}</p>
                        </div>
                    ` : ''}
                </div>
            `;
        }

        // Filter with debounce
        function debounceFilter(form) {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(() => {
                form.submit();
            }, 500);
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['createOrderModal', 'editOrderModal', 'orderDetailsModal', 'deleteModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }

        // Close modals with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeCreateModal();
                closeEditModal();
                closeDetailsModal();
                closeDeleteModal();
            }
        });
    </script>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>