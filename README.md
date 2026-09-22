# Reel Together

A WordPress plugin for movies watched alone and with your household. Includes a private film diary, personal and shared watchlists, viewing dates, repeat viewings, ratings, notes, household invitations, and optional Kinopoisk and TMDB catalogs. The interface is English; movie search prioritizes Russian titles.

## 1. Run locally

Requires Node.js 22 or newer. PHP, MySQL, and Docker are not needed for the local preview: WordPress Playground supplies PHP and SQLite.

```sh
npm ci
npm run dev
```

Open **http://127.0.0.1:9400/** and sign in with:

- Username: `moviebuff`
- Password: `movie-night`

These credentials are for the local preview only. Administrator credentials are generated in `.local/credentials.json`; use them at `/wp-admin/` to configure the preview. The deployment ZIP contains neither these accounts nor development credentials.

Your data persists in `.local/wordpress/`. Stop the process with Ctrl+C, then use `npm run dev` to restart. Keep `.local/` to preserve the preview. Playground also caches downloaded WordPress distributions under `~/.wordpress-playground/`. Use `REEL_PORT=9500 npm run dev` if port 9400 is occupied.

### Try the first flow

1. Select **Log a film**, enter a title, and save it to your diary.
2. Open **Our household**, create one, and generate an invitation code.
3. Add a film to the watchlist with **Together** visibility.
4. Select **Log a viewing** when you watch it. That moves your watchlist entry into the diary.
5. Use **Log a film** again for a rewatch: each viewing has its own date, rating, and notes.

To try multiple people locally, sign into `/wp-admin/` as the administrator, add another Subscriber under Users, and use a separate browser profile or private window for that account. The preview does not send registration or password-reset emails.

## 2. Test and package

```sh
npm test
npm run package
```

Tests boot an isolated WordPress instance and never use your preview's database. They exercise real WordPress REST handlers and a headless browser, including account and household isolation, nonce authentication, validation, invitations and revocation, repeat viewings, pagination, and the core diary flow on desktop and mobile. Tests also cover reusable companion profiles, group filtering, private versus shared companion visibility, and invitation requests with manual handling and no email or account creation. Catalog tests mock Kinopoisk and TMDB; a live token is not required. They cover exact IMDb/Kinopoisk ID lookups, provider cross-references, manual links without API keys, and forged metadata rejection. Browser screenshots are saved under `test-results/`.

On macOS, tests use installed Google Chrome. On other platforms, install Playwright Chromium first with `npx playwright install chromium`. Override the browser using `REEL_BROWSER_CHANNEL=chrome npm test` if needed.

Tested on WordPress 7.1.1 and PHP 8.3.33 using Playground's SQLite integration. The database schema uses WordPress's `dbDelta` and `$wpdb` for MySQL/MariaDB hosting. Deployment checks verify the live WordPress.com app page, database schema, anonymous API rejection, stylesheet, favicon, and collection search layout against the hosted theme. Authenticated hosted SSO and catalog calls with a real API key still require a live account flow; the complete diary flow is tested locally.

The output is **`dist/reel-together.zip`**. It contains only the plugin, including its PHP, JavaScript, CSS, and credits asset. No JavaScript build step or Node.js runtime is needed on WordPress.com.

## 3. Install on WordPress.com

Deployment target: **https://films.khroliz.com/**, Business plan.

1. In the site's WP Admin, open **Plugins → Add New Plugin → Upload Plugin**.
2. Upload `dist/reel-together.zip`, install, and activate **Reel Together**.
3. Open **Settings → Reel Together → Open your movie diary**. Activation creates a page with the `[reel_together]` shortcode, normally at `/films/`.
4. The live site uses the Reel Together page as its homepage at **https://films.khroliz.com/**. On another installation, choose it under **Settings → Reading**.
5. Confirm the sign-in flow and use two Subscriber accounts to verify personal and household visibility on the hosted site.

The plugin keeps the site's existing homepage and account-registration settings on activation. It does not import the local preview's accounts or movie records.

