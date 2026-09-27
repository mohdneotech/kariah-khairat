# Changelog

## 1.4.0 — 2026-09-27
First public release (generalised from Perepat Kariah 1.3.1).
- New settings: masjid name & address, editable terms (`{masjid}` `{yuran_daftar}` `{yuran_tahunan}`), akad wakalah text, QR label + Media picker, colours, Font Awesome mode, IP source, uninstall data removal
- Setup screen (Persediaan) creates the five public pages; page mapping in Tetapan with automatic fallback lookup
- Theme-independent front end: scoped Bootstrap-compatible CSS under `.pk-ui`, built-in portal tabs
- Fixed: fee calculator data lost on block themes (shortcodes render before `wp_enqueue_scripts`)
- Chart.js bundled locally; Font Awesome from jsDelivr with SRI, only when the theme lacks it
- Private-folder exposure check with nginx/Caddy instructions; `web.config` for IIS
- Client IP: REMOTE_ADDR by default; Cloudflare / X-Forwarded-For opt-in
- `pk_labels` filter; uninstall.php (keeps data unless opted in); built-in GitHub Releases updater
- Refuses to activate alongside the old site-specific build (tables and settings are shared)
