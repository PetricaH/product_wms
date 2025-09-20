<?php

/**
 * Migrare: Crearea infrastructurii pentru șabloanele de email.
 */
class CreateEmailTemplatesTable
{
    /**
     * Rulare migrare.
     */
    public function up(PDO $pdo): void
    {
        try {
            $pdo->beginTransaction();

            $createTableSql = <<<'SQL'
                CREATE TABLE IF NOT EXISTS email_templates (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    template_type VARCHAR(50) NOT NULL DEFAULT 'auto_order',
                    template_name VARCHAR(100) NOT NULL,
                    subject_template TEXT NULL,
                    body_template TEXT NULL,
                    is_active TINYINT(1) DEFAULT 1,
                    is_default TINYINT(1) DEFAULT 0,
                    variables_used JSON NULL COMMENT 'Array of variables used in template',
                    created_by INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_template_type (template_type),
                    INDEX idx_is_active (is_active),
                    INDEX idx_is_default (is_default),
                    CONSTRAINT fk_email_templates_users_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL;
            $pdo->exec($createTableSql);

            $variables = [
                'COMPANY_NAME',
                'COMPANY_ADDRESS',
                'COMPANY_PHONE',
                'COMPANY_EMAIL',
                'ORDER_NUMBER',
                'ORDER_DATE',
                'ORDER_TIME',
                'DELIVERY_DATE',
                'ORDER_TOTAL',
                'PRODUCT_NAME',
                'PRODUCT_CODE',
                'ORDER_QUANTITY',
                'UNIT_PRICE',
                'TOTAL_PRICE',
                'UNIT_MEASURE',
                'SUPPLIER_NAME',
                'SUPPLIER_EMAIL',
                'SUPPLIER_PHONE',
                'CURRENT_DATE',
                'CURRENT_TIME'
            ];

            $variablesJson = json_encode($variables, JSON_UNESCAPED_UNICODE);
            if ($variablesJson === false) {
                throw new RuntimeException('Nu s-a putut serializa lista variabilelor implicite pentru șablonul de email.');
            }

            $defaultSubject = 'Comandă automată {{ORDER_NUMBER}} - {{COMPANY_NAME}}';

            $defaultBody = <<<'HTML'
                <p>Stimate {{SUPPLIER_NAME}},</p>
                <p>Vă transmitem comanda automată cu numărul <strong>{{ORDER_NUMBER}}</strong>, generată în data de {{ORDER_DATE}} la ora {{ORDER_TIME}}. Solicităm livrarea până la data de {{DELIVERY_DATE}}.</p>
                <p>Datele companiei noastre sunt următoarele:</p>
                <ul>
                    <li><strong>Companie:</strong> {{COMPANY_NAME}}</li>
                    <li><strong>Adresă:</strong> {{COMPANY_ADDRESS}}</li>
                    <li><strong>Telefon:</strong> {{COMPANY_PHONE}}</li>
                    <li><strong>Email:</strong> {{COMPANY_EMAIL}}</li>
                </ul>
                <p>Rezumatul comenzii:</p>
                <table border="1" cellpadding="6" cellspacing="0" width="100%">
                    <thead>
                        <tr>
                            <th>Cod produs</th>
                            <th>Denumire</th>
                            <th>Cantitate</th>
                            <th>UM</th>
                            <th>Preț unitar</th>
                            <th>Valoare totală</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>{{PRODUCT_CODE}}</td>
                            <td>{{PRODUCT_NAME}}</td>
                            <td>{{ORDER_QUANTITY}}</td>
                            <td>{{UNIT_MEASURE}}</td>
                            <td>{{UNIT_PRICE}}</td>
                            <td>{{TOTAL_PRICE}}</td>
                        </tr>
                    </tbody>
                </table>
                <p><strong>Total comandă:</strong> {{ORDER_TOTAL}}</p>
                <p>În cazul în care aveți nevoie de clarificări, ne puteți contacta la {{COMPANY_PHONE}} sau {{COMPANY_EMAIL}}.</p>
                <p>Vă rugăm să confirmați primirea comenzii răspunzând la acest email sau contactându-ne la {{SUPPLIER_PHONE}} / {{SUPPLIER_EMAIL}}.</p>
                <p>Cu stimă,<br>
                {{COMPANY_NAME}}<br>
                Generat automat la {{CURRENT_DATE}} {{CURRENT_TIME}}</p>
            HTML;

            $insertTemplateSql = <<<'SQL'
                INSERT INTO email_templates (
                    template_type,
                    template_name,
                    subject_template,
                    body_template,
                    is_active,
                    is_default,
                    variables_used,
                    created_by
                ) VALUES (
                    :template_type,
                    :template_name,
                    :subject_template,
                    :body_template,
                    :is_active,
                    :is_default,
                    CAST(:variables_used AS JSON),
                    :created_by
                )
                ON DUPLICATE KEY UPDATE
                    subject_template = VALUES(subject_template),
                    body_template = VALUES(body_template),
                    is_active = VALUES(is_active),
                    is_default = VALUES(is_default),
                    variables_used = VALUES(variables_used),
                    updated_at = CURRENT_TIMESTAMP;
            SQL;

            $stmt = $pdo->prepare($insertTemplateSql);
            $stmt->execute([
                ':template_type' => 'auto_order',
                ':template_name' => 'Șablon automat comandă furnizor',
                ':subject_template' => $defaultSubject,
                ':body_template' => $defaultBody,
                ':is_active' => 1,
                ':is_default' => 1,
                ':variables_used' => $variablesJson,
                ':created_by' => null,
            ]);

            $companySettings = [
                [
                    'setting_key' => 'company_name',
                    'setting_value' => 'Compania Exemplu SRL',
                    'setting_type' => 'string',
                    'description' => 'Denumirea oficială a companiei folosită în comunicări',
                ],
                [
                    'setting_key' => 'company_address',
                    'setting_value' => 'Str. Exemplu nr. 10, București, România',
                    'setting_type' => 'string',
                    'description' => 'Adresă completă a companiei',
                ],
                [
                    'setting_key' => 'company_phone',
                    'setting_value' => '+40 21 123 45 67',
                    'setting_type' => 'string',
                    'description' => 'Numărul principal de telefon al companiei',
                ],
                [
                    'setting_key' => 'company_email',
                    'setting_value' => 'contact@compania-exemplu.ro',
                    'setting_type' => 'string',
                    'description' => 'Adresa de email generală a companiei',
                ],
            ];

            $insertSettingSql = <<<'SQL'
                INSERT INTO settings (setting_key, setting_value, setting_type, description)
                VALUES (:setting_key, :setting_value, :setting_type, :description)
                ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    setting_type = VALUES(setting_type),
                    description = VALUES(description),
                    updated_at = CURRENT_TIMESTAMP;
            SQL;

            $settingStmt = $pdo->prepare($insertSettingSql);
            foreach ($companySettings as $setting) {
                $settingStmt->execute([
                    ':setting_key' => $setting['setting_key'],
                    ':setting_value' => $setting['setting_value'],
                    ':setting_type' => $setting['setting_type'],
                    ':description' => $setting['description'],
                ]);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Revenire migrare.
     */
    public function down(PDO $pdo): void
    {
        try {
            $pdo->beginTransaction();

            $pdo->exec('DROP TABLE IF EXISTS email_templates;');

            $settingKeys = [
                'company_name',
                'company_address',
                'company_phone',
                'company_email',
            ];

            $placeholders = implode(',', array_fill(0, count($settingKeys), '?'));
            $deleteSql = "DELETE FROM settings WHERE setting_key IN ($placeholders);";
            $stmt = $pdo->prepare($deleteSql);
            $stmt->execute($settingKeys);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}

return new CreateEmailTemplatesTable();
