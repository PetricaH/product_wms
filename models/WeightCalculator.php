<?php
/**
 * Weight and Parcels Calculator Model
 * File: models/WeightCalculator.php
 * 
 * Smart calculation logic for order weights and parcel counts
 * based on product types, units, and packaging rules
 */

class WeightCalculator 
{
    private $conn;
    private $config;
    private $packagingRules;
    
    public function __construct($conn) {
        $this->conn = $conn;
        $this->loadConfiguration();
        $this->loadPackagingRules();
    }
    
    /**
     * Load system configuration
     */
    private function loadConfiguration() {
        $stmt = $this->conn->prepare("
            SELECT setting_key, setting_value, setting_type 
            FROM cargus_config 
            WHERE active = 1
        ");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->config = [];
        foreach ($settings as $setting) {
            $value = $setting['setting_value'];
            
            switch ($setting['setting_type']) {
                case 'integer':
                    $value = (int)$value;
                    break;
                case 'decimal':
                    $value = (float)$value;
                    break;
                case 'boolean':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'json':
                    $value = json_decode($value, true);
                    break;
            }
            
            $this->config[$setting['setting_key']] = $value;
        }
    }
    
    /**
     * Load packaging rules ordered by priority
     */
    private function loadPackagingRules() {
        $stmt = $this->conn->prepare("
            SELECT * FROM packaging_rules 
            WHERE active = 1 
            ORDER BY priority DESC
        ");
        $stmt->execute();
        $this->packagingRules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Calculate complete shipping information for an order
     */
    public function calculateOrderShipping($orderId) {
        $orderItems = $this->getOrderItemsWithUnits($orderId);
        
        if (empty($orderItems)) {
            return $this->getDefaultShippingData();
        }
        
        // Group items by packaging requirements
        $itemGroups = $this->groupItemsByPackaging($orderItems);
        
        // Calculate parcels for each group
        $parcels = [];
        $totalWeight = 0;
        $packageContentItems = [];
        
        foreach ($itemGroups as $groupType => $items) {
            $groupParcels = $this->calculateParcelsForGroup($items, $groupType);
            $parcels = array_merge($parcels, $groupParcels);
            
            foreach ($items as $item) {
                $itemWeight = $item['quantity'] * $item['weight_per_unit'];
                $totalWeight += $itemWeight;
                $packageContentItems[] = $item['product_name'] . ' (' . $item['quantity'] . ' ' . $item['unit_measure'] . ')';
            }
        }
        
        // Optimize parcels if possible
        $optimizedParcels = $this->optimizeParcels($parcels);
        
        return [
            'total_weight' => max($totalWeight, 0.1), // Minimum 100g
            'parcels_count' => count($optimizedParcels),
            'envelopes_count' => $this->calculateEnvelopes($orderItems),
            'parcels_detail' => $optimizedParcels,
            'package_content' => implode(', ', $packageContentItems),
            'shipping_notes' => $this->generateShippingNotes($itemGroups),
            'calculation_metadata' => [
                'item_groups' => array_keys($itemGroups),
                'rules_applied' => $this->getAppliedRules($itemGroups),
                'optimization_applied' => count($parcels) !== count($optimizedParcels)
            ]
        ];
    }
    
    /**
     * Get order items with their unit and weight data
     */
    private function getOrderItemsWithUnits($orderId) {
        $stmt = $this->conn->prepare("
            SELECT 
                oi.quantity,
                oi.unit_measure,
                oi.notes,
                p.id as product_id,
                p.name as product_name,
                p.code as product_code,
                p.category as product_category,
                COALESCE(pu.weight_per_unit, ut.default_weight_per_unit, 0.5) as weight_per_unit,
                COALESCE(pu.volume_per_unit, 0) as volume_per_unit,
                COALESCE(pu.fragile, 0) as fragile,
                COALESCE(pu.hazardous, 0) as hazardous,
                COALESCE(pu.temperature_controlled, 0) as temperature_controlled,
                COALESCE(pu.dimensions_length, 0) as dimensions_length,
                COALESCE(pu.dimensions_width, 0) as dimensions_width,
                COALESCE(pu.dimensions_height, 0) as dimensions_height,
                COALESCE(pu.max_stack_height, 1) as max_stack_height,
                ut.unit_code,
                ut.unit_name,
                ut.base_type,
                ut.packaging_type,
                COALESCE(ut.max_items_per_parcel, 10) as max_items_per_parcel,
                COALESCE(ut.requires_separate_parcel, 0) as requires_separate_parcel
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            LEFT JOIN product_units pu ON p.id = pu.product_id 
            LEFT JOIN unit_types ut ON pu.unit_type_id = ut.id AND ut.unit_code = oi.unit_measure
            WHERE oi.order_id = ?
            ORDER BY 
                ut.requires_separate_parcel DESC,
                ut.packaging_type = 'liquid' DESC,
                pu.hazardous DESC,
                pu.fragile DESC,
                oi.quantity * COALESCE(pu.weight_per_unit, ut.default_weight_per_unit, 0.5) DESC
        ");
        
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Group items by their packaging requirements
     */
    private function groupItemsByPackaging($items) {
        $groups = [
            'liquids_separate' => [],
            'hazardous_separate' => [],
            'fragile_separate' => [],
            'heavy_items' => [],
            'temperature_controlled' => [],
            'standard_combinable' => []
        ];
        
        foreach ($items as $item) {
            $itemWeight = $item['quantity'] * $item['weight_per_unit'];
            
            // Apply packaging rules in priority order
            if ($item['requires_separate_parcel'] || $item['packaging_type'] === 'liquid') {
                $groups['liquids_separate'][] = $item;
            } elseif ($item['hazardous']) {
                $groups['hazardous_separate'][] = $item;
            } elseif ($item['temperature_controlled']) {
                $groups['temperature_controlled'][] = $item;
            } elseif ($item['fragile'] && $itemWeight > 2.0) {
                $groups['fragile_separate'][] = $item;
            } elseif ($itemWeight > 15.0) {
                $groups['heavy_items'][] = $item;
            } else {
                $groups['standard_combinable'][] = $item;
            }
        }
        
        // Remove empty groups
        return array_filter($groups, function($group) {
            return !empty($group);
        });
    }
    
    /**
     * Calculate parcels for a specific group of items
     */
    private function calculateParcelsForGroup($items, $groupType) {
        $parcels = [];
        
        switch ($groupType) {
            case 'liquids_separate':
                // Each liquid item gets its own parcel
                foreach ($items as $item) {
                    for ($i = 0; $i < $item['quantity']; $i++) {
                        $parcels[] = [
                            'weight' => $item['weight_per_unit'],
                            'items' => 1,
                            'type' => 'liquid',
                            'content' => $item['product_name'],
                            'special_handling' => ['liquid', 'fragile']
                        ];
                    }
                }
                break;
                
            case 'hazardous_separate':
                // Hazardous items in separate parcels
                foreach ($items as $item) {
                    $parcels[] = [
                        'weight' => $item['quantity'] * $item['weight_per_unit'],
                        'items' => $item['quantity'],
                        'type' => 'hazardous',
                        'content' => $item['product_name'],
                        'special_handling' => ['hazardous']
                    ];
                }
                break;
                
            case 'temperature_controlled':
                // Temperature controlled items together
                $parcels = $this->packItemsWithRules($items, [
                    'max_weight' => 20.0,
                    'max_items' => 15,
                    'type' => 'temperature_controlled',
                    'special_handling' => ['temperature_controlled']
                ]);
                break;
                
            case 'fragile_separate':
                // Fragile heavy items individually
                foreach ($items as $item) {
                    $parcels[] = [
                        'weight' => $item['quantity'] * $item['weight_per_unit'],
                        'items' => $item['quantity'],
                        'type' => 'fragile',
                        'content' => $item['product_name'],
                        'special_handling' => ['fragile']
                    ];
                }
                break;
                
            case 'heavy_items':
                // Heavy items with weight-based packing
                $parcels = $this->packItemsWithRules($items, [
                    'max_weight' => 25.0,
                    'max_items' => 5,
                    'type' => 'heavy',
                    'special_handling' => []
                ]);
                break;
                
            case 'standard_combinable':
                // Standard items can be combined efficiently
                $parcels = $this->packItemsWithRules($items, [
                    'max_weight' => 20.0,
                    'max_items' => 25,
                    'type' => 'standard',
                    'special_handling' => []
                ]);
                break;
        }
        
        return $parcels;
    }
    
    /**
     * Pack items following specific rules
     */
    private function packItemsWithRules($items, $rules) {
        $parcels = [];
        $currentParcel = [
            'weight' => 0,
            'items' => 0,
            'type' => $rules['type'],
            'content_items' => [],
            'special_handling' => $rules['special_handling']
        ];
        
        foreach ($items as $item) {
            $itemTotalWeight = $item['quantity'] * $item['weight_per_unit'];
            $quantity = $item['quantity'];
            
            // Check if item can fit in current parcel
            while ($quantity > 0) {
                $maxFitByWeight = floor(($rules['max_weight'] - $currentParcel['weight']) / $item['weight_per_unit']);
                $maxFitByCount = $rules['max_items'] - $currentParcel['items'];
                $maxFit = min($maxFitByWeight, $maxFitByCount, $quantity);
                
                if ($maxFit <= 0) {
                    // Close current parcel and start new one
                    if ($currentParcel['items'] > 0) {
                        $currentParcel['content'] = implode(', ', $currentParcel['content_items']);
                        unset($currentParcel['content_items']);
                        $parcels[] = $currentParcel;
                    }
                    
                    $currentParcel = [
                        'weight' => 0,
                        'items' => 0,
                        'type' => $rules['type'],
                        'content_items' => [],
                        'special_handling' => $rules['special_handling']
                    ];
                    continue;
                }
                
                // Add items to current parcel
                $currentParcel['weight'] += $maxFit * $item['weight_per_unit'];
                $currentParcel['items'] += $maxFit;
                $currentParcel['content_items'][] = $item['product_name'] . ' (' . $maxFit . ')';
                $quantity -= $maxFit;
            }
        }
        
        // Close last parcel if it has items
        if ($currentParcel['items'] > 0) {
            $currentParcel['content'] = implode(', ', $currentParcel['content_items']);
            unset($currentParcel['content_items']);
            $parcels[] = $currentParcel;
        }
        
        return $parcels;
    }
    
    /**
     * Optimize parcels by combining where possible
     */
    private function optimizeParcels($parcels) {
        if (!$this->config['auto_calculate_parcels'] ?? true) {
            return $parcels;
        }
        
        $optimized = [];
        $combinableParcels = [];
        
        // Separate parcels that can be combined from those that cannot
        foreach ($parcels as $parcel) {
            if (in_array($parcel['type'], ['liquid', 'hazardous', 'temperature_controlled'])) {
                $optimized[] = $parcel; // Cannot be combined
            } else {
                $combinableParcels[] = $parcel;
            }
        }
        
        // Try to combine standard and fragile parcels
        if (!empty($combinableParcels)) {
            $combined = $this->combineCompatibleParcels($combinableParcels);
            $optimized = array_merge($optimized, $combined);
        }
        
        return $optimized;
    }
    
    /**
     * Combine compatible parcels
     */
    private function combineCompatibleParcels($parcels) {
        $combined = [];
        $maxWeight = 25.0;
        $maxItems = 30;
        
        foreach ($parcels as $parcel) {
            $added = false;
            
            // Try to add to existing compatible parcel
            foreach ($combined as &$existingParcel) {
                if ($this->canCombineParcels($existingParcel, $parcel) &&
                    ($existingParcel['weight'] + $parcel['weight']) <= $maxWeight &&
                    ($existingParcel['items'] + $parcel['items']) <= $maxItems) {
                    
                    $existingParcel['weight'] += $parcel['weight'];
                    $existingParcel['items'] += $parcel['items'];
                    $existingParcel['content'] .= ', ' . $parcel['content'];
                    $existingParcel['special_handling'] = array_unique(
                        array_merge($existingParcel['special_handling'], $parcel['special_handling'])
                    );
                    $added = true;
                    break;
                }
            }
            
            if (!$added) {
                $combined[] = $parcel;
            }
        }
        
        return $combined;
    }
    
    /**
     * Check if two parcels can be combined
     */
    private function canCombineParcels($parcel1, $parcel2) {
        // Cannot combine different special handling types
        $incompatible = ['liquid', 'hazardous', 'temperature_controlled'];
        
        foreach ($incompatible as $type) {
            if ((in_array($type, $parcel1['special_handling']) && !in_array($type, $parcel2['special_handling'])) ||
                (!in_array($type, $parcel1['special_handling']) && in_array($type, $parcel2['special_handling']))) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Calculate number of envelopes (for small, light items)
     */
    private function calculateEnvelopes($items) {
        $envelopeItems = 0;
        
        foreach ($items as $item) {
            $itemWeight = $item['weight_per_unit'];
            
            // Items under 100g and not fragile can go in envelopes
            if ($itemWeight < 0.1 && !$item['fragile'] && !$item['hazardous'] && 
                $item['packaging_type'] !== 'liquid') {
                $envelopeItems += $item['quantity'];
            }
        }
        
        // Max 9 envelopes, each can hold multiple small items
        return min(9, ceil($envelopeItems / 10));
    }
    
    /**
     * Generate shipping notes based on item groups
     */
    private function generateShippingNotes($itemGroups) {
        $notes = [];
        
        if (isset($itemGroups['liquids_separate'])) {
            $notes[] = 'Conține lichide - ambalare separată';
        }
        if (isset($itemGroups['hazardous_separate'])) {
            $notes[] = 'Conține materiale periculoase';
        }
        if (isset($itemGroups['temperature_controlled'])) {
            $notes[] = 'Necesită control temperatură';
        }
        if (isset($itemGroups['fragile_separate'])) {
            $notes[] = 'Conține articole fragile';
        }
        
        return implode('; ', $notes);
    }
    
    /**
     * Get rules that were applied during calculation
     */
    private function getAppliedRules($itemGroups) {
        $rules = [];
        
        foreach ($itemGroups as $groupType => $items) {
            $rules[] = $groupType . ' (' . count($items) . ' items)';
        }
        
        return $rules;
    }
    
    /**
     * Get default shipping data when no items found
     */
    private function getDefaultShippingData() {
        return [
            'total_weight' => 0.1, // Minimum weight
            'parcels_count' => 1,
            'envelopes_count' => 0,
            'parcels_detail' => [[
                'weight' => 0.1,
                'items' => 0,
                'type' => 'default',
                'content' => 'Comandă fără articole',
                'special_handling' => []
            ]],
            'package_content' => 'Comandă fără articole',
            'shipping_notes' => '',
            'calculation_metadata' => [
                'item_groups' => [],
                'rules_applied' => ['default'],
                'optimization_applied' => false
            ]
        ];
    }
    
    /**
     * Recalculate order weights and update database
     */
    public function recalculateAndUpdateOrder($orderId) {
        $shippingData = $this->calculateOrderShipping($orderId);
        
        $updateQuery = "
            UPDATE orders SET 
                total_weight = ?,
                parcels_count = ?,
                envelopes_count = ?,
                package_content = ?,
                observations = COALESCE(observations, '') || ?
            WHERE id = ?
        ";
        
        $notes = $shippingData['shipping_notes'] ? 
                ' | Calc: ' . $shippingData['shipping_notes'] : '';
        
        $stmt = $this->conn->prepare($updateQuery);
        $result = $stmt->execute([
            $shippingData['total_weight'],
            $shippingData['parcels_count'],
            $shippingData['envelopes_count'],
            $shippingData['package_content'],
            $notes,
            $orderId
        ]);
        
        return [
            'success' => $result,
            'shipping_data' => $shippingData
        ];
    }
    
    /**
     * Get weight estimation for specific products and quantities
     */
    public function estimateWeight($productQuantities) {
        $totalWeight = 0;
        
        foreach ($productQuantities as $productId => $data) {
            $quantity = $data['quantity'];
            $unitMeasure = $data['unit_measure'];
            
            $stmt = $this->conn->prepare("
                SELECT COALESCE(pu.weight_per_unit, ut.default_weight_per_unit, 0.5) as weight_per_unit
                FROM products p
                LEFT JOIN product_units pu ON p.id = pu.product_id
                LEFT JOIN unit_types ut ON pu.unit_type_id = ut.id AND ut.unit_code = ?
                WHERE p.id = ?
            ");
            
            $stmt->execute([$unitMeasure, $productId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $totalWeight += $quantity * $result['weight_per_unit'];
            }
        }
        
        return max($totalWeight, 0.1);
    }
}