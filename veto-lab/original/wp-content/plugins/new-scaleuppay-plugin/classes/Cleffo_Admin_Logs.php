<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cleffo_Admin_Logs {

    const PAGE_SLUG = 'cleffo-logs';

    /**
     * Capability used for Cleffo admin pages.
     * Use manage_options so Administrators always have access.
     * (Some installs strip manage_woocommerce from the admin role.)
     *
     * @return string
     */
    public static function get_capability() {
        return 'manage_options';
    }

    /**
     * @return bool
     */
    public static function current_user_can_access() {
        return current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );
    }

    /**
     * Register menus and actions.
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_post_cleffo_clear_log', array( __CLASS__, 'handle_clear_log' ) );
        add_action( 'admin_post_cleffo_download_log', array( __CLASS__, 'handle_download_log' ) );
    }

    /**
     * @return void
     */
    public static function register_menu() {
        $cap = self::get_capability();

        add_menu_page(
            __( 'Cleffo', 'woo_mup_wordpresspayplugin' ),
            __( 'Cleffo', 'woo_mup_wordpresspayplugin' ),
            $cap,
            'cleffo',
            array( __CLASS__, 'render_settings_redirect' ),
            'dashicons-lock',
            56
        );

        add_submenu_page(
            'cleffo',
            __( 'Settings', 'woo_mup_wordpresspayplugin' ),
            __( 'Settings', 'woo_mup_wordpresspayplugin' ),
            $cap,
            'cleffo',
            array( __CLASS__, 'render_settings_redirect' )
        );

        add_submenu_page(
            'cleffo',
            __( 'Logs', 'woo_mup_wordpresspayplugin' ),
            __( 'Logs', 'woo_mup_wordpresspayplugin' ),
            $cap,
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Keep Settings click going to WooCommerce gateway settings.
     */
    public static function render_settings_redirect() {
        if ( ! self::current_user_can_access() ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'woo_mup_wordpresspayplugin' ) );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=suap_wordpresspayplugin' ) );
        exit;
    }

    /**
     * @param string $hook
     */
    public static function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
            return;
        }

        $css = '
            .cleffo-logs-wrap .cleffo-logs-toolbar {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                align-items: flex-end;
                margin: 16px 0;
                padding: 14px;
                background: #fff;
                border: 1px solid #c3c4c7;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            .cleffo-logs-wrap .cleffo-logs-toolbar label {
                display: block;
                font-weight: 600;
                margin-bottom: 4px;
            }
            .cleffo-logs-wrap .cleffo-logs-meta {
                margin: 0 0 12px;
                color: #646970;
            }
            .cleffo-logs-wrap .cleffo-log-viewer {
                background: #1d2327;
                color: #f0f0f1;
                border-radius: 4px;
                padding: 12px;
                max-height: 70vh;
                overflow: auto;
                font-family: Consolas, Monaco, monospace;
                font-size: 12px;
                line-height: 1.5;
                text-align: left;
            }
            .cleffo-logs-wrap .cleffo-log-line {
                display: flex;
                align-items: flex-start;
                gap: 10px;
                margin: 0 0 8px;
                padding-bottom: 8px;
                border-bottom: 1px solid rgba(255,255,255,.08);
                text-align: left;
            }
            .cleffo-logs-wrap .cleffo-log-line:last-child {
                border-bottom: none;
                margin-bottom: 0;
                padding-bottom: 0;
            }
            .cleffo-logs-wrap .cleffo-log-num {
                flex: 0 0 25px;
                width: 25px;
                color: #8c8f94;
                text-align: right;
                user-select: none;
            }
            .cleffo-logs-wrap .cleffo-log-msg {
                flex: 1 1 auto;
                min-width: 0;
                white-space: pre-wrap;
                word-break: break-word;
                text-align: left;
            }
            .cleffo-logs-wrap .cleffo-level-INFO { color:rgb(255, 255, 255); }
            .cleffo-logs-wrap .cleffo-level-ERROR { color:rgb(255, 32, 39); }
            .cleffo-logs-wrap .cleffo-level-DEBUG { color:rgb(207, 168, 71); }
            .cleffo-logs-wrap .cleffo-actions {
                display: flex;
                gap: 8px;
                margin-left: auto;
            }
        ';

        wp_register_style( 'cleffo-admin-logs', false );
        wp_enqueue_style( 'cleffo-admin-logs' );
        wp_add_inline_style( 'cleffo-admin-logs', $css );
    }

    /**
     * @return CleffoLogger
     */
    private static function logger() {
        require_once dirname( __DIR__ ) . '/helpers/Logger.php';
        return CleffoLogger::getInstance();
    }

    /**
     * Render Logs admin page.
     */
    public static function render_page() {
        if ( ! self::current_user_can_access() ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'woo_mup_wordpresspayplugin' ) );
        }

        $logger = self::logger();
        $files  = $logger->listLogFiles();

        $selected = isset( $_GET['log_file'] ) ? sanitize_file_name( wp_unslash( $_GET['log_file'] ) ) : '';
        if ( '' === $selected && ! empty( $files ) ) {
            $selected = $files[0]['name'];
        }

        $level  = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : 'all';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $limit  = isset( $_GET['limit'] ) ? absint( $_GET['limit'] ) : 500;
        $order  = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'newest';

        if ( ! in_array( $level, array( 'all', 'INFO', 'ERROR', 'DEBUG' ), true ) ) {
            $level = 'all';
        }
        if ( ! in_array( $order, array( 'newest', 'oldest' ), true ) ) {
            $order = 'newest';
        }
        if ( ! in_array( $limit, array( 100, 250, 500, 1000, 2000 ), true ) ) {
            $limit = 500;
        }

        $result = null;
        if ( $selected ) {
            $result = $logger->readLogFile(
                $selected,
                array(
                    'level'  => $level,
                    'search' => $search,
                    'limit'  => $limit,
                    'order'  => $order,
                )
            );
        }

        $cleared = isset( $_GET['cleared'] ) && '1' === $_GET['cleared'];
        ?>
        <div class="wrap cleffo-logs-wrap">
            <h1><?php esc_html_e( 'Cleffo Logs', 'woo_mup_wordpresspayplugin' ); ?></h1>

            <?php if ( $cleared ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Log file cleared.', 'woo_mup_wordpresspayplugin' ); ?></p></div>
            <?php endif; ?>

            <?php if ( empty( $files ) ) : ?>
                <div class="notice notice-info"><p><?php esc_html_e( 'No Cleffo log files found yet.', 'woo_mup_wordpresspayplugin' ); ?></p></div>
            <?php else : ?>
                <form method="get" class="cleffo-logs-toolbar">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />

                    <div>
                        <label for="cleffo-log-file"><?php esc_html_e( 'Log file', 'woo_mup_wordpresspayplugin' ); ?></label>
                        <select name="log_file" id="cleffo-log-file">
                            <?php foreach ( $files as $file ) : ?>
                                <option value="<?php echo esc_attr( $file['name'] ); ?>" <?php selected( $selected, $file['name'] ); ?>>
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            '%s (%s)',
                                            $file['name'],
                                            size_format( $file['size'] )
                                        )
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="cleffo-log-level"><?php esc_html_e( 'Level', 'woo_mup_wordpresspayplugin' ); ?></label>
                        <select name="level" id="cleffo-log-level">
                            <option value="all" <?php selected( $level, 'all' ); ?>><?php esc_html_e( 'All', 'woo_mup_wordpresspayplugin' ); ?></option>
                            <option value="INFO" <?php selected( $level, 'INFO' ); ?>>INFO</option>
                            <option value="ERROR" <?php selected( $level, 'ERROR' ); ?>>ERROR</option>
                            <option value="DEBUG" <?php selected( $level, 'DEBUG' ); ?>>DEBUG</option>
                        </select>
                    </div>

                    <div>
                        <label for="cleffo-log-order"><?php esc_html_e( 'Order', 'woo_mup_wordpresspayplugin' ); ?></label>
                        <select name="order" id="cleffo-log-order">
                            <option value="newest" <?php selected( $order, 'newest' ); ?>><?php esc_html_e( 'Newest first', 'woo_mup_wordpresspayplugin' ); ?></option>
                            <option value="oldest" <?php selected( $order, 'oldest' ); ?>><?php esc_html_e( 'Oldest first', 'woo_mup_wordpresspayplugin' ); ?></option>
                        </select>
                    </div>

                    <div>
                        <label for="cleffo-log-limit"><?php esc_html_e( 'Lines', 'woo_mup_wordpresspayplugin' ); ?></label>
                        <select name="limit" id="cleffo-log-limit">
                            <?php foreach ( array( 100, 250, 500, 1000, 2000 ) as $opt ) : ?>
                                <option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $limit, $opt ); ?>><?php echo esc_html( $opt ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="cleffo-log-search"><?php esc_html_e( 'Search', 'woo_mup_wordpresspayplugin' ); ?></label>
                        <input type="search" name="s" id="cleffo-log-search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'order id, txn, notify...', 'woo_mup_wordpresspayplugin' ); ?>" />
                    </div>

                    <div>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'View logs', 'woo_mup_wordpresspayplugin' ); ?></button>
                    </div>

                    <?php if ( $selected ) : ?>
                        <div class="cleffo-actions">
                            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cleffo_download_log&log_file=' . rawurlencode( $selected ) ), 'cleffo_download_log_' . $selected ) ); ?>">
                                <?php esc_html_e( 'Download', 'woo_mup_wordpresspayplugin' ); ?>
                            </a>
                            <a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cleffo_clear_log&log_file=' . rawurlencode( $selected ) ), 'cleffo_clear_log_' . $selected ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Clear this log file?', 'woo_mup_wordpresspayplugin' ) ); ?>');">
                                <?php esc_html_e( 'Clear', 'woo_mup_wordpresspayplugin' ); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </form>

                <?php if ( is_array( $result ) && ! empty( $result['error'] ) ) : ?>
                    <div class="notice notice-error"><p><?php echo esc_html( $result['error'] ); ?></p></div>
                <?php elseif ( is_array( $result ) ) : ?>
                    <p class="cleffo-logs-meta">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: 1: file name, 2: file size, 3: returned lines, 4: matched lines, 5: total lines */
                                __( 'Showing %3$d of %4$d matched lines from %1$s (%2$s). File has %5$d total lines.', 'woo_mup_wordpresspayplugin' ),
                                $result['file'],
                                size_format( $result['size'] ),
                                $result['returned'],
                                $result['matched'],
                                $result['total_lines']
                            )
                        );
                        ?>
                    </p>

                    <div class="cleffo-log-viewer">
                        <?php if ( empty( $result['lines'] ) ) : ?>
                            <p><?php esc_html_e( 'No log lines matched your filters.', 'woo_mup_wordpresspayplugin' ); ?></p>
                        <?php else : ?>
                            <?php foreach ( $result['lines'] as $entry ) : ?>
                                <div class="cleffo-log-line"><span class="cleffo-log-num">#<?php echo esc_html( $entry['num'] ); ?></span><span class="cleffo-log-msg cleffo-level-<?php echo esc_attr( $entry['level'] ); ?>"><?php echo esc_html( $entry['line'] ); ?></span></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Clear selected log file.
     */
    public static function handle_clear_log() {
        if ( ! self::current_user_can_access() ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'woo_mup_wordpresspayplugin' ) );
        }

        $log_file = isset( $_GET['log_file'] ) ? sanitize_file_name( wp_unslash( $_GET['log_file'] ) ) : '';
        check_admin_referer( 'cleffo_clear_log_' . $log_file );

        $ok = self::logger()->clearLogFile( $log_file );

        $redirect = add_query_arg(
            array(
                'page'     => self::PAGE_SLUG,
                'log_file' => $log_file,
                'cleared'  => $ok ? '1' : '0',
            ),
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Download selected log file.
     */
    public static function handle_download_log() {
        if ( ! self::current_user_can_access() ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'woo_mup_wordpresspayplugin' ) );
        }

        $log_file = isset( $_GET['log_file'] ) ? sanitize_file_name( wp_unslash( $_GET['log_file'] ) ) : '';
        check_admin_referer( 'cleffo_download_log_' . $log_file );

        $path = self::logger()->resolveLogFile( $log_file );
        if ( ! $path ) {
            wp_die( esc_html__( 'Log file not found.', 'woo_mup_wordpresspayplugin' ) );
        }

        nocache_headers();
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
        header( 'Content-Length: ' . (string) filesize( $path ) );
        readfile( $path );
        exit;
    }
}
