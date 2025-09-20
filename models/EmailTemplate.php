<?php

declare(strict_types=1);

/**
 * Model responsabil de gestionarea șabloanelor de email pentru sistemul WMS.
 */
class EmailTemplate
{
    private PDO $conn;
    private string $table = 'email_templates';

    /**
     * Catalogul variabilelor permise împreună cu descrierea și categoria lor.
     * @var array<string, array{categorie: string, descriere: string}>
     */
    private array $variabilePermise = [
        'COMPANY_NAME' => ['categorie' => 'Date companie', 'descriere' => 'Numele complet al companiei care trimite comanda'],
        'COMPANY_ADDRESS' => ['categorie' => 'Date companie', 'descriere' => 'Adresă completă a companiei emitente'],
        'COMPANY_PHONE' => ['categorie' => 'Date companie', 'descriere' => 'Numărul principal de telefon al companiei'],
        'COMPANY_EMAIL' => ['categorie' => 'Date companie', 'descriere' => 'Adresa principală de email a companiei'],
        'ORDER_NUMBER' => ['categorie' => 'Detalii comandă', 'descriere' => 'Numărul comenzii generate automat'],
        'ORDER_DATE' => ['categorie' => 'Detalii comandă', 'descriere' => 'Data generării comenzii'],
        'ORDER_TIME' => ['categorie' => 'Detalii comandă', 'descriere' => 'Ora generării comenzii'],
        'DELIVERY_DATE' => ['categorie' => 'Detalii comandă', 'descriere' => 'Data solicitată pentru livrare'],
        'ORDER_TOTAL' => ['categorie' => 'Detalii comandă', 'descriere' => 'Valoarea totală a comenzii'],
        'PRODUCT_NAME' => ['categorie' => 'Produse', 'descriere' => 'Denumirea produsului din linia curentă'],
        'PRODUCT_CODE' => ['categorie' => 'Produse', 'descriere' => 'Codul produsului din linia curentă'],
        'ORDER_QUANTITY' => ['categorie' => 'Produse', 'descriere' => 'Cantitatea comandată pentru produs'],
        'UNIT_PRICE' => ['categorie' => 'Produse', 'descriere' => 'Prețul unitar negociat'],
        'TOTAL_PRICE' => ['categorie' => 'Produse', 'descriere' => 'Valoarea totală pe linie'],
        'UNIT_MEASURE' => ['categorie' => 'Produse', 'descriere' => 'Unitatea de măsură a produsului'],
        'SUPPLIER_NAME' => ['categorie' => 'Furnizor', 'descriere' => 'Numele persoanei sau companiei furnizor'],
        'SUPPLIER_EMAIL' => ['categorie' => 'Furnizor', 'descriere' => 'Adresa de email a furnizorului'],
        'SUPPLIER_PHONE' => ['categorie' => 'Furnizor', 'descriere' => 'Numărul de telefon al furnizorului'],
        'CURRENT_DATE' => ['categorie' => 'Date sistem', 'descriere' => 'Data curentă la trimiterea emailului'],
        'CURRENT_TIME' => ['categorie' => 'Date sistem', 'descriere' => 'Ora curentă la trimiterea emailului'],
    ];

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Returnează șablonul activ pentru tipul indicat.
     */
    public function getActiveTemplate(string $type = 'auto_order'): ?array
    {
        try {
            $sql = "SELECT * FROM {$this->table} WHERE template_type = :type AND is_active = 1 ORDER BY is_default DESC, updated_at DESC LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':type', $type, PDO::PARAM_STR);
            $stmt->execute();
            $template = $stmt->fetch(PDO::FETCH_ASSOC);

            return $template ? $this->normalizeTemplateRow($template) : null;
        } catch (PDOException $exception) {
            throw new RuntimeException('Nu s-a putut obține șablonul activ: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Creează sau actualizează un șablon de email și întoarce varianta salvată.
     */
    public function saveTemplate(array $data): array
    {
        $templateType = trim($data['template_type'] ?? 'auto_order');
        if ($templateType === '') {
            throw new InvalidArgumentException('Tipul șablonului este obligatoriu.');
        }

        $templateName = trim($data['template_name'] ?? '');
        if ($templateName === '') {
            throw new InvalidArgumentException('Numele șablonului este obligatoriu.');
        }

        $subject = $data['subject_template'] ?? null;
        $body = $data['body_template'] ?? null;
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;
        $isDefault = isset($data['is_default']) ? (int)(bool)$data['is_default'] : 0;
        $createdBy = $data['created_by'] ?? null;

        $variabileFolosite = $this->validateTemplate($subject, $body);
        $variablesJson = $this->encodeVariables($variabileFolosite);

        $id = isset($data['id']) ? (int)$data['id'] : null;

        try {
            if ($id) {
                $sql = "UPDATE {$this->table}
                        SET template_type = :template_type,
                            template_name = :template_name,
                            subject_template = :subject_template,
                            body_template = :body_template,
                            is_active = :is_active,
                            is_default = :is_default,
                            variables_used = :variables_used,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
                $stmt = $this->conn->prepare($sql);
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            } else {
                $sql = "INSERT INTO {$this->table} (template_type, template_name, subject_template, body_template, is_active, is_default, variables_used, created_by)
                        VALUES (:template_type, :template_name, :subject_template, :body_template, :is_active, :is_default, :variables_used, :created_by)";
                $stmt = $this->conn->prepare($sql);
                $stmt->bindValue(':created_by', $createdBy, $createdBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            }

            $stmt->bindValue(':template_type', $templateType, PDO::PARAM_STR);
            $stmt->bindValue(':template_name', $templateName, PDO::PARAM_STR);
            $stmt->bindValue(':subject_template', $subject, $subject === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':body_template', $body, $body === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':is_active', $isActive, PDO::PARAM_INT);
            $stmt->bindValue(':is_default', $isDefault, PDO::PARAM_INT);
            if ($variablesJson === null) {
                $stmt->bindValue(':variables_used', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':variables_used', $variablesJson, PDO::PARAM_STR);
            }

            $stmt->execute();

            if (!$id) {
                $id = (int)$this->conn->lastInsertId();
            }
        } catch (PDOException $exception) {
            throw new RuntimeException('Șablonul nu a putut fi salvat: ' . $exception->getMessage(), 0, $exception);
        }

        if ($isDefault === 1) {
            $this->setAsDefault($id);
        }

        $template = $this->getTemplateById($id);
        if (!$template) {
            throw new RuntimeException('Șablonul ar fi trebuit să existe după salvare, dar nu a fost găsit.');
        }

        return $template;
    }

    /**
     * Preia un șablon după identificator.
     */
    public function getTemplateById(int $id): ?array
    {
        try {
            $sql = "SELECT * FROM {$this->table} WHERE id = :id LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $template = $stmt->fetch(PDO::FETCH_ASSOC);

            return $template ? $this->normalizeTemplateRow($template) : null;
        } catch (PDOException $exception) {
            throw new RuntimeException('Nu s-a putut obține șablonul solicitat: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Marchează șablonul indicat ca implicit pentru tipul său și dezactivează altele implicite.
     */
    public function setAsDefault(int $id): void
    {
        $template = $this->getTemplateById($id);
        if (!$template) {
            throw new InvalidArgumentException('Șablonul specificat nu există.');
        }

        try {
            $this->conn->beginTransaction();

            $resetSql = "UPDATE {$this->table} SET is_default = 0 WHERE template_type = :type";
            $stmt = $this->conn->prepare($resetSql);
            $stmt->bindValue(':type', $template['template_type'], PDO::PARAM_STR);
            $stmt->execute();

            $activateSql = "UPDATE {$this->table} SET is_default = 1, is_active = 1 WHERE id = :id";
            $stmt = $this->conn->prepare($activateSql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            $this->conn->commit();
        } catch (PDOException $exception) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw new RuntimeException('Nu s-a putut seta șablonul ca implicit: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Listează variabilele ce pot fi folosite în șabloane, grupate pe categorii.
     *
     * @return array<string, array<string, string>>
     */
    public function getAvailableVariables(): array
    {
        $rezultat = [];
        foreach ($this->variabilePermise as $cheie => $informatii) {
            $categorie = $informatii['categorie'];
            if (!isset($rezultat[$categorie])) {
                $rezultat[$categorie] = [];
            }
            $rezultat[$categorie]['{{' . $cheie . '}}'] = $informatii['descriere'];
        }

        ksort($rezultat);
        foreach ($rezultat as &$valori) {
            ksort($valori);
        }

        return $rezultat;
    }

    /**
     * Validează subiectul și corpul șablonului și returnează variabilele utilizate.
     *
     * @return string[] Lista variabilelor folosite (fără acolade).
     */
    public function validateTemplate(?string $subject, ?string $body): array
    {
        $textCombinat = trim(($subject ?? '') . ' ' . ($body ?? ''));
        if ($textCombinat === '') {
            return [];
        }

        if (!preg_match_all('/\{\{\s*([A-Z0-9_]+)\s*\}\}/u', $textCombinat, $matches)) {
            return [];
        }

        $variabile = array_unique(array_map('strtoupper', $matches[1]));
        foreach ($variabile as $variabila) {
            if (!isset($this->variabilePermise[$variabila])) {
                throw new InvalidArgumentException(sprintf('Variabila {{%s}} nu este permisă în șabloane.', $variabila));
            }
        }

        sort($variabile);
        return $variabile;
    }

    /**
     * Normalizează rezultatul citit din baza de date pentru a fi ușor de utilizat în aplicație.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeTemplateRow(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['is_active'] = (int)$row['is_active'] === 1;
        $row['is_default'] = (int)$row['is_default'] === 1;
        if (array_key_exists('created_by', $row)) {
            $row['created_by'] = $row['created_by'] !== null ? (int)$row['created_by'] : null;
        }

        if (array_key_exists('variables_used', $row) && $row['variables_used'] !== null) {
            $decoded = json_decode((string)$row['variables_used'], true);
            $row['variables_used'] = is_array($decoded) ? array_values($decoded) : [];
        } else {
            $row['variables_used'] = [];
        }

        return $row;
    }

    /**
     * Serializează variabilele pentru stocare în baza de date.
     */
    private function encodeVariables(array $variabile): ?string
    {
        if (empty($variabile)) {
            return null;
        }

        $json = json_encode(array_values($variabile), JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Nu s-a putut serializa lista variabilelor utilizate.');
        }

        return $json;
    }
}
