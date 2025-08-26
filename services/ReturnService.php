<?php
/**
 * ReturnService
 * Simplified service layer for processing returns.
 */

class ReturnService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function startReturn(string $orderNumber, int $processedBy): int {
        $stmt = $this->db->prepare("SELECT id, status FROM orders WHERE order_number = :num");
        $stmt->execute([':num' => $orderNumber]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new InvalidArgumentException('Order not found');
        }
        if ($order['status'] !== 'shipped') {
            throw new RuntimeException('Order not shipped');
        }
        $check = $this->db->prepare("SELECT id FROM returns WHERE order_id = :oid AND status IN ('in_progress','pending')");
        $check->execute([':oid' => $order['id']]);
        if ($check->fetch()) {
            throw new RuntimeException('Return already in progress');
        }
        $ins = $this->db->prepare("INSERT INTO returns (order_id, processed_by, status) VALUES (:oid, :pid, 'in_progress')");
        $ins->execute([':oid' => $order['id'], ':pid' => $processedBy]);
        return (int)$this->db->lastInsertId();
    }

    public function verifyItem(int $returnId, int $productId, int $qty, string $condition = 'good', bool $isExtra = false): void {
        $ret = $this->db->prepare("SELECT order_id FROM returns WHERE id = :id AND status = 'in_progress'");
        $ret->execute([':id' => $returnId]);
        $row = $ret->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Return not active');
        }
        $orderId = (int)$row['order_id'];
        $oi = $this->db->prepare("SELECT id, quantity FROM order_items WHERE order_id = :oid AND product_id = :pid");
        $oi->execute([':oid' => $orderId, ':pid' => $productId]);
        $orderItem = $oi->fetch(PDO::FETCH_ASSOC);
        $orderItemId = $orderItem['id'] ?? null;
        $expected = $orderItem['quantity'] ?? 0;
        $ins = $this->db->prepare("INSERT INTO return_items (return_id, order_item_id, product_id, quantity_returned, item_condition, is_extra)
                                   VALUES (:rid, :oiid, :pid, :qty, :cond, :extra)");
        $ins->execute([
            ':rid' => $returnId,
            ':oiid' => $orderItemId,
            ':pid' => $productId,
            ':qty' => $qty,
            ':cond' => $condition,
            ':extra' => $isExtra ? 1 : 0
        ]);
        if ($qty > $expected || $isExtra) {
            $this->recordDiscrepancy($returnId, $orderItemId, $productId, $isExtra ? 'extra' : 'damaged', $expected, $qty, $condition);
        }
    }

    public function completeReturn(int $returnId, int $verifiedBy): void {
        $up = $this->db->prepare("UPDATE returns SET status = 'completed', verified_by = :vb, verified_at = CURRENT_TIMESTAMP WHERE id = :id");
        $up->execute([':id' => $returnId, ':vb' => $verifiedBy]);
    }

    public function recordDiscrepancy(?int $returnId, ?int $orderItemId, int $productId, string $type,
        int $expected, int $actual, string $condition = 'good', string $notes = ''): int {
        $check = $this->db->prepare("SELECT id FROM return_discrepancies WHERE return_id = :rid AND product_id = :pid AND discrepancy_type = :dtype");
        $check->execute([':rid' => $returnId, ':pid' => $productId, ':dtype' => $type]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $upd = $this->db->prepare("UPDATE return_discrepancies SET expected_quantity = expected_quantity + :exp, actual_quantity = actual_quantity + :act, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $upd->execute([':exp' => $expected, ':act' => $actual, ':id' => $existing['id']]);
            return (int)$existing['id'];
        }
        $ins = $this->db->prepare("INSERT INTO return_discrepancies (return_id, order_item_id, product_id, discrepancy_type, expected_quantity, actual_quantity, item_condition, notes)
                                    VALUES (:rid, :oiid, :pid, :dtype, :exp, :act, :cond, :notes)");
        $ins->bindValue(':rid', $returnId, PDO::PARAM_INT);
        $ins->bindValue(':oiid', $orderItemId, $orderItemId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $ins->bindValue(':pid', $productId, PDO::PARAM_INT);
        $ins->bindValue(':dtype', $type, PDO::PARAM_STR);
        $ins->bindValue(':exp', $expected, PDO::PARAM_INT);
        $ins->bindValue(':act', $actual, PDO::PARAM_INT);
        $ins->bindValue(':cond', $condition, PDO::PARAM_STR);
        $ins->bindValue(':notes', $notes, PDO::PARAM_STR);
        $ins->execute();
        return (int)$this->db->lastInsertId();
    }
}
