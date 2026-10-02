# JavaScript Vendor Dependencies

Third-party libraries bundled with the plugin, so that no CDN and no build step is required. All files are unmodified copies from the published npm packages. The license texts are in `licenses/`.

| File | Package | Version | License | Use |
|---|---|---|---|---|
| `marked.min.js` | `marked` (`marked.min.js`) | 12.0.0 | MIT | Markdown rendering of answers; always loaded |
| `purify.min.js` | `dompurify` (`dist/purify.min.js`) | 3.4.16 | Apache-2.0 or MPL-2.0 | Cleaning of rendered answers and RAG excerpts; always loaded, required |
| `highlight.min.js` | `@highlightjs/cdn-assets` (`highlight.min.js`, common languages) | 11.12.0 | BSD-3-Clause | Syntax highlighting of code blocks; loaded when an answer contains code |
| `katex/katex.min.js`, `katex/katex.min.css`, `katex/fonts/*.woff2` | `katex` (`dist/`) | 0.19.0 | MIT | Rendering of formulas; loaded when an answer contains a formula |

Only the WOFF2 fonts of KaTeX are bundled; all current browsers support them.

## Updating

1. Download the package with `npm pack <package>@<version>` and take the files listed above.
2. Update the version in this file and the license text in `licenses/` if it changed.
3. Check rendering, sanitizing, highlighting and formulas in a chat.
