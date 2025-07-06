// Modal functions
        function openAddStockModal() {
            document.getElementById('addStockModal').style.display = 'block';
        }

        function closeAddStockModal() {
            document.getElementById('addStockModal').style.display = 'none';
        }

        function openRemoveStockModal() {
            document.getElementById('removeStockModal').style.display = 'block';
        }

        function closeRemoveStockModal() {
            document.getElementById('removeStockModal').style.display = 'none';
        }

        function openMoveStockModal(item) {
            document.getElementById('move_inventory_id').value = item.id;
            document.getElementById('move_quantity').max = item.quantity;
            
            const infoHtml = `
                <h4>Informații Stoc</h4>
                <p><strong>Produs:</strong> ${item.sku} - ${item.product_name}</p>
                <p><strong>Locația Curentă:</strong> ${item.location_code} (${item.zone})</p>
                <p><strong>Cantitate Disponibilă:</strong> ${parseInt(item.quantity).toLocaleString()}</p>
                ${item.batch_number ? `<p><strong>Batch:</strong> ${item.batch_number}</p>` : ''}
                ${item.lot_number ? `<p><strong>Lot:</strong> ${item.lot_number}</p>` : ''}
            `;
            
            document.getElementById('move_stock_info').innerHTML = infoHtml;
            document.getElementById('moveStockModal').style.display = 'block';
        }

        function closeMoveStockModal() {
            document.getElementById('moveStockModal').style.display = 'none';
        }

        function viewProductDetails(productId) {
            window.location.href = `?view=detailed&product_id=${productId}`;
        }

        function quickAddStock(productId) {
            document.getElementById('add_product_id').value = productId;
            openAddStockModal();
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['addStockModal', 'removeStockModal', 'moveStockModal'];
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
                closeAddStockModal();
                closeRemoveStockModal();
                closeMoveStockModal();
            }
        });