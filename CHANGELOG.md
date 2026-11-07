# Changelog

## 1.5.1 — 2026-11-07
- Fix: on block themes (e.g. Twenty Twenty-Five) the Makluman Takziah front-page section sat off-centre and made the page scroll sideways (WordPress forces `margin: auto !important` on children of constrained layouts). Full-width margins now take precedence. Classic themes were not affected.

## 1.5.0 — 2026-11-07
Ported from the Perepat Kariah 1.4.0–1.4.3 site builds.
- **Gambar profil ahli**: members upload / replace / remove a profile photo on Portal Ahli → Profil (JPG/PNG/WEBP ≤ 10 MB, re-encoded to a 600×600 JPEG, EXIF/GPS stripped, served privately); AJK can manage it from the member screen
- **Makluman Takziah**: wp-admin → Kariah & Khairat → Takziah, or "Makluman Takziah" from a member record. Generates a 1500×2250 poster (black-and-white photo, prayer text, footer) with GD, shows active notices on the front page (high-res download, WhatsApp share), optional "set member status to Meninggal Dunia". New settings: doa text, footer, display days, show automatically at the top of the front page. Shortcode `[pk_takziah]`. Fonts (OFL) bundled in `assets/takziah/`
- **Portal Ahli for visitors**: numbered steps 1 Daftar → 2 Aktifkan akaun → 3 Log masuk, two columns on wide screens, "Belum mendaftar?" hint on activation
- DB version 3 (`wp_pk_takziah`, `wp_pk_ahli.gambar`); upgrades automatically
- Uninstall (when data removal is ticked) also removes the takziah table and posters

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
