# WMS (Warehouse Management System) Project

## Overview

This project aims to build a functional Warehouse Management System (WMS) to streamline common warehouse operations. It currently consists of two main components:

1.  **Admin Dashboard:** A web interface for managers/administrators to get a high-level overview of warehouse status and key metrics.
2.  **Mobile Worker Interface:** A mobile-friendly web interface designed for warehouse workers to perform tasks like order picking, guided by the system.

## Technology Stack

* **Backend:** PHP (using PDO for database interaction)
* **Database:** MySQL / MariaDB
* **Frontend Styling:** SCSS (compiled using Gulp), Custom CSS (aligned with Admin theme for Mobile UI)
* **Frontend Logic:** Vanilla JavaScript (ES6+), Fetch API
* **Frontend Build:** Node.js, NPM, Gulp (for SCSS compilation, JS concatenation/minification - optional based on `gulpfile.js`)
* **Barcode Scanning:** `html5-qrcode` JavaScript library (for camera-based scanning)
* **Web Server:** PHP Built-in server (for development) or Apache/Nginx

## Project Structure

/├── api/                    # Backend API endpoints│   └── picking/│       ├── get_next_task.php # API to get the next item/location to pick│       └── confirm_pick.php  # API to confirm a pick action├── config/                 # Configuration files (e.g., database)│   └── config.php├── includes/               # Reusable PHP view components (header, footer, navbar)├── models/                 # PHP classes for database table interactions (Product, Order, etc.)├── node_modules/           # Node.js dependencies (installed via npm)├── scripts/                # Compiled JavaScript output (from Gulp/build process)│   └── mobile_picker.js│   └── ... (other compiled JS)├── src/                    # Source files for frontend assets│   ├── js/│   │   ├── pages/│   │   │   └── mobile_picker.js # Source JS for mobile picker│   │   └── universal/         # Shared JS (if any)│   └── scss/│       ├── pages/│       │   └── mobile_picker.scss # Source SCSS for mobile picker│       └── universal/         # Shared SCSS (variables, base styles)├── styles/                 # Compiled CSS output (from Gulp/build process)│   └── mobile_picker.css│   └── ... (other compiled CSS)├── views/                  # PHP view files for specific pages/sections (if any beyond includes)├── assets/                 # Static assets (images, logos, fonts)├── .env                    # Environment variables (optional, for DB credentials etc.)├── .gitignore              # Specifies intentionally untracked files that Git should ignore├── bootstrap.php           # Basic setup, constants, helper functions├── gulpfile.js             # Gulp tasks for building frontend assets├── index.php               # Main entry point for the Admin Dashboard├── mobile_picker.html      # Entry point for the Mobile Picking Interface├── package.json            # Node.js project metadata and dependencies├── package-lock.json       # Records exact versions of Node.js dependencies└── README.md               # This file
## Current Features

### 1. Admin Dashboard (`index.php`)

* **Purpose:** Provides managers with a quick overview of warehouse operations.
* **Functionality:** Displays summary cards showing:
    * Total distinct products (SKUs)
    * Total registered users
    * Warehouse location occupation percentage
    * Total individual items currently in stock
    * Number of active orders (not yet shipped/completed)
    * Number of orders shipped today
    * Total revenue (placeholder)
* **Data Flow:**
    1.  `index.php` includes `bootstrap.php` and `config.php`.
    2.  It establishes a database connection using the factory in `config.php`.
    3.  It instantiates necessary Model classes (e.g., `Product`, `Order`, `Location`, `Inventory`, `User`).
    4.  Each model fetches the required summary data from the database (e.g., `Product->countAll()`, `Location->calculateOccupationPercentage()`).
    5.  The fetched data is displayed within the HTML structure of `index.php`, using included `header.php`, `navbar.php`, and `footer.php`.
* **Styling:** Uses SCSS defined in `src/scss/` (likely `index.scss` and shared files), compiled to `/styles/` by Gulp.

### 2. Mobile Picking Interface (`mobile_picker.html`)

