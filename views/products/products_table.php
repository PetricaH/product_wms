<?php
// Partial: views/products/products_table.php
// Expects variables: $allProducts, $search, $category, $sellerId, $totalPages, $offset, $pageSize, $totalCount, $page
?>
<div class="table-container">
    <?php if (!empty($allProducts)): ?>
        <form id="bulkForm" method="POST" action="">
            <input type="hidden" name="action" value="bulk_action">
            <input type="hidden" name="bulk_action" id="bulkActionInput">
            <input type="hidden" name="category" id="bulkCategoryInput">

            <table class="table">
                <thead>
                    <tr>
                        <th width="30">
                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                        </th>
                        <th>SKU</th>
                        <th>Nume Produs</th>
                        <th>Categorie</th>
                        <th>Furnizor</th>
                        <th>U.M.</th>
                        <th>Locație</th>
                        <th>Stoc</th>
                        <th>Status</th>
                        <th width="100">Acțiuni</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($allProducts)): ?>
                    <?php foreach ($allProducts as $product): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="selected_products[]"
                                    value="<?= $product['product_id'] ?>" class="product-checkbox">
                            </td>
                            <td>
                                <span class="sku-badge"><?= htmlspecialchars($product['sku']) ?></span>
                            </td>
                            <td>
                                <div class="product-info">
                                    <strong><?= htmlspecialchars($product['name']) ?></strong>
                                    <?php if (!empty($product['description'])): ?>
                                        <small class="product-description">
                                            <?= htmlspecialchars(substr($product['description'], 0, 60)) ?>
                                            <?= strlen($product['description']) > 60 ? '...' : '' ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($product['category'])): ?>
                                    <span class="category-badge"><?= htmlspecialchars($product['category']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($product['seller_name'])): ?>
                                    <div class="seller-info">
                                        <strong class="seller-name"><?= htmlspecialchars($product['seller_name']) ?></strong>
                                        <?php if (!empty($product['seller_contact'])): ?>
                                            <small class="seller-contact">
                                                <?= htmlspecialchars($product['seller_contact']) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted no-seller">Fără furnizor</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($product['unit_of_measure'])): ?>
                                    <span class="unit-badge"><?= htmlspecialchars($product['unit_of_measure']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($product['location_details'])): ?>
                                    <span class="location-info"><?= htmlspecialchars($product['location_details']) ?></span>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="assignLocationForProduct(<?= $product['product_id'] ?>)">
                                        Atribuie Locatie
                                    </button>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                    $rawCurrentStock = $product['current_stock'] ?? null;
                                    $currentStockValue = is_numeric($rawCurrentStock) ? (float) $rawCurrentStock : 0.0;
                                    $formattedStock = number_format($currentStockValue);
                                    $rawMinStock = $product['min_stock_level'] ?? null;
                                    $minStockLevel = is_numeric($rawMinStock) ? (float) $rawMinStock : 5.0;
                                ?>
                                <div class="quantity-info">
                                    <span class="quantity-value"><?= $formattedStock ?></span>
                                    <?php if ($currentStockValue <= $minStockLevel): ?>
                                        <span class="low-stock-indicator" title="Stoc scăzut">
                                            <span class="material-symbols-outlined">warning</span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge status-<?= $product['status'] ?? 'active' ?>">
                                    <?= ($product['status'] ?? 'active') === 'active' ? 'Activ' : 'Inactiv' ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button type="button" class="btn btn-sm btn-primary"
                                            onclick="openEditModal(<?= htmlspecialchars(json_encode($product)) ?>)"
                                            title="Editează produs">
                                        <span class="material-symbols-outlined">edit</span>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger"
                                            onclick="confirmDelete(<?= $product['product_id'] ?>, '<?= htmlspecialchars($product['name']) ?>')"
                                            title="Șterge produs">
                                        <span class="material-symbols-outlined">delete</span>
                                    </button>

                                    <!-- SmartBill sync button if product has no seller -->
                                    <!-- <?php if (empty($product['seller_id'])): ?>
                                        <button type="button" class="btn btn-sm btn-info"
                                                onclick="suggestSeller(<?= $product['product_id'] ?>)"
                                                title="Sugerează furnizor">
                                            <span class="material-symbols-outlined">auto_fix_high</span>
                                        </button>
                                    <?php endif; ?> -->

                                    <!-- Existing label printing and other actions -->
                                    <button type="button" class="btn btn-sm btn-secondary"
                                            onclick="printLabels(<?= $product['product_id'] ?>, '<?= htmlspecialchars($product['name']) ?>')"
                                            title="Printează etichete">
                                        <span class="material-symbols-outlined">local_printshop</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="text-center">
                            <div class="empty-state">
                                <span class="material-symbols-outlined">inventory_2</span>
                                <h3>Nu există produse</h3>
                                <p>
                                    <?php if (!empty($search) || !empty($category) || !empty($sellerId)): ?>
                                        Nu s-au găsit produse cu criteriile selectate.
                                        <a href="?" class="btn btn-secondary">Șterge filtrele</a>
                                    <?php else: ?>
                                        Adaugă primul produs folosind butonul "Nou Produs".
                                    <?php endif; ?>
                                </p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </form>

        <!-- Compact Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination-container">
                <div class="pagination-info">
                    Afișare <?= ($offset + 1) ?>-<?= min($offset + $pageSize, $totalCount) ?> din <?= number_format($totalCount) ?> produse
                </div>
                <div class="pagination-controls">
                    <?php if ($page > 1): ?>
                        <a href="?page=1&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>" class="pagination-btn">Prima</a>
                        <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>&seller_id=<?= $sellerId ?>" class="pagination-btn">‹</a>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);

                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="pagination-btn active"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>" class="pagination-btn"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>" class="pagination-btn">›</a>
                        <a href="?page=<?= $totalPages ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>" class="pagination-btn">Ultima</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="empty-state">
            <span class="material-symbols-outlined">inventory_2</span>
            <h3>Nu există produse</h3>
            <p>
                <?php if ($search || $category): ?>
                    Nu s-au găsit produse cu criteriile selectate.
                    <a href="?" class="btn btn-secondary">Șterge filtrele</a>
                <?php else: ?>
                    Adaugă primul produs folosind butonul "Nou Produs".
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>
</div>
