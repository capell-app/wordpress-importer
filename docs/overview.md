# WordPress Importer

<!-- prettier-ignore-start -->

## What it does for you

WordPress Importer reads a WordPress WXR export through Migration Assistant and turns its content into Capell pages. First inspect the import preview; then execute a tracked import session that retains the WordPress details needed to review pages, media, and legacy URLs afterwards.

## Your screens

- **WordPress import**: the Migration Assistant source where you provide a WXR export and inspect the proposed create, skip, and error counts.
- **WordPress metadata preview**: where categories, tags, author, permalink, media, Gutenberg-block, and shortcode details are retained for review.
- **Results**: the import session summary, including created or skipped pages, media checkpoints, redirects, and anything that needs attention.

## What you can do

- Preview a WordPress WXR export before writing anything to Capell.
- Preserve old categories, tags, authors, permalinks, and media references as WordPress metadata.
- Import the preview into an authorised target site and layout.
- Resume the same import session after an incomplete media download.
- Verify imported pages, media, and legacy URL redirects.

## Where to find it

It appears as a source inside **Migration Assistant**. Install Migration Assistant first, then start a WordPress import from there.

For a headless audit, an integrator can run `wordpress-importer:import <path>` and add `--json` for structured preview output. `--execute` writes content and therefore also requires `--site-id`, `--layout-id`, `--type-id`, and `--actor-id`; `--language-id` otherwise defaults to the target site's language. The named actor must be authorised for the target site and is checked again when a failed session is retried.

## Review publication and converted content

The reader imports WordPress `page` and `post` items. Attachments support media lookup, but custom post types, comments, users, and revisions are not created as Capell content records.

WordPress `post_status` is retained as metadata; it is not a Capell publication gate. The WordPress post date becomes the page's **Visible from** value. Review every imported page before exposing the target site, especially WordPress draft, private, or future content.

Gutenberg comment markers are removed from the editable content. Most WordPress shortcodes become visible placeholder elements rather than equivalent Capell components, while the original raw content and detected shortcode names remain in WordPress metadata for review.

## Media and recovery

Only absolute HTTP or HTTPS media URLs are considered. Downloads reject private or reserved network addresses, do not follow redirects, and accept detected JPEG, PNG, GIF, WebP, or AVIF image bytes only. The defaults are a 20-second request timeout and a 50 MB limit per image; integrators can change them with `CAPELL_WORDPRESS_IMPORTER_MEDIA_TIMEOUT` and `CAPELL_WORDPRESS_IMPORTER_MEDIA_MAX_BYTES`.

Execution is checkpointed in chunks and media is checkpointed per page and source URL. A media failure leaves already-created pages and successful downloads in place, marks the session failed, and lists pending downloads. Retry the same session after fixing reachability or limits: previously completed chunks and media are skipped. Retry requires the stored spool manifest and chunks to remain on the configured Migration Assistant disk.

## Good to know

- Start with a valid WordPress **WXR** export. The preview reports how many rows will be created or skipped and lists parse errors; resolve those errors before executing the import.
- The importer records a WordPress source identity for each imported page. Identity matching is installation-wide, not target-site scoped: a later preview skips an identity that already exists anywhere in Capell. This prevents duplicates when rerunning an export, but also means two WordPress exports with the same post IDs need collision review before import.
- Review old categories, tags, author, permalink, Gutenberg-block, and shortcode metadata before deciding how to organise imported content in Capell.
- Imported media is downloaded into the WordPress import collection and matching source URLs are replaced in imported content. A referenced document, video, redirected URL, non-image response, oversized image, or unreachable host remains a failed checkpoint rather than being attached.
- If URL Manager is installed, the completed import also creates redirects from supported old WordPress permalinks to the imported pages. Review the redirect report in the session results, including any skipped URLs.
- Migration Assistant rollback does not delete or reverse WordPress permalink redirects. Use the stored redirect report to review and remove them separately when rolling an import back.
- The execution spool contains the source rows, including page content and author logins, on the configured storage disk. Restrict access and retain it only as long as session review and retry policy require.

---

For how to use WordPress Importer, see the [admin guide](admin-guide.md).
For developers: see the [README](../README.md).

<!-- prettier-ignore-end -->