* **Purpose:** Guides a warehouse worker step-by-step through picking items for a specific customer order, ensuring accuracy through scanning.
* **Functionality:**
    * **Order Selection:**
    * Allows scanning an order barcode using the device camera (`html5-qrcode`).
    * Provides a fallback option to manually type the Order ID.
* **Location Verification:**
    * After an order is loaded, the system determines the *first* item to pick based on FIFO (First-In, First-Out) logic using the `inventory.received_at` timestamp.
    * It prompts the worker to navigate to the specific `location_code` for that item.
    * The worker must scan the location's barcode (or enter it manually) to verify they are in the correct place.
* **Product Verification:**
    * After location verification, the system prompts the worker to verify the product.
    * The interface displays the target Product SKU and Name.
    * The worker must scan the product's barcode (or enter the SKU manually).
    * The scanned/entered SKU is compared against the expected SKU for the task.
* **Task Display & Quantity Input:**
    * Once both location and product are verified, the interface displays full details of the item to be picked: Product Name, SKU, Batch Number (if any), and the exact Quantity to Pick for this step.
    * Provides a number input field, pre-filled with the suggested quantity to pick.
* **Confirmation:** A "Confirm Pick" button allows the worker to confirm they have picked the specified quantity.
* **Backend Update:** Confirmation triggers an API call that updates the database:
    * Decrements the quantity in the specific `inventory` row (identified by `inventory_id`).
    * Increments the `picked_quantity` in the corresponding `order_items` row.
* **Flow Control:** After successful confirmation, the interface automatically fetches the next picking task for the *same order*. If all items are picked, an "All Done" message appears.
* **Data Flow:**
    1.  The user opens `mobile_picker.html`.
    2.  **Order Load:**
        * *Scan:* User clicks "Scan Order", `startScanner()` is called (mode: 'order'). `html5-qrcode` uses the camera. On success, `onScanSuccess()` gets the `orderId` and calls `fetchNextTask(orderId)`.
        * *Manual:* User clicks "Enter ID Manually", types ID into `#order-id-input`, clicks "Load". The click listener calls `fetchNextTask(orderId)`.
    3.  **Get Next Task:** `fetchNextTask()` calls `GET /api/picking/get_next_task.php?order_id={id}`.
        * The PHP script queries `order_items` (finding items where `quantity_ordered > picked_quantity`) and `inventory` (finding the oldest stock via `received_at` for the required `product_id`), determines the specific `inventory_id`, `location_code`, `batch_number`, and `quantity_to_pick`.
        * It returns this data as JSON (or a 'complete' status, or an error).
    4.  **Location Prompt:** If the API returns a task (`status: 'success'`), `fetchNextTask()` calls `showLocationScanPrompt()`. The UI displays "Go to [location\_code]. Scan Location Barcode".
    5.  **Location Scan:** User clicks "Scan Location", `startScanner()` is called (mode: 'location'). On success, `onScanSuccess()` compares the scanned `decodedText` with `currentTask.location_code`.
    6.  **Display Task:** If location matches, `onScanSuccess()` calls `displayTaskDetails()`. The UI now shows the product info, quantity needed, and the confirmation section.
    7.  **Confirm Pick:** User enters the quantity picked (adjusting if necessary, though typically they pick the suggested amount) into `#quantity-picked-input` and clicks "Confirm Pick".
    8.  **Confirm API Call:** The `confirmPick()` JS function sends a `POST` request to `/api/picking/confirm_pick.php` with a JSON body: `{ "order_item_id": ..., "inventory_id": ..., "quantity_picked": ... }`.
    9.  **Database Update:** The PHP script starts a transaction, verifies stock, decrements `inventory.quantity` for the specific `inventory_id`, increments `order_items.picked_quantity`, checks if the order/item is complete, updates statuses if needed, and commits the transaction. It returns a JSON success or error message.
    10. **Loop:** If confirmation was successful, the `confirmPick()` JS function calls `fetchNextTask()` again for the same `order_id`, restarting the cycle from step 3 until the API returns `status: 'complete'`.
