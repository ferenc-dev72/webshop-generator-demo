-- SQLite Production Storefront Schema
-- Reference Shop: http://edger.nhely.hu/

CREATE TABLE IF NOT EXISTS products (
    sku TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    type TEXT,
    price INTEGER NOT NULL,
    purchase_price INTEGER,
    old_price INTEGER,
    custom_price INTEGER,
    group_name TEXT NOT NULL,
    category_slug TEXT,
    product_slug TEXT,
    image TEXT,
    galeria TEXT,
    kep_statusz TEXT,
    status TEXT DEFAULT 'standard',
    shipping_type TEXT DEFAULT 'global',
    shipping_fee INTEGER DEFAULT 0,
    leiras TEXT,
    te TEXT
);

CREATE INDEX IF NOT EXISTS idx_products_group ON products(group_name);
CREATE INDEX IF NOT EXISTS idx_products_cat_slug ON products(category_slug);
CREATE INDEX IF NOT EXISTS idx_products_prod_slug ON products(product_slug);
CREATE INDEX IF NOT EXISTS idx_products_status ON products(status);

-- B2B Users & Enterprise Credit Management
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    email TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    discount_percent INTEGER DEFAULT 0,
    is_admin INTEGER DEFAULT 0,
    company_name TEXT,
    vat_number TEXT,
    billing_address TEXT,
    shipping_address TEXT,
    credit_limit INTEGER DEFAULT 0,
    is_approved INTEGER DEFAULT 1,
    allow_transfer INTEGER DEFAULT 0,
    payment_terms INTEGER DEFAULT 8,
    phone TEXT
);

-- Per-User Individual SKU Contract Prices
CREATE TABLE IF NOT EXISTS user_custom_prices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    product_sku TEXT NOT NULL,
    custom_price INTEGER NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE(user_id, product_sku)
);

-- Orders with Invoiced JSON Items & Payment Methods
CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    items_json TEXT NOT NULL,
    total_price INTEGER NOT NULL,
    status TEXT DEFAULT 'Függőben',
    payment_method TEXT DEFAULT 'Utánvét',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);