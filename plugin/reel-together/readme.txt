=== Reel Together ===
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A private movie diary and shared household watchlist.

== Installation ==
Upload the ZIP under Plugins > Add New Plugin, then activate it.
Open Settings > Reel Together to find the automatically created app page.
Use WordPress Subscriber accounts for ordinary members.

== Features ==
Personal and household diary entries, viewing dates, repeat viewings, watchlists,
ratings, notes, household invitations, mobile interface, and optional Kinopoisk
and TMDB search. The interface remains English; movie titles can be Russian.

== External services ==
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

== Changelog ==
= 0.4.0 =
Kinopoisk movie search, Russian titles, posters, rating snapshots, film links,
provider selection, verified catalog metadata, and Unicode-aware field limits.

= 0.1.0 =
Initial usable version.
