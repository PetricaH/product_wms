<?php
/**
 * Serviciu dedicat gestionării complete a autocomenzilor.
 * Toate mesajele, logurile și documentația sunt în limba română.
 */

declare(strict_types=1);

require_once __DIR__ . '/PurchaseOrder.php';
require_once __DIR__ . '/Setting.php';

class AutoOrderManager
{
    private const CHEIE_SETARI = 'auto_order_configuratie_globala';
    private const INTERVAL_DUPLICATE_ORE = 24;
    private const NUMAR_MAXIM_INCERCARI_EMAIL = 3;

    private PDO $conn;
    private PurchaseOrder $purchaseOrder;
    private Setting $settingModel;
    private array $emailConfig = [];
    private string $productsTable = 'products';
    private string $purchaseOrdersTable = 'purchase_orders';
    private string $purchaseOrderItemsTable = 'purchase_order_items';
    private string $purchasableProductsTable = 'purchasable_products';

    /**
     * Inițializează managerul de autocomenzi cu conexiunea PDO curentă.
     */
    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
        $this->purchaseOrder = new PurchaseOrder($conn);
        $this->settingModel = new Setting($conn);
        $this->emailConfig = $this->incarcaConfiguratieEmail();
    }

    /**
     * Parcurge toate produsele eligibile și încearcă să declanșeze autocomenzi.
     */
    public function checkAllProducts(): array
    {
        $rezumat = [
            'total_verificate' => 0,
            'eligibile' => 0,
            'comenzi_generate' => 0,
            'mesaje' => [],
            'erori' => []
        ];

        $setari = $this->getAutoOrderSettings();
        if (empty($setari['activ'])) {
            $mesaj = 'Autocomanda globală este dezactivată în configurație. Verificarea a fost oprită.';
            $this->log($mesaj, 'info');
            $rezumat['mesaje'][] = $mesaj;
            return $rezumat;
        }

        try {
            $sql = "SELECT product_id FROM {$this->productsTable} WHERE auto_order_enabled = 1";
            $stmt = $this->conn->query($sql);
            $produse = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        } catch (PDOException $e) {
            $eroare = 'Nu s-a putut obține lista produselor pentru autocomandă: ' . $e->getMessage();
            $this->log($eroare, 'critical');
            $rezumat['erori'][] = $eroare;
            return $rezumat;
        }

        foreach ($produse as $productId) {
            $rezumat['total_verificate']++;
            $evaluare = $this->validateProductForAutoOrder((int)$productId);

            if (!$evaluare['poate_comanda']) {
                $rezumat['mesaje'][] = sprintf('Produsul #%d nu îndeplinește criteriile: %s', $productId, $evaluare['motiv_principal']);
                continue;
            }

            $rezumat['eligibile']++;

            $rezultatComanda = $this->createAndSendAutoOrder($evaluare);
            if ($rezultatComanda['succes']) {
                $rezumat['comenzi_generate']++;
                $rezumat['mesaje'][] = $rezultatComanda['mesaj'];
            } else {
                $rezumat['erori'][] = $rezultatComanda['mesaj'];
            }
        }

        return $rezumat;
    }

    /**
     * Validează dacă produsul poate declanșa o autocomandă și construiește contextul necesar.
     */
    public function validateProductForAutoOrder(int $productId): array
    {
        $detalii = [
            'poate_comanda' => false,
            'motiv_principal' => 'Produsul nu a fost evaluat.',
            'validari' => [],
            'produs' => null,
            'furnizor' => null,
            'articol' => null,
            'comanda' => null,
            'payload' => null
        ];

        try {
            $query = "SELECT
                        p.product_id,
                        p.sku,
                        p.name,
                        COALESCE(inv.current_stock, 0) AS current_stock,
                        p.min_stock_level,
                        p.min_order_quantity,
                        p.auto_order_enabled,
                        p.seller_id,
                        p.last_auto_order_date,
                        p.price,
                        p.price_eur,
                        s.supplier_name,
                        s.email AS seller_email,
                        s.contact_person,
                        sp.supplier_name AS preferred_supplier_name,
                        sp.email AS preferred_seller_email,
                        pp.id AS purchasable_product_id,
                        pp.supplier_product_name,
                        pp.supplier_product_code,
                        pp.last_purchase_price,
                        pp.preferred_seller_id,
                        pp.currency
                    FROM {$this->productsTable} p
                    LEFT JOIN (
                        SELECT product_id, SUM(quantity) AS current_stock
                        FROM inventory
                        GROUP BY product_id
                    ) inv ON inv.product_id = p.product_id
                    LEFT JOIN {$this->purchasableProductsTable} pp
                        ON pp.internal_product_id = p.product_id
                    LEFT JOIN sellers s ON s.id = p.seller_id
                    LEFT JOIN sellers sp ON sp.id = pp.preferred_seller_id
                    WHERE p.product_id = :id
                    ORDER BY
                        CASE WHEN pp.preferred_seller_id = p.seller_id THEN 0 ELSE 1 END,
                        CASE WHEN pp.last_purchase_price IS NULL OR pp.last_purchase_price <= 0 THEN 1 ELSE 0 END,
                        pp.id ASC";

            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                $detalii['motiv_principal'] = 'Produsul nu există în baza de date.';
                $detalii['validari'][] = [
                    'conditie' => 'Identificare produs',
                    'rezultat' => 'eroare',
                    'tip' => 'critic',
                    'detalii' => 'Produsul solicitat nu a putut fi găsit.'
                ];
                return $detalii;
            }

            $primaLinie = $rows[0];
            $cantitateCurenta = (float)($primaLinie['current_stock'] ?? 0);
            $pragMinim = (float)($primaLinie['min_stock_level'] ?? 0);
            $cantitateMinimaComanda = (int)($primaLinie['min_order_quantity'] ?? 0);
            $ultimaAutocomanda = $primaLinie['last_auto_order_date'] ?? null;

            $pretFallback = 0.0;
            $monedaFallback = null;
            $pretProdusRon = isset($primaLinie['price']) ? (float)$primaLinie['price'] : 0.0;
            $pretProdusEur = isset($primaLinie['price_eur']) ? (float)$primaLinie['price_eur'] : 0.0;
            if ($pretProdusRon > 0) {
                $pretFallback = $pretProdusRon;
                $monedaFallback = 'RON';
            } elseif ($pretProdusEur > 0) {
                $pretFallback = $pretProdusEur;
                $monedaFallback = 'EUR';
            }

            $detalii['produs'] = [
                'id' => (int)$primaLinie['product_id'],
                'sku' => $primaLinie['sku'] ?? null,
                'nume' => $primaLinie['name'] ?? null,
                'cantitate_curenta' => $cantitateCurenta,
                'prag_minim' => $pragMinim,
                'cantitate_minima_comanda' => $cantitateMinimaComanda,
                'ultima_autocomanda' => $ultimaAutocomanda
            ];

            $detalii['validari'][] = [
                'conditie' => 'Produs disponibil',
                'rezultat' => 'ok',
                'tip' => 'critic',
                'detalii' => 'Produsul a fost identificat cu succes.'
            ];

            $autoActiv = (int)($primaLinie['auto_order_enabled'] ?? 0) === 1;
            $detalii['validari'][] = [
                'conditie' => 'Autocomandă activă',
                'rezultat' => $autoActiv ? 'ok' : 'eroare',
                'tip' => 'critic',
                'detalii' => $autoActiv ? 'Autocomanda este activată pentru acest produs.' : 'Autocomanda este dezactivată pentru produs.'
            ];

            $sellerId = (int)($primaLinie['seller_id'] ?? 0);
            $numeFurnizor = $primaLinie['supplier_name'] ?? null;
            $emailFurnizor = trim($primaLinie['seller_email'] ?? '');
            $sursaFurnizor = 'produs';

            if ($sellerId <= 0 || $emailFurnizor === '' || !filter_var($emailFurnizor, FILTER_VALIDATE_EMAIL)) {
                foreach ($rows as $row) {
                    $preferredId = (int)($row['preferred_seller_id'] ?? 0);
                    $preferredEmail = trim($row['preferred_seller_email'] ?? '');

                    if ($preferredId > 0 && $preferredEmail !== '' && filter_var($preferredEmail, FILTER_VALIDATE_EMAIL)) {
                        $sellerId = $preferredId;
                        $numeFurnizor = $row['preferred_supplier_name'] ?? $numeFurnizor;
                        $emailFurnizor = $preferredEmail;
                        $sursaFurnizor = 'preferred';
                        break;
                    }
                }
            }

            $detalii['furnizor'] = [
                'id' => $sellerId,
                'nume' => $numeFurnizor,
                'email' => $emailFurnizor,
                'contact' => $primaLinie['contact_person'] ?? null,
                'sursa' => $sursaFurnizor
            ];

            $areFurnizor = $sellerId > 0;
            $detalii['validari'][] = [
                'conditie' => 'Furnizor configurat',
                'rezultat' => $areFurnizor ? 'ok' : 'eroare',
                'tip' => 'critic',
                'detalii' => $areFurnizor
                    ? ($sursaFurnizor === 'preferred'
                        ? 'Produsul folosește furnizorul preferat definit în articolul achiziționabil.'
                        : 'Produsul are un furnizor principal configurat.')
                    : 'Produsul nu are definit un furnizor.'
            ];

            $emailValid = $emailFurnizor !== '' && filter_var($emailFurnizor, FILTER_VALIDATE_EMAIL);
            $detalii['validari'][] = [
                'conditie' => 'Email furnizor valid',
                'rezultat' => $emailValid ? 'ok' : 'eroare',
                'tip' => 'critic',
                'detalii' => $emailValid ? 'Adresa de email a furnizorului este validă.' : 'Adresa de email a furnizorului este absentă sau invalidă.'
            ];

            $subPrag = $pragMinim > 0 ? $cantitateCurenta <= $pragMinim : $cantitateCurenta <= 0;
            $detalii['validari'][] = [
                'conditie' => 'Nivel de stoc critic',
                'rezultat' => $subPrag ? 'ok' : 'eroare',
                'tip' => 'critic',
                'detalii' => $subPrag ? 'Stocul a atins pragul minim și necesită reaprovizionare.' : 'Stocul este peste pragul minim configurat.'
            ];

            $articolSelectat = null;
            $scorSelectat = -1;
            foreach ($rows as $row) {
                if (empty($row['purchasable_product_id'])) {
                    continue;
                }

                $pretAchizitie = isset($row['last_purchase_price']) ? (float)$row['last_purchase_price'] : 0.0;
                $monedaAchizitie = $row['currency'] ?? null;
                $pretCalculat = $pretAchizitie;
                $monedaCalculata = $monedaAchizitie;

                if ($pretCalculat <= 0 && $pretFallback > 0) {
                    $pretCalculat = $pretFallback;
                    $monedaCalculata = $monedaFallback ?? $monedaCalculata;
                }

                $monedaCalculata = $monedaCalculata ?: ($monedaFallback ?: 'RON');

                $scor = 1;
                if ((int)($row['preferred_seller_id'] ?? 0) === $sellerId) {
                    $scor += 4;
                }
                if ($pretCalculat > 0) {
                    $scor += 3;
                }

                if ($articolSelectat === null || $scor > $scorSelectat || ($scor === $scorSelectat && $pretCalculat > (float)($articolSelectat['pret'] ?? 0))) {
                    $articolSelectat = [
                        'id' => (int)$row['purchasable_product_id'],
                        'nume' => $row['supplier_product_name'] ?? $primaLinie['name'] ?? '',
                        'cod' => $row['supplier_product_code'] ?? null,
                        'pret' => $pretCalculat,
                        'currency' => $monedaCalculata,
                        'price_source' => $pretAchizitie > 0 ? 'purchasable_product' : ($pretCalculat > 0 ? 'product_price' : 'unknown')
                    ];
                    $scorSelectat = $scor;
                }
            }

            if ($articolSelectat) {
                $detalii['validari'][] = [
                    'conditie' => 'Articol achiziționabil asociat',
                    'rezultat' => 'ok',
                    'tip' => 'critic',
                    'detalii' => 'A fost identificat un articol valid pentru comandă.'
                ];
            } else {
                $detalii['validari'][] = [
                    'conditie' => 'Articol achiziționabil asociat',
                    'rezultat' => 'eroare',
                    'tip' => 'critic',
                    'detalii' => 'Produsul nu are asociat un articol achiziționabil pentru comenzi.'
                ];
            }

            $detalii['articol'] = $articolSelectat;

            $verificareDuplicat = $this->preventDuplicateOrders($productId);
            $detalii['validari'][] = [
                'conditie' => 'Interval minim între autocomenzi',
                'rezultat' => $verificareDuplicat['permisa'] ? 'ok' : 'eroare',
                'tip' => 'critic',
                'detalii' => $verificareDuplicat['mesaj']
            ];

            $cantitateMinimaComanda = max(1, $cantitateMinimaComanda);
            $deficit = max(0, $pragMinim - $cantitateCurenta);
            $cantitateComandata = max($cantitateMinimaComanda, (int)ceil($deficit));
            if ($cantitateComandata <= 0) {
                $cantitateComandata = $cantitateMinimaComanda;
            }

            $pretUnitar = $articolSelectat['pret'] ?? 0.0;
            $monedaComanda = $articolSelectat['currency'] ?? ($monedaFallback ?: 'RON');
            $valoareTotala = $pretUnitar * $cantitateComandata;

            $detalii['comanda'] = [
                'cantitate' => $cantitateComandata,
                'deficit_estimat' => $deficit,
                'pret_unitar' => $pretUnitar,
                'valoare_totala' => $valoareTotala,
                'currency' => $monedaComanda,
                'price_source' => $articolSelectat['price_source'] ?? null
            ];

            $detalii['validari'][] = [
                'conditie' => 'Cantitate propusă pentru comandă',
                'rezultat' => 'ok',
                'tip' => 'informativ',
                'detalii' => sprintf('Sistemul propune comandarea a %d bucăți.', $cantitateComandata)
            ];

            $poateComanda = true;
            foreach ($detalii['validari'] as $validare) {
                if ($validare['tip'] === 'critic' && $validare['rezultat'] !== 'ok') {
                    $poateComanda = false;
                    $detalii['motiv_principal'] = $validare['detalii'];
                    break;
                }
            }

            if ($poateComanda && $articolSelectat) {
                $detalii['payload'] = [
                    'seller_id' => $sellerId,
                    'status' => 'sent',
                    'notes' => 'Autocomandă generată automat pe baza pragului minim de stoc.',
                    'custom_message' => null,
                    'email_subject' => null,
                    'email_recipient' => $emailFurnizor,
                    'total_amount' => $valoareTotala,
                    'currency' => $monedaComanda,
                    'items' => [[
                        'purchasable_product_id' => $articolSelectat['id'],
                        'quantity' => $cantitateComandata,
                        'unit_price' => $pretUnitar,
                        'notes' => 'Autocomandă generată automat de sistemul WMS.'
                    ]]
                ];
            }

            $detalii['poate_comanda'] = $poateComanda;
            if ($poateComanda) {
                $detalii['motiv_principal'] = 'Toate condițiile critice au fost îndeplinite.';
            }
        } catch (Exception $e) {
            $detalii['poate_comanda'] = false;
            $detalii['motiv_principal'] = 'A apărut o eroare neașteptată: ' . $e->getMessage();
            $detalii['validari'][] = [
                'conditie' => 'Procesare autocomandă',
                'rezultat' => 'eroare',
                'tip' => 'critic',
                'detalii' => $detalii['motiv_principal']
            ];
            $this->log('Eroare la evaluarea produsului #' . $productId . ': ' . $e->getMessage(), 'error');
        }

        return $detalii;
    }

    /**
     * Creează comanda de achiziție și trimite emailul aferent autocomenzii.
     * Acceptă fie un ID de produs, fie rezultatul complet al validării.
     */
    public function createAndSendAutoOrder($productData): array
    {
        $context = is_array($productData) && isset($productData['produs'])
            ? $productData
            : $this->validateProductForAutoOrder((int)$productData);

        if (!$context['poate_comanda'] || empty($context['payload'])) {
            return [
                'succes' => false,
                'mesaj' => sprintf('Produsul #%d nu poate genera autocomanda: %s', $context['produs']['id'] ?? 0, $context['motiv_principal'])
            ];
        }

        $productId = (int)($context['produs']['id'] ?? 0);

        try {
            $orderId = $this->purchaseOrder->createPurchaseOrder($context['payload']);
            if (!$orderId) {
                $mesaj = sprintf('Autocomanda pentru produsul #%d a eșuat: comanda de achiziție nu a putut fi creată.', $productId);
                $this->log($mesaj, 'error');
                $this->notificaAdministratori($mesaj);
                return ['succes' => false, 'mesaj' => $mesaj];
            }

            $orderNumber = $this->obtineNumarComanda($orderId);
            $email = $this->construiesteEmailAutocomanda($orderNumber, $context);

            $this->actualizeazaMesajEmail($orderId, $email);

            $setari = $this->getAutoOrderSettings();
            $incercariMaxime = (int)($setari['maxim_retrimitere_email'] ?? self::NUMAR_MAXIM_INCERCARI_EMAIL);

            $rezultatEmail = $this->trimiteEmailCuRetry(
                $this->emailConfig,
                $context['furnizor']['email'],
                $context['furnizor']['nume'],
                $email['subiect'],
                $email['corp'],
                max(1, $incercariMaxime)
            );

            if ($rezultatEmail['success']) {
                $this->purchaseOrder->markAsSent($orderId, $context['furnizor']['email']);
                $this->actualizeazaUltimaAutocomanda($productId);
                $mesajSucces = sprintf('Autocomanda #%s pentru produsul %s a fost trimisă către %s.', $orderNumber, $context['produs']['nume'], $context['furnizor']['email']);
                $this->log($mesajSucces, 'info');
                if (function_exists('logActivity')) {
                    logActivity(
                        $_SESSION['user_id'] ?? 0,
                        'create',
                        'purchase_order',
                        $orderId,
                        'Autocomandă generată automat: ' . $orderNumber
                    );
                }
                return [
                    'succes' => true,
                    'mesaj' => $mesajSucces,
                    'order_id' => $orderId,
                    'order_number' => $orderNumber
                ];
            }

            $mesajEroare = sprintf(
                'Autocomanda %s pentru produsul %s nu a putut trimite emailul către %s: %s',
                $orderNumber,
                $context['produs']['nume'],
                $context['furnizor']['email'],
                $rezultatEmail['message'] ?? 'motiv necunoscut'
            );
            $this->log($mesajEroare, 'error');
            $this->notificaAdministratori($mesajEroare);

            return [
                'succes' => false,
                'mesaj' => $mesajEroare,
                'order_id' => $orderId,
                'order_number' => $orderNumber
            ];
        } catch (Exception $e) {
            $mesaj = sprintf('Autocomanda pentru produsul #%d a întâmpinat o eroare critică: %s', $productId, $e->getMessage());
            $this->log($mesaj, 'critical');
            $this->notificaAdministratori($mesaj);
            return [
                'succes' => false,
                'mesaj' => $mesaj
            ];
        }
    }

    /**
     * Verifică dacă a trecut intervalul minim între două autocomenzi pentru același produs.
     */
    public function preventDuplicateOrders(int $productId): array
    {
        $setari = $this->getAutoOrderSettings();
        $intervalOre = (int)($setari['interval_minim_ore'] ?? self::INTERVAL_DUPLICATE_ORE);

        try {
            $stmt = $this->conn->prepare("SELECT last_auto_order_date FROM {$this->productsTable} WHERE product_id = :id");
            $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            $ultimaData = $stmt->fetchColumn();
        } catch (PDOException $e) {
            $mesaj = 'Nu s-a putut verifica data ultimei autocomenzi: ' . $e->getMessage();
            $this->log($mesaj, 'error');
            return [
                'permisa' => false,
                'mesaj' => 'Eroare la verificarea istoricului de autocomandă.'
            ];
        }

        $limit = new DateTimeImmutable('-' . max(1, $intervalOre) . ' hours');
        if ($ultimaData) {
            try {
                $ultima = new DateTimeImmutable($ultimaData);
                if ($ultima > $limit) {
                    return [
                        'permisa' => false,
                        'mesaj' => 'Există deja o autocomandă generată în ultimele ' . $intervalOre . ' ore.'
                    ];
                }
            } catch (Exception $e) {
                $this->log('Format invalid pentru last_auto_order_date la produsul #' . $productId, 'warning');
            }
        }

        try {
            $sql = "SELECT po.created_at
                    FROM {$this->purchaseOrdersTable} po
                    INNER JOIN {$this->purchaseOrderItemsTable} poi ON poi.purchase_order_id = po.id
                    INNER JOIN {$this->purchasableProductsTable} pp ON poi.purchasable_product_id = pp.id
                    WHERE pp.internal_product_id = :product_id
                        AND po.created_at >= :limit
                        AND (po.notes LIKE 'Autocomandă%' OR poi.notes LIKE 'Autocomandă%')
                    ORDER BY po.created_at DESC
                    LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit->format('Y-m-d H:i:s'));
            $stmt->execute();
            $ultimaComanda = $stmt->fetchColumn();
            if ($ultimaComanda) {
                return [
                    'permisa' => false,
                    'mesaj' => 'În ultimele ' . $intervalOre . ' ore a fost deja emisă o autocomandă pentru acest produs.'
                ];
            }
        } catch (PDOException $e) {
            $this->log('Nu s-a putut interoga istoricul autocomenzilor: ' . $e->getMessage(), 'warning');
        }

        return [
            'permisa' => true,
            'mesaj' => 'Nu există autocomenzi recente pentru acest produs.'
        ];
    }

    /**
     * Returnează istoricul autocomenzilor, opțional filtrat după produs.
     */
    public function getAutoOrderHistory(?int $productId = null): array
    {
        $sql = "SELECT
                    po.id,
                    po.order_number,
                    po.status,
                    po.created_at,
                    po.email_sent_at,
                    po.email_recipient,
                    po.total_amount,
                    p.product_id,
                    p.sku,
                    p.name AS product_name,
                    poi.quantity,
                    poi.unit_price,
                    poi.total_price,
                    s.supplier_name
                FROM {$this->purchaseOrdersTable} po
                INNER JOIN {$this->purchaseOrderItemsTable} poi ON poi.purchase_order_id = po.id
                INNER JOIN {$this->purchasableProductsTable} pp ON poi.purchasable_product_id = pp.id
                INNER JOIN {$this->productsTable} p ON pp.internal_product_id = p.product_id
                LEFT JOIN sellers s ON po.seller_id = s.id
                WHERE po.notes LIKE 'Autocomandă%' OR poi.notes LIKE 'Autocomandă%'
                ORDER BY po.created_at DESC";

        $params = [];
        if ($productId !== null) {
            $sql = "SELECT * FROM (" . $sql . ") istorice WHERE product_id = :pid";
            $params[':pid'] = $productId;
        }

        try {
            $stmt = $this->conn->prepare($sql);
            foreach ($params as $cheie => $valoare) {
                $stmt->bindValue($cheie, $valoare, PDO::PARAM_INT);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $this->log('Eroare la obținerea istoricului de autocomenzi: ' . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * Returnează lista produselor care îndeplinesc condițiile de autocomandă.
     */
    public function getProductsEligibleForAutoOrder(): array
    {
        try {
            $sql = "SELECT product_id FROM {$this->productsTable} WHERE auto_order_enabled = 1";
            $stmt = $this->conn->query($sql);
            $ids = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        } catch (PDOException $e) {
            $this->log('Nu s-a putut obține lista produselor eligibile: ' . $e->getMessage(), 'error');
            return [];
        }

        $rezultate = [];
        foreach ($ids as $id) {
            $evaluare = $this->validateProductForAutoOrder((int)$id);
            if ($evaluare['poate_comanda']) {
                $rezultate[] = $evaluare['produs'];
            }
        }

        return $rezultate;
    }

    /**
     * Returnează configurația globală a sistemului de autocomandă.
     */
    public function getAutoOrderSettings(): array
    {
        $setari = $this->settingModel->get(self::CHEIE_SETARI);
        if (is_string($setari)) {
            $setari = json_decode($setari, true) ?: [];
        } elseif (!is_array($setari)) {
            $setari = [];
        }

        $implicite = [
            'activ' => true,
            'interval_minim_ore' => self::INTERVAL_DUPLICATE_ORE,
            'maxim_retrimitere_email' => self::NUMAR_MAXIM_INCERCARI_EMAIL,
            'notificare_admin_email' => $this->emailConfig['reply_to']
                ?? $this->emailConfig['from_email']
                ?? null
        ];

        return array_merge($implicite, $setari);
    }

    /**
     * Actualizează configurația globală a autocomenzii.
     */
    public function updateAutoOrderSettings(array $settings): bool
    {
        $curente = $this->getAutoOrderSettings();
        $actualizate = array_merge($curente, $settings);

        $actualizate['activ'] = !empty($actualizate['activ']);
        $actualizate['interval_minim_ore'] = max(1, (int)$actualizate['interval_minim_ore']);
        $actualizate['maxim_retrimitere_email'] = max(1, (int)$actualizate['maxim_retrimitere_email']);

        try {
            $json = json_encode($actualizate, JSON_UNESCAPED_UNICODE);
            $rezultat = $this->settingModel->set(self::CHEIE_SETARI, $json, 'string');
            if ($rezultat && function_exists('logActivity')) {
                logActivity(
                    $_SESSION['user_id'] ?? 0,
                    'update',
                    'auto_order_settings',
                    0,
                    'Configurarea autocomenzii a fost actualizată.'
                );
            }
            return (bool)$rezultat;
        } catch (Exception $e) {
            $this->log('Nu s-au putut salva setările de autocomandă: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Activează autocomanda pentru un anumit produs.
     */
    public function enableAutoOrderForProduct(int $productId): bool
    {
        return $this->actualizeazaStareAutocomandaProdus($productId, true);
    }

    /**
     * Dezactivează autocomanda pentru un anumit produs.
     */
    public function disableAutoOrderForProduct(int $productId): bool
    {
        return $this->actualizeazaStareAutocomandaProdus($productId, false);
    }

    /**
     * Generează statistici sumar pentru tabloul de bord.
     */
    public function getAutoOrderStatistics(): array
    {
        $statistici = [
            'total_autocomenzi' => 0,
            'autocomenzi_ultimele_30_zile' => 0,
            'autocomenzi_azi' => 0,
            'autocomenzi_nefinalizate' => 0
        ];

        try {
            $sqlTotal = "SELECT COUNT(*) FROM {$this->purchaseOrdersTable} WHERE notes LIKE 'Autocomandă%'";
            $statistici['total_autocomenzi'] = (int)$this->conn->query($sqlTotal)->fetchColumn();

            $sqlLuna = "SELECT COUNT(*) FROM {$this->purchaseOrdersTable}
                        WHERE notes LIKE 'Autocomandă%'
                        AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            $statistici['autocomenzi_ultimele_30_zile'] = (int)$this->conn->query($sqlLuna)->fetchColumn();

            $sqlAzi = "SELECT COUNT(*) FROM {$this->purchaseOrdersTable}
                       WHERE notes LIKE 'Autocomandă%'
                       AND DATE(created_at) = CURDATE()";
            $statistici['autocomenzi_azi'] = (int)$this->conn->query($sqlAzi)->fetchColumn();

            $sqlNeFinalizate = "SELECT COUNT(*) FROM {$this->purchaseOrdersTable}
                                WHERE notes LIKE 'Autocomandă%'
                                  AND (email_sent_at IS NULL OR status <> 'sent')";
            $statistici['autocomenzi_nefinalizate'] = (int)$this->conn->query($sqlNeFinalizate)->fetchColumn();
        } catch (PDOException $e) {
            $this->log('Nu s-au putut calcula statisticile pentru autocomandă: ' . $e->getMessage(), 'warning');
        }

        return $statistici;
    }

    /**
     * Obține lista autocomenzilor care nu au fost trimise cu succes.
     */
    public function getFailedAutoOrders(): array
    {
        try {
            $sql = "SELECT po.id, po.order_number, po.created_at, po.status, po.email_recipient,
                           p.product_id, p.name AS product_name, s.supplier_name
                    FROM {$this->purchaseOrdersTable} po
                    INNER JOIN {$this->purchaseOrderItemsTable} poi ON poi.purchase_order_id = po.id
                    INNER JOIN {$this->purchasableProductsTable} pp ON poi.purchasable_product_id = pp.id
                    INNER JOIN {$this->productsTable} p ON pp.internal_product_id = p.product_id
                    LEFT JOIN sellers s ON po.seller_id = s.id
                    WHERE (po.notes LIKE 'Autocomandă%' OR poi.notes LIKE 'Autocomandă%')
                      AND (po.email_sent_at IS NULL OR po.status <> 'sent')
                    GROUP BY po.id
                    ORDER BY po.created_at DESC";
            $stmt = $this->conn->query($sql);
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (PDOException $e) {
            $this->log('Nu s-a putut obține lista autocomenzilor eșuate: ' . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * Reîncearcă trimiterea emailului pentru o autocomandă eșuată.
     */
    public function retryFailedAutoOrder(int $orderId): array
    {
        try {
            $sql = "SELECT po.*, poi.quantity, poi.unit_price, pp.internal_product_id, p.name AS product_name,
                           p.sku, p.min_stock_level,
                           COALESCE(inv.current_stock, 0) AS stoc_curent,
                           p.last_auto_order_date AS last_auto_order_date_produs,
                           s.email AS seller_email, s.supplier_name
                    FROM {$this->purchaseOrdersTable} po
                    INNER JOIN {$this->purchaseOrderItemsTable} poi ON poi.purchase_order_id = po.id
                    INNER JOIN {$this->purchasableProductsTable} pp ON poi.purchasable_product_id = pp.id
                    INNER JOIN {$this->productsTable} p ON pp.internal_product_id = p.product_id
                    LEFT JOIN (
                        SELECT product_id, SUM(quantity) AS current_stock
                        FROM inventory
                        GROUP BY product_id
                    ) inv ON inv.product_id = p.product_id
                    LEFT JOIN sellers s ON po.seller_id = s.id
                    WHERE po.id = :id
                    LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
            $stmt->execute();
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                return [
                    'succes' => false,
                    'mesaj' => 'Comanda de achiziție nu a fost găsită în baza de date.'
                ];
            }

            if ($order['notes'] && strpos($order['notes'], 'Autocomandă') === false) {
                return [
                    'succes' => false,
                    'mesaj' => 'Comanda selectată nu este marcată ca autocomandă.'
                ];
            }

            $productId = (int)$order['internal_product_id'];
            $context = [
                'poate_comanda' => true,
                'produs' => [
                    'id' => $productId,
                    'sku' => $order['sku'],
                    'nume' => $order['product_name'],
                    'cantitate_curenta' => (float)$order['stoc_curent'],
                    'prag_minim' => (float)$order['min_stock_level'],
                    'cantitate_minima_comanda' => (int)$order['quantity'],
                    'ultima_autocomanda' => $order['last_auto_order_date_produs'] ?? null
                ],
                'furnizor' => [
                    'id' => (int)$order['seller_id'],
                    'nume' => $order['supplier_name'],
                    'email' => $order['seller_email']
                ],
                'comanda' => [
                    'cantitate' => (int)$order['quantity'],
                    'deficit_estimat' => 0,
                    'pret_unitar' => (float)$order['unit_price'],
                    'valoare_totala' => (float)$order['total_amount']
                ]
            ];

            $email = $this->construiesteEmailAutocomanda($order['order_number'], $context);
            $setari = $this->getAutoOrderSettings();
            $incercariMaxime = (int)($setari['maxim_retrimitere_email'] ?? self::NUMAR_MAXIM_INCERCARI_EMAIL);

            $rezultatEmail = $this->trimiteEmailCuRetry(
                $this->emailConfig,
                $order['seller_email'],
                $order['supplier_name'],
                $email['subiect'],
                $email['corp'],
                max(1, $incercariMaxime)
            );

            if ($rezultatEmail['success']) {
                $this->purchaseOrder->markAsSent($orderId, $order['seller_email']);
                $this->actualizeazaMesajEmail($orderId, $email);
                $this->actualizeazaUltimaAutocomanda($productId);
                $mesaj = sprintf('Emailul autocomenzii %s a fost retrimis cu succes către %s.', $order['order_number'], $order['seller_email']);
                $this->log($mesaj, 'info');
                return [
                    'succes' => true,
                    'mesaj' => $mesaj
                ];
            }

            $mesajEroare = sprintf('Retrimiterea autocomenzii %s a eșuat: %s', $order['order_number'], $rezultatEmail['message'] ?? 'motiv necunoscut');
            $this->log($mesajEroare, 'error');
            $this->notificaAdministratori($mesajEroare);
            return [
                'succes' => false,
                'mesaj' => $mesajEroare
            ];
        } catch (Exception $e) {
            $mesaj = 'Nu s-a putut reîncerca autocomanda: ' . $e->getMessage();
            $this->log($mesaj, 'critical');
            $this->notificaAdministratori($mesaj);
            return [
                'succes' => false,
                'mesaj' => $mesaj
            ];
        }
    }

    /**
     * Încarcă configurația email din fișierul general.
     */
    private function incarcaConfiguratieEmail(): array
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        $config = $GLOBALS['config'] ?? require $basePath . '/config/config.php';
        return $config['email'] ?? [];
    }

    /**
     * Obține numărul comenzii de achiziție.
     */
    private function obtineNumarComanda(int $orderId): string
    {
        $stmt = $this->conn->prepare("SELECT order_number FROM {$this->purchaseOrdersTable} WHERE id = :id");
        $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
        $stmt->execute();
        $orderNumber = $stmt->fetchColumn();
        return $orderNumber ?: ('PO-' . date('Y') . '-NEDEFINIT');
    }

    /**
     * Actualizează câmpurile de email ale comenzii cu subiectul și conținutul generat.
     */
    private function actualizeazaMesajEmail(int $orderId, array $email): void
    {
        $stmt = $this->conn->prepare("UPDATE {$this->purchaseOrdersTable} SET email_subject = :subject, custom_message = :body WHERE id = :id");
        $stmt->execute([
            ':subject' => $email['subiect'],
            ':body' => $email['corp'],
            ':id' => $orderId
        ]);
    }

    /**
     * Marchează data ultimei autocomenzi la nivel de produs.
     */
    private function actualizeazaUltimaAutocomanda(int $productId): void
    {
        $stmt = $this->conn->prepare("UPDATE {$this->productsTable} SET last_auto_order_date = NOW() WHERE product_id = :id");
        $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Construiește subiectul și conținutul emailului de autocomandă.
     */
    private function construiesteEmailAutocomanda(string $orderNumber, array $context): array
    {
        $numeProdus = $context['produs']['nume'] ?? '';
        $sku = $context['produs']['sku'] ?? '';
        $cantitate = $context['comanda']['cantitate'] ?? 0;
        $pret = $context['comanda']['pret_unitar'] ?? 0.0;
        $total = $context['comanda']['valoare_totala'] ?? 0.0;
        $currency = $context['comanda']['currency'] ?? 'RON';
        $dataGenerarii = date('d.m.Y H:i');

        $pretFormatat = number_format((float)$pret, 2, ',', '.');
        $totalFormatat = number_format((float)$total, 2, ',', '.');

        $subiect = sprintf('Autocomandă urgentă - %s - %s', $numeProdus, $orderNumber);

        $corp = "Bună ziua,\n";
        $corp .= "Sistemul WMS a detectat că produsul \"{$numeProdus}\" (SKU: {$sku}) a atins nivelul minim de stoc.\n";
        $corp .= "Vă rugăm să procesați următoarea autocomandă generată automat:\n\n";
        $corp .= "Număr comandă: {$orderNumber}\n";
        $corp .= "Data generării: {$dataGenerarii}\n";
        $corp .= "Cantitate solicitată: {$cantitate} bucăți\n";
        $corp .= "Preț unitar estimat: {$pretFormatat} {$currency}\n";
        $corp .= "Valoare totală estimată: {$totalFormatat} {$currency}\n\n";
        $corp .= "Această autocomandă a fost generată automat conform pragurilor de stoc configurate.\n";
        $corp .= "Vă mulțumim pentru promptitudine.\n\n";
        $corp .= "Cu stimă,\n";
        $corp .= "Echipa Wartung - Sistem WMS";

        return [
            'subiect' => $subiect,
            'corp' => $corp,
            'data_generare' => $dataGenerarii
        ];
    }

    /**
     * Trimite emailul cu mecanism de retry și log detaliat.
     */
    private function trimiteEmailCuRetry(array $smtpConfig, string $destinatar, ?string $numeDestinatar, string $subiect, string $corp, int $maxIncercari): array
    {
        $maxIncercari = max(1, $maxIncercari);
        $ultimaEroare = null;

        for ($incercare = 1; $incercare <= $maxIncercari; $incercare++) {
            $rezultat = $this->trimiteEmailSimplu($smtpConfig, $destinatar, $numeDestinatar, $subiect, $corp);
            if ($rezultat['success']) {
                $mesaj = sprintf('Email trimis cu succes către %s la încercarea #%d.', $destinatar, $incercare);
                $this->log($mesaj, 'info');
                $rezultat['incercari'] = $incercare;
                return $rezultat;
            }

            $ultimaEroare = $rezultat['message'] ?? 'Eroare necunoscută la trimiterea emailului.';
            $this->log(sprintf('Încercarea #%d de trimitere către %s a eșuat: %s', $incercare, $destinatar, $ultimaEroare), 'warning');
            usleep(250000);
        }

        return [
            'success' => false,
            'message' => $ultimaEroare,
            'incercari' => $maxIncercari
        ];
    }

    /**
     * Trimite efectiv emailul folosind infrastructura existentă.
     */
    private function trimiteEmailSimplu(array $smtpConfig, string $destinatar, ?string $numeDestinatar, string $subiect, string $corp): array
    {
        if (empty($smtpConfig['host']) || empty($smtpConfig['port']) || empty($smtpConfig['username']) || empty($smtpConfig['password'])) {
            return [
                'success' => false,
                'message' => 'Configurația SMTP este incompletă.'
            ];
        }

        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        require_once $basePath . '/lib/PHPMailer/PHPMailer.php';
        require_once $basePath . '/lib/PHPMailer/SMTP.php';
        require_once $basePath . '/lib/PHPMailer/Exception.php';

        try {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = $smtpConfig['host'];
            $mailer->Port = (int)$smtpConfig['port'];
            $mailer->SMTPAuth = true;
            $mailer->Username = $smtpConfig['username'];
            $mailer->Password = $smtpConfig['password'];
            if (!empty($smtpConfig['encryption'])) {
                $mailer->SMTPSecure = $smtpConfig['encryption'];
            }
            $mailer->CharSet = 'UTF-8';

            $fromEmail = $smtpConfig['from_email'] ?? $smtpConfig['username'];
            $fromName = $smtpConfig['from_name'] ?? 'Sistem WMS';
            $mailer->setFrom($fromEmail, $fromName);
            if (!empty($smtpConfig['reply_to'])) {
                $mailer->addReplyTo($smtpConfig['reply_to']);
            }

            $mailer->addAddress($destinatar, $numeDestinatar ?? '');
            $mailer->Subject = $subiect;
            $mailer->Body = $corp;
            $mailer->AltBody = $corp;
            $mailer->isHTML(false);

            $mailer->send();

            return [
                'success' => true,
                'message' => 'Email trimis cu succes.'
            ];
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            return [
                'success' => false,
                'message' => 'Trimiterea emailului a eșuat: ' . $e->getMessage()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Eroare neașteptată la trimiterea emailului: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Actualizează rapid starea autocomenzii pentru produs.
     */
    private function actualizeazaStareAutocomandaProdus(int $productId, bool $activ): bool
    {
        try {
            $stmt = $this->conn->prepare("UPDATE {$this->productsTable} SET auto_order_enabled = :activ WHERE product_id = :id");
            $stmt->bindValue(':activ', $activ ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
            $rezultat = $stmt->execute();

            if ($rezultat && function_exists('logActivity')) {
                $actiune = $activ ? 'Autocomanda a fost activată pentru produs.' : 'Autocomanda a fost dezactivată pentru produs.';
                logActivity(
                    $_SESSION['user_id'] ?? 0,
                    'update',
                    'product_auto_order',
                    $productId,
                    $actiune
                );
            }

            return (bool)$rezultat;
        } catch (PDOException $e) {
            $this->log('Nu s-a putut actualiza starea autocomenzii pentru produsul #' . $productId . ': ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Metodă centralizată de logare.
     */
    private function log(string $mesaj, string $nivel = 'info'): void
    {
        $prefix = sprintf('[AUTOCOMANDA][%s] ', strtoupper($nivel));
        error_log($prefix . $mesaj);
    }

    /**
     * Trimite o notificare administratorilor atunci când apare o eroare critică.
     */
    private function notificaAdministratori(string $mesaj): void
    {
        $setari = $this->getAutoOrderSettings();
        $emailAdmin = $setari['notificare_admin_email'] ?? null;
        if (!$emailAdmin) {
            $this->log('Nu există un email de administrator configurat pentru notificări critice.', 'warning');
            return;
        }

        $subiect = 'Avertizare sistem autocomandă';
        $corp = "Bună ziua,\n\n";
        $corp .= "S-a înregistrat o eroare critică în procesul de autocomandă:\n";
        $corp .= $mesaj . "\n\n";
        $corp .= "Vă rugăm să verificați aplicația.\n\n";
        $corp .= "Sistem WMS";

        $rezultat = $this->trimiteEmailSimplu($this->emailConfig, $emailAdmin, 'Administrator WMS', $subiect, $corp);
        if (!$rezultat['success']) {
            $this->log('Notificarea către administratori nu a putut fi trimisă: ' . $rezultat['message'], 'warning');
        }
    }
}
