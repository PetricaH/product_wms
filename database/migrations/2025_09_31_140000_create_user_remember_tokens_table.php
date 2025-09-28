<?php

class CreateUserRememberTokensTable
{
    public function up(PDO $pdo)
    {
        $sql = <<<SQL
        CREATE TABLE `user_remember_tokens` (
          `id` int NOT NULL AUTO_INCREMENT,
          `user_id` int NOT NULL,
          `selector` varchar(32) NOT NULL,
          `validator_hash` char(64) NOT NULL,
          `expires_at` datetime NOT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_selector` (`selector`),
          KEY `idx_user_id` (`user_id`),
          KEY `idx_expires_at` (`expires_at`),
          CONSTRAINT `fk_remember_tokens_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL;

        try {
            $pdo->exec($sql);
            echo "✅ Table 'user_remember_tokens' created successfully.\n";
        } catch (PDOException $e) {
            echo "❌ Error creating 'user_remember_tokens' table: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    public function down(PDO $pdo)
    {
        $sql = "DROP TABLE IF EXISTS `user_remember_tokens`;";

        try {
            $pdo->exec($sql);
            echo "✅ Table 'user_remember_tokens' dropped successfully.\n";
        } catch (PDOException $e) {
            echo "❌ Error dropping 'user_remember_tokens' table: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}

return new CreateUserRememberTokensTable();
