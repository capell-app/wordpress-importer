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

## Good to know

- Start with a valid WordPress **WXR** export. The preview reports how many rows will be created or skipped and lists parse errors; resolve those errors before executing the import.
- The importer records a WordPress source identity for each imported page. A later preview skips an identity that already exists, so rerunning an export does not create a duplicate page for that source item.
- Review old categories, tags, author, permalink, Gutenberg-block, and shortcode metadata before deciding how to organise imported content in Capell.
- Imported media is downloaded into the WordPress import collection and its URLs are replaced in imported content. Failed downloads are saved as session checkpoints; retry that import session after fixing the source media issue.
- If URL Manager is installed, the completed import also creates redirects from supported old WordPress permalinks to the imported pages. Review the redirect report in the session results, including any skipped URLs.
