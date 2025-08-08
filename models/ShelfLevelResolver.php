<?php
/**
 * ShelfLevelResolver
 * Helper to resolve shelf level names based on subdivision assignment
 */
class ShelfLevelResolver {
    /**
     * Resolve the proper shelf level name for a subdivision.
     *
     * @param PDO      $conn              Database connection
     * @param int      $locationId        Location ID
     * @param int      $productId         Product ID
     * @param int|null $subdivisionNumber Subdivision number
     *
     * @return string|null Level name if found, null otherwise
     */
    public static function getCorrectShelfLevel(PDO $conn, int $locationId, int $productId, ?int $subdivisionNumber): ?string {
        if ($subdivisionNumber === null) {
            return null;
        }

        try {
            $stmt = $conn->prepare(
                'SELECT lls.level_name
                 FROM location_subdivisions ls
                 JOIN location_level_settings lls ON ls.location_id = lls.location_id AND ls.level_number = lls.level_number
                 WHERE ls.location_id = :location_id
                   AND ls.subdivision_number = :subdivision_number
                   AND (ls.dedicated_product_id = :product_id OR ls.dedicated_product_id IS NULL)
                 ORDER BY ls.dedicated_product_id IS NULL
                 LIMIT 1'
            );
            $stmt->execute([
                ':location_id' => $locationId,
                ':subdivision_number' => $subdivisionNumber,
                ':product_id' => $productId
            ]);
            $levelName = $stmt->fetchColumn();
            if ($levelName !== false && $levelName !== null && $levelName !== '') {
                return $levelName;
            }
        } catch (PDOException $e) {
            error_log('ShelfLevelResolver error: ' . $e->getMessage());
        }

        return null;
    }
}
