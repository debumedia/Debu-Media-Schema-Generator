# WP AI SEO Schema Generator

A WordPress plugin that automatically generates schema.org JSON-LD structured data for your pages using AI (DeepSeek LLM).

## Features

- **AI-Powered Schema Generation** - Uses DeepSeek LLM to analyze page content and generate appropriate schema.org markup
- **Rich, Comprehensive Output** - Extracts services, contact info, team members, FAQs, and more
- **Multiple Schema Types** - Supports 14 schema types: Organization, LocalBusiness, Service, Product, Person, Event, FAQPage, Article, WebPage, HowTo, ContactPoint, PostalAddress, Offer, Review
- **Smart Content Processing** - Preserves HTML structure (headings, lists, sections) for better AI understanding
- **Automatic Type Detection** - AI selects the most appropriate schema type, or you can specify a preference
- **Secure API Key Storage** - AES-256-CBC encryption using WordPress salts
- **Caching System** - Content hash-based caching prevents unnecessary API calls
- **SEO Plugin Compatible** - Works alongside Yoast, RankMath, AIOSEO, and SEOPress
- **Dynamic Token Management** - Automatically adjusts to prevent context window overflow

## Requirements

- WordPress 5.0+
- PHP 7.4+
- DeepSeek API key ([Get one here](https://platform.deepseek.com/))

## Installation

1. Download the latest release zip from [Releases](https://github.com/debumedia/Debu-Media-Schema-Generator/releases)
2. In WordPress, go to **Plugins → Add New → Upload Plugin**
3. Upload the zip file and activate
4. Go to **Settings → AI JSON-LD** to configure your API key

## Configuration

### Settings Page

Navigate to **Settings → AI JSON-LD** to configure:

| Setting | Default | Description |
|---------|---------|-------------|
| API Key | - | Your DeepSeek API key (stored encrypted) |
| Model | deepseek-chat | The DeepSeek model to use |
| Temperature | 0.2 | Controls randomness (lower = more consistent) |
| Max Tokens | 8000 | Maximum tokens for schema output |
| Max Content Chars | 50000 | Maximum page content to process |
| Output Location | head | Where to inject schema (head or after content) |
| Enabled Post Types | page | Which post types to enable |

### Generating Schema

1. Edit any page in WordPress
2. Find the **WP AI SEO Schema Generator** metabox in the sidebar
3. Optionally select a preferred schema type (or leave as Auto-detect)
4. Click **Generate JSON-LD**
5. Review the generated schema in the preview
6. Save the page - schema will be output on the frontend

## How It Works

### Content Processing

The plugin processes your page content while preserving semantic structure:

```
HTML Input:
<h2>Our Services</h2>
<ul>
  <li>Web Development</li>
  <li>Security Audits</li>
</ul>

Processed Output:
## [Our Services] ##
[LIST START]
- Web Development
- Security Audits
[LIST END]
```

This helps the AI understand page organization and extract relevant information.

### Schema Generation

The AI analyzes processed content and generates comprehensive JSON-LD:

```json
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "WebPage",
      "name": "Our Services",
      "url": "https://example.com/services/",
      "description": "Professional web development and security services"
    },
    {
      "@type": "Organization",
      "@id": "#organization",
      "name": "Example Company",
      "description": "We provide web development and security services"
    },
    {
      "@type": "Service",
      "name": "Web Development",
      "provider": {"@id": "#organization"}
    },
    {
      "@type": "Service",
      "name": "Security Audits",
      "provider": {"@id": "#organization"}
    }
  ]
}
```

### Token Management

The plugin dynamically manages tokens to prevent API errors:

- **Context Window**: DeepSeek has 64K tokens total (input + output)
- **Input**: System prompt + page content + schema reference
- **Output**: The generated JSON-LD schema

```
Available Output = 64K - Input Tokens - 2K safety buffer
Actual max_tokens = min(requested, available, 8192)
```

## Caching

Schema is cached based on a SHA-256 hash of:
- Page content
- Page title
- Page excerpt
- Last modified date
- Plugin settings version

Schema only regenerates when content changes or you click "Force Regenerate".

## SEO Plugin Compatibility

The plugin detects popular SEO plugins and can skip output if they already provide schema:

- Yoast SEO
- RankMath
- All in One SEO
- SEOPress

Enable "Skip if schema exists" in settings to prevent duplicates.

## Hooks & Filters

```php
// Control whether to output schema
add_filter('wp_ai_schema_should_output', function($should_output, $post_id) {
    // Your logic here
    return $should_output;
}, 10, 2);

// Allow output even with Yoast active
add_filter('wp_ai_schema_output_with_yoast', '__return_true');

// Allow output even with RankMath active
add_filter('wp_ai_schema_output_with_rankmath', '__return_true');
```

## File Structure

```
wp-ai-seo-schema-generator/
├── wp-ai-seo-schema-generator.php      # Main plugin file
├── uninstall.php                # Cleanup on uninstall
├── includes/
│   ├── class-admin.php          # Settings page
│   ├── class-ajax.php           # AJAX handlers
│   ├── class-content-processor.php   # Content preparation
│   ├── class-encryption.php     # API key encryption
│   ├── class-metabox.php        # Edit screen metabox
│   ├── class-prompt-builder.php # LLM prompt construction
│   ├── class-schema-output.php  # Frontend output
│   ├── class-schema-reference.php    # Schema.org definitions
│   ├── class-schema-validator.php    # JSON validation
│   └── class-conflict-detector.php   # SEO plugin detection
├── providers/
│   ├── interface-provider.php   # Provider contract
│   ├── class-abstract-provider.php   # Shared functionality
│   ├── class-provider-registry.php   # Provider management
│   └── class-deepseek-provider.php   # DeepSeek implementation
└── assets/
    ├── css/                     # Stylesheets
    └── js/                      # JavaScript
```

## Security

- API keys encrypted with AES-256-CBC using WordPress salts
- Nonce validation on all forms and AJAX requests
- Capability checks (`manage_options` for settings, `edit_post` for generation)
- JSON structure and size validation before saving
- No sensitive data logged

## Changelog

### v1.1.1
- Increased default limits (max_tokens: 8000, max_content_chars: 50000)
- Added dynamic token calculation to prevent context overflow
- Added safety buffer and minimum output guarantee

### v1.1.0
- Enhanced schema generation with richer output
- Added HTML structure preservation
- Added comprehensive schema.org reference (14 types)
- Improved LLM prompts for detailed extraction

### v1.0.0
- Initial release
- DeepSeek LLM integration
- Settings page with encrypted API key storage
- Metabox for schema generation
- Caching and rate limiting
- SEO plugin conflict detection

## License

GPL v2 or later

## Author

[Debu Media](https://debumedia.com)

---

Built with [Claude Code](https://claude.ai/code)
