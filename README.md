# DOCX to Post PRO - WordPress Plugin

## 🚀 Features

### ✅ Freemium System
- **Free Version**: 10 successful imports
- **PRO Version**: Unlimited imports with license key
- Automatic upgrade prompts and warnings
- License activation in admin panel

### 📁 Multi-Source Document Import
- **DOCX Files** (Free & PRO) - Microsoft Word documents
- **PDF Files** (PRO only) - Full PDF text extraction with Smalot library
- **Google Drive** (PRO only) - Coming soon

### 🎨 Custom Branding
- Upload your own logo
- Displays in plugin header
- Perfect for white-label solutions

### 🤖 AI-Powered Features
- **SEO Generation**: Auto-generate Yoast SEO metadata using OpenAI
- **Smart Fallback**: SEO generation without AI when unavailable
- **Image ALT Text**: AI-generated accessibility text for images
- **Keyword Extraction**: Automatic focus keyword detection

### ⚡ Advanced Processing
- Background queue with auto-tuning
- Live progress updates (AJAX)
- ZIP archive support (bulk uploads)
- Retry logic for failed imports
- Automatic stuck item recovery

---

## 📦 Installation

### Requirements
- WordPress 5.0+
- PHP 7.4+
- ZipArchive extension
- Composer (for dependencies)

### Steps

1. **Install Dependencies**
   ```bash
   cd /path/to/plugin/
   composer install
   ```

2. **Upload to WordPress**
   - Copy entire folder to `/wp-content/plugins/`
   - Or ZIP and upload via WordPress admin

3. **Activate Plugin**
   - Go to Plugins > Installed Plugins
   - Click "Activate" on "DOCX to Post PRO"

4. **Configure Settings** (optional)
   - Add OpenAI API key for AI features
   - Upload custom logo
   - Select document source

---

## 🎯 Quick Start

### For Free Users (10 imports)

1. Navigate to **DOCX to Post** in admin menu
2. Select **DOCX Files** as source
3. Upload .docx files or .zip archive
4. Choose post status (Draft/Publish)
5. Click "Start Processing"
6. Watch live progress!

### For PRO Users (Unlimited)

1. Purchase license key
2. Go to **Settings & License** section
3. Enter license key: `PRO-XXXX-XXXX-XXXX-XXXX`
4. Click "Activate License"
5. Unlock PDF and Google Drive support!

---

## 🔑 License Activation

### Demo/Testing
For testing, use any key starting with `PRO-` (minimum 20 characters):
```
PRO-TEST-1234-5678-ABCD
```

### Production
Replace `validate_license_key()` method with your actual API:

```php
private function validate_license_key(string $key): bool {
    $response = wp_remote_post('https://yourdomain.com/api/validate', [
        'body' => [
            'license_key' => $key,
            'domain' => home_url(),
            'product_id' => 'docx-to-post-pro'
        ]
    ]);

    $data = json_decode(wp_remote_retrieve_body($response), true);
    return isset($data['valid']) && $data['valid'] === true;
}
```

---

## 📖 Usage

### Document Source Selection

**DOCX Files** (Always available)
- Upload .docx files
- ZIP archives with multiple DOCX files
- Supports Word 2007+ format

**PDF Files** (PRO only)
- Upload .pdf files
- Automatic text extraction
- Heading detection
- Title extraction from metadata

**Google Drive** (PRO only - Coming Soon)
- OAuth authentication
- Browse Google Docs
- Direct import from cloud

### Upload Process

1. Select source type (DOCX/PDF/Google Drive)
2. Upload files or connect to Drive
3. Choose post status
4. Files added to queue automatically
5. Background processing begins
6. Live updates show progress

### SEO Features

**With OpenAI API:**
- Auto-generates SEO title (60 chars)
- Creates meta description (160 chars)
- Extracts focus keyword
- Keyword validation
- Yoast SEO integration

**Without OpenAI (Fallback):**
- Smart keyword extraction from title
- Sentence-based meta descriptions
- Automatic keyword placement
- Still integrates with Yoast

### Image Handling

- Embedded images → WordPress Media Library
- First image → Featured image
- External images downloaded
- AI alt text generation (optional)
- Lazy loading attributes

---

## 🛠️ Configuration

### OpenAI Settings (Optional)

```php
// In WordPress admin
SEO & AI Settings:
- Enable OpenAI SEO: ✓
- API Key: sk-...
- Model: gpt-4o-mini
- Language: auto (or en, pl, etc.)
- Enable AI Alt Text: ✓
```

### Google Drive (PRO)

1. Create Google Cloud Project
2. Enable Drive API
3. Create OAuth 2.0 credentials
4. Add redirect URI:
   ```
   https://yoursite.com/wp-admin/admin-post.php?action=dtp_gdrive_callback
   ```
5. Enter Client ID and Secret in settings

---

## 🔧 Troubleshooting

### Common Issues

**"Free Limit Reached"**
- You've used 10 free imports
- Solution: Upgrade to PRO license

**"ZIP parse failed"**
- ZIP may be corrupted
- Solution: Extract and upload individual files

**"Parse failed"**
- DOCX file may be corrupted or old format
- Solution: Re-save in Word 2007+ format

**Items stuck in "Processing"**
- Server timeout or crash occurred
- Solution: Click "Recover Stuck Items" button

**PDF text garbled**
- PDF may have images instead of text
- Solution: Use OCR software first

### Debug Mode

Enable WordPress debug in `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check logs at: `/wp-content/debug.log`

### Slow Server Mode

For shared hosting with strict limits:
```php
define('DTP_SLOW_SERVER_MODE', true);
```

---

## 📊 Queue Management

### Queue Status

- **Total**: All items ever queued
- **Pending**: Waiting to process
- **Processing**: Currently being imported
- **Done**: Successfully completed
- **Error**: Failed (can be requeued)

### Maintenance Actions

**Process Now**
- Force immediate processing
- Bypasses scheduled cron

**Recover Stuck Items**
- Fixes items stuck in "processing"
- Checks if post was actually created
- Retries up to 3 times

**Reset Queue**
- Clears all items
- Stops processing
- Use with caution!

**Clean History**
- Removes uploaded .docx/.pdf source files
- Keeps created posts and images
- Frees disk space

---

## 🎨 Customization

### White-Label

1. Upload custom logo in Settings
2. Logo appears in plugin header
3. Perfect for agencies

### Hooks (Coming Soon)

```php
// Before import
do_action('dtp_before_import', $file_path, $post_id);

// After import
do_action('dtp_after_import', $post_id, $file_path);

// Modify HTML
$html = apply_filters('dtp_clean_html', $html, $doc_path);
```

---

## 💰 Pricing

### Free Version
- 10 total imports
- DOCX files only
- Basic AI SEO
- Community support

### PRO Version - $49/year
- **Unlimited imports**
- DOCX + PDF support
- Google Drive integration
- Priority support
- All future updates

### Agency - $149/year (Coming Soon)
- 10 site licenses
- White-label ready
- Priority support
- Bulk license management

---

## 📝 File Structure

```
docx-to-post-pro/
├── docx-to-post-pro.php     # Main plugin file
├── composer.json             # Dependencies
├── vendor/                   # Composer packages
│   └── smalot/pdfparser/    # PDF parsing library
├── README.md                 # This file
├── IMPLEMENTATION-GUIDE.md   # Setup instructions
└── FEATURES-OVERVIEW.md      # Feature documentation
```

---

## 🔄 Updates

### Changelog

**Version 2.0.0** (Current)
- ✅ Freemium system (10 free imports)
- ✅ Multi-source selection (DOCX/PDF/GDrive)
- ✅ PDF parsing with Smalot library
- ✅ Custom logo branding
- ✅ Complete DOCX processing
- ✅ AI SEO with smart fallback
- ✅ License activation system

**Version 1.8.0** (Prototype)
- Initial DOCX import
- AI SEO generation
- Queue system
- Live progress

---

## 🆘 Support

### Documentation
- See `IMPLEMENTATION-GUIDE.md` for setup
- See `FEATURES-OVERVIEW.md` for features

### Issues
- Report bugs: [your-support-email]
- Feature requests: [your-support-email]

### Resources
- WordPress Codex: https://codex.wordpress.org/
- PDF Parser: https://github.com/smalot/pdfparser
- OpenAI API: https://platform.openai.com/docs

---

## 📄 License

This is a commercial WordPress plugin.

**Free Version**: Limited to 10 imports
**PRO Version**: Requires valid license key

---

## 👨‍💻 Developer Notes

### TODO for Production

1. **Implement License Server**
   - Replace demo validation in `validate_license_key()`
   - Set up API endpoint
   - Add domain locking

2. **Complete Google Drive**
   - OAuth flow implementation
   - File browser UI
   - Download/convert logic

3. **Add Hooks for Extensibility**
   - Before/after import hooks
   - Content filters
   - Custom post type support

4. **Testing**
   - Unit tests for DOCX parsing
   - Integration tests for PDF
   - E2E tests for freemium limits

5. **Documentation**
   - Video tutorials
   - Knowledge base
   - API documentation

---

## ✨ Credits

- **PDF Parsing**: Smalot PDF Parser
- **AI Features**: OpenAI GPT-4o-mini
- **WordPress Framework**: WordPress Core Team

---

**Built for WordPress • Powered by AI • Ready to Sell**
