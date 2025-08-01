<?php
/**
 * Migration: Add WebAuthn Support
 * Adds tables for storing WebAuthn credentials and authentication data
 */

class AddWebAuthnSupportMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        echo "🔐 Adding WebAuthn support...\n\n";
        
        try {
            // 1. Add WebAuthn credentials table
            echo "📱 Creating webauthn_credentials table...\n";
            $pdo->exec("
                CREATE TABLE webauthn_credentials (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    credential_id VARCHAR(255) NOT NULL UNIQUE,
                    public_key TEXT NOT NULL,
                    counter BIGINT UNSIGNED DEFAULT 0,
                    device_name VARCHAR(100),
                    aaguid VARCHAR(36),
                    attestation_format VARCHAR(50),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    last_used_at TIMESTAMP NULL,
                    is_active TINYINT(1) DEFAULT 1,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_user_id (user_id),
                    INDEX idx_credential_id (credential_id),
                    INDEX idx_active (is_active)
                )
            ");

            // 2. Add WebAuthn challenges table (for temporary storage during auth)
            echo "🎯 Creating webauthn_challenges table...\n";
            $pdo->exec("
                CREATE TABLE webauthn_challenges (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    user_id INT NULL,
                    challenge VARCHAR(255) NOT NULL,
                    type ENUM('registration', 'authentication') NOT NULL,
                    expires_at TIMESTAMP NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_challenge (challenge),
                    INDEX idx_expires (expires_at),
                    INDEX idx_user_type (user_id, type)
                )
            ");

            // 3. Add columns to users table for WebAuthn preferences
            echo "👤 Adding WebAuthn columns to users table...\n";
            $pdo->exec("
                ALTER TABLE users 
                ADD COLUMN webauthn_enabled TINYINT(1) DEFAULT 0,
                ADD COLUMN webauthn_user_handle VARCHAR(64) NULL UNIQUE,
                ADD COLUMN require_webauthn TINYINT(1) DEFAULT 0
            ");

            // 4. Create cleanup procedure for expired challenges
            echo "🧹 Creating cleanup procedure...\n";
            $pdo->exec("
                CREATE EVENT IF NOT EXISTS cleanup_expired_webauthn_challenges
                ON SCHEDULE EVERY 1 HOUR
                DO
                DELETE FROM webauthn_challenges WHERE expires_at < NOW()
            ");

            echo "✅ WebAuthn support added successfully!\n\n";
            
        } catch (PDOException $e) {
            echo "❌ Database error: " . $e->getMessage() . "\n";
            throw $e;
        } catch (Exception $e) {
            echo "❌ General error: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        echo "🗑️ Rolling back WebAuthn support...\n";
        
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        // Drop event
        $pdo->exec("DROP EVENT IF EXISTS cleanup_expired_webauthn_challenges");
        
        // Remove columns from users table
        $pdo->exec("
            ALTER TABLE users 
            DROP COLUMN IF EXISTS webauthn_enabled,
            DROP COLUMN IF EXISTS webauthn_user_handle,
            DROP COLUMN IF EXISTS require_webauthn
        ");
        
        // Drop tables
        $pdo->exec("DROP TABLE IF EXISTS webauthn_challenges");
        $pdo->exec("DROP TABLE IF EXISTS webauthn_credentials");
        
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        echo "✅ WebAuthn support rollback completed!\n\n";
    }
}

// Return instance for migration runner
return new AddWebAuthnSupportMigration();