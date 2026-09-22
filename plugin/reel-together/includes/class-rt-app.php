<?php
defined( 'ABSPATH' ) || exit;

final class RT_App {
    public static function activate() {
        RT_Store::install();
        $page = get_post( (int) get_option( 'rt_page_id' ) );
        if ( ! $page || 'trash' === $page->post_status ) {
            $id = wp_insert_post( array(
                'post_title' => 'Reel Together', 'post_name' => 'films', 'post_type' => 'page',
                'post_status' => 'publish', 'post_content' => '[reel_together]',
            ), true );
            if ( ! is_wp_error( $id ) ) {
                update_option( 'rt_page_id', $id, false );
            }
        }
    }

    public static function init() {
        add_shortcode( 'reel_together', array( self::class, 'render' ) );
        add_action( 'template_redirect', function () {
            if ( self::is_app() ) {
                if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                    define( 'DONOTCACHEPAGE', true );
                }
                nocache_headers();
                header( 'X-Robots-Tag: noindex, nofollow', true );
            }
        } );
        add_filter( 'template_include', function ( $template ) {
            return self::is_app() ? RT_PATH . 'templates/app.php' : $template;
        } );
        add_filter( 'show_admin_bar', function ( $show ) { return self::is_app() ? false : $show; } );
        add_action( 'wp_enqueue_scripts', function () {
            if ( ! self::is_app() ) {
                return;
            }
            wp_enqueue_style( 'reel-together', RT_URL . 'assets/app.css', array(), RT_VERSION );
            if ( is_user_logged_in() ) {
                wp_enqueue_script( 'reel-together', RT_URL . 'assets/app.js', array(), RT_VERSION, true );
                wp_add_inline_script( 'reel-together', 'window.ReelTogether=' . wp_json_encode( array(
                    'api' => esc_url_raw( rest_url( 'reel-together/v1/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ),
                ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
            }
        } );
        add_action( 'admin_menu', function () {
            add_options_page( 'Reel Together', 'Reel Together', 'manage_options', 'reel-together', array( self::class, 'settings' ) );
        } );
        add_action( 'admin_init', function () {
            foreach ( array( 'rt_tmdb_token', 'rt_kinopoisk_token' ) as $option ) {
            register_setting( 'rt_settings', $option, array(
                'type' => 'string', 'show_in_rest' => false,
                'sanitize_callback' => function ( $value ) use ( $option ) {
                    if ( ! is_string( $value ) || '' === trim( $value ) ) {
                        return get_option( $option, '' );
                    }
                    return preg_replace( '/[^a-zA-Z0-9._-]/', '', $value );
                },
            ) );
            }
        } );
        foreach ( array( 'tmdb', 'kinopoisk' ) as $provider ) {
        add_action( 'admin_post_rt_disconnect_' . $provider, function () use ( $provider ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( 'Access denied.', '', array( 'response' => 403 ) );
            }
            check_admin_referer( 'rt_disconnect_' . $provider );
            delete_option( 'rt_' . $provider . '_token' );
            wp_safe_redirect( admin_url( 'options-general.php?page=reel-together' ) );
            exit;
        } );
        }
    }

    public static function is_app() {
        return is_singular( 'page' ) && has_shortcode( get_post()->post_content ?? '', 'reel_together' );
    }

    public static function token( $provider = 'tmdb' ) {
        if ( 'kinopoisk' === $provider ) {
            return defined( 'REEL_TOGETHER_KINOPOISK_TOKEN' ) ? (string) REEL_TOGETHER_KINOPOISK_TOKEN : (string) get_option( 'rt_kinopoisk_token', '' );
        }
        return defined( 'REEL_TOGETHER_TMDB_TOKEN' ) ? (string) REEL_TOGETHER_TMDB_TOKEN : (string) get_option( 'rt_tmdb_token', '' );
    }

    public static function url() {
        return get_permalink( (int) get_option( 'rt_page_id' ) ) ?: home_url( '/films/' );
    }

    public static function render() {
        ob_start();
        if ( is_user_logged_in() ) {
            ?>
            <div id="rt-app" class="rt-app">
                <aside class="sidebar">
                    <a class="brand" href="<?php echo esc_url( self::url() ); ?>"><span class="brand-mark" aria-hidden="true">r.</span><span>reel<br>together<span class="brand-dot">.</span></span></a>
                    <p class="eyebrow sidebar-label">YOUR LITTLE CINEMA</p>
                    <nav aria-label="Main navigation">
                        <button class="nav-item active" data-view="watched"><span aria-hidden="true">▤</span> Film diary <span id="count-watched" class="count">0</span></button>
                        <button class="nav-item" data-view="watchlist"><span aria-hidden="true">＋</span> Watchlist <span id="count-watchlist" class="count">0</span></button>
                        <button class="nav-item" data-view="companions"><span aria-hidden="true">♧</span> Watching companions</button>
                        <button class="nav-item" data-view="household"><span aria-hidden="true">⌂</span> Our household</button>
                    </nav>
                    <div class="sidebar-note"><span aria-hidden="true">✳</span><p>Good films.<br>Better company.</p><small>A place for the movies<br>and the moments between.</small></div>
                    <div class="account"><span class="avatar" id="avatar" aria-hidden="true">R</span><div><strong id="account-name">Your account</strong><a href="<?php echo esc_url( wp_logout_url( self::url() ) ); ?>">Sign out</a></div></div>
                    <?php if ( current_user_can( 'manage_options' ) ) : ?>
                    <a class="manage-invitations" href="<?php echo esc_url( RT_Invitations::url() ); ?>">Invitation requests (<?php echo (int) RT_Invitations::pending_count(); ?>)</a>
                    <?php endif; ?>
                </aside>
                <main id="main" class="main" tabindex="-1">
                    <header class="topbar"><span>THE HOME OF YOUR MOVIE NIGHTS</span><span class="topbar-right">Your stories, kept close <span aria-hidden="true">↗</span></span></header>
                    <div id="screen" aria-busy="true"><div class="loading" role="status">Opening your diary…</div></div>
                    <footer class="app-footer"><span>A little collection of time well spent.</span><span>REEL TOGETHER © <?php echo esc_html( wp_date( 'Y' ) ); ?></span></footer>
                    <details class="credits"><summary>About &amp; credits</summary><p>Reel Together is your personal movie diary and household watchlist.</p><p>Russian titles, posters, Kinopoisk ratings, and available IMDb ratings are provided through <a href="https://kinopoiskapiunofficial.tech/" target="_blank" rel="noopener noreferrer">Kinopoisk API Unofficial</a>, an independent third-party service. This app is not affiliated with Kinopoisk, Yandex, or IMDb. Catalog ratings are snapshots from when the film was saved.</p><a href="https://www.themoviedb.org/" target="_blank" rel="noopener noreferrer"><img src="<?php echo esc_url( RT_URL . 'assets/tmdb-logo.svg' ); ?>" alt="TMDB" width="85" height="12"></a><p>Optional movie search and posters are also provided by TMDB. This product uses the TMDB API but is not endorsed or certified by TMDB.</p></details>
                </main>
                <dialog id="entry-dialog" aria-labelledby="dialog-title"></dialog>
                <div id="toast" role="status" aria-live="polite" hidden></div>
            </div>
            <noscript><p>Please enable JavaScript to use your movie diary.</p></noscript>
            <?php
        } else {
            ?>
            <main id="main" class="welcome">
                <a class="brand" href="<?php echo esc_url( self::url() ); ?>"><span class="brand-mark" aria-hidden="true">r.</span><span>reel together.</span></a>
                <div class="welcome-grid"><div><p class="eyebrow">MAKE A NIGHT OF IT</p><h1>Every film.<br>Every <em>memory.</em></h1><p class="intro">The ones you loved. The ones you watched together. A little home for your life in movies.</p><div class="welcome-ticket" aria-hidden="true"><span>ADMIT EVERYONE</span><strong>Good films.<br>Better company.</strong><span>YOUR NEXT MOVIE NIGHT STARTS HERE</span></div></div>
                <section class="login-card"><p class="eyebrow">WELCOME TO YOUR LITTLE CINEMA</p><h2>Come on in.</h2><p>Sign in to your private diary and household watchlist.</p>
                    <?php wp_login_form( array( 'redirect' => self::url(), 'label_log_in' => 'Open my diary', 'remember' => true ) ); ?>
                    <p><a href="<?php echo esc_url( wp_login_url( self::url() ) ); ?>">More sign-in options</a></p>
                    <p><a href="<?php echo esc_url( wp_lostpassword_url( self::url() ) ); ?>">Forgot your password?</a></p>
                    <?php if ( get_option( 'users_can_register' ) ) : ?>
                    <p class="signup">New here? <a href="<?php echo esc_url( wp_registration_url() ); ?>">Create an account</a></p>
                    <?php else : ?>
                    <?php RT_Invitations::render_form(); ?>
                    <?php endif; ?>
                </section></div>
            </main>
            <?php
        }
        return ob_get_clean();
    }

    public static function settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap"><h1>Reel Together</h1>
            <?php settings_errors(); ?>
            <p><a class="button button-primary" href="<?php echo esc_url( self::url() ); ?>">Open your movie diary</a></p>
            <p><a class="button" href="<?php echo esc_url( RT_Invitations::url() ); ?>">Review invitation requests (<?php echo (int) RT_Invitations::pending_count(); ?>)</a></p>
            <h2>Movie catalogs</h2><p>Manual entry is always available. Connect either catalog to search for movies. Keys stay on your server and are never sent to your visitors.</p>
            <form method="post" action="options.php">
                <?php settings_fields( 'rt_settings' ); ?>
                <h3>Kinopoisk — Russian titles, posters, and ratings</h3>
                <p>This integration uses <a href="https://kinopoiskapiunofficial.tech/" target="_blank" rel="noopener noreferrer">Kinopoisk API Unofficial</a>, an independent provider. Create an account there to obtain an API key. It does not use your Kinopoisk or Yandex password.</p>
                <p>Status: <strong><?php echo self::token( 'kinopoisk' ) ? 'Key configured' : 'Not connected'; ?></strong></p>
                <label for="rt_kinopoisk_token">Kinopoisk API Unofficial key</label><br>
                <input type="password" id="rt_kinopoisk_token" name="rt_kinopoisk_token" class="large-text" autocomplete="new-password" value="" placeholder="Paste your API key to connect or replace it">
                <h3>TMDB</h3><p><a href="https://www.themoviedb.org/settings/api" target="_blank" rel="noopener noreferrer">Get a TMDB token</a>. TMDB search also requests Russian titles where available.</p>
                <p>Status: <strong><?php echo self::token() ? 'Token configured' : 'Not connected'; ?></strong></p>
                <label for="rt_tmdb_token">TMDB API read access token</label><br>
                <input type="password" id="rt_tmdb_token" name="rt_tmdb_token" class="large-text" autocomplete="new-password" value="" placeholder="Paste a token to connect or replace it">
                <p class="description">Leave blank to keep the existing token.</p>
                <?php submit_button( 'Save connections' ); ?>
            </form>
            <?php foreach ( array( 'kinopoisk' => 'Kinopoisk', 'tmdb' => 'TMDB' ) as $provider => $label ) : ?>
            <?php if ( get_option( 'rt_' . $provider . '_token' ) ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( 'rt_disconnect_' . $provider ); ?>"><?php wp_nonce_field( 'rt_disconnect_' . $provider ); ?>
                <?php submit_button( 'Disconnect ' . $label, 'secondary' ); ?>
            </form>
            <?php endif; ?>
            <?php endforeach; ?>
            <h2>Accounts and households</h2>
            <p>People use their WordPress account to sign in. Give new users the Subscriber role. To allow public registration, enable “Anyone can register” in <a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">General Settings</a> and keep the default role set to Subscriber. Your hosting provider may manage registration separately.</p>
            <p>Each account can belong to one household. Household owners create invitation codes in the app. Personal entries stay private even after joining a household.</p>
            <h2>Your app page</h2><p>Activation creates a page containing <code>[reel_together]</code>. You can add that shortcode to another page, or choose the existing page as your homepage in Reading Settings.</p>
            <h2>Data</h2><p>Deactivating or deleting the plugin preserves diary data. Back up the full database to include households, movies, and viewing records; WordPress’s content export does not include these custom tables.</p>
        </div>
        <?php
    }
}
