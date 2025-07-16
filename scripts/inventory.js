  function openAddStockModal() {
            document.getElementById('addStockModal').classList.add('show');
        }

        function closeAddStockModal() {
            document.getElementById('addStockModal').classList.remove('show');
        }

        function openRemoveStockModal(productId, productName) {
            document.getElementById('remove-product-id').value = productId;
            document.getElementById('remove-product-name').textContent = productName;
            document.getElementById('removeStockModal').classList.add('show');
        }

        function closeRemoveStockModal() {
            document.getElementById('removeStockModal').classList.remove('show');
        }

        function openMoveStockModal(item) {
            document.getElementById('move-inventory-id').value = item.id;
            document.getElementById('move-product-id').value = item.product_id;
            document.getElementById('move-from-location-id').value = item.location_id;
            document.getElementById('move-product-name').textContent = item.product_name;
            document.getElementById('available-quantity').textContent = parseInt(item.quantity).toLocaleString();
            document.getElementById('move-quantity').max = item.quantity;
            document.getElementById('moveStockModal').classList.add('show');
        }

        function closeMoveStockModal() {
            document.getElementById('moveStockModal').classList.remove('show');
        }

        function addStockForProduct(productId) {
            document.getElementById('add-product').value = productId;
            openAddStockModal();
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['addStockModal', 'removeStockModal', 'moveStockModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    modal.classList.remove('show');
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