[WordPress.com plugin upload instructions](https://wordpress.com/support/plugins/install-a-plugin/).

### Direct deployment through SSH

Business supports SSH and WP-CLI. An unattended deployment needs the SSH command from **Hosting Dashboard → site → Settings → SFTP/SSH**, plus a key that is attached to that site. The project-specific public key is `.local/wpcom-deploy.pub`. The private key stays in `.local/` and is excluded from Git and the ZIP.

If the account already has an SSH key, reuse it rather than replacing it without checking its other attached sites. WordPress.com currently supports one SSH public key per account.

[Enable SSH](https://wordpress.com/support/ssh/) · [Attach a public key](https://wordpress.com/support/ssh/generate-an-ssh-key/).

The configured deployment script verifies the exact target URL before any write, checks the uploaded archive's SHA-256, activates the plugin, and checks its database tables and anonymous-access rejection:

```sh
node scripts/deploy.mjs                 # Read-only connection check
npm run package
node scripts/deploy.mjs --deploy        # Install/replace this plugin and activate it
node scripts/deploy.mjs --verify        # Read-only verification
node scripts/deploy.mjs --make-homepage # Make the diary open at /
node scripts/deploy.mjs --configure-kinopoisk  # Read key from the ignored local file
```

The normal deployment command adds or updates the app without changing the homepage or registration settings. The explicit `--make-homepage` command sets the diary as the front page and saves the previous homepage options in `.local/`. WordPress handles the page’s canonical URL; the plugin does not install a custom redirect. It is configured specifically for the deployment target above. A known SSH host key must already exist in `.local/wpcom_known_hosts`.

## 4. Accounts and movie search

### Accounts

The app uses WordPress accounts and cookie authentication. Give household members the **Subscriber** role. On hosts exposing standard WordPress registration, enable **Settings → General → Membership → Anyone can register**, with **Subscriber** as the default role. The app then shows a registration link. Complete a real signup and email-delivery test before opening public registration.

On WordPress.com, use the sign-in options provided by the host. The app's **More sign-in options** link opens the standard login page, including any available SSO flow. Site-level privacy can also restrict who reaches the app, independently of the plugin's household rules.

### Viewing companions

Choose **Watched with → With other people** when logging or editing a viewing, then select one or more companions. Quick additions include **My wife** and **My elder son**; add any other name or label. Use **Watching companions** to add people or rename them. They do not need site accounts.

Use the diary’s **Watched with** filter to see films watched with one person, with other people, alone, or with unspecified company. A viewing with both your wife and son appears under either person’s filter. Rewatches retain their own date, rating, notes, and companion selection. Renaming a companion updates labels on previous viewings without changing their identity.

Companion profiles belong to the person who records the viewing. Household members can see and filter companion labels only on shared entries they can access. Selecting a companion does not share a private entry. Older free-text “who watched” notes remain available when editing; they are not automatically interpreted or classified as watched alone.

To associate a label with a real person, have them join **Our household**, then open **Watching companions**, choose their **Family account**, and save. Linked participants see tagged household viewings under **My viewings**, the diary’s default filter. **All films** still shows every entry they can access. The original author retains ownership of the entry, notes, and rating; linking does not create a duplicate or allow others to edit it.

Personal entries need explicit sharing. Check **Share all earlier viewings…** when saving a companion to share their tagged history, including personal notes and ratings, with the selected account. This grants access only to that person, not the entire household, and does not automatically share future personal entries. For individual viewings, use **Who can see this? → Linked companions — selected accounts** and select the recipients. Changing to **Just for me** revokes that entry’s grants. Removing or changing an account link, leaving the household, or removing a member revokes the affected personal grants. Relinking or rejoining does not restore them without a new explicit sharing choice.

### Invitation requests

When public registration is disabled, the login page offers **Request an invitation**. Visitors submit a name and email address. Requests are visible to administrators at **Users → Invitation requests**, with links from the diary sidebar and **Settings → Reel Together**.

The site owner arranges accounts manually, then uses **Mark handled**, **Decline**, or **Delete request**. These actions do not create accounts or send emails. The queue supports status filters and pagination. Duplicate requests keep the original details and decision. The public form uses a nonce, honeypot, and rate limits; request details are never exposed to other visitors. Administrators can delete the stored contact details after review.

### Kinopoisk

The Kinopoisk integration provides Russian movie search, exact film ID lookup, posters, community ratings out of 10, and links to the film page. When the provider supplies an IMDb ID and rating, the card shows those separately too. Your own viewing rating remains separate, out of 5. It does not connect to your Kinopoisk account or import viewing history.

1. Obtain an API key from [Kinopoisk API Unofficial](https://kinopoiskapiunofficial.tech/), an independent third-party provider.
2. Open **Settings → Reel Together**, enter it under **Kinopoisk API Unofficial key**, and save.
3. Open **Log a film → Movie catalog → Kinopoisk**, search in Russian or by original title, and select a result.
4. Save the film to either the diary or watchlist. Its Kinopoisk rating and film link appear on the card.

Alternatively define `REEL_TOGETHER_KINOPOISK_TOKEN` in `wp-config.php`. For agent-assisted setup, save only the API key in `.local/kinopoisk-api-key.txt`; this directory is ignored by Git. Never send a Kinopoisk/Yandex password. No key is bundled with the plugin.

Without a key, manual movie entry, direct Kinopoisk/IMDb ID links, and **Find on Kinopoisk** title search links still work. Missing ratings stay unrated. Catalog ratings are snapshots from the time the movie was saved; the tooltip shows when the catalog data was fetched. Search results are cached for an hour. Provider limits and connection errors leave manual entry available.

Only server-fetched catalog metadata may create a catalog movie record or its community rating. The server ignores attempts to override those values from the browser. Movies already saved remain editable after a catalog connection is disabled. Looking up either a Kinopoisk ID or its IMDb cross-reference through the Kinopoisk provider reuses the same verified movie record. Existing TMDB and Kinopoisk records are not automatically merged across providers.

The provider's [API documentation](https://kinopoiskapiunofficial.tech/documentation/api/) defines the search response and authentication. The app credits the provider and links to Kinopoisk; it is not affiliated with Kinopoisk or Yandex.

### Link by Kinopoisk or IMDb ID

Open **Log a film** or **Edit entry**, then use **Movie links**:

- **Kinopoisk link or ID:** `430` or `https://www.kinopoisk.ru/film/430/`.
- **IMDb link or ID:** `tt0126029` or `https://www.imdb.com/title/tt0126029/`.

Enter a title and save to keep direct links without any API connection. Both example IDs identify Shrek (2001). IMDb IDs remain strings so their leading zeros are preserved. User-entered links are format-validated; their correspondence to the entered title is not verified until you load catalog details. Manual linked records stay scoped to their author and cannot overwrite verified shared metadata.

With a Kinopoisk API Unofficial key configured, **Load details** uses `/api/v2.2/films/{id}` for Kinopoisk, or the exact `imdbId` filter for IMDb. It fills the title, year, ratings, and the matching IDs returned by the provider. It does not guess matches by title. Without a Kinopoisk key, a connected TMDB account can resolve IMDb IDs through its [Find by ID endpoint](https://developer.themoviedb.org/reference/find-by-id); TMDB does not supply an IMDb rating through that endpoint. Missing or ambiguous IDs leave manual entry available.

These are native ID fields and direct website links; metadata comes through the configured catalog. Kinopoisk API Unofficial is independent of Kinopoisk and IMDb. A direct, official IMDb API integration would require a separate [IMDb subscription through AWS Data Exchange](https://data.imdb.com/documentation/api-documentation/getting-access/); it is not configured in this app. Catalog ratings are snapshots, not live counters.

### TMDB

Manual title and year entry works without any external account. To enable catalog search:

1. Obtain an **API Read Access Token** in [TMDB API settings](https://www.themoviedb.org/settings/api).
2. Sign into WordPress as an administrator.
3. Open **Settings → Reel Together**, paste the token into the password field, and save.
4. Return to the diary and open **Log a film → Find a movie**.

The token stays on the server. Alternatively, define `REEL_TOGETHER_TMDB_TOKEN` in your deployment's `wp-config.php`. TMDB requests Russian titles (`ru-RU`) where available. Search queries go to TMDB through WordPress; posters are loaded directly from TMDB's image CDN. The app includes TMDB credits and its approved logo. Review [TMDB's API usage terms](https://developer.themoviedb.org/docs/faq) for your intended use, including any future commercial use.

## Data and access rules

- Personal entries belong to one user and remain private unless their author explicitly shares them with selected linked companions.
- Shared entries belong to one household. Members can read them, and only their author can edit or delete them.
- The rating belongs to the person logging the entry. Per-member ratings on a single shared viewing are a later feature.
- One household per account. Owners generate seven-day invitation codes and can remove members; removing a member also revokes outstanding invitations.
- A member can leave; the owner cannot leave in this version. Shared entries remain with the household after a member leaves.
- Viewing companions use reusable per-author profiles and support multiple people per viewing, without requiring accounts for them.
- Movie records and viewing entries are separate, so rewatches reuse metadata and retain independent viewing details.
- REST requests require authentication and a WordPress nonce. Every query enforces visibility; client-supplied owner/household IDs do not grant access. App HTML and API responses send no-cache headers.

Tables use the site's WordPress prefix: `rt_households`, `rt_members`, `rt_movies`, `rt_entries`, `rt_companions`, `rt_entry_shares`, and `rt_invitation_requests`. Deactivating or deleting the plugin retains data. Invitation requests include visitors’ names and emails; only administrators can review or remove them. Back up the full database: WordPress's built-in content export does not include these tables. Self-service data export, account erasure workflows, household ownership transfer, imports, and recommendations are not included in this first version.

## Project layout

```text
plugin/reel-together/   Uploadable WordPress plugin
scripts/               Local server, test runner, ZIP packaging
tests/                 WordPress integration tests
.local/                Ignored local database, credentials, and deployment key
dist/                  Generated plugin ZIP
test-results/          Generated browser screenshots
```

Plugin code: GPL-2.0-or-later. TMDB's logo and any catalog artwork retain their respective ownership and branding terms.