* **Styling:** Uses custom SCSS (`src/scss/pages/mobile_picker.scss`) designed to align with the admin theme variables, compiled via Gulp.

## Setup & Installation

1.  **Prerequisites:**
    * PHP (version 8.x recommended)
    * MySQL or MariaDB Database Server
    * Node.js and npm (for frontend build process)
    * Web Server (Apache, Nginx, or PHP built-in server for development)
2.  **Clone Repository:** `git clone <repository_url>`
3.  **Database:**
    * Create a database (e.g., `wartung_wms`).
    * Import the database schema (from provided `.sql` files or manually create tables based on the `CREATE TABLE` statements).
    * Configure database connection details:
        * Option A: Create a `.env` file in the project root based on `.env.example` (if provided) with your DB credentials (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`).
        * Option B: Directly edit the defaults in `config/config.php`.
4.  **PHP Dependencies:** If using Composer (not currently shown in `package.json`), run `composer install`.
5.  **Node.js Dependencies:** Navigate to the project root in your terminal and run: `npm install`
6.  **Build Frontend Assets:**
    * For development (with file watching): `npm run dev` (if `dev` script is configured in `package.json` to run Gulp watch)
    * For production (minified/versioned files): `npm run build` (if `build` script is configured)
7.  **Run Development Server:**
    * Navigate to the project root in your terminal.
    * Start the PHP built-in server (adjust port if needed): `php -S localhost:3000 -t .` (The `-t .` serves from the current directory).
8.  **Access:**
    * Admin Dashboard: `http://localhost:3000/` or `http://localhost:3000/index.php`
    * Mobile Picker: `http://localhost:3000/mobile_picker.html`

## Next Steps & Future Enhancements

### Mobile Picking Interface

* **Batch/Lot Scan:** If `currentTask.batch_number` or `currentTask.lot_number` is present, add an optional scanning step to verify it.
* **Partial Picks:** Modify logic if workers are allowed to pick less than the suggested quantity from a location (requires more complex state management).
* **Error Handling:** Implement more specific error handling (e.g., "Item not found at scanned location", "Invalid barcode format").
* **UI/UX Improvements:**
    * Larger fonts/buttons for better usability on rugged devices.
    * Clearer audio/visual feedback for successful/failed scans.
    * Progress indicator for the overall order.
    * Ability to skip an item (with reason code) or report issues.
* **User Authentication:** Require workers to log in. Associate picks with specific users.

### Other Mobile Modules

* **Receiving:** Interface to scan Purchase Order (PO) or ASN, scan received items, enter quantities, capture batch/lot/expiry, assign to receiving location.
* **Putaway:** Interface to scan item/pallet ID in receiving area, get system-suggested location, scan location to confirm placement.
* **Stock Check/Cycle Count:** Interface to scan a location, display expected items/quantities, allow worker to enter counted quantity, submit count.
* **Location Inquiry:** Scan a location to see its current contents.

### Admin Dashboard

* **Detailed Views:** Add pages to list/view/edit orders, products, locations, inventory details.
* **Reporting:** Generate reports (inventory levels, picking performance, stock aging).
* **User Management:** Add/edit/remove users and roles.
* **Discrepancy Management:** Interface to review and resolve discrepancies found during cycle counting.
* **Filtering/Searching:** Add robust filtering and searching to all data tables.

### Backend/API

* **Refinement:** Optimize database queries.
* **Error Handling:** Add more specific server-side validation and error responses in API endpoints.
* **Routing:** Consider implementing a simple PHP router or micro-framework (like Slim or Laminas Mezzio) for cleaner API endpoint management instead of individual PHP files per endpoint.
* **Authentication/Authorization:** Implement API authentication (e.g., tokens) for mobile users.

### General

* **Testing:** Add unit and integration tests for backend logic and API endpoints.
* **Deployment:** Create build scripts and configurations for deploying to a production server environment.