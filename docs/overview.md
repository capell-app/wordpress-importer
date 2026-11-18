# WordPress Importer

<!-- prettier-ignore-start -->

Hand over a client's WordPress export file and the posts and pages in it arrive with their images as ordinary pages on the new site, built with Capell. An agency or developer runs the import from the command line.

The preview writes nothing and counts what would be created, skipped or rejected. Posts already imported anywhere on the site are skipped, so a repeat run of the same export won't create copies. If Redirects is installed too, each post's old address leads to its new page, and any post that can't get one is listed with the reason.

Only posts WordPress had published keep their date. Everything else arrives unpublished, with its WordPress status noted. Comments and users stay behind, as do revisions and custom post types. Shortcodes are taken out. Text between a shortcode's opening and closing tags stays as plain text, and stand-alone ones such as galleries or forms disappear. Bracketed words such as [sic] or [note] standing alone go too. The original content stays in the page's details.

Images come from public addresses only, and files over 50 MB are refused. If a download fails, retrying the import picks up where it stopped without duplicating anything. Each image that arrives is stored on the new site, and the page's text is updated to point at that copy.

## What it does for you

WordPress Importer reads a WordPress WXR export through Migration Assistant and turns its content into Capell pages. First inspect the import preview; then execute a tracked import session that retains the WordPress details needed to review pages, media, and legacy URLs afterwards.

## Your screens

- **WordPress import**: the Migration Assistant source where you provide a WXR export and inspect the proposed create, skip, and error counts.
- **WordPress metadata preview**: where categories, tags, author, permalink, media, Gutenberg-block, and shortcode details are retained for review.
- **Results**: the import session summary, including created or skipped pages, media checkpoints, redirects, and anything that needs attention.

## What you can do

- Preview a WordPress WXR export before writing anything to Capell.
- Map categories and tags to taxonomy terms and authors to existing users by email, and preserve old permalinks and media references as WordPress metadata.
- Import the preview into an authorised target site and layout.
- Resume the same import session after an incomplete media download.
- Verify imported pages, media, and legacy URL redirects.

## Where to find it

It appears as a source inside **Migration Assistant**. Install Migration Assistant first, then start a WordPress import from there.

For a headless audit, an integrator can run `wordpress-importer:import <path>` and add `--json` for structured preview output. `--execute` writes content and therefore also requires `--site-id`, `--layout-id`, `--type-id`, and `--actor-id`; `--language-id` otherwise defaults to the target site's language. The named actor must be authorised for the target site and is checked again when a failed session is retried.

## Review publication and converted content

The reader imports WordPress `page` and `post` items. Attachments support media lookup, but custom post types, comments, users, and revisions are not created as Capell content records.

WordPress `post_status` is retained as metadata. Only posts WordPress had published keep their date as the page's **Visible from** value. Drafts, scheduled (`future`), private and pending posts arrive unpublished, so publish them yourself once you have reviewed them.

Block-editor content is converted to plain HTML (see below). Most WordPress shortcodes become visible placeholder elements rather than equivalent Capell components, while the original raw content and detected shortcode names remain in WordPress metadata for review.

## Media and recovery

Only absolute HTTP or HTTPS media URLs are considered. Downloads reject private or reserved network addresses, do not follow redirects, and accept detected JPEG, PNG, GIF, WebP, or AVIF image bytes only. The defaults are a 20-second request timeout and a 50 MB limit per image; integrators can change them with `CAPELL_WORDPRESS_IMPORTER_MEDIA_TIMEOUT` and `CAPELL_WORDPRESS_IMPORTER_MEDIA_MAX_BYTES`.

Execution is checkpointed in chunks and media is checkpointed per page and source URL. A media failure leaves already-created pages and successful downloads in place, marks the session failed, and lists pending downloads. Retry the same session after fixing reachability or limits: previously completed chunks and media are skipped. Retry requires the stored spool manifest and chunks to remain on the configured Migration Assistant disk.

## Good to know

- Start with a valid WordPress **WXR** export. The preview reports how many rows will be created or skipped and lists parse errors; resolve those errors before executing the import.
- The importer records a WordPress source identity and the export's site key for each imported page. Duplicate detection pairs both within the authorised target site, so two exports with the same post IDs both import and a rerun of one export stays idempotent.
- Categories and tags become terms in a Categories and a Tags taxonomy on the target site, reusing any taxonomy or term that already exists. Each author is matched to an existing Capell user by the email in the export and recorded as the page's creator; an author with no matching user is listed in the import result and no account is ever created. Block-editor content is converted to plain HTML: block markers and layout wrappers go, embeds become a link to the original, and any block the importer cannot express is kept as its exported HTML and named in the page's WordPress details.
- A re-import skips posts that already exist unless you ask for updates with `--update-fields=title,content,excerpt`. A requested field is updated only when the export changed it since the last import and nobody has edited it on the page since; a field an editor changed, and any page imported before the importer recorded what it stored, is left alone and listed in the result. The address and the publish state are never changed by a re-import. Each update is an ordinary page save, so the page's revision history holds the previous value.
- Imported media is downloaded into the WordPress import collection and matching source URLs are replaced in imported content. A referenced document, video, redirected URL, non-image response, oversized image, or unreachable host remains a failed checkpoint rather than being attached.
- If Redirects is installed, the completed import also creates redirects from supported old WordPress permalinks to the imported pages. Review the redirect report in the session results, including any skipped URLs.
- Newly created permalink redirects enter the signed Migration Assistant rollback report and can be removed when unchanged and authorised. Existing redirects are preserved and reported for review. Edited records still require manual recovery; old reports that never captured a redirect do not gain provenance retroactively.
- The execution spool contains the source rows, including page content and author logins, on the configured storage disk. Restrict access and retain it only as long as session review and retry policy require.

---

For how to use WordPress Importer, see the [admin guide](admin-guide.md).
For developers: see the [README](../README.md).

<!-- prettier-ignore-end -->
