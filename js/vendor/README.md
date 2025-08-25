# JavaScript Vendor Dependencies

This directory contains third-party JavaScript libraries bundled with the plugin to avoid external CDN dependencies.

## Files

### marked.min.js
- **Version**: 12.0.0
- **Purpose**: Markdown parsing for AI response formatting
- **Source**: https://github.com/markedjs/marked
- **License**: MIT License
- **Downloaded**: August 2025
- **Reason for bundling**: 
  - Avoids external CDN dependency (security & reliability)
  - Works in corporate environments with restricted internet access
  - Ensures consistent version across deployments

## Security Considerations

These files are verified third-party libraries:
- ✅ Downloaded from official sources
- ✅ Integrity verified against published versions  
- ✅ No modifications made to original code
- ✅ Scanned for security vulnerabilities

## Updates

To update vendor libraries:
1. Download new version from official source
2. Verify integrity and security
3. Update version information in this README
4. Test plugin functionality thoroughly
5. Update version references in plugin code if needed

## Alternative Solutions

If you prefer different approaches:

### Option 1: Use ILIAS Built-in Libraries
Check if ILIAS provides equivalent functionality in its core libraries.

### Option 2: Implement Custom Markdown Parser
Replace with lightweight custom implementation for basic markdown support.

### Option 3: CDN with Fallback
```php
// Example: CDN with local fallback
$tpl->addJavaScript("https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js");
$tpl->addOnLoadCode('
    if (typeof marked === "undefined") {
        document.write("<script src=\\"' . $this->plugin->getDirectory() . '/js/vendor/marked.min.js\\"><\\/script>");
    }
');
```

The current approach (local bundling) is recommended for production enterprise environments.