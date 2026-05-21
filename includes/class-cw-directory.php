<?php
/**
 * Public directory shortcodes:
 *   [cw_organizers_directory] — Business / organizer cards linking to /organizer/{slug}/
 *   [cw_creators_directory]   — Creator portfolio cards linking to /profile/{slug}/
 *
 * Both shortcodes are public (no login required) and support search, filter pills,
 * sort, and server-side pagination via prefixed GET params so two directories can
 * coexist on the same page without colliding.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Directory {

    /** Query var prefixes — keep distinct so both shortcodes on one page do not collide. */
    const QV_ORG = 'dir_org_';
    const QV_CR  = 'dir_cr_';

    /** Visibility opt-out user-meta. */
    const META_HIDE = 'cw_hide_from_directory';

    public function __construct() {
        add_shortcode( 'cw_organizers_directory', [ $this, 'render_organizers' ] );
        add_shortcode( 'cw_creators_directory',   [ $this, 'render_creators' ] );
    }

    /* ────────────────────────────────────────────────────────────────────
     *  Asset enqueuer (called from creativewings-core.php once we know the
     *  current post has one of our shortcodes).
     * ──────────────────────────────────────────────────────────────────── */
    public static function enqueue_css() {
        if ( ! wp_style_is( 'cw-style-directory', 'registered' ) ) {
            wp_register_style( 'cw-style-directory', CW_URL . 'assets/css/cw-style-directory.css', [ 'cw-fontawesome' ], CW_VERSION );
        }
        wp_enqueue_style( 'cw-style-directory' );
    }

    /* ────────────────────────────────────────────────────────────────────
     *  Shortcode: organizers
     * ──────────────────────────────────────────────────────────────────── */
    public function render_organizers( $atts ) {
        $atts = shortcode_atts( [
            'per_page'     => 12,
            'columns'      => 3,
            'show_search'  => 1,
            'show_filters' => 1,
            'show_sort'    => 1,
        ], $atts, 'cw_organizers_directory' );

        $atts['per_page'] = max( 1, min( 60, (int) $atts['per_page'] ) );
        $atts['columns']  = max( 1, min( 4,  (int) $atts['columns']  ) );

        self::enqueue_css();

        $prefix       = self::QV_ORG;
        $q            = isset( $_GET[ $prefix . 'q' ] )    ? sanitize_text_field( wp_unslash( $_GET[ $prefix . 'q' ] ) )    : '';
        $industry     = isset( $_GET[ $prefix . 'ind' ] )  ? sanitize_text_field( wp_unslash( $_GET[ $prefix . 'ind' ] ) )  : '';
        $sort         = isset( $_GET[ $prefix . 'sort' ] ) ? sanitize_key( wp_unslash( $_GET[ $prefix . 'sort' ] ) )        : 'newest';
        $current_page = isset( $_GET[ $prefix . 'page' ] ) ? max( 1, (int) $_GET[ $prefix . 'page' ] )                       : 1;

        // Cache the rendered HTML by filter signature. Busted automatically on
        // profile / product / role changes via CW_Cache hooks.
        if ( class_exists( 'CW_Cache' ) ) {
            $sig = md5( wp_json_encode( [ 'a' => $atts, 'q' => $q, 'i' => $industry, 's' => $sort, 'p' => $current_page ] ) );
            $hit = CW_Cache::get( 'org:' . $sig, 'directory' );
            if ( $hit !== null ) {
                return $hit;
            }
            $html = (string) $this->render_organizers_uncached( $atts, $q, $industry, $sort, $current_page );
            CW_Cache::set( 'org:' . $sig, 'directory', $html, 5 * MINUTE_IN_SECONDS );
            return $html;
        }
        return (string) $this->render_organizers_uncached( $atts, $q, $industry, $sort, $current_page );
    }

    private function render_organizers_uncached( $atts, $q, $industry, $sort, $current_page ) {
        $prefix = self::QV_ORG;

        $args = [
            'role__in'    => [ 'business_role', 'administrator' ],
            'number'      => $atts['per_page'],
            'paged'       => $current_page,
            'count_total' => true,
            'meta_query'  => [
                'relation' => 'AND',
                [
                    'relation' => 'OR',
                    [ 'key' => self::META_HIDE, 'compare' => 'NOT EXISTS' ],
                    [ 'key' => self::META_HIDE, 'value' => '1', 'compare' => '!=' ],
                ],
                // Completeness gate (SQL-side): non-empty text basics.
                [ 'key' => 'business_name',     'value' => '', 'compare' => '!=' ],
                [ 'key' => 'business_industry', 'value' => '', 'compare' => '!=' ],
                [ 'key' => 'business_about',    'value' => '', 'compare' => '!=' ],
                [
                    'relation' => 'OR',
                    [ 'key' => 'business_city',    'value' => '', 'compare' => '!=' ],
                    [ 'key' => 'business_country', 'value' => '', 'compare' => '!=' ],
                ],
                // Logo must contain a real URL — the meta is a serialized array
                // shaped like a:N:{s:3:"url";s:NN:"https://...";...}, so a LIKE
                // on "http" reliably proves the URL slot is populated.
                [ 'key' => 'business_logo', 'value' => 'http', 'compare' => 'LIKE' ],
            ],
        ];

        if ( $industry !== '' ) {
            $args['meta_query'][] = [
                'key'     => 'business_industry',
                'value'   => $industry,
                'compare' => '=',
            ];
        }

        $sort_map = [
            'newest'   => [ 'orderby' => 'registered',    'order' => 'DESC' ],
            'name_asc' => [ 'orderby' => 'display_name',  'order' => 'ASC'  ],
        ];
        $args = array_merge( $args, $sort_map[ $sort ] ?? $sort_map['newest'] );

        if ( $q !== '' ) {
            $matched = $this->find_user_ids_by_search( $q, 'business' );
            $args['include'] = $matched ?: [ 0 ];
        }

        $query     = new WP_User_Query( $args );
        $users     = (array) $query->get_results();

        // Final completeness sweep — drops the rare "logo meta exists but no URL" case.
        if ( class_exists( 'CW_Roles' ) ) {
            $users = array_values( array_filter(
                $users,
                static fn( WP_User $u ) => CW_Roles::has_complete_organizer_profile( $u )
            ) );
        }

        $total     = (int) $query->get_total();
        $max_pages = $atts['per_page'] > 0 ? (int) ceil( $total / $atts['per_page'] ) : 1;

        $user_ids = array_map( static fn( WP_User $u ) => (int) $u->ID, $users );
        $counts   = $this->bulk_campaign_counts( $user_ids );

        $industries = $this->get_industry_values();

        ob_start();
        ?>
        <section class="cw-dir cw-dir--organizers" id="cw-dir-org" data-cols="<?php echo esc_attr( $atts['columns'] ); ?>">
            <?php $this->render_toolbar( [
                'kind'         => 'org',
                'prefix'       => $prefix,
                'show_search'  => (bool) $atts['show_search'],
                'show_filters' => (bool) $atts['show_filters'],
                'show_sort'    => (bool) $atts['show_sort'],
                'search'       => $q,
                'industry'     => $industry,
                'sort'         => $sort,
                'filter_label' => __( 'Industries', 'creativewings-core' ),
                'filter_values'=> $industries,
                'search_ph'    => __( 'Search organizers…', 'creativewings-core' ),
                'sort_opts'    => [
                    'newest'   => __( 'Newest', 'creativewings-core' ),
                    'name_asc' => __( 'A–Z', 'creativewings-core' ),
                ],
                'total'        => $total,
                'total_label'  => _n( '%s organizer', '%s organizers', $total, 'creativewings-core' ),
            ] ); ?>

            <?php if ( empty( $users ) ) : ?>
                <?php $this->render_empty_state( 'organizers', $prefix ); ?>
            <?php else : ?>
                <div class="cw-dir-grid cw-dir-grid--<?php echo esc_attr( $atts['columns'] ); ?>">
                    <?php foreach ( $users as $u ) : ?>
                        <?php echo $this->render_organizer_card( $u, (int) ( $counts[ (int) $u->ID ] ?? 0 ) ); ?>
                    <?php endforeach; ?>
                </div>
                <?php $this->render_pagination( $current_page, $max_pages, $prefix ); ?>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    /* ────────────────────────────────────────────────────────────────────
     *  Shortcode: creators
     * ──────────────────────────────────────────────────────────────────── */
    public function render_creators( $atts ) {
        $atts = shortcode_atts( [
            'per_page'     => 12,
            'columns'      => 3,
            'show_search'  => 1,
            'show_filters' => 1,
            'show_sort'    => 1,
        ], $atts, 'cw_creators_directory' );

        $atts['per_page'] = max( 1, min( 60, (int) $atts['per_page'] ) );
        $atts['columns']  = max( 1, min( 4,  (int) $atts['columns']  ) );

        self::enqueue_css();

        $prefix       = self::QV_CR;
        $q            = isset( $_GET[ $prefix . 'q' ] )    ? sanitize_text_field( wp_unslash( $_GET[ $prefix . 'q' ] ) )    : '';
        $skill        = isset( $_GET[ $prefix . 'skill' ] )? sanitize_text_field( wp_unslash( $_GET[ $prefix . 'skill' ] ) ): '';
        $sort         = isset( $_GET[ $prefix . 'sort' ] ) ? sanitize_key( wp_unslash( $_GET[ $prefix . 'sort' ] ) )        : 'newest';
        $current_page = isset( $_GET[ $prefix . 'page' ] ) ? max( 1, (int) $_GET[ $prefix . 'page' ] )                       : 1;

        if ( class_exists( 'CW_Cache' ) ) {
            $sig = md5( wp_json_encode( [ 'a' => $atts, 'q' => $q, 'k' => $skill, 's' => $sort, 'p' => $current_page ] ) );
            $hit = CW_Cache::get( 'cr:' . $sig, 'directory' );
            if ( $hit !== null ) {
                return $hit;
            }
            $html = (string) $this->render_creators_uncached( $atts, $q, $skill, $sort, $current_page );
            CW_Cache::set( 'cr:' . $sig, 'directory', $html, 5 * MINUTE_IN_SECONDS );
            return $html;
        }
        return (string) $this->render_creators_uncached( $atts, $q, $skill, $sort, $current_page );
    }

    private function render_creators_uncached( $atts, $q, $skill, $sort, $current_page ) {
        $prefix = self::QV_CR;

        $args = [
            'role__in'    => [ 'creator_role' ],
            'number'      => $atts['per_page'],
            'paged'       => $current_page,
            'count_total' => true,
            'meta_query'  => [
                'relation' => 'AND',
                [
                    'relation' => 'OR',
                    [ 'key' => self::META_HIDE, 'compare' => 'NOT EXISTS' ],
                    [ 'key' => self::META_HIDE, 'value' => '1', 'compare' => '!=' ],
                ],
                // Completeness gate (SQL-side): non-empty text basics. Display name
                // can fall back to WP display_name in PHP, so it's not required here.
                [ 'key' => 'creator_tagline', 'value' => '', 'compare' => '!=' ],
                [ 'key' => 'creator_address', 'value' => '', 'compare' => '!=' ],
                // Avatar must contain a real URL — same serialized-array shape as
                // business_logo, so LIKE "http" proves the URL slot is populated.
                [ 'key' => 'creator_profile_image', 'value' => 'http', 'compare' => 'LIKE' ],
            ],
        ];

        if ( $skill !== '' ) {
            $args['meta_query'][] = [
                'key'     => 'creator_skills',
                'value'   => $skill,
                'compare' => 'LIKE',
            ];
        }

        $sort_map = [
            'newest'   => [ 'orderby' => 'registered',    'order' => 'DESC' ],
            'name_asc' => [ 'orderby' => 'display_name',  'order' => 'ASC'  ],
        ];
        $args = array_merge( $args, $sort_map[ $sort ] ?? $sort_map['newest'] );

        if ( $q !== '' ) {
            $matched = $this->find_user_ids_by_search( $q, 'creator' );
            $args['include'] = $matched ?: [ 0 ];
        }

        $query     = new WP_User_Query( $args );
        $users     = (array) $query->get_results();

        // Final completeness sweep — drops the rare "avatar meta exists but no URL"
        // case plus any user missing a usable display name.
        if ( class_exists( 'CW_Roles' ) ) {
            $users = array_values( array_filter(
                $users,
                static fn( WP_User $u ) => CW_Roles::has_complete_creator_profile( $u )
            ) );
        }

        $total     = (int) $query->get_total();
        $max_pages = $atts['per_page'] > 0 ? (int) ceil( $total / $atts['per_page'] ) : 1;

        $user_ids = array_map( static fn( WP_User $u ) => (int) $u->ID, $users );
        $counts   = $this->bulk_portfolio_counts( $user_ids );

        $skills = $this->get_skill_values();

        ob_start();
        ?>
        <section class="cw-dir cw-dir--creators" id="cw-dir-cr" data-cols="<?php echo esc_attr( $atts['columns'] ); ?>">
            <?php $this->render_toolbar( [
                'kind'         => 'cr',
                'prefix'       => $prefix,
                'show_search'  => (bool) $atts['show_search'],
                'show_filters' => (bool) $atts['show_filters'],
                'show_sort'    => (bool) $atts['show_sort'],
                'search'       => $q,
                'industry'     => $skill,
                'sort'         => $sort,
                'filter_param' => 'skill',
                'filter_label' => __( 'Skills', 'creativewings-core' ),
                'filter_values'=> $skills,
                'search_ph'    => __( 'Search creators…', 'creativewings-core' ),
                'sort_opts'    => [
                    'newest'   => __( 'Newest', 'creativewings-core' ),
                    'name_asc' => __( 'A–Z', 'creativewings-core' ),
                ],
                'total'        => $total,
                'total_label'  => _n( '%s creator', '%s creators', $total, 'creativewings-core' ),
            ] ); ?>

            <?php if ( empty( $users ) ) : ?>
                <?php $this->render_empty_state( 'creators', $prefix ); ?>
            <?php else : ?>
                <div class="cw-dir-grid cw-dir-grid--<?php echo esc_attr( $atts['columns'] ); ?>">
                    <?php foreach ( $users as $u ) : ?>
                        <?php echo $this->render_creator_card( $u, (int) ( $counts[ (int) $u->ID ] ?? 0 ) ); ?>
                    <?php endforeach; ?>
                </div>
                <?php $this->render_pagination( $current_page, $max_pages, $prefix ); ?>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    /* ────────────────────────────────────────────────────────────────────
     *  Toolbar (shared)
     * ──────────────────────────────────────────────────────────────────── */
    private function render_toolbar( array $cfg ) {
        $prefix       = $cfg['prefix'];
        $kind         = $cfg['kind'];
        $filter_param = $cfg['filter_param'] ?? 'ind';
        $filter_qs    = $prefix . $filter_param;
        $current_url  = remove_query_arg( [ $prefix . 'q', $prefix . 'page', $filter_qs, $prefix . 'sort' ] );

        $hidden_inputs = '';
        foreach ( $_GET as $k => $v ) {
            if ( ! is_scalar( $v ) ) {
                continue;
            }
            if ( strpos( (string) $k, $prefix ) === 0 ) {
                continue;
            }
            $hidden_inputs .= sprintf(
                '<input type="hidden" name="%s" value="%s">',
                esc_attr( (string) $k ),
                esc_attr( wp_unslash( (string) $v ) )
            );
        }

        $anchor = '#cw-dir-' . $kind;
        ?>
        <form class="cw-dir-toolbar" method="get" action="<?php echo esc_url( $anchor ); ?>">
            <?php echo $hidden_inputs; ?>
            <?php if ( $cfg['total'] > 0 ) : ?>
                <p class="cw-dir-count"><?php echo esc_html( sprintf( $cfg['total_label'], number_format_i18n( $cfg['total'] ) ) ); ?></p>
            <?php endif; ?>

            <div class="cw-dir-toolbar-row">
                <?php if ( $cfg['show_search'] ) : ?>
                    <label class="cw-dir-search">
                        <i class="fas fa-search" aria-hidden="true"></i>
                        <input
                            type="search"
                            name="<?php echo esc_attr( $prefix . 'q' ); ?>"
                            value="<?php echo esc_attr( $cfg['search'] ); ?>"
                            placeholder="<?php echo esc_attr( $cfg['search_ph'] ); ?>"
                            aria-label="<?php echo esc_attr( $cfg['search_ph'] ); ?>"
                        >
                    </label>
                <?php endif; ?>

                <?php if ( $cfg['show_sort'] ) : ?>
                    <label class="cw-dir-sort">
                        <span class="cw-dir-sort-label"><?php esc_html_e( 'Sort by', 'creativewings-core' ); ?></span>
                        <select name="<?php echo esc_attr( $prefix . 'sort' ); ?>" onchange="this.form.submit()">
                            <?php foreach ( $cfg['sort_opts'] as $val => $label ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $cfg['sort'], $val ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>

                <button type="submit" class="cw-dir-btn cw-dir-btn--primary">
                    <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    <?php esc_html_e( 'Apply', 'creativewings-core' ); ?>
                </button>
            </div>

            <?php if ( $cfg['show_filters'] && ! empty( $cfg['filter_values'] ) ) : ?>
                <div class="cw-dir-filter-pills" role="tablist" aria-label="<?php echo esc_attr( $cfg['filter_label'] ); ?>">
                    <?php
                    $all_url = remove_query_arg( [ $filter_qs, $prefix . 'page' ] );
                    ?>
                    <a class="cw-dir-pill <?php echo $cfg['industry'] === '' ? 'is-active' : ''; ?>"
                       href="<?php echo esc_url( $all_url . $anchor ); ?>">
                        <?php esc_html_e( 'All', 'creativewings-core' ); ?>
                    </a>
                    <?php foreach ( $cfg['filter_values'] as $val ) :
                        $val = (string) $val;
                        if ( $val === '' ) { continue; }
                        $url = add_query_arg( [ $filter_qs => $val ], remove_query_arg( $prefix . 'page' ) ) . $anchor;
                        $is_active = ( $cfg['industry'] === $val );
                        ?>
                        <a class="cw-dir-pill <?php echo $is_active ? 'is-active' : ''; ?>"
                           href="<?php echo esc_url( $url ); ?>"
                           data-value="<?php echo esc_attr( $val ); ?>">
                            <?php echo esc_html( $val ); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </form>
        <?php
    }

    /* ────────────────────────────────────────────────────────────────────
     *  Cards
     * ──────────────────────────────────────────────────────────────────── */
    private function render_organizer_card( WP_User $u, int $campaign_count ) {
        $uid       = (int) $u->ID;
        $name      = (string) get_user_meta( $uid, 'business_name', true );
        if ( $name === '' ) { $name = $u->display_name ?: $u->user_login; }
        $tagline   = (string) get_user_meta( $uid, 'business_tagline', true );
        $industry  = (string) get_user_meta( $uid, 'business_industry', true );
        $city      = (string) get_user_meta( $uid, 'business_city', true );
        $country   = (string) get_user_meta( $uid, 'business_country', true );
        $location  = trim( implode( ', ', array_filter( [ trim( $city ), trim( $country ) ] ) ) );

        $logo_url  = $this->meta_image_url( $uid, 'business_logo' );
        $cover_url = $this->meta_image_url( $uid, 'business_cover' );

        $url = class_exists( 'CW_Roles' ) ? CW_Roles::get_public_organizer_url( $u ) : '';
        if ( $url === '' ) {
            $url = home_url( '/organizer/' . rawurlencode( $u->user_login ) . '/' );
        }

        $initial   = mb_strtoupper( mb_substr( $name, 0, 1 ) );
        $tagline   = $tagline !== '' ? $this->trim_to( $tagline, 120 ) : '';

        ob_start();
        ?>
        <article class="cw-dir-card cw-dir-card--org">
            <a class="cw-dir-card-link" href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'View %s organizer profile', 'creativewings-core' ), $name ) ); ?>"></a>
            <div class="cw-dir-cover" <?php if ( $cover_url ) : ?>style="background-image:url('<?php echo esc_url( $cover_url ); ?>')"<?php endif; ?>></div>
            <div class="cw-dir-body">
                <div class="cw-dir-head">
                    <div class="cw-dir-avatar">
                        <?php if ( $logo_url ) : ?>
                            <?php
                            if ( class_exists( 'CW_Image_Optimizer' ) ) {
                                echo CW_Image_Optimizer::picture_tag( $logo_url, '' );
                            } else {
                                echo '<img src="' . esc_url( $logo_url ) . '" alt="" loading="lazy" decoding="async">';
                            }
                            ?>
                        <?php else : ?>
                            <span class="cw-dir-avatar-fallback"><?php echo esc_html( $initial ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="cw-dir-titles">
                        <h3 class="cw-dir-name"><?php echo esc_html( $name ); ?></h3>
                        <?php if ( $tagline ) : ?>
                            <p class="cw-dir-tagline"><?php echo esc_html( $tagline ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                if ( class_exists( 'CW_Badges_Engine' ) && class_exists( 'CW_Badges_Display' ) ) {
                    $org_card_badges = CW_Badges_Engine::get_user_badges( $uid );
                    if ( ! empty( $org_card_badges ) ) {
                        echo '<div class="cw-dir-badges">' . CW_Badges_Display::render_strip( $org_card_badges, 3, [ 'size' => 'sm', 'show_label' => false, 'show_tier' => false ] ) . '</div>';
                    }
                }
                ?>
                <div class="cw-dir-meta">
                    <?php if ( $industry !== '' ) : ?>
                        <span class="cw-dir-meta-pill cw-dir-meta-pill--industry">
                            <i class="fas fa-briefcase" aria-hidden="true"></i>
                            <?php echo esc_html( $industry ); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ( $location !== '' ) : ?>
                        <span class="cw-dir-meta-pill">
                            <i class="fas fa-map-marker-alt" aria-hidden="true"></i>
                            <?php echo esc_html( $location ); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="cw-dir-foot">
                    <span class="cw-dir-count-chip">
                        <i class="fas fa-bullhorn" aria-hidden="true"></i>
                        <?php echo esc_html( sprintf( _n( '%s campaign', '%s campaigns', $campaign_count, 'creativewings-core' ), number_format_i18n( $campaign_count ) ) ); ?>
                    </span>
                    <span class="cw-dir-cta">
                        <?php esc_html_e( 'View profile', 'creativewings-core' ); ?>
                        <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </span>
                </div>
            </div>
        </article>
        <?php
        return ob_get_clean();
    }

    private function render_creator_card( WP_User $u, int $project_count ) {
        $uid     = (int) $u->ID;

        $name    = (string) get_user_meta( $uid, 'creator_display_name', true );
        if ( $name === '' ) { $name = $u->display_name ?: $u->user_login; }

        $tagline = (string) get_user_meta( $uid, 'creator_tagline', true );
        $address = (string) get_user_meta( $uid, 'creator_address', true );
        $skills_raw = (string) get_user_meta( $uid, 'creator_skills', true );
        $skills  = array_slice( array_filter( array_map( 'trim', explode( ',', $skills_raw ) ) ), 0, 2 );

        $avatar_url = $this->meta_image_url( $uid, 'creator_profile_image' );
        if ( ! $avatar_url ) {
            $avatar_url = $this->meta_image_url( $uid, 'business_logo' );
        }
        if ( ! $avatar_url ) {
            $avatar_url = get_avatar_url( $uid, [ 'size' => 200 ] );
        }

        $cover_url = $this->meta_image_url( $uid, 'creator_header_image' );
        if ( ! $cover_url ) {
            $cover_url = $this->meta_image_url( $uid, 'business_cover' );
        }

        $url = class_exists( 'CW_Roles' ) ? CW_Roles::get_public_portfolio_url( $u ) : '';
        if ( $url === '' ) {
            $url = home_url( '/profile/' . rawurlencode( $u->user_login ) . '/' );
        }

        $initial = mb_strtoupper( mb_substr( $name, 0, 1 ) );
        $tagline = $tagline !== '' ? $this->trim_to( $tagline, 120 ) : '';

        ob_start();
        ?>
        <article class="cw-dir-card cw-dir-card--creator">
            <a class="cw-dir-card-link" href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'View %s portfolio', 'creativewings-core' ), $name ) ); ?>"></a>
            <div class="cw-dir-cover" <?php if ( $cover_url ) : ?>style="background-image:url('<?php echo esc_url( $cover_url ); ?>')"<?php endif; ?>></div>
            <div class="cw-dir-body">
                <div class="cw-dir-head">
                    <div class="cw-dir-avatar cw-dir-avatar--round">
                        <?php if ( $avatar_url ) : ?>
                            <?php
                            if ( class_exists( 'CW_Image_Optimizer' ) ) {
                                echo CW_Image_Optimizer::picture_tag( $avatar_url, '' );
                            } else {
                                echo '<img src="' . esc_url( $avatar_url ) . '" alt="" loading="lazy" decoding="async">';
                            }
                            ?>
                        <?php else : ?>
                            <span class="cw-dir-avatar-fallback"><?php echo esc_html( $initial ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="cw-dir-titles">
                        <h3 class="cw-dir-name"><?php echo esc_html( $name ); ?></h3>
                        <?php if ( $tagline ) : ?>
                            <p class="cw-dir-tagline"><?php echo esc_html( $tagline ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                if ( class_exists( 'CW_Badges_Engine' ) && class_exists( 'CW_Badges_Display' ) ) {
                    $cr_card_badges = CW_Badges_Engine::get_user_badges( $uid );
                    if ( ! empty( $cr_card_badges ) ) {
                        echo '<div class="cw-dir-badges">' . CW_Badges_Display::render_strip( $cr_card_badges, 3, [ 'size' => 'sm', 'show_label' => false, 'show_tier' => false ] ) . '</div>';
                    }
                }
                ?>
                <div class="cw-dir-meta">
                    <?php foreach ( $skills as $s ) : ?>
                        <span class="cw-dir-meta-pill cw-dir-meta-pill--skill">
                            <i class="fas fa-star" aria-hidden="true"></i>
                            <?php echo esc_html( $s ); ?>
                        </span>
                    <?php endforeach; ?>
                    <?php if ( $address !== '' ) : ?>
                        <span class="cw-dir-meta-pill">
                            <i class="fas fa-map-marker-alt" aria-hidden="true"></i>
                            <?php echo esc_html( $address ); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="cw-dir-foot">
                    <span class="cw-dir-count-chip">
                        <i class="fas fa-image" aria-hidden="true"></i>
                        <?php echo esc_html( sprintf( _n( '%s project', '%s projects', $project_count, 'creativewings-core' ), number_format_i18n( $project_count ) ) ); ?>
                    </span>
                    <span class="cw-dir-cta">
                        <?php esc_html_e( 'View portfolio', 'creativewings-core' ); ?>
                        <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </span>
                </div>
            </div>
        </article>
        <?php
        return ob_get_clean();
    }

    /* ────────────────────────────────────────────────────────────────────
     *  Empty state
     * ──────────────────────────────────────────────────────────────────── */
    private function render_empty_state( $kind, $prefix ) {
        $clear_keys = [ $prefix . 'q', $prefix . 'page', $prefix . 'sort', $prefix . 'ind', $prefix . 'skill' ];
        $clear_url  = remove_query_arg( $clear_keys );
        ?>
        <div class="cw-dir-empty">
            <div class="cw-dir-empty-icon"><i class="fas fa-search" aria-hidden="true"></i></div>
            <h3>
                <?php
                if ( $kind === 'creators' ) {
                    esc_html_e( 'No creators match your filters', 'creativewings-core' );
                } else {
                    esc_html_e( 'No organizers match your filters', 'creativewings-core' );
                }
                ?>
            </h3>
            <p><?php esc_html_e( 'Try a different search or clear your filters.', 'creativewings-core' ); ?></p>
            <a class="cw-dir-btn cw-dir-btn--primary" href="<?php echo esc_url( $clear_url ); ?>">
                <i class="fas fa-undo" aria-hidden="true"></i>
                <?php esc_html_e( 'Clear filters', 'creativewings-core' ); ?>
            </a>
        </div>
        <?php
    }

    /* ────────────────────────────────────────────────────────────────────
     *  Pagination
     * ──────────────────────────────────────────────────────────────────── */
    private function render_pagination( $current_page, $max_pages, $prefix ) {
        if ( $max_pages < 2 ) {
            return;
        }

        $base = remove_query_arg( $prefix . 'page' );
        if ( strpos( $base, '?' ) === false ) {
            $base .= '?';
        } else {
            $base .= '&';
        }
        $base .= $prefix . 'page=%#%';

        $links = paginate_links( [
            'base'      => $base,
            'format'    => '',
            'current'   => $current_page,
            'total'     => $max_pages,
            'prev_text' => '<i class="fas fa-chevron-left" aria-hidden="true"></i> ' . __( 'Previous', 'creativewings-core' ),
            'next_text' => __( 'Next', 'creativewings-core' ) . ' <i class="fas fa-chevron-right" aria-hidden="true"></i>',
            'type'      => 'plain',
            'end_size'  => 1,
            'mid_size'  => 2,
            'add_fragment' => '#cw-dir-' . ( $prefix === self::QV_ORG ? 'org' : 'cr' ),
        ] );

        if ( $links ) {
            echo '<nav class="cw-dir-pagination" aria-label="' . esc_attr__( 'Directory pages', 'creativewings-core' ) . '">' . $links . '</nav>';
        }
    }

    /* ────────────────────────────────────────────────────────────────────
     *  Two-step text search:
     *  1) Match users by user_login / user_nicename / display_name.
     *  2) Match users by relevant role-specific user_meta.
     *  Union the IDs and feed them to WP_User_Query via 'include'.
     * ──────────────────────────────────────────────────────────────────── */
    private function find_user_ids_by_search( $q, $kind ) {
        global $wpdb;
        $like = '%' . $wpdb->esc_like( $q ) . '%';

        $user_ids = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->users}
             WHERE user_login LIKE %s
                OR user_nicename LIKE %s
                OR display_name LIKE %s
                OR user_email LIKE %s
             LIMIT 500",
            $like, $like, $like, $like
        ) );

        if ( $kind === 'business' ) {
            $keys = [ 'business_name', 'business_tagline', 'business_city', 'business_country', 'business_industry' ];
        } elseif ( $kind === 'creator' ) {
            $keys = [ 'creator_display_name', 'creator_tagline', 'creator_address', 'creator_skills' ];
        } else {
            $keys = [];
        }

        $meta_ids = [];
        if ( ! empty( $keys ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
            $sql          = "SELECT user_id FROM {$wpdb->usermeta}
                             WHERE meta_key IN ($placeholders)
                             AND meta_value LIKE %s
                             LIMIT 500";
            $params       = array_merge( $keys, [ $like ] );
            $meta_ids     = (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
        }

        $ids = array_unique( array_map( 'intval', array_merge( $user_ids, $meta_ids ) ) );
        $ids = array_values( array_filter( $ids ) );
        return $ids;
    }

    /* ────────────────────────────────────────────────────────────────────
     *  Bulk counts (one SQL each)
     * ──────────────────────────────────────────────────────────────────── */
    private function bulk_campaign_counts( array $user_ids ) {
        if ( empty( $user_ids ) ) {
            return [];
        }
        global $wpdb;
        $user_ids = array_map( 'intval', $user_ids );
        $in       = implode( ',', $user_ids );
        $rows     = $wpdb->get_results(
            "SELECT post_author AS uid, COUNT(*) AS c
             FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish'
             AND post_author IN ($in)
             GROUP BY post_author"
        );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->uid ] = (int) $r->c;
        }
        return $out;
    }

    private function bulk_portfolio_counts( array $user_ids ) {
        if ( empty( $user_ids ) ) {
            return [];
        }
        global $wpdb;
        $table = $wpdb->prefix . 'jet_cct_creator_portfolio';

        // Table might not exist on installs that never enabled the portfolio CCT.
        $exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $exists ) {
            return [];
        }

        $has_visibility = (bool) $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'visibility'
        ) );

        $user_ids = array_map( 'intval', $user_ids );
        $in       = implode( ',', $user_ids );

        if ( $has_visibility ) {
            $sql = "SELECT created_by AS uid, COUNT(*) AS c
                    FROM {$table}
                    WHERE created_by IN ($in)
                      AND ( visibility IS NULL OR visibility = '' OR visibility = 'public' )
                    GROUP BY created_by";
        } else {
            $sql = "SELECT created_by AS uid, COUNT(*) AS c
                    FROM {$table}
                    WHERE created_by IN ($in)
                    GROUP BY created_by";
        }

        $rows = $wpdb->get_results( $sql );
        $out  = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->uid ] = (int) $r->c;
        }
        return $out;
    }

    /* ────────────────────────────────────────────────────────────────────
     *  Filter-pill data (cached)
     * ──────────────────────────────────────────────────────────────────── */
    private function get_industry_values() {
        $cached = get_transient( 'cw_dir_industries' );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->usermeta}
             WHERE meta_key = 'business_industry'
             AND meta_value != ''
             ORDER BY meta_value ASC
             LIMIT 50"
        );
        $rows = array_values( array_filter( array_map( 'trim', (array) $rows ) ) );
        set_transient( 'cw_dir_industries', $rows, 15 * MINUTE_IN_SECONDS );
        return $rows;
    }

    private function get_skill_values() {
        $cached = get_transient( 'cw_dir_skills' );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->usermeta}
             WHERE meta_key = 'creator_skills'
             AND meta_value != ''
             LIMIT 500"
        );
        $bucket = [];
        foreach ( (array) $rows as $blob ) {
            foreach ( explode( ',', (string) $blob ) as $s ) {
                $s = trim( $s );
                if ( $s === '' ) { continue; }
                $key = mb_strtolower( $s );
                if ( ! isset( $bucket[ $key ] ) ) {
                    $bucket[ $key ] = $s;
                }
            }
        }
        ksort( $bucket );
        $out = array_values( $bucket );
        if ( count( $out ) > 30 ) {
            $out = array_slice( $out, 0, 30 );
        }
        set_transient( 'cw_dir_skills', $out, 15 * MINUTE_IN_SECONDS );
        return $out;
    }

    /* ────────────────────────────────────────────────────────────────────
     *  Helpers
     * ──────────────────────────────────────────────────────────────────── */
    private function meta_image_url( $uid, $key ) {
        $meta = get_user_meta( (int) $uid, $key, true );
        if ( is_array( $meta ) && ! empty( $meta['url'] ) ) {
            return (string) $meta['url'];
        }
        if ( is_string( $meta ) && filter_var( $meta, FILTER_VALIDATE_URL ) ) {
            return $meta;
        }
        return '';
    }

    private function trim_to( $text, $limit ) {
        $text = wp_strip_all_tags( (string) $text );
        if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) <= $limit : strlen( $text ) <= $limit ) {
            return $text;
        }
        if ( function_exists( 'wp_html_excerpt' ) ) {
            return wp_html_excerpt( $text, $limit, '…' );
        }
        return rtrim( substr( $text, 0, $limit ) ) . '…';
    }
}
