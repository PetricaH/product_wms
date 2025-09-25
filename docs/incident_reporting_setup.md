# Sistem Raportare Incidente - Instrucțiuni Integrare

## 1. Migrare Bază de Date
1. Rulează utilitarul de migrare existent:
   ```bash
   php migrate.php
   ```
2. Verifică faptul că tabelele `incidents` și `incident_photos` au fost create.

## 2. Permisiuni și Stocare Fișiere
- Asigură-te că directorul `storage/incidents` există și are permisiuni de scriere pentru utilizatorul PHP:
  ```bash
  mkdir -p storage/incidents
  chmod 775 storage/incidents
  ```
- Fișierele sunt organizate pe fiecare incident (`storage/incidents/INCYYYYMMDD###`).

## 3. Integrare Interfață Worker
- Componenta pentru raportare este încărcată automat pe toate paginile worker prin `includes/warehouse_footer.php`.
- Pentru paginile personalizate warehouse, confirmă că folosesc footer-ul standard.
- Butonul flotant „Raportează Incident” se afișează doar pentru rolurile `warehouse`, `worker` și `admin`.

## 4. Interfață Administrator
- Accesibilă doar pentru utilizatorii cu rol `admin` la `incidents-admin.php`.
- Include filtre pentru tip, severitate și status, plus actualizare workflow.
- Fotografii se pot deschide în tab nou pentru audit.

## 5. API-uri Noi
- `POST /api/incidents/create.php`
  - Acceptă formular multipart cu câmpurile din formularul worker.
  - Necesită antet `X-CSRF-TOKEN` și sesiune validă.
- `POST /api/incidents/update-status.php`
  - Acceptă payload JSON cu `incident_id`, `status`, `admin_notes`, `resolution_notes`, `follow_up_required`.
  - Doar rol `admin`.

## 6. Reguli de Securitate
- CSRF obligatoriu pentru ambele endpoint-uri.
- Upload-urile sunt limitate la 5 MB/fisier și JPEG/PNG/WebP.
- Toate operațiile folosesc PDO cu prepared statements.

## 7. Activitate și Audit
- Fiecare raportare și actualizare înregistrează evenimente în sistemul existent `logActivity`.
- Numerele de incident sunt generate ca `INCYYYYMMDD###` și garantat unice.

## 8. Personalizare Ulterioară
- Pentru includerea altor câmpuri se poate extinde modelul `Incident`.
- Stilurile se află în `styles/incident-report.css` și `styles/incidents-admin.css`.
- Logica front-end este în `scripts/incident-report-worker.js` și `scripts/incidents-admin.js`.
