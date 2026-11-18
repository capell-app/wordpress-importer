Hand over a client's WordPress export file and the posts and pages in it arrive with their images as ordinary pages on the new site, built with Capell. An agency or developer runs the import from the command line.

The preview writes nothing and counts what would be created, skipped or rejected. Posts already imported anywhere on the site are skipped, so a repeat run of the same export won't create copies. If Redirects is installed too, each post's old address leads to its new page, and any post that can't get one is listed with the reason.

Only posts WordPress had published keep their date. Everything else arrives unpublished, with its WordPress status noted. Comments and users stay behind, as do revisions and custom post types. Shortcodes are taken out. Text between a shortcode's opening and closing tags stays as plain text, and stand-alone ones such as galleries or forms disappear. Bracketed words such as [sic] or [note] standing alone go too. The original content stays in the page's details.

Images come from public addresses only, and files over 50 MB are refused. If a download fails, retrying the import picks up where it stopped without duplicating anything. Each image that arrives is stored on the new site, and the page's text is updated to point at that copy.
