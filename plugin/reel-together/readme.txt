=== Reel Together ===
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A private movie diary and shared household watchlist.

== Installation ==
Upload the ZIP under Plugins > Add New Plugin, then activate it.
Open Settings > Reel Together to find the automatically created app page.
Use WordPress Subscriber accounts for ordinary members.

== Features ==
Personal and household diary entries, viewing dates, repeat viewings, watchlists,
ratings, notes, household invitations, and an interface for desktop and mobile.
Reusable viewing companions can be linked to family accounts. Personal viewings
require explicit sharing consent before they appear in another person's diary.
Invitation requests are collected for the owner to handle manually.
Kinopoisk and IMDb film IDs support exact links and catalog lookups, with optional
Kinopoisk and TMDB search. The interface is English; movie titles can be Russian.
Optional Stripe membership costs EUR 1 per person per month, with complimentary
access selected individually by the administrator.

== External services ==
Stripe payments are optional and disabled until the administrator connects an
account, completes a test checkout and webhook, and opens subscriptions.
Membership costs EUR 1 per person per month, with manually assigned complimentary
access. Stripe processes recurring charges, cancellation, and payment details.
Requests to api.stripe.com include the member’s email, WordPress account ID,
site identifier, and Stripe billing identifiers. Checkout and the customer portal
open checkout.stripe.com and billing.stripe.com. Card details are entered at
Stripe and are never stored by this plugin. Stripe keys stay on the server.
Service: https://stripe.com/billing
Terms: https://stripe.com/legal/ssa
Privacy: https://stripe.com/privacy


Kinopoisk search is optional and uses the independent third-party provider
Kinopoisk API Unofficial. Search keywords are sent to kinopoiskapiunofficial.tech
with a server-side API key. Results include Russian titles, poster URLs, and
community ratings. Ratings are saved snapshots and separate from your rating.
Posters load in the visitor's browser from kinopoiskapiunofficial.tech,
avatars.mds.yandex.net, or st.kp.yandex.net. Film links open kinopoisk.ru.
Provider: https://kinopoiskapiunofficial.tech/
Documentation: https://kinopoiskapiunofficial.tech/documentation/api/
This plugin is not affiliated with Kinopoisk or Yandex and does not access your
Kinopoisk account, passwords, ratings, or viewing history.

TMDB movie search is optional. When a server-side API token is configured, search
queries are sent to api.themoviedb.org. Posters load from image.tmdb.org in the
visitor's browser. No viewing histories, ratings, notes, or account details are
sent to TMDB by this plugin.
Terms: https://www.themoviedb.org/api-terms-of-use
Privacy: https://www.themoviedb.org/privacy-policy
The bundled TMDB logo is used for attribution and remains TMDB's property.

== Data retention ==
Deactivation and plugin deletion preserve custom database tables. Back up the
entire database to retain households and diary records.
Deactivating this plugin does not stop Stripe charges. Manage or cancel active
subscriptions in Stripe before retiring the service.

== Changelog ==
= 0.6.1 =
Align membership buttons with consistent widths and spacing under the hosted
theme, and improve companion-sharing checkbox alignment and text wrapping.

= 0.6.0 =
Direct Stripe EUR 1/month personal membership, administrator-selected free access,
secure checkout and customer portal, signed payment webhooks, separate test/live
setup, paid-period enforcement, and preserved diary data after cancellation.


= 0.5.0 =
Optional companion links to household accounts, My viewings participant filter,
explicit sharing of earlier or individual personal viewings, and access revocation
when links change or members leave. Shared notes and ratings remain attributed
to their original author.

= 0.4.0 =
Kinopoisk movie search, Russian titles, posters, rating snapshots, film links,
provider selection, verified catalog metadata, and Unicode-aware field limits.

= 0.1.0 =
Initial usable version.
