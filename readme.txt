=== Simple Digital Downloads – Sell Files, Ebooks & PDFs with Stripe ===
Contributors: simpledigitaldownloads
Tags: stripe, digital downloads, sell files, ebooks, pdf
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell digital files with Stripe. Paste a restricted API key, set a price, and add a buy button to any post.

== Description ==

Simple Digital Downloads lets you sell digital files on your WordPress site using Stripe.

**How it works:**

1. Paste a Stripe restricted API key in Downloads → Settings
2. Create a download with a price and one or more files
3. Add a Buy Button block to any post or page
4. Buyers pay via Stripe Checkout and receive their files by email

**Features:**

* Multiple files per download
* Protected file storage with automatic security self-check
* Automatic webhook registration
* Delivery page with payment confirmation polling
* Download limits and link expiry
* Order management with email resend
* GDPR personal data export and erasure support

**Tax & VAT:** This plugin does not calculate or collect sales tax, VAT, or GST. You are responsible for meeting your tax obligations in every jurisdiction where you sell.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to Downloads → Settings and paste your Stripe restricted API key
4. Create a download and add a Buy Button block to a page

== Frequently Asked Questions ==

= What Stripe permissions does the API key need? =

* Checkout Sessions — Write
* Payment Intents — Read
* Webhook Endpoints — Write
* Refunds — Read
* Products — Write (optional, for BookFunnel or other integrations)

= Does this plugin handle taxes? =

No. Tax and VAT collection is not included. You are responsible for your own tax compliance.

== Privacy ==

Purchase data is processed by Stripe. See [Stripe's Privacy Policy](https://stripe.com/privacy) for details. Customer email addresses and order records are stored in your WordPress database.

== Changelog ==

= 1.0.2 =
* Added Git Updater headers so the plugin can update from GitHub.

= 1.0.1 =
* Added "Create Stripe Product" button so downloads can have a permanent Stripe product ID (e.g. for BookFunnel). Requires the optional "Products — Write" key permission and a live key.
* Order emails for free downloads now say "Thank you!" and only mention link expiry when there is an expiring link.

= 1.0.0 =
* Initial release
