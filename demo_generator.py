"""
Python ➡️ PHP Webshop & Brochure Generator - Architectural Demo
Reference Shop: http://edger.nhely.hu/
Author: Your Name

Demonstrates the core orchestration logic:
1. Data Ingestion & Image Autocrop
2. SQLite Database Population with SEO Slugs
3. Dynamic PHP Storefront Compilation
4. Embedded PHP Dev Server Launcher
"""

import os
import re
import json
import sqlite3
import subprocess
import argparse
from pathlib import Path
from typing import Dict, Any, List

try:
    from PIL import Image, ImageChops
except ImportError:
    Image = None
    ImageChops = None


def seo_slug(text: str) -> str:
    """Generates clean, accent-free SEO URL slugs."""
    if not text:
        return "egyeb"
    accents = {"á": "a", "é": "e", "í": "i", "ó": "o", "ö": "o", "ő": "o", "ú": "u", "ü": "u", "ű": "u"}
    cleaned = str(text).lower().strip()
    for accented, plain in accents.items():
        cleaned = cleaned.replace(accented, plain)
    cleaned = re.sub(r'[^a-z0-9\s-]', '', cleaned)
    cleaned = re.sub(r'[\s-]+', '-', cleaned).strip('-')
    return cleaned if cleaned else "egyeb"


def autocrop_image_demo(img, margin_percent: float = 0.04):
    """
    Intelligent border trimmer: Strips excess white/transparent vendor borders
    and applies a uniform protective margin around the SKU asset.
    """
    if not ImageChops:
        return img
    try:
        bbox = img.getbbox() if img.mode == "RGBA" else None
        if not bbox:
            img_rgb = img.convert("RGB")
            bg = Image.new("RGB", img_rgb.size, (255, 255, 255))
            diff = ImageChops.difference(img_rgb, bg)
            diff = diff.point(lambda p: 255 if p > 15 else 0)
            bbox = diff.getbbox()
        if bbox:
            cropped = img.crop(bbox)
            w, h = cropped.size
            pad_w = max(2, int(w * margin_percent))
            pad_h = max(2, int(h * margin_percent))
            mode = "RGBA" if cropped.mode == "RGBA" else "RGB"
            bg_col = (255, 255, 255, 0) if mode == "RGBA" else (255, 255, 255)
            padded = Image.new(mode, (w + pad_w * 2, h + pad_h * 2), bg_col)
            padded.paste(cropped, (pad_w, pad_h), cropped if mode == "RGBA" else None)
            return padded
    except Exception:
        pass
    return img


class WebshopCompilerDemo:
    """Compiles product records into a high-speed standalone PHP/SQLite runtime."""

    def __init__(self, output_dir: str = "./Webshop_Demo"):
        self.output_dir = output_dir
        os.makedirs(os.path.join(self.output_dir, "static", "images"), exist_ok=True)
        self.db_path = os.path.join(self.output_dir, "webshop.db")

    def init_sqlite_database(self, products: List[Dict[str, Any]]) -> None:
        """Initializes schema and pre-indexes catalog records with SEO slugs."""
        conn = sqlite3.connect(self.db_path)
        cur = conn.cursor()

        cur.execute("""
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
            leiras TEXT
        )
        """)
        cur.execute("CREATE INDEX IF NOT EXISTS idx_products_cat_slug ON products(category_slug)")
        cur.execute("CREATE INDEX IF NOT EXISTS idx_products_prod_slug ON products(product_slug)")

        cur.execute("BEGIN TRANSACTION")
        for p in products:
            sku_clean = p["sku"].lower().strip()
            cat_slug = seo_slug(p.get("group", "Egyéb"))
            prod_slug = seo_slug(f"{sku_clean}-{p['name']}")
            cur.execute("""
            INSERT OR REPLACE INTO products 
            (sku, name, type, price, purchase_price, old_price, custom_price, group_name, category_slug, product_slug, image, status, leiras)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, (
                sku_clean,
                p.get("name", ""),
                p.get("type", ""),
                int(p.get("price", 0)),
                p.get("purchase_price"),
                p.get("old_price"),
                p.get("custom_price"),
                p.get("group", "Egyéb"),
                cat_slug,
                prod_slug,
                p.get("image", ""),
                p.get("status", "standard"),
                p.get("leiras", "")
            ))
        conn.commit()
        conn.close()
        print(f"✓ SQLite database successfully compiled at: {self.db_path}")

    def run_embedded_server(self, host: str = "127.0.0.1", port: int = 8000) -> None:
        """Launches synchronized local PHP development server (Zero-Nginx setup)."""
        print(f"🚀 Starting embedded PHP server at: http://{host}:{port}")
        try:
            subprocess.run(["php", "-S", f"{host}:{port}", "-t", self.output_dir])
        except FileNotFoundError:
            print("[Hiba] PHP CLI nem található a rendszer PATH-ban. Telepíts PHP 8.x-et a futtatáshoz!")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Webshop & Brochure Generator Demo")
    parser.add_argument("--serve", action="store_true", help="Start local test server")
    args = parser.parse_args()

    sample_catalog = [
        {"sku": "ED10", "name": "Dupla Ecset Kanadai Ferde", "type": "25mm", "price": 1490, "old_price": 1890, "group": "Ecsetek", "image": "ed10_ecsetek.webp"},
        {"sku": "KFDM20", "name": "Mester Partvis Sörte", "type": "40cm", "price": 3290, "old_price": None, "group": "Partvisok", "image": "kfdm20_partvisok.webp"}
    ]

    compiler = WebshopCompilerDemo()
    compiler.init_sqlite_database(sample_catalog)

    if args.serve:
        compiler.run_embedded_server()