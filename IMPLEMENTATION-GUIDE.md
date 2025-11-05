# DOCX to Post PRO - Implementation Guide

## ✅ Features Implemented

### 1. **Freemium System**
- Free version limited to **10 successful imports**
- Counter tracked in `dtp_total_successful_imports` option
- Only increments when post is successfully created
- Prominent upgrade prompts throughout UI
- License activation system ready

### 2. **Document Source Selection**
- Three source types: **DOCX**, **PDF**, **Google Drive**
- Visual source selector with cards
- PDF and Google Drive locked for free users (PRO only)
- Per-source file handling

### 3. **Logo/Branding**
- Logo upload functionality
- Displays in header
- Placeholder when no logo uploaded
- Stored in Media Library

---

## 🔧 How to Complete Implementation

### **Step 1: Replace Original Plugin**

The file `docx-to-post-enhanced.php` contains the framework. You need to:

1. **Copy ALL processing logic** from your original plugin:
   - The entire `docx_to_clean_html_with_images()` method
   - All DOCX parsing functions
   - SEO generation functions
   - Image handling functions
   - All the utility methods marked with `[KEEP ORIGINAL IMPLEMENTATION]`

2. **Search for placeholders** and replace:
   ```php
   // Find these comments and add your original code:
   // [KEEP ALL THE DOCX PARSING CODE FROM ORIGINAL PLUGIN]
   // [KEEP ORIGINAL IMPLEMENTATION]
   ```

### **Step 2: Implement License Validation**

Replace the `validate_license_key()` method with real validation:

```php
private function validate_license_key(string $key): bool {
    // Option 1: Use Freemius (recommended)
    // Install: composer require freemius/wordpress-sdk

    // Option 2: Custom API
    $response = wp_remote_post('https://yourdomain.com/api/validate', [
        'body' => [
            'license_key' => $key,
            'domain' => home_url(),
            'product_id' => 'docx-to-post-pro'
        ],
        'timeout' => 15
    ]);

    if (is_wp_error($response)) {
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    return isset($data['valid']) && $data['valid'] === true;
}
```

**License Server Requirements:**
- Endpoint to validate license keys
- Check if key exists
- Check if domain is authorized
- Return JSON: `{"valid": true, "expires": "2025-12-31"}`

### **Step 3: Implement PDF Parsing**

The basic implementation is placeholder. For production:

**Option A: Use Smalot PDF Parser (Recommended)**
```bash
composer require smalot/pdfparser
```

```php
private function pdf_to_html($pdf_path) {
    require_once 'vendor/autoload.php';

    $parser = new \Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($pdf_path);
    $text = $pdf->getText();

    if (empty($text)) {
        return null;
    }

    // Extract title (first line or from metadata)
    $lines = explode("\n", trim($text));
    $title = $lines[0] ?? 'Untitled';

    // Convert to paragraphs
    $html = '';
    foreach ($lines as $i => $line) {
        if ($i === 0) continue; // Skip title
        $line = trim($line);
        if (empty($line)) continue;
        $html .= '<p>' . esc_html($line) . '</p>' . "\n";
    }

    return [$html, $title, []]; // [html, title, images]
}
```

**Option B: Use pdftotext Command**
```php
private function pdf_to_html($pdf_path) {
    $temp_file = tempnam(sys_get_temp_dir(), 'pdf_');

    $command = sprintf(
        'pdftotext %s %s 2>&1',
        escapeshellarg($pdf_path),
        escapeshellarg($temp_file)
    );

    exec($command, $output, $return_var);

    if ($return_var !== 0 || !file_exists($temp_file)) {
        return null;
    }

    $text = file_get_contents($temp_file);
    unlink($temp_file);

    // Process text to HTML...
    return $this->text_to_html($text);
}
```

### **Step 4: Implement Google Drive Integration**

**Requirements:**
1. Create Google Cloud Project
2. Enable Google Drive API
3. Create OAuth 2.0 credentials
4. Add authorized redirect URI: `https://yoursite.com/wp-admin/admin-post.php?action=dtp_gdrive_callback`

**Implementation:**
```php
// 1. Add OAuth callback handler
add_action('admin_post_dtp_gdrive_callback', [$this, 'handle_gdrive_callback']);

public function handle_gdrive_callback() {
    if (!isset($_GET['code'])) {
        wp_die('No authorization code received');
    }

    $code = sanitize_text_field($_GET['code']);

    // Exchange code for access token
    $client_id = get_option(self::OPT_GDRIVE_CLIENT_ID);
    $client_secret = get_option(self::OPT_GDRIVE_CLIENT_SECRET);
    $redirect_uri = admin_url('admin-post.php?action=dtp_gdrive_callback');

    $response = wp_remote_post('https://oauth2.googleapis.com/token', [
        'body' => [
            'code' => $code,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri' => $redirect_uri,
            'grant_type' => 'authorization_code'
        ]
    ]);

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($data['access_token'])) {
        update_option(self::OPT_GDRIVE_ACCESS_TOKEN, $data['access_token']);
        update_option(self::OPT_GDRIVE_REFRESH_TOKEN, $data['refresh_token'] ?? '');

        wp_redirect(admin_url('admin.php?page=' . self::SLUG . '&dtp_notice=' . urlencode('Google Drive connected!')));
        exit;
    }
}

// 2. List Google Drive files
private function list_gdrive_files() {
    $token = get_option(self::OPT_GDRIVE_ACCESS_TOKEN);

    $response = wp_remote_get('https://www.googleapis.com/drive/v3/files?q=mimeType%3D%27application%2Fvnd.google-apps.document%27', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token
        ]
    ]);

    $data = json_decode(wp_remote_retrieve_body($response), true);
    return $data['files'] ?? [];
}

// 3. Download Google Doc as DOCX
private function download_gdrive_file($file_id) {
    $token = get_option(self::OPT_GDRIVE_ACCESS_TOKEN);

    $url = sprintf(
        'https://www.googleapis.com/drive/v3/files/%s/export?mimeType=application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        $file_id
    );

    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token
        ]
    ]);

    if (is_wp_error($response)) {
        return false;
    }

    // Save to temp file
    $uploads = wp_upload_dir();
    $temp_file = trailingslashit($uploads['path']) . 'gdrive-' . $file_id . '.docx';
    file_put_contents($temp_file, wp_remote_retrieve_body($response));

    return $temp_file;
}
```

### **Step 5: Add Composer Dependencies (Optional)**

Create `composer.json`:
```json
{
    "require": {
        "smalot/pdfparser": "^2.0"
    },
    "autoload": {
        "psr-4": {
            "DTP\\": "includes/"
        }
    }
}
```

Run:
```bash
composer install
```

Add to plugin:
```php
// At top of plugin file
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
```

---

## 📊 Database Schema

The plugin uses WordPress options table. No custom tables needed.

**Options stored:**
- `dtp_license_key` - PRO license key
- `dtp_license_status` - 'free' or 'active'
- `dtp_total_successful_imports` - Counter for free limit
- `dtp_document_source` - 'docx', 'pdf', or 'google_drive'
- `dtp_logo_url` - URL to uploaded logo
- `dtp_gdrive_client_id` - Google OAuth client ID
- `dtp_gdrive_client_secret` - Google OAuth secret
- `dtp_gdrive_access_token` - Google Drive access token

---

## 🎨 UI/UX Features

### Visual Source Selector
- **Cards with icons** for each document type
- **PRO badge** on locked features
- **Active state** highlighting
- **Click to select** interaction

### License Status
- **Gradient banner** showing free/pro status
- **Progress indicator** for free users (X/10 imports)
- **Prominent upgrade button**
- **License activation form**

### Logo Branding
- **Header display** (right side)
- **Upload interface** in settings
- **Placeholder** when not set
- **Recommended size** hints

---

## 🧪 Testing Checklist

### Free Version
- [ ] Can import up to 10 posts
- [ ] Shows warning at 3 remaining
- [ ] Blocks upload when limit reached
- [ ] Upgrade prompts visible
- [ ] PDF/GDrive locked

### PRO Version
- [ ] License activation works
- [ ] Unlimited imports
- [ ] PDF upload enabled
- [ ] Google Drive tab visible
- [ ] All features unlocked

### Document Sources
- [ ] DOCX import works
- [ ] PDF import works (PRO)
- [ ] Google Drive works (PRO)
- [ ] ZIP extraction works for each type
- [ ] File type validation works

### Branding
- [ ] Logo upload works
- [ ] Logo displays in header
- [ ] Placeholder shows when empty
- [ ] Image properly sized

---

## 🚀 Deployment Steps

1. **Merge code**: Copy all original functions into enhanced version
2. **Test locally**: Verify all three features work
3. **Set up license server**: Implement validation endpoint
4. **Configure Google Cloud**: Set up OAuth if using Drive
5. **Create sales page**: Landing page with pricing
6. **Test payment flow**: Ensure licenses are delivered
7. **Launch**: Upload to your distribution platform

---

## 💰 Pricing Strategy

**Recommended:**
- **Free**: 10 imports, DOCX only, community support
- **PRO**: $49/year - Unlimited, PDF + Google Drive, priority support

**Upsells:**
- Agency tier: $149/year (10 sites)
- White-label: $299/year (unlimited sites, rebrand)

---

## 📝 Next Steps

1. **Copy original code** into enhanced file
2. **Choose PDF library** (Smalot recommended)
3. **Set up license server** (or use Freemius)
4. **Test freemium limits** thoroughly
5. **Design sales page**
6. **Create demo video**
7. **Launch!**

---

## 🆘 Support

For questions during implementation:
- Check WordPress Codex for API references
- Test each feature independently
- Use WP_DEBUG for error tracking
- Validate license logic first (easiest to break)

---

## 📚 Useful Resources

- **Freemius**: https://freemius.com (easiest licensing)
- **Google Drive API**: https://developers.google.com/drive/api/v3/quickstart/php
- **PDF Parser**: https://github.com/smalot/pdfparser
- **WordPress Plugin Handbook**: https://developer.wordpress.org/plugins/

---

Good luck with your plugin! 🚀
