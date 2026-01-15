=== AI JSON-LD Generator ===
Contributors: debumedia
Tags: schema, json-ld, seo, structured data, ai, deepseek
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically generate schema.org JSON-LD structured data for WordPress pages using AI (DeepSeek).

== Description ==

AI JSON-LD Generator uses artificial intelligence to analyze your page content and automatically generate appropriate schema.org JSON-LD structured data. This helps search engines better understand your content and can improve your search result appearance with rich snippets.

**Features:**

* AI-powered schema generation using DeepSeek LLM
* Supports multiple schema types: Article, WebPage, Service, LocalBusiness, FAQPage, Product, Organization, Person, Event, HowTo
* Auto-detection of appropriate schema type based on content
* Secure API key storage with AES-256-CBC encryption
* Intelligent content truncation at sentence boundaries
* Caching system to avoid unnecessary API calls
* Rate limiting to prevent API abuse
* SEO plugin compatibility (Yoast, RankMath, AIOSEO, SEOPress)
* Output in page head (recommended) or after content

== Installation ==

1. Upload the `ai-jsonld-generator` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings → AI JSON-LD to configure your DeepSeek API key
4. Edit any page and use the "AI JSON-LD Generator" metabox to generate schema

== Frequently Asked Questions ==

= Where do I get a DeepSeek API key? =

You can obtain an API key from [DeepSeek's website](https://platform.deepseek.com/).

= Is my API key secure? =

Yes, API keys are encrypted using AES-256-CBC encryption with WordPress security salts before being stored in the database.

= Will this conflict with my SEO plugin? =

The plugin includes conflict detection for popular SEO plugins. You can enable "Skip if schema exists" in settings to prevent duplicate schema output.

= What content types are supported? =

Version 1.0 supports Pages by default. You can enable additional post types in the settings.

== Changelog ==

= 1.1.0 =
* Enhanced schema generation with richer, more comprehensive output
* Added HTML structure preservation for better content understanding
* Added comprehensive schema.org reference for 14 schema types
* Improved LLM prompts to encourage detailed schema output
* Services, contact info, team members now properly extracted
* Uses @graph format with @id references for linked entities

= 1.0.0 =
* Initial release
* DeepSeek LLM integration
* Settings page with encrypted API key storage
* Metabox for schema generation
* Caching and rate limiting
* SEO plugin conflict detection
* Frontend schema output

== Upgrade Notice ==

= 1.1.0 =
Major enhancement: Schema output now includes services, contact info, team members, and more detailed structured data. Regenerate schemas for existing pages to get richer output.

= 1.0.0 =
Initial release of AI JSON-LD Generator.
