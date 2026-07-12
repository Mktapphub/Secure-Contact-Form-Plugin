=== Secure Contact Form ===
Contributors: Minhajul Khan
Tags: contact form, ajax, security, honeypot, spam protection
Requires at least: 6.0
Requires PHP: 7.4

A security-first, enterprise-grade contact form plugin with nonce-verified AJAX submissions, honeypot spam protection, rate limiting, and a clean admin dashboard.

== Description ==

Secure Contact Form Pro is a lightweight, dependency-free contact form plugin built to demonstrate WordPress plugin development best practices:

* **Nonce-protected submissions** — every request is verified with `wp_verify_nonce()` before any processing occurs.
* **Full input sanitization** — every field is sanitized with the correct WordPress function (`sanitize_text_field()`, `sanitize_email()`, `sanitize_textarea_field()`) and validated server-side, never trusting client-side checks alone.
* **Honeypot anti-spam** — a CSS-hidden decoy field plus a submission-timing trap silently filters out automated bot traffic.
* **Rate limiting** — per-IP submission throttling using transients, with IPs stored only as salted hashes (never in plain text).
* **Asynchronous AJAX** — form submits via the native Fetch API with zero page reloads and no jQuery dependency.
* **Accessible UI** — proper labels, `aria-live` status announcements, keyboard-reachable honeypot exclusion, and visible focus states.
* **Admin dashboard** — view and manage submissions from the WordPress admin, with paginated listing and nonce-protected deletion.
* **Settings API integration** — configure recipient email, subject prefix, success message, and rate limit from a native Settings page.

= Usage =

Add the shortcode `[secure_contact_form]` to any page or post. Optionally pass a custom title: `[secure_contact_form title="Contact Us"]`.

== Installation ==

1. Upload the `secure-contact-form` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure recipient email and other options under **Contact Form > Settings**.
4. Add `[secure_contact_form]` to any page.

== Frequently Asked Questions ==

= Does this plugin store submissions in the database? =
Yes, submissions are stored in a custom `wp_scf_submissions` table so they remain accessible from the admin dashboard even if an email notification fails to deliver.

= Is visitor IP data stored? =
No raw IP addresses are stored. IPs are hashed (SHA-256, salted) solely for rate-limiting/abuse-detection purposes.

= What happens on uninstall? =
By default, submission data is retained even if the plugin is deleted. Full data removal on uninstall is available as an opt-in setting.

== Changelog ==

= 1.0.0 =
* Initial release.
