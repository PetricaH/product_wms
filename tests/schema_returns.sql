CREATE TABLE products (
    product_id INTEGER PRIMARY KEY,
    sku TEXT,
    name TEXT
);
CREATE TABLE orders (
    id INTEGER PRIMARY KEY,
    order_number TEXT,
    status TEXT
);
CREATE TABLE order_items (
    id INTEGER PRIMARY KEY,
    order_id INTEGER,
    product_id INTEGER,
    quantity INTEGER
);
CREATE TABLE returns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER,
    processed_by INTEGER,
    verified_by INTEGER,
    status TEXT,
    verified_at TEXT
);
CREATE TABLE return_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    return_id INTEGER,
    order_item_id INTEGER,
    product_id INTEGER,
    quantity_returned INTEGER,
    item_condition TEXT,
    is_extra INTEGER
);
CREATE TABLE return_discrepancies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    return_id INTEGER,
    order_item_id INTEGER,
    product_id INTEGER,
    discrepancy_type TEXT,
    expected_quantity INTEGER,
    actual_quantity INTEGER,
    item_condition TEXT,
    notes TEXT,
    updated_at TEXT
);
