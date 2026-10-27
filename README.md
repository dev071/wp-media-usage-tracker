# WP Media Usage Tracker

### The Safest WordPress Media Usage Scanner, Orphan File Finder & Storage Cleaner

[![WordPress Compatibility](https://img.shields.io/badge/WordPress-5.8%20to%206.7+-21759b.svg?style=flat-square&logo=wordpress)](https://wordpress.org/)
[![PHP Compatibility](https://img.shields.io/badge/PHP-7.4%20|%208.0%20|%208.1%20|%208.2%20|%208.3+-777bb4.svg?style=flat-square&logo=php)](https://php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-green.svg?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Safety: 100% Read Only](https://img.shields.io/badge/Safety-100%25%20Read--Only-success.svg?style=flat-square)](#-100-read-only--safe-guarantee)
[![Release](https://img.shields.io/badge/Release-v1.0.3-orange.svg?style=flat-square)](https://github.com/dev071/wp-media-usage-tracker/releases)

---

## 📖 Table of Contents
- [📌 Overview](#-overview)
- [🛑 The Problem: Why Traditional Media Cleaners Break Sites](#-the-problem-why-traditional-media-cleaners-break-sites)
- [✨ Key Features](#-key-features)
- [📊 Coverage Matrix (Comparison)](#-coverage-matrix-comparison)
- [🏗️ How It Works (Scanner Architecture)](#️-how-it-works-scanner-architecture)
- [💡 Real-World Use Cases](#-real-world-use-cases)
- [📥 Installation Guide](#-installation-guide)
- [🖥️ How to Use (Step-by-Step for Clients & Beginners)](#️-how-to-use-step-by-step-for-clients--beginners)
- [🧹 How to Safely Clean Up Unused Files](#-how-to-safely-clean-up-unused-files)
- [❓ Frequently Asked Questions (FAQ)](#-frequently-asked-questions-faq)
- [⚙️ Technical Specifications](#️-technical-specifications)
- [🤝 Contributing](#-contributing)
- [📄 License](#-license)
- [👨‍💻 Author](#-author)

---

## 📌 Overview

**WP Media Usage Tracker** is a lightweight, 100% safe, open-source WordPress plugin designed to solve one of the most frustrating challenges in WordPress website maintenance: **knowing exactly where every media file is used, and safely finding unused (orphan) files.**

As WordPress websites grow over months and years, the Media Library accumulates hundreds of duplicate, legacy, or abandoned images, PDFs, videos, and documents. Blindly deleting files often leads to broken images (404 errors) on live pages. On the other hand, keeping all files bloats server disk space, slows down backup routines, and inflates hosting bills.

**WP Media Usage Tracker provides 100% certainty and peace of mind:**
1. **Total Visibility:** Shows exactly how many times every file is used across your whole site.
2. **Exact Locations:** Displays direct, clickable links to the Pages, Posts, Blocks, ACF fields, Menus, or Theme templates where each file is located.
3. **Safe Storage Optimization:** Highlights all completely unused files (0 usages) and calculates the exact disk space you can safely reclaim.
4. **100% Read-Only Safety:** The plugin has **zero auto-delete routines**. Your website is 100% protected against accidental data loss.

---

## 🛑 The Problem: Why Traditional Media Cleaners Break Sites

Most media cleaner plugins only run a shallow SQL search in the standard `post_content` table. They completely overlook modern WordPress development practices, missing:

- ❌ **Featured Images:** Stored separately in `postmeta` (`_thumbnail_id`).
- ❌ **Gutenberg Core Blocks:** Comment structures and fallback markup (causing false duplicate counts).
- ❌ **LazyBlocks Custom Blocks:** JSON-encoded block attributes, direct image fields, repeaters, and rich text editors.
- ❌ **Advanced Custom Fields (ACF / SCF):** Image IDs, Image Object arrays, File download fields, Repeaters, and Options pages.
- ❌ **Navigation Menu Items:** Menu icons and custom item images stored under `nav_menu_item`.
- ❌ **Theme Customizer:** Site logos and headers stored in `theme_mods`.
- ❌ **Active Theme Files:** Images and PDFs hardcoded in templates (`single.php`, `header.php`, `functions.php`) or CSS stylesheets.

When other plugins miss these locations, they report active images as "unused." If an inexperienced user or client clicks "Delete All," **critical parts of the live website break.**

**WP Media Usage Tracker scans all of these hidden areas, ensuring zero false-positive flags.**

---

## ✨ Key Features

### 🔍 1. Universal Deep Scanning Engine
Accurately detects media across every layer of WordPress:
* **Standard Content:** Posts, Pages, and all Custom Post Types (CPTs) across every post status (`publish`, `draft`, `trash`, `future`, `pending`, `private`).
* **Featured Images:** Accurately tracks `_thumbnail_id` across all post types.
* **Gutenberg Blocks:** Full support for `core/image`, `core/cover`, `core/media-text`, `core/gallery`, `core/file`, `core/audio`, and `core/video`.
* **LazyBlocks:** Decodes URL-encoded JSON attributes, differentiating between **Direct Fields** and **Repeaters**. Also scans **Classic Editor / Rich Text** fields within LazyBlocks.
* **ACF & Secure Custom Fields (SCF):** Image IDs, Image Object arrays, File fields (PDFs, docs), Repeaters, Flexible Content, and ACF Options pages.
* **Navigation Menus:** Detects media attached to menu items with direct links to the WordPress Menu Editor.
* **Taxonomies:** Detects custom images and files in Categories, Tags, and Custom Taxonomy term meta.
* **Theme Customizer:** Tracks `get_theme_mods()` (e.g., custom logos, footer logos).
* **Active Theme Files:** Scans template files (`single.php`, `header.php`, `functions.php`), custom page templates, CSS stylesheets, and JS files for uploads URLs or PHP attachment functions (`image_render`, `wp_get_attachment_image`).
* **Classic Editor & Shortcodes:** Detects `<img>` tags, `wp-image-{id}` classes, and `[gallery]` shortcodes.

### 🎯 2. Smart Anti-Duplication
Prevents inflated usage counts. For example, when Gutenberg inserts an image, WordPress stores both a block comment and a fallback `<img>` tag in the database. WP Media Usage Tracker accurately recognizes this as **one single usage**, not two.

### 📄 3. Supports All Media & Document Formats
Works universally with every file format supported by WordPress:
* **Images:** `.jpg`, `.jpeg`, `.png`, `.webp`, `.svg`, `.gif`, `.avif`
* **Documents:** `.pdf`, `.doc`, `.docx`, `.xls`, `.xlsx`, `.ppt`, `.pptx`, `.csv`, `.txt`, `.rtf`
* **Archives:** `.zip`, `.rar`, `.7z`, `.tar.gz`
* **Media:** `.mp3`, `.wav`, `.mp4`, `.mov`, `.webm`

### 🔒 4. 100% Read-Only & Safe Guarantee
* **Zero Auto-Delete Risk:** The plugin contains **no DELETE queries**. It will never delete or modify any of your files or database records.
* **Clean Uninstallation:** Leaves no leftover options or garbage data in your database if you deactivate or uninstall it.

### 📊 5. Fast, Interactive Dashboard
* **Live KPI Cards:** Displays Total Media Count, Used Files, Unused Files, Total Usages, and Reclaimable Disk Space.
* **Instant Filter Tabs:** Seamlessly switch between **All Media**, **Used Media**, and **Unused Media**.
* **Instant Client-Side Search:** Search immediately by title, filename, or attachment ID without waiting for page reloads.
* **Type & Location Filters:** Filter by file type (Images, Documents, Audio, Video, Archives) or by location type (Page, Post, CPT, Menu Item, Theme File, Option).
* **Direct Navigation Links:** Clickable links to view the live page, open the post editor, jump to the WordPress Menu Editor, or open the Theme File Editor.
* **1-Click CSV Export:** Export complete media audits to CSV spreadsheets for client reviews or technical audits.

---

## 📊 Coverage Matrix (Comparison)

| Location / Feature | WP Media Usage Tracker | Traditional Cleaners |
| :--- | :---: | :---: |
| Posts & Pages Content | ✅ Yes | ✅ Yes |
| Featured Images (`_thumbnail_id`) | ✅ Yes | ✅ Yes |
| Gutenberg Core Image / Cover / File Blocks | ✅ Yes | ⚠️ Often Duplicated |
| LazyBlocks Direct Fields & Repeaters | ✅ Yes | ❌ Missed |
| LazyBlocks Rich Text / Classic Editor Links | ✅ Yes | ❌ Missed |
| ACF / Secure Custom Fields (SCF) Fields | ✅ Yes | ⚠️ Partial / Unreliable |
| Navigation Menu Items (`nav_menu_item`) | ✅ Yes | ❌ Missed |
| Taxonomy Term Meta (Category / Tag Images) | ✅ Yes | ❌ Missed |
| Theme Customizer Settings (`theme_mods`) | ✅ Yes | ❌ Missed |
| Active Theme Template Files (`.php`, `.css`) | ✅ Yes | ❌ Missed |
| 100% Read-Only (Zero Auto-Delete Risk) | ✅ Yes (Safe) | ❌ High Risk (Auto-Delete) |
| 1-Click CSV Audit Export | ✅ Yes (Free) | ⚠️ Often Paid Pro Feature |

---

## 🏗️ How It Works (Scanner Architecture)

```text
┌─────────────────────────────────────────────────────────────────┐
│                    WordPress Media Library                      │
│               (Images, PDFs, Documents, Videos)                 │
└────────────────────────────────┬────────────────────────────────┘
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────┐
│                   Deep Scanning Engine (PHP)                    │
│                                                                 │
│  ├─ 1. Content Posts & CPTs (Gutenberg, Classic, Shortcodes)    │
│  ├─ 2. PostMeta & Featured Images (_thumbnail_id)               │
│  ├─ 3. LazyBlocks (JSON attributes, repeaters, rich text)       │
│  ├─ 4. ACF / SCF Meta (Image Objects, File IDs, Repeaters)      │
│  ├─ 5. Navigation Menus (nav_menu_item custom meta)             │
│  ├─ 6. Term Meta (Category & Taxonomy custom image fields)      │
│  ├─ 7. Theme Customizer (theme_mods, site logos)                │
│  └─ 8. Active Theme Templates (.php, .css uploads references)   │
└────────────────────────────────┬────────────────────────────────┘
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────┐
│              Smart Anti-Duplication & Cache Layer               │
└────────────────────────────────┬────────────────────────────────┘
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────┐
│                   Interactive Admin Dashboard                   │
│                                                                 │
│  [KPI Summary]  [All / Used / Unused Tabs]  [Instant Search]    │
│  [Exact Location Links]  [Re-Scan Button]   [1-Click CSV Export]│
└─────────────────────────────────────────────────────────────────┘
```

---

## 💡 Real-World Use Cases

### 🏢 For Website Owners & Clients
* **Reclaim Hosting Disk Space:** Find gigabytes of old PDF brochures, abandoned product photos, and banner drafts uploaded years ago that are no longer referenced anywhere on the site.
* **Reduce Hosting & Backup Costs:** Smaller uploads folders mean faster backups, cheaper cloud storage, and quicker migrations.
* **Confidence & Security:** You don't have to worry about a plugin deleting files by mistake. You stay in 100% control of what gets deleted.

### 💻 For Developers & Agencies
* **Pre-Launch Site Audits:** Run a scan before client handover to ensure no test images or dummy mockups remain in production.
* **Theme & Plugin Migrations:** Verify if legacy template assets (`single.php`, `header.php`) or old custom blocks are still used before deleting them.
* **Client Reports:** Generate and deliver comprehensive CSV media audit reports to clients with one click.

---

## 📥 Installation Guide

### Method 1: Upload via WordPress Admin (Recommended for Beginners & Clients)
1. Download the latest `wp-media-usage-tracker.zip` from the [Releases](https://github.com/dev071/wp-media-usage-tracker/releases) page.
2. In your WordPress Admin dashboard, navigate to **Plugins > Add New Plugin**.
3. Click the **Upload Plugin** button at the top of the screen.
4. Select the downloaded `wp-media-usage-tracker.zip` file and click **Install Now**.
5. Once installed, click **Activate Plugin**.
6. Access the plugin anytime from your WordPress sidebar under **Media > Media Usage**.

### Method 2: Manual Installation via Git / FTP (For Developers)
1. Clone the repository into your WordPress plugins directory:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/dev071/wp-media-usage-tracker.git
   ```
2. Navigate to **Plugins** in your WordPress Admin and click **Activate**.

---

## 🖥️ How to Use (Step-by-Step for Clients & Beginners)

1. **Open the Dashboard:** Go to **Media > Media Usage** in your WordPress admin menu.
2. **Review the Summary:** Check the top KPI stat cards to see your total media count, how many files are active, how many are unused, and total reclaimable storage space.
3. **Inspect Locations:** For any used file, see the exact pages, custom blocks, or theme files where it appears. Click `+ X more places` to see all references.
4. **Quick Navigation:** Click the edit links or preview icons to jump directly to the post, menu, or theme file editor.
5. **Filter Unused Files:** Click the **Unused Media** tab to see all files that are not referenced anywhere on your site.
6. **Re-Scan Whenever Needed:** If you edit content or upload new images, click the **Re-Scan Now** button at the top right to refresh the scan cache.
7. **Export Data:** Click **Export CSV** to download a complete spreadsheet report for offline auditing.

---

## 🧹 How to Safely Clean Up Unused Files

Because **WP Media Usage Tracker is 100% read-only**, it will never delete any files automatically. Here is the safest recommended workflow to clean up your site:

1. In **Media > Media Usage**, click the **Unused Media** tab.
2. Review the list of unused files.
3. Click the title or thumbnail of the file you want to inspect or delete. This opens the file directly in the native WordPress Media Library.
4. If you confirm the file is no longer needed, click **Delete Permanently**.
5. After removing files, return to **Media > Media Usage** and click **Re-Scan Now** to see your updated storage savings!

> **Pro Tip:** Always take a backup of your `wp-content/uploads/` directory before bulk deleting files.

---

## ❓ Frequently Asked Questions (FAQ)

### Will this plugin delete my media files automatically?
**No. Absolutely not.** WP Media Usage Tracker is 100% read-only. It scans and reports usage data so you have complete visibility. It contains zero delete commands, ensuring your website remains 100% safe.

### Does this plugin slow down my website frontend?
**No.** The plugin runs exclusively in the WordPress Admin dashboard when you visit the **Media > Media Usage** screen. It adds zero CSS, zero JavaScript, and zero database queries to your website’s public frontend.

### Does it support PDFs, documents, audio, and video files?
**Yes.** It tracks all file types supported by the WordPress Media Library, including PDFs, Word documents (`.docx`), Excel spreadsheets (`.xlsx`), zip archives, MP3 audio, and MP4 videos.

### What happens if I update a page or add a new image?
The plugin automatically invalidates its scan cache whenever a post, page, or attachment is created, updated, or deleted. You can also click the **Re-Scan Now** button at any time for an instant fresh scan.

### Why does it scan theme files like `single.php` or `header.php`?
Developers frequently reference images or PDFs directly inside theme template files (e.g., hardcoded logos, banner backgrounds, or custom helper functions like `image_render()` and `wp_get_attachment_image()`). Most cleaner plugins miss these files, causing developers to accidentally delete active assets. WP Media Usage Tracker scans active theme files to protect these assets.

### Is this plugin compatible with WooCommerce and Custom Post Types?
**Yes.** It automatically scans all public and private Custom Post Types (CPTs), including WooCommerce Products, Portfolio items, Testimonials, and Custom Taxonomies.

### Is this plugin compatible with the latest WordPress and PHP versions?
**Yes.** It is fully tested with **WordPress 5.8 through 6.7+** and **PHP 7.4 through 8.3+**.

---

## ⚙️ Technical Specifications

- **Requires WordPress:** 5.8 or higher
- **Requires PHP:** 7.4 or higher (Fully compatible with PHP 8.0, 8.1, 8.2, 8.3+)
- **Tested Up To:** WordPress 6.7+
- **Text Domain:** `wp-media-usage-tracker`
- **License:** GNU General Public License v2.0 or later

---

## 🤝 Contributing

Contributions, feature suggestions, and feedback are warmly welcomed!
If you find a bug or have an idea for an enhancement, please open an issue on the [Issues](https://github.com/dev071/wp-media-usage-tracker/issues) tab.

1. Fork the Project
2. Create your Feature Branch (`git checkout -b feature/NewFeature`)
3. Commit your Changes (`git commit -m 'Add NewFeature'`)
4. Push to the Branch (`git push origin feature/NewFeature`)
5. Open a Pull Request

---

## 📄 License

This plugin is free, open-source software licensed under the [GNU General Public License v2.0 or later](LICENSE).

---

## 👨‍💻 Author

Developed with care by [**dev071**](https://github.com/dev071).  
If this plugin helped you clean up your WordPress site or save storage, please give it a ⭐️ on GitHub